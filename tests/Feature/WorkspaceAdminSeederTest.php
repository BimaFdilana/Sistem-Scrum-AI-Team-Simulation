<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class WorkspaceAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_seeder_creates_the_configured_account_with_a_hashed_password(): void
    {
        Config::set('workspace.admin.name', 'Workspace Owner');
        Config::set('workspace.admin.email', 'Sipua@polbeng.ac.id');
        Config::set('workspace.admin.password', 'Example-Test-Only-Password-93!');

        $this->seed();

        $user = User::query()->sole();
        $this->assertSame('Workspace Owner', $user->name);
        $this->assertSame('Sipua@polbeng.ac.id', $user->email);
        $this->assertTrue(Hash::check('Example-Test-Only-Password-93!', $user->password));
        $this->assertNotSame('Example-Test-Only-Password-93!', $user->password);
    }

    public function test_workspace_admin_seeder_requires_a_password_and_does_not_create_an_account_without_it(): void
    {
        Config::set('workspace.admin.name', 'Workspace Owner');
        Config::set('workspace.admin.email', 'Sipua@polbeng.ac.id');
        Config::set('workspace.admin.password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WORKSPACE_ADMIN_PASSWORD');

        $this->seed(\Database\Seeders\WorkspaceAdminSeeder::class);
    }

    public function test_workspace_admin_seeder_is_idempotent_and_does_not_reset_an_existing_password(): void
    {
        Config::set('workspace.admin.name', 'Workspace Owner');
        Config::set('workspace.admin.email', 'Sipua@polbeng.ac.id');
        Config::set('workspace.admin.password', 'Example-Test-Only-Password-93!');
        $existing = User::factory()->create([
            'name' => 'Existing Owner',
            'email' => 'Sipua@polbeng.ac.id',
            'password' => 'Changed-Existing-Password-94!',
        ]);

        $this->seed(\Database\Seeders\WorkspaceAdminSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($existing->fresh()->password, User::query()->sole()->password);
        $this->assertTrue(Hash::check('Changed-Existing-Password-94!', User::query()->sole()->password));
    }
}
