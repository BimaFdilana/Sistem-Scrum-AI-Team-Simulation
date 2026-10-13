<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TunnelHttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_urls_use_https_on_tunnel_host(): void
    {
        $response = $this->get('http://scrumspace-tunnel.example/login');

        $response->assertOk();
        $this->assertStringContainsString(
            'https://scrumspace-tunnel.example/css/',
            $response->getContent(),
            'Asset harus https di host tunnel agar tidak mixed content.'
        );
        $this->assertStringNotContainsString(
            'http://scrumspace-tunnel.example/css/',
            $response->getContent()
        );
    }

    public function test_localhost_keeps_http_scheme(): void
    {
        $response = $this->get('http://localhost/login');

        $response->assertOk();
        $this->assertStringContainsString('http://localhost/css/', $response->getContent());
    }
}
