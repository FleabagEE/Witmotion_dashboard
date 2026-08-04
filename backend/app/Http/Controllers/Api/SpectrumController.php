<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Sensor;
use App\Services\SpectrumAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Frequency-domain view of one channel.
 *
 * Two independent answers travel together in every response, because they come
 * from different places and have very different reach:
 *
 *   - what the sensor reports, computed inside the device from its own
 *     high-rate sampling, valid to 300 Hz and unaffected by our polling;
 *   - what we can derive from what we sampled, valid only to 0.4 * fs - about
 *     3 Hz on an 8 Hz channel.
 *
 * Presenting only the second would understate the appliance badly. Presenting
 * only the first would hide that our own record cannot corroborate it. Both,
 * side by side, is the honest answer.
 */
class SpectrumController extends Controller
{
    /** Raw rows are capped before analysis; the analyser reports any decimation. */
    private const MAX_ROWS = 20000;

    public function __invoke(Request $request, SpectrumAnalyzer $analyzer): JsonResponse
    {
        $data = $request->validate([
            'sensor_id' => ['required', 'string'],
            'channel_key' => ['required', 'string'],
            'seconds' => ['nullable', 'integer', 'min:10', 'max:86400'],
            'requested_hz' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
        ]);

        $sensor = Sensor::with('model')->where('sensor_id', $data['sensor_id'])->firstOrFail();
        $seconds = $data['seconds'] ?? 300;
        $from = now()->subSeconds($seconds);

        $rows = DB::select(<<<'SQL'
            SELECT extract(epoch from time) AS t, value
            FROM measurements
            WHERE sensor_id = ? AND channel_key = ? AND time >= ? AND value IS NOT NULL
              AND quality = 'good'
            ORDER BY time
            LIMIT ?
        SQL, [$data['sensor_id'], $data['channel_key'], $from, self::MAX_ROWS]);

        $samples = array_map(fn ($r) => ['t' => (float) $r->t, 'v' => (float) $r->value], $rows);
        $result = $analyzer->analyse($samples, isset($data['requested_hz']) ? (float) $data['requested_hz'] : null);

        $channel = Channel::where('channel_key', $data['channel_key'])->first();

        return response()->json([
            'sensor_id' => $data['sensor_id'],
            'channel_key' => $data['channel_key'],
            'unit' => $channel?->unit,
            'window_seconds' => $seconds,
            'analysis' => $result,
            'device_reported' => $this->deviceReported($data['sensor_id'], $data['channel_key'], $from),
            // Carried on every response for the same reason the live view
            // carries it: a spectrum from a sensor whose register map was never
            // confirmed is a picture of an assumption.
            'verification_status' => $sensor->model?->verification_status,
        ]);
    }

    /**
     * The sensor's own dominant-frequency reading for the matching axis.
     *
     * This is the counterweight to our own analysis. The device computes it
     * internally at full rate, so it is valid across the whole 0-300 Hz range
     * while ours stops at a few Hz. Where the two disagree, ours is the one
     * limited by the bus.
     *
     * @return array<string, mixed>|null
     */
    private function deviceReported(string $sensorId, string $channelKey, \DateTimeInterface $from): ?array
    {
        if (! preg_match('/_(x|y|z)$/', $channelKey, $matches)) {
            return null;
        }

        $frequencyChannel = 'vib_frequency_'.$matches[1];
        // Good readings only. The register is declared 0-300 Hz and the
        // decoder already marks anything past that implausible, but this
        // summary averaged them in anyway and reported a maximum of 381 Hz -
        // a figure the appliance had itself rejected, presented on the page as
        // a headline statistic with nothing to say it was not believed.
        $row = DB::selectOne(<<<'SQL'
            SELECT avg(value) FILTER (WHERE quality = 'good') AS mean,
                   min(value) FILTER (WHERE quality = 'good') AS lo,
                   max(value) FILTER (WHERE quality = 'good') AS hi,
                   count(*)   FILTER (WHERE quality = 'good') AS n,
                   count(*)   FILTER (WHERE quality <> 'good') AS rejected
            FROM measurements
            WHERE sensor_id = ? AND channel_key = ? AND time >= ? AND value IS NOT NULL
        SQL, [$sensorId, $frequencyChannel, $from]);

        if (! $row || (int) $row->n === 0) {
            return null;
        }

        return [
            'channel_key' => $frequencyChannel,
            'unit' => 'Hz',
            'mean_hz' => round((float) $row->mean, 2),
            'min_hz' => round((float) $row->lo, 2),
            'max_hz' => round((float) $row->hi, 2),
            // Reported, not silently dropped. A window where most readings were
            // out of range is a different situation from a clean one, and the
            // summary would otherwise look identical.
            'rejected_samples' => (int) $row->rejected,
            'samples' => (int) $row->n,
            'note' => 'Computed on-device at full sampling rate. Not limited by the poll rate, '
                .'and not corroborated by the appliance\'s own record above the defensible band.',
        ];
    }
}
