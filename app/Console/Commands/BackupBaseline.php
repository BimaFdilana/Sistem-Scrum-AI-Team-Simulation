<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Support\Workspace\BaselineSnapshot;
use Illuminate\Console\Command;

class BackupBaseline extends Command
{
    protected $signature = 'projects:snapshot {project} {--label=manual}';

    protected $description = 'Buat snapshot (git bundle) baseline project sebelum diubah agent';

    public function handle(BaselineSnapshot $snapshots): int
    {
        $project = Project::query()->find((int) $this->argument('project'));
        if (! $project) {
            $this->error('Project tidak ditemukan.');

            return self::FAILURE;
        }

        $file = $snapshots->backup($project, (string) $this->option('label'));
        if ($file === null) {
            $this->error('Baseline bukan repo git — tidak ada yang bisa di-snapshot.');

            return self::FAILURE;
        }

        $this->info('Snapshot: '.$file.' ('.number_format(filesize($file) / 1024 / 1024, 2).' MB)');

        return self::SUCCESS;
    }
}
