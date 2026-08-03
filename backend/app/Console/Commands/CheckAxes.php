<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Commissioning check: prove all three axes actually respond.
 *
 * A dead axis does not look like a fault. It looks like a still building - the
 * channel reads zero, the chart is flat, and nothing anywhere says the reading
 * is absent rather than small. Both DIN 4150-3 and BS 7385-2 assess the maximum
 * of three orthogonal components, so one dead axis silently understates the
 * result and can only ever understate it.
 *
 * This is not hypothetical. Re-analysed against the criterion below, the spare
 * WTVB01-485 on this bench was excited 125 times above 0.05 g on Z and its
 * velocity output never left zero, while X responded 10 times out of 51 under
 * the same conditions. Same capture, same session, one axis mute.
 *
 * The comparison against a sibling axis is what makes it evidence. An axis that
 * reads zero on a still structure is correct, not broken - and that is most of
 * the time.
 *
 * Run it during commissioning, after any sensor swap, and after re-mounting.
 * It needs the structure excited - tap the sensor on each axis - and says so
 * rather than passing a sensor it never tested.
 */
class CheckAxes extends Command
{
    protected $signature = 'sensors:check-axes
                            {--sensor=SENSOR-001}
                            {--minutes=30}';

    protected $description = 'Prove every axis responds, so a dead one cannot read as a still building';

    /**
     * Acceleration amplitude above which an axis is genuinely being excited, in g.
     *
     * At rest this channel reads about 0.0074 g and is ALWAYS non-zero, so
     * counting non-zero samples measures nothing at all - the first version of
     * this check did exactly that and reported every quiet axis as excited.
     */
    private const EXCITED_G = 0.05;

    /** Excited samples needed before an axis's silence is evidence of anything. */
    private const MIN_EXCITED_SAMPLES = 20;

    public function handle(): int
    {
        $sensor = $this->option('sensor');
        $minutes = (int) $this->option('minutes');

        $rows = DB::select(<<<'SQL'
            SELECT channel_key,
                   count(*)                                   AS samples,
                   count(*) FILTER (WHERE value <> 0)         AS nonzero,
                   max(value)                                 AS peak
            FROM measurements
            WHERE sensor_id = ? AND time > now() - (? || ' minutes')::interval
              AND (channel_key LIKE 'vib_velocity%'
                OR channel_key LIKE 'vib_displacement%'
                OR channel_key LIKE 'vib_frequency%'
                OR channel_key LIKE 'accel_amplitude%')
            GROUP BY channel_key
        SQL, [$sensor, $minutes]);

        if ($rows === []) {
            $this->warn("No data for {$sensor} in the last {$minutes} minutes.");

            return self::SUCCESS;
        }

        $by = [];
        foreach ($rows as $r) {
            $by[$r->channel_key] = $r;
        }

        $this->line(sprintf('Sensor %s, last %d minutes', $sensor, $minutes));
        $this->newLine();

        $failures = 0;
        $untested = 0;

        foreach (['x', 'y', 'z'] as $axis) {
            // The only question that matters: while this axis was genuinely
            // being excited, did velocity ever report anything?
            //
            // Not "is velocity zero" - it is correctly zero on a still
            // structure, which is most of the time. And not "does it report a
            // frequency while velocity is zero" either: the device estimates
            // frequency from acceleration and does so at excitations below the
            // level at which it reports velocity, so that combination is normal
            // and an earlier version of this check condemned a healthy sensor
            // for it.
            $result = DB::selectOne(<<<'SQL'
                WITH s AS (
                    SELECT time,
                        max(value) FILTER (WHERE channel_key = ?) AS amp,
                        max(value) FILTER (WHERE channel_key = ?) AS vel
                    FROM measurements
                    WHERE sensor_id = ? AND time > now() - (? || ' minutes')::interval
                    GROUP BY time
                )
                SELECT count(*) FILTER (WHERE amp > ?)                        AS excited,
                       count(*) FILTER (WHERE amp > ? AND vel > 0)            AS responded,
                       max(vel) FILTER (WHERE amp > ?)                        AS peak_vel,
                       max(amp)                                               AS peak_amp
                FROM s
            SQL, [
                "accel_amplitude_{$axis}", "vib_velocity_{$axis}", $sensor, $minutes,
                self::EXCITED_G, self::EXCITED_G, self::EXCITED_G,
            ]);

            $label = strtoupper($axis);
            $excited = (int) ($result->excited ?? 0);
            $responded = (int) ($result->responded ?? 0);

            if ($excited < self::MIN_EXCITED_SAMPLES) {
                $this->warn(sprintf(
                    '  %s  UNTESTED — only %d sample(s) above %.2f g. Tap along this axis.',
                    $label, $excited, self::EXCITED_G,
                ));
                $untested++;
                continue;
            }

            if ($responded > 0) {
                $this->info(sprintf(
                    '  %s  responds — %d/%d excited samples reported velocity, peak %.2f mm/s',
                    $label, $responded, $excited, (float) $result->peak_vel,
                ));
                continue;
            }

            $this->error(sprintf(
                '  %s  FAULT — excited %d times up to %.2f g, and velocity never left zero',
                $label, $excited, (float) $result->peak_amp,
            ));
            $this->line('       The accelerometer sees the motion; the velocity output does not.');
            $failures++;
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error(sprintf('%d axis/axes failed.', $failures));
            $this->line('DIN 4150-3 and BS 7385-2 both assess the maximum of three orthogonal');
            $this->line('components. A dead axis can only understate that maximum, and it reads');
            $this->line('as a quiet building rather than as a fault. Do not commission this unit.');

            return self::FAILURE;
        }

        if ($untested > 0) {
            $this->warn(sprintf(
                '%d axis/axes were never excited, so this is not a pass.', $untested,
            ));
            $this->line('Tap the sensor along each axis in turn and run this again.');

            return self::SUCCESS;
        }

        $this->info('All three axes respond. Safe to commission.');

        return self::SUCCESS;
    }
}
