<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The way back into an appliance nobody can log into.
 *
 * The dashboard can reset a password, but only for an administrator who is
 * already signed in. That is no use to an appliance whose administrator forgot
 * theirs, and none at all to one that never had an administrator - which is the
 * state this appliance was actually in when admin-only pages were added to it.
 */
class UserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_administrator(): void
    {
        $this->artisan('user:password', [
            'email' => 'boss@example.com', '--role' => 'administrator', '--create' => true,
        ])
            ->expectsQuestion('New password (at least 12 characters, not shown)', 'a-sufficient-password')
            ->expectsQuestion('Type it again', 'a-sufficient-password')
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertSame(Roles::ADMINISTRATOR, $user->role);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('a-sufficient-password', $user->password));
    }

    public function test_it_resets_a_forgotten_password_and_signs_the_user_out(): void
    {
        $user = User::factory()->create(['email' => 'eng@example.com', 'role' => Roles::ENGINEER]);
        $user->createToken('browser', ['read']);

        $this->artisan('user:password', ['email' => 'eng@example.com'])
            ->expectsQuestion('New password (at least 12 characters, not shown)', 'another-good-password')
            ->expectsQuestion('Type it again', 'another-good-password')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('another-good-password', $user->fresh()->password));
        // A password change that leaves old tokens alive has not locked anybody out.
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_a_mistyped_confirmation_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'eng@example.com']);
        $original = $user->password;

        $this->artisan('user:password', ['email' => 'eng@example.com'])
            ->expectsQuestion('New password (at least 12 characters, not shown)', 'first-attempt-here')
            ->expectsQuestion('Type it again', 'second-attempt-here')
            ->assertFailed();

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_a_short_password_changes_nothing(): void
    {
        $user = User::factory()->create(['email' => 'eng@example.com']);
        $original = $user->password;

        $this->artisan('user:password', ['email' => 'eng@example.com'])
            ->expectsQuestion('New password (at least 12 characters, not shown)', 'short')
            ->expectsQuestion('Type it again', 'short')
            ->assertFailed();

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_it_refuses_an_unknown_account_without_create(): void
    {
        $this->artisan('user:password', ['email' => 'nobody@example.com'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_an_unknown_role(): void
    {
        $this->artisan('user:password', [
            'email' => 'x@example.com', '--role' => 'wizard', '--create' => true,
        ])->assertFailed();
    }
}
