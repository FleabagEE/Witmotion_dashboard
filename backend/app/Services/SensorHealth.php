<?php

namespace App\Services;

use App\Models\Sensor;
use Illuminate\Support\Facades\DB;

/**
 * Whether each sensor can still be believed.
 *
 * Separate from whether the structure is moving, and more fundamental: a dead
 * sensor and a perfectly still silo produce the same chart. Every flat line on
 * this dashboard has two explanations and only one of them is good news.
 *
 * WHAT IS ACTUALLY CHECKED
 * -----------------------
 *
 * Each of these is a fact the appliance can establish about the instrument
 * itself, without knowing anything about the structure:
 *
 *   reporting     - is data arriving at the configured rate
 *   gravity       - does |a| read 1 g at rest, which it must whatever the
 *                   orientation. The single best evidence an accelerometer is
 *                   working: a failed axis, a scaling fault or a loose mount all
 *                   move it off 1
 *   quality       - how much of what arrived the decoder itself rejected
 *   faults        - the device's own fault-diagnosis words
 *   calibration   - whether a correction has been fitted for this unit
 *   baseline      - whether it has a commissioning reference, without which no
 *                   movement can be measured at all
 *
 * WHAT IS DELIBERATELY NOT CHECKED
 * --------------------------------
 *
 * Nothing about tilt, movement or vibration. Those describe the structure, and
 * mixing them in would let a genuinely moving silo read as an unhealthy sensor
 * — which is the one confusion this page exists to prevent.
 */
class SensorHealth
{
    /**
     * Gravity magnitude tolerance before a unit is doubted.
     *
     * Uncalibrated units on this bench read 0.987 and 0.994; each accelerometer
     * is wrong in its own way and that is expected, not a fault. 5% is wide
     * enough to leave an uncalibrated sensor alone and narrow enough that a
     * failed axis - which typically costs 30% or more - cannot hide.
     */
    private const GRAVITY_TOLERANCE = 0.05;

    /** Silence beyond this is a fault rather than a gap. */
    private const SILENT_SECONDS = 120;

    /** @return array<string, mixed> */
    public function all(int $seconds = 120): array
    {
        return Sensor::where('status', 'active')
            ->orderBy('sensor_id')
            ->get()
            ->map(fn (Sensor $sensor) => $this->forSensor($sensor, $seconds))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function forSensor(Sensor $sensor, int $seconds = 120): array
    {
        $mounting = ($sensor->metadata ?? [])['mounting'] ?? [];

        $row = DB::selectOne(<<<'SQL'
            SELECT
                avg(value) FILTER (WHERE channel_key = 'accel_x')     AS x,
                avg(value) FILTER (WHERE channel_key = 'accel_y')     AS y,
                avg(value) FILTER (WHERE channel_key = 'accel_z')     AS z,
                avg(value) FILTER (WHERE channel_key = 'temperature') AS temperature,
                max(value) FILTER (WHERE channel_key LIKE 'fault_%')  AS fault_word,
                count(*)   FILTER (WHERE channel_key = 'accel_x')     AS samples,
                count(*)   FILTER (WHERE quality <> 'good')           AS rejected,
                count(*)                                              AS total,
                max(time)                                             AS latest
            FROM measurements
            WHERE sensor_id = ? AND time > now() - (? || ' seconds')::interval
        SQL, [$sensor->sensor_id, $seconds]);

        $checks = [];

        // --- reporting ---------------------------------------------------
        $silentFor = $row?->latest
            ? (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($row->latest), true)
            : null;

        $checks['reporting'] = $silentFor === null
            ? $this->fail('No data at all in this window.')
            : ($silentFor <= self::SILENT_SECONDS
                ? $this->pass(sprintf('%d samples, last %ds ago', (int) $row->samples, $silentFor))
                : $this->fail(sprintf('Silent for %ds. A dead sensor and a still structure '
                    .'look identical on a chart.', $silentFor)));

        // --- gravity -----------------------------------------------------
        $magnitude = null;

        if ($row && $row->x !== null) {
            $magnitude = sqrt($row->x ** 2 + $row->y ** 2 + $row->z ** 2);
            $error = abs($magnitude - 1.0);

            $checks['gravity'] = $error <= self::GRAVITY_TOLERANCE
                ? $this->pass(sprintf('%.4f g', $magnitude))
                : $this->fail(sprintf('%.4f g, %.1f%% from 1 g. An accelerometer at rest '
                    .'measures 1 g whatever way it faces.', $magnitude, $error * 100));
        } else {
            $checks['gravity'] = $this->unknown('No acceleration readings.');
        }

        // --- what the decoder rejected -------------------------------------
        $rejected = (int) ($row->rejected ?? 0);
        $total = (int) ($row->total ?? 0);
        $share = $total > 0 ? $rejected / $total : 0.0;

        $checks['readings_believed'] = $total === 0
            ? $this->unknown('Nothing to judge.')
            : ($share < 0.01
                ? $this->pass(sprintf('%d of %d rejected', $rejected, $total))
                : $this->warn(sprintf('%.1f%% rejected as out of range. Persistent '
                    .'out-of-range readings point at the scaling or the register map.',
                    $share * 100)));

        // --- the device's own opinion --------------------------------------
        $fault = $row?->fault_word;

        $checks['self_diagnosis'] = $fault === null
            ? $this->unknown('Fault words not polled.')
            : ((float) $fault === 0.0
                ? $this->pass('No fault reported')
                : $this->fail(sprintf('Device reports fault word %s', $fault)));

        // --- fitted correction ---------------------------------------------
        // Not a fault. An uncalibrated sensor is honest about being uncalibrated,
        // and tilt angles are unaffected because they normalise the vector.
        $calibrated = $magnitude !== null && abs($magnitude - 1.0) < 0.005;

        $checks['calibration'] = $calibrated
            ? $this->pass('Fitted')
            : $this->warn('Not fitted. Angles are unaffected; the 1 g health check is '
                .'less sensitive against an already-wrong baseline.');

        // --- commissioning reference ----------------------------------------
        $baseline = ($sensor->metadata ?? [])['tilt_baseline'] ?? null;

        $checks['baseline'] = $baseline
            ? $this->pass('Captured '.\Illuminate\Support\Carbon::parse($baseline['captured_at'])->toDateString())
            : $this->warn('Not captured. Movement cannot be measured without one.');

        return [
            'sensor_id' => $sensor->sensor_id,
            'position' => $mounting['position'] ?? null,
            'role' => $mounting['role'] ?? 'monitor',
            'port' => $mounting['port'] ?? null,
            'model' => $sensor->model?->model,
            'verification_status' => $sensor->model?->verification_status,
            'temperature' => $row?->temperature === null ? null : round((float) $row->temperature, 2),
            'gravity_magnitude' => $magnitude === null ? null : round($magnitude, 5),
            'silent_for_seconds' => $silentFor,
            'checks' => $checks,
            // One word for a wall display. `fail` beats `warn` beats `pass`,
            // because the worst thing true about a sensor is what matters.
            'status' => $this->worst($checks),
        ];
    }

    /** @param array<string, array{state:string}> $checks */
    private function worst(array $checks): string
    {
        $states = array_column($checks, 'state');

        if (in_array('fail', $states, true)) {
            return 'fail';
        }

        if (in_array('warn', $states, true)) {
            return 'warn';
        }

        return in_array('unknown', $states, true) ? 'unknown' : 'pass';
    }

    /** @return array{state:string, detail:string} */
    private function pass(string $detail): array
    {
        return ['state' => 'pass', 'detail' => $detail];
    }

    /** @return array{state:string, detail:string} */
    private function warn(string $detail): array
    {
        return ['state' => 'warn', 'detail' => $detail];
    }

    /** @return array{state:string, detail:string} */
    private function fail(string $detail): array
    {
        return ['state' => 'fail', 'detail' => $detail];
    }

    /** @return array{state:string, detail:string} */
    private function unknown(string $detail): array
    {
        return ['state' => 'unknown', 'detail' => $detail];
    }
}
