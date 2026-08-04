<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\AlarmEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens to an alarm when its definition is switched off.
 *
 * Disabling a definition stops it being evaluated, which sounds like it would
 * end its alarms and does the opposite: the open events are never looked at
 * again, so they never clear and never age out. Two of them sat "active" on the
 * dashboard indefinitely, and no code path anywhere could have closed them.
 *
 * The fix has to close them without claiming anything about the structure. An
 * alarm that ends because somebody turned the check off did not recover.
 */
class AlarmRetirementTest extends TestCase
{
    use RefreshDatabase;

    private function sensor(): Sensor
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.1.0', 'verification_status' => 'verified',
        ]);

        return Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80, 'status' => 'active',
        ]);
    }

    private function openEvent(AlarmDefinition $definition, Sensor $sensor): AlarmEvent
    {
        return AlarmEvent::create([
            'alarm_definition_id' => $definition->id,
            'sensor_id' => $sensor->id,
            'channel_key' => 'vib_velocity_x',
            'level' => 'critical', 'peak_level' => 'critical',
            'state' => 'active', 'provisional' => true,
            'trigger_value' => 0.0, 'peak_value' => 469.29,
            'threshold' => 3.0, 'unit' => 'mm/s', 'raised_at' => now()->subDay(),
        ]);
    }

    private function definition(bool $enabled = true): AlarmDefinition
    {
        return AlarmDefinition::create([
            'key' => 'ppv-'.uniqid(), 'name' => 'Structural vibration',
            'condition_type' => 'structural_ppv', 'unit' => 'mm/s', 'enabled' => $enabled,
        ]);
    }

    public function test_disabling_a_definition_closes_the_events_it_leaves_behind(): void
    {
        $sensor = $this->sensor();
        $definition = $this->definition();
        $event = $this->openEvent($definition, $sensor);

        $this->artisan('alarms:disable', [
            'definition' => $definition->id,
            '--reason' => 'moved to tilt-only monitoring',
        ])->assertSuccessful();

        $definition->refresh();
        $event->refresh();

        $this->assertFalse($definition->enabled);
        $this->assertSame('retired', $event->state);
        $this->assertNotNull($event->cleared_at);
    }

    public function test_a_retired_alarm_is_not_recorded_as_having_recovered(): void
    {
        $sensor = $this->sensor();
        $definition = $this->definition();
        $event = $this->openEvent($definition, $sensor);

        $this->artisan('alarms:disable', ['definition' => $definition->id])->assertSuccessful();
        $event->refresh();

        // "Cleared" is a statement about the structure - the measurement came
        // back within limits. Nothing observed the structure here. Recording
        // this as a recovery would put a fact in the history that was never
        // measured.
        $this->assertNotSame('cleared', $event->state);
        $this->assertSame('critical', $event->peak_level, 'the severity it reached must survive');
        $this->assertSame(
            'alarm definition disabled or removed',
            $event->metadata['retired_reason'] ?? null,
        );
    }

    public function test_retiring_is_not_acknowledging(): void
    {
        $sensor = $this->sensor();
        $definition = $this->definition();
        $event = $this->openEvent($definition, $sensor);

        $this->artisan('alarms:disable', ['definition' => $definition->id])->assertSuccessful();
        $event->refresh();

        // Acknowledgement records that a named person read the alarm and
        // accepted it. Nobody did. Forging that would put a human's judgement
        // in the record where there was none.
        $this->assertNull($event->acknowledged_at);
        $this->assertNull($event->acknowledged_by);
    }

    public function test_events_of_an_enabled_definition_are_left_alone(): void
    {
        $sensor = $this->sensor();
        $event = $this->openEvent($this->definition(enabled: true), $sensor);

        (new AlarmEvaluator)->retireOrphanedEvents();

        $this->assertSame('active', $event->refresh()->state);
    }

    public function test_the_sweep_catches_a_definition_disabled_some_other_way(): void
    {
        $sensor = $this->sensor();
        $definition = $this->definition();
        $event = $this->openEvent($definition, $sensor);

        // A seeder, a migration, or somebody editing the row directly. The
        // command is not the only way this happens, so the scheduled sweep has
        // to catch it too.
        $definition->update(['enabled' => false]);

        $this->artisan('alarms:sweep')->assertSuccessful();

        $this->assertSame('retired', $event->refresh()->state);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $sensor = $this->sensor();
        $definition = $this->definition();
        $event = $this->openEvent($definition, $sensor);

        $this->artisan('alarms:disable', [
            'definition' => $definition->id, '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertTrue($definition->refresh()->enabled);
        $this->assertSame('active', $event->refresh()->state);
    }
}
