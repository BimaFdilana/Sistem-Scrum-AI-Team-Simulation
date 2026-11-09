<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Cloudflare dan browser menyimpan CSS/JS sampai 4 jam (max-age=14400). Tanpa nomor versi,
 * perubahan tampilan tidak terlihat setelah deploy sampai cache habis.
 */
class AssetVersioningTest extends TestCase
{
    public function test_every_stylesheet_and_script_in_views_carries_a_filemtime_version(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                $isAsset = preg_match('/rel="stylesheet"|<script\\s+[^>]*src=/', $line) === 1;
                $isLocal = str_contains($line, 'asset(') || str_contains($line, 'href="/css') || str_contains($line, 'src="/js');
                if ($isAsset && $isLocal && ! str_contains($line, 'filemtime')) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname()).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'tambahkan ?v={{ filemtime(public_path(...)) }} pada aset lokal');
    }
}
