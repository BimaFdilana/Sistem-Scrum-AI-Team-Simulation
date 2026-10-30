<?php

namespace App\Http\Controllers;

use App\Services\AiStatusService;
use App\Services\CliProxyApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AiProviderController extends Controller
{
    /**
     * Provider yang bisa diisi API key lewat menu. Key = nama grup di
     * konfigurasi api-keys gateway (bukan slug kartu OAuth).
     */
    public const API_KEY_PROVIDERS = [
        'gemini' => 'Gemini (Google AI Studio)',
        'vertex' => 'Vertex AI',
        'claude' => 'Claude (Anthropic)',
        'codex' => 'Codex (OpenAI)',
        'xai' => 'xAI (Grok)',
        'meta' => 'Meta (Muse)',
        'openai-compatibility' => 'OpenAI-compatible (custom)',
    ];

    public function index(CliProxyApiClient $client, AiStatusService $status): View
    {
        $gatewayUp = $client->isAvailable();
        $managementUp = $client->isManagementAvailable();

        return view('workspace.ai-providers', [
            'providers' => $status->providers(),
            'gatewayUp' => $gatewayUp,
            'managementUp' => $managementUp,
            'usage' => $this->usageByProviderCard($status),
            'apiKeyProviders' => self::API_KEY_PROVIDERS,
            'apiKeys' => $this->maskedApiKeyGroups($client),
            'v8Up' => $client->isV8ManagementAvailable(),
            'activePage' => 'ai-providers',
        ]);
    }

    /**
     * Pemakaian token 30 hari terakhir, dikelompokkan per kartu provider
     * (bukan panel terpisah): label pemakaian dari AiUsageRecorder
     * dipetakan ke slug kartu OAuth. Label tanpa kartu (mis. provider
     * API-key baru seperti Mimo, atau 'Lainnya') dikembalikan di 'other'
     * agar tidak ada data yang hilang dari halaman. Sejak halaman hanya
     * menampilkan kartu provider yang aktif, pemakaian untuk kartu yang
     * sedang tidak terhubung ikut dipindah ke 'other' — kalau tidak,
     * pemakaian lama itu lenyap dari halaman saat providernya diputus.
     *
     * @return array{by_slug: array<string, array{total:int, calls:int, models: array<int, array{model:string, total:int, calls:int}>}>, other: array<int, array{provider:string, total:int, calls:int}>, grand_total:int}
     */
    private function usageByProviderCard(AiStatusService $status): array
    {
        $summary = \App\Services\AiUsageRecorder::summary(30);
        $labelToSlug = ['Google' => 'antigravity', 'OpenAI' => 'codex', 'Anthropic' => 'anthropic'];
        $connected = [];
        foreach ($status->providers() as $slug => $info) {
            if ($info['connected']) {
                $connected[$slug] = true;
            }
        }
        $bySlug = [];
        $other = [];
        foreach ($summary['providers'] as $row) {
            $slug = $labelToSlug[$row['provider']] ?? null;
            if ($slug !== null && isset($connected[$slug])) {
                $bySlug[$slug] = [
                    'total' => $row['total'],
                    'calls' => $row['calls'],
                    'models' => array_values(array_filter(
                        $summary['models'],
                        fn ($model) => $model['provider'] === $row['provider']
                    )),
                ];
            } else {
                $other[] = $row;
            }
        }

        return ['by_slug' => $bySlug, 'other' => $other, 'grand_total' => $summary['grand_total']];
    }

    public function connect(string $provider, CliProxyApiClient $client): View|RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        $auth = $client->authUrl($provider);

        if (! $auth) {
            return redirect()->route('ai-providers.index')
                ->with('error', 'Gagal meminta link login dari gateway. Pastikan CLIProxyAPI berjalan dan Management API aktif.');
        }

        session()->put("ai_provider_state.{$provider}", $auth['state']);

        return view('workspace.ai-provider-connect', [
            'provider' => $provider,
            'meta' => $providers[$provider],
            'authUrl' => $auth['url'],
            'state' => $auth['state'],
            'activePage' => 'ai-providers',
        ]);
    }

    public function callback(Request $request, string $provider, CliProxyApiClient $client): RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        // Verifikasi token refresh bisa menunggu gateway sampai ~60 detik.
        set_time_limit(120);

        $request->validate([
            'callback_url' => ['required', 'string', 'max:4000'],
        ], [
            'callback_url.required' => 'Tempel URL callback dari address bar browser kamu.',
        ]);

        $callbackUrl = $request->string('callback_url')->toString();
        $code = CliProxyApiClient::extractCode($callbackUrl);

        if (! $code) {
            return back()->with('error', 'URL tersebut tidak mengandung kode otorisasi (parameter ?code=...). Pastikan kamu meng-copy seluruh URL dari address bar setelah menekan Allow/Izinkan.');
        }

        // Bedakan gateway mati vs kode invalid: bila gateway tidak terjangkau,
        // kode belum terpakai sehingga user cukup coba lagi dengan URL yang sama
        // setelah gateway pulih — tidak perlu mengulang login dari awal.
        if (! $client->isAvailable()) {
            return back()->with('error', 'Gateway AI tidak dapat dihubungi saat ini. Kode login kamu belum terpakai — tunggu gateway pulih lalu tempel URL yang sama lagi, tidak perlu login ulang dari awal.');
        }

        // Utamakan state dari URL yang ditempel: itulah alur login yang
        // benar-benar diselesaikan user. State session hanya cadangan bila
        // URL tidak membawanya (mis. user membuka beberapa tab login).
        $state = CliProxyApiClient::extractState($callbackUrl)
            ?? session()->pull("ai_provider_state.{$provider}");

        if (! $state) {
            return redirect()->route('ai-providers.connect', ['provider' => $provider])
                ->with('error', 'Sesi login kedaluwarsa dan URL tidak mengandung state. Silakan mulai lagi dari tombol Hubungkan.');
        }

        if ($client->submitCallback($state, $code)) {
            // Tunggu bukti nyata pertukaran kode berhasil (file token ditulis),
            // bukan sekadar "callback diterima".
            $account = $client->waitForTokenRefresh($provider, 60);

            if ($account !== null) {
                // Gateway tidak mengaktifkan otomatis akun yang pernah diputus.
                if (($account['disabled'] ?? false) && isset($account['name'])) {
                    $client->setAccountDisabled($account['name'], false);
                }

                if ($client->waitForAccountEnabled($provider, 10)) {
                    $client->forgetModelsCache();

                    return redirect()->route('ai-providers.index')
                        ->with('success', $providers[$provider]['label'].' berhasil terhubung.');
                }
            }

            session()->put("ai_provider_state.{$provider}", $state);

            return back()->with('error', 'Kode login diterima, tetapi akun belum aktif di gateway. Kemungkinan kode sudah dipakai atau kedaluwarsa — ulangi proses login dari awal dan tempel URL callback yang baru.');
        }

        session()->put("ai_provider_state.{$provider}", $state);

        return back()->with('error', 'Gagal menyelesaikan login. Kode mungkin kedaluwarsa — coba ulangi proses login dari awal.');
    }

    public function disconnect(string $provider, CliProxyApiClient $client): RedirectResponse
    {
        $providers = config('ai.providers', []);
        abort_unless(isset($providers[$provider]), 404);

        $account = $client->accountForProvider($provider);

        if ($account && isset($account['name']) && $client->disableAccount($account['name'])) {
            // Clean slate: hapus sisa status OAuth yang tertunda agar
            // penghubungan ulang ke provider yang sama mulai dari nol.
            // (Cache katalog model sudah dihapus di dalam disableAccount.)
            session()->forget("ai_provider_state.{$provider}");

            return redirect()->route('ai-providers.index')
                ->with('success', $providers[$provider]['label'].' diputus. Kamu bisa mengaktifkannya lagi kapan pun tanpa login ulang.');
        }

        return redirect()->route('ai-providers.index')
            ->with('error', 'Gagal memutus akun. Coba lagi.');
    }

    // ------------------------------------------------------------------
    // API key (multi key & multi akun) — via Management API v8 gateway.
    // Key disimpan di konfigurasi gateway, BUKAN di database website.
    // Ke halaman HANYA dikirim versi termasking; nilai penuh tidak pernah
    // dirender, tidak pernah di-log, dan tidak pernah dikembalikan
    // sebagai old input saat validasi gagal.
    // ------------------------------------------------------------------

    /**
     * Grup api-keys dari gateway dengan key termasking untuk view.
     * Null = gateway tidak dapat dihubungi (bedakan dari daftar kosong).
     */
    private function maskedApiKeyGroups(CliProxyApiClient $client): ?array
    {
        $groups = $client->apiKeyGroups();

        if ($groups === null) {
            return null;
        }

        $masked = [];

        foreach ($groups as $provider => $list) {
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $group) {
                if (! is_array($group) || ! isset($group['name'])) {
                    continue;
                }

                $keys = [];

                foreach ($group['keys'] ?? [] as $entry) {
                    $keys[] = self::maskKey((string) ($entry['api-key'] ?? ''));
                }

                $models = [];

                foreach ($group['models'] ?? [] as $model) {
                    $models[] = is_array($model)
                        ? ($model['alias'] ?? $model['name'] ?? '')
                        : (string) $model;
                }

                $masked[$provider][] = [
                    'name' => $group['name'],
                    'base_url' => $group['base-url'] ?? null,
                    'keys' => $keys,
                    'quota' => $this->fetchGroupQuota($provider, $group),
                    'models' => array_values(array_filter($models)),
                    'models_raw' => array_values(array_filter(
                        array_map(
                            fn ($model) => is_array($model)
                                ? array_filter(['name' => $model['name'] ?? null, 'alias' => $model['alias'] ?? null])
                                : ['name' => (string) $model],
                            $group['models'] ?? []
                        ),
                        fn ($model) => ! empty($model['name'])
                    )),
                ];
            }
        }

        return $masked;
    }

    /**
     * Ambil kuota agregator untuk satu akun (grup) API key.
     * GET {base-url}/quota dengan key pertama akun, server-side saja:
     * key penuh tidak pernah dirender, dilog, atau dikembalikan ke browser
     * (view hanya menerima hasil normalisasi). Hasil sukses di-cache 60
     * detik per provider+akun agar pemuatan halaman tidak menelepon
     * upstream setiap kali; kegagalan ditandai 30 detik agar endpoint yang
     * mati tidak menghambat setiap pemuatan. Gagal apa pun → null, kartu
     * tetap tampil tanpa blok kuota.
     */
    private function fetchGroupQuota(string $provider, array $group): ?array
    {
        $baseUrl = $group['base-url'] ?? null;
        $key = $group['keys'][0]['api-key'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($key) || $key === '') {
            return null;
        }

        $cacheKey = 'api-quota:'.$provider.':'.$group['name'];
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return isset($cached['_unavailable']) ? null : $cached;
        }

        try {
            $response = Http::timeout(5)->withToken($key)
                ->acceptJson()->get(rtrim($baseUrl, '/').'/quota');

            if (! $response->successful()) {
                Cache::put($cacheKey, ['_unavailable' => true], 30);

                return null;
            }

            $quota = self::normalizeQuota($response->json());

            if ($quota === null) {
                Cache::put($cacheKey, ['_unavailable' => true], 30);

                return null;
            }

            Cache::put($cacheKey, $quota, 60);

            return $quota;
        } catch (Throwable) {
            Cache::put($cacheKey, ['_unavailable' => true], 30);

            return null;
        }
    }

    /**
     * Normalisasi respons kuota agregator ke bentuk kartu: id, name,
     * status, maxTokens, usedTokens, remainingTokens, usagePercent, usage
     * (skalar saja), expiresAt (diformat bila bisa diparse). Menerima
     * objek langsung, {data: objek}, atau list (dipakai item pertama).
     * Bentuk tak dikenal (mis. {error: ...}) → null.
     */
    private static function normalizeQuota(mixed $json): ?array
    {
        if (is_array($json) && array_is_list($json)) {
            $json = $json[0] ?? null;
        }

        if (is_array($json) && isset($json['data'])) {
            return self::normalizeQuota($json['data']);
        }

        if (! is_array($json)) {
            return null;
        }

        $known = array_intersect_key($json, array_flip([
            'id', 'name', 'status', 'maxTokens', 'usedTokens',
            'remainingTokens', 'usagePercent', 'usage', 'expiresAt',
        ]));

        if ($known === []) {
            return null;
        }

        $quota = [];

        foreach (['id', 'name', 'status'] as $field) {
            if (isset($known[$field]) && is_scalar($known[$field])) {
                $quota[$field] = (string) $known[$field];
            }
        }

        foreach (['maxTokens', 'usedTokens', 'remainingTokens'] as $field) {
            if (isset($known[$field]) && is_numeric($known[$field])) {
                $quota[$field] = (int) $known[$field];
            }
        }

        if (isset($known['usagePercent']) && is_numeric($known['usagePercent'])) {
            $quota['usagePercent'] = round((float) $known['usagePercent'], 1);
        }

        if (isset($known['usage']) && is_scalar($known['usage'])) {
            $quota['usage'] = (string) $known['usage'];
        }

        if (! isset($quota['remainingTokens']) && isset($quota['maxTokens'], $quota['usedTokens'])) {
            $quota['remainingTokens'] = max(0, $quota['maxTokens'] - $quota['usedTokens']);
        }

        if (! isset($quota['usagePercent']) && isset($quota['maxTokens'], $quota['usedTokens']) && $quota['maxTokens'] > 0) {
            $quota['usagePercent'] = round($quota['usedTokens'] / $quota['maxTokens'] * 100, 1);
        }

        if (isset($known['expiresAt']) && is_scalar($known['expiresAt'])) {
            $raw = (string) $known['expiresAt'];

            try {
                $quota['expiresAt'] = \Carbon\Carbon::parse($raw)->format('d M Y, H:i');
            } catch (Throwable) {
                $quota['expiresAt'] = $raw;
            }
        }

        return $quota;
    }

    private static function maskKey(string $key): string
    {
        $len = mb_strlen($key);

        if ($len <= 8) {
            return mb_substr($key, 0, 2).'…'.mb_substr($key, -2);
        }

        return mb_substr($key, 0, 4).'…'.mb_substr($key, -4);
    }

    /** Tambah satu akun (grup) API key baru untuk satu provider. */
    public function storeApiKeyAccount(Request $request, CliProxyApiClient $client): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['required', 'string', Rule::in(array_keys(self::API_KEY_PROVIDERS))],
            'group' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'api_key' => ['required', 'string', 'min:8', 'max:500'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'models_text' => ['nullable', 'string', 'max:5000'],
            'model_name' => ['nullable', 'string', 'max:200'],
            'model_alias' => ['nullable', 'string', 'max:200'],
        ], [
            'group.regex' => 'Nama akun hanya boleh huruf kecil, angka, dash, dan underscore, diawali huruf/angka.',
        ]);

        // Provider custom wajib membawa endpoint + minimal satu model.
        $validator->sometimes('base_url', 'required', fn ($input) => $input->provider === 'openai-compatibility');

        // Validasi daftar model ikut di validator agar error-nya muncul
        // bersama error field lain (mis. base_url) dalam satu respons.
        $validator->after(function ($validator) use ($request) {
            if (self::keyHasGatewayRejectedChars($request->input('api_key', ''))) {
                $validator->errors()->add('api_key', 'API key mengandung karakter yang ditolak gateway (karakter emoji/tak terlihat). Salin ulang key langsung dari dashboard provider dan pastikan tidak ada karakter aneh yang ikut.');
            }

            $parsed = self::parseModelLines($request->input('models_text'), $request->input('model_name'), $request->input('model_alias'));

            if ($parsed['invalid'] !== null) {
                $validator->errors()->add('models_text', 'Baris model tidak valid: "'.$parsed['invalid'].'". Pakai huruf, angka, titik, dash, underscore, slash, atau titik dua, diawali huruf/angka; alias ditulis setelah "|".');
            } elseif ($request->input('provider') === 'openai-compatibility' && $parsed['models'] === []) {
                $validator->errors()->add('models_text', 'Provider custom wajib membawa minimal satu model.');
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('api_key'))->with('api_key_dialog', true);
        }

        $data = $validator->validated();

        // Daftar model: textarea (satu per baris, opsional "|alias") diutamakan;
        // field model tunggal lama tetap didukung bila textarea kosong.
        // (Validitasnya sudah dijamin lolos validator di atas.)
        $parsed = self::parseModelLines($data['models_text'] ?? null, $data['model_name'] ?? null, $data['model_alias'] ?? null);
        $groups = $client->apiKeyGroups();

        if ($groups === null) {
            return back()->with('error', 'Gateway tidak dapat dihubungi. Key belum disimpan — coba lagi setelah gateway pulih.')
                ->withInput($request->except('api_key'));
        }

        $list = $groups[$data['provider']] ?? [];

        foreach ($list as $group) {
            if (($group['name'] ?? null) === $data['group']) {
                return back()->with('error', 'Nama akun sudah dipakai untuk provider ini. Pakai nama lain atau tambah key ke akun yang sudah ada.')
                    ->withInput($request->except('api_key'));
            }
        }

        $new = ['name' => $data['group'], 'keys' => [['api-key' => $data['api_key'], 'weight' => 1]]];

        if (! empty($data['base_url'])) {
            $new['base-url'] = $data['base_url'];
        }

        if ($parsed['models'] !== []) {
            $new['models'] = $parsed['models'];
        }

        $list[] = $new;

        if (! $client->putApiKeyGroups($data['provider'], $list)) {
            return back()->with('error', 'Gateway menolak konfigurasi ini. Periksa kembali provider, endpoint, dan modelnya.'.$this->gatewayErrorSuffix($client))
                ->withInput($request->except('api_key'))
                ->with('api_key_dialog', true);
        }

        return redirect()->route('ai-providers.index')
            ->with('success', 'Akun API key "'.$data['group'].'" ('.self::API_KEY_PROVIDERS[$data['provider']].') ditambahkan ke gateway.');
    }

    /** Tambah satu key lagi ke akun (grup) yang sudah ada. */
    public function storeApiKey(Request $request, string $provider, string $group, CliProxyApiClient $client): RedirectResponse
    {
        abort_unless(isset(self::API_KEY_PROVIDERS[$provider]), 404);

        // Form inline tidak menampilkan $errors (hanya flash session('error')),
        // jadi karakter key yang ditolak gateway ditolak di sini dengan flash,
        // bukan lewat validator — pesannya tetap terlihat di atas halaman.
        if (self::keyHasGatewayRejectedChars($request->input('api_key', ''))) {
            return back()->with('error', 'API key mengandung karakter yang ditolak gateway (karakter emoji/tak terlihat). Salin ulang key langsung dari dashboard provider dan pastikan tidak ada karakter aneh yang ikut.');
        }

        $validator = Validator::make($request->all(), [
            'api_key' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('api_key'));
        }

        $groups = $client->apiKeyGroups();

        if ($groups === null) {
            return back()->with('error', 'Gateway tidak dapat dihubungi. Key belum disimpan — coba lagi setelah gateway pulih.')
                ->withInput($request->except('api_key'));
        }

        $list = $groups[$provider] ?? [];
        $found = false;

        foreach ($list as &$existing) {
            if (($existing['name'] ?? null) === $group) {
                $existing['keys'][] = ['api-key' => $validator->validated()['api_key'], 'weight' => 1];
                $found = true;
                break;
            }
        }

        unset($existing);

        if (! $found) {
            return redirect()->route('ai-providers.index')->with('error', 'Akun tidak ditemukan di gateway.');
        }

        if (! $client->putApiKeyGroups($provider, $list)) {
            return back()->with('error', 'Gateway menolak konfigurasi ini.'.$this->gatewayErrorSuffix($client))->withInput($request->except('api_key'));
        }

        return redirect()->route('ai-providers.index')
            ->with('success', 'Key baru ditambahkan ke akun "'.$group.'". Gateway akan merotasinya bersama key lain di akun itu.');
    }

    /** Hapus satu key dari akun. Key terakhir yang dihapus ikut menghapus akunnya. */
    public function destroyApiKey(string $provider, string $group, int $index, CliProxyApiClient $client): RedirectResponse
    {
        abort_unless(isset(self::API_KEY_PROVIDERS[$provider]), 404);

        $groups = $client->apiKeyGroups();

        if ($groups === null) {
            return redirect()->route('ai-providers.index')->with('error', 'Gateway tidak dapat dihubungi. Key belum dihapus.');
        }

        $list = $groups[$provider] ?? [];

        foreach ($list as $i => $existing) {
            if (($existing['name'] ?? null) !== $group) {
                continue;
            }

            $keys = $existing['keys'] ?? [];

            if (! isset($keys[$index])) {
                return redirect()->route('ai-providers.index')->with('error', 'Key tidak ditemukan.');
            }

            unset($keys[$index]);
            $keys = array_values($keys);

            if ($keys === []) {
                unset($list[$i]);
                $list = array_values($list);
                $message = 'Key terakhir dihapus, akun "'.$group.'" ikut dihapus dari gateway.';
            } else {
                $list[$i]['keys'] = $keys;
                $message = 'Key dihapus dari akun "'.$group.'".';
            }

            if (! $client->putApiKeyGroups($provider, $list)) {
                return redirect()->route('ai-providers.index')->with('error', 'Gateway menolak perubahan ini. Key belum dihapus.'.$this->gatewayErrorSuffix($client));
            }

            return redirect()->route('ai-providers.index')->with('success', $message);
        }

        return redirect()->route('ai-providers.index')->with('error', 'Akun tidak ditemukan di gateway.');
    }

    /** Hapus satu akun (grup) beserta seluruh key-nya. */
    public function destroyApiKeyAccount(string $provider, string $group, CliProxyApiClient $client): RedirectResponse
    {
        abort_unless(isset(self::API_KEY_PROVIDERS[$provider]), 404);

        $groups = $client->apiKeyGroups();

        if ($groups === null) {
            return redirect()->route('ai-providers.index')->with('error', 'Gateway tidak dapat dihubungi. Akun belum dihapus.');
        }

        $list = array_values(array_filter(
            $groups[$provider] ?? [],
            fn ($existing) => ($existing['name'] ?? null) !== $group
        ));

        if (count($list) === count($groups[$provider] ?? [])) {
            return redirect()->route('ai-providers.index')->with('error', 'Akun tidak ditemukan di gateway.');
        }

        if (! $client->putApiKeyGroups($provider, $list)) {
            return redirect()->route('ai-providers.index')->with('error', 'Gateway menolak perubahan ini. Akun belum dihapus.'.$this->gatewayErrorSuffix($client));
        }

        return redirect()->route('ai-providers.index')
            ->with('success', 'Akun "'.$group.'" beserta seluruh key-nya dihapus dari gateway.');
    }

    /** Akhiran pesan error dengan alasan gateway (bila ada), sudah disensor dari key. */
    private function gatewayErrorSuffix(CliProxyApiClient $client): string
    {
        $reason = $client->lastGatewayError();

        return $reason !== null && $reason !== '' ? ' Alasan gateway: '.Str::limit($reason, 200) : '';
    }

    /**
     * Uji endpoint + key ke upstream tanpa menyimpan apa pun. Key dipakai
     * sekali untuk panggilan ini saja, tidak dicatat dan tidak dikembalikan.
     * Hanya skema http/https yang diizinkan (cegah URL non-HTTP).
     */
    public function checkApiKeyConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(self::API_KEY_PROVIDERS))],
            'api_key' => ['required', 'string', 'min:8', 'max:500'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'models_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $baseUrl = rtrim((string) ($data['base_url'] ?? ''), '/');

        if ($baseUrl === '' || ! in_array((string) parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Isi Endpoint (base URL) http/https dulu untuk memakai Check connection.',
            ]);
        }

        try {
            $response = Http::timeout(15)
                ->withToken($data['api_key'])
                ->acceptJson()
                ->get($baseUrl.'/models');

            $status = $response->status();
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Endpoint tidak dapat dihubungi (timeout / jaringan). Periksa kembali endpoint-nya.',
            ]);
        }

        if ($status === 401 || $status === 403) {
            return response()->json([
                'ok' => false,
                'message' => "Upstream menjawab {$status} — endpoint terjangkau, tapi key ditolak. Periksa kembali key-nya.",
            ]);
        }

        if (! $response->successful()) {
            return response()->json([
                'ok' => false,
                'message' => "Upstream menjawab {$status}. Periksa kembali endpoint dan key-nya.",
            ]);
        }

        $upstreamModels = [];
        $decoded = $response->json();
        if (is_array($decoded['data'] ?? null)) {
            foreach ($decoded['data'] as $model) {
                if (isset($model['id'])) {
                    $upstreamModels[] = (string) $model['id'];
                }
            }
        }

        $result = [
            'ok' => true,
            'message' => 'Endpoint terjangkau dan key diterima.',
            'upstream_models' => count($upstreamModels),
        ];

        $parsed = self::parseModelLines($data['models_text'] ?? null, null, null);
        if ($parsed['models'] !== []) {
            $wanted = array_column($parsed['models'], 'name');
            $result['matched'] = array_values(array_intersect($wanted, $upstreamModels));
            $result['missing'] = array_values(array_diff($wanted, $upstreamModels));
        }

        return response()->json($result);
    }

    /**
     * Gateway menolak key yang mengandung karakter astral (di atas U+FFFF —
     * terkirim sebagai surrogate pair oleh json_encode) atau byte DEL 0x7F:
     * keduanya gagal di lapisan YAML gateway dengan 400 invalid_body.
     * Karakter non-ASCII BMP (é, CJK, dsb) lolos, jadi jangan tolak semua
     * non-ASCII — hanya dua pola ini yang terbukti ditolak (diuji 2026-10-31
     * pada gateway 8.0.47 lokal melawan body persis dari log VPS).
     */
    private static function keyHasGatewayRejectedChars(string $key): bool
    {
        return preg_match('/[\x{10000}-\x{10FFFF}\x7F]/u', $key) === 1;
    }

    /**
     * Parse daftar model dari textarea (satu per baris, opsional "|alias")
     * atau dari field model tunggal lama. Kembalikan ['models' => [...],
     * 'invalid' => ?string] — 'invalid' berisi baris yang ditolak.
     */
    private static function parseModelLines(?string $text, ?string $singleName, ?string $singleAlias): array
    {
        $models = [];
        $seen = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$name, $alias] = array_pad(explode('|', $line, 2), 2, null);
            $name = trim((string) $name);
            $alias = $alias !== null ? trim($alias) : null;

            if ($name === '' || strlen($name) > 200
                || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:-]*$/', $name)
                || ($alias !== null && ($alias === '' || strlen($alias) > 200))) {
                return ['models' => [], 'invalid' => $line];
            }

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $model = ['name' => $name];

            if ($alias !== null) {
                $model['alias'] = $alias;
            }

            $models[] = $model;
        }

        if ($models === [] && ! empty($singleName)) {
            $singleName = trim($singleName);

            if (strlen($singleName) > 200 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:-]*$/', $singleName)) {
                return ['models' => [], 'invalid' => $singleName];
            }

            $model = ['name' => $singleName];

            if (! empty($singleAlias)) {
                $model['alias'] = trim($singleAlias);
            }

            $models[] = $model;
        }

        if (count($models) > 200) {
            return ['models' => [], 'invalid' => 'terlalu banyak model (maks 200)'];
        }

        return ['models' => $models, 'invalid' => null];
    }

    /** Ubah daftar model satu akun (grup) yang sudah ada. */
    public function updateApiKeyModels(Request $request, string $provider, string $group, CliProxyApiClient $client): RedirectResponse
    {
        abort_unless(isset(self::API_KEY_PROVIDERS[$provider]), 404);

        $validator = Validator::make($request->all(), [
            'models_text' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $parsed = self::parseModelLines($validator->validated()['models_text'], null, null);

        if ($parsed['invalid'] !== null) {
            return back()->withErrors(['models_text' => 'Baris model tidak valid: "'.$parsed['invalid'].'". Pakai huruf, angka, titik, dash, underscore, slash, atau titik dua, diawali huruf/angka; alias ditulis setelah "|".']);
        }

        if ($parsed['models'] === []) {
            return back()->withErrors(['models_text' => 'Daftar model tidak boleh kosong.']);
        }

        $groups = $client->apiKeyGroups();

        if ($groups === null) {
            return back()->with('error', 'Gateway tidak dapat dihubungi. Daftar model belum diubah.');
        }

        $list = $groups[$provider] ?? [];
        $found = false;

        foreach ($list as &$existing) {
            if (($existing['name'] ?? null) === $group) {
                $existing['models'] = $parsed['models'];
                $found = true;
                break;
            }
        }

        unset($existing);

        if (! $found) {
            return redirect()->route('ai-providers.index')->with('error', 'Akun tidak ditemukan di gateway.');
        }

        if (! $client->putApiKeyGroups($provider, $list)) {
            return back()->with('error', 'Gateway menolak perubahan ini. Daftar model belum diubah.'.$this->gatewayErrorSuffix($client));
        }

        return redirect()->route('ai-providers.index')
            ->with('success', 'Daftar model akun "'.$group.'" diperbarui ('.count($parsed['models']).' model).');
    }
}
