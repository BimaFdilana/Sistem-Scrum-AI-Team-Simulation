<?php

namespace App\Console\Commands;

use App\Models\StoryEstimationRun;
use App\Services\StoryEstimationService;
use Illuminate\Console\Command;

/**
 * Selamatkan run estimasi yang tertahan di REPORT_PENDING.
 *
 * Penyebab nyata: laporan ringkas gagal disimpan karena SQLite "database is
 * locked", sehingga run tidak pernah pindah ke COMPLETED walau poin estimasi
 * sudah tersedia. Perintah ini menutup run seperti itu agar PO tidak terjebak
 * di status yang terlihat "berhenti".
 */
class RecoverStuckEstimations extends Command
{
    protected $signature = 'estimations:recover {--minutes=10 : Umur minimum REPORT_PENDING} {--dry-run}';

    protected $description = 'Tandai run estimasi REPORT_PENDING yang macet sebagai COMPLETED';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $stuck = StoryEstimationRun::query()
            ->where('status', 'REPORT_PENDING')
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('Tidak ada run REPORT_PENDING yang macet.');

            return self::SUCCESS;
        }

        foreach ($stuck as $run) {
            $this->line('#'.$run->id.' story='.$run->story_id.' updated='.$run->updated_at);

            if (! $this->option('dry-run')) {
                // Poin konsensus ikut disimpan ke story (dulu hanya status yang diubah).
                $this->line('  -> '.app(StoryEstimationService::class)->completeWithFallback($run, 'dipulihkan otomatis'));
            }
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '').$stuck->count().' run dipulihkan.');

        return self::SUCCESS;
    }
}
