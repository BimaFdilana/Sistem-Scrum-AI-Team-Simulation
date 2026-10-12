<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ProjectDownloadController extends Controller
{
    /** Unduh workspace project sebagai ZIP (tanpa vendor/.git/.env). */
    public function download(Project $project): BinaryFileResponse
    {
        $root = $project->workspace_path;
        if (! $root || ! is_dir($root)) {
            abort(422, 'Baseline project belum siap.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'scrumspace-').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat arsip ZIP.');
        }

        $excluded = ['vendor', 'node_modules', '.git', '.env'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $first = explode('/', str_replace('\\', '/', $relative))[0];
            if (in_array($first, $excluded, true) || in_array($relative, $excluded, true)) {
                continue;
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($file->getPathname(), $relative);
            }
        }
        $zip->close();

        return response()->download($zipPath, Str::slug($project->name).'-'.now()->format('Ymd-His').'.zip')
            ->deleteFileAfterSend(true);
    }
}
