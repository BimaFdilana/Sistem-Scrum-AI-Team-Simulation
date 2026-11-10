<?php

namespace Tests\Feature;

use App\Services\CliProxyApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayAvailabilityTest extends TestCase
{
    public function test_availability_check_sends_the_api_key_so_it_is_not_a_failed_auth_attempt(): void
    {
        config(['ai.cliproxy.api_key' => 'kunci-uji-api', 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        Http::fake(['http://127.0.0.1:8317/v1/models' => Http::response(['data' => []])]);

        $this->assertTrue(app(CliProxyApiClient::class)->isAvailable());

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer kunci-uji-api'));
    }

    public function test_gateway_answering_401_still_counts_as_up(): void
    {
        config(['ai.cliproxy.api_key' => null, 'ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        Http::fake(['http://127.0.0.1:8317/v1/models' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->assertTrue(app(CliProxyApiClient::class)->isAvailable());
    }

    public function test_unreachable_gateway_is_down(): void
    {
        config(['ai.cliproxy.base_url' => 'http://127.0.0.1:8317']);
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->assertFalse(app(CliProxyApiClient::class)->isAvailable());
    }
}
