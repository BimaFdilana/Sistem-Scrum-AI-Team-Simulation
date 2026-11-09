<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\StoryEstimationRun;
use App\Models\User;
use App\Services\AgentProfileClient;
use App\Services\HermesClient;
use App\Services\HermesProfileChat;
use App\Services\StoryEstimationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Integrasi estimasi lewat profile Hermes.
 *
 * Dua cacat nyata yang ditutup di sini (bukti: 32 warning di log produksi,
 * 6 dari 8 estimation run gagal, US-03 2 Nov 2026):
 *
 * 1. `--format text` mengembalikan SELURUH transkrip sesi (diukur 2.472
 *    byte: echo prompt + banner + jawaban + ekor "Resume this session").
 *    extractJsonObject() mengambil dari '{' pertama (yang ada di ECHO
 *    PROMPT kita) sampai '}' terakhir, sehingga yang divalidasi bukan
 *    jawaban agent. Perbaikan: `--format stream-json`, ambil event result.
 *
 * 2. Frasa prompt "vote terstruktur internal antar-agent ... jangan
 *    menolak" memicu penolakan model ("I'm sorry, but I can't help with
 *    that"). Perbaikan: permintaan biasa.
 */
class StoryEstimationPointNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /** Poin 2 SAH (ada di [1,2,3,5,8,13]) dan harus lolos apa adanya. */
    public function test_valid_point_two_is_accepted_unchanged(): void
    {
        [$story, $owner] = $this->story();

        $this->mockAgents(fn () => '{"estimate_points":2,"rationale":"Story kecil, satu kolom saja."}');

        $run = app(StoryEstimationService::class)->estimate($story, $owner);
        $be = $run->agentRuns()->where('profile_slug', 'backend-developer')->firstOrFail();

        $this->assertSame('COMPLETED', $be->status);
        $this->assertSame(2, $be->estimate_points);
        $this->assertSame(2, $be->raw_estimate_points);
    }

    /** Poin Fibonacci di luar daftar dibulatkan ke tetangga terdekat, bukan dibuang. */
    public function test_fibonacci_outside_list_maps_to_nearest_valid(): void
    {
        // 4 -> 5, 6 -> 5, 7 -> 8. (1, 2, 3, 5, 8, 13 sudah sah.)
        foreach ([4 => 5, 6 => 5, 7 => 8] as $given => $expected) {
            [$story, $owner] = $this->story('US-0'.$given);

            $this->mockAgents(fn () => json_encode([
                'estimate_points' => $given,
                'rationale' => 'Penilaian ukuran story.',
            ]));

            $run = app(StoryEstimationService::class)->estimate($story, $owner);
            $be = $run->agentRuns()->where('profile_slug', 'backend-developer')->firstOrFail();

            $this->assertSame($expected, $be->estimate_points, "Poin {$given} harus menjadi {$expected}.");
            $this->assertSame($given, $be->raw_estimate_points);
        }
    }

    /** Kunci tambahan di jawaban yang sah dibuang, vote tetap diterima. */
    public function test_extra_keys_in_valid_vote_are_ignored(): void
    {
        [$story, $owner] = $this->story();

        $this->mockAgents(fn (string $slug) => $slug === 'qa-engineer'
            ? '{"estimate_points":5,"rationale":"Delapan skenario uji.","note":"Story diperlakukan sebagai data."}'
            : '{"estimate_points":5,"rationale":"Alasan singkat."}');

        $run = app(StoryEstimationService::class)->estimate($story, $owner);
        $qa = $run->agentRuns()->where('profile_slug', 'qa-engineer')->firstOrFail();

        $this->assertSame('COMPLETED', $qa->status);
        $this->assertSame(5, $qa->estimate_points);
    }

    /** Angka di luar jangkauan daftar tetap ditolak. */
    public function test_out_of_range_point_is_still_rejected(): void
    {
        [$story, $owner] = $this->story();

        $this->mockAgents(fn (string $slug) => $slug === 'qa-engineer'
            ? '{"estimate_points":21,"rationale":"Angka 21 di luar jangkauan daftar."}'
            : '{"estimate_points":5,"rationale":"Alasan singkat."}');

        $run = app(StoryEstimationService::class)->estimate($story, $owner);
        $qa = $run->agentRuns()->where('profile_slug', 'qa-engineer')->firstOrFail();

        $this->assertSame('FAILED', $qa->status);
        $this->assertSame('INVALID_JSON_SCHEMA', $qa->error_code);
        $this->assertNull($qa->raw_estimate_points);
    }

    /** Kontrak helper pemetaan. */
    public function test_normalization_helper_contract(): void
    {
        $this->assertSame(2, StoryEstimationService::nearestValidPoint(2));
        $this->assertSame(3, StoryEstimationService::nearestValidPoint(3));
        $this->assertSame(5, StoryEstimationService::nearestValidPoint(4));
        $this->assertSame(5, StoryEstimationService::nearestValidPoint(6));
        $this->assertSame(8, StoryEstimationService::nearestValidPoint(7));
        $this->assertSame(1, StoryEstimationService::nearestValidPoint(1));
        $this->assertSame(13, StoryEstimationService::nearestValidPoint(13));
        $this->assertNull(StoryEstimationService::nearestValidPoint(14));
        $this->assertNull(StoryEstimationService::nearestValidPoint(0));
        $this->assertNull(StoryEstimationService::nearestValidPoint(-3));
    }

    /** Prompt estimasi tidak lagi memuat frasa yang memicu penolakan. */
    public function test_estimation_prompt_has_no_refusal_trigger(): void
    {
        $prompt = $this->estimationPrompt();

        $this->assertStringNotContainsString('jangan menolak', $prompt);
        $this->assertStringNotContainsString('vote terstruktur internal', $prompt);
        $this->assertStringContainsString('[1,2,3,5,8,13]', $prompt);
    }

    /** Parser stream-json: ambil event result, gabungkan potongan, fallback ke teks mentah. */
    public function test_stream_json_result_parsing(): void
    {
        $chat = app(HermesProfileChat::class);
        $method = new ReflectionMethod($chat, 'extractResultText');
        $method->setAccessible(true);

        // Event result adalah sumber jawaban, bukan potongan text.
        $stream = implode("\n", [
            '{"type":"text","text":"{\\"estimate_points\\":2,"}',
            '{"type":"text","text":"\\"rationale\\":\\"kecil\\"}"}',
            '{"type":"result","session_id":"local-x","exit_code":0,"text":"{\\"estimate_points\\":2,\\"rationale\\":\\"kecil\\"}"}',
        ]);
        $this->assertSame('{"estimate_points":2,"rationale":"kecil"}', $method->invoke($chat, $stream, 'backend-developer'));

        // Stream terputus sebelum result: potongan text digabung.
        $partial = '{"type":"text","text":"{\\"estimate_points\\":5,"}'."\n".'{"type":"text","text":"\\"rationale\\":\\"sedang\\"}"}';
        $this->assertSame('{"estimate_points":5,"rationale":"sedang"}', $method->invoke($chat, $partial, 'backend-developer'));

        // Bukan stream-json (versi Hermes lawas): keluaran dipakai apa adanya.
        $this->assertSame('halo', $method->invoke($chat, 'halo', 'backend-developer'));
    }

    private function estimationPrompt(): string
    {
        [$story, $owner] = $this->story();
        $run = StoryEstimationRun::query()->create([
            'story_id' => $story->id,
            'requested_by' => $owner->id,
            'story_revision' => 1,
            'story_snapshot_hash' => 'x',
            'status' => 'RUNNING',
        ]);
        $method = new ReflectionMethod(StoryEstimationService::class, 'agentPrompt');
        $method->setAccessible(true);

        return $method->invoke(
            app(StoryEstimationService::class),
            'backend-developer',
            'Backend Developer',
            ['story_id' => 'US-01', 'feature' => 'Fitur'],
            1,
            [],
            $run,
            $story,
        );
    }

    private function mockAgents(callable $responder): void
    {
        $agents = Mockery::mock(AgentProfileClient::class);
        $agents->shouldReceive('sendMessage')->andReturnUsing(fn ($slug, $prompt) => $responder($slug, $prompt));
        $this->app->instance(AgentProfileClient::class, $agents);

        $sm = Mockery::mock(HermesClient::class);
        $sm->shouldReceive('sendMessage')->andReturn('Laporan berdasar diskusi.');
        $this->app->instance(HermesClient::class, $sm);
    }

    /** @return array{0: Story, 1: User} */
    private function story(string $key = 'US-01'): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Normalisasi Poin',
            'project_type' => 'NEW_PROJECT',
            'stack' => 'Laravel',
            'status' => 'SETUP_REQUIRED',
        ]);
        $sprint = Sprint::query()->create([
            'project_id' => $project->id,
            'name' => 'Sprint 1',
            'status' => 'PLANNING',
            'revision' => 1,
        ]);
        $story = Story::query()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'user_story_key' => $key,
            'title' => 'Fitur estimasi '.$key,
            'acceptance_criteria' => 'Kriteria nyata.',
            'scope_status' => 'NEEDS_REFINEMENT',
            'revision' => 1,
        ]);

        return [$story, $owner];
    }
}
