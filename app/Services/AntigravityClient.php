<?php

namespace App\Services;

use App\Support\Ai\AiQuota;
use App\Support\Execution\ExecutionStopper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Satu pintu ke Antigravity CLI (`agy`) untuk semua peran agen.
 *
 * Setiap panggilan adalah satu proses `agy -p` non-interaktif. Prompt dikirim
 * lewat stdin (format stream-json) karena prompt review QA bisa memuat diff
 * besar yang melewati batas 128 KB per argumen Linux. Hasil dibaca dari
 * event `result` terakhir; error terstruktur dibaca dari baris `AGY_ERROR:`
 * di stderr.
 *
 * Bentuk hasil chatResilient() sengaja sama dengan klien gateway lama agar
 * pemanggil lama cukup berganti klien, tanpa mengubah penanganan error.
 */
class AntigravityClient
{
    /** Profil chat tanpa tool: tidak bisa membaca file apa pun di server. */
    public const AGENT_CHAT = 'scrumspace-chat';

    /** Profil chat yang hanya boleh melihat lampiran gambar di folder sandbox. */
    public const AGENT_VISION = 'scrumspace-vision';

    private const MODELS_CACHE_KEY = 'antigravity:models';

    private const QUOTA_CACHE_KEY = 'antigravity:quota';

    public function isEnabled(): bool
    {
        return (bool) config('ai.antigravity.enabled', true);
    }

    public function binary(): string
    {
        return (string) config('ai.antigravity.binary', '/home/sidik/.local/bin/agy');
    }

    public function isInstalled(): bool
    {
        $binary = $this->binary();

        return is_file($binary) && is_executable($binary);
    }

    /**
     * Daftar model yang tersedia untuk akun yang sedang login.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function models(bool $fresh = false): array
    {
        if ($fresh || $this->tokenMissing()) {
            Cache::forget(self::MODELS_CACHE_KEY);
        }
        if ($this->tokenMissing()) {
            return [];
        }

        return Cache::remember(self::MODELS_CACHE_KEY, (int) config('ai.antigravity.models_cache', 600), function (): array {
            if (! $this->isEnabled() || ! $this->isInstalled()) {
                return [];
            }

            $process = new Process([$this->binary(), 'models'], $this->sandboxDir(), $this->processEnv(), null, 60);
            try {
                $process->run();
            } catch (Throwable $exception) {
                Log::warning('agy models gagal', ['error' => $exception->getMessage()]);

                return [];
            }

            return $this->parseModels($process->getOutput());
        });
    }

    /**
     * Token login agy disimpan di file. Bila foldernya ada tetapi file token
     * hilang (sudah Keluar), akun pasti tidak login, jadi cache daftar model
     * tidak boleh lagi menampilkan "Terhubung". Tanpa konfigurasi token_file,
     * atau bila foldernya tidak terlihat, pemeriksaan ini dilewati.
     */
    public function tokenMissing(): bool
    {
        $file = (string) config('ai.antigravity.token_file', '');
        if ($file === '' || ! is_dir(dirname($file))) {
            return false;
        }

        return ! file_exists($file);
    }

    /**
     * Ringkasan untuk halaman AI Model. Login dianggap aktif bila akun bisa
     * membaca daftar model.
     *
     * @return array{enabled: bool, installed: bool, version: ?string, logged_in: bool, model_count: int}
     */
    public function status(bool $fresh = false): array
    {
        $installed = $this->isInstalled();
        $models = $installed ? $this->models($fresh) : [];

        return [
            'enabled' => $this->isEnabled(),
            'installed' => $installed,
            'version' => $installed ? $this->version() : null,
            'logged_in' => $models !== [],
            'model_count' => count($models),
        ];
    }

    public function version(): ?string
    {
        return Cache::remember('antigravity:version', 3600, function (): ?string {
            try {
                $process = new Process([$this->binary(), '--version'], $this->sandboxDir(), $this->processEnv(), null, 30);
                $process->run();
                $version = trim($process->getOutput());

                return preg_match('/^\d+\.\d+\.\d+/', $version, $match) ? $match[0] : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /** @return string[] */
    public function modelIds(bool $fresh = false): array
    {
        return array_column($this->models($fresh), 'id');
    }

    public function forgetModelsCache(): void
    {
        Cache::forget(self::MODELS_CACHE_KEY);
    }

    /**
     * Sisa kuota akun Google (batas 5 jam dan mingguan per kelompok model),
     * dibaca dari slash command `/quota`. Tidak memakai token model.
     *
     * @return array{groups: array<int, array{name: string, models: string, buckets: array<int, array{window: string, remaining: float, reset_at: ?string}>}>, error: ?string, fetched_at: string}
     */
    public function accountQuota(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::QUOTA_CACHE_KEY);
        }

        return Cache::remember(self::QUOTA_CACHE_KEY, (int) config('ai.antigravity.quota_cache', 60), function (): array {
            $empty = fn (string $error): array => ['groups' => [], 'error' => $error, 'fetched_at' => now()->toIso8601String()];
            if (! $this->isEnabled()) {
                return $empty('DISABLED');
            }
            if (! $this->isInstalled()) {
                return $empty('AGY_NOT_INSTALLED');
            }

            $process = new Process([$this->binary(), '-p=/quota', '--output-format', 'json', '--print-timeout', '40s'], $this->sandboxDir(), $this->processEnv(), null, 60);
            try {
                $process->run();
            } catch (Throwable $exception) {
                Log::warning('agy /quota gagal', ['error' => $exception->getMessage()]);

                return $empty('TIMEOUT');
            }

            $outcome = $this->interpret($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
            if ($outcome['status'] !== 'ok') {
                return $empty((string) $outcome['error']);
            }
            $groups = $this->parseQuota($outcome['result']);

            return $groups === [] ? $empty('QUOTA_UNREADABLE') : ['groups' => $groups, 'error' => null, 'fetched_at' => now()->toIso8601String()];
        });
    }

    /**
     * Ambil kelompok kuota dari hasil `/quota --output-format json`
     * (`command.data.groups[].buckets[]`).
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array{name: string, models: string, buckets: array<int, array{window: string, remaining: float, reset_at: ?string}>}>
     */
    public function parseQuota(array $result): array
    {
        $groups = data_get($result, 'command.data.groups');
        if (! is_array($groups)) {
            return [];
        }

        $parsed = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_array($group['buckets'] ?? null)) {
                continue;
            }
            $buckets = [];
            foreach ($group['buckets'] as $bucket) {
                if (! is_array($bucket) || ! is_numeric($bucket['remaining_fraction'] ?? null) || ! in_array($bucket['window'] ?? null, ['5h', 'weekly'], true)) {
                    continue;
                }
                $buckets[] = [
                    'window' => (string) $bucket['window'],
                    'remaining' => max(0.0, min(1.0, (float) $bucket['remaining_fraction'])),
                    'reset_at' => is_string($bucket['reset_time'] ?? null) ? $bucket['reset_time'] : null,
                ];
            }
            if ($buckets === []) {
                continue;
            }
            usort($buckets, fn (array $a, array $b): int => $a['window'] === '5h' ? -1 : ($b['window'] === '5h' ? 1 : 0));
            $parsed[] = [
                'name' => (string) ($group['name'] ?? 'Model'),
                'models' => trim((string) preg_replace('/^Models within this group:\s*/i', '', (string) ($group['description'] ?? ''))),
                'buckets' => $buckets,
            ];
        }

        return $parsed;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function parseModels(string $output): array
    {
        $models = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = preg_split('/\t+/', trim($line));
            if (count($parts) < 2 || ! preg_match('/^[a-z0-9][a-z0-9.\-]*$/', $parts[0])) {
                continue;
            }
            $models[] = ['id' => $parts[0], 'name' => trim($parts[1])];
        }

        return $models;
    }

    /**
     * Chat tanpa akses file (atau hanya lampiran gambar), dengan bentuk hasil
     * yang sama seperti klien gateway lama (text, error, model, usage).
     *
     * @param  array{data: string, mime: string}|null  $image
     * @param  array<string, mixed>  $usageContext
     * @return array{text: ?string, error: ?string, model: ?string, retry_after: ?int, usage?: array, available_models?: string[], conversation_id?: ?string}
     */
    public function chatResilient(string $model, string $systemPrompt, string $userMessage, int $timeout = 0, ?array $image = null, array $usageContext = []): array
    {
        if (! $this->isEnabled()) {
            return ['text' => null, 'error' => 'DISABLED', 'model' => null, 'retry_after' => null];
        }
        if (! $this->isInstalled()) {
            return ['text' => null, 'error' => 'AGY_NOT_INSTALLED', 'model' => null, 'retry_after' => null];
        }

        $timeout = $timeout > 0 ? $timeout : (int) config('ai.antigravity.chat_timeout', 180);
        $attempts = max(1, (int) config('ai.antigravity.chat_attempts', 2));
        $workDir = $this->makeCallDir();

        try {
            $agent = self::AGENT_CHAT;
            $prompt = $this->composePrompt($systemPrompt, $userMessage);
            $settings = null;
            if ($image !== null) {
                $file = $this->writeImage($workDir, $image);
                $agent = self::AGENT_VISION;
                $prompt .= "\n\nLampiran gambar dari Product Owner tersimpan di file ./".basename($file).'. Lihat file itu dengan view_file bila relevan.';
                $settings = ['permissions' => ['allow' => ['read_file('.$workDir.')']]];
            }

            $last = null;
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $outcome = $this->invoke($prompt, $workDir, $model, $agent, $timeout, $settings);
                if ($outcome['status'] === 'ok') {
                    $usage = $this->normalizeUsage($outcome['result']['usage'] ?? []);
                    AiUsageRecorder::record($model, $usage, $usageContext);
                    AiQuota::recovered($model, false);

                    return [
                        'text' => $outcome['text'],
                        'error' => null,
                        'model' => $model,
                        'retry_after' => null,
                        'usage' => $usage,
                        'conversation_id' => $outcome['result']['conversation_id'] ?? null,
                    ];
                }
                $last = $outcome;
                if ($outcome['error'] === 'MODEL_PROBLEMATIC') {
                    return ['text' => null, 'error' => 'MODEL_PROBLEMATIC', 'model' => $model, 'retry_after' => null, 'available_models' => $this->modelIds()];
                }
                if ($outcome['error'] === 'QUOTA_EXHAUSTED') {
                    AiQuota::exhausted($model, $outcome['detail']);
                }
                if ($outcome['status'] !== 'transient') {
                    break;
                }
                if ($attempt < $attempts) {
                    sleep(min(2 ** ($attempt - 1), 8));
                }
            }

            return ['text' => null, 'error' => $last['error'] ?? 'UNKNOWN', 'model' => null, 'retry_after' => null];
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    public function chat(string $model, string $systemPrompt, string $userMessage, int $timeout = 0, ?array $image = null): ?string
    {
        return $this->chatResilient($model, $systemPrompt, $userMessage, $timeout, $image)['text'];
    }

    /**
     * Jalankan satu task developer di worktree: agen mengedit file langsung,
     * Laravel yang memeriksa perubahan, commit, QA, dan merge.
     *
     * @param  array<string, mixed>  $usageContext
     * @return array{ok: bool, exit: ?int, output: string, error: ?string, usage: array}
     */
    public function runTask(string $agent, string $worktree, string $prompt, string $model, int $timeout = 0, array $usageContext = []): array
    {
        if (! $this->isInstalled()) {
            return ['ok' => false, 'exit' => null, 'output' => 'Antigravity CLI belum terpasang di server.', 'error' => 'AGY_NOT_INSTALLED', 'usage' => []];
        }

        $timeout = $timeout > 0 ? $timeout : (int) config('ai.antigravity.task_timeout', 1500);
        ExecutionStopper::clearFlag($worktree);

        $outcome = $this->invoke($prompt, $worktree, $model, $agent, $timeout, $this->taskSettings($worktree), true);

        if ($outcome['status'] === 'stopped') {
            return ['ok' => false, 'exit' => null, 'output' => 'STOPPED_BY_PO: proses agen dihentikan paksa oleh PO.', 'error' => 'STOPPED_BY_PO', 'usage' => []];
        }

        $usage = $this->normalizeUsage($outcome['result']['usage'] ?? []);
        if ($usage !== []) {
            AiUsageRecorder::record($model, $usage, $usageContext);
        }

        if ($outcome['status'] === 'ok') {
            AiQuota::recovered($model, false);

            return ['ok' => true, 'exit' => 0, 'output' => mb_substr((string) $outcome['text'], -4000), 'error' => null, 'usage' => $usage];
        }

        $detail = $outcome['error'] === 'QUOTA_EXHAUSTED'
            ? 'QUOTA_EXHAUSTED: '.$outcome['detail']
            : $outcome['detail'];

        return ['ok' => false, 'exit' => $outcome['exit'], 'output' => mb_substr($detail, -4000), 'error' => $outcome['error'], 'usage' => $usage];
    }

    /**
     * Satu proses agy. Status: ok | transient | permanent | stopped.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array{status: string, error: ?string, detail: string, text: ?string, result: array, exit: ?int}
     */
    private function invoke(string $prompt, string $cwd, string $model, string $agent, int $timeout, ?array $settings = null, bool $watchStop = false): array
    {
        $command = [
            $this->binary(),
            '-p=',
            '--model', $model,
            '--agent', $agent,
            '--input-format', 'stream-json',
            '--output-format', 'stream-json',
            '--print-timeout', $timeout.'s',
            '--disable-slash-commands',
        ];
        if ($settings !== null) {
            $command[] = '--settings';
            $command[] = json_encode($settings, JSON_UNESCAPED_SLASHES);
        }

        $process = new Process($command, $cwd, $this->processEnv(), null, $timeout + 60);
        $process->setInput(json_encode(['event' => 'user', 'message' => ['content' => $prompt]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        try {
            $process->start();
            while ($process->isRunning()) {
                usleep(500_000);
                $process->checkTimeout();
                if ($watchStop && ExecutionStopper::stopRequested($cwd)) {
                    $process->stop(5);

                    return ['status' => 'stopped', 'error' => 'STOPPED_BY_PO', 'detail' => '', 'text' => null, 'result' => [], 'exit' => null];
                }
            }
        } catch (ProcessTimedOutException) {
            $process->stop(5);

            return ['status' => 'transient', 'error' => 'TIMEOUT', 'detail' => 'Antigravity tidak selesai dalam '.$timeout.' detik.', 'text' => null, 'result' => [], 'exit' => null];
        }

        return $this->interpret($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Terjemahkan keluaran agy menjadi status + kode error ScrumSpace.
     *
     * @return array{status: string, error: ?string, detail: string, text: ?string, result: array, exit: ?int}
     */
    public function interpret(?int $exit, string $stdout, string $stderr): array
    {
        $result = $this->lastResult($stdout);
        $agyError = $this->agyError($stderr);
        $text = isset($result['response']) && is_string($result['response']) ? trim($result['response']) : null;

        if (($result['status'] ?? null) === 'SUCCESS' && $text !== null && $text !== '') {
            return ['status' => 'ok', 'error' => null, 'detail' => '', 'text' => $text, 'result' => $result, 'exit' => $exit];
        }

        $detail = trim((string) ($result['error'] ?? $agyError['short_error'] ?? ''));
        if ($detail === '') {
            $detail = $this->stderrSummary($stderr);
        }
        $lower = mb_strtolower($detail.' '.$stderr);
        $base = ['detail' => Str::limit($detail, 1500), 'text' => null, 'result' => $result, 'exit' => $exit];

        if (str_contains($lower, 'not logged into antigravity') || str_contains($lower, 'authentication required')) {
            return ['status' => 'permanent', 'error' => 'NOT_LOGGED_IN'] + $base;
        }
        if (str_contains($lower, 'invalid model selection') || str_contains($lower, 'not recognized as a known model')) {
            return ['status' => 'permanent', 'error' => 'MODEL_PROBLEMATIC'] + $base;
        }
        if (AiQuota::isQuotaText($lower) || ($agyError['status'] ?? null) === 'RESOURCE_EXHAUSTED') {
            return ['status' => 'permanent', 'error' => 'QUOTA_EXHAUSTED'] + $base;
        }
        if (($agyError['reason'] ?? null) === 'print_timeout' || ($agyError['status'] ?? null) === 'DEADLINE_EXCEEDED') {
            return ['status' => 'transient', 'error' => 'TIMEOUT'] + $base;
        }
        if (str_contains($lower, '429') || str_contains($lower, 'rate limit')) {
            return ['status' => 'transient', 'error' => 'RATE_LIMITED'] + $base;
        }
        if (($result['status'] ?? null) === 'SUCCESS') {
            return ['status' => 'transient', 'error' => 'EMPTY_RESPONSE'] + $base;
        }
        if (($agyError['retryable'] ?? false) === true || str_contains($lower, 'unavailable') || str_contains($lower, 'overloaded') || str_contains($lower, '503')) {
            return ['status' => 'transient', 'error' => 'UPSTREAM_OVERLOADED'] + $base;
        }

        return ['status' => 'permanent', 'error' => 'AGY_FAILED'] + $base;
    }

    /** @return array<string, mixed> */
    private function lastResult(string $stdout): array
    {
        $result = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            if (($event['event'] ?? null) === 'result' && is_array($event['result'] ?? null)) {
                $result = $event['result'];
            } elseif (isset($event['status'], $event['conversation_id'])) {
                $result = $event;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function agyError(string $stderr): array
    {
        if (preg_match_all('/^AGY_ERROR:\s*(\{.*\})\s*$/m', $stderr, $matches) && $matches[1] !== []) {
            $decoded = json_decode((string) end($matches[1]), true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** Baris stderr yang bermakna (tanpa log internal I/W/E bertanda waktu). */
    private function stderrSummary(string $stderr): string
    {
        $lines = array_filter(preg_split('/\R/', $stderr) ?: [], function (string $line): bool {
            $line = trim($line);

            return $line !== '' && ! preg_match('/^[IWEF]\d{4} /', $line);
        });

        return Str::limit(implode(' ', array_slice($lines, -5)), 1500);
    }

    /**
     * agy melaporkan input/output/thinking; pencatat ScrumSpace memakai
     * istilah prompt/completion.
     *
     * @param  array<string, mixed>  $usage
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|array{}
     */
    public function normalizeUsage(array $usage): array
    {
        if ($usage === []) {
            return [];
        }
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0) + (int) ($usage['thinking_tokens'] ?? 0);

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => (int) ($usage['total_tokens'] ?? ($prompt + $completion)),
        ];
    }

    private function composePrompt(string $systemPrompt, string $userMessage): string
    {
        $systemPrompt = trim($systemPrompt);
        if ($systemPrompt === '') {
            return $userMessage;
        }

        return "<instruksi_sistem>\n{$systemPrompt}\n</instruksi_sistem>\n\n{$userMessage}";
    }

    /**
     * Izin agen developer: bebas di worktree task, tanpa git, sudo, atau
     * penghapusan massal; tidak bisa menulis di luar worktree.
     *
     * @return array{permissions: array{allow: string[], deny: string[]}}
     */
    public function taskSettings(string $worktree): array
    {
        return [
            'permissions' => [
                'allow' => [
                    'read_file('.$worktree.')',
                    'write_file('.$worktree.')',
                    'command(php)',
                    'command(composer)',
                    'command(npm)',
                    'command(npx)',
                    'command(node)',
                    'command(ls)',
                    'command(cat)',
                    'command(grep)',
                    'command(find)',
                    'command(mkdir)',
                ],
                'deny' => [
                    'command(git)',
                    'command(sudo)',
                    'command(rm -rf)',
                    'command(regex:curl .*)',
                    'command(regex:wget .*)',
                    'write_file('.$worktree.'/.git)',
                    'write_file('.$worktree.'/.env)',
                ],
            ],
        ];
    }

    /** @param array{data: string, mime: string} $image */
    private function writeImage(string $dir, array $image): string
    {
        $extension = match ($image['mime'] ?? '') {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        $path = $dir.'/lampiran.'.$extension;
        file_put_contents($path, base64_decode($image['data'], true) ?: '');

        return $path;
    }

    private function sandboxDir(): string
    {
        $dir = (string) config('ai.antigravity.sandbox_dir', storage_path('app/private/agy-sandbox'));
        File::ensureDirectoryExists($dir, 0775);

        return $dir;
    }

    private function makeCallDir(): string
    {
        $dir = $this->sandboxDir().'/'.Str::uuid()->toString();
        File::ensureDirectoryExists($dir, 0775);

        return $dir;
    }

    /** @return array<string, string> */
    private function processEnv(): array
    {
        return [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'TERM' => 'dumb',
            'NO_COLOR' => '1',
        ];
    }
}
