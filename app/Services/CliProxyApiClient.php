<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Client for the local CLIProxyAPI gateway, covering both the
 * OpenAI-compatible inference API (/v1/...) and the Management API
 * (/v0/management/...) used for OAuth account linking.
 */
class CliProxyApiClient
{
    protected string $baseUrl;

    protected ?string $managementKey;

    protected ?string $apiKey;

    protected int $timeout;

    /** Memoize hasil accounts() selama satu request (hindari N+1 HTTP ke gateway). */
    protected ?array $accountsCache = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ai.cliproxy.base_url'), '/');
        $this->managementKey = config('ai.cliproxy.management_key') ?: null;
        $this->apiKey = config('ai.cliproxy.api_key') ?: null;
        $this->timeout = (int) config('ai.cliproxy.timeout', 15);
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(3)->get($this->baseUrl.'/v1/models');

            // 401/403 both prove the gateway is up and responding; it just
            // requires an API key for unauthenticated callers.
            return $response->successful() || in_array($response->status(), [401, 403], true);
        } catch (Throwable) {
            return false;
        }
    }

    public function isManagementAvailable(): bool
    {
        if (! $this->managementKey) {
            return false;
        }

        try {
            $response = $this->management()->get('/auth-files');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * List linked OAuth accounts: ['files' => [...]].
     * Each file carries id/email/status/disabled plus provider info.
     */
    public function accounts(bool $fresh = false): array
    {
        if ($fresh) {
            $this->forgetAccountsCache();
        }

        if ($this->accountsCache !== null) {
            return $this->accountsCache;
        }

        try {
            $response = $this->management()->get('/auth-files');

            $this->accountsCache = $response->successful() ? (array) $response->json() : ['files' => []];
        } catch (Throwable) {
            $this->accountsCache = ['files' => []];
        }

        return $this->accountsCache;
    }

    /** Paksa refresh cache accounts() (mis. setelah connect/disconnect). */
    public function forgetAccountsCache(): void
    {
        $this->accountsCache = null;
    }

    /**
     * Start an OAuth login: returns ['state' => ..., 'url' => ...].
     */
    public function authUrl(string $provider): ?array
    {
        try {
            $response = $this->management()->get("/{$provider}-auth-url");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return isset($data['url'], $data['state'])
                ? ['url' => $data['url'], 'state' => $data['state']]
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Poll the login status for a given state token.
     */
    public function authStatus(string $state): array
    {
        try {
            $response = $this->management()->get('/get-auth-status', ['state' => $state]);

            return (array) $response->json();
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }

    /**
     * Complete an OAuth login by submitting the authorization code
     * (extracted from the localhost callback URL the user pastes).
     */
    public function submitCallback(string $state, string $code): bool
    {
        try {
            $response = $this->management()->post('/oauth-callback', [
                'state' => $state,
                'code' => $code,
            ]);

            return $response->successful()
                && ($response->json('status') === 'ok');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Enable/disable a linked account by its auth-file name.
     */
    public function setAccountDisabled(string $name, bool $disabled): bool
    {
        try {
            $response = $this->management()->patch('/auth-files/status', [
                'name' => $name,
                'disabled' => $disabled,
            ]);

            if ($response->successful()) {
                // Status akun berubah → katalog model basi, paksa refresh
                // agar halaman Agent langsung mencerminkan kondisi baru.
                $this->forgetModelsCache();

                return true;
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Hapus cache katalog model agar diambil ulang dari gateway. */
    public function forgetModelsCache(): void
    {
        Cache::forget('cliproxy:models');
        $this->forgetAccountsCache();
    }

    /** Disable (disconnect) a linked account by its auth-file name. */
    public function disableAccount(string $name): bool
    {
        return $this->setAccountDisabled($name, true);
    }

    /**
     * Find the linked account file belonging to a provider slug
     * (e.g. 'codex', 'antigravity', 'claude'). Bila ada beberapa file
     * (mis. file lama yang diputus + file baru), prioritaskan yang aktif.
     */
    public function accountForProvider(string $provider, bool $fresh = false): ?array
    {
        $fallback = null;

        foreach ($this->accounts($fresh)['files'] ?? [] as $account) {
            if (! is_array($account)) {
                continue;
            }

            $haystack = implode(' ', array_filter([
                $account['provider'] ?? null,
                $account['type'] ?? null,
                $account['kind'] ?? null,
                $account['name'] ?? null,
                $account['id'] ?? null,
            ]));

            if ($haystack === '' || stripos($haystack, $provider) === false) {
                continue;
            }

            if (! ($account['disabled'] ?? false)) {
                return $account;
            }

            $fallback ??= $account;
        }

        return $fallback;
    }

    /**
     * Tunggu sampai gateway selesai memproses OAuth: file akun baru muncul
     * atau file lama ditulis ulang (token refresh). Kembalikan akunnya,
     * atau null bila timeout. Ini sinyal "pertukaran kode BERHASIL" yang
     * sebenarnya — bukan sekadar "callback diterima".
     */
    public function waitForTokenRefresh(string $provider, int $timeoutSeconds = 60, int $intervalSeconds = 3): ?array
    {
        $before = $this->accountForProvider($provider, true);
        $beforeModtime = is_string($before['modtime'] ?? null) ? $before['modtime'] : null;
        $deadline = time() + max(0, $timeoutSeconds);

        do {
            $account = $this->accountForProvider($provider, true);

            if ($account !== null) {
                $afterModtime = is_string($account['modtime'] ?? null) ? $account['modtime'] : null;
                $isNew = $before === null;
                $wasRewritten = $beforeModtime !== null && $afterModtime !== null && $afterModtime !== $beforeModtime;

                if ($isNew || $wasRewritten) {
                    return $account;
                }
            }

            if (time() >= $deadline) {
                break;
            }

            sleep(min($intervalSeconds, max(1, $deadline - time())));
        } while (true);

        return null;
    }

    /**
     * Tunggu sampai akun provider benar-benar aktif di gateway (ada dan
     * tidak disabled). Pertukaran kode OAuth bisa berjalan asinkron di
     * gateway, jadi "callback diterima" belum berarti "akun terhubung".
     */
    public function waitForAccountEnabled(string $provider, int $timeoutSeconds = 30, int $intervalSeconds = 3): bool
    {
        $deadline = time() + max(0, $timeoutSeconds);

        do {
            $account = $this->accountForProvider($provider, true);

            if ($account !== null && ! ($account['disabled'] ?? false)) {
                return true;
            }

            if (time() >= $deadline) {
                break;
            }

            sleep(min($intervalSeconds, $deadline - time()));
        } while (true);

        return false;
    }

    /**
     * Extract the OAuth `code` (or `error`) from a pasted callback URL.
     */
    public static function extractCode(string $callbackUrl): ?string
    {
        return self::extractCallbackParam($callbackUrl, 'code');
    }

    /**
     * Extract the OAuth `state` from a pasted callback URL. Dipakai agar
     * callback memakai state dari alur login yang benar-benar diselesaikan
     * user (bukan state basi di session bila user membuka beberapa tab).
     */
    public static function extractState(string $callbackUrl): ?string
    {
        return self::extractCallbackParam($callbackUrl, 'state');
    }

    protected static function extractCallbackParam(string $callbackUrl, string $param): ?string
    {
        $parts = parse_url(trim($callbackUrl));

        if (! isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);
        $value = $query[$param] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Apakah jalur agen via CLIProxyAPI aktif. Mati saat testing agar
     * panggilan agen tidak bocor ke gateway asli.
     */
    public function agentsEnabled(): bool
    {
        return (bool) config('ai.cliproxy.agents_enabled', true);
    }

    /**
     * Model IDs available on the OpenAI-compatible inference API.
     * Cached briefly; empty when gateway unreachable or no account linked.
     *
     * @return array<int, array{id: string, owned_by: string}>
     */
    public function models(): array
    {
        if (! $this->apiKey) {
            return [];
        }

        // Jangan cache hasil kosong: bila gateway hiccup sesaat, [] yang
        // ter-cache 60 detik membuat semua agent tampil "terputus" walau
        // gateway sudah pulih. Hanya hasil non-kosong yang disimpan.
        $cached = \Illuminate\Support\Facades\Cache::get('cliproxy:models');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $result = $this->fetchModels();
        if ($result !== []) {
            \Illuminate\Support\Facades\Cache::put('cliproxy:models', $result, 60);
        }

        return $result;
    }

    private function fetchModels(): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)->withToken($this->apiKey)
                ->timeout($this->timeout)->acceptJson()->get('/v1/models');
            if (! $response->successful()) {
                return [];
            }
            $data = $response->json('data');
            if (! is_array($data)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn ($m) => isset($m['id']) ? ['id' => (string) $m['id'], 'owned_by' => (string) ($m['owned_by'] ?? '')] : null,
                $data
            )));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pick the best CLIProxyAPI model for an agent role, honoring the role's
     * preferred_provider from config/agents.php (disetujui Bima 2026-10-13):
     * QA=anthropic, BE=codex, FE=google, SM=model terpintar yang tersedia.
     * Returns null when nothing suitable is available.
     */
    public function modelForRole(string $role): ?string
    {
        if (! $this->agentsEnabled()) {
            return null;
        }
        $models = $this->models();
        if ($models === []) {
            return null;
        }
        $profile = collect(config('agents.profiles'))->firstWhere('slug', $role);
        $preferred = strtolower((string) ($profile['preferred_provider'] ?? 'any'));

        $byProvider = fn (string $provider) => array_values(array_filter(
            $models,
            fn ($m) => self::modelMatchesProvider($m['id'], $m['owned_by'], $provider)
        ));

        $candidates = $byProvider($preferred);
        if ($candidates === [] && $preferred !== 'any') {
            // Fallback: provider mana pun yang tersedia.
            $candidates = $models;
        }
        if ($candidates === []) {
            return null;
        }

        return self::pickBest($candidates);
    }

    /** Model terpintar yang tersedia (untuk Scrum Master). */
    public function smartestModel(): ?string
    {
        if (! $this->agentsEnabled()) {
            return null;
        }
        $models = $this->models();

        return $models === [] ? null : self::pickBest($models);
    }

    /**
     * Model IDs dikelompokkan per provider OAuth: anthropic/codex/google.
     *
     * @return array<string, list<string>>
     */
    public function modelsByProvider(): array
    {
        if (! $this->agentsEnabled()) {
            return [];
        }
        $grouped = array_fill_keys(self::modelProviderSlugs(), []);
        foreach ($this->models() as $m) {
            foreach (array_keys($grouped) as $provider) {
                if (self::modelMatchesProvider($m['id'], $m['owned_by'], $provider)) {
                    $grouped[$provider][] = $m['id'];
                    break;
                }
            }
        }

        return array_filter($grouped);
    }

    /**
     * Model final untuk sebuah peran: hormati pilihan tersimpan (snapshot
     * terverifikasi dari halaman Konfigurasi Agent) bila modelnya masih
     * tersedia saat ini; selain itu pakai pemetaan otomatis modelForRole().
     */
    public function resolveModel(string $role): ?string
    {
        if (! $this->agentsEnabled()) {
            return null;
        }
        $models = $this->models();
        if ($models === []) {
            return null;
        }
        $snapshot = app(AgentConfiguration::class)->current($role);
        $saved = is_array($snapshot) ? ($snapshot['model'] ?? null) : null;
        if (($snapshot['status'] ?? null) === 'verified' && is_string($saved)
            && in_array($saved, array_column($models, 'id'), true)) {
            return $saved;
        }

        return $this->modelForRole($role);
    }

    /**
     * OpenAI-compatible chat completion via CLIProxyAPI.
     * Returns the assistant text, or null on failure.
     * Punya ketahanan: retry + fallback model (lihat chatResilient).
     *
     * @param array{data: string, mime: string}|null $image lampiran gambar (base64); diteruskan lalu dibuang.
     */
    public function chat(string $model, string $systemPrompt, string $userMessage, int $timeout = 120, ?array $image = null): ?string
    {
        return $this->chatResilient($model, $systemPrompt, $userMessage, $timeout, $image)['text'];
    }

    /**
     * Chat dengan ketahanan terhadap gangguan upstream:
     * - Coba ulang (backoff) untuk kegagalan transient: 429/502/503, timeout,
     *   gateway tak terjangkau, respons kosong.
     * - Pindah ke model cadangan dari provider yang sama bila model utama
     *   tidak ditemukan atau terus gagal (model image dikecualikan).
     * - Berhenti langsung untuk kegagalan permanen: 401 (auth), 400.
     *
     * @param array{data: string, mime: string}|null $image
     * @return array{text: ?string, error: ?string, model: ?string}
     */
    public function chatResilient(string $model, string $systemPrompt, string $userMessage, int $timeout = 120, ?array $image = null): array
    {
        if (! $this->agentsEnabled() || ! $this->apiKey) {
            return ['text' => null, 'error' => 'DISABLED', 'model' => null];
        }
        $maxAttempts = max(1, (int) config('ai.cliproxy.chat_attempts', 3));
        $maxFallbacks = max(0, (int) config('ai.cliproxy.chat_fallbacks', 2));

        // Budget waktu absolut: seluruh loop retry tidak boleh melebihi ini,
        // agar tidak menabrak set_time_limit() di controller (fatal error
        // tanpa pesan bersih bila upstream hang).
        $deadline = microtime(true) + (int) config('ai.cliproxy.chat_deadline', 240);

        $lastError = 'UNKNOWN';
        $lastRetryAfter = null;
        foreach (array_slice($this->fallbackCandidates($model), 0, $maxFallbacks + 1) as $candidate) {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if (microtime(true) >= $deadline) {
                    return ['text' => null, 'error' => $lastError === 'UNKNOWN' ? 'TIMEOUT' : $lastError, 'model' => null, 'retry_after' => $lastRetryAfter];
                }
                $outcome = $this->attemptChat($candidate, $systemPrompt, $userMessage, $timeout, $image);
                if ($outcome['status'] === 'ok') {
                    AiUsageRecorder::record($candidate, $outcome['usage'] ?? []);
                    return ['text' => $outcome['text'], 'error' => null, 'model' => $candidate, 'retry_after' => null, 'usage' => $outcome['usage'] ?? []];
                }
                $lastError = $outcome['error'];
                $lastRetryAfter = $outcome['retry_after'] ?? null;
                if ($outcome['status'] === 'permanent') {
                    return ['text' => null, 'error' => $lastError, 'model' => null, 'retry_after' => $lastRetryAfter];
                }
                if ($outcome['status'] === 'not_found') {
                    break; // coba model cadangan berikutnya
                }
                if ($attempt < $maxAttempts) {
                    sleep((int) min(2 ** ($attempt - 1), 8)); // 1s, 2s, 4s, maks 8s
                }
            }
        }

        return ['text' => null, 'error' => $lastError, 'model' => null, 'retry_after' => $lastRetryAfter];
    }

        /**
     * Satu percobaan chat, opsional dengan tool calling terstruktur.
     * Bila $tools diberikan, respons boleh tanpa teks selama ada
     * tool_calls yang argumennya JSON valid (terbukti lewat probe
     * gateway 2026-10-30: gemini-3.1-flash-lite mengembalikan tool_calls
     * valid). Argumen tool yang bukan JSON valid dilaporkan sebagai
     * 'malformed' — pemanggil yang memutuskan repair, bukan retry buta.
     *
     * @param  array<int,array{type:string,function:array{name:string,parameters:array}}>|null  $tools
     * @return array{status: 'ok'|'transient'|'permanent'|'not_found'|'malformed', text: ?string, error: string, tool_calls: array, usage: array, retry_after: ?int}
     */
    private function attemptChat(string $model, string $systemPrompt, string $userMessage, int $timeout, ?array $image, ?array $tools = null): array
    {
        $userContent = $image !== null
            ? [
                ['type' => 'text', 'text' => $userMessage],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$image['mime'].';base64,'.$image['data']]],
            ]
            : $userMessage;
        return $this->attemptChatMessages($model, $systemPrompt, [['role' => 'user', 'content' => $userContent]], $timeout, $tools);
    }

    /**
     * Satu percobaan chat dengan riwayat pesan penuh (untuk loop tool).
     *
     * @param  array<int,array{role:string,content:mixed}>  $messages
     * @param  array<int,array{type:string,function:array{name:string,parameters:array}}>|null  $tools
     * @return array{status: 'ok'|'transient'|'permanent'|'not_found'|'malformed', text: ?string, error: string, tool_calls: array, usage: array, retry_after: ?int}
     */
    private function attemptChatMessages(string $model, string $systemPrompt, array $messages, int $timeout, ?array $tools = null): array
    {
        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages),
        ];
        if ($tools !== null) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        try {
            $response = Http::baseUrl($this->baseUrl)->withToken($this->apiKey)
                ->timeout($timeout)->acceptJson()->post('/v1/chat/completions', $payload);
        } catch (Throwable $e) {
            // Timeout cURL (28) vs koneksi gagal dibedakan dari pesan exception.
            $error = str_contains(strtolower($e->getMessage()), 'timed out') ? 'TIMEOUT' : 'GATEWAY_UNREACHABLE';

            return ['status' => 'transient', 'text' => null, 'error' => $error, 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
        }

        $status = $response->status();
        if ($status === 401) {
            return ['status' => 'permanent', 'text' => null, 'error' => 'AUTH_FAILED', 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
        }
        if ($status === 404) {
            return ['status' => 'not_found', 'text' => null, 'error' => 'MODEL_NOT_FOUND', 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
        }
        if ($status === 400) {
            // Jangan buang body error upstream: tanpa ini, 400 hanya tampil
            // sebagai "permintaan tidak valid" dan penyebab aslinya (mis.
            // auth provider yang kehilangan project_id) tidak terlacak.
            $detail = (string) ($response->json('error.message') ?? $response->body() ?? '');
            Log::warning('CLIProxyAPI chat 400', ['model' => $model, 'error' => Str::limit($detail, 500)]);
            $lower = strtolower($detail);
            if (str_contains($lower, 'project_id') || str_contains($lower, 'auth missing')) {
                return ['status' => 'permanent', 'text' => null, 'error' => 'PROVIDER_AUTH_INCOMPLETE', 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
            }

            return ['status' => 'permanent', 'text' => null, 'error' => 'BAD_REQUEST', 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
        }
        if ($status === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 0);

            return ['status' => 'transient', 'text' => null, 'error' => 'RATE_LIMITED', 'tool_calls' => [], 'usage' => [], 'retry_after' => $retryAfter > 0 ? min($retryAfter, 300) : null];
        }
        if ($status >= 500) {
            return ['status' => 'transient', 'text' => null, 'error' => 'UPSTREAM_OVERLOADED', 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
        }
        if (! $response->successful()) {
            return ['status' => 'transient', 'text' => null, 'error' => 'UPSTREAM_OVERLOADED', 'tool_calls' => [], 'usage' => [], 'retry_after' => null];
        }
        $usage = $response->json('usage');
        $usage = is_array($usage) ? $usage : [];
        if ($tools !== null) {
            $rawCalls = $response->json('choices.0.message.tool_calls');
            $toolCalls = [];
            if (is_array($rawCalls)) {
                foreach ($rawCalls as $call) {
                    $args = $call['function']['arguments'] ?? null;
                    $decoded = is_string($args) ? json_decode($args, true) : $args;
                    if (! is_array($decoded)) {
                        return ['status' => 'malformed', 'text' => null, 'error' => 'MALFORMED_TOOL_CALL', 'tool_calls' => [], 'usage' => $usage, 'retry_after' => null];
                    }
                    $toolCalls[] = [
                        'id' => $call['id'] ?? null,
                        'name' => $call['function']['name'] ?? null,
                        'arguments' => $decoded,
                    ];
                }
            }
            if ($toolCalls !== []) {
                $content = $response->json('choices.0.message.content');

                return ['status' => 'ok', 'text' => is_string($content) ? trim($content) : null, 'error' => '', 'tool_calls' => $toolCalls, 'usage' => $usage, 'retry_after' => null];
            }
        }
        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return ['status' => 'transient', 'text' => null, 'error' => 'EMPTY_RESPONSE', 'tool_calls' => [], 'usage' => $usage, 'retry_after' => null];
        }

        return ['status' => 'ok', 'text' => trim($content), 'error' => '', 'tool_calls' => [], 'usage' => $usage, 'retry_after' => null];
    }

    /**
     * Chat dengan tool calling terstruktur (satu putaran tool, tanpa
     * eksekusi tool di sisi client — pemanggil yang mengeksekusi dan
     * memutuskan langkah berikutnya). Retry hanya untuk kegagalan
     * transient; jawaban malformed dikembalikan apa adanya agar
     * pemanggil bisa repair dengan koreksi, bukan mengulang buta.
     *
     * @param  array<int,array{type:string,function:array{name:string,parameters:array}}>  $tools
     * @return array{tool_calls: array, text: ?string, error: ?string, model: ?string, usage: array, retry_after: ?int}
     */
    public function chatWithTools(string $model, string $systemPrompt, string $userMessage, array $tools, int $timeout = 120): array
    {
        return $this->chatWithToolsHistory($model, $systemPrompt, [['role' => 'user', 'content' => $userMessage]], $tools, $timeout);
    }

    /**
     * Seperti chatWithTools, tapi dengan riwayat pesan penuh (loop tool).
     *
     * @param  array<int,array{role:string,content:mixed}>  $messages
     * @param  array<int,array{type:string,function:array{name:string,parameters:array}}>  $tools
     * @return array{tool_calls: array, text: ?string, error: ?string, model: ?string, usage: array, retry_after: ?int}
     */
    public function chatWithToolsHistory(string $model, string $systemPrompt, array $messages, array $tools, int $timeout = 120): array
    {
        if (! $this->agentsEnabled() || ! $this->apiKey) {
            return ['tool_calls' => [], 'text' => null, 'error' => 'DISABLED', 'model' => null, 'usage' => [], 'retry_after' => null];
        }
        $maxAttempts = max(1, (int) config('ai.cliproxy.chat_attempts', 3));
        $maxFallbacks = max(0, (int) config('ai.cliproxy.chat_fallbacks', 2));
        $deadline = microtime(true) + (int) config('ai.cliproxy.chat_deadline', 240);
        $lastError = 'UNKNOWN';
        $lastRetryAfter = null;
        foreach (array_slice($this->fallbackCandidates($model), 0, $maxFallbacks + 1) as $candidate) {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if (microtime(true) >= $deadline) {
                    return ['tool_calls' => [], 'text' => null, 'error' => $lastError === 'UNKNOWN' ? 'TIMEOUT' : $lastError, 'model' => null, 'usage' => [], 'retry_after' => $lastRetryAfter];
                }
                $outcome = $this->attemptChatMessages($candidate, $systemPrompt, $messages, $timeout, $tools);
                if ($outcome['status'] === 'ok') {
                    AiUsageRecorder::record($candidate, $outcome['usage'] ?? []);
                    return ['tool_calls' => $outcome['tool_calls'], 'text' => $outcome['text'], 'error' => null, 'model' => $candidate, 'usage' => $outcome['usage'], 'retry_after' => null];
                }
                $lastError = $outcome['error'];
                $lastRetryAfter = $outcome['retry_after'];
                if (in_array($outcome['status'], ['permanent', 'malformed'], true)) {
                    return ['tool_calls' => [], 'text' => null, 'error' => $lastError, 'model' => null, 'usage' => $outcome['usage'], 'retry_after' => $lastRetryAfter];
                }
                if ($outcome['status'] === 'not_found') {
                    break;
                }
                if ($attempt < $maxAttempts) {
                    sleep((int) min(max($outcome['retry_after'] ?? 0, 2 ** ($attempt - 1)), 60));
                }
            }
        }

        return ['tool_calls' => [], 'text' => null, 'error' => $lastError, 'model' => null, 'usage' => [], 'retry_after' => $lastRetryAfter];
    }

    /**
     * Kandidat model: utama dulu, lalu cadangan terbaik dari provider yang
     * sama, lalu model terbaik tiap provider lain. Urutan ini penting:
     * bila provider pilihan gagal transient (mis. semua modelnya cooldown
     * 429 seperti kasus estimasi laundry Run 9–12), model dari provider
     * lain yang sehat tetap dicoba — sebelumnya fallback berhenti di
     * provider yang sama sehingga model Google yang sehat tidak pernah
     * menyelamatkan peran Backend/QA. Model image-* dikecualikan (bukan
     * untuk chat). Pemanggil membatasi jumlah kandidat lewat config
     * chat_fallbacks, dan urutan di sini menjamin satu model lintas
     * provider selalu masuk dalam batas itu.
     *
     * @return string[]
     */
    private function fallbackCandidates(string $model): array
    {
        $candidates = [$model];
        $models = $this->models();
        $primary = null;
        foreach ($models as $m) {
            if ($m['id'] === $model) {
                $primary = $m;
                break;
            }
        }
        if ($primary === null) {
            return $candidates;
        }
        $primaryProvider = null;
        foreach (self::modelProviderSlugs() as $provider) {
            if (self::modelMatchesProvider($primary['id'], $primary['owned_by'], $provider)) {
                $primaryProvider = $provider;
                break;
            }
        }
        $sameProvider = [];
        foreach ($models as $m) {
            if ($m['id'] === $model
                || str_contains(strtolower($m['id']), 'image')
                || ($primaryProvider !== null && ! self::modelMatchesProvider($m['id'], $m['owned_by'], $primaryProvider))) {
                continue;
            }
            $sameProvider[] = $m;
        }
        // Cadangan terbaik provider yang sama dulu, lalu satu model terbaik
        // tiap provider lain (Google didahulukan: akun yang terverifikasi
        // sehat saat insiden cooldown 2026-10-30).
        if ($sameProvider !== []) {
            $candidates[] = self::pickBest($sameProvider);
        }
        foreach (self::modelProviderSlugs() as $provider) {
            if ($provider === $primaryProvider) {
                continue;
            }
            $others = array_values(array_filter(
                $models,
                fn ($m) => $m['id'] !== $model
                    && ! str_contains(strtolower($m['id']), 'image')
                    && self::modelMatchesProvider($m['id'], $m['owned_by'], $provider)
            ));
            if ($others !== []) {
                $candidates[] = self::pickBest($others);
            }
        }
        foreach ($sameProvider as $m) {
            if (! in_array($m['id'], $candidates, true)) {
                $candidates[] = $m['id'];
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Provider OAuth untuk sebuah model dari katalog gateway
     * (slug registry, mis. 'google' | 'codex' | 'anthropic' | 'mimo',
     * atau null bila tak dikenali). Dipakai untuk mencatat
     * model/provider di log kegagalan vote.
     */
    public function providerForModel(string $model): ?string
    {
        foreach ($this->models() as $m) {
            if ($m['id'] !== $model) {
                continue;
            }
            foreach (self::modelProviderSlugs() as $provider) {
                if (self::modelMatchesProvider($m['id'], $m['owned_by'], $provider)) {
                    return $provider;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Slug grup provider dari registry config ai.model_providers, sesuai
     * urutan prioritas fallback. Provider baru cukup ditambahkan di
     * config — tidak perlu mengubah logika routing di sini.
     *
     * @return string[]
     */
    public static function modelProviderSlugs(): array
    {
        $registry = config('ai.model_providers', []);
        $slugs = is_array($registry) ? array_keys($registry) : [];

        return $slugs !== [] ? $slugs : ['google', 'codex', 'anthropic'];
    }

    private static function modelMatchesProvider(string $id, string $ownedBy, string $provider): bool
    {
        $provider = strtolower($provider);
        if ($provider === 'any') {
            return true;
        }
        // Alias lama: kanal Antigravity/Gemini = grup Google.
        if (in_array($provider, ['antigravity', 'gemini'], true)) {
            $provider = 'google';
        }
        $registry = config('ai.model_providers', []);
        $entry = is_array($registry) ? ($registry[$provider] ?? null) : null;
        if (! is_array($entry)) {
            return false;
        }
        $id = strtolower($id);
        $ownedBy = strtolower($ownedBy);
        foreach ((array) ($entry['id'] ?? []) as $needle) {
            if (str_contains($id, strtolower((string) $needle))) {
                return true;
            }
        }
        foreach ((array) ($entry['owned_by'] ?? []) as $needle) {
            if (str_contains($ownedBy, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    /** Heuristik "terbaik": hindari model image, utamakan flagship. */
    private static function pickBest(array $models): string
    {
        $ids = array_column($models, 'id');
        $nonImage = array_values(array_filter($ids, fn ($id) => ! str_contains(strtolower($id), 'image')));
        $pool = $nonImage !== [] ? $nonImage : $ids;
        $ranked = [
            'claude-opus', 'claude-sonnet', 'claude',
            'gpt-5', 'gpt-6', 'gpt-4', 'gpt',
            'gemini-2.5-pro', 'gemini-3', 'gemini-2.5', 'gemini-3.1-pro', 'gemini',
        ];
        foreach ($ranked as $needle) {
            foreach ($pool as $id) {
                if (str_contains(strtolower($id), $needle)) {
                    return $id;
                }
            }
        }

        return $pool[0];
    }

    protected function management()
    {
        return Http::baseUrl($this->baseUrl.'/v0/management')
            ->withToken($this->managementKey)
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
