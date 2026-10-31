<?php

namespace Tests\Feature;

use App\Services\AgentConfiguration;
use App\Services\CliProxyApiClient;
use App\Services\HermesTaskRunner;
use RuntimeException;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HermesTaskRunnerTest extends TestCase
{
    private string $worktree;

    private string $fakeBin;

    /** @var string[] */
    private array $argsFiles = [];

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

        // Aturan ketat: model HANYA dari snapshot AgentConfiguration
        // terverifikasi yang masih ada di daftar model gateway. Mock
        // default: role sudah memilih glm-5.3 yang terverifikasi, dan
        // daftar gateway memuatnya — sehingga tes-tes lama yang tidak
        // menguji pemilihan model tetap berjalan. Tes pemilihan model
        // mengikat ulang mock ini sesuai kasusnya (byDefault = boleh
        // tidak terpakai bila diganti mock lain di tes tersebut).
        $this->mock(CliProxyApiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('models')->andReturn([
                ['id' => 'glm-5.3', 'owned_by' => 'glm'],
                ['id' => 'menu-model-be', 'owned_by' => 'test'],
                ['id' => 'menu-model-fe', 'owned_by' => 'test'],
            ])->byDefault();
        });
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(['status' => 'verified', 'model' => 'glm-5.3'])->byDefault();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->worktree);
        @unlink($this->fakeBin);
        foreach ($this->argsFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function git(array $args): void
    {
        $process = new Process(array_merge(['git', '-C', $this->worktree], $args), null, null, null, 60);
        $process->mustRun();
    }

    private function runTask(string $mode = 'ok', bool $allowDelete = true, string $slug = 'backend-engineer', ?string $argsFile = null): array
    {
        $body = match ($mode) {
            'badphp' => 'printf \'<?php echo "oops\n\' > bad.php',
            'empty' => ':',
            'delete' => 'rm hello.txt',
            default => "echo \"changed\" >> hello.txt\nprintf '<?php echo \"ok\";\\n' > new.php",
        };
        $script = "#!/bin/sh\n";
        if ($argsFile !== null) {
            $script .= "printf '%s\\n' \"\$@\" > ".escapeshellarg($argsFile)."\n";
        }
        $script .= $body."\necho \"Ringkasan: tugas selesai\"\n";
        file_put_contents($this->fakeBin, $script);
        chmod($this->fakeBin, 0755);

        return app(HermesTaskRunner::class)->run($slug, $this->worktree, 'Task: ubah hello', $allowDelete, []);
    }

    private function freshArgsFile(): string
    {
        $file = storage_path('framework/testing/hermes-args-'.uniqid().'.txt');
        $this->argsFiles[] = $file;

        return $file;
    }

    private function modelFromArgs(string $argsFile): ?string
    {
        $lines = file($argsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if ($line === '--model' && isset($lines[$i + 1])) {
                return $lines[$i + 1];
            }
        }

        return null;
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

    public function test_uses_menu_model_per_role(): void
    {
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->with('backend-developer')->andReturn(['status' => 'verified', 'model' => 'menu-model-be']);
            $mock->shouldReceive('current')->with('frontend-developer')->andReturn(['status' => 'verified', 'model' => 'menu-model-fe']);
        });
        $argsFile = $this->freshArgsFile();

        $this->runTask('ok', true, 'backend-developer', $argsFile);
        $this->assertSame('menu-model-be', $this->modelFromArgs($argsFile));

        $this->runTask('ok', true, 'frontend-developer', $argsFile);
        $this->assertSame('menu-model-fe', $this->modelFromArgs($argsFile));
    }

    public function test_rejects_unselected_role_without_calling_hermes(): void
    {
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturnNull();
        });
        $argsFile = $this->freshArgsFile();

        try {
            $this->runTask('ok', true, 'backend-developer', $argsFile);
            $this->fail('Exception tidak dilempar untuk role yang belum memilih.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belum dipilih di halaman Konfigurasi Agent', $exception->getMessage());
        }
        // Biner Hermes palsu tidak pernah dijalankan: tidak ada file
        // argumen yang ditulis, dan worktree tidak berubah.
        $this->assertFileDoesNotExist($argsFile);
        $this->assertSame("hello\n", file_get_contents($this->worktree.'/hello.txt'));
    }

    public function test_rejects_stale_menu_model(): void
    {
        $this->mock(CliProxyApiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('models')->andReturn([['id' => 'glm-5.3', 'owned_by' => 'glm']]);
        });
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(['status' => 'verified', 'model' => 'stale-model']);
        });
        $argsFile = $this->freshArgsFile();

        try {
            $this->runTask('ok', true, 'backend-developer', $argsFile);
            $this->fail('Exception tidak dilempar untuk model yang sudah tidak tersedia.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tidak ditemukan di daftar model gateway', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($argsFile);
    }

    public function test_rejects_unverified_menu_model(): void
    {
        $this->mock(AgentConfiguration::class, function (MockInterface $mock) {
            $mock->shouldReceive('current')->andReturn(['status' => 'pending', 'model' => 'glm-5.3']);
        });
        $argsFile = $this->freshArgsFile();

        try {
            $this->runTask('ok', true, 'backend-developer', $argsFile);
            $this->fail('Exception tidak dilempar untuk model yang belum terverifikasi.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belum terverifikasi di halaman Konfigurasi Agent', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($argsFile);
    }
}
