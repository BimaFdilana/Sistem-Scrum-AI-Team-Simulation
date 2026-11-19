<?php

namespace Tests\Feature;

use App\Support\Demo\DemoServerManager;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoServerLaunchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/demo-launch-'.uniqid());
        File::ensureDirectoryExists($this->root.'/database/seeders');
        File::ensureDirectoryExists($this->root.'/public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_demo_seeders_are_found_unless_database_seeder_already_calls_them(): void
    {
        file_put_contents($this->root.'/database/seeders/DatabaseSeeder.php', '<?php $this->call([UserSeeder::class, DemoPaguSeeder::class]);');
        foreach (['DemoUsulanSeeder', 'DemoPaguSeeder', 'UserSeeder', 'DemoHelper'] as $name) {
            file_put_contents($this->root.'/database/seeders/'.$name.'.php', '<?php');
        }

        $this->assertSame(['Database\\Seeders\\DemoUsulanSeeder'], DemoServerManager::demoSeeders($this->root));
        $this->assertSame([], DemoServerManager::demoSeeders($this->root.'/tidak-ada'));
    }

    public function test_appended_demo_log_is_trimmed_to_its_latest_part(): void
    {
        $log = $this->root.'/demo.log';
        file_put_contents($log, str_repeat("baris lama\n", 2000)."baris terbaru\n");

        DemoServerManager::trimLog($log, 10000, 500);

        $content = (string) file_get_contents($log);
        $this->assertLessThanOrEqual(500, strlen($content));
        $this->assertStringEndsWith("baris terbaru\n", $content);
        $this->assertStringStartsWith('baris', $content);
    }

    public function test_demo_behind_the_public_host_listens_on_loopback_only(): void
    {
        config(['demo.public_host' => 'https://demo.example.test']);
        $this->assertSame('127.0.0.1', DemoServerManager::bindHost());

        config(['demo.public_host' => '']);
        $this->assertSame('0.0.0.0', DemoServerManager::bindHost());
    }

    public function test_launcher_returns_at_once_and_server_keeps_no_inherited_descriptors(): void
    {
        file_put_contents($this->root.'/public/index.php', '<?php echo "ok";');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $log = $this->root.'/demo.log';

        // Pemanggil memegang pipe stdout/stderr (seperti perintah artisan di terminal).
        $launcher = new Process(['bash', '-c', DemoServerManager::launchCommand('', PHP_BINARY, '127.0.0.1', $port, $this->root.'/public', $log)]);
        $launcher->setTimeout(15);
        $started = microtime(true);
        $launcher->run();
        $pid = (int) trim($launcher->getOutput());

        try {
            $this->assertGreaterThan(0, $pid);
            $this->assertLessThan(10, microtime(true) - $started);
            usleep(500_000);
            $this->assertTrue(posix_kill($pid, 0), 'Server demo harus tetap hidup setelah peluncur selesai.');

            $descriptors = array_map('intval', array_diff(scandir('/proc/'.$pid.'/fd') ?: [], ['.', '..']));
            foreach ($descriptors as $fd) {
                if ($fd <= 2) {
                    continue;
                }
                $target = (string) @readlink('/proc/'.$pid.'/fd/'.$fd);
                $this->assertStringNotContainsString('pipe:', $target, 'Server demo tidak boleh mewarisi pipe pemanggil.');
            }
            $this->assertStringContainsString('127.0.0.1:'.$port, (string) file_get_contents('/proc/'.$pid.'/cmdline'));
        } finally {
            if ($pid > 0) {
                posix_kill($pid, 9);
            }
        }
    }
}
