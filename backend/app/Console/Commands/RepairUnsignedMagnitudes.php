<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs readings corrupted by the signed/unsigned decode fault (profile 1.0.0).
 *
 * Vibration velocity and displacement are unsigned magnitudes that were decoded
 * as signed until profile 1.1.0, so every value above 32767 counts came out
 * inverted. Those are exactly the large-amplitude samples a structural record
 * exists to hold.
 *
 * They are recoverable because the raw register words were stored alongside
 * every reading. That was the point of keeping them: a decode can be found to be
 * wrong later, and without the raw words the only correct response would have
 * been to discard the affected history.
 *
 * Idempotent, and narrow by construction: it only touches rows whose stored
 * value is negative on a channel that cannot be negative, and it recomputes from
 * the raw word rather than trying to invert the arithmetic.
 */
class RepairUnsignedMagnitudes extends Command
{
    protected $signature = 'measurements:repair-unsigned
                            {--apply : write the corrections; without this the command only reports}';

    protected $description = 'Recompute readings that the signed/unsigned decode fault inverted';

    /** Channel prefix => [scale, maximum], matching profile 1.1.0. */
    private const AFFECTED = [
        'vib_velocity_' => [0.01, 655.35],
        'vib_displacement_' => [1.0, 65535.0],
        'vib_frequency_' => [0.1, 300.0],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $totalFound = 0;
        $totalFixed = 0;

        $totalReflagged = 0;

        foreach (self::AFFECTED as $prefix => [$scale, $maximum]) {
            foreach (['x', 'y', 'z'] as $axis) {
                $channelKey = $prefix.$axis;

                $rows = DB::select(<<<'SQL'
                    SELECT time, sensor_id, value, raw_registers[1] AS raw
                    FROM measurements
                    WHERE channel_key = ? AND value < 0 AND raw_registers[1] IS NOT NULL
                SQL, [$channelKey]);

                if ($rows === []) {
                    continue;
                }

                $totalFound += count($rows);
                $this->line(sprintf(
                    '%-22s %d inverted reading(s), e.g. %.2f -> %.2f',
                    $channelKey,
                    count($rows),
                    $rows[0]->value,
                    $rows[0]->raw * $scale,
                ));

                if (! $apply) {
                    continue;
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    foreach ($chunk as $row) {
                        DB::update(<<<'SQL'
                            UPDATE measurements
                            SET value = ?, quality = 'good'
                            WHERE channel_key = ? AND sensor_id = ? AND time = ?
                        SQL, [$row->raw * $scale, $channelKey, $row->sensor_id, $row->time]);
                        $totalFixed++;
                    }
                }
            }
        }

        // The inverted values were only half of it. Profile 1.0.0 also capped
        // velocity at 120 mm/s, well under what the hardware emits, so genuine
        // large readings were marked implausible. They stay excluded from
        // analysis until re-flagged - and the spectrum endpoint reads only
        // 'good' rows, so the loudest part of a real event was the part being
        // thrown away.
        foreach (self::AFFECTED as $prefix => [$scale, $maximum]) {
            foreach (['x', 'y', 'z'] as $axis) {
                $channelKey = $prefix.$axis;

                $stale = DB::selectOne(<<<'SQL'
                    SELECT count(*) AS n FROM measurements
                    WHERE channel_key = ? AND quality = 'implausible'
                      AND value >= 0 AND value <= ?
                SQL, [$channelKey, $maximum]);

                if ((int) $stale->n === 0) {
                    continue;
                }

                $this->line(sprintf(
                    '%-22s %d reading(s) flagged implausible by the old %s bound, now within range',
                    $channelKey, $stale->n, $prefix === 'vib_velocity_' ? '120 mm/s' : 'range',
                ));
                $totalFound += (int) $stale->n;

                if ($apply) {
                    $totalReflagged += DB::update(<<<'SQL'
                        UPDATE measurements SET quality = 'good'
                        WHERE channel_key = ? AND quality = 'implausible'
                          AND value >= 0 AND value <= ?
                    SQL, [$channelKey, $maximum]);
                }
            }
        }

        if ($totalFound === 0) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->warn(sprintf(
                '%d reading(s) would be corrected. Re-run with --apply to write them.',
                $totalFound,
            ));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            '%d reading(s) recomputed from their raw registers, %d re-flagged as good.',
            $totalFixed, $totalReflagged,
        ));
        // Said plainly: the values are corrected, but they were recorded under a
        // profile version that is now known to have decoded them wrongly, and
        // the rows still carry that version. Anyone auditing the record should
        // be able to see that rather than find it silently rewritten.
        $this->warn('These rows still record profile_version 1.0.0, which is accurate: '
            .'that is the version they were captured under.');

        return self::SUCCESS;
    }
}
