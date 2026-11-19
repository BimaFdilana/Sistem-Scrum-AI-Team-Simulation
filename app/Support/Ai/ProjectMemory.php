<?php

namespace App\Support\Ai;

use App\Models\ExecutionFinding;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Memori jangka panjang proyek untuk agen Antigravity.
 *
 * Diringkas dari data ScrumSpace (keputusan PO, temuan QA) dan riwayat git,
 * bukan dari log mentah. Hasilnya dipasang ke worktree task sebagai rules
 * agy (`.agents/rules/memori-proyek.md`, trigger always_on) yang dikecualikan
 * dari git, sehingga tidak pernah ikut commit dan tidak menghalangi checkout.
 */
class ProjectMemory
{
    /** Batas ukuran rules agy per file adalah 24 KB; sisakan ruang aman. */
    public const MAX_BYTES = 20000;

    public const RULE_PATH = '.agents/rules/memori-proyek.md';

    public function build(Project $project): string
    {
        $sections = array_filter([
            '# Memori proyek '.$project->name,
            'Ringkasan otomatis dari keputusan Product Owner, temuan QA, dan riwayat kode. Pakai sebagai konteks: jangan ulangi kesalahan yang sudah tercatat, dan jangan merusak fitur yang sudah diterima.',
            $this->lessonsSection($project),
            $this->openFindingsSection($project),
            $this->acceptedSection($project),
            $this->rejectedSection($project),
            $this->gitSection($project),
        ]);

        return $this->limit(implode("\n\n", $sections));
    }

    /** Simpan salinan memori di storage ScrumSpace (untuk dilihat PO). */
    public function snapshot(Project $project): string
    {
        $path = storage_path('app/private/agy-memory/project-'.$project->id.'.md');
        File::ensureDirectoryExists(dirname($path), 0775);
        File::put($path, $this->build($project));

        return $path;
    }

    /** Pasang memori ke worktree task sebagai rules agy, di luar pelacakan git. */
    public function installInto(string $worktree, Project $project): void
    {
        $content = "---\ntrigger: always_on\ndescription: Memori proyek dari keputusan PO, temuan QA, dan riwayat kode.\n---\n\n".$this->build($project)."\n";
        $path = rtrim($worktree, '/').'/'.self::RULE_PATH;
        File::ensureDirectoryExists(dirname($path), 0775);
        File::put($path, $content);
        $this->excludeFromGit($worktree);
    }

    private function acceptedSection(Project $project): string
    {
        $stories = Story::query()->where('project_id', $project->id)
            ->where('acceptance_status', 'BERHASIL')->latest('accepted_at')->limit(35)->get();
        if ($stories->isEmpty()) {
            return '';
        }

        return "## Fitur yang sudah diterima PO\n".$stories
            ->map(fn (Story $story) => '- '.$story->display_id.': '.$this->line($story->effectiveTitle()))
            ->implode("\n");
    }

    private function rejectedSection(Project $project): string
    {
        $stories = Story::query()->where('project_id', $project->id)
            ->where('acceptance_status', 'GAGAL')->latest('updated_at')->limit(12)->get();
        if ($stories->isEmpty()) {
            return '';
        }

        return "## Story yang ditolak PO (hasilnya tidak diterima)\n".$stories
            ->map(fn (Story $story) => '- '.$story->display_id.': '.$this->line($story->effectiveTitle()))
            ->implode("\n");
    }

    private function lessonsSection(Project $project): string
    {
        $findings = $this->findings($project)->where('status', 'RESOLVED')
            ->orderByRaw("case severity when 'high' then 0 else 1 end")->latest('id')->limit(120)->get()
            ->unique(fn (ExecutionFinding $finding) => Str::lower(Str::limit((string) $finding->issue, 60, '')))
            ->take(30);
        if ($findings->isEmpty()) {
            return '';
        }

        return "## Pelajaran dari temuan QA yang sudah diperbaiki\n".$findings
            ->map(fn (ExecutionFinding $finding) => '- '.($finding->file ? '`'.$finding->file.'`: ' : '').$this->line((string) $finding->issue))
            ->implode("\n");
    }

    private function openFindingsSection(Project $project): string
    {
        $findings = $this->findings($project)->where('status', 'OPEN')->latest('id')->limit(10)->get();
        if ($findings->isEmpty()) {
            return '';
        }

        return "## Temuan QA yang masih terbuka\n".$findings
            ->map(fn (ExecutionFinding $finding) => '- '.($finding->file ? '`'.$finding->file.'`: ' : '').$this->line((string) $finding->issue))
            ->implode("\n");
    }

    private function gitSection(Project $project): string
    {
        $path = (string) $project->workspace_path;
        if ($path === '' || ! is_dir($path.'/.git')) {
            return '';
        }

        try {
            $process = new Process(['git', 'log', '--no-merges', '--format=- %s', '-25', 'main'], $path, null, null, 20);
            $process->run();
            $log = trim($process->getOutput());
        } catch (Throwable) {
            return '';
        }

        return $log === '' ? '' : "## Perubahan kode terakhir (branch main)\n".$log;
    }

    /** @return Builder<ExecutionFinding> */
    private function findings(Project $project)
    {
        return ExecutionFinding::query()->whereHas('run.story', fn ($query) => $query->where('project_id', $project->id));
    }

    private function line(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $text)), 160);
    }

    private function limit(string $markdown): string
    {
        if (strlen($markdown) <= self::MAX_BYTES) {
            return $markdown;
        }

        return mb_strcut($markdown, 0, self::MAX_BYTES)."\n\n(dipotong agar tetap di bawah batas ukuran rules)";
    }

    /** Daftarkan .agents/ ke info/exclude repo agar tidak ikut status/commit. */
    private function excludeFromGit(string $worktree): void
    {
        try {
            $process = new Process(['git', 'rev-parse', '--git-common-dir'], $worktree, null, null, 20);
            $process->run();
            $common = trim($process->getOutput());
            if ($common === '') {
                return;
            }
            $common = str_starts_with($common, '/') ? $common : rtrim($worktree, '/').'/'.$common;
            $exclude = $common.'/info/exclude';
            File::ensureDirectoryExists(dirname($exclude));
            $current = is_file($exclude) ? (string) file_get_contents($exclude) : '';
            if (! preg_match('#^/?\.agents/?$#m', $current)) {
                File::append($exclude, (str_ends_with($current, "\n") || $current === '' ? '' : "\n")."/.agents/\n");
            }
        } catch (Throwable) {
            // Gagal mengecualikan tidak menghentikan task; dirtyFiles tetap
            // mengabaikan .agents/ (lihat pemanggil).
        }
    }
}
