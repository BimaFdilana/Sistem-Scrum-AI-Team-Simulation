<?php

namespace Tests\Feature;

use App\Support\Demo\DemoServerManager;
use Tests\TestCase;

class DemoAssetsStaleTest extends TestCase
{
    private function project(): string
    {
        $root = storage_path('framework/testing/assets-'.uniqid());
        mkdir($root.'/public/build', 0700, true);
        mkdir($root.'/resources/views', 0700, true);
        file_put_contents($root.'/public/build/manifest.json', '{}');
        file_put_contents($root.'/resources/views/a.blade.php', 'lama');
        touch($root.'/public/build/manifest.json', time());
        touch($root.'/resources/views/a.blade.php', time() - 3600);

        return $root;
    }

    public function test_assets_are_fresh_when_nothing_is_newer_than_the_manifest(): void
    {
        $this->assertFalse(DemoServerManager::assetsAreStale($this->project()));
    }

    public function test_new_or_changed_view_makes_assets_stale_because_tailwind_scans_views(): void
    {
        $root = $this->project();
        file_put_contents($root.'/resources/views/baru.blade.php', 'kelas baru');
        touch($root.'/resources/views/baru.blade.php', time() + 60);

        $this->assertTrue(DemoServerManager::assetsAreStale($root));
    }

    public function test_changed_tailwind_config_makes_assets_stale(): void
    {
        $root = $this->project();
        file_put_contents($root.'/tailwind.config.js', 'x');
        touch($root.'/tailwind.config.js', time() + 60);

        $this->assertTrue(DemoServerManager::assetsAreStale($root));
    }

    public function test_missing_manifest_counts_as_stale(): void
    {
        $this->assertTrue(DemoServerManager::assetsAreStale(storage_path('framework/testing/tidak-ada-'.uniqid())));
    }
}
