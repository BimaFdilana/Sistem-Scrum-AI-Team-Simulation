<?php

namespace Tests\Feature;

use App\Services\HermesTaskRunner;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HermesTaskRunnerTest extends TestCase
{
    private string $worktree;

    private string $fakeBin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worktree = storage_path('framework/testing/hermes-runner-'.uniqid());
        mkdir($this->worktree, 0700, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        file_put_contents($this->worktree.'/hello.txt', "hello\n");
        $this->git(['add', '.']);
        $this->git(['commit', '-qm', 'init']);

        // Biner Hermes palsu: ditulis ulang per mode (tanpa env, karena
        // Symfony Process tidak mewarisi putenv), mengabaikan argumen,
        // bekerja di $PWD seperti Hermes asli (cwd = worktree), lalu
        // mencetak ringkasan. Baris ditulis tanpa indentasi agar shebang
        // tetap di byte pertama file.
        $this->fakeBin = storage_path('framework/testing/hermes-fake-'.uniqid().'.sh');

        config([
            'ai.cliproxy.api_key' => 'test-key',
            'ai.hermes.binary' => $this->fakeBin,
            'ai.hermes.model' => 'glm-5.3',
            'ai.hermes.provider' => 'custom',
            'ai.hermes.base_url' => 'http://127.0.0.1:8317/v1',
            'ai.hermes.max_turns' => 50,
            'ai.hermes.timeout' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        @unlink($this->fakeBin);
        parent::tearDown();
    }

    private function git(array $args): void
    {
        $process = new Process(array_merge(['git', '-C', $this->worktree], $args), null, null, null, 60);
        $process->mustRun();
    }

    private function runTask(string $mode = 'ok', bool $allowDelete = true): array
    {
        $body = match ($mode) {
            'badphp' => 'printf \'<?php echo "oops\n\' > bad.php',
            'empty' => ':',
            'delete' => 'rm hello.txt',
            default => "echo \"changed\" >> hello.txt\nprintf '<?php echo \"ok\";\\n' > new.php",
        };
        file_put_contents($this->fakeBin, "#!/bin/sh\n".$body."\necho \"Ringkasan: tugas selesai\"\n");
        chmod($this->fakeBin, 0755);

        return app(HermesTaskRunner::class)->run('backend-engineer', $this->worktree, 'Task: ubah hello', $allowDelete, []);
    }

    public function test_returns_changed_files_and_summary(): void
    {
        $result = $this->runTask('ok');

        $this->assertContains('hello.txt', $result['files']);
        $this->assertContains('new.php', $result['files']);
        $this->assertStringContainsString('Ringkasan: tugas selesai', $result['summary']);
    }

    public function test_rejects_invalid_php(): void
    {
        $this->expectExceptionMessage('gagal lint');
        $this->runTask('badphp');
    }

    public function test_rejects_no_changes(): void
    {
        $this->expectExceptionMessage('tidak mengubah file apa pun');
        $this->runTask('empty');
    }

    public function test_rejects_delete_when_not_allowed(): void
    {
        $this->expectExceptionMessage('menghapus file');
        $this->runTask('delete', false);
    }

    public function test_allows_delete_when_allowed(): void
    {
        $result = $this->runTask('delete', true);

        $this->assertContains('hello.txt', $result['files']);
    }
}
