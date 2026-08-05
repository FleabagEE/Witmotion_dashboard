<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\StructureMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Telling what the site did from what the structure did.
 *
 * A single sensor cannot: a lorry passing and a silo settling both produce a
 * reading that changed. The whole point of a ground reference is that those two
 * become different, and the tests here are the four shapes that matter -
 * site-only, rigid rotation, bending, and the one that means somebody should
 * check a bracket.
 */
class StructureMovementTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function silo(): void
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 't']);
        $model = SensorModel::create(['model' => 'WTVB01-485', 'manufacturer' => 'W',
            'profile_version' => '1.2.0', 'verification_status' => 'verified']);

        foreach ([['SENSOR-001', 'top', 'monitor'], ['SENSOR-002', 'mid', 'monitor'],
                  ['SENSOR-003', 'ground', 'reference']] as [$id, $pos, $role]) {
            Sensor::create([
                'sensor_id' => $id, 'appliance_id' => $appliance->id,
                'sensor_model_id' => $model->id, 'slave_id' => 0x50, 'status' => 'active',
                'metadata' => [
                    'mounting' => ['position' => $pos, 'role' => $role],
                    'tilt_baseline' => [
                        'tilt' => 0.5, 'temp' => 25.0, 'samples' => 5000,
                        'captured_at' => now()->subDays(30)->toIso8601String(),
                    ],
                ],
            ]);
        }
    }

    /** Quiet minutes at a given tilt, for one sensor. */
    private function reading(string $sensorId, float $tilt, int $minutes = 30): void
    {
        $rows = [];
        $base = now()->subMinutes($minutes);

        for ($m = 0; $m < $minutes; $m++) {
            $at = $base->copy()->addMinutes($m);
            foreach ([
                'incl_tilt' => $tilt, 'incl_roll' => 0.0, 'incl_pitch' => -$tilt,
                'temperature' => 25.0, 'accel_amplitude_x' => 0.004,
            ] as $channel => $value) {
                for ($i = 0; $i < 10; $i++) {
                    $this->seq++;
                    $rows[] = [
                        'time' => $at->copy()->addSeconds($i * 6)->format('Y-m-d H:i:s.uP'),
                        'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => $sensorId,
                        'channel_key' => $channel, 'value' => $value, 'unit' => 'x',
                        'quality' => 'good', 'source_type' => 'derived', 'sequence' => $this->seq,
                        'run_id' => 'r1', 'ingested_at' => now(),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('measurements')->insert($chunk);
        }
    }

    private function analyse(): array
    {
        return app(StructureMovement::class)->analyse();
    }

    public function test_a_site_disturbance_is_not_reported_as_structural_movement(): void
    {
        // The case a single sensor cannot distinguish. All three move together,
        // so nothing moved relative to the ground.
        $this->silo();
        foreach (['SENSOR-001', 'SENSOR-002', 'SENSOR-003'] as $id) {
            $this->reading($id, 0.8);   // 0.3 deg from a 0.5 baseline, everywhere
        }

        $result = $this->analyse();

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(0.3, $result['site'], 0.01, 'the site moved and must be reported');
        $this->assertEqualsWithDelta(0.0, $result['structure']['top'], 0.01);
        $this->assertSame('still', $result['interpretation']['shape']);
    }

    public function test_a_rigid_rotation_points_at_the_foundation(): void
    {
        $this->silo();
        $this->reading('SENSOR-001', 0.75);   // +0.25
        $this->reading('SENSOR-002', 0.75);   // +0.25
        $this->reading('SENSOR-003', 0.50);   // ground still

        $result = $this->analyse();

        $this->assertEqualsWithDelta(0.25, $result['structure']['top'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['bending'], 0.01);
        $this->assertSame('rigid', $result['interpretation']['shape']);
        $this->assertStringContainsString('foundation', $result['interpretation']['summary']);
    }

    public function test_the_top_moving_more_than_the_middle_is_bending(): void
    {
        // A rigid rotation tips every sensor by the same angle whatever its
        // height. Only bending tips the top more.
        $this->silo();
        $this->reading('SENSOR-001', 0.90);   // +0.40
        $this->reading('SENSOR-002', 0.65);   // +0.15
        $this->reading('SENSOR-003', 0.50);

        $result = $this->analyse();

        $this->assertEqualsWithDelta(0.25, $result['bending'], 0.01);
        $this->assertSame('bending', $result['interpretation']['shape']);
    }

    public function test_the_middle_moving_more_than_the_top_is_called_unexpected(): void
    {
        // Neither settlement nor simple bending does this. Saying "bending"
        // would send somebody looking at the structure when the likeliest
        // answer is a loose bracket.
        $this->silo();
        $this->reading('SENSOR-001', 0.60);   // +0.10
        $this->reading('SENSOR-002', 0.90);   // +0.40
        $this->reading('SENSOR-003', 0.50);

        $result = $this->analyse();

        $this->assertSame('unexpected', $result['interpretation']['shape']);
        $this->assertStringContainsString('mounting', $result['interpretation']['summary']);
    }

    public function test_the_site_reading_is_reported_and_not_only_subtracted(): void
    {
        // A ground reading that grows over months is the ground going
        // somewhere, and subtracting it silently would hide that.
        $this->silo();
        $this->reading('SENSOR-001', 1.30);
        $this->reading('SENSOR-002', 1.30);
        $this->reading('SENSOR-003', 1.30);

        $this->assertEqualsWithDelta(0.8, $this->analyse()['site'], 0.01);
    }

    public function test_it_refuses_when_a_sensor_has_no_baseline(): void
    {
        // Comparing sensors without baselines compares their mountings rather
        // than their movement.
        $this->silo();
        Sensor::where('sensor_id', 'SENSOR-002')->update(['metadata' => [
            'mounting' => ['position' => 'mid', 'role' => 'monitor'],
        ]]);

        foreach (['SENSOR-001', 'SENSOR-002', 'SENSOR-003'] as $id) {
            $this->reading($id, 0.6);
        }

        $result = $this->analyse();

        $this->assertFalse($result['available']);
        $this->assertContains('mid', $result['missing']);
    }
}
