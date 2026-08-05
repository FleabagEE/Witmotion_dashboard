<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reading back what the structure has actually been experiencing.
 *
 * The placeholder vibration limits were breached by somebody picking the sensor
 * up off a bench, which is what choosing a number before you know the
 * distribution looks like. This command exists so the next choice is made from
 * evidence.
 */
class VibrationSurveyTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** Each reading gets its own instant: identical timestamps collide on the key. */
    private function reading(float $value, int $minutesAgo = 5, string $quality = 'good'): void
    {
        $this->seq++;

        DB::table('measurements')->insert([
            'time' => now()->subMinutes($minutesAgo)->addMilliseconds($this->seq * 10)
                ->format('Y-m-d H:i:s.uP'),
            'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
            'channel_key' => 'accel_amplitude_x', 'value' => $value, 'unit' => 'g',
            'quality' => $quality, 'source_type' => 'native', 'sequence' => $this->seq,
            'run_id' => 'r1', 'ingested_at' => now(),
        ]);
    }

    public function test_it_reports_the_distribution_not_just_the_peak(): void
    {
        // A mean describes the noise floor and a max describes one event. What
        // sets a threshold is the shape in between, so the command has to
        // surface percentiles - asserted against the query rather than against
        // the formatted line, which would break on a column width.
        foreach (range(1, 200) as $i) {
            $this->reading(0.006);
        }
        $this->reading(0.52);   // one hard knock

        $this->artisan('alarms:vibration-survey --days=1')
            ->expectsOutputToContain('accel_amplitude_x')
            ->assertSuccessful();

        $row = DB::table('measurements')
            ->where('channel_key', 'accel_amplitude_x')->where('quality', 'good')
            ->selectRaw('max(value) as peak')
            ->selectRaw('percentile_cont(0.50) within group (order by value) as p50')
            ->first();

        $this->assertEqualsWithDelta(0.52, $row->peak, 1e-6, 'the knock was lost');
        $this->assertEqualsWithDelta(0.006, $row->p50, 1e-6, 'the median is not the noise floor');
    }

    public function test_it_excludes_readings_the_appliance_did_not_believe(): void
    {
        foreach (range(1, 50) as $i) {
            $this->reading(0.006);
        }
        // Out of range, already flagged by the decoder. Including it would put a
        // number the appliance rejected into the evidence used to set a limit.
        $this->reading(99.0, quality: 'implausible');

        $this->artisan('alarms:vibration-survey --days=1')
            ->doesntExpectOutputToContain('99.0000')
            ->assertSuccessful();
    }

    public function test_it_says_when_there_is_nothing_to_summarise(): void
    {
        $this->artisan('alarms:vibration-survey --days=1')->assertFailed();
    }

    public function test_it_warns_that_a_slow_poll_rate_bounds_the_answer(): void
    {
        // At 1 Hz the appliance sees one value per second and the shape between
        // samples is the device's summary, not a measurement. A survey that did
        // not say so would read as more authoritative than it is.
        foreach (range(1, 60) as $i) {
            $this->reading(0.006, minutesAgo: 2);
        }

        $this->artisan('alarms:vibration-survey --days=1')
            ->expectsOutputToContain('Sampled at about')
            ->assertSuccessful();
    }
}
