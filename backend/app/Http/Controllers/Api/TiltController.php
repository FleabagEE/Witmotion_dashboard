<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sensor;
use App\Services\TiltMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settlement monitoring: has the structure moved?
 *
 * Everything here is reported against a commissioning baseline rather than
 * against zero. A silo built 0.4 degrees off plumb is fine and stays fine; the
 * same silo drifting to 1.4 over a winter is the thing worth knowing.
 *
 * The response carries three numbers where a naive one would carry one:
 * the raw deviation, the part temperature accounts for, and the remainder.
 * Reporting only the remainder would hide how much was explained away, and a
 * compensation nobody can inspect is a place for a real signal to disappear.
 */
class TiltController extends Controller
{
    public function __invoke(Request $request, TiltMonitor $monitor): JsonResponse
    {
        $data = $request->validate([
            'sensor_id' => ['nullable', 'string'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $days = $data['days'] ?? 7;

        $sensors = Sensor::query()
            ->when($data['sensor_id'] ?? null, fn ($q, $id) => $q->where('sensor_id', $id))
            ->where('status', 'active')
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'window_days' => $days,
            'sensors' => $sensors->map(fn ($sensor) => $this->forSensor($sensor, $monitor, $days))->values(),
        ]);
    }

    private function forSensor(Sensor $sensor, TiltMonitor $monitor, int $days): array
    {
        $baseline = ($sensor->metadata ?? [])['tilt_baseline'] ?? null;

        return [
            'sensor_id' => $sensor->sensor_id,
            'verification_status' => $sensor->model?->verification_status,
            'baseline' => $baseline,
            // How it was bolted down. Kept for the record even though the
            // per-axis breakdown it labelled is gone: knowing which way the unit
            // faces still matters when somebody goes to inspect it.
            'mounting' => ($sensor->metadata ?? [])['mounting'] ?? null,
            'deviation' => $baseline ? $monitor->deviation($sensor->sensor_id, $baseline) : null,
            // Refitted on every request rather than cached with the baseline.
            // The relationship is learned from accumulating data, and a model
            // frozen at commissioning would never improve as the seasons widen
            // the temperature range it has seen.
            // Bounded by commissioning, like the series. Fitted over the whole
            // window it kept re-reading the bench re-orientations that happened
            // before the sensor was ever installed, reporting a slope of
            // -1.22 deg/degC and refusing itself - correctly, but permanently,
            // because that history never leaves a seven-day window.
            'thermal_model' => $monitor->thermalModel(
                $sensor->sensor_id,
                $this->modelHours($baseline, $days),
            ),
            'series' => $this->series($sensor->sensor_id, $days, $baseline),
        ];
    }

    /**
     * Hours to fit the thermal model over: the window, or the time since
     * commissioning, whichever is shorter.
     */
    private function modelHours(?array $baseline, int $days): int
    {
        $hours = $days * 24;

        if (! isset($baseline['captured_at'])) {
            return $hours;
        }

        $since = (int) ceil(
            \Illuminate\Support\Carbon::parse($baseline['captured_at'])->diffInMinutes(now()) / 60,
        );

        // At least one hour, so a sensor commissioned minutes ago asks a
        // well-formed question and gets "not enough data" rather than a
        // zero-length window.
        return max(1, min($hours, $since));
    }

    /**
     * Tilt and temperature over the window, bucketed for the timescale.
     *
     * Settlement is watched over months, so the bucket is chosen from the window
     * rather than fixed. A day of five-minute buckets is 288 points; a year of
     * them would be 105 000, which no chart wants and no eye can read.
     */
    private function series(string $sensorId, int $days, ?array $baseline): array
    {
        [$bucket, $bucketSeconds] = match (true) {
            $days <= 2  => ['5 minutes', 300],
            $days <= 14 => ['1 hour', 3600],
            $days <= 90 => ['6 hours', 21600],
            default     => ['1 day', 86400],
        };

        // Averaged from quiet minutes only, the same rule the headline deviation
        // and the thermal model use.
        //
        // Plotting every bucket raw made the page misleading to look at. A few
        // minutes of handling put 13 degree spikes in the trace, the y-axis
        // stretched to fit them, and the settlement signal - which lives in
        // hundredths of a degree - was crushed into a flat line on the zero
        // gridline. The chart was scaled by the disturbances and showed none of
        // the measurement.
        //
        // Disturbed buckets are not hidden: they come back as null so the line
        // breaks, and disturbed_minutes drives a shaded band on the chart. The
        // gap is visible, it just no longer sets the scale.
        //
        // That alone was not enough. The 10 degree excursions in the bench record
        // were not vibration - they were the sensor sitting perfectly still at a
        // tilted angle during a tilt test. Quiet, and genuinely 10 degrees off.
        // No amplitude filter can catch that, because nothing was shaking.
        //
        // The principle that does catch it: movement from a baseline is undefined
        // before that baseline was captured. Plotting it for earlier times
        // compares the record against a reference that did not yet exist, and
        // here that record is deliberate bench testing. So deviation starts at
        // commissioning. Absolute tilt and temperature still cover the whole
        // window - the history is kept, it just stops setting the scale of a
        // number it cannot describe.
        $commissionedAt = isset($baseline['captured_at'])
            ? \Illuminate\Support\Carbon::parse($baseline['captured_at'])
            : null;

        $rows = DB::select(<<<SQL
            WITH m AS (
                SELECT time_bucket('1 minute', time) AS b,
                       avg(value) FILTER (WHERE channel_key = 'incl_tilt')   AS tilt,
                       avg(value) FILTER (WHERE channel_key = 'incl_roll')   AS roll,
                       avg(value) FILTER (WHERE channel_key = 'incl_pitch')  AS pitch,
                       avg(value) FILTER (WHERE channel_key = 'temperature') AS temp,
                       max(value) FILTER (WHERE channel_key = 'accel_amplitude_x') AS amp
                FROM measurements
                WHERE sensor_id = ? AND time > now() - (? || ' days')::interval
                GROUP BY b
            ), q AS (
                SELECT *, (amp IS NULL OR amp < 0.02) AS quiet FROM m
            )
            SELECT time_bucket('{$bucket}', b) AS t,
                   avg(tilt)  FILTER (WHERE quiet) AS tilt,
                   -- Filtered by minute, not by bucket. Excluding whole buckets
                   -- that straddle the commissioning instant threw away every
                   -- point for the first hour after capture, which left an empty
                   -- chart - a different way of showing nothing.
                   avg(tilt)  FILTER (WHERE quiet AND b >= ?) AS tilt_commissioned,
                   avg(roll)  FILTER (WHERE quiet) AS roll,
                   avg(pitch) FILTER (WHERE quiet) AS pitch,
                   -- Temperature is deliberately NOT filtered. It stays a true
                   -- reading of the chip while the sensor is handled, and the
                   -- whole point of the second axis is to compare movement
                   -- against it - a trace full of holes would defeat that.
                   avg(temp) AS temperature,
                   count(*) FILTER (WHERE NOT quiet) AS disturbed_minutes,
                   count(*) AS total_minutes
            FROM q
            WHERE tilt IS NOT NULL OR temp IS NOT NULL
            GROUP BY t ORDER BY t
        SQL, [$sensorId, $days, $commissionedAt?->toDateTimeString() ?? '-infinity']);

        $baselineTilt = (float) ($baseline['tilt'] ?? 0);

        return [
            'bucket' => $bucket,
            'bucket_seconds' => $bucketSeconds,
            'commissioned_at' => $commissionedAt?->toIso8601String(),
            'points' => array_map(fn ($r) => [
                't' => \Illuminate\Support\Carbon::parse($r->t)->valueOf(),
                'tilt' => $r->tilt === null ? null : round((float) $r->tilt, 4),
                'roll' => $r->roll === null ? null : round((float) $r->roll, 4),
                'pitch' => $r->pitch === null ? null : round((float) $r->pitch, 4),
                'temperature' => $r->temperature === null ? null : round((float) $r->temperature, 2),
                // Deviation is what an operator reads; absolute tilt is context.
                'deviation' => $r->tilt_commissioned === null || $baseline === null
                    ? null
                    : round((float) $r->tilt_commissioned - $baselineTilt, 4),
                // True where the bucket predates commissioning entirely. Drives a
                // shaded band, so the excluded history is visible as a period
                // rather than silently absent.
                'pre_commissioning' => $commissionedAt !== null && $r->tilt_commissioned === null,
                // Carried so a step in the trace can be told from settlement.
                // Somebody leaning on the silo moves the reading too.
                'disturbed' => (int) $r->disturbed_minutes > 0,
                'disturbed_minutes' => (int) $r->disturbed_minutes,
                'total_minutes' => (int) $r->total_minutes,
            ], $rows),
        ];
    }
}
