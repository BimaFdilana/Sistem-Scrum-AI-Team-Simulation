<?php

namespace Tests\Feature;

use App\Jobs\ExecuteExecutionTaskJob;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RestartKillTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    private function signaled(int $signal): ProcessSignaledException
    {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getTermSignal')->andReturn($signal);

        return new ProcessSignaledException($process);
    }

    public function test_sigterm_and_sigkill_are_treated_as_a_service_restart(): void
    {
        $this->assertTrue(ExecuteExecutionTaskJob::wasKilledByRestart($this->signaled(15)));
        $this->assertTrue(ExecuteExecutionTaskJob::wasKilledByRestart($this->signaled(9)));
    }

    public function test_other_signals_and_ordinary_errors_still_fail_the_run(): void
    {
        $this->assertFalse(ExecuteExecutionTaskJob::wasKilledByRestart($this->signaled(11)));
        $this->assertFalse(ExecuteExecutionTaskJob::wasKilledByRestart(new RuntimeException('agen gagal')));
    }
}
