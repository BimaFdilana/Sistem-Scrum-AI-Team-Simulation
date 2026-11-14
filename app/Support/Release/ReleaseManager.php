<?php

namespace App\Support\Release;

use App\Models\ExecutionRun;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\User;
use App\Services\GithubApiClient;
use App\Support\Execution\DirectWorkspace;
use App\Support\Ui\FriendlyText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Demo & Rilis: menyatukan hasil kerja tim (cabang main di ScrumSpace) dengan
 * GitHub, lalu mengirimnya ke GitHub sebagai satu project utuh.
 *
 * - Satukan: perubahan GitHub yang belum ada di ScrumSpace digabung lebih dulu di
 *   salinan sementara; kode utama baru berubah bila penggabungan bersih.
 * - Kirim: push main ke GitHub (bukan force) beserta tanda cadangan (tag) versi
 *   GitHub sebelumnya, sehingga bisa dikembalikan.
 * Token hanya dikirim lewat header perintah git, tidak pernah disimpan di repo.
 */
class ReleaseManager
{
    private const BRANCH = 'main';

    public function __construct(private readonly GithubApiClient $github) {}

    /** @return array<string, mixed> */
    public function status(Project $project, bool $fetch = true): array
    {
        $repo = $this->repo($project);
        $status = [
            'ready' => false, 'reason' => null, 'remote' => null, 'ahead' => 0, 'behind' => 0, 'items' => [],
            'blockers' => [], 'connected' => $this->github->hasToken(), 'fetch_error' => null, 'releases' => collect(),
        ];
        if ($repo === null) {
            $status['reason'] = 'Kode project belum siap.';

            return $status;
        }
        $remote = trim($this->git($repo, ['remote', 'get-url', 'origin'], true));
        if ($remote === '') {
            $status['reason'] = 'Project belum terhubung ke repository GitHub.';

            return $status;
        }
        $status['remote'] = (string) preg_replace('#//[^/@]*@#', '//', $remote);

        if ($fetch) {
            try {
                $this->authGit($repo, ['fetch', '-q', 'origin', self::BRANCH]);
            } catch (Throwable $exception) {
                $status['fetch_error'] = $this->friendlyGitError($exception->getMessage());
            }
        }
        $hasRemote = trim($this->git($repo, ['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.self::BRANCH], true)) !== '';
        if (! $hasRemote) {
            $status['reason'] = 'Cabang main di GitHub belum terbaca.';

            return $status;
        }

        $status['ahead'] = (int) trim($this->git($repo, ['rev-list', '--count', 'refs/remotes/origin/'.self::BRANCH.'..refs/heads/'.self::BRANCH]));
        $status['behind'] = (int) trim($this->git($repo, ['rev-list', '--count', 'refs/heads/'.self::BRANCH.'..refs/remotes/origin/'.self::BRANCH]));
        $status['items'] = $this->items($repo);
        $status['blockers'] = $this->blockers($project);
        $status['releases'] = ProjectRelease::query()->where('project_id', $project->id)->latest('id')->limit(10)->get();
        $status['ready'] = true;

        return $status;
    }

    /**
     * Gabungkan perubahan GitHub ke kode utama ScrumSpace.
     *
     * @param  string|null  $strategy  null = berhenti bila bentrok; 'ours' = pakai versi ScrumSpace
     *                                 untuk bagian yang bentrok; 'theirs' = pakai versi GitHub.
     * @return array{ok: bool, message: string, conflicts: array<int, string>}
     */
    public function unify(Project $project, ?string $strategy = null): array
    {
        $repo = $this->repoOrFail($project);
        $this->assertNoBlockers($project);
        $this->authGit($repo, ['fetch', '-q', 'origin', self::BRANCH]);

        $behind = (int) trim($this->git($repo, ['rev-list', '--count', 'refs/heads/'.self::BRANCH.'..refs/remotes/origin/'.self::BRANCH]));
        if ($behind === 0) {
            return ['ok' => true, 'message' => 'Sudah menyatu: semua perubahan di GitHub sudah ada di ScrumSpace.', 'conflicts' => []];
        }

        // Gabungkan di salinan sementara: kode utama dan demo tidak tersentuh bila bentrok.
        $tmp = storage_path('app/release-tmp/'.$project->id.'-'.Str::random(6));
        $this->git($repo, ['worktree', 'add', '--detach', $tmp, 'refs/heads/'.self::BRANCH]);
        try {
            $args = ['-c', 'user.email=scrumspace@local', '-c', 'user.name=ScrumSpace', 'merge', '--no-ff', '--no-edit', '-m',
                'Satukan dengan GitHub: '.$behind.' perubahan dari repository digabung ke hasil kerja tim'];
            if (in_array($strategy, ['ours', 'theirs'], true)) {
                $args[] = '-X';
                $args[] = $strategy;
            }
            $args[] = 'refs/remotes/origin/'.self::BRANCH;
            $merge = $this->run($tmp, $args);
            if (! $merge->isSuccessful()) {
                $conflicts = array_values(array_filter(array_map('trim', explode("\n", $this->git($tmp, ['diff', '--name-only', '--diff-filter=U'], true)))));
                $this->git($tmp, ['merge', '--abort'], true);

                return [
                    'ok' => false,
                    'message' => $conflicts !== []
                        ? count($conflicts).' file bentrok: perubahan di GitHub dan hasil kerja tim mengubah bagian yang sama. Tidak ada yang berubah.'
                        : 'Penggabungan gagal: '.Str::limit(trim($merge->getErrorOutput().' '.$merge->getOutput()), 300),
                    'conflicts' => $conflicts,
                ];
            }
            $merged = trim($this->git($tmp, ['rev-parse', 'HEAD']));
        } finally {
            $this->git($repo, ['worktree', 'remove', '--force', $tmp], true);
        }

        // Majukan cabang main ke hasil gabungan (selalu maju, tidak menimpa riwayat).
        $current = trim($this->git($repo, ['branch', '--show-current'], true));
        if ($current === self::BRANCH) {
            if (trim($this->git($repo, ['status', '--porcelain'])) !== '') {
                throw new RuntimeException('Kode project sedang punya perubahan yang belum disimpan. Coba lagi setelah eksekusi selesai.');
            }
            $this->git($repo, ['merge', '--ff-only', $merged]);
        } else {
            $this->git($repo, ['update-ref', 'refs/heads/'.self::BRANCH, $merged]);
        }

        return ['ok' => true, 'message' => 'Project disatukan: '.$behind.' perubahan dari GitHub digabung dengan hasil kerja tim. Demo diperbarui; cek dulu sebelum mengirim ke GitHub.', 'conflicts' => []];
    }

    /** Kirim kode utama ke GitHub, dengan tanda cadangan versi GitHub sebelumnya. */
    public function push(Project $project, ?User $user): ProjectRelease
    {
        $repo = $this->repoOrFail($project);
        $this->assertNoBlockers($project);
        if (! $this->github->hasToken() && $this->isHttpRemote($repo)) {
            throw new RuntimeException('GitHub belum terhubung. Simpan token GitHub di bagian Koneksi GitHub.');
        }
        $this->authGit($repo, ['fetch', '-q', 'origin', self::BRANCH]);
        $behind = (int) trim($this->git($repo, ['rev-list', '--count', 'refs/heads/'.self::BRANCH.'..refs/remotes/origin/'.self::BRANCH]));
        if ($behind > 0) {
            throw new RuntimeException('GitHub punya '.$behind.' perubahan yang belum ada di ScrumSpace. Klik "Satukan menjadi 1 project" dulu.');
        }
        $ahead = (int) trim($this->git($repo, ['rev-list', '--count', 'refs/remotes/origin/'.self::BRANCH.'..refs/heads/'.self::BRANCH]));
        if ($ahead === 0) {
            throw new RuntimeException('Tidak ada yang perlu dikirim: GitHub sudah sama dengan ScrumSpace.');
        }

        $previous = trim($this->git($repo, ['rev-parse', 'refs/remotes/origin/'.self::BRANCH]));
        $head = trim($this->git($repo, ['rev-parse', 'refs/heads/'.self::BRANCH]));
        $tag = 'scrumspace/sebelum-rilis-'.now()->format('Ymd-His');
        $items = $this->items($repo);

        try {
            $this->git($repo, ['tag', '-f', $tag, $previous]);
            $this->authGit($repo, ['push', '--atomic', 'origin', 'refs/heads/'.self::BRANCH.':refs/heads/'.self::BRANCH, 'refs/tags/'.$tag.':refs/tags/'.$tag]);
        } catch (Throwable $exception) {
            // Kirim gagal: tanda cadangan lokal tidak dipakai, jangan menumpuk.
            $this->git($repo, ['tag', '-d', $tag], true);
            $release = ProjectRelease::query()->create([
                'project_id' => $project->id, 'user_id' => $user?->id, 'status' => 'FAILED', 'commit_sha' => $head,
                'previous_sha' => $previous, 'backup_tag' => null, 'items' => $items, 'commit_count' => $ahead,
                'error' => $this->friendlyGitError($exception->getMessage()),
            ]);
            Log::warning('Rilis ke GitHub gagal', ['project' => $project->id, 'error' => Str::limit($exception->getMessage(), 500)]);

            return $release;
        }

        return ProjectRelease::query()->create([
            'project_id' => $project->id, 'user_id' => $user?->id, 'status' => 'PUSHED', 'commit_sha' => $head,
            'previous_sha' => $previous, 'backup_tag' => $tag, 'items' => $items, 'commit_count' => $ahead,
        ]);
    }

    /** @return array<int, string> Story/perintah yang ikut rilis (dari commit "Merge scrum/run-N"). */
    private function items(string $repo): array
    {
        $subjects = explode("\n", $this->git($repo, ['log', '--format=%s', 'refs/remotes/origin/'.self::BRANCH.'..refs/heads/'.self::BRANCH], true));
        $runIds = [];
        foreach ($subjects as $subject) {
            if (preg_match('/^Merge scrum\/run-(\d+)$/', trim($subject), $m)) {
                $runIds[] = (int) $m[1];
            }
        }
        if ($runIds === []) {
            return [];
        }

        return ExecutionRun::query()->with('story')->whereIn('id', $runIds)->orderBy('id')->get()
            ->map(fn (ExecutionRun $run) => FriendlyText::clean($run->subjectLabel()))
            ->unique()->values()->all();
    }

    /** @return array<int, string> */
    private function blockers(Project $project): array
    {
        $busy = ExecutionRun::query()->with('story')->whereIn('status', DirectWorkspace::ACTIVE)
            ->whereHas('story', fn ($q) => $q->where('project_id', $project->id))->first();

        return $busy ? ['Tim masih mengerjakan '.FriendlyText::clean($busy->subjectLabel()).'. Tunggu sampai selesai.'] : [];
    }

    private function assertNoBlockers(Project $project): void
    {
        $blockers = $this->blockers($project);
        if ($blockers !== []) {
            throw new RuntimeException($blockers[0]);
        }
    }

    private function repo(Project $project): ?string
    {
        $repo = rtrim((string) $project->workspace_path, '/');

        return $repo !== '' && (is_dir($repo.'/.git') || is_file($repo.'/.git')) ? $repo : null;
    }

    private function repoOrFail(Project $project): string
    {
        return $this->repo($project) ?? throw new RuntimeException('Kode project belum siap.');
    }

    private function isHttpRemote(string $repo): bool
    {
        return str_starts_with(trim($this->git($repo, ['remote', 'get-url', 'origin'], true)), 'http');
    }

    private function friendlyGitError(string $message): string
    {
        $lower = mb_strtolower($message);

        return match (true) {
            str_contains($lower, '403') || str_contains($lower, 'permission') || str_contains($lower, 'denied') => 'GitHub menolak: token tidak punya izin menulis. Buat token baru dengan izin Contents: Read and write untuk repository ini.',
            str_contains($lower, '401') || str_contains($lower, 'authentication') => 'GitHub menolak: token tidak valid atau kedaluwarsa. Simpan token baru di Koneksi GitHub.',
            str_contains($lower, 'non-fast-forward') || str_contains($lower, 'rejected') || str_contains($lower, 'fetch first') => 'GitHub punya perubahan baru. Klik "Satukan menjadi 1 project" lalu kirim lagi.',
            str_contains($lower, 'could not resolve') || str_contains($lower, 'unable to access') => 'GitHub tidak dapat dihubungi dari server. Coba lagi sebentar.',
            default => Str::limit(trim($message), 300),
        };
    }

    /** git dengan token GitHub via header (hanya untuk perintah yang menghubungi GitHub). */
    private function authGit(string $repo, array $args): string
    {
        $token = $this->github->token();
        $auth = filled($token) && $this->isHttpRemote($repo)
            ? ['-c', 'http.extraHeader=AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token)]
            : [];

        try {
            return $this->git($repo, [...$auth, ...$args]);
        } catch (RuntimeException $exception) {
            // Repository publik tetap bisa dibaca walau token salah/kurang izin.
            if ($auth !== [] && ($args[0] ?? null) === 'fetch') {
                return $this->git($repo, $args);
            }
            throw $exception;
        }
    }

    private function git(string $cwd, array $args, bool $allowFailure = false): string
    {
        $process = $this->run($cwd, $args);
        if (! $process->isSuccessful() && ! $allowFailure) {
            // Token tidak pernah ikut pesan galat.
            throw new RuntimeException((string) preg_replace('/basic\s+[A-Za-z0-9+\/=]+/', 'basic ***', trim($process->getErrorOutput().' '.$process->getOutput())));
        }

        return $process->getOutput();
    }

    private function run(string $cwd, array $args): Process
    {
        $process = new Process(['git', '-c', 'credential.helper=', ...$args], $cwd, [
            'GIT_TERMINAL_PROMPT' => '0',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
