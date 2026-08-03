<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A dead axis reads as a still building, which is why this check exists and why
 * getting its criterion right matters more than having it at all.
 *
 * The first version keyed on "reports a dominant frequency while velocity is
 * zero" and called that a contradiction. It is not: the device estimates
 * frequency from acceleration at excitations below the level at which it reports
 * velocity, so the combination is normal. That version condemned the healthy
 * sensor in service.
 *
 * The criterion that works is comparative: while an axis was demonstrably being
 * excited, did its velocity output ever respond?
 */
class CheckAxesTest extends TestCase
{
    use RefreshDatabase;

    private function sample(string $channel, float $value, int $offsetSeconds): array
    {
        // One fixed base, so every channel at a given offset shares a timestamp.
        // In production they do by construction - amplitude and velocity arrive
        // in the same Modbus transaction - and the check joins on exactly that.
        // Calling now() per row gave them microsecond-different timestamps, the
        // join found nothing, and three healthy axes were reported as faults.
        static $base = null;
        $base ??= now();

        return [
            'time' => $base->copy()->subSeconds($offsetSeconds)->format('Y-m-d H:i:s.uP'),
            'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
            'channel_key' => $channel, 'value' => $value, 'unit' => 'x',
            'quality' => 'good', 'source_type' => 'native', 'sequence' => $offsetSeconds,
            'run_id' => 'r1', 'ingested_at' => now(),
        ];
    }

    /** @param array<string, array{amp: float, vel: float}> $axes */
    private function seedAxes(array $axes, int $samples = 40): void
    {
        $rows = [];
        for ($i = 0; $i < $samples; $i++) {
            foreach ($axes as $axis => $spec) {
                $rows[] = $this->sample("accel_amplitude_{$axis}", $spec['amp'], 600 - $i);
                $rows[] = $this->sample("vib_velocity_{$axis}", $spec['vel'], 600 - $i);
            }
        }
        DB::table('measurements')->insert($rows);
    }

    public function test_an_axis_excited_but_silent_is_a_fault(): void
    {
        $this->seedAxes([
            'x' => ['amp' => 0.4, 'vel' => 12.0],
            'y' => ['amp' => 0.4, 'vel' => 12.0],
            'z' => ['amp' => 0.4, 'vel' => 0.0],   // sees the motion, reports nothing
        ]);

        $this->artisan('sensors:check-axes')
            ->expectsOutputToContain('FAULT')
            ->assertFailed();
    }

    public function test_all_three_responding_passes(): void
    {
        $this->seedAxes([
            'x' => ['amp' => 0.4, 'vel' => 12.0],
            'y' => ['amp' => 0.4, 'vel' => 9.0],
            'z' => ['amp' => 0.4, 'vel' => 15.0],
        ]);

        $this->artisan('sensors:check-axes')
            ->expectsOutputToContain('All three axes respond')
            ->assertSuccessful();
    }

    public function test_a_still_sensor_is_untested_not_healthy(): void
    {
        // At rest amplitude sits near 0.0074 g and velocity is correctly zero.
        // Passing this would certify a sensor nobody tested.
        $this->seedAxes([
            'x' => ['amp' => 0.0074, 'vel' => 0.0],
            'y' => ['amp' => 0.0074, 'vel' => 0.0],
            'z' => ['amp' => 0.0074, 'vel' => 0.0],
        ]);

        $this->artisan('sensors:check-axes')
            ->expectsOutputToContain('UNTESTED')
            ->assertSuccessful();
    }

    public function test_a_still_sensor_is_not_reported_as_a_fault(): void
    {
        $this->seedAxes([
            'x' => ['amp' => 0.0074, 'vel' => 0.0],
            'y' => ['amp' => 0.0074, 'vel' => 0.0],
            'z' => ['amp' => 0.0074, 'vel' => 0.0],
        ]);

        $this->artisan('sensors:check-axes')
            ->doesntExpectOutputToContain('FAULT')
            ->assertSuccessful();
    }

    public function test_zero_velocity_with_a_reported_frequency_is_not_a_fault(): void
    {
        // The false positive that condemned a healthy sensor. Below the velocity
        // reporting threshold the device still estimates a frequency from
        // acceleration; that is normal behaviour, not a contradiction.
        $this->seedAxes([
            'x' => ['amp' => 0.4, 'vel' => 12.0],
            'y' => ['amp' => 0.4, 'vel' => 12.0],
            'z' => ['amp' => 0.4, 'vel' => 12.0],
        ]);
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = $this->sample('vib_frequency_y', 120.0, 600 - $i);
        }
        DB::table('measurements')->insert($rows);

        $this->artisan('sensors:check-axes')
            ->expectsOutputToContain('All three axes respond')
            ->assertSuccessful();
    }
}
