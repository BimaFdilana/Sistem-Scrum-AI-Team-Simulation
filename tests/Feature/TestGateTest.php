<?php

namespace Tests\Feature;

use App\Services\TestGate;
use Tests\TestCase;

class TestGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/test-gate-'.uniqid());
        mkdir($this->dir, 0700, true);
    }

    public function test_valid_php_and_no_test_command_passes(): void
    {
        file_put_contents($this->dir.'/ok.php', '<?php echo 1;');

        $this->assertNull((new TestGate)->run($this->dir, ['ok.php'], null));
        $this->assertNull((new TestGate)->run($this->dir, ['ok.php'], '  '));
    }

    public function test_invalid_php_fails_gate(): void
    {
        file_put_contents($this->dir.'/bad.php', '<?php this is not valid {{{');

        $error = (new TestGate)->run($this->dir, ['bad.php'], null);

        $this->assertNotNull($error);
        $this->assertStringContainsString('bad.php', $error);
    }

    public function test_non_php_files_are_not_linted(): void
    {
        file_put_contents($this->dir.'/style.css', '{{{ not php');

        $this->assertNull((new TestGate)->run($this->dir, ['style.css'], null));
    }

    public function test_failing_test_command_fails_gate_and_passing_one_passes(): void
    {
        file_put_contents($this->dir.'/ok.php', '<?php echo 1;');

        $error = (new TestGate)->run($this->dir, ['ok.php'], 'false');
        $this->assertNotNull($error);
        $this->assertStringContainsString('false', $error);

        $this->assertNull((new TestGate)->run($this->dir, ['ok.php'], 'true'));
    }
}
