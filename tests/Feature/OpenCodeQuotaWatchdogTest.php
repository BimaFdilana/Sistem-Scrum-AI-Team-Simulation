<?php

namespace Tests\Feature;

use App\Services\CliProxyApiClient;
use App\Support\Ai\AiQuota;
use App\Support\Execution\OpenCodeRunner;
use Mockery;
use Tests\TestCase;

/** Run #39: OpenCode mengulang 429 diam-diam sampai batas 25 menit habis. */
class OpenCodeQuotaWatchdogTest extends TestCase
{
    private function fakeBinary(): string
    {
        $path = sys_get_temp_dir().'/fake-opencode-'.uniqid();
        file_put_contents($path, "#!/bin/sh\nsleep 60\n");
        chmod($path, 0755);

        return $path;
    }

    public function test_hung_agent_is_stopped_early_when_model_keeps_cooling_down(): void
    {
        config(['ai.opencode.binary' => $binary = $this->fakeBinary(), 'ai.opencode.quota_probe_seconds' => 1, 'ai.opencode.follow_active_model' => false, 'ai.opencode.model' => 'scrumgateway/gemini-3.1-flash-lite']);
        $gateway = Mockery::mock(CliProxyApiClient::class);
        $gateway->shouldReceive('modelCooldown')->andReturn(['retry_at' => now()->addMinute()->toIso8601String(), 'accounts' => 1, 'level' => 5]);
        $gateway->shouldIgnoreMissing();
        $this->app->instance(CliProxyApiClient::class, $gateway);

        $started = microtime(true);
        $result = app(OpenCodeRunner::class)->run(sys_get_temp_dir(), 'tes', 50, 'scrumgateway/gemini-3.1-flash-lite');
        @unlink($binary);

        $this->assertFalse($result['ok']);
        $this->assertLessThan(20, microtime(true) - $started);
        $this->assertTrue(AiQuota::isQuotaText($result['output'])); // pemanggil menjeda, bukan menggagalkan
    }

    public function test_agent_is_left_alone_while_model_is_available(): void
    {
        $binary = sys_get_temp_dir().'/fake-opencode-ok-'.uniqid();
        file_put_contents($binary, "#!/bin/sh\nsleep 3\necho selesai\n");
        chmod($binary, 0755);
        config(['ai.opencode.binary' => $binary, 'ai.opencode.quota_probe_seconds' => 1, 'ai.opencode.follow_active_model' => false]);
        $gateway = Mockery::mock(CliProxyApiClient::class);
        $gateway->shouldReceive('modelCooldown')->andReturn(null);
        $gateway->shouldIgnoreMissing();
        $this->app->instance(CliProxyApiClient::class, $gateway);

        $result = app(OpenCodeRunner::class)->run(sys_get_temp_dir(), 'tes', 50, 'scrumgateway/gemini-3.1-flash-lite');
        @unlink($binary);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('selesai', $result['output']);
    }
}
