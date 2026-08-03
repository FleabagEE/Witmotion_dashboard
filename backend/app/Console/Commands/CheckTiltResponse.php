<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Measures how long the tilt reading keeps moving after the sensor stops.
 *
 * Tilt is derived from the acceleration registers, and those are filtered inside
 * the device - heavily enough that the reading is still converging seconds after
 * the sensor is physically still. That lag is invisible while you are moving the
 * sensor, because a moving reading looks like it is tracking. It only shows when
 * the motion stops and the number carries on.
 *
 * The measurement is possible because two channels come from one transaction and
 * share a timestamp: acceleration amplitude (0x37-0x39) responds immediately, so
 * it says when the motion actually ended, and tilt says how long it took to catch
 * up. Without the fast channel there would be no way to separate "the operator
 * moved it slowly" from "the sensor is lagging".
 *
 * Run it after changing the sampling rate in the vendor software to find out
 * whether that setting affects the filter. The appliance's own path is about
 * 25 ms end to end, so anything measured here belongs to the device.
 */
class CheckTiltResponse extends Command
{
    protected $signature = 'measurements:check-tilt-response
                            {--sensor=SENSOR-001}
                            {--minutes=30}';

    protected $description = 'Measure how long tilt keeps drifting after the sensor stops moving';

    /** Amplitude above which the sensor is being moved, in g. At rest it sits near 0.007. */
    private const MOVING_G = 0.05;

    /** Amplitude below which the motion has genuinely stopped. */
    private const STILL_G = 0.015;

    /** Tilt movement below this per sample counts as settled, in degrees. */
    private const SETTLED_DEG = 0.02;

    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            WITH s AS (
                SELECT time,
                    max(value) FILTER (WHERE channel_key = 'incl_tilt') AS tilt,
                    greatest(
                        max(value) FILTER (WHERE channel_key = 'accel_amplitude_x'),
                        max(value) FILTER (WHERE channel_key = 'accel_amplitude_y'),
                        max(value) FILTER (WHERE channel_key = 'accel_amplitude_z')
                    ) AS amp
                FROM measurements
                WHERE sensor_id = ? AND time > now() - (? || ' minutes')::interval
                GROUP BY time
            )
            SELECT * FROM s WHERE tilt IS NOT NULL AND amp IS NOT NULL ORDER BY time
        SQL, [$this->option('sensor'), (int) $this->option('minutes')]);

        if (count($rows) < 50) {
            $this->warn('Not enough data. Tilt the sensor, hold it still, then run this again.');

            return self::SUCCESS;
        }

        $events = [];
        $n = count($rows);
        $moving = false;

        // A state machine, not a comparison of adjacent samples. Amplitude does
        // not cross from moving to still in one step - it decays through several
        // (0.068, 0.038, 0.023, 0.015) - so a two-sample test never fires and the
        // first version of this reported no events at all on data that plainly
        // contained them.
        for ($i = 1; $i < $n; $i++) {
            if ($rows[$i]->amp > self::MOVING_G) {
                $moving = true;
                continue;
            }

            if (! $moving || $rows[$i]->amp > self::STILL_G) {
                continue;
            }

            // Motion has just ended. Follow the tilt until it stops changing.
            $moving = false;
            $start = strtotime($rows[$i]->time);
            $tiltAtStop = $rows[$i]->tilt;
            $settledAt = null;
            $drift = 0.0;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($rows[$j]->amp > self::MOVING_G) {
                    break;   // moved again: this is a new event, not this one settling
                }
                $drift = max($drift, abs($rows[$j]->tilt - $tiltAtStop));
                if (abs($rows[$j]->tilt - $rows[$j - 1]->tilt) < self::SETTLED_DEG) {
                    $settledAt = strtotime($rows[$j]->time) - $start;
                    break;
                }
            }

            if ($settledAt !== null && $drift > 0.1) {
                $events[] = ['seconds' => $settledAt, 'drift' => $drift];
                $i = $j;
            }
        }

        if ($events === []) {
            $this->warn('No clean movement-then-stop found.');
            $this->line('Tilt the sensor to a new angle and hold it perfectly still for');
            $this->line('~10 seconds, then run this again.');

            return self::SUCCESS;
        }

        $seconds = array_column($events, 'seconds');
        $drifts = array_column($events, 'drift');
        sort($seconds);
        sort($drifts);
        $medianSeconds = $seconds[intdiv(count($seconds), 2)];
        $medianDrift = $drifts[intdiv(count($drifts), 2)];

        $this->newLine();
        $this->line(sprintf('%d movement(s) analysed', count($events)));
        $this->line(sprintf('  settling time  %.1f s (median), worst %.1f s',
            $medianSeconds, max($seconds)));
        $this->line(sprintf('  drift after the sensor stopped  %.2f deg (median), worst %.2f deg',
            $medianDrift, max($drifts)));
        $this->newLine();

        if ($medianSeconds <= 1.0) {
            $this->info('Fast. The tilt reading tracks the sensor closely.');
        } else {
            $this->warn(sprintf(
                'The tilt reading is still moving %.1f s after the sensor stops.', $medianSeconds,
            ));
            $this->line('This is the device filtering its acceleration registers, not the');
            $this->line('appliance: the live path measures about 25 ms end to end. It cannot');
            $this->line('be corrected in software - undoing an unspecified filter would be');
            $this->line('guesswork dressed as a reading.');
            $this->line('');
            $this->line('For structural monitoring it rarely matters: a mounting shifts over');
            $this->line('days, not seconds. It only shows up in a hand test.');
            $this->line('');
            $this->line('Worth trying: change the sampling rate in the vendor software, save');
            $this->line('it, then repeat this measurement to see whether the filter follows.');
        }

        return self::SUCCESS;
    }
}
