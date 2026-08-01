<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\Sensor;
use App\Support\StructuralVibration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the dashboard.
 *
 * Every response that carries a measured value also carries what is known about
 * its trustworthiness - profile verification, whether an alarm's thresholds were
 * confirmed by a person, when the sensor last spoke. A dashboard that shows a
 * number without that context invites somebody to act on it.
 */
class ReadController extends Controller
{
    /** How long without data before a sensor is treated as silent on the overview. */
    private const SILENT_AFTER_SECONDS = 120;

    public function overview(): JsonResponse
    {
        $now = now();
        $sensors = Sensor::with('model')->where('status', 'active')->get();

        $online = $sensors->filter(
            fn ($s) => $s->last_measurement_at
                && $s->last_measurement_at->diffInSeconds($now, absolute: true) <= self::SILENT_AFTER_SECONDS
        );

        $activeAlarms = AlarmEvent::where('state', 'active')->where('level', '!=', 'normal')->get();

        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'sensors' => [
                'total' => $sensors->count(),
                'online' => $online->count(),
                'silent' => $sensors->count() - $online->count(),
                // A sensor whose register map is unconfirmed is reporting
                // numbers nobody has validated. Worth surfacing on the front page.
                'unverified_profiles' => $sensors->filter(
                    fn ($s) => ! ($s->model?->isTrustworthy() ?? false)
                )->count(),
            ],
            'alarms' => [
                'active' => $activeAlarms->count(),
                'critical' => $activeAlarms->where('level', 'critical')->count(),
                'warning' => $activeAlarms->where('level', 'warning')->count(),
                'advisory' => $activeAlarms->where('level', 'advisory')->count(),
                'unacknowledged' => $activeAlarms->whereNull('acknowledged_at')->count(),
                // Raised from thresholds nobody has signed off. Shown, never sent.
                'provisional' => $activeAlarms->where('provisional', true)->count(),
            ],
            'appliances' => Appliance::get()->map(fn ($a) => [
                'appliance_id' => $a->appliance_id,
                'name' => $a->name,
                'status' => $a->status,
                'last_ingest_at' => $a->last_ingest_at?->toIso8601String(),
                'seconds_since_ingest' => $a->last_ingest_at
                    ? (int) $a->last_ingest_at->diffInSeconds($now, absolute: true)
                    : null,
            ]),
            'storage' => [
                'measurements' => (int) DB::table('measurements')->count(),
                'oldest' => DB::table('measurements')->min('time'),
            ],
            'standards' => [
                'structural_tables_status' => StructuralVibration::STATUS,
            ],
        ]);
    }

    public function sensors(): JsonResponse
    {
        $now = now();

        return response()->json([
            'data' => Sensor::with(['model', 'channels', 'appliance'])->get()->map(function ($sensor) use ($now) {
                $silentFor = $sensor->last_measurement_at
                    ? (int) $sensor->last_measurement_at->diffInSeconds($now, absolute: true)
                    : null;

                return [
                    'sensor_id' => $sensor->sensor_id,
                    'appliance_id' => $sensor->appliance?->appliance_id,
                    'model' => $sensor->model?->model,
                    'profile_version' => $sensor->model?->profile_version,
                    'verification_status' => $sensor->model?->verification_status,
                    'trustworthy' => $sensor->model?->isTrustworthy() ?? false,
                    'slave_id' => $sensor->slave_id,
                    'status' => $sensor->status,
                    'mount_location' => $sensor->mount_location,
                    'last_measurement_at' => $sensor->last_measurement_at?->toIso8601String(),
                    'silent_for_seconds' => $silentFor,
                    'online' => $silentFor !== null && $silentFor <= self::SILENT_AFTER_SECONDS,
                    'channel_count' => $sensor->channels->count(),
                ];
            }),
        ]);
    }

    public function channels(Request $request, string $sensorId): JsonResponse
    {
        $sensor = Sensor::with('channels')->where('sensor_id', $sensorId)->firstOrFail();

        return response()->json([
            'sensor_id' => $sensor->sensor_id,
            'data' => $sensor->channels->map(fn ($c) => [
                'channel_key' => $c->channel_key,
                'group_key' => $c->group_key,
                'label' => $c->label,
                'quantity' => $c->quantity,
                'unit' => $c->unit,
                'value_class' => $c->value_class,
                // Decoding provenance travels with the channel, so a value can
                // always be explained rather than merely displayed.
                'register_address' => $c->register_address,
                'scale' => $c->scale,
                'range' => ['min' => $c->range_min, 'max' => $c->range_max],
                'configured_hz' => $c->configured_hz,
            ]),
        ]);
    }

    /** Latest value per channel for one sensor. */
    public function latest(Request $request, string $sensorId): JsonResponse
    {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT ON (channel_key)
                   channel_key, value, unit, quality, source_type, time
            FROM measurements
            WHERE sensor_id = ?
              AND time > now() - interval '1 hour'
            ORDER BY channel_key, time DESC
        SQL, [$sensorId]);

        return response()->json([
            'sensor_id' => $sensorId,
            'data' => array_map(fn ($r) => [
                'channel_key' => $r->channel_key,
                'value' => $r->value === null ? null : (float) $r->value,
                'unit' => $r->unit,
                'quality' => $r->quality,
                'source_type' => $r->source_type,
                'at' => Carbon::parse($r->time)->toIso8601String(),
            ], $rows),
        ]);
    }

    /**
     * Time series for a channel.
     *
     * Reads the hourly rollup for long windows and raw samples for short ones.
     * A month of raw samples is millions of rows nobody can plot; the continuous
     * aggregate exists precisely so that request does not have to be refused.
     */
    public function series(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sensor_id' => ['required', 'string', 'max:80'],
            'channel_key' => ['required', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'max_points' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subHour();
        if ($from >= $to) {
            return response()->json(['message' => 'from must be before to'], 422);
        }

        $maxPoints = $data['max_points'] ?? 1000;
        $spanSeconds = $from->diffInSeconds($to);
        $useRollup = $spanSeconds > 6 * 3600;

        if ($useRollup) {
            $rows = DB::select(<<<'SQL'
                SELECT bucket AS t, avg_value AS value, min_value, max_value, samples
                FROM measurements_hourly
                WHERE sensor_id = ? AND channel_key = ? AND bucket BETWEEN ? AND ?
                ORDER BY bucket
                LIMIT ?
            SQL, [$data['sensor_id'], $data['channel_key'], $from, $to, $maxPoints]);
        } else {
            // Bucket raw samples so the response size is bounded by the window,
            // not by how fast the sensor happens to be polling.
            $bucketSeconds = max(1, (int) ceil($spanSeconds / $maxPoints));
            $rows = DB::select(<<<'SQL'
                SELECT time_bucket(make_interval(secs => ?), time) AS t,
                       avg(value) AS value, min(value) AS min_value,
                       max(value) AS max_value, count(*) AS samples
                FROM measurements
                WHERE sensor_id = ? AND channel_key = ? AND time BETWEEN ? AND ?
                GROUP BY t ORDER BY t
                LIMIT ?
            SQL, [$bucketSeconds, $data['sensor_id'], $data['channel_key'], $from, $to, $maxPoints]);
        }

        return response()->json([
            'sensor_id' => $data['sensor_id'],
            'channel_key' => $data['channel_key'],
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            // Stated rather than hidden: a rollup point is an hourly average, and
            // reading it as an instantaneous value would understate peaks.
            'resolution' => $useRollup ? 'hourly_rollup' : 'raw_bucketed',
            'data' => array_map(fn ($r) => [
                't' => Carbon::parse($r->t)->toIso8601String(),
                'value' => $r->value === null ? null : round((float) $r->value, 6),
                'min' => $r->min_value === null ? null : round((float) $r->min_value, 6),
                'max' => $r->max_value === null ? null : round((float) $r->max_value, 6),
                'samples' => (int) $r->samples,
            ], $rows),
        ]);
    }

    /**
     * Several channels over one window, in a single request.
     *
     * A waveform card plots three axes together; fetching them separately would
     * let the traces drift apart in time as each request lands at a slightly
     * different moment, which on a chart reads as a phase difference that is not
     * there.
     */
    public function multiSeries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sensor_id' => ['required', 'string', 'max:80'],
            'channels' => ['required', 'string', 'max:400'],
            'seconds' => ['nullable', 'integer', 'min:10', 'max:604800'],
            'max_points' => ['nullable', 'integer', 'min:10', 'max:2000'],
        ]);

        $channels = array_values(array_filter(array_map('trim', explode(',', $data['channels']))));
        // Raised to 16 so a whole dashboard fits in one request: splitting it
        // let each half land at a different moment, which on a chart reads as a
        // skew between cards that is not in the data.
        if (count($channels) > 16) {
            return response()->json(['message' => 'at most 16 channels per request'], 422);
        }

        $seconds = $data['seconds'] ?? 300;
        $maxPoints = $data['max_points'] ?? 300;
        $to = now();
        $from = $to->copy()->subSeconds($seconds);
        $useRollup = $seconds > 6 * 3600;
        $bucketSeconds = max(1, (int) ceil($seconds / $maxPoints));

        $series = [];
        foreach ($channels as $channelKey) {
            if ($useRollup) {
                $rows = DB::select(<<<'SQL'
                    SELECT bucket AS t, avg_value AS value, min_value, max_value
                    FROM measurements_hourly
                    WHERE sensor_id = ? AND channel_key = ? AND bucket BETWEEN ? AND ?
                    ORDER BY bucket LIMIT ?
                SQL, [$data['sensor_id'], $channelKey, $from, $to, $maxPoints]);
            } else {
                $rows = DB::select(<<<'SQL'
                    SELECT time_bucket(make_interval(secs => ?), time) AS t,
                           avg(value) AS value, min(value) AS min_value, max(value) AS max_value
                    FROM measurements
                    WHERE sensor_id = ? AND channel_key = ? AND time BETWEEN ? AND ?
                    GROUP BY t ORDER BY t LIMIT ?
                SQL, [$bucketSeconds, $data['sensor_id'], $channelKey, $from, $to, $maxPoints]);
            }

            $series[$channelKey] = array_map(fn ($r) => [
                't' => Carbon::parse($r->t)->valueOf(),
                'v' => $r->value === null ? null : round((float) $r->value, 6),
                'lo' => $r->min_value === null ? null : round((float) $r->min_value, 6),
                'hi' => $r->max_value === null ? null : round((float) $r->max_value, 6),
            ], $rows);
        }

        return response()->json([
            'sensor_id' => $data['sensor_id'],
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            // Stated so a card can say when it is showing averages rather than
            // samples: an hourly mean flattens exactly the peaks that matter.
            'resolution' => $useRollup ? 'hourly_rollup' : 'raw_bucketed',
            'bucket_seconds' => $useRollup ? 3600 : $bucketSeconds,
            'series' => $series,
        ]);
    }

    public function alarms(Request $request): JsonResponse
    {
        $query = AlarmEvent::with('definition')->orderByDesc('raised_at');

        if ($request->string('state')->isNotEmpty()) {
            $query->where('state', $request->string('state'));
        }
        if ($request->boolean('unacknowledged_only')) {
            $query->whereNull('acknowledged_at');
        }

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->definition?->name,
                'sensor_id' => $e->sensor_id,
                'channel_key' => $e->channel_key,
                'level' => $e->level,
                'peak_level' => $e->peak_level,
                'state' => $e->state,
                'value' => $e->trigger_value,
                'peak_value' => $e->peak_value,
                'threshold' => $e->threshold,
                'unit' => $e->unit,
                'raised_at' => $e->raised_at?->toIso8601String(),
                'cleared_at' => $e->cleared_at?->toIso8601String(),
                'acknowledged_at' => $e->acknowledged_at?->toIso8601String(),
                // The dashboard must render these differently: a provisional
                // alarm fired against numbers nobody has verified.
                'provisional' => $e->provisional,
                'actionable' => $e->isActionable(),
                'thresholds_confirmed_by' => $e->definition?->thresholds_confirmed_by,
            ]),
        ]);
    }
}
