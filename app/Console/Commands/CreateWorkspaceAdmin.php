<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateWorkspaceAdmin extends Command
{
    protected $signature = 'workspace:create-admin {--name=} {--email=}';

    protected $description = 'Create the single Product Owner account for this workspace';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('A workspace admin already exists.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Admin name')));
        $email = trim((string) ($this->option('email') ?: $this->ask('Admin email')));
        $password = (string) $this->secret('Admin password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:14'],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info('Admin account created. Password was not displayed.');

        return self::SUCCESS;
    }
}
