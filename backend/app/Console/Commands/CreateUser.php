<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUser extends Command
{
    protected $signature = 'user:create {email} {--name=} {--role=viewer} {--password=}';

    protected $description = 'Create a dashboard user';

    public function handle(): int
    {
        $role = $this->option('role');
        if (! Roles::isValid($role)) {
            $this->error("unknown role '{$role}'. Valid: ".implode(', ', Roles::ALL));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: bin2hex(random_bytes(12));

        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            [
                'name' => $this->option('name') ?: $this->argument('email'),
                'password' => Hash::make($password),
                'role' => $role,
                'active' => true,
            ],
        );

        $this->info("user: {$user->email}");
        $this->info("role: {$user->role} (".implode(', ', Roles::abilitiesFor($role)).')');
        if (! $this->option('password')) {
            $this->newLine();
            $this->line("generated password: {$password}");
            $this->comment('Shown once.');
        }

        return self::SUCCESS;
    }
}
