<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A kiosk screen sits somewhere anyone can reach it.
 *
 * The display hides the controls, but hiding is presentation, not a boundary.
 * These tests are about the boundary: a kiosk token must be unable to do
 * anything but read, whatever request somebody makes with it.
 */
class KioskTest extends TestCase
{
    use RefreshDatabase;

    private function kiosk(): User
    {
        $user = User::factory()->create(['role' => Roles::KIOSK, 'active' => true]);
        $this->app['auth']->forgetGuards();

        return $user;
    }

    public function test_a_kiosk_token_carries_only_read(): void
    {
        $this->assertSame(['read'], Roles::abilitiesFor(Roles::KIOSK));
    }

    public function test_a_kiosk_can_read_the_live_data_it_displays(): void
    {
        Sanctum::actingAs($this->kiosk(), ['read']);

        $this->getJson('/api/v1/sensors')->assertOk();
        $this->getJson('/api/v1/alarms')->assertOk();
    }

    private function realAlarm(): AlarmEvent
    {
        // A real record, because acknowledging a non-existent alarm returns 404
        // from route-model binding before the ability check is ever reached -
        // which would let this test pass while proving nothing.
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.1.0', 'verification_status' => 'verified',
        ]);
        $sensor = Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80,
        ]);
        $definition = AlarmDefinition::create([
            'key' => 'k-'.uniqid(), 'name' => 'Structural vibration',
            'condition_type' => 'structural_ppv', 'unit' => 'mm/s', 'enabled' => true,
        ]);

        return AlarmEvent::create([
            'alarm_definition_id' => $definition->id, 'sensor_id' => $sensor->id,
            'channel_key' => 'vib_velocity_x', 'level' => 'critical', 'peak_level' => 'critical',
            'state' => 'active', 'provisional' => true,
            'trigger_value' => 8.2, 'threshold' => 5.0, 'unit' => 'mm/s', 'raised_at' => now(),
        ]);
    }

    public function test_a_kiosk_cannot_acknowledge_a_real_alarm(): void
    {
        // The screen shows alarms. Standing in front of it must not be enough to
        // clear one - an acknowledgement is a person taking responsibility, and
        // it has to be attributable to that person rather than to a wall.
        $alarm = $this->realAlarm();
        Sanctum::actingAs($this->kiosk(), ['read']);

        $this->postJson("/api/v1/alarms/{$alarm->id}/acknowledge", ['note' => 'seen'])
            ->assertForbidden();

        $this->assertNull($alarm->fresh()->acknowledged_at);
    }

    public function test_an_operator_can_acknowledge_the_same_alarm(): void
    {
        // The inverse, so the test above is known to be measuring the kiosk's
        // ability rather than some unrelated obstacle.
        $alarm = $this->realAlarm();
        $operator = User::factory()->create(['role' => Roles::OPERATOR, 'active' => true]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($operator, ['read', 'acknowledge']);

        $this->postJson("/api/v1/alarms/{$alarm->id}/acknowledge", ['note' => 'seen'])
            ->assertOk();
    }

    public function test_the_kiosk_role_is_a_recognised_role(): void
    {
        $this->assertTrue(Roles::isValid(Roles::KIOSK));
        $this->assertContains(Roles::KIOSK, Roles::ALL);
    }
}
