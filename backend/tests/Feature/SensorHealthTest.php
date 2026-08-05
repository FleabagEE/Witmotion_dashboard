<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\SensorHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Whether a sensor can still be believed, judged apart from the structure.
 *
 * A dead sensor and a perfectly still silo produce the same chart. Every flat
 * line has two explanations and only one is good news, so this asks about the
 * instrument and says nothing about movement - mixing them would let a genuinely
 * moving silo read as an unhealthy sensor.
 */
class SensorHealthTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function sensor(array $metadata = []): Sensor
    {
        $appliance = Appliance::firstOrCreate(['appliance_id' => 'QV-EDGE-TEST'], ['name' => 't']);
        $model = SensorModel::firstOrCreate(['model' => 'WTVB01-485'],
            ['manufacturer' => 'W', 'profile_version' => '1.2.0', 'verification_status' => 'verified']);

        return Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 0x50, 'status' => 'active',
            'metadata' => array_merge(['mounting' => ['position' => 'top', 'role' => 'monitor']], $metadata),
        ]);
    }

    private function reading(string $channel, float $value, string $quality = 'good',
                             int $secondsAgo = 5): void
    {
        $this->seq++;
        DB::table('measurements')->insert([
            'time' => now()->subSeconds($secondsAgo)->addMilliseconds($this->seq)->format('Y-m-d H:i:s.uP'),
            'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
            'channel_key' => $channel, 'value' => $value, 'unit' => 'g',
            'quality' => $quality, 'source_type' => 'native', 'sequence' => $this->seq,
            'run_id' => 'r1', 'ingested_at' => now(),
        ]);
    }

    /** A healthy unit at rest: gravity on Z, nothing else. */
    private function healthy(): void
    {
        foreach (range(1, 20) as $i) {
            $this->reading('accel_x', 0.01);
            $this->reading('accel_y', 0.01);
            $this->reading('accel_z', 0.9999);
            $this->reading('fault_x', 0.0);
        }
    }

    private function health(Sensor $sensor): array
    {
        return app(SensorHealth::class)->forSensor($sensor);
    }

    public function test_a_working_calibrated_commissioned_sensor_passes(): void
    {
        $sensor = $this->sensor(['tilt_baseline' => [
            'tilt' => 0.5, 'captured_at' => now()->subDay()->toIso8601String(),
        ]]);
        $this->healthy();

        $this->assertSame('pass', $this->health($sensor)['status']);
    }

    public function test_a_silent_sensor_fails(): void
    {
        // The failure this page exists for: a dead sensor looks exactly like a
        // structure that has stopped moving.
        $sensor = $this->sensor();
        $this->reading('accel_z', 0.9999, secondsAgo: 600);

        $health = $this->health($sensor);

        $this->assertSame('fail', $health['status']);
        $this->assertSame('fail', $health['checks']['reporting']['state']);
    }

    public function test_gravity_far_from_one_fails(): void
    {
        // An accelerometer at rest measures 1 g whatever way it faces. A failed
        // axis costs 30% or more and cannot hide behind a 5% tolerance.
        $sensor = $this->sensor();
        foreach (range(1, 20) as $i) {
            $this->reading('accel_x', 0.0);
            $this->reading('accel_y', 0.0);
            $this->reading('accel_z', 0.62);   // one axis dead
        }

        $health = $this->health($sensor);

        $this->assertSame('fail', $health['checks']['gravity']['state']);
        $this->assertStringContainsString('whatever way it faces', $health['checks']['gravity']['detail']);
    }

    public function test_an_uncalibrated_sensor_is_not_called_broken(): void
    {
        // Each accelerometer is wrong in its own way. 0.987 g is an uncalibrated
        // unit being honest, not a fault.
        $sensor = $this->sensor();
        foreach (range(1, 20) as $i) {
            $this->reading('accel_x', 0.01);
            $this->reading('accel_y', 0.01);
            $this->reading('accel_z', 0.9866);
        }

        $health = $this->health($sensor);

        $this->assertSame('pass', $health['checks']['gravity']['state']);
        $this->assertSame('warn', $health['checks']['calibration']['state']);
        $this->assertSame('warn', $health['status'], 'uncalibrated is a caveat, not a failure');
    }

    public function test_a_device_reported_fault_fails(): void
    {
        $sensor = $this->sensor();
        $this->healthy();
        $this->reading('fault_z', 4.0);

        $this->assertSame('fail', $this->health($sensor)['checks']['self_diagnosis']['state']);
    }

    public function test_rejected_readings_are_surfaced(): void
    {
        $sensor = $this->sensor();
        $this->healthy();
        foreach (range(1, 10) as $i) {
            $this->reading('vib_frequency_x', 404.0, quality: 'implausible');
        }

        $check = $this->health($sensor)['checks']['readings_believed'];

        $this->assertSame('warn', $check['state']);
        $this->assertStringContainsString('register map', $check['detail']);
    }

    public function test_a_missing_baseline_is_a_warning_not_a_failure(): void
    {
        // The sensor is fine; it simply has nothing to measure against yet.
        $sensor = $this->sensor();
        $this->healthy();

        $health = $this->health($sensor);

        $this->assertSame('warn', $health['checks']['baseline']['state']);
        $this->assertSame('pass', $health['checks']['reporting']['state']);
    }

    public function test_it_judges_the_instrument_and_not_the_structure(): void
    {
        // A silo leaning hard is a healthy sensor doing its job. If movement
        // could degrade this verdict, the one confusion the page exists to
        // prevent would be built into it.
        $sensor = $this->sensor(['tilt_baseline' => [
            'tilt' => 0.5, 'captured_at' => now()->subDay()->toIso8601String(),
        ]]);
        $this->healthy();
        foreach (range(1, 20) as $i) {
            $this->reading('incl_tilt', 42.0);
            $this->reading('accel_amplitude_x', 3.0);
        }

        $this->assertSame('pass', $this->health($sensor)['status']);
    }
}
