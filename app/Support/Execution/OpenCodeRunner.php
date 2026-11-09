<?php

namespace App\Support\Execution;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Menjalankan task lewat OpenCode CLI (coding agent eksternal).
 *
 * Dipakai untuk peran tertentu (mis. backend/frontend) sebagai alternatif
 * worker Hermes. OpenCode mengedit file di worktree, lalu jalur Laravel tetap
 * sama: diff -> commit -> QA -> merge.
 */
class OpenCodeRunner
{
    /**
     * Model yang dipakai OpenCode.
     *
     * Sumber tunggal = menu AI Providers (Setting active_model). Bila saklar
     * OPENCODE_FOLLOW_ACTIVE_MODEL=false, barulah config ai.opencode.model
     * dipakai sebagai patokan khusus OpenCode.
     */
    public function model(): string
    {
        $follow = (bool) config('ai.opencode.follow_active_model', true);
        $configured = (string) config('ai.opencode.model', 'scrumgateway/deepseek-v4.1-flash-1017');

        if (! $follow) {
            return $configured;
        }

        $active = app(\App\Services\ActiveModelService::class)->current();

        if (! is_string($active) || trim($active) === '') {
            return $configured;
        }

        return $this->qualify($active);
    }

    /** Pastikan id model memakai prefix provider yang terdaftar di config OpenCode. */
    private function qualify(string $model): string
    {
        $model = trim($model);

        return str_contains($model, '/') ? $model : 'scrumgateway/'.$model;
    }

    public function isEnabledFor(string $role): bool
    {
        $roles = (array) config('ai.opencode.roles', []);

        return in_array($role, $roles, true);
    }

    /**
     * @return array{ok: bool, exit: ?int, output: string}
     */
    public function run(string $worktree, string $prompt, int $timeout = 0, ?string $model = null): array
    {
        $binary = (string) config('ai.opencode.binary', '/usr/bin/opencode');
        if (! is_executable($binary)) {
            throw new RuntimeException('Binary OpenCode tidak ditemukan di '.$binary.'.');
        }

        $home = $this->ensureHome();
        $model = $model ?: $this->model();
        $timeout = $timeout > 0 ? $timeout : (int) config('ai.opencode.timeout', 1500);

        $process = new Process(
            [$binary, 'run', $prompt, '--model', $model],
            $worktree,
            [
                'HOME' => $home,
                'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                'TERM' => 'dumb',
                'CI' => '1',
            ],
            null,
            $timeout
        );
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        return [
            'ok' => $process->isSuccessful(),
            'exit' => $process->getExitCode(),
            'output' => mb_substr($output, -4000),
        ];
    }

    /**
     * Siapkan HOME khusus OpenCode + konfigurasi provider gateway.
     * Kunci API tidak ditulis ke worktree project, hanya di HOME ini (0600).
     */
    private function ensureHome(): string
    {
        $home = storage_path('app/private/.opencode-home');
        $dir = $home.'/.config/opencode';
        File::ensureDirectoryExists($dir, 0700);

        $configFile = $dir.'/opencode.json';
        $apiKey = (string) config('ai.cliproxy.api_key');
        $baseUrl = rtrim((string) config('ai.cliproxy.base_url', 'http://127.0.0.1:8317'), '/').'/v1';

        $config = [
            '$schema' => 'https://opencode.ai/config.json',
            'provider' => [
                'scrumgateway' => [
                    'npm' => '@ai-sdk/openai-compatible',
                    'name' => 'Scrum Gateway',
                    'options' => ['baseURL' => $baseUrl, 'apiKey' => $apiKey],
                    'models' => $this->modelCatalog(),
                ],
            ],
            'model' => $this->model(),
            // Izinkan agen membaca/mengedit file project dan menjalankan
            // perintah di dalam direktori kerja — termasuk membaca .env
            // project untuk memahami konfigurasi. Ini permintaan PO: akses
            // file project tidak dibatasi lagi.
            'permission' => [
                'read' => 'allow',
                'edit' => 'allow',
                'bash' => 'allow',
                'webfetch' => 'deny',
                'external_directory' => 'allow',
            ],
        ];

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($configFile, 0600);

        return $home;
    }

    /**
     * Daftar model yang didaftarkan ke OpenCode.
     *
     * Diambil dari gateway (sumber yang sama dengan menu AI Providers) agar
     * memilih model di sana langsung bisa dipakai OpenCode tanpa mengedit
     * file konfigurasi manual.
     *
     * @return array<string, array{name: string}>
     */
    private function modelCatalog(): array
    {
        $skip = ['embed', 'tts', 'whisper', 'rerank', 'image', 'dall', 'stable-diffusion', 'moderation'];
        $ids = [];

        try {
            $ids = (array) app(\App\Services\ActiveModelService::class)->available();
        } catch (\Throwable) {
            $ids = [];
        }

        $ids = array_values(array_filter(array_map('trim', $ids), function (string $id) use ($skip) {
            if ($id === '') {
                return false;
            }
            foreach ($skip as $needle) {
                if (str_contains(strtolower($id), $needle)) {
                    return false;
                }
            }

            return true;
        }));

        // Model yang sedang aktif harus selalu terdaftar, meski gateway
        // sementara tidak mengembalikannya di daftar.
        $current = $this->model();
        if (! in_array($current, $ids, true)) {
            $ids[] = $current;
        }

        $catalog = [];
        foreach ($ids as $id) {
            $short = str_contains($id, '/') ? substr($id, strpos($id, '/') + 1) : $id;
            $catalog[$short] = ['name' => $short];
        }

        return $catalog;
    }
}
