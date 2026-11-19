<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Salin profil peran Antigravity dari repo ke folder profil global agy.
 *
 * Jalankan sebagai user yang login ke agy (di VPS: sidik), karena folder
 * tujuannya ada di home user itu.
 */
#[Signature('antigravity:sync-agents {--dir= : Folder tujuan (default config ai.antigravity.agents_dir)}')]
#[Description('Salin profil peran ScrumSpace ke folder agen Antigravity CLI')]
class AntigravitySyncAgents extends Command
{
    public function handle(): int
    {
        $source = resource_path('antigravity/agents');
        $target = rtrim((string) ($this->option('dir') ?: config('ai.antigravity.agents_dir')), '/');

        if (! is_dir($source)) {
            $this->error('Folder sumber profil tidak ada: '.$source);

            return self::FAILURE;
        }

        $files = File::glob($source.'/*.md');
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $dir = $target.'/'.$name;
            File::ensureDirectoryExists($dir, 0755);
            File::copy($file, $dir.'/agent.md');
            $this->line('Disalin: '.$name);
        }

        $this->info(count($files).' profil disalin ke '.$target.'.');

        return self::SUCCESS;
    }
}
