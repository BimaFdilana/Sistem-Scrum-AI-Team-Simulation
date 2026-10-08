<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_can_log_in_and_open_the_agent_dashboard(): void
    {
        $user = User::factory()->create([
            'password' => 'Correct-Horse-123!',
        ]);

        $this->get('/login')->assertOk()->assertSee('AI Scrum Agent Workspace');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-123!',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Scrum Master')
            ->assertSee('QA Engineer');
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        $user = User::factory()->create([
            'password' => 'Correct-Horse-123!',
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_https_proxy_requests_generate_secure_asset_and_form_urls(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => 'workspace.example',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/login')
            ->assertOk()
            ->assertSee('href="https://localhost:8000/css/workspace.css"', false)
            ->assertSee('action="https://localhost:8000/login"', false);
    }

    public function test_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
