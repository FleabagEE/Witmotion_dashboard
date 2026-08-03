<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cross-checks displacement, velocity and frequency against each other.
 *
 * For sinusoidal motion v = 2*pi*f*A, so the device's three vibration outputs
 * are not independent: given any two, the third is determined. That makes them a
 * check on each other, and specifically a check on the *units* - which is the
 * one thing a register map cannot verify on its own.
 *
 * The case this exists for is real. The WTVB01-485 has a displacement range mode
 * with two settings, 60000 um at 1 um resolution and 600 um at 0.01 um. Changing
 * it in the vendor software changes what a count means by a factor of a hundred,
 * and nothing in the Modbus data announces it. An appliance still scaling counts
 * as micrometres after a switch to the fine mode would over-report displacement
 * a hundredfold, and the readings would look entirely plausible - just wrong by
 * two orders of magnitude, which is the difference between cosmetic cracking and
 * structural damage on any guideline table.
 *
 * A hundredfold error in A shows up as a hundredfold error in the implied
 * frequency, and the device reports frequency independently. So the mismatch is
 * visible without knowing which register holds the mode.
 */
class CheckUnitConsistency extends Command
{
    protected $signature = 'measurements:check-units
                            {--sensor=SENSOR-001}
                            {--minutes=60 : how far back to look for excitation}';

    protected $description = 'Verify displacement, velocity and frequency agree on their units';

    /** Ratio beyond which implied and measured frequency are not the same quantity. */
    private const TOLERANCE = 4.0;

    public function handle(): int
    {
        $sensor = $this->option('sensor');
        $minutes = (int) $this->option('minutes');

        // Simultaneous samples only. All three channels arrive in one Modbus
        // transaction and so share a timestamp; comparing peaks taken from
        // different moments would compare unrelated events.
        $rows = DB::select(<<<'SQL'
            WITH s AS (
                SELECT time,
                    max(value) FILTER (WHERE channel_key = 'vib_velocity_z')     AS v,
                    max(value) FILTER (WHERE channel_key = 'vib_displacement_z') AS d,
                    max(value) FILTER (WHERE channel_key = 'vib_frequency_z')    AS f
                FROM measurements
                WHERE sensor_id = ? AND time > now() - (? || ' minutes')::interval
                GROUP BY time
            )
            SELECT * FROM s WHERE v > 1 AND d > 0 AND f > 0 ORDER BY v DESC LIMIT 200
        SQL, [$sensor, $minutes]);

        if ($rows === []) {
            // Not a failure. With the structure at rest there is nothing to
            // check, and saying so beats inventing a verdict.
            $this->warn("No excitation in the last {$minutes} minutes - nothing to check.");
            $this->line('Tap or shake the sensor, then run this again.');

            return self::SUCCESS;
        }

        $ratios = [];
        foreach ($rows as $row) {
            // d is micrometres under the current profile; v is mm/s.
            $impliedHz = $row->v / (2 * M_PI * ($row->d / 1000));
            if ($impliedHz > 0) {
                $ratios[] = $impliedHz / $row->f;
            }
        }

        sort($ratios);
        $count = count($ratios);
        $median = $ratios[intdiv($count, 2)];
        $low = $ratios[intdiv($count, 4)];
        $high = $ratios[intdiv(3 * $count, 4)];

        $this->line(sprintf('%d simultaneous samples with excitation', count($rows)));
        $this->line(sprintf(
            'implied/measured frequency ratio: median %.2f, middle half %.2f to %.2f',
            $median, $low, $high,
        ));
        // The scatter is genuinely wide and saying so matters. A tap is a
        // transient, not a sinusoid, and the device computes its three outputs
        // over its own windows - so v = 2*pi*f*A holds only approximately on
        // real data. What the check relies on is not precision but distance: a
        // range-mode error moves the ratio by a factor of a hundred, which no
        // amount of this scatter can imitate.
        $this->line('(wide scatter is normal - a tap is not a sinusoid; the fault this');
        $this->line(' detects is a factor of 100, far outside that spread)');
        $this->newLine();

        if ($median >= 1 / self::TOLERANCE && $median <= self::TOLERANCE) {
            $this->info('Consistent. Displacement counts are micrometres, as the profile assumes.');

            return self::SUCCESS;
        }

        // Direction matters and is easy to get backwards. Displacement appears
        // in the denominator of the implied frequency, so a displacement that is
        // 100x too large makes the implied frequency 100x too SMALL - a ratio of
        // 0.01, not 100. Getting this the wrong way round would send an operator
        // looking for the opposite fault.
        if ($median < 1) {
            $this->error(sprintf(
                'Displacement appears to be %.0fx too large.', 1 / $median,
            ));
            $this->line('That is the signature of the 600 um / 0.01 um range mode while the');
            $this->line('profile still scales counts as micrometres. Displacement readings and');
            $this->line('any threshold derived from them are wrong by that factor.');
            $this->line('Fix: set the displacement scale to 0.01 in profiles/wtvb01-485.v1.yaml,');
            $this->line('or return the sensor to 60000 um / 1 um in the vendor software.');
        } else {
            $this->error(sprintf(
                'Displacement appears to be %.0fx too small.', $median,
            ));
            $this->line('Check the displacement scale in the profile against the range mode set');
            $this->line('on the device.');
        }

        return self::FAILURE;
    }
}
