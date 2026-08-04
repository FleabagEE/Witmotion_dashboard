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
            'deviation' => $baseline ? $monitor->deviation($sensor->sensor_id, $baseline) : null,
            // Refitted on every request rather than cached with the baseline.
            // The relationship is learned from accumulating data, and a model
            // frozen at commissioning would never improve as the seasons widen
            // the temperature range it has seen.
            'thermal_model' => $monitor->thermalModel($sensor->sensor_id, $days * 24),
            'series' => $this->series($sensor->sensor_id, $days, $baseline),
        ];
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
        $bucket = match (true) {
            $days <= 2 => '5 minutes',
            $days <= 14 => '1 hour',
            $days <= 90 => '6 hours',
            default => '1 day',
        };

        $rows = DB::select(<<<SQL
            SELECT time_bucket('{$bucket}', time) AS t,
                   avg(value) FILTER (WHERE channel_key = 'incl_tilt')   AS tilt,
                   avg(value) FILTER (WHERE channel_key = 'incl_roll')   AS roll,
                   avg(value) FILTER (WHERE channel_key = 'incl_pitch')  AS pitch,
                   avg(value) FILTER (WHERE channel_key = 'temperature') AS temperature,
                   max(value) FILTER (WHERE channel_key = 'accel_amplitude_x') AS disturbance
            FROM measurements
            WHERE sensor_id = ? AND time > now() - (? || ' days')::interval
            GROUP BY t ORDER BY t
        SQL, [$sensorId, $days]);

        $baselineTilt = (float) ($baseline['tilt'] ?? 0);

        return [
            'bucket' => $bucket,
            'points' => array_map(fn ($r) => [
                't' => \Illuminate\Support\Carbon::parse($r->t)->valueOf(),
                'tilt' => $r->tilt === null ? null : round((float) $r->tilt, 4),
                'roll' => $r->roll === null ? null : round((float) $r->roll, 4),
                'pitch' => $r->pitch === null ? null : round((float) $r->pitch, 4),
                'temperature' => $r->temperature === null ? null : round((float) $r->temperature, 2),
                // Deviation is what an operator reads; absolute tilt is context.
                'deviation' => $r->tilt === null || $baseline === null
                    ? null
                    : round((float) $r->tilt - $baselineTilt, 4),
                // Carried so a step in the trace can be told from settlement.
                // Somebody leaning on the silo moves the reading too.
                'disturbed' => $r->disturbance !== null && (float) $r->disturbance > 0.05,
            ], $rows),
        ];
    }
}
