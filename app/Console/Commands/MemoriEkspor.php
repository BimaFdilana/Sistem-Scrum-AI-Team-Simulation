<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Support\Ai\ProjectMemory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('memori:ekspor {project? : ID project (kosong = semua project)} {--tampilkan : Cetak isi memori ke layar}')]
#[Description('Susun memori proyek untuk agen Antigravity dari keputusan PO, temuan QA, dan riwayat git')]
class MemoriEkspor extends Command
{
    public function handle(ProjectMemory $memory): int
    {
        $projects = $this->argument('project')
            ? Project::query()->whereKey($this->argument('project'))->get()
            : Project::query()->get();

        if ($projects->isEmpty()) {
            $this->warn('Tidak ada project yang cocok.');

            return self::FAILURE;
        }

        foreach ($projects as $project) {
            $path = $memory->snapshot($project);
            $this->info('Memori '.$project->name.' disimpan: '.$path.' ('.number_format((int) filesize($path)).' byte)');
            if ($this->option('tampilkan')) {
                $this->line((string) file_get_contents($path));
            }
        }

        return self::SUCCESS;
    }
}
