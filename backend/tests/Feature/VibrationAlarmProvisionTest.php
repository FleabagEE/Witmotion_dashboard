<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\Appliance;
use App\Models\Channel;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\AlarmEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vibration thresholds, and the two things that make them honest.
 *
 * They are scoped by quantity rather than channel, so one definition covers X,
 * Y and Z. And they ship unconfirmed, because nobody has judged the numbers -
 * a value this appliance invented has not earned the right to wake anybody up.
 */
class VibrationAlarmProvisionTest extends TestCase
{
    use RefreshDatabase;

    private function sensor(): Sensor
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 't']);
        $model = SensorModel::create(['model' => 'WTVB01-485', 'manufacturer' => 'W',
            'profile_version' => '1.2.0', 'verification_status' => 'verified']);

        $sensor = Sensor::create(['sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80, 'status' => 'active']);

        foreach (['x', 'y', 'z'] as $axis) {
            Channel::create([
                'sensor_id' => $sensor->id, 'channel_key' => "accel_amplitude_{$axis}",
                'group_key' => 'motion', 'label' => "Acceleration amplitude {$axis}",
                'quantity' => 'acceleration_amplitude', 'unit' => 'g', 'value_class' => 'native',
            ]);
        }

        return $sensor;
    }

    public function test_it_creates_both_definitions(): void
    {
        $this->sensor();

        $this->artisan('alarms:provision-vibration')->assertSuccessful();

        $this->assertDatabaseHas('alarm_definitions', ['key' => 'vibration-acceleration-SENSOR-001']);
        $this->assertDatabaseHas('alarm_definitions', ['key' => 'vibration-velocity-SENSOR-001']);
    }

    public function test_they_ship_unconfirmed_and_therefore_silent(): void
    {
        $this->sensor();
        $this->artisan('alarms:provision-vibration');

        foreach (AlarmDefinition::whereIn('key', [
            'vibration-acceleration-SENSOR-001', 'vibration-velocity-SENSOR-001',
        ])->get() as $definition) {
            // Displayed, never sent. A number the appliance chose for itself has
            // not earned the right to page anyone.
            $this->assertNull($definition->thresholds_confirmed_by);
            $this->assertFalse($definition->thresholdsConfirmed());
        }
    }

    public function test_one_definition_covers_all_three_axes(): void
    {
        // Scoped by quantity, not channel. Three cards saying the same thing
        // would be three places to forget to change.
        $sensor = $this->sensor();
        $this->artisan('alarms:provision-vibration');

        $evaluator = new AlarmEvaluator;
        $sensor->load('channels', 'model');

        foreach (['accel_amplitude_x', 'accel_amplitude_y', 'accel_amplitude_z'] as $channel) {
            $matched = collect((fn () => iterator_to_array($this->definitionsFor($sensor, $channel)))
                ->call($evaluator));

            $this->assertTrue(
                $matched->contains(fn ($d) => $d->key === 'vibration-acceleration-SENSOR-001'),
                "the definition did not match {$channel}",
            );
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->sensor();

        $this->artisan('alarms:provision-vibration');
        $this->artisan('alarms:provision-vibration');

        $this->assertSame(2, AlarmDefinition::count());
    }

    public function test_vibration_clears_far_faster_than_settlement(): void
    {
        // A hammer blow is over in milliseconds; a silo settles over weeks. An
        // alarm that waits an hour to be sure would miss the first and an alarm
        // that clears in a minute would chatter on the second.
        $this->sensor();
        $this->artisan('alarms:provision-vibration');

        $vibration = AlarmDefinition::where('key', 'vibration-acceleration-SENSOR-001')->first();

        $this->assertSame(0, $vibration->persistence_seconds);
        $this->assertLessThanOrEqual(60, $vibration->clear_seconds);
    }
}
