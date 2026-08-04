<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the settlement chart is allowed to plot.
 *
 * The chart had a failure mode that no assertion about correctness would have
 * caught: every number it drew was true, and the picture was still useless. A
 * few minutes of bench handling put 10 degree excursions in the trace, the
 * y-axis stretched to fit them, and the settlement signal - which lives in
 * hundredths of a degree - was flattened onto the zero gridline.
 *
 * So these tests assert something unusual: not just that the values are right,
 * but that the RANGE of what gets plotted stays close to the measurement rather
 * than to the disturbances. A chart scaled by its own artifacts is a bug even
 * when every point on it is accurate.
 */
class TiltSeriesTest extends TestCase
{
    use RefreshDatabase;

    private function sensorWithBaseline(Carbon $capturedAt, float $tilt = 0.66): Sensor
    {
        return $this->sensor([
            'tilt_baseline' => [
                'tilt' => $tilt,
                'temp' => 25.0,
                'samples' => 5000,
                'captured_at' => $capturedAt->toIso8601String(),
            ],
        ]);
    }

    private function sensor(array $metadata): Sensor
    {
        $appliance = Appliance::firstOrCreate(
            ['appliance_id' => 'QV-EDGE-TEST'],
            ['name' => 'test'],
        );
        $model = SensorModel::firstOrCreate(
            ['model' => 'WTVB01-485'],
            ['manufacturer' => 'WitMotion', 'profile_version' => '1.1.0',
             'verification_status' => 'verified'],
        );

        return Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80,
            'status' => 'active', 'metadata' => $metadata,
        ]);
    }

    /** One minute of readings: tilt, temperature and the disturbance channel. */
    private function minute(Carbon $at, float $tilt, float $amplitude, float $temp = 25.0): void
    {
        $rows = [];
        foreach ([
            'incl_tilt' => $tilt,
            'incl_roll' => 0.0,
            'incl_pitch' => -$tilt,
            'temperature' => $temp,
            'accel_amplitude_x' => $amplitude,
        ] as $channel => $value) {
            $rows[] = [
                'time' => $at->format('Y-m-d H:i:s.uP'),
                'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
                'channel_key' => $channel, 'value' => $value, 'unit' => 'x',
                'quality' => 'good', 'source_type' => 'derived', 'sequence' => 0,
                'run_id' => 'r1', 'ingested_at' => now(),
            ];
        }
        DB::table('measurements')->insert($rows);
    }

    private function series(int $days = 1): array
    {
        Sanctum::actingAs(User::factory()->create(), ['read']);

        $response = $this->getJson("/api/v1/tilt?days={$days}&sensor_id=SENSOR-001");
        $response->assertOk();

        return $response->json('sensors.0.series');
    }

    public function test_a_bench_tilt_test_does_not_set_the_scale_of_the_chart(): void
    {
        // Four hours of bench work at up to 12 degrees, then commissioning, then
        // two hours of a silo that has settled by three hundredths of a degree.
        $commissioned = now()->subHours(2)->startOfHour();

        for ($m = 240; $m > 0; $m--) {
            $at = $commissioned->copy()->subMinutes($m);
            // Set down at a steep angle and left there: perfectly still, and
            // genuinely 12 degrees off. No amplitude filter can catch this.
            $this->minute($at, tilt: $m % 40 < 20 ? 12.0 : 0.66, amplitude: 0.004);
        }

        for ($m = 0; $m < 120; $m++) {
            $this->minute($commissioned->copy()->addMinutes($m), tilt: 0.69, amplitude: 0.004);
        }

        $this->sensorWithBaseline($commissioned);
        $series = $this->series();

        $plotted = array_values(array_filter(
            array_column($series['points'], 'deviation'),
            fn ($v) => $v !== null,
        ));

        $this->assertNotEmpty($plotted, 'the chart must still plot the data it has');

        // The whole point: 12 degrees of bench testing must not appear on an
        // axis that has to resolve 0.03 degrees of settlement.
        $this->assertLessThan(
            0.5,
            max(array_map('abs', $plotted)),
            'a pre-commissioning excursion reached the plotted series and will crush the axis',
        );
        $this->assertEqualsWithDelta(0.03, max($plotted), 0.005);
    }

    public function test_excluded_history_is_marked_rather_than_silently_absent(): void
    {
        $commissioned = now()->subHour()->startOfHour();

        for ($m = 120; $m > 0; $m--) {
            $this->minute($commissioned->copy()->subMinutes($m), tilt: 9.0, amplitude: 0.004);
        }
        for ($m = 0; $m < 60; $m++) {
            $this->minute($commissioned->copy()->addMinutes($m), tilt: 0.67, amplitude: 0.004);
        }

        $this->sensorWithBaseline($commissioned);
        $series = $this->series();

        $pre = array_filter($series['points'], fn ($p) => $p['pre_commissioning']);

        // The history is still returned - it drives a shaded band - it just
        // carries no deviation. Dropping the rows entirely would have made the
        // chart look like the sensor had not been running.
        $this->assertNotEmpty($pre, 'pre-commissioning buckets must still be returned');
        foreach ($pre as $point) {
            $this->assertNull($point['deviation']);
            $this->assertNotNull($point['temperature'], 'temperature is context and stays continuous');
        }
    }

    public function test_handling_after_commissioning_is_excluded_from_the_bucket(): void
    {
        $commissioned = now()->subHours(3)->startOfHour();
        $this->sensorWithBaseline($commissioned);

        for ($m = 0; $m < 150; $m++) {
            // Ten minutes of somebody leaning on it, an hour after commissioning.
            $handled = $m >= 60 && $m < 70;
            $this->minute(
                $commissioned->copy()->addMinutes($m),
                tilt: $handled ? 15.0 : 0.68,
                amplitude: $handled ? 3.0 : 0.004,
            );
        }

        $series = $this->series();
        $plotted = array_values(array_filter(
            array_column($series['points'], 'deviation'),
            fn ($v) => $v !== null,
        ));

        $this->assertNotEmpty($plotted);
        $this->assertLessThan(0.5, max(array_map('abs', $plotted)));

        $disturbed = array_filter($series['points'], fn ($p) => $p['disturbed']);
        $this->assertNotEmpty($disturbed, 'the handled interval must be reported, not just dropped');
    }

    public function test_the_bucket_width_is_reported_so_bands_can_be_drawn(): void
    {
        $commissioned = now()->subHours(2);
        $this->sensorWithBaseline($commissioned);
        $this->minute(now()->subMinutes(5), tilt: 0.67, amplitude: 0.004);

        $this->assertSame(300, $this->series(1)['bucket_seconds']);
        $this->assertSame(3600, $this->series(7)['bucket_seconds']);
    }

    public function test_an_uncommissioned_sensor_still_returns_its_tilt(): void
    {
        $this->sensor([]);

        for ($m = 0; $m < 30; $m++) {
            $this->minute(now()->subMinutes(30 - $m), tilt: 0.71, amplitude: 0.004);
        }

        $points = $this->series()['points'];
        $tilts = array_filter(array_column($points, 'tilt'), fn ($v) => $v !== null);

        // Nothing to deviate from, but the readings are real and the page shows
        // them. Hiding them is what made the dashboard look broken.
        $this->assertNotEmpty($tilts);
        $this->assertEqualsWithDelta(0.71, max($tilts), 0.001);
        foreach ($points as $point) {
            $this->assertNull($point['deviation']);
            $this->assertFalse($point['pre_commissioning'], 'no baseline means no commissioning boundary');
        }
    }
}
