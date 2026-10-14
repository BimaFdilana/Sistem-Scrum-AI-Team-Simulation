<?php

namespace Tests\Feature;

use App\Support\Workspace\PatchApplier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guardrail keamanan PatchApplier:
 * 1. Pola destruktif (drop/truncate/hapus massal) ditolak pada perintah perbaikan.
 * 2. Symlink escape: patch via symlink yang keluar worktree ditolak.
 * 3. Segmen `.git` nested (bukan hanya segmen pertama) ditolak.
 */
class PatchApplierSecurityTest extends TestCase
{
    private string $dir;

    private PatchApplier $patcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/patchsec-'.uniqid());
        mkdir($this->dir, 0700, true);
        $this->patcher = new PatchApplier;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    private function removeDir(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeDir($path.'/'.$item);
        }
        @rmdir($path);
    }

    #[DataProvider('destructiveContents')]
    public function test_repair_order_rejects_destructive_content(string $content): void
    {
        try {
            // $allowDelete = false menandai konteks perintah perbaikan.
            $this->patcher->apply($this->dir, [
                ['path' => 'database/migrations/2026_01_01_000000_x.php', 'action' => 'create', 'content' => $content],
            ], false);
            $this->fail('Konten destruktif seharusnya ditolak pada perintah perbaikan.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('anti-destruktif', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/database/migrations/2026_01_01_000000_x.php');
    }

    public static function destructiveContents(): array
    {
        return [
            'dropIfExists' => ["<?php Schema::dropIfExists('users');"],
            'Schema::drop' => ["<?php Schema::drop('users');"],
            'truncate' => ["<?php DB::table('logs')->truncate();"],
            'model truncate' => ['<?php OldRecord::truncate();'],
            'mass delete DB::table tanpa where' => ["<?php DB::table('logs')->delete();"],
            'mass delete query() tanpa where' => ['<?php User::query()->delete();'],
            'multiline chain' => ["<?php DB::table('logs')\n    ->delete();"],
        ];
    }

    public function test_normal_execution_still_allows_migrations(): void
    {
        // Eksekusi normal ($allowDelete = true) tidak dipindai — migrasi sah tetap jalan.
        $applied = $this->patcher->apply($this->dir, [
            ['path' => 'database/migrations/2026_01_01_000000_x.php', 'action' => 'create',
                'content' => "<?php Schema::dropIfExists('users');"],
        ]);
        $this->assertSame(['database/migrations/2026_01_01_000000_x.php'], $applied);
    }

    #[DataProvider('legitimateContents')]
    public function test_repair_order_allows_legitimate_deletes(string $content): void
    {
        // Hapus satu record / hapus ber-filter = sah, jangan false positive.
        $applied = $this->patcher->apply($this->dir, [
            ['path' => 'app/Http/Controllers/Fix.php', 'action' => 'create', 'content' => $content],
        ], false);
        $this->assertSame(['app/Http/Controllers/Fix.php'], $applied);
    }

    public static function legitimateContents(): array
    {
        return [
            'single model delete' => ['<?php $user->delete();'],
            'filtered delete' => ["<?php User::where('id', 1)->delete();"],
            'filtered DB delete' => ["<?php DB::table('logs')->where('old', true)->delete();"],
            'plain controller' => ['<?php return view("ok");'],
        ];
    }

    public function test_symlink_escape_is_rejected(): void
    {
        $outside = storage_path('framework/testing/patchsec-outside-'.uniqid());
        mkdir($outside, 0700, true);
        $this->removeDir($outside); // pastikan bersih, buat ulang di bawah
        mkdir($outside, 0700, true);
        symlink($outside, $this->dir.'/link');

        try {
            try {
                $this->patcher->apply($this->dir, [
                    ['path' => 'link/evil.php', 'action' => 'create', 'content' => '<?php // evil'],
                ]);
                $this->fail('Patch via symlink keluar worktree seharusnya ditolak.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
            }
            $this->assertFileDoesNotExist($outside.'/evil.php');
        } finally {
            $this->removeDir($outside);
        }
    }

    public function test_symlink_escape_on_delete_is_rejected(): void
    {
        $outside = storage_path('framework/testing/patchsec-outside-'.uniqid());
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/victim.txt', 'jangan hapus');
        symlink($outside, $this->dir.'/link');

        try {
            try {
                $this->patcher->apply($this->dir, [
                    ['path' => 'link/victim.txt', 'action' => 'delete'],
                ]);
                $this->fail('Delete via symlink keluar worktree seharusnya ditolak.');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
            $this->assertFileExists($outside.'/victim.txt');
        } finally {
            $this->removeDir($outside);
        }
    }

    #[DataProvider('nestedGitPaths')]
    public function test_nested_git_segment_is_rejected(string $bad): void
    {
        try {
            $this->patcher->apply($this->dir, [
                ['path' => $bad, 'action' => 'create', 'content' => 'x'],
            ]);
            $this->fail("Path {$bad} seharusnya ditolak.");
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
    }

    public static function nestedGitPaths(): array
    {
        return [
            'root .git' => ['.git/config'],
            'nested .git' => ['vendor/.git/config'],
            'deep nested .git' => ['a/b/.git/hooks/pre-commit'],
            'case-insensitive' => ['sub/.GIT/index'],
        ];
    }

    public function test_legitimate_paths_still_work(): void
    {
        $applied = $this->patcher->apply($this->dir, [
            ['path' => 'app/Http/X.php', 'action' => 'create', 'content' => '<?php // ok'],
            ['path' => 'database/migrations/ok.php', 'action' => 'create', 'content' => '<?php // ok'],
            // 'git' sebagai substring nama file biasa tetap boleh
            ['path' => 'app/legit.php', 'action' => 'create', 'content' => '<?php // ok'],
        ]);
        $this->assertCount(3, $applied);
        $this->assertFileExists($this->dir.'/app/Http/X.php');
    }
}
