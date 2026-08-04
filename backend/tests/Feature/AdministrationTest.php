<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may change the numbers, and what happens when they do.
 *
 * A threshold is not ordinary configuration. Raising one silences an alarm and
 * leaves the dashboard looking calm, which is indistinguishable from a
 * structure that stopped moving. So the tests here are less about "does the
 * field save" than about who is allowed to touch it, whether the change is
 * recorded, and whether a signature given for one number survives being applied
 * to a different one.
 */
class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        Sanctum::actingAs($user, Roles::abilitiesFor($role));

        return $user;
    }

    private function definition(array $overrides = []): AlarmDefinition
    {
        return AlarmDefinition::create(array_merge([
            'key' => 'tilt-'.uniqid(),
            'name' => 'Tilt movement from baseline',
            'channel_key' => 'tilt_deviation',
            'condition_type' => 'high_threshold',
            'unit' => 'deg',
            'warning_at' => 0.5,
            'critical_at' => 3.0,
            'enabled' => true,
        ], $overrides));
    }

    // --- who may change a threshold ---------------------------------------

    public function test_an_operator_cannot_change_a_threshold(): void
    {
        $this->asRole(Roles::OPERATOR);
        $definition = $this->definition();

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'critical_at' => 99.0, 'reason' => 'quieter dashboard',
        ])->assertForbidden();

        $this->assertSame(3.0, (float) $definition->fresh()->critical_at);
    }

    public function test_an_engineer_cannot_change_a_threshold_either(): void
    {
        // Engineers hold `configure`, which covers acquisition settings. A
        // threshold decides when a structure is called unsafe, and that is a
        // step above.
        $this->asRole(Roles::ENGINEER);
        $definition = $this->definition();

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'critical_at' => 99.0, 'reason' => 'testing',
        ])->assertForbidden();
    }

    public function test_an_administrator_can_change_a_threshold(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition();

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'critical_at' => 0.25,
            'warning_at' => 0.1,
            'reason' => 'Structural engineer set H/250 for a 9.1 m silo',
        ])->assertOk()->assertJsonPath('data.critical_at', 0.25);
    }

    public function test_everyone_who_can_read_can_see_the_thresholds(): void
    {
        // An operator who cannot see the limit cannot judge whether an alarm
        // matters, so reading is not restricted.
        $this->asRole(Roles::OPERATOR);
        $this->definition();

        $response = $this->getJson('/api/v1/alarm-definitions')->assertOk();

        $this->assertEqualsWithDelta(3.0, $response->json('data.0.critical_at'), 1e-9);
    }

    // --- what a change costs ----------------------------------------------

    public function test_changing_the_numbers_clears_the_confirmation(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition([
            'thresholds_confirmed_by' => 'A. Engineer',
            'thresholds_confirmed_at' => now()->subDay(),
            'thresholds_reference' => 'Report 2026-07',
        ]);

        $response = $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'critical_at' => 12.0, 'reason' => 'fewer alarms please',
        ])->assertOk();

        // The engineer signed off 3 degrees, not 12. The appliance must not go
        // on paging people on the authority of that signature.
        $this->assertNull($definition->fresh()->thresholds_confirmed_by);
        $this->assertTrue($response->json('confirmation_cleared'));
        $this->assertFalse($response->json('data.actionable'));
    }

    public function test_toggling_enabled_does_not_clear_the_confirmation(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition([
            'thresholds_confirmed_by' => 'A. Engineer',
            'thresholds_confirmed_at' => now()->subDay(),
        ]);

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'enabled' => false, 'reason' => 'sensor removed for maintenance',
        ])->assertOk();

        // Nothing the engineer put their name to has changed.
        $this->assertSame('A. Engineer', $definition->fresh()->thresholds_confirmed_by);
    }

    public function test_a_change_is_recorded_with_both_values_and_a_reason(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition();

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'critical_at' => 25.0, 'reason' => 'suppressing nuisance alarms',
        ])->assertOk();

        $entry = DB::table('audit_events')->where('action', 'alarm_definition.updated')->first();

        $this->assertNotNull($entry, 'a threshold change left no audit trail');
        $this->assertStringContainsString('3', $entry->before ?? '');
        $this->assertStringContainsString('25', $entry->after ?? '');
        $this->assertStringContainsString('nuisance', $entry->after ?? '');
    }

    public function test_a_reason_is_required(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition();

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", ['critical_at' => 9.0])
            ->assertStatus(422);
    }

    public function test_a_warning_above_its_critical_is_refused(): void
    {
        // It would let a structure pass through warning without reaching it.
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition();

        $this->patchJson("/api/v1/alarm-definitions/{$definition->id}", [
            'warning_at' => 5.0, 'critical_at' => 1.0, 'reason' => 'oops',
        ])->assertStatus(422);
    }

    public function test_confirming_is_separate_from_editing(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $definition = $this->definition();

        $this->postJson("/api/v1/alarm-definitions/{$definition->id}/confirm", [
            'confirmed_by' => 'R. Structural',
            'reference' => 'Geotechnical report GR-2026-114, section 4',
        ])->assertOk()->assertJsonPath('data.actionable', true);

        $this->assertSame('R. Structural', $definition->fresh()->thresholds_confirmed_by);
    }

    // --- users -------------------------------------------------------------

    public function test_only_an_administrator_may_list_users(): void
    {
        $this->asRole(Roles::ENGINEER);
        $this->getJson('/api/v1/users')->assertForbidden();

        $this->asRole(Roles::ADMINISTRATOR);
        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_an_administrator_cannot_demote_themselves(): void
    {
        // One click from an appliance in a plant room that nobody can
        // administer, on a machine that may have no other route in.
        $admin = $this->asRole(Roles::ADMINISTRATOR);

        $this->patchJson("/api/v1/users/{$admin->id}", ['role' => Roles::VIEWER])
            ->assertStatus(422);

        $this->assertSame(Roles::ADMINISTRATOR, $admin->fresh()->role);
    }

    public function test_the_last_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->asRole(Roles::ADMINISTRATOR);
        $other = User::factory()->create(['role' => Roles::ADMINISTRATOR, 'active' => true]);

        // Two admins: deactivating one is fine.
        $this->patchJson("/api/v1/users/{$other->id}", ['active' => false])->assertOk();

        // Now there is one left, and it is the actor - blocked twice over.
        $this->patchJson("/api/v1/users/{$admin->id}", ['active' => false])->assertStatus(422);
        $this->assertTrue((bool) $admin->fresh()->active);
    }

    public function test_deactivating_a_user_revokes_their_sessions(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $victim = User::factory()->create(['role' => Roles::OPERATOR, 'active' => true]);
        $victim->createToken('tablet', ['read']);

        $this->assertSame(1, $victim->tokens()->count());

        $this->patchJson("/api/v1/users/{$victim->id}", ['active' => false])->assertOk();

        // A deactivated account with a live token is not deactivated - the
        // tablet in the site office would still be logged in.
        $this->assertSame(0, $victim->fresh()->tokens()->count());
    }

    public function test_a_password_reset_revokes_every_session(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $user = User::factory()->create(['role' => Roles::OPERATOR, 'active' => true]);
        $user->createToken('phone', ['read']);
        $user->createToken('desktop', ['read']);

        $this->postJson("/api/v1/users/{$user->id}/password", [
            'password' => 'a-long-enough-replacement',
        ])->assertOk()->assertJsonPath('sessions_revoked', 2);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $user = User::factory()->create(['role' => Roles::OPERATOR]);

        $this->postJson("/api/v1/users/{$user->id}/password", ['password' => 'short'])
            ->assertStatus(422);
    }

    public function test_roles_are_published_with_what_each_can_do(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);

        $response = $this->getJson('/api/v1/roles')->assertOk();
        $roles = collect($response->json('data'));

        $operator = $roles->firstWhere('role', Roles::OPERATOR);
        $this->assertContains('acknowledge', $operator['abilities']);
        $this->assertNotContains('administer', $operator['abilities']);
    }
}
