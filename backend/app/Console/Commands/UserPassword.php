<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Set a password, and optionally a role, from the console.
 *
 * The way back in when nobody can log in. The dashboard can reset a password,
 * but only for an administrator who is already signed in, which is no help to
 * an appliance whose only administrator has forgotten theirs — or, as here, to
 * one that never had an administrator at all.
 *
 * The password is prompted for, never taken as an argument. `user:create
 * --password=...` writes the secret into shell history and into the process
 * list where any other user on the box can read it with `ps`. This asks instead,
 * and the terminal does not echo it.
 */
class UserPassword extends Command
{
    protected $signature = 'user:password
        {email : the account to set a password for}
        {--role= : also change the role, e.g. administrator}
        {--create : create the account if it does not exist}
        {--name= : full name, when creating}';

    protected $description = 'Set a user password from the console, prompted rather than typed';

    public function handle(AuditLogger $audit): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user && ! $this->option('create')) {
            $this->error("No account for {$email}. Pass --create to make one.");

            return self::FAILURE;
        }

        $role = $this->option('role');

        if ($role !== null && ! Roles::isValid($role)) {
            $this->error("Unknown role '{$role}'. One of: ".implode(', ', Roles::ALL));

            return self::FAILURE;
        }

        $password = $this->secret('New password (at least 12 characters, not shown)');
        $again = $this->secret('Type it again');

        if ($password !== $again) {
            $this->error('They do not match. Nothing was changed.');

            return self::FAILURE;
        }

        if (strlen((string) $password) < 12) {
            $this->error('At least 12 characters. Nothing was changed.');

            return self::FAILURE;
        }

        $creating = $user === null;

        $user ??= new User([
            'email' => $email,
            'name' => $this->option('name') ?? $email,
            'role' => $role ?? Roles::VIEWER,
        ]);

        $user->password = Hash::make($password);
        $user->active = true;

        if ($role !== null) {
            $user->role = $role;
        }

        $user->save();

        // Every existing session, gone. A password change that leaves old tokens
        // alive has not locked anybody out.
        $revoked = $creating ? 0 : $user->tokens()->count();
        $user->tokens()->delete();

        $audit->record(
            action: $creating ? 'user.created' : 'user.password_reset',
            subjectType: 'user',
            subjectId: (string) $user->id,
            summary: sprintf(
                '%s %s from the console as %s, %d session(s) revoked',
                $user->email,
                $creating ? 'created' : 'password reset',
                $user->role,
                $revoked,
            ),
            actorTypeOverride: 'console',
            actorNameOverride: 'artisan user:password',
        );

        $this->info(sprintf(
            '%s: %s, role %s%s',
            $user->email,
            $creating ? 'created' : 'password set',
            $user->role,
            $revoked > 0 ? ", {$revoked} session(s) signed out" : '',
        ));

        // Said here because it is the failure this command exists to prevent
        // happening twice.
        $admins = User::where('role', Roles::ADMINISTRATOR)->where('active', true)->count();

        if ($admins === 0) {
            $this->newLine();
            $this->warn('This appliance has no active administrator.');
            $this->line('  Thresholds and accounts cannot be changed from the dashboard by anyone.');
            $this->line('  php artisan user:password you@example.com --role=administrator --create');
        }

        return self::SUCCESS;
    }
}
