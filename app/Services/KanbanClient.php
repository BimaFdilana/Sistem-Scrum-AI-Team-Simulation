<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Klien board kanban Hermes (multi-agent), lewat CLI hermes yang sama
 * dengan HermesTaskRunner (binary dari config ai.hermes.binary, wrapper
 * sudoers ke user sidik di live).
 *
 * Peran klien ini SENGJA sempit: hanya membuat/membaca board dan kartu.
 * Yang mengerjakan kartu adalah worker profile Hermes (dispatcher di
 * dalam gateway), bukan Laravel. Laravel hanya:
 *   - memastikan board project ada (satu board per project, diturunkan
 *     dari project yang tertaut dengan user story, bukan dibuat manual
 *     per story),
 *   - membuat satu kartu todo per execution task, dengan assignee =
 *     profile Hermes role itu (backend-developer / frontend-developer),
 *     workspace = dir:<worktree Laravel task itu>,
 *     // KOREKSI dari probe kanban 1 Nov 11:47: workspace worktree:<path>
 *     // TIDAK berarti worker mengedit path itu langsung — kanban
 *     // membuat sub-worktree di <path>/.worktrees/<card_id> dan worker
 *     // mengedit di sana, sehingga cek git status di worktree Laravel
 *     // menunjukkan kosong dan poll job akan salah menandai no-op.
 *     // dir:<path> dipakai agar worker mengedit worktree Laravel itu
 *     // sendiri (direktori itu sudah terisolasi per task, jadi tetap
 *     // aman). Probe dir: ini belum dijalankan — wajib sebelum
 *     // dispatch live pertama.
 *   - mencatat id kartu ke pesan eksekusi (riwayat/dokumentasi),
 *   - menunggu lewat KanbanExecutionPollJob, lalu menjalankan gerbang
 *     lama: commit + diff + QA review + test gate + merge.
 *
 * CATATAN KEJUJURAN (bukan janji tersembunyi):
 * - Pembuat kartu di sini adalah kode Laravel yang menerjemahkan todo
 *   list breakdown SM. SM profile (scrumagent) sendiri BELUM membuat
 *   kartu lewat tool kanban_create-nya — itu langkah lanjutan, bukan
 *   bagian dari perubahan ini. Yang sudah multi-agent Hermes adalah
 *   pekerjanya (worker profile), bukan pembuat kartunya.
 * - Worker Hermes berjalan sebagai user gateway (sidik di live),
 *   sedangkan worktree dibuat Laravel (worker queue). Bila izin tulis
 *   worktree tidak memungkinkan sidik menulis ke sana, kartu akan
 *   gagal/blocked — verifikasi izin ini wajib sebelum dispatch live
 *   pertama, dan kegagalan itu tercatat sebagai blocked, bukan diam.
 * - Model worker ditentukan profile Hermes (hasil clone default =
 *   glm-5.3 untuk semua role saat ini), BUKAN model per role dari menu
 *   Konfigurasi Agent Laravel. Menu itu tetap dipakai jalur Hermes
 *   lama, bukan jalur kanban ini.
 */
class KanbanClient
{
    private const COMMAND_TIMEOUT = 120;

    /** Slug board untuk satu project: dari nama project, fallback id. */
    public function boardSlugForProject(Project $project): string
    {
        $slug = Str::slug((string) $project->name);
        if ($slug === '') {
            $slug = 'project-'.$project->id;
        }

        return mb_substr($slug, 0, 64);
    }

    /**
     * Pastikan board project ada. Membuat bila belum ada; bila sudah
     * ada, kegagalan "sudah ada" diperlakukan sebagai sukses (idempotent).
     */
    public function ensureBoard(string $slug, string $name, string $description = ''): void
    {
        $command = [$this->binary(), 'kanban', 'boards', 'create', $slug,
            '--name', $name];
        if ($description !== '') {
            $command[] = '--description';
            $command[] = $description;
        }
        $process = $this->run($command);
        if ($process->isSuccessful()) {
            return;
        }
        $combined = $process->getOutput().' '.$process->getErrorOutput();
        if (preg_match('/already exists|duplicate|exists/i', $combined) === 1) {
            return;
        }
        throw new RuntimeException('Gagal membuat board kanban '.$slug.': '.mb_substr(trim($combined), 0, 1000));
    }

    /**
     * Buat satu kartu todo di board. Return id kartu (mis. t_...).
     *
     * @return string id kartu kanban
     */
    public function createTask(
        string $board,
        string $title,
        string $body,
        string $assigneeProfile,
        string $worktree,
        string $idempotencyKey,
    ): string {
        $command = [
            $this->binary(), 'kanban', '--board', $board, 'create', $title,
            '--body', $body,
            '--assignee', $assigneeProfile,
            '--workspace', 'dir:'.$worktree,
            '--idempotency-key', $idempotencyKey,
            '--json',
        ];
        $process = $this->run($command);
        $output = trim($process->getOutput());
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Gagal membuat kartu kanban di board '.$board.': '.mb_substr(trim($output.' '.$process->getErrorOutput()), 0, 1000));
        }
        $id = $this->extractId($output);
        if ($id === null) {
            throw new RuntimeException('Kartu kanban dibuat tapi id tidak bisa dibaca dari output: '.mb_substr($output, 0, 500));
        }

        return $id;
    }

    /**
     * Status kartu: todo|ready|running|blocked|review|done|archived,
     * plus kolom mentah lain bila ada (alasan blocked / error terakhir).
     *
     * @return array{status: string, raw: array<string, mixed>}
     */
    public function showTask(string $board, string $taskId): array
    {
        $command = [$this->binary(), 'kanban', '--board', $board, 'show', $taskId, '--json'];
        $process = $this->run($command);
        $output = trim($process->getOutput());
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Gagal membaca kartu kanban '.$taskId.': '.mb_substr(trim($output.' '.$process->getErrorOutput()), 0, 1000));
        }
        $data = json_decode($output, true);
        if (is_array($data)) {
            $task = $data['task'] ?? $data;
            $status = $task['status'] ?? $task['state'] ?? null;
            if (is_string($status) && $status !== '') {
                return ['status' => strtolower($status), 'raw' => is_array($task) ? $task : []];
            }
        }
        if (preg_match('/"status"\s*:\s*"([^"]+)"/', $output, $m) === 1) {
            return ['status' => strtolower($m[1]), 'raw' => []];
        }
        throw new RuntimeException('Status kartu kanban '.$taskId.' tidak bisa dibaca dari output: '.mb_substr($output, 0, 500));
    }

    private function extractId(string $output): ?string
    {
        $data = json_decode($output, true);
        if (is_array($data)) {
            $task = $data['task'] ?? $data;
            foreach (['id', 'task_id'] as $key) {
                if (isset($task[$key]) && is_string($task[$key]) && $task[$key] !== '') {
                    return $task[$key];
                }
            }
        }
        if (preg_match('/\b(t_[A-Za-z0-9_-]+)\b/', $output, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function binary(): string
    {
        $cfg = config('ai.hermes', []);

        return $cfg['binary'] ?? 'hermes';
    }

    private function run(array $command): Process
    {
        $process = new Process($command, null, null, null, self::COMMAND_TIMEOUT);
        $process->run();

        return $process;
    }
}
