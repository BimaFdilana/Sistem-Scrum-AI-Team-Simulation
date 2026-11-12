<?php

namespace App\Support\Chat;

use App\Models\ChatCommand;
use App\Models\ExecutionRun;
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
        $summary = trim((string) Str::of((string) $run->summary)->before("\nMode langsung"));

        return match ($run->status) {
            'EXECUTING' => "Perintah Anda saya pecah menjadi tugas untuk tim dan langsung dikerjakan:\n".$tasks
                ."\n\nQA akan memeriksa hasilnya setelah semua tugas selesai. Saya kabari lagi di sini.",
            'COMPLETED' => "Perintah selesai dikerjakan dan lolos pemeriksaan QA.\n".$tasks
                .($summary !== '' ? "\n\nCatatan QA: ".Str::limit($summary, 400) : '')
                ."\n\nCoba hasilnya di demo (Pengaturan & Demo project), lalu pilih **Terima** atau **Tandai Gagal** di bawah.",
            'NEEDS_PO' => 'Pekerjaan berhenti dan butuh keputusan Anda: '.Str::limit($summary !== '' ? $summary : 'lihat detail proses.', 500)
                ."\n\nKlik **Lanjutkan** untuk mencoba lagi, atau **Tandai Gagal**.",
            default => 'Perintah gagal dikerjakan: '.Str::limit($summary !== '' ? $summary : (string) $run->error_code, 500)
                ."\n\nKode utama tidak berubah. Klik **Jalankan ulang** untuk mencoba lagi, atau ubah perintahnya dengan mengirim perintah baru.",
        };
    }
}
