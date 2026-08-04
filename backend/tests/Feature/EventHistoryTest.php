<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The record of what happened, and who may read which half of it.
 *
 * Two records exist. `alarm_events` is what the structure did; `audit_events`
 * is what people did to the appliance. Both were being written and neither was
 * visible anywhere.
 *
 * They are not equally public. The monitoring record belongs to anyone who can
 * read. The audit trail names individuals and records their actions, so it sits
 * behind `audit` - and an operator who cannot see it is told it exists rather
 * than being shown a list that quietly omits half of what happened.
 */
class EventHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        Sanctum::actingAs($user, Roles::abilitiesFor($role));

        return $user;
    }

    private function seedAudit(string $action = 'alarm_definition.updated'): void
    {
        DB::table('audit_events')->insert([
            'occurred_at' => now()->subHour(),
            'actor_name' => 'Site Administrator',
            'actor_type' => 'user',
            'action' => $action,
            'subject_type' => 'alarm_definition',
            'subject_id' => '1',
            'summary' => 'Tilt threshold changed',
            'before' => json_encode(['critical_at' => 3.0]),
            'after' => json_encode(['critical_at' => 25.0, 'reason' => 'fewer alarms']),
            'result' => 'success',
        ]);
    }

    public function test_an_operator_sees_alarms_but_not_the_audit_trail(): void
    {
        $this->asRole(Roles::OPERATOR);
        $this->seedAudit();

        $response = $this->getJson('/api/v1/events')->assertOk();

        $this->assertFalse($response->json('audit_visible'));
        foreach ($response->json('data') as $event) {
            $this->assertSame('alarm', $event['kind'], 'an operator was shown an audit entry');
        }
    }

    public function test_an_operator_is_told_the_audit_trail_exists(): void
    {
        // Silently omitting half the history would let somebody conclude
        // nothing happened when in fact they were not allowed to see it.
        $this->asRole(Roles::OPERATOR);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('audit_visible', false);
    }

    public function test_an_auditor_sees_the_audit_trail(): void
    {
        $this->asRole(Roles::AUDITOR);
        $this->seedAudit();

        $response = $this->getJson('/api/v1/events')->assertOk();

        $this->assertTrue($response->json('audit_visible'));
        $this->assertNotEmpty(
            array_filter($response->json('data'), fn ($e) => $e['kind'] === 'audit'),
        );
    }

    public function test_an_administrator_sees_both_records_interleaved(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $this->seedAudit();

        $response = $this->getJson('/api/v1/events')->assertOk();

        $this->assertTrue($response->json('audit_visible'));
    }

    public function test_a_threshold_change_carries_both_values(): void
    {
        // The reason this list matters: somebody changing a limit an hour before
        // the alarms stopped is the thing an investigation needs to see.
        $this->asRole(Roles::ADMINISTRATOR);
        $this->seedAudit();

        $entry = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('kind', 'audit');

        $this->assertEqualsWithDelta(3.0, $entry['before']['critical_at'], 1e-9);
        $this->assertEqualsWithDelta(25.0, $entry['after']['critical_at'], 1e-9);
        $this->assertSame('fewer alarms', $entry['after']['reason']);
    }

    public function test_the_window_is_respected(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        DB::table('audit_events')->insert([
            'occurred_at' => now()->subDays(90),
            'action' => 'user.created', 'summary' => 'ancient', 'result' => 'success',
        ]);
        $this->seedAudit();

        $recent = $this->getJson('/api/v1/events?days=7')->json('data');

        $this->assertNotContains('ancient', array_column($recent, 'title'));
    }

    public function test_it_can_be_filtered_to_one_kind(): void
    {
        $this->asRole(Roles::ADMINISTRATOR);
        $this->seedAudit();

        $audit = $this->getJson('/api/v1/events?kind=audit')->json('data');

        $this->assertNotEmpty($audit);
        foreach ($audit as $event) {
            $this->assertSame('audit', $event['kind']);
        }
    }
}
