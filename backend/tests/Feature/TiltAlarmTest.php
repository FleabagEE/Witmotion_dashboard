<?php

namespace Tests\Feature;

use App\Console\Commands\TiltCheck;
use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Does a silo that has moved actually raise an alarm?
 *
 * The alarm engine is tested; the tilt path into it was not. Every piece
 * existed - a definition, a scheduled command, an evaluator, a dashboard - and
 * nothing anywhere asserted that a structure leaning past the threshold
 * produces an alarm. That is the one behaviour the appliance is for.
 */
class TiltAlarmTest extends TestCase
{
    use RefreshDatabase;

    private function sensor(): Sensor
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.2.0', 'verification_status' => 'verified',
        ]);

        return Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80, 'status' => 'active',
            'metadata' => ['tilt_baseline' => [
                'tilt' => 0.66, 'temp' => 25.0, 'samples' => 5000,
                'captured_at' => now()->subDays(30)->toIso8601String(),
            ]],
        ]);
    }

    /** Quiet minutes at a given tilt, enough to clear the sample floor. */
    private function leaning(float $tilt, int $minutes = 90): void
    {
        $rows = [];
        $base = now()->subMinutes($minutes);
        for ($m = 0; $m < $minutes; $m++) {
            $at = $base->copy()->addMinutes($m);
            foreach ([
                'incl_tilt' => $tilt, 'incl_roll' => 0.0, 'incl_pitch' => -$tilt,
                'temperature' => 25.0, 'accel_amplitude_x' => 0.004,
                'accel_x' => sin(deg2rad($tilt)), 'accel_y' => 0.0, 'accel_z' => cos(deg2rad($tilt)),
            ] as $channel => $value) {
                for ($i = 0; $i < 10; $i++) {
                    $rows[] = [
                        'time' => $at->copy()->addSeconds($i * 6)->format('Y-m-d H:i:s.uP'),
                        'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
                        'channel_key' => $channel, 'value' => $value, 'unit' => 'x',
                        'quality' => 'good', 'source_type' => 'derived', 'sequence' => $i,
                        'run_id' => 'r1', 'ingested_at' => now(),
                    ];
                }
            }
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('measurements')->insert($chunk);
        }
    }

    public function test_a_silo_leaning_past_the_threshold_raises_an_alarm(): void
    {
        $sensor = $this->sensor();
        TiltCheck::provision($sensor, warningDeg: 0.5, criticalDeg: 3.0);

        // 4.7 degrees from a 0.66 baseline: past critical.
        $this->leaning(5.36);

        // Persistence is an hour, so one evaluation only nominates the level.
        $this->artisan('tilt:check')->assertSuccessful();

        // The clock moves on and the silo keeps reporting, still leaning. Without
        // the second seeding the deviation window is empty after the jump and the
        // command skips the sensor - which is what made the first version of this
        // test fail while the code was working.
        $this->travel(2)->hours();
        $this->leaning(5.36);
        $this->artisan('tilt:check')->assertSuccessful();

        $event = DB::table('alarm_events')->where('channel_key', 'tilt_deviation')->first();

        $this->assertNotNull($event, 'a silo leaning 4.7 deg raised no alarm at all');
        $this->assertSame('critical', $event->level);
        $this->assertSame('active', $event->state);
    }

    public function test_a_still_silo_raises_nothing(): void
    {
        $sensor = $this->sensor();
        TiltCheck::provision($sensor, warningDeg: 0.5, criticalDeg: 3.0);

        $this->leaning(0.67);   // 0.01 deg from baseline

        $this->artisan('tilt:check')->assertSuccessful();
        $this->travel(2)->hours();
        $this->leaning(0.67);
        $this->artisan('tilt:check')->assertSuccessful();

        $this->assertDatabaseCount('alarm_events', 0);
    }

    public function test_the_alarm_is_marked_provisional_and_will_not_notify(): void
    {
        $sensor = $this->sensor();
        TiltCheck::provision($sensor, warningDeg: 0.5, criticalDeg: 3.0);
        $this->leaning(5.36);

        $this->artisan('tilt:check');
        $this->travel(2)->hours();
        $this->leaning(5.36);
        $this->artisan('tilt:check');

        $event = DB::table('alarm_events')->where('channel_key', 'tilt_deviation')->first();

        // No published standard says how far a silo may lean. Until a named
        // person owns the number, the alarm is displayed and never sent - which
        // is correct, and is also why the appliance would page nobody today.
        $this->assertTrue((bool) $event->provisional);
    }

    /**
     * The whole chain: a silo leans, and somebody who is not looking at the
     * dashboard finds out.
     *
     * Every link was tested in isolation and the chain had never been. The one
     * that was broken - the definition being filtered out before evaluation -
     * sat between two well-tested components, which is exactly where nothing was
     * looking.
     */
    public function test_a_leaning_silo_reaches_somebody_who_is_not_watching(): void
    {
        $sensor = $this->sensor();
        $definition = TiltCheck::provision($sensor, warningDeg: 0.5, criticalDeg: 3.0);

        // Both human decisions made: a route out, and a named person owning the
        // number. Without either, the appliance stays silent by design.
        $this->artisan('alarms:channel', [
            'name' => 'duty-engineer', 'transport' => 'log', '--min-level' => 'warning',
        ])->assertSuccessful();

        $definition->update([
            'thresholds_confirmed_by' => 'A. Engineer',
            'thresholds_confirmed_at' => now(),
        ]);

        $this->leaning(5.36);
        $this->artisan('tilt:check');
        $this->travel(2)->hours();
        $this->leaning(5.36);
        $this->artisan('tilt:check');

        $event = DB::table('alarm_events')->where('channel_key', 'tilt_deviation')->first();
        $this->assertNotNull($event, 'no alarm was raised');
        $this->assertSame('critical', $event->level);
        $this->assertFalse((bool) $event->provisional, 'a confirmed threshold must not be provisional');

        $delivered = DB::table('notification_deliveries')->get();

        $this->assertNotEmpty($delivered, 'the alarm raised but reached nobody');
        $this->assertTrue(
            $delivered->contains(fn ($d) => $d->status === 'sent'),
            'every delivery was suppressed or failed: '
            . $delivered->pluck('status')->implode(', '),
        );
    }

    public function test_without_a_confirmed_threshold_nothing_is_sent(): void
    {
        $sensor = $this->sensor();
        TiltCheck::provision($sensor, warningDeg: 0.5, criticalDeg: 3.0);
        $this->artisan('alarms:channel', ['name' => 'duty', 'transport' => 'log']);

        $this->leaning(5.36);
        $this->artisan('tilt:check');
        $this->travel(2)->hours();
        $this->leaning(5.36);
        $this->artisan('tilt:check');

        // Raised and visible, but never sent. Unverified numbers have earned a
        // place on the dashboard and nothing more.
        $this->assertDatabaseHas('alarm_events', ['channel_key' => 'tilt_deviation']);
        $sent = DB::table('notification_deliveries')->where('status', 'sent')->count();
        $this->assertSame(0, $sent);
    }
}
