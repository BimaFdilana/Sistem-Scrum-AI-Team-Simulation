<?php

namespace Tests\Feature;

use App\Support\Workspace\PatchApplier;
use Tests\TestCase;

/**
 * Atomicity PatchApplier (two-phase):
 * 1. Validasi seluruh patch dulu — satu gagal, tidak ada file yang berubah.
 * 2. Gagal di tengah penerapan — semua file dikembalikan ke kondisi semula.
 */
class PatchApplierAtomicTest extends TestCase
{
    private string $dir;

    private PatchApplier $patcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/patchatomic-'.uniqid());
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

    public function test_validation_failure_applies_nothing(): void
    {
        // Patch terakhir tidak valid -> patch pertama (yang valid) pun tidak boleh diterapkan.
        try {
            $this->patcher->apply($this->dir, [
                ['path' => 'a/satu.php', 'action' => 'create', 'content' => '<?php // 1'],
                ['path' => 'a/dua.php', 'action' => 'create', 'content' => '<?php // 2'],
                ['path' => 'a/tiga.php', 'action' => 'meledak', 'content' => '<?php // 3'],
            ]);
            $this->fail('Patch dengan action tidak valid seharusnya ditolak utuh.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('action tidak valid', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/a/satu.php');
        $this->assertFileDoesNotExist($this->dir.'/a/dua.php');
        $this->assertDirectoryDoesNotExist($this->dir.'/a');
    }

    public function test_destructive_content_late_in_batch_rejected_before_any_write(): void
    {
        try {
            $this->patcher->apply($this->dir, [
                ['path' => 'app/Fix.php', 'action' => 'create', 'content' => '<?php // aman'],
                ['path' => 'database/migrations/x.php', 'action' => 'create',
                    'content' => "<?php Schema::dropIfExists('users');"],
            ], false);
            $this->fail('Konten destruktif di akhir batch seharusnya menolak seluruh patch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('anti-destruktif', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/app/Fix.php');
    }

    public function test_rollback_restores_updated_and_created_files_on_mid_apply_failure(): void
    {
        file_put_contents($this->dir.'/keep.txt', 'asli');
        mkdir($this->dir.'/blocked.php'); // tabrakan: File::put ke path ini gagal

        try {
            $this->patcher->apply($this->dir, [
                ['path' => 'keep.txt', 'action' => 'update', 'content' => 'baru'],
                ['path' => 'sub/new.txt', 'action' => 'create', 'content' => 'baru dibuat'],
                ['path' => 'blocked.php', 'action' => 'update', 'content' => 'x'],
            ]);
            $this->fail('Gagal tulis di tengah seharusnya membatalkan seluruh patch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gagal menulis', $e->getMessage());
        }

        $this->assertSame('asli', file_get_contents($this->dir.'/keep.txt'));
        $this->assertFileDoesNotExist($this->dir.'/sub/new.txt');
        $this->assertDirectoryExists($this->dir.'/blocked.php');
    }

    public function test_rollback_restores_deleted_file_on_mid_apply_failure(): void
    {
        file_put_contents($this->dir.'/victim.txt', 'jangan hilang');
        mkdir($this->dir.'/blocked.php');

        try {
            $this->patcher->apply($this->dir, [
                ['path' => 'victim.txt', 'action' => 'delete'],
                ['path' => 'blocked.php', 'action' => 'update', 'content' => 'x'],
            ]);
            $this->fail('Seharusnya gagal di tengah.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }

        $this->assertSame('jangan hilang', file_get_contents($this->dir.'/victim.txt'));
    }

    public function test_successful_batch_returns_applied_paths(): void
    {
        file_put_contents($this->dir.'/old.txt', 'lama');
        file_put_contents($this->dir.'/gone.txt', 'hapus saya');

        $applied = $this->patcher->apply($this->dir, [
            ['path' => 'baru/deep/f.php', 'action' => 'create', 'content' => '<?php // baru'],
            ['path' => 'old.txt', 'action' => 'update', 'content' => 'diperbarui'],
            ['path' => 'gone.txt', 'action' => 'delete'],
        ]);

        $this->assertSame(['baru/deep/f.php', 'old.txt', 'gone.txt'], $applied);
        $this->assertSame('<?php // baru', file_get_contents($this->dir.'/baru/deep/f.php'));
        $this->assertSame('diperbarui', file_get_contents($this->dir.'/old.txt'));
        $this->assertFileDoesNotExist($this->dir.'/gone.txt');
    }
}
