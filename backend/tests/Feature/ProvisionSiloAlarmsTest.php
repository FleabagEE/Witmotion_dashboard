<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Twelve definitions across three sensors, and what they must not do.
 *
 * The two that matter: nothing ships able to notify anybody, and re-running the
 * command must not quietly revoke a signature. An engineer's confirmation is an
 * assertion about the outside world, and a provisioning tool has no business
 * cancelling one.
 */
class ProvisionSiloAlarmsTest extends TestCase
{
    use RefreshDatabase;

    private function sensors(): void
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 't']);
        $model = SensorModel::create(['model' => 'WTVB01-485', 'manufacturer' => 'W',
            'profile_version' => '1.2.0', 'verification_status' => 'verified']);

        foreach ([['SENSOR-001', 'top'], ['SENSOR-002', 'mid'], ['SENSOR-003', 'ground']] as [$id, $pos]) {
            Sensor::create([
                'sensor_id' => $id, 'appliance_id' => $appliance->id,
                'sensor_model_id' => $model->id, 'slave_id' => 0x50, 'status' => 'active',
                'metadata' => ['mounting' => ['position' => $pos]],
            ]);
        }
    }

    public function test_it_covers_four_quantities_on_every_sensor(): void
    {
        $this->sensors();

        $this->artisan('alarms:provision-silo')->assertSuccessful();

        $this->assertSame(12, AlarmDefinition::count());

        foreach (['SENSOR-001', 'SENSOR-002', 'SENSOR-003'] as $id) {
            foreach (['tilt-deviation', 'vibration-acceleration',
                      'vibration-velocity', 'vibration-displacement'] as $prefix) {
                $this->assertDatabaseHas('alarm_definitions', ['key' => "{$prefix}-{$id}"]);
            }
        }
    }

    public function test_nothing_ships_able_to_notify_anybody(): void
    {
        $this->sensors();
        $this->artisan('alarms:provision-silo');

        foreach (AlarmDefinition::all() as $definition) {
            $this->assertNull(
                $definition->thresholds_confirmed_by,
                "{$definition->key} shipped confirmed; nobody has judged these numbers",
            );
        }
    }

    public function test_re_running_does_not_revoke_a_signature(): void
    {
        // A provisioning tool has no business cancelling an engineer's
        // assertion about the outside world.
        $this->sensors();
        $this->artisan('alarms:provision-silo');

        AlarmDefinition::where('key', 'tilt-deviation-SENSOR-001')->update([
            'thresholds_confirmed_by' => 'A. Engineer',
            'thresholds_confirmed_at' => now(),
        ]);

        $this->artisan('alarms:provision-silo')->assertSuccessful();

        $this->assertSame(
            'A. Engineer',
            AlarmDefinition::where('key', 'tilt-deviation-SENSOR-001')->value('thresholds_confirmed_by'),
        );
    }

    public function test_it_is_idempotent(): void
    {
        $this->sensors();

        $this->artisan('alarms:provision-silo');
        $this->artisan('alarms:provision-silo');

        $this->assertSame(12, AlarmDefinition::count());
    }

    public function test_vibration_is_scoped_by_quantity_not_by_channel(): void
    {
        // One card per quantity per sensor. Three would be three places to
        // forget to change.
        $this->sensors();
        $this->artisan('alarms:provision-silo');

        $definition = AlarmDefinition::where('key', 'vibration-velocity-SENSOR-001')->first();

        $this->assertNull($definition->channel_key);
        $this->assertSame('vibration_velocity', $definition->quantity);
    }

    public function test_vibration_and_settlement_get_different_timers(): void
    {
        // A hammer blow is over in milliseconds and a silo settles over weeks.
        // One set of timers would either miss the first or make the second
        // chatter.
        $this->sensors();
        $this->artisan('alarms:provision-silo');

        $tilt = AlarmDefinition::where('key', 'tilt-deviation-SENSOR-001')->first();
        $shake = AlarmDefinition::where('key', 'vibration-acceleration-SENSOR-001')->first();

        $this->assertSame(3600, $tilt->persistence_seconds);
        $this->assertSame(0, $shake->persistence_seconds);
        $this->assertGreaterThan($shake->clear_seconds, $tilt->clear_seconds);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->sensors();

        $this->artisan('alarms:provision-silo', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, AlarmDefinition::count());
    }
}
