<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client untuk GitHub REST API, terautentikasi via Personal Access Token (PAT).
 *
 * Token diambil dari (urutan prioritas):
 *   1. Database (tabel settings, key 'github_pat') — diatur via UI Settings > GitHub
 *   2. config('services.github.token') — dari env GITHUB_PAT
 *
 * Semua method TIDAK melempar exception ke pemanggil: gagal → null/false + log.
 * Pesan untuk user selalu Bahasa Indonesia (di layer controller/view).
 */
class GithubApiClient
{
    protected const BASE_URL = 'https://api.github.com';

    protected ?string $token;

    protected int $timeout;

    public function __construct()
    {
        $this->token = $this->resolveToken();
        $this->timeout = 15;
    }

    /**
     * Ambil PAT: database dulu, lalu env.
     */
    protected function resolveToken(): ?string
    {
        try {
            $fromDb = \App\Models\Setting::get('github_pat');
            if (filled($fromDb)) {
                return $fromDb;
            }
        } catch (Throwable) {
            // Tabel belum ada (mis. sebelum migrasi) — lanjut ke env.
        }

        return config('services.github.token') ?: null;
    }

    public function hasToken(): bool
    {
        return filled($this->token);
    }

    /**
     * Token mentah untuk operasi git (push). Pemanggil WAJIB tidak me-log
     * nilainya dan hanya meneruskannya via http.extraHeader.
     */
    public function token(): ?string
    {
        return $this->token ?: null;
    }

    /**
     * Hapus token dari database (untuk fitur "Putuskan").
     */
    public function forgetToken(): void
    {
        try {
            \App\Models\Setting::forget('github_pat');
        } catch (Throwable) {
            // Abaikan — tabel mungkin belum ada.
        }
        $this->token = config('services.github.token') ?: null;
    }

    /**
     * Profil user pemilik token. null bila token invalid / tak ada koneksi.
     *
     * @return array{login: string, name: ?string, avatar_url: ?string}|null
     */
    public function me(): ?array
    {
        $data = $this->get('/user');
        if (! is_array($data) || ! isset($data['login'])) {
            return null;
        }

        return [
            'login' => (string) $data['login'],
            'name' => isset($data['name']) ? (string) $data['name'] : null,
            'avatar_url' => isset($data['avatar_url']) ? (string) $data['avatar_url'] : null,
        ];
    }

    /**
     * Cek apakah repo ada (dan token bisa melihatnya).
     */
    public function repoExists(string $owner, string $repo): bool
    {
        if (! $this->hasToken()) {
            return false;
        }

        try {
            $response = $this->request()->get($this->url("/repos/{$owner}/{$repo}"));

            return $response->status() !== 404;
        } catch (Throwable $e) {
            Log::warning('GitHub repoExists gagal', ['owner' => $owner, 'repo' => $repo, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Buat repo baru di akun pemilik token.
     *
     * @return array{name: string, full_name: string, html_url: string, private: bool}|null
     */
    public function createRepo(string $name, bool $private = true): ?array
    {
        $data = $this->post('/user/repos', [
            'name' => $name,
            'private' => $private,
            'auto_init' => false,
        ]);

        if (! is_array($data) || ! isset($data['full_name'])) {
            return null;
        }

        return [
            'name' => (string) ($data['name'] ?? $name),
            'full_name' => (string) $data['full_name'],
            'html_url' => (string) ($data['html_url'] ?? ''),
            'private' => (bool) ($data['private'] ?? $private),
        ];
    }

    /**
     * Fork repo ke akun pemilik token.
     *
     * @return array{name: string, full_name: string, html_url: string}|null
     */
    public function forkRepo(string $owner, string $repo): ?array
    {
        $data = $this->post("/repos/{$owner}/{$repo}/forks", []);

        if (! is_array($data) || ! isset($data['full_name'])) {
            return null;
        }

        return [
            'name' => (string) ($data['name'] ?? $repo),
            'full_name' => (string) $data['full_name'],
            'html_url' => (string) ($data['html_url'] ?? ''),
        ];
    }

    /**
     * Buat branch baru dari SHA tertentu.
     */
    public function createBranch(string $owner, string $repo, string $branch, string $fromSha): bool
    {
        $data = $this->post("/repos/{$owner}/{$repo}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $fromSha,
        ]);

        return is_array($data) && isset($data['ref']);
    }

    /**
     * Merge branch head ke base. Return null bila konflik / gagal.
     *
     * @return array{sha: string, merged: bool}|null
     */
    public function mergeBranch(string $owner, string $repo, string $base, string $head): ?array
    {
        $data = $this->post("/repos/{$owner}/{$repo}/merges", [
            'base' => $base,
            'head' => $head,
        ]);

        if (! is_array($data)) {
            return null;
        }

        return [
            'sha' => (string) ($data['sha'] ?? ''),
            'merged' => (bool) ($data['merged'] ?? false),
        ];
    }

    /**
     * Ambil SHA head dari branch (untuk createBranch).
     */
    public function branchSha(string $owner, string $repo, string $branch): ?string
    {
        $data = $this->get("/repos/{$owner}/{$repo}/git/ref/heads/{$branch}");

        return is_array($data) ? (string) ($data['object']['sha'] ?? null) ?: null : null;
    }

    // ------------------------------------------------------------------
    // HTTP helpers (internal)
    // ------------------------------------------------------------------

    protected function url(string $path): string
    {
        return self::BASE_URL.$path;
    }

    protected function request()
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) $this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }

    /**
     * GET dengan penanganan error diam-diam (null bila gagal).
     */
    protected function get(string $path): ?array
    {
        if (! $this->hasToken()) {
            return null;
        }

        try {
            $response = $this->request()->get($this->url($path));
            if (! $response->successful()) {
                Log::warning('GitHub GET gagal', ['path' => $path, 'status' => $response->status()]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            Log::warning('GitHub GET exception', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * POST dengan penanganan error diam-diam (null bila gagal).
     */
    protected function post(string $path, array $payload): ?array
    {
        if (! $this->hasToken()) {
            return null;
        }

        try {
            $response = $this->request()->post($this->url($path), $payload);
            if (! $response->successful()) {
                Log::warning('GitHub POST gagal', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);

                return null;
            }

            // 202 Accepted (fork) atau 201/200 — body bisa kosong untuk 204.
            if ($response->status() === 204 || trim((string) $response->body()) === '') {
                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            Log::warning('GitHub POST exception', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
