<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class WorkspaceAdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('workspace.admin.name'));
        $email = trim((string) config('workspace.admin.email'));

        $existingUser = User::query()->first();
        if ($existingUser !== null) {
            if ($existingUser->email === $email) {
                $this->command?->info('Workspace admin already exists; credentials were not changed.');

                return;
            }

            throw new RuntimeException('A different user already exists; refusing to create a second workspace admin.');
        }

        $password = config('workspace.admin.password');
        if (! is_string($password) || $password === '') {
            $hasInteractiveInput = defined('STDIN')
                && function_exists('stream_isatty')
                && stream_isatty(STDIN);

            if ($this->command === null || ! $hasInteractiveInput) {
                throw new RuntimeException('Set WORKSPACE_ADMIN_PASSWORD or run db:seed interactively to enter it securely.');
            }

            $password = (string) $this->command->secret('Admin password');
            $confirmation = (string) $this->command->secret('Confirm admin password');

            if ($password !== $confirmation) {
                throw new RuntimeException('Admin password confirmation does not match.');
            }
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:14'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException($validator->errors()->first());
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->command?->info('Workspace admin created; password was not displayed or stored in the seeder.');
    }
}
