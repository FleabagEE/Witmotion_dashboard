<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Long-term tilt monitoring: has the structure actually moved?
 *
 * This answers a different question from vibration monitoring, and the naive
 * version of it does not work.
 *
 * A silo does not care about absolute tilt. One built 0.4 degrees off plumb is
 * fine and stays fine; the same silo drifting from 0.4 to 1.4 degrees over a
 * winter is a settlement problem. So everything here is measured against a
 * BASELINE captured at commissioning, never against zero.
 *
 * THE PROBLEM THAT DOMINATES EVERYTHING
 * -------------------------------------
 *
 * A MEMS accelerometer's zero point moves with temperature. Measured on the
 * bench unit: tilt correlates with chip temperature at r = +0.755, slope
 * +0.0457 deg/degC. On an outdoor silo cycling 20 degC between night and
 * afternoon that is nearly a degree of apparent tilt appearing and
 * disappearing every day - larger than the settlement anybody is looking for.
 *
 * Uncompensated, the instrument would report the silo leaning each afternoon
 * and recovering each night, and a real slow movement would be buried inside
 * that swing.
 *
 * So tilt is modelled as a linear function of temperature over a learning
 * window, and what gets reported and alarmed is the RESIDUAL: the part of the
 * movement temperature does not explain. That is the part that can be
 * settlement.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 *
 * It cannot separate settlement from a thermal effect that is genuinely
 * non-linear, and it cannot compensate for the structure itself expanding in
 * the heat - a concrete silo really does lean slightly when one side is in the
 * sun, and that is a real movement of the structure, not an instrument error.
 * Distinguishing "the silo is warm" from "the silo is failing" needs the
 * seasonal record, which is why the baseline model is kept and reported rather
 * than silently applied.
 */
class TiltMonitor
{
    /** Below this correlation, temperature is not worth compensating for. */
    private const SIGNIFICANT_CORRELATION = 0.4;

    /** Samples needed before a temperature model means anything. */
    private const MIN_SAMPLES = 30;

    /**
     * Tilt range, in degrees, above which the window is assumed to contain a
     * physical re-orientation rather than drift.
     *
     * This guard exists because its absence produced a confident lie. Fitted
     * over a bench day in which the sensor was picked up and set down several
     * times, the model returned a slope of -1.22 deg/degC - implying 24 degrees
     * of apparent tilt across a normal day/night swing - and reported itself as
     * usable. It was fitting re-orientations against whatever the temperature
     * happened to be doing.
     *
     * A sensor bolted to a silo does not move a degree in a week. If the window
     * shows more, something moved it, and no thermal model can be separated from
     * that.
     */
    private const MAX_TILT_RANGE_DEG = 1.0;

    /**
     * Normalise an acceleration vector, or null if it has no direction.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public static function unitVector(float $x, float $y, float $z): ?array
    {
        $magnitude = sqrt($x * $x + $y * $y + $z * $z);

        if ($magnitude < 0.5) {
            // Well below 1 g at rest: a missing axis, or the sensor in free
            // fall, and either way the direction means nothing.
            return null;
        }

        return [$x / $magnitude, $y / $magnitude, $z / $magnitude];
    }

    /**
     * Angle in degrees between two unit vectors.
     *
     * THIS IS THE MEASUREMENT, and `incl_tilt` is not.
     *
     * incl_tilt is acos(az/|a|) - the angle between the sensor's Z axis and
     * gravity. Lying flat on a bench that is a fine description of "how far off
     * level is it". Bolted to a vertical silo wall it is not, because Z then
     * points horizontally and the formula becomes blind to an entire axis of
     * rotation: a silo leaning sideways relative to the sensor rotates it about
     * its own Z, which leaves az unchanged and incl_tilt reading exactly 90
     * degrees no matter how far it goes.
     *
     * The angle between the current gravity direction and the commissioned one
     * asks "how far has this sensor rotated from where it was bolted", which is
     * the question settlement monitoring is actually asking, and it gives the
     * same answer for every mounting orientation.
     *
     * MAGNITUDE ONLY, AND THAT IS NOT A CHOICE
     * ----------------------------------------
     *
     * The WTVB01-485 reports the absolute value of each acceleration component;
     * the sign is not transmitted. Measured directly: tipping the unit one way
     * and then the opposite way both gave accel_x = +0.5152 and +0.4307. See
     * docs/known-limitations.md.
     *
     * This angle survives that rectification. A lean of +0.3 deg and one of
     * -0.3 deg both compute to 0.3000 deg, which is correct - and is also
     * precisely the information that is gone. How far, never which way.
     *
     * @param array{0:float,1:float,2:float} $a
     * @param array{0:float,1:float,2:float} $b
     */
    public static function angleBetween(array $a, array $b): float
    {
        $dot = $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];

        return rad2deg(acos(max(-1.0, min(1.0, $dot))));
    }

    /**
     * Fit tilt against temperature over a window.
     *
     * @return array{samples:int, correlation:float, slope:float, intercept:float,
     *               temp_range:float, tilt_range:float, significant:bool}|null
     */
    public function thermalModel(string $sensorId, int $hours = 168): ?array
    {
        $rows = DB::select(<<<'SQL'
            WITH s AS (
                SELECT time_bucket('5 minutes', time) AS b,
                       avg(value) FILTER (WHERE channel_key = 'incl_tilt')   AS tilt,
                       avg(value) FILTER (WHERE channel_key = 'temperature') AS temp,
                       max(value) FILTER (WHERE channel_key = 'accel_amplitude_x') AS amp
                FROM measurements
                WHERE sensor_id = ? AND time > now() - (? || ' hours')::interval
                GROUP BY b
            )
            SELECT tilt, temp FROM s
            -- Quiet periods only. Tilt read while somebody is handling the
            -- sensor describes the handling, not the structure.
            WHERE tilt IS NOT NULL AND temp IS NOT NULL AND (amp IS NULL OR amp < 0.02)
            ORDER BY b
        SQL, [$sensorId, $hours]);

        if (count($rows) < self::MIN_SAMPLES) {
            return null;
        }

        $tilt = array_map(fn ($r) => (float) $r->tilt, $rows);
        $temp = array_map(fn ($r) => (float) $r->temp, $rows);
        $n = count($tilt);

        $meanTilt = array_sum($tilt) / $n;
        $meanTemp = array_sum($temp) / $n;

        $cov = $varTilt = $varTemp = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dTilt = $tilt[$i] - $meanTilt;
            $dTemp = $temp[$i] - $meanTemp;
            $cov += $dTilt * $dTemp;
            $varTilt += $dTilt * $dTilt;
            $varTemp += $dTemp * $dTemp;
        }

        $correlation = ($varTilt > 0 && $varTemp > 0)
            ? $cov / sqrt($varTilt * $varTemp)
            : 0.0;
        $slope = $varTemp > 0 ? $cov / $varTemp : 0.0;

        return [
            'samples' => $n,
            'correlation' => round($correlation, 4),
            'slope' => round($slope, 6),
            'intercept' => round($meanTilt - $slope * $meanTemp, 6),
            'temp_range' => round(max($temp) - min($temp), 2),
            'tilt_range' => round(max($tilt) - min($tilt), 4),
            // A model fitted over a narrow temperature range extrapolates badly.
            // Reported so nobody trusts a slope measured across half a degree.
            'significant' => abs($correlation) >= self::SIGNIFICANT_CORRELATION
                && (max($temp) - min($temp)) >= 2.0
                && (max($tilt) - min($tilt)) <= self::MAX_TILT_RANGE_DEG,
            'disturbed' => (max($tilt) - min($tilt)) > self::MAX_TILT_RANGE_DEG,
        ];
    }

    /**
     * Current tilt against the baseline, with temperature removed.
     *
     * @return array<string, mixed>
     */
    public function deviation(string $sensorId, array $baseline, int $minutes = 60): array
    {
        // Disturbed minutes are dropped before averaging, not after.
        //
        // The first version averaged the window raw, and two minutes of somebody
        // handling the sensor pulled an hourly mean of 0.008 deg up to 0.646 -
        // eighty times the real movement, from a disturbance that had ended half
        // an hour earlier. On a silo that is a person leaning on it moving the
        // number the alarm reads. The same amp < 0.02 g test that guards the
        // thermal model guards this.
        $now = DB::selectOne(<<<'SQL'
            WITH m AS (
                SELECT time_bucket('1 minute', time) AS b,
                       avg(value) FILTER (WHERE channel_key = 'incl_tilt')   AS tilt,
                       avg(value) FILTER (WHERE channel_key = 'incl_roll')   AS roll,
                       avg(value) FILTER (WHERE channel_key = 'incl_pitch')  AS pitch,
                       avg(value) FILTER (WHERE channel_key = 'temperature') AS temp,
                       max(value) FILTER (WHERE channel_key = 'accel_amplitude_x') AS amp,
                       avg(value) FILTER (WHERE channel_key = 'accel_x')     AS gx,
                       avg(value) FILTER (WHERE channel_key = 'accel_y')     AS gy,
                       avg(value) FILTER (WHERE channel_key = 'accel_z')     AS gz,
                       count(*) FILTER (WHERE channel_key = 'incl_tilt')     AS n
                FROM measurements
                WHERE sensor_id = ? AND time > now() - (? || ' minutes')::interval
                GROUP BY b
            )
            SELECT avg(tilt)  FILTER (WHERE quiet) AS tilt,
                   avg(roll)  FILTER (WHERE quiet) AS roll,
                   avg(pitch) FILTER (WHERE quiet) AS pitch,
                   avg(temp)  FILTER (WHERE quiet) AS temp,
                   avg(gx)    FILTER (WHERE quiet) AS gx,
                   avg(gy)    FILTER (WHERE quiet) AS gy,
                   avg(gz)    FILTER (WHERE quiet) AS gz,
                   coalesce(sum(n) FILTER (WHERE quiet), 0)     AS samples,
                   count(*)   FILTER (WHERE NOT quiet)          AS disturbed_minutes,
                   count(*)                                     AS total_minutes
            FROM (SELECT *, (amp IS NULL OR amp < 0.02) AS quiet FROM m WHERE tilt IS NOT NULL) q
        SQL, [$sensorId, $minutes]);

        if (! $now || $now->samples < 10) {
            // Distinguished, because "the sensor is being worked on" and "the
            // sensor is dead" call for different responses and both used to
            // arrive here as the same sentence.
            $wasDisturbed = $now && $now->disturbed_minutes > 0;

            return [
                'available' => false,
                'reason' => $wasDisturbed
                    ? sprintf(
                        'sensor disturbed for %d of the last %d minutes - no quiet data to average',
                        $now->disturbed_minutes,
                        $minutes,
                    )
                    : 'not enough recent data',
                'disturbed_minutes' => $wasDisturbed ? (int) $now->disturbed_minutes : 0,
            ];
        }

        // Preferred: the angle the sensor has rotated through since it was
        // commissioned, which is mounting-independent. Falls back to the
        // difference in reported tilt for baselines captured before the gravity
        // vector was stored - those are still usable, just blind on a wall.
        $baseGravity = isset($baseline['gravity']) ? array_values($baseline['gravity']) : null;
        $nowGravity = self::unitVector((float) $now->gx, (float) $now->gy, (float) $now->gz);

        if ($baseGravity !== null && $nowGravity !== null) {
            $rawDeviation = self::angleBetween($nowGravity, $baseGravity);
            $method = 'gravity_vector';
        } else {
            $rawDeviation = (float) $now->tilt - (float) ($baseline['tilt'] ?? 0);
            $method = 'reported_tilt';
        }

        // Temperature correction, only when the model earned it. Applying a
        // slope fitted across half a degree of indoor variation to a silo in
        // February would inject more error than it removes.
        $model = $baseline['thermal_model'] ?? null;
        $corrected = $rawDeviation;
        $thermalPart = 0.0;

        if ($model && ($model['significant'] ?? false)) {
            $thermalPart = $model['slope'] * ((float) $now->temp - (float) ($baseline['temp'] ?? 0));
            $corrected = $rawDeviation - $thermalPart;
        }

        return [
            'available' => true,
            // Which measure produced the movement figure. A 'reported_tilt'
            // baseline cannot see rotation about the sensor's own Z axis, so on
            // a wall-mounted unit it under-reports; the page says so rather than
            // presenting both as the same number.
            'method' => $method,
            'samples' => (int) $now->samples,
            // Reported so an operator can tell a quiet hour from one that was
            // mostly thrown away. A deviation averaged over three surviving
            // minutes deserves less trust than one averaged over sixty.
            'disturbed_minutes' => (int) $now->disturbed_minutes,
            'window_minutes' => $minutes,
            'tilt_now' => round((float) $now->tilt, 4),
            'roll_now' => round((float) $now->roll, 4),
            'pitch_now' => round((float) $now->pitch, 4),
            'temperature_now' => round((float) $now->temp, 2),
            'baseline_tilt' => round((float) ($baseline['tilt'] ?? 0), 4),
            'baseline_temp' => round((float) ($baseline['temp'] ?? 0), 2),
            'raw_deviation' => round($rawDeviation, 4),
            // What temperature is judged to account for. Reported separately so
            // an operator can see how much of the movement was explained away
            // rather than having it silently removed.
            'thermal_component' => round($thermalPart, 4),
            'corrected_deviation' => round($corrected, 4),
            'compensated' => (bool) ($model['significant'] ?? false),
        ];
    }

    /**
     * Correlation time of the sensor's tilt output, in seconds.
     *
     * Measured on the bench unit by tilting it and watching the approach to the
     * new angle: 63.2% at 9 s, 86.5% at 20 s, 95% at 28 s, 99% at 44 s. That is
     * a first-order response with a time constant near 9 seconds, applied
     * inside the sensor and not defeatable from the bus.
     */
    private const TILT_CORRELATION_SECONDS = 9.0;

    /**
     * Angular resolution available by averaging.
     *
     * At small angles a tilt error is about the acceleration error divided by g,
     * so one LSB of a +/-16 g 16-bit accelerometer - 0.000488 g - is roughly
     * 0.028 degrees. Averaging N independent samples improves that as 1/sqrt(N),
     * which is the whole reason a settlement monitor should integrate over
     * minutes rather than react in milliseconds.
     *
     * THE WORD THAT DOES THE WORK IS "INDEPENDENT"
     * --------------------------------------------
     *
     * The naive form of this divides by the square root of the sample count and
     * reports a resolution the instrument does not have. Sampled at 9 Hz through
     * a filter with a 9 second time constant, consecutive readings are very
     * nearly the same reading: ten minutes yields about 5200 samples but only
     * around 33 independent ones. The naive answer for that window was
     * 0.00039 degrees; the honest one is roughly 0.005.
     *
     * For an exponentially correlated process the variance of the mean over a
     * window T goes as 2*tau/T, so the effective count is T/(2*tau) - and the
     * sample count only sets the ceiling.
     *
     * The distinction does not endanger the 3 degree silo threshold, which it
     * clears by a factor of several hundred either way. It matters because the
     * number is displayed next to a baseline and would otherwise invite somebody
     * to believe a movement of 0.001 degrees meant something.
     */
    public function resolution(int $samples, ?float $durationSeconds = null): array
    {
        $lsbG = 16.0 / 32768.0;
        $singleSample = rad2deg($lsbG);

        $effective = $samples;
        if ($durationSeconds !== null && $durationSeconds > 0) {
            $independent = $durationSeconds / (2 * self::TILT_CORRELATION_SECONDS);
            $effective = (int) max(1, min($samples, floor($independent)));
        }

        return [
            'single_sample_deg' => round($singleSample, 4),
            'averaged_deg' => round($singleSample / max(sqrt($effective), 1), 5),
            'samples' => $samples,
            // Reported alongside, so the gap between "how much data" and "how
            // much information" is visible rather than being quietly folded
            // into a single optimistic figure.
            'effective_samples' => $effective,
            'correlation_seconds' => self::TILT_CORRELATION_SECONDS,
        ];
    }
}
