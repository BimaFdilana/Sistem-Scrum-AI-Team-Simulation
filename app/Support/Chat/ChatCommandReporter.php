<?php

namespace App\Support\Chat;

use App\Models\ChatCommand;
use App\Models\ExecutionRun;
use App\Support\Ai\AiQuota;
use App\Support\Ui\FriendlyText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scrum Master melapor ke chat PO saat pekerjaan sebuah perintah chat
 * (Tanya Scrum Master) berpindah tahap: mulai dikerjakan, selesai, berhenti,
 * atau gagal. Satu laporan per tahap per run (tidak dobel).
 */
class ChatCommandReporter
{
    private const REPORTABLE = ['EXECUTING', 'COMPLETED', 'NEEDS_PO', 'FAILED', 'BLOCKED'];

    public static function statusChanged(ExecutionRun $run): void
    {
        if (! in_array($run->status, self::REPORTABLE, true)) {
            return;
        }

        try {
            $story = $run->story()->first();
            if ($story === null || ! $story->isChatCommand()) {
                return;
            }

            DB::transaction(function () use ($run, $story): void {
                $command = ChatCommand::query()->where('story_id', $story->id)->lockForUpdate()->first();
                $conversation = $command?->conversation;
                if ($command === null || $conversation === null) {
                    return;
                }
                $key = $run->id.':'.$run->status;
                $reported = $command->reported ?? [];
                if (in_array($key, $reported, true)) {
                    return;
                }

                $conversation->messages()->create([
                    'role' => 'assistant',
                    'kind' => 'report',
                    'content' => self::content($run),
                    'link_url' => route('stories.executions.show', [$story, $run], false),
                    'link_label' => 'Lihat proses lengkap',
                    'execution_run_id' => $run->id,
                ]);
                $command->forceFill(['reported' => array_slice([...$reported, $key], -50)])->save();
                $conversation->touch();
            });
        } catch (Throwable $exception) {
            Log::warning('Laporan perintah chat gagal dikirim', ['run_id' => $run->id, 'error' => $exception->getMessage()]);
        }
    }

    private static function content(ExecutionRun $run): string
    {
        $tasks = $run->tasks()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($task) => '- ['.($task->assignee === 'frontend-developer' ? 'FE' : 'BE').'] '.$task->title)
            ->implode("\n");
        $summary = FriendlyText::clean(trim((string) Str::of((string) $run->summary)->before("\nMode langsung")));

        return match ($run->status) {
            'EXECUTING' => "Perintah Anda saya pecah menjadi tugas untuk tim dan langsung dikerjakan:\n".$tasks
                ."\n\nQA akan memeriksa hasilnya setelah semua tugas selesai. Saya kabari lagi di sini.",
            'COMPLETED' => "Perintah selesai dikerjakan dan lolos pemeriksaan QA.\n".$tasks
                .($summary !== '' ? "\n\nCatatan QA: ".Str::limit($summary, 400) : '')
                ."\n\nCoba hasilnya di demo (Pengaturan & Demo project), lalu pilih **Terima** atau **Tandai Gagal** di bawah.",
            'NEEDS_PO' => $run->error_code === 'QUOTA_EXHAUSTED'
                ? "⚠ Pekerjaan dijeda karena kuota model AI habis.\n\n".Str::limit($summary, 400)."\n\nSetelah mengganti model aktif di AI Providers (atau kuota pulih), klik **Lanjutkan**. Pekerjaan diteruskan dari task yang tertahan."
                : 'Pekerjaan berhenti dan butuh keputusan Anda: '.Str::limit($summary !== '' ? $summary : 'lihat detail proses.', 500)
                ."\n\nKlik **Lanjutkan** untuk mencoba lagi, atau **Tandai Gagal**.",
            default => 'Perintah gagal dikerjakan. '.self::failureExplanation($run)
                ."\n\nKode utama tidak berubah. ".match (self::failureKind($run)) {
                    'transient' => 'Gangguan ini sementara, jadi aman klik **Jalankan ulang**.',
                    'setup' => 'Perbaiki pengaturannya dulu, lalu klik **Jalankan ulang**.',
                    default => 'Menjalankan ulang hal yang sama akan gagal lagi, jadi klik **Rumuskan ulang**: saya rumuskan perintahnya lebih jelas atau lebih kecil.',
                }
            .($summary !== '' ? "\n\nDetail teknis: ".Str::limit($summary, 300) : ''),
        };
    }

    /**
     * Jenis kegagalan, menentukan tombol yang ditawarkan ke PO:
     * - transient: gangguan sementara (AI sibuk, koneksi, restart) -> Jalankan ulang
     * - too_big: pekerjaan terlalu besar (waktu habis, QA terus menolak) -> Rumuskan ulang
     * - unclear: SM tidak bisa memetakan perintah jadi tugas -> Rumuskan ulang
     * - setup: model AI belum siap -> perbaiki pengaturan
     */
    public static function failureKind(ExecutionRun $run): string
    {
        $code = (string) $run->error_code;
        $text = mb_strtolower((string) $run->summary);

        if (in_array($code, ['MODEL_NOT_CHOSEN', 'MODEL_PROBLEMATIC', 'QUOTA_EXHAUSTED'], true) || AiQuota::isQuotaText($text)) {
            return 'setup';
        }
        if (in_array($code, ['TASK_STALLED', 'QA_REVISION_LIMIT', 'GATE_FAILED'], true)
            || str_contains($text, 'exceeded the timeout') || str_contains($text, 'timed out') || str_contains($text, 'waktu habis')) {
            return 'too_big';
        }
        if (in_array($code, ['BREAKDOWN_FAILED', 'OPENCODE_NO_CHANGES', 'REVIEW_FAILED'], true)) {
            return 'unclear';
        }

        return 'transient';
    }

    /** Penjelasan kegagalan dalam bahasa PO. */
    public static function failureExplanation(ExecutionRun $run): string
    {
        return match (self::failureKind($run)) {
            'setup' => AiQuota::isQuotaText((string) $run->summary) || $run->error_code === 'QUOTA_EXHAUSTED'
                ? 'Penyebab: kuota model AI habis. Ganti model aktif di menu AI Providers, atau tunggu kuota pulih.'
                : 'Penyebab: model AI belum dipilih atau sedang bermasalah. Buka menu AI Providers dan pilih model yang tersedia.',
            'too_big' => 'Penyebab: pekerjaannya terlalu besar untuk satu putaran (developer kehabisan waktu atau QA terus menemukan masalah).',
            'unclear' => 'Penyebab: Scrum Master tidak bisa memetakan perintah menjadi tugas yang jelas (kriteria terlalu banyak, terlalu umum, atau tidak ada perubahan kode yang dihasilkan).',
            default => 'Penyebab: gangguan sementara pada AI atau koneksi server.',
        };
    }
}
