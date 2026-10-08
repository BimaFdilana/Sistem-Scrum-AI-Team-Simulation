<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_be_provisioned_once_from_the_console_without_printing_the_password(): void
    {
        $password = 'Very-Strong-Local-Secret-902!';

        $this->artisan('workspace:create-admin', [
            '--name' => 'Product Owner',
            '--email' => 'owner@example.test',
        ])
            ->expectsQuestion('Admin password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutputToContain('Admin account created.')
            ->assertSuccessful();

        $user = User::query()->sole();

        $this->assertSame('owner@example.test', $user->email);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_admin_provisioning_is_refused_when_an_account_already_exists(): void
    {
        User::factory()->create();

        $this->artisan('workspace:create-admin')
            ->expectsOutputToContain('A workspace admin already exists.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
