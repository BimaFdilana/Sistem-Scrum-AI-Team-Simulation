<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActiveModelService;
use App\Services\CliProxyApiClient;
use App\Support\Ai\AiQuota;
use App\Support\Ai\AntigravityQuota;
use App\Support\Ai\QuotaWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** Timer dan tanda kuota habis: sampai kapan pengguna harus menunggu. */
class QuotaTimerTest extends TestCase
{
    use RefreshDatabase;

    private array $gateway = [];

    private bool $gatewayFaked = false;

    private function summary(float $gemini, float $thirdParty, ?string $reset = null, string $window = 'weekly'): array
    {
        $reset ??= now()->addDays(6)->addHours(23)->utc()->toIso8601String();

        return ['groups' => [
            ['displayName' => 'Gemini Models', 'buckets' => [['bucketId' => 'gemini-'.$window, 'window' => $window, 'remainingFraction' => $gemini, 'resetTime' => $reset]]],
            ['displayName' => 'Claude and GPT models', 'buckets' => [['bucketId' => '3p-'.$window, 'window' => $window, 'remainingFraction' => $thirdParty, 'resetTime' => $reset]]],
        ]];
    }

    /** Gateway tiruan: daftar akun, ringkasan kuota, dan tingkat akun. */
    private function fakeGateway(array $summary, bool $disabled = false, array $cooldowns = [], array $extraAccounts = []): void
    {
        config(['ai.cliproxy.base_url' => 'http://127.0.0.1:8317', 'ai.cliproxy.management_key' => 'mk', 'ai.cliproxy.api_key' => 'k', 'ai.cliproxy.agents_enabled' => true]);
        Cache::flush();
        // Http::fake menumpuk callback (yang pertama menang): isi gateway diganti lewat properti.
        $this->gateway = compact('summary', 'disabled', 'cooldowns', 'extraAccounts');
        if ($this->gatewayFaked) {
            return;
        }
        $this->gatewayFaked = true;
        Http::fake(function (Request $request) {
            ['summary' => $summary, 'disabled' => $disabled, 'cooldowns' => $cooldowns, 'extraAccounts' => $extraAccounts] = $this->gateway;
            if (str_contains($request->url(), '/v0/management/auth-files')) {
                return Http::response(['files' => array_merge([[
                    'provider' => 'antigravity', 'label' => 'akun@example.test', 'auth_index' => 'abc123', 'project_id' => 'proj',
                    'disabled' => $disabled, 'status' => $disabled ? 'disabled' : 'active', 'cooldowns' => $cooldowns,
                ]], $extraAccounts)]);
            }
            if (str_contains($request->url(), '/v8/management/requests/api-call')) {
                $target = (string) $request['url'];
                $body = str_contains($target, 'loadCodeAssist')
                    ? ['currentTier' => ['id' => 'free-tier', 'name' => 'Antigravity'], 'paidTier' => ['id' => 'free-tier', 'name' => 'Antigravity Starter Quota']]
                    : $summary;

                return Http::response(['status_code' => 200, 'body' => json_encode($body)]);
            }

            return Http::response(['error' => 'tidak di-fake'], 404);
        });
    }

    public function test_windows_tier_and_models_are_parsed_and_full_windows_have_no_fixed_reset(): void
    {
        $windows = AntigravityQuota::parseSummary($this->summary(1.0, 0.4, '2026-11-22T06:00:00Z'));

        $this->assertSame('gemini', $windows[0]['group']);
        $this->assertSame('Mingguan', $windows[0]['window_label']);
        $this->assertFalse($windows[0]['reset_fixed']); // penuh: reset hanya sekarang + 7 hari
        $this->assertSame('3p', $windows[1]['group']);
        $this->assertTrue($windows[1]['reset_fixed']);  // terpakai: waktu reset tetap
        $this->assertSame('5 jam', AntigravityQuota::parseSummary($this->summary(0.5, 0.5, null, '5h'))[0]['window_label']);

        $this->assertSame(['free', 'Antigravity Starter Quota'], AntigravityQuota::parseTier(['currentTier' => ['id' => 'free-tier', 'name' => 'A'], 'paidTier' => ['id' => 'free-tier', 'name' => 'Antigravity Starter Quota']]));
        $this->assertSame(['pro', 'P'], AntigravityQuota::parseTier(['paidTier' => ['id' => 'g1-pro-tier', 'name' => 'P']]));

        $this->assertSame('gemini', AntigravityQuota::groupForModel('scrumgateway/gemini-3.1-flash-lite'));
        $this->assertSame('3p', AntigravityQuota::groupForModel('claude-sonnet-4-6'));
        $this->assertNull(AntigravityQuota::groupForModel('deepseek-v4.1-flash'));
    }

    public function test_banner_shows_countdown_when_reset_is_known_and_honest_text_when_not(): void
    {
        $user = User::factory()->create();
        Cache::flush();
        Setting::put(ActiveModelService::SETTING_KEY, 'gemini-3.1-flash-lite');
        AiQuota::exhausted('gemini-3.1-flash-lite', 'Resource has been exhausted (e.g. check quota).');

        $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
        $this->assertStringContainsString('Waktu pulih belum diketahui', $html);
        $this->assertStringNotContainsString('data-reset-at', $html);

        AiQuota::annotate(['reset_at' => now()->addDays(6)->addHours(23)->utc()->toIso8601String(), 'reset_certain' => true]);
        $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
        $this->assertStringContainsString('Perkiraan pulih', $html);
        $this->assertStringContainsString('data-reset-at', $html);
        $this->assertMatchesRegularExpression('/6 hari 2[23] jam lagi/', $html);
        $this->assertStringContainsString('WIB', $html);
        $this->actingAs($user)->getJson(route('activity.active'))->assertJsonPath('alert', fn ($text) => str_contains((string) $text, 'Perkiraan pulih'));
    }

    public function test_watcher_reads_reset_time_when_google_quota_is_empty(): void
    {
        $this->fakeGateway($this->summary(0.0, 1.0, '2026-11-22T06:44:13Z'));
        Setting::put(ActiveModelService::SETTING_KEY, 'gemini-3.1-flash-lite');
        AiQuota::exhausted('gemini-3.1-flash-lite', 'x');

        $this->assertSame('waiting', app(QuotaWatcher::class)->check(force: true, allowChatProbe: false));

        $state = AiQuota::current();
        $this->assertSame('2026-11-22T06:44:13+00:00', Carbon::parse($state['reset_at'])->toIso8601String());
        $this->assertTrue($state['reset_certain']);
        $this->assertNotEmpty($state['next_check_at']);
        $this->assertTrue(Carbon::parse($state['next_check_at'])->lessThanOrEqualTo(now()->addMinutes(16)));
    }

    public function test_watcher_clears_warning_notifies_and_stops_when_quota_returns(): void
    {
        $user = User::factory()->create();
        Queue::fake();
        $this->fakeGateway($this->summary(0.6, 1.0));
        Setting::put(ActiveModelService::SETTING_KEY, 'gemini-3.1-flash-lite');
        AiQuota::exhausted('gemini-3.1-flash-lite', 'x');

        $this->assertSame('recovered', app(QuotaWatcher::class)->check(force: true, allowChatProbe: false));
        $this->assertNull(AiQuota::current());
        $this->assertSame(1, Notification::query()->where('type', 'ai_quota_pulih')->where('user_id', $user->id)->count());
        $this->assertSame('idle', app(QuotaWatcher::class)->check()); // tidak ada lagi yang diawasi
    }

    public function test_watcher_does_not_call_google_for_disabled_accounts_and_respects_schedule(): void
    {
        $this->fakeGateway($this->summary(0.0, 0.0), disabled: true);
        Setting::put(ActiveModelService::SETTING_KEY, 'gemini-3.1-flash-lite');
        AiQuota::exhausted('gemini-3.1-flash-lite', 'x');

        $this->assertSame('waiting', app(QuotaWatcher::class)->check(force: true, allowChatProbe: false));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'requests/api-call'));
        $this->assertNull(AiQuota::current()['reset_at'] ?? null);

        // Jadwal berikutnya di masa depan: pengecekan terjadwal dilewati sampai waktunya.
        $this->assertSame('skipped', app(QuotaWatcher::class)->check());
    }

    public function test_panel_and_refresh_show_plan_windows_and_honest_notes(): void
    {
        $user = User::factory()->create();
        $this->fakeGateway($this->summary(1.0, 0.4, '2026-11-22T06:00:00Z'));

        $html = view('workspace.partials.quota-panel', ['quotaSnapshot' => app(AntigravityQuota::class)->snapshot(true), 'errors' => new ViewErrorBag])->render();
        $this->assertStringContainsString('akun@example.test', $html);
        $this->assertStringContainsString('Gratis', $html);
        $this->assertStringContainsString('Antigravity Starter Quota', $html);
        $this->assertStringContainsString('Kuota masih penuh', $html);   // Gemini 100%: tanpa jadwal reset
        $this->assertStringContainsString('data-reset-at', $html); // Claude/GPT 40%: ada reset tetap
        $this->assertStringContainsString('sisa <strong>40%</strong>', $html);
        $this->assertStringContainsString('hanya punya batas', $html); // semua jendela mingguan: catatan tingkat gratis tampil

        $this->actingAs($user)->post(route('ai-providers.quota.refresh'))->assertRedirect()->assertSessionHas('status');
    }

    private function cooldown(int $seconds = 90): array
    {
        return [['scope' => 'model', 'model_key' => 'gemini-3.1-flash-lite', 'reason' => 'quota', 'retry_at' => now()->addSeconds($seconds)->utc()->toIso8601String(), 'backoff_level' => 5, 'http_status' => 429]];
    }

    public function test_gateway_cooldown_blocks_model_only_when_every_active_account_waits(): void
    {
        $this->fakeGateway($this->summary(1.0, 1.0), cooldowns: $this->cooldown());
        $cool = app(CliProxyApiClient::class)->modelCooldown('scrumgateway/gemini-3.1-flash-lite');
        $this->assertNotNull($cool);
        $this->assertSame(5, $cool['level']);
        $this->assertNull(app(CliProxyApiClient::class)->modelCooldown('gemini-3.5-flash-lite'));

        $this->fakeGateway($this->summary(1.0, 1.0), cooldowns: $this->cooldown(), extraAccounts: [['provider' => 'antigravity', 'auth_index' => 'free1', 'disabled' => false, 'cooldowns' => []]]);
        $this->assertNull(app(CliProxyApiClient::class)->modelCooldown('gemini-3.1-flash-lite'));

        $this->fakeGateway($this->summary(1.0, 1.0), cooldowns: [['model_key' => 'gemini-3.1-flash-lite', 'retry_at' => now()->subMinute()->toIso8601String()]]);
        $this->assertNull(app(CliProxyApiClient::class)->modelCooldown('gemini-3.1-flash-lite'));
    }

    public function test_rate_limit_with_full_weekly_quota_keeps_warning_instead_of_false_recovery(): void
    {
        // Kasus run #39: kuota mingguan 100% tetapi Google menolak 429 berulang.
        $user = User::factory()->create();
        Queue::fake();
        $this->fakeGateway($this->summary(1.0, 1.0), cooldowns: $this->cooldown());
        Setting::put(ActiveModelService::SETTING_KEY, 'gemini-3.1-flash-lite');
        AiQuota::exhausted('gemini-3.1-flash-lite', 'All credentials for model gemini-3.1-flash-lite are cooling down');

        $this->assertSame('waiting', app(QuotaWatcher::class)->check(force: true, allowChatProbe: false));
        $state = AiQuota::current();
        $this->assertSame('cooldown', $state['kind']);
        $this->assertNotEmpty($state['reset_at']);
        $this->assertSame(0, Notification::query()->where('type', 'ai_quota_pulih')->count());

        $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
        $this->assertStringContainsString('AI dibatasi sementara', $html);
        $this->assertStringContainsString('Dicoba lagi sekitar', $html);

        // Jeda selesai: masih ditunggu 10 menit sebelum dianggap pulih.
        $this->fakeGateway($this->summary(1.0, 1.0));
        Cache::put('ai-quota:exhausted', $state, 3600);
        $this->assertSame('waiting', app(QuotaWatcher::class)->check(force: true, allowChatProbe: false));
        $this->assertNotNull(AiQuota::current());
        $this->travel(11)->minutes();
        $this->assertSame('recovered', app(QuotaWatcher::class)->check(force: true, allowChatProbe: false));
        $this->assertNull(AiQuota::current());
    }
}
