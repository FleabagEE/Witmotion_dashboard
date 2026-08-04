<?php

namespace Tests\Feature;

use App\Services\TiltMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settlement monitoring on a concrete silo.
 *
 * The whole difficulty is that a MEMS accelerometer's zero point moves with
 * temperature, and an outdoor structure cycles 20 degC a day. Uncompensated,
 * the silo appears to lean every afternoon and straighten every night, and any
 * real settlement is buried inside that swing.
 *
 * So the thing being tested is not "does it compute an angle" but "does it
 * refuse to attribute movement to settlement when temperature explains it, and
 * refuse to model temperature when somebody moved the sensor".
 */
class TiltMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function seedTilt(array $points): void
    {
        $rows = [];
        $base = now()->subHours(48);
        foreach ($points as $i => [$tilt, $temp]) {
            $at = $base->copy()->addMinutes($i * 5)->format('Y-m-d H:i:s.uP');
            foreach ([
                'incl_tilt' => $tilt,
                'incl_roll' => 0.0,
                'incl_pitch' => -$tilt,
                'temperature' => $temp,
                'accel_amplitude_x' => 0.007,   // at rest
            ] as $channel => $value) {
                $rows[] = [
                    'time' => $at, 'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
                    'channel_key' => $channel, 'value' => $value, 'unit' => 'x',
                    'quality' => 'good', 'source_type' => 'derived', 'sequence' => $i,
                    'run_id' => 'r1', 'ingested_at' => now(),
                ];
            }
        }
        DB::table('measurements')->insert($rows);
    }

    private function monitor(): TiltMonitor
    {
        return new TiltMonitor();
    }

    public function test_a_thermal_relationship_is_found_when_it_exists(): void
    {
        // Tilt that only follows temperature: 0.05 deg per degC across 10 degC.
        $points = [];
        for ($i = 0; $i < 120; $i++) {
            $temp = 15 + 10 * sin($i / 20);
            $points[] = [0.4 + 0.05 * ($temp - 15), $temp];
        }
        $this->seedTilt($points);

        $model = $this->monitor()->thermalModel('SENSOR-001', 72);

        $this->assertGreaterThan(0.9, abs($model['correlation']));
        $this->assertEqualsWithDelta(0.05, $model['slope'], 0.005);
        $this->assertTrue($model['significant']);
    }

    public function test_a_model_is_refused_when_the_sensor_was_moved(): void
    {
        // The failure this guard exists for. Fitted over a bench day where the
        // sensor was picked up and set down, the model returned -1.22 deg/degC
        // - implying 24 degrees across a day/night swing - and called itself
        // usable. It was fitting re-orientations against the weather.
        $points = [];
        for ($i = 0; $i < 120; $i++) {
            $temp = 20 + 3 * sin($i / 15);
            // Somebody moves it a third of the way through.
            $points[] = [$i < 40 ? 1.5 : 14.8, $temp];
        }
        $this->seedTilt($points);

        $model = $this->monitor()->thermalModel('SENSOR-001', 72);

        $this->assertTrue($model['disturbed']);
        $this->assertFalse($model['significant'],
            'a window containing a re-orientation must not produce a usable model');
    }

    public function test_a_model_is_refused_across_too_narrow_a_temperature_range(): void
    {
        // A slope fitted across half a degree indoors cannot be extrapolated to
        // a silo in February. Applying it would inject more error than it
        // removes.
        $points = [];
        for ($i = 0; $i < 120; $i++) {
            $temp = 25 + 0.4 * sin($i / 10);
            $points[] = [0.5 + 0.05 * ($temp - 25), $temp];
        }
        $this->seedTilt($points);

        $model = $this->monitor()->thermalModel('SENSOR-001', 72);

        $this->assertLessThan(2.0, $model['temp_range']);
        $this->assertFalse($model['significant']);
    }

    public function test_temperature_movement_is_not_reported_as_settlement(): void
    {
        // The headline case. The silo has not moved; only the temperature has.
        $points = [];
        for ($i = 0; $i < 200; $i++) {
            $temp = 15 + 10 * sin($i / 25);
            $points[] = [0.4 + 0.05 * ($temp - 15), $temp];
        }
        $this->seedTilt($points);
        $model = $this->monitor()->thermalModel('SENSOR-001', 72);

        // Baseline taken at 15 degC; now reading at 25 degC.
        $baseline = ['tilt' => 0.4, 'temp' => 15.0, 'thermal_model' => $model];
        $this->seedTilt(array_fill(0, 20, [0.9, 25.0]));   // 0.5 deg higher, entirely thermal

        $deviation = $this->monitor()->deviation('SENSOR-001', $baseline, 60 * 24 * 3);

        $this->assertTrue($deviation['compensated']);
        // Raw looks like half a degree of settlement; corrected is near nothing.
        $this->assertGreaterThan(
            abs($deviation['corrected_deviation']),
            abs($deviation['raw_deviation']),
            'the correction should reduce the apparent movement, not increase it',
        );
    }

    public function test_the_thermal_component_is_reported_not_hidden(): void
    {
        // An operator has to see how much movement was explained away, or the
        // compensation becomes a black box that can quietly erase a real signal.
        $points = [];
        for ($i = 0; $i < 200; $i++) {
            $temp = 15 + 10 * sin($i / 25);
            $points[] = [0.4 + 0.05 * ($temp - 15), $temp];
        }
        $this->seedTilt($points);
        $model = $this->monitor()->thermalModel('SENSOR-001', 72);
        $baseline = ['tilt' => 0.4, 'temp' => 15.0, 'thermal_model' => $model];

        $deviation = $this->monitor()->deviation('SENSOR-001', $baseline, 60 * 24 * 3);

        $this->assertArrayHasKey('thermal_component', $deviation);
        $this->assertArrayHasKey('raw_deviation', $deviation);
        $this->assertArrayHasKey('corrected_deviation', $deviation);
    }

    public function test_no_correction_is_applied_without_a_usable_model(): void
    {
        // Enough samples to clear the minimum - deviation() refuses to report
        // from fewer than ten, because an average of three readings is noise.
        $this->seedTilt(array_fill(0, 20, [0.9, 25.0]));
        $baseline = ['tilt' => 0.4, 'temp' => 15.0, 'thermal_model' => null];

        $deviation = $this->monitor()->deviation('SENSOR-001', $baseline, 60 * 24 * 3);

        $this->assertFalse($deviation['compensated']);
        $this->assertSame(0.0, $deviation['thermal_component']);
        $this->assertEqualsWithDelta(0.5, $deviation['raw_deviation'], 0.001);
    }

    public function test_averaging_improves_the_resolution_as_root_n(): void
    {
        // One LSB of a +/-16 g 16-bit accelerometer is about 0.028 deg at small
        // angles. Averaging is the only way a settlement monitor sees tenths of
        // a degree, and it is why this integrates over an hour rather than
        // reacting in milliseconds.
        $monitor = $this->monitor();

        $one = $monitor->resolution(1)['averaged_deg'];
        $tenThousand = $monitor->resolution(10000)['averaged_deg'];

        $this->assertEqualsWithDelta(0.028, $one, 0.002);
        $this->assertEqualsWithDelta($one / 100, $tenThousand, 0.0001);
    }
}
