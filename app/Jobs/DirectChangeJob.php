<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\DirectChange;
use App\Models\ExecutionRun;
use App\Support\Execution\DirectWorkspace;
use App\Support\Execution\OpenCodeRunner;
use App\Support\Execution\ProjectFacts;
use App\Support\Workspace\WorktreeManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Perubahan kode langsung dari chat PO, di luar alur Scrum.
 *
 * Dikerjakan peran Developer (mode langsung), bukan Scrum Master. Agar aman
 * tanpa QA: pekerjaan dibuat di cabang scrum/chat-{id}, file PHP diperiksa
 * sintaksnya, baru digabung ke cabang utama. Gagal = kode utama tidak berubah.
 * Setiap perubahan yang selesai bisa dibatalkan PO dengan satu klik (git revert).
 */
class DirectChangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $changeId) {}

    public function handle(WorktreeManager $git, DirectWorkspace $direct, OpenCodeRunner $runner, ProjectFacts $facts): void
    {
        $change = DirectChange::query()->with('project')->find($this->changeId);
        if (! $change || $change->status !== 'QUEUED') {
            return;
        }
        $project = $change->project;
        $path = (string) $project?->workspace_path;
        if ($project === null || $path === '' || ! is_dir($path)) {
            $this->finish($change, 'FAILED', null, 'Kode project belum siap (baseline tidak ditemukan).');

            return;
        }

        $change->forceFill(['status' => 'RUNNING', 'started_at' => now()])->save();
        $this->updateMessage($change);

        try {
            $git->ensureRepo($path);
            $direct->recoverLeftovers($project);

            $current = $git->currentBranch($path);
            if (str_starts_with($current, 'scrum/run-')) {
                $run = ExecutionRun::query()->with('story')->where('work_branch', $current)->first();
                if ($run !== null && in_array($run->status, DirectWorkspace::ACTIVE, true)) {
                    throw new RuntimeException('Eksekusi '.$run->subjectLabel().' sedang berjalan di project ini. Tunggu sampai selesai, lalu kirim permintaan lagi.');
                }
                if ($run !== null && $run->story && $run->story->acceptance_status === null && $run->status !== 'FAILED') {
                    throw new RuntimeException('Hasil eksekusi '.$run->subjectLabel().' masih menunggu keputusan Anda (Terima atau Tandai Gagal). Putuskan dulu agar perubahan langsung tidak bertabrakan.');
                }
                $current = $run?->base_branch ?: 'main';
                $git->checkoutBranch($path, $current);
            }

            $base = $current !== '' ? $current : 'main';
            $before = $git->head($path);
            $branch = 'scrum/chat-'.$change->id;
            $git->createBranchAndCheckout($path, $branch, $base);
            $change->forceFill(['base_branch' => $base, 'branch' => $branch, 'commit_before' => $before])->save();

            // Batas 15 menit: di bawah retry_after antrean (960 dtk) agar job tidak diambil ganda.
            $result = $runner->run($path, $this->prompt($change, $facts->section($project)), 900);
            $files = $git->dirtyFiles($path);

            if (! $result['ok']) {
                $this->park($git, $path, $base, $change, $files);
                throw new RuntimeException('Developer gagal menyelesaikan perubahan (kode '.$result['exit'].'): '.Str::limit(trim((string) $result['output']), 400));
            }
            if ($files === []) {
                $git->checkoutBranch($path, $base);
                $this->finish($change, 'NO_CHANGES', 'Developer tidak mengubah file apa pun. Coba jelaskan permintaan dengan lebih spesifik (halaman, tombol, atau data yang dimaksud).', null);

                return;
            }

            $lint = $this->lint($path, $files);
            if ($lint !== null) {
                $this->park($git, $path, $base, $change, $files);
                throw new RuntimeException('Perubahan dibatalkan karena kode PHP tidak valid: '.$lint);
            }

            $git->commit($path, 'Chat #'.$change->id.': '.Str::limit(preg_replace('/\s+/', ' ', $change->request), 70));
            $git->checkoutBranch($path, $base);
            $git->merge($path, $branch);

            $change->forceFill(['files' => $files, 'commit_after' => $git->head($path)])->save();
            $this->finish($change, 'DONE', count($files).' file diubah dan langsung diterapkan ke kode utama ('.$base.').', null);
        } catch (Throwable $exception) {
            try {
                if ($git->currentBranch($path) !== ($change->base_branch ?: 'main')) {
                    $this->park($git, $path, $change->base_branch ?: 'main', $change, $git->dirtyFiles($path));
                }
            } catch (Throwable) {
                // best-effort; recoverLeftovers() memulihkan pada percobaan berikutnya
            }
            $this->finish($change, 'FAILED', null, Str::limit($exception->getMessage(), 800));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $change = DirectChange::query()->find($this->changeId);
        if ($change && $change->isActive()) {
            $this->finish($change, 'FAILED', null, 'Proses berhenti: '.Str::limit((string) $exception?->getMessage(), 400));
        }
    }

    private function prompt(DirectChange $change, string $facts): string
    {
        return implode("\n\n", array_filter([
            'Kamu Developer full-stack pada project Laravel di direktori kerja ini. Product Owner meminta perubahan kode secara langsung (di luar alur Scrum). Kerjakan permintaan ini dengan perubahan sekecil mungkin yang benar dan konsisten dengan gaya kode project.',
            "PERMINTAAN PO:\n".trim($change->request),
            $facts,
            implode("\n", [
                'ATURAN WAJIB:',
                '1. Kerjakan hanya di direktori kerja ini, langsung ubah file yang diperlukan.',
                '2. JANGAN menjalankan perintah git.',
                '3. JANGAN menjalankan tes yang lama (php artisan test, phpunit), composer, atau npm install.',
                '4. Jangan ubah .env, file vendor, atau node_modules.',
                '5. Bila perlu kolom database baru, buat file migrasi baru; jangan ubah migrasi lama.',
                '6. Selesaikan tanpa menunggu konfirmasi; jawaban teks tanpa perubahan file dianggap gagal.',
            ]),
        ]));
    }

    /** Simpan pekerjaan yang gagal di cabangnya sendiri, lalu kembali ke cabang utama. */
    private function park(WorktreeManager $git, string $path, string $base, DirectChange $change, array $files): void
    {
        if ($files !== []) {
            $git->commit($path, 'wip(chat-'.$change->id.'): perubahan yang tidak diterapkan');
        }
        $git->checkoutBranch($path, $base);
        $change->forceFill(['files' => $files])->save();
    }

    /** @param array<int, string> $files */
    private function lint(string $path, array $files): ?string
    {
        foreach ($files as $file) {
            if (! str_ends_with($file, '.php') || ! is_file($path.'/'.$file)) {
                continue;
            }
            $result = Process::path($path)->timeout(30)->run(['php', '-l', $file]);
            if (! $result->successful()) {
                return $file.' — '.Str::limit(trim($result->output().' '.$result->errorOutput()), 300);
            }
        }

        return null;
    }

    private function finish(DirectChange $change, string $status, ?string $summary, ?string $error): void
    {
        $change->forceFill([
            'status' => $status,
            'summary' => $summary,
            'error' => $error,
            'completed_at' => now(),
        ])->save();
        $this->updateMessage($change);
        $change->conversation?->touch();
    }

    private function updateMessage(DirectChange $change): void
    {
        $text = match ($change->status) {
            'DONE' => 'Perubahan kode selesai diterapkan.',
            'NO_CHANGES' => 'Tidak ada perubahan kode.',
            'FAILED' => 'Perubahan kode gagal; kode utama tidak berubah.',
            'RUNNING' => 'Developer sedang mengubah kode…',
            default => 'Permintaan perubahan kode diterima.',
        };
        ChatMessage::query()->where('direct_change_id', $change->id)->where('role', 'assistant')
            ->update(['content' => $text]);
    }
}
