<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Support\Workspace\BaselineSnapshot;
use Illuminate\Console\Command;

class RestoreBaseline extends Command
{
    protected $signature = 'projects:restore {project} {bundle} {--force}';

    protected $description = 'Pulihkan baseline project dari snapshot (git bundle)';

    public function handle(BaselineSnapshot $snapshots): int
    {
        $project = Project::query()->find((int) $this->argument('project'));
        if (! $project) {
            $this->error('Project tidak ditemukan.');

            return self::FAILURE;
        }

        $bundle = (string) $this->argument('bundle');
        if (! is_file($bundle)) {
            $this->error('Bundle tidak ditemukan: '.$bundle);

            return self::FAILURE;
        }

        $this->warn('Ini akan MENGGANTI isi baseline '.$project->workspace_path);
        if (! $this->option('force') && ! $this->confirm('Lanjutkan?', false)) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        $snapshots->restore($project, $bundle);
        $this->info('Baseline dipulihkan dari '.$bundle);

        return self::SUCCESS;
    }
}
