<?php

namespace Tests\Feature;

use App\Services\AiGateway;
use Tests\TestCase;

class AiGatewayTest extends TestCase
{
    public function test_transient_codes_are_retryable_and_permanent_codes_are_not(): void
    {
        foreach (['RATE_LIMITED', 'UPSTREAM_OVERLOADED', 'TIMEOUT', 'GATEWAY_UNREACHABLE', 'EMPTY_RESPONSE'] as $code) {
            $this->assertTrue(AiGateway::isTransient($code), $code);
        }
        foreach (['AUTH_FAILED', 'BAD_REQUEST', 'MODEL_NOT_FOUND', 'PROVIDER_AUTH_INCOMPLETE', 'DISABLED'] as $code) {
            $this->assertFalse(AiGateway::isTransient($code), $code);
        }
    }

    public function test_release_delay_grows_and_respects_retry_after_with_cap(): void
    {
        $this->assertSame(60, AiGateway::releaseDelay(1));
        $this->assertSame(120, AiGateway::releaseDelay(2));
        $this->assertSame(120, AiGateway::releaseDelay(1, 120));
        $this->assertSame(600, AiGateway::releaseDelay(1, 9999));
        $this->assertSame(600, AiGateway::releaseDelay(10));
    }
}
