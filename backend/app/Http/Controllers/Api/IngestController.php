<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IngestController extends Controller
{
    public function __construct(private readonly IngestService $ingest)
    {
    }

    /**
     * POST /api/internal/v1/ingest/batch
     *
     * Accepts a bounded batch of measurement envelopes. Replay-safe: re-offering
     * a batch after a forwarder crash is a no-op, reported as duplicates rather
     * than as an error, so the client can mark the batch delivered either way.
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_uid' => ['nullable', 'string', 'max:64'],
            'measurements' => ['required', 'array', 'min:1', 'max:'.IngestService::MAX_BATCH],
        ], [
            'measurements.max' => 'batch exceeds the maximum of '.IngestService::MAX_BATCH.' measurements',
        ]);

        $result = $this->ingest->ingestBatch(
            $data['measurements'],
            $data['batch_uid'] ?? IngestService::newBatchUid(),
            $request->ip(),
        );

        // 202: the batch is durably recorded. Rejected envelopes are itemised so
        // the client can act on them without the whole batch failing.
        return response()->json($result, 202);
    }

    /**
     * POST /api/internal/v1/ingest/profile
     *
     * Announces a sensor's register map and decoding provenance. Idempotent, so
     * an appliance may announce on every start.
     */
    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appliance_id' => ['required', 'string', 'max:80'],
            'sensor_id' => ['required', 'string', 'max:80'],
            'sensor_model' => ['required', 'string', 'max:80'],
            'profile_version' => ['required', 'string', 'max:40'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'protocol' => ['nullable', 'string', 'max:40'],
            'verification_status' => ['nullable', 'in:unverified,candidate,verified'],
            'slave_id' => ['nullable', 'integer', 'min:1', 'max:247'],
            'capabilities' => ['nullable', 'array'],
            'limitations' => ['nullable', 'array'],
            'channels' => ['required', 'array', 'min:1', 'max:500'],
            'channels.*.channel_key' => ['required', 'string', 'max:60'],
            'channels.*.group_key' => ['required', 'string', 'max:60'],
            'channels.*.label' => ['nullable', 'string', 'max:160'],
            'channels.*.unit' => ['nullable', 'string', 'max:20'],
            'channels.*.quantity' => ['nullable', 'string', 'max:60'],
            'channels.*.value_class' => ['nullable', 'string', 'max:20'],
            // Decoding provenance. Every one of these needs an explicit rule:
            // validate() returns only keys that have one, so an omitted rule
            // silently drops the field instead of failing.
            'channels.*.register_address' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'channels.*.data_type' => ['nullable', 'string', 'max:20'],
            'channels.*.scale' => ['nullable', 'numeric'],
            'channels.*.offset' => ['nullable', 'numeric'],
            'channels.*.range_min' => ['nullable', 'numeric'],
            'channels.*.range_max' => ['nullable', 'numeric'],
            'channels.*.configured_hz' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->ingest->registerProfile($data), 200);
    }

    /** GET /api/internal/v1/ingest/health */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'schema_version' => '1.0',
            'max_batch' => IngestService::MAX_BATCH,
        ]);
    }
}
