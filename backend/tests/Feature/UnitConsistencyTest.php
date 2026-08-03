<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The check has to catch a hundredfold displacement error and pass a correct
 * one. Both directions matter: a check that never fires is decoration, and one
 * that fires on good data gets switched off.
 */
class UnitConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Writes a synthetic event obeying v = 2*pi*f*A.
     *
     * @param  float  $displacementFactor  1.0 for correct micrometres, 100.0 for
     *                                     the fine range mode read as micrometres
     */
    private function seedEvent(float $displacementFactor): void
    {
        $frequency = 20.0;              // Hz
        $amplitudeMm = 0.5;             // 500 um
        $velocity = 2 * M_PI * $frequency * $amplitudeMm;   // mm/s

        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $at = now()->subSeconds(60 - $i)->format('Y-m-d H:i:s.uP');
            foreach ([
                'vib_velocity_z' => $velocity,
                'vib_displacement_z' => $amplitudeMm * 1000 * $displacementFactor,
                'vib_frequency_z' => $frequency,
            ] as $channel => $value) {
                $rows[] = [
                    'time' => $at, 'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
                    'channel_key' => $channel, 'value' => $value, 'unit' => 'x',
                    'quality' => 'good', 'source_type' => 'native', 'sequence' => $i,
                    'run_id' => 'r1', 'ingested_at' => now(),
                ];
            }
        }
        DB::table('measurements')->insert($rows);
    }

    public function test_correctly_scaled_displacement_passes(): void
    {
        $this->seedEvent(displacementFactor: 1.0);

        $this->artisan('measurements:check-units')
            ->expectsOutputToContain('Consistent')
            ->assertSuccessful();
    }

    public function test_the_fine_range_mode_read_as_micrometres_is_caught(): void
    {
        // The real hazard: somebody switches the device to 600 um / 0.01 um in
        // the vendor software and nothing in the Modbus data says so. Every
        // displacement reading is then 100x too large and looks entirely
        // plausible - the difference between cosmetic and structural on any
        // guideline table.
        $this->seedEvent(displacementFactor: 100.0);

        $this->artisan('measurements:check-units')
            ->expectsOutputToContain('too large')
            ->assertFailed();
    }

    public function test_a_quiet_sensor_is_not_reported_as_a_fault(): void
    {
        // Nothing to check is not the same as something wrong. A check that
        // cries wolf on a still structure gets ignored when it matters.
        $this->artisan('measurements:check-units')
            ->expectsOutputToContain('nothing to check')
            ->assertSuccessful();
    }
}
