<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orkestrasi integrasi GitHub untuk project (Fase 2).
 *
 * Keputusan desain Bima (final):
 * - Project BARU (dari nol) → WAJIB buatkan repo baru di GitHub PO.
 * - Project IMPORT/clone → cek dulu: repo sudah ada di GitHub PO → pakai
 *   langsung. Belum ada → FORK dari sumber ke GitHub PO.
 * - Branch `main` dibiarkan saja (tidak disentuh).
 *
 * Semua pesan error Bahasa Indonesia. Bila GitHub belum terhubung, pemanggil
 * harus melewati dengan graceful (jangan gagalkan flow project).
 */
class GithubProjectService
{
    public function __construct(
        protected GithubApiClient $github,
    ) {}

    public function isConnected(): bool
    {
        return $this->github->hasToken();
    }

    /**
     * Project baru → buatkan repo baru di GitHub PO.
     *
     * @return array{owner: string, repo: string}
     *
     * @throws RuntimeException bila nama tidak valid, tidak terhubung,
     *                           repo sudah ada, atau pembuatan gagal.
     */
    public function ensureRepoForNewProject(Project $project, string $repoName): array
    {
        $repoName = trim($repoName);
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $repoName) || str_contains($repoName, '..')) {
            throw new RuntimeException('Nama repository GitHub tidak valid. Gunakan huruf, angka, titik, strip, atau underscore.');
        }

        $me = $this->github->me();
        if ($me === null) {
            throw new RuntimeException('Tidak terhubung ke GitHub. Hubungkan dulu di Settings > GitHub.');
        }
        $owner = $me['login'];

        if ($this->github->repoExists($owner, $repoName)) {
            throw new RuntimeException("Repository {$owner}/{$repoName} sudah ada di GitHub kamu. Pakai nama lain.");
        }

        $created = $this->github->createRepo($repoName, true);
        if ($created === null) {
            throw new RuntimeException('Gagal membuat repository di GitHub. Periksa token dan koneksi, lalu coba lagi.');
        }

        Log::info('GitHub repo dibuat untuk project baru', [
            'project_id' => $project->id,
            'repo' => $created['full_name'],
        ]);

        return ['owner' => $owner, 'repo' => $repoName];
    }

    /**
     * Project import/clone → pakai repo PO yang sudah ada, atau fork bila belum ada.
     *
     * @return array{owner: string, repo: string, action: 'existing'|'forked'}
     *
     * @throws RuntimeException bila URL tidak valid, tidak terhubung,
     *                           atau fork gagal.
     */
    public function ensureRepoForImport(Project $project, string $sourceUrl): array
    {
        $normalized = PublicGithubRepositoryService::normalizeUrl($sourceUrl);
        if (! preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)\.git$#', $normalized, $m)) {
            throw new RuntimeException('URL repository GitHub tidak valid.');
        }
        $sourceOwner = $m[1];
        $repoName = $m[2];

        $me = $this->github->me();
        if ($me === null) {
            throw new RuntimeException('Tidak terhubung ke GitHub. Hubungkan dulu di Settings > GitHub.');
        }
        $poUsername = $me['login'];

        // Sudah ada di GitHub PO → pakai langsung, tidak perlu apa-apa.
        if ($this->github->repoExists($poUsername, $repoName)) {
            Log::info('GitHub repo sudah ada, dipakai langsung', [
                'project_id' => $project->id,
                'repo' => $poUsername.'/'.$repoName,
            ]);

            return ['owner' => $poUsername, 'repo' => $repoName, 'action' => 'existing'];
        }

        // Belum ada → fork dari sumber ke akun PO.
        $forked = $this->github->forkRepo($sourceOwner, $repoName);
        if ($forked === null) {
            throw new RuntimeException(
                "Gagal fork repository {$sourceOwner}/{$repoName} ke GitHub kamu. ".
                'Kemungkinan repository bersifat private atau kamu tidak punya akses. '.
                'Minta akses ke pemilik repository, atau pakai URL repository lain.'
            );
        }

        Log::info('GitHub repo di-fork untuk project import', [
            'project_id' => $project->id,
            'from' => $sourceOwner.'/'.$repoName,
            'to' => $forked['full_name'],
        ]);

        return ['owner' => $poUsername, 'repo' => $repoName, 'action' => 'forked'];
    }

    /**
     * Pastikan branch `integration` ada di repo GitHub PO. Bila belum ada,
     * buat dari branch main. Idempotent — aman dipanggil berulang.
     *
     * @throws RuntimeException bila tidak terhubung atau pembuatan gagal.
     */
    public function ensureIntegrationBranch(string $owner, string $repo): void
    {
        if ($this->github->branchSha($owner, $repo, 'integration') !== null) {
            return; // Sudah ada.
        }

        $mainSha = $this->github->branchSha($owner, $repo, 'main');
        if ($mainSha === null) {
            // Fallback ke master bila main tidak ada.
            $mainSha = $this->github->branchSha($owner, $repo, 'master');
        }
        if ($mainSha === null) {
            throw new RuntimeException('Branch main/master tidak ditemukan di GitHub. Periksa repository.');
        }

        if (! $this->github->createBranch($owner, $repo, 'integration', $mainSha)) {
            throw new RuntimeException('Gagal membuat branch integration di GitHub.');
        }

        Log::info('Branch integration dibuat di GitHub', ['repo' => $owner.'/'.$repo]);
    }

    /**
     * Buat story branch `story-{storyId}-{slug}` dari main di GitHub.
     * Return nama branch, atau null bila gagal (pemanggil harus graceful).
     */
    public function createStoryBranch(string $owner, string $repo, int $storyId, string $title): ?string
    {
        $slug = $this->slugify($title);
        $branch = "story-{$storyId}-{$slug}";

        // Sudah ada (mis. retry) → pakai yang ada.
        if ($this->github->branchSha($owner, $repo, $branch) !== null) {
            return $branch;
        }

        $mainSha = $this->github->branchSha($owner, $repo, 'main')
            ?? $this->github->branchSha($owner, $repo, 'master');
        if ($mainSha === null) {
            Log::warning('Story branch gagal: main/master tidak ditemukan', ['repo' => $owner.'/'.$repo]);

            return null;
        }

        if (! $this->github->createBranch($owner, $repo, $branch, $mainSha)) {
            Log::warning('Story branch gagal dibuat', ['repo' => $owner.'/'.$repo, 'branch' => $branch]);

            return null;
        }

        Log::info('Story branch dibuat di GitHub', ['repo' => $owner.'/'.$repo, 'branch' => $branch]);

        return $branch;
    }

    /**
     * Push branch lokal ke GitHub (token via http.extraHeader, tidak tersimpan).
     *
     * @throws RuntimeException bila push gagal.
     */
    public function pushBranch(string $workspacePath, string $owner, string $repo, string $localRef, string $remoteBranch): void
    {
        $token = $this->github->token();
        if (! filled($token)) {
            throw new RuntimeException('Tidak terhubung ke GitHub.');
        }

        $authHeader = 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token);
        $this->git($workspacePath, ['-c', "http.extraHeader={$authHeader}", 'push', 'origin', "{$localRef}:{$remoteBranch}"]);

        Log::info('Branch di-push ke GitHub', ['repo' => $owner.'/'.$repo, 'branch' => $remoteBranch]);
    }

    /**
     * Fetch dari origin dan update branch lokal agar sama dengan remote.
     * Membuat worktree/branch lokal bila belum ada.
     */
    public function syncBranchFromRemote(string $workspacePath, string $branch): void
    {
        $token = $this->github->token();
        $extraArgs = [];
        if (filled($token)) {
            $authHeader = 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token);
            $extraArgs = ['-c', "http.extraHeader={$authHeader}"];
        }

        $args = array_merge($extraArgs, ['fetch', 'origin', $branch]);
        $this->git($workspacePath, $args);

        // Update atau buat branch lokal dari origin/{branch}.
        try {
            $this->git($workspacePath, ['rev-parse', '--verify', $branch]);
            $branchExists = true;
        } catch (RuntimeException) {
            $branchExists = false;
        }

        if ($branchExists) {
            $this->git($workspacePath, ['branch', '-f', $branch, "origin/{$branch}"]);
        } else {
            $this->git($workspacePath, ['branch', $branch, "origin/{$branch}"]);
        }
    }

    /**
     * Slug aman untuk nama branch dari judul story.
     */
    protected function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 40) : 'story';
    }

    /**
     * Push baseline lokal ke repo GitHub PO.
     *
     * Token TIDAK pernah ditulis ke remote URL / git config / log — hanya
     * diteruskan via `http.extraHeader` pada command push.
     *
     * @throws RuntimeException bila tidak terhubung, workspace tidak ada,
     *                           atau push gagal.
     */
    public function pushBaseline(Project $project, string $owner, string $repo): void
    {
        $token = $this->github->token();
        if (! filled($token)) {
            throw new RuntimeException('Tidak terhubung ke GitHub. Hubungkan dulu di Settings > GitHub.');
        }

        $workspace = (string) $project->workspace_path;
        if ($workspace === '' || ! is_dir($workspace)) {
            throw new RuntimeException('Workspace project belum siap untuk di-push ke GitHub.');
        }

        $remoteUrl = "https://github.com/{$owner}/{$repo}.git";
        // Basic auth: username x-access-token, password = PAT. Base64 dihitung
        // di sini agar token tidak muncul sebagai argumen terpisah.
        $authHeader = 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token);

        $this->git($workspace, ['init', '-b', 'main']);
        $this->git($workspace, ['add', '-A']);
        // Commit hanya bila ada perubahan (baseline bisa sudah ter-commit).
        $status = $this->git($workspace, ['status', '--porcelain']);
        if (trim($status) !== '') {
            $this->git($workspace, [
                '-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace',
                'commit', '-m', 'Baseline awal project', '--no-verify',
            ]);
        }
        $this->git($workspace, ['remote', 'remove', 'origin']);
        $this->git($workspace, ['remote', 'add', 'origin', $remoteUrl]);
        // Token hanya via extraHeader — tidak tersimpan di .git/config.
        $this->git($workspace, ['-c', "http.extraHeader={$authHeader}", 'push', '-u', 'origin', 'main']);

        Log::info('Baseline di-push ke GitHub', [
            'project_id' => $project->id,
            'repo' => $owner.'/'.$repo,
        ]);
    }

    /**
     * Jalankan git via proc_open (tanpa shell) dengan env minimal + proxy.
     */
    protected function git(string $cwd, array $args): string
    {
        $command = array_merge(['git', '-c', 'credential.helper='], $args);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'no_proxy', 'NO_PROXY'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        $process = proc_open($command, $descriptor, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Git tidak dapat dijalankan di server.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            // Jangan sertakan output mentah — bisa mengandung URL dengan token
            // bila command lain bocor. Pesan generik Bahasa Indonesia.
            throw new RuntimeException('Perintah git gagal saat sinkronisasi ke GitHub.');
        }

        return $stdout;
    }
}
