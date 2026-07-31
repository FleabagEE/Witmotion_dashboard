<?php

namespace App\Services;

use App\Models\Appliance;
use App\Models\Channel;
use App\Models\Sensor;
use App\Models\SensorModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns measurement envelopes from an appliance into rows.
 *
 * Two properties matter more than throughput here:
 *
 *  - Idempotency. A forwarder that crashes mid-batch will re-offer the same
 *    measurements. Every envelope carries a key of
 *    appliance:run_id:sensor:group:sequence, and the ingested_polls unique
 *    index makes a replay a no-op rather than a duplicate.
 *  - Never losing data to ordering. Measurements are accepted even if the
 *    sensor's profile has not been announced yet; the sensor and its channels
 *    are provisioned on the spot and enriched later when the profile arrives.
 */
class IngestService
{
    /** Bounded so one request cannot be used to exhaust memory. */
    public const MAX_BATCH = 1000;

    private array $sensorCache = [];
    private array $channelCache = [];
    /** Latest reading per (sensor, channel) in this batch, for alarm evaluation. */
    private array $latest = [];

    public function __construct(private readonly AlarmEvaluator $alarms)
    {
    }

    public function ingestBatch(array $envelopes, string $batchUid, ?string $sourceIp = null): array
    {
        $offered = count($envelopes);
        $accepted = 0;
        $duplicates = 0;
        $rejected = 0;
        $errors = [];
        $applianceIds = [];

        DB::transaction(function () use (
            $envelopes, &$accepted, &$duplicates, &$rejected, &$errors, &$applianceIds
        ): void {
            $rows = [];

            foreach ($envelopes as $index => $envelope) {
                $problem = $this->validateEnvelope($envelope);
                if ($problem !== null) {
                    $rejected++;
                    $errors[] = ['index' => $index, 'error' => $problem];
                    continue;
                }

                $key = $this->idempotencyKey($envelope);
                $applianceIds[$envelope['appliance_id']] = true;

                $inserted = DB::table('ingested_polls')->insertOrIgnore([
                    'idempotency_key' => $key,
                    'appliance_id' => $envelope['appliance_id'],
                    'run_id' => $envelope['run_id'],
                    'sensor_id' => $envelope['sensor_id'],
                    'group_key' => $envelope['group'],
                    'sequence' => $envelope['sequence'],
                    'measured_at' => Carbon::parse($envelope['timestamp_utc']),
                    'quality' => data_get($envelope, 'quality.status', 'good'),
                    'latency_ms' => data_get($envelope, 'quality.latency_ms'),
                    'channel_count' => count($envelope['measurements']),
                    'simulated' => (bool) ($envelope['simulated'] ?? false),
                    'ingested_at' => now(),
                ]);

                if ($inserted === 0) {
                    // Already ingested. Replay safety, not an error.
                    $duplicates++;
                    continue;
                }

                $pollId = DB::table('ingested_polls')->where('idempotency_key', $key)->value('id');
                $sensor = $this->resolveSensor($envelope);
                $sensor->last_measurement_at = Carbon::parse($envelope['timestamp_utc']);

                foreach ($envelope['measurements'] as $channelKey => $reading) {
                    $channel = $this->resolveChannel($sensor, $envelope['group'], $channelKey, $reading);
                    $rows[] = [
                        'time' => Carbon::parse($envelope['timestamp_utc']),
                        'appliance_id' => $envelope['appliance_id'],
                        'sensor_id' => $envelope['sensor_id'],
                        'channel_key' => $channelKey,
                        'channel_id' => $channel->id,
                        'poll_id' => $pollId,
                        'value' => $reading['value'] ?? null,
                        'unit' => $reading['unit'] ?? '',
                        'quality' => $reading['quality'] ?? 'good',
                        'source_type' => $reading['class'] ?? 'native',
                        'sequence' => $envelope['sequence'],
                        'run_id' => $envelope['run_id'],
                        'raw_registers' => $this->pgIntArray($reading['raw'] ?? []),
                        'profile_version' => $envelope['profile_version'] ?? null,
                        'ingested_at' => now(),
                    ];

                    // Alarms are evaluated once per channel after the batch
                    // commits, not once per row: bounded by distinct channels
                    // (tens) rather than by rows (hundreds).
                    $this->latest[$sensor->id.'|'.$channelKey] = [
                        'sensor' => $sensor,
                        'channel_key' => $channelKey,
                        'value' => $reading['value'] ?? null,
                        'unit' => $reading['unit'] ?? null,
                        'quality' => $reading['quality'] ?? 'good',
                        'at' => Carbon::parse($envelope['timestamp_utc']),
                    ];
                }

                $accepted++;
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('measurements')->insert($chunk);
            }

            foreach ($this->sensorCache as $cached) {
                if ($cached->isDirty('last_measurement_at')) {
                    $cached->save();
                }
            }

            $this->touchAppliances(array_keys($applianceIds), $envelopes);
        });

        // insertOrIgnore, not insert: the client sends a stable batch id, so a
        // retry after a lost response re-offers the same id. That retry must be
        // harmless, not a unique-constraint violation.
        DB::table('ingest_batches')->insertOrIgnore([
            'batch_uid' => $batchUid,
            'appliance_id' => array_key_first($applianceIds) ?? 'unknown',
            'offered' => $offered,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'status' => $rejected > 0 ? 'partial' : 'accepted',
            'source_ip' => $sourceIp,
            'received_at' => now(),
        ]);

        $raised = $this->evaluateAlarms();

        return [
            'batch_uid' => $batchUid,
            'alarms_changed' => $raised,
            'offered' => $offered,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'errors' => $errors,
        ];
    }

    /**
     * Evaluate alarms for every channel touched by this batch.
     *
     * Deliberately outside the ingest transaction. Measurements are already
     * durably stored by this point, and an alarm-engine fault must never roll
     * back data that arrived correctly - losing the reading is far worse than
     * missing one evaluation, which the next batch will redo anyway.
     */
    private function evaluateAlarms(): int
    {
        $changed = 0;
        foreach ($this->latest as $reading) {
            try {
                $sensor = $reading['sensor']->loadMissing('channels', 'model');
                $events = $this->alarms->evaluate(
                    $sensor,
                    $reading['channel_key'],
                    $reading['value'] === null ? null : (float) $reading['value'],
                    $reading['unit'],
                    $reading['at'],
                    $reading['quality'],
                );
                $changed += count($events);
            } catch (\Throwable $exception) {
                Log::error('alarm evaluation failed', [
                    'sensor_id' => $reading['sensor']->sensor_id ?? null,
                    'channel' => $reading['channel_key'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
        $this->latest = [];

        return $changed;
    }

    /**
     * Register a sensor's profile: model, verification status, and the decoding
     * provenance for every channel. Idempotent, so an appliance can announce on
     * every start without creating churn.
     */
    public function registerProfile(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $appliance = Appliance::firstOrCreate(
                ['appliance_id' => $payload['appliance_id']],
                ['name' => $payload['appliance_id'], 'status' => 'online'],
            );

            $model = SensorModel::updateOrCreate(
                ['model' => $payload['sensor_model']],
                [
                    'manufacturer' => $payload['manufacturer'] ?? 'unknown',
                    'protocol' => $payload['protocol'] ?? 'modbus_rtu',
                    'profile_version' => $payload['profile_version'],
                    'verification_status' => $payload['verification_status'] ?? 'unverified',
                    'capabilities' => $payload['capabilities'] ?? [],
                    'limitations' => $payload['limitations'] ?? [],
                ],
            );

            $sensor = Sensor::updateOrCreate(
                ['appliance_id' => $appliance->id, 'sensor_id' => $payload['sensor_id']],
                [
                    'sensor_model_id' => $model->id,
                    'slave_id' => $payload['slave_id'] ?? null,
                    'status' => 'active',
                ],
            );

            $channels = 0;
            foreach ($payload['channels'] ?? [] as $channel) {
                Channel::updateOrCreate(
                    ['sensor_id' => $sensor->id, 'channel_key' => $channel['channel_key']],
                    [
                        'group_key' => $channel['group_key'],
                        'label' => $channel['label'] ?? $channel['channel_key'],
                        'quantity' => $channel['quantity'] ?? 'unknown',
                        'unit' => $channel['unit'] ?? '',
                        'value_class' => $channel['value_class'] ?? 'native',
                        'register_address' => $channel['register_address'] ?? null,
                        'data_type' => $channel['data_type'] ?? null,
                        'scale' => $channel['scale'] ?? null,
                        'offset' => $channel['offset'] ?? null,
                        'range_min' => $channel['range_min'] ?? null,
                        'range_max' => $channel['range_max'] ?? null,
                        'configured_hz' => $channel['configured_hz'] ?? null,
                    ],
                );
                $channels++;
            }

            return [
                'appliance_id' => $appliance->appliance_id,
                'sensor_id' => $sensor->sensor_id,
                'sensor_model' => $model->model,
                'verification_status' => $model->verification_status,
                'trustworthy' => $model->isTrustworthy(),
                'channels' => $channels,
            ];
        });
    }

    private function validateEnvelope(mixed $envelope): ?string
    {
        if (! is_array($envelope)) {
            return 'envelope must be an object';
        }
        foreach (['appliance_id', 'run_id', 'sensor_id', 'group', 'sequence', 'timestamp_utc', 'measurements'] as $field) {
            if (! array_key_exists($field, $envelope)) {
                return "missing required field: {$field}";
            }
        }
        if (($envelope['schema_version'] ?? null) !== '1.0') {
            return 'unsupported schema_version: '.var_export($envelope['schema_version'] ?? null, true);
        }
        if (! is_array($envelope['measurements'])) {
            return 'measurements must be an object';
        }
        if (! is_int($envelope['sequence']) || $envelope['sequence'] < 1) {
            return 'sequence must be a positive integer';
        }
        try {
            Carbon::parse($envelope['timestamp_utc']);
        } catch (\Throwable) {
            return 'timestamp_utc is not a valid timestamp';
        }

        return null;
    }

    private function idempotencyKey(array $envelope): string
    {
        return sprintf(
            '%s:%s:%s:%s:%d',
            $envelope['appliance_id'],
            $envelope['run_id'],
            $envelope['sensor_id'],
            $envelope['group'],
            $envelope['sequence'],
        );
    }

    private function resolveSensor(array $envelope): Sensor
    {
        $cacheKey = $envelope['appliance_id'].'|'.$envelope['sensor_id'];
        if (isset($this->sensorCache[$cacheKey])) {
            return $this->sensorCache[$cacheKey];
        }

        $appliance = Appliance::firstOrCreate(
            ['appliance_id' => $envelope['appliance_id']],
            ['name' => $envelope['appliance_id'], 'status' => 'online'],
        );

        $model = null;
        if (! empty($envelope['sensor_model'])) {
            $model = SensorModel::firstOrCreate(
                ['model' => $envelope['sensor_model']],
                [
                    'manufacturer' => 'unknown',
                    'profile_version' => $envelope['profile_version'] ?? 'unknown',
                    // Provisioned from a measurement, not from a profile
                    // announcement, so nothing about the map is confirmed yet.
                    'verification_status' => 'unverified',
                ],
            );
        }

        $sensor = Sensor::firstOrCreate(
            ['appliance_id' => $appliance->id, 'sensor_id' => $envelope['sensor_id']],
            ['sensor_model_id' => $model?->id, 'slave_id' => $envelope['slave_id'] ?? null],
        );

        return $this->sensorCache[$cacheKey] = $sensor;
    }

    private function resolveChannel(Sensor $sensor, string $group, string $channelKey, array $reading): Channel
    {
        $cacheKey = $sensor->id.'|'.$channelKey;
        if (isset($this->channelCache[$cacheKey])) {
            return $this->channelCache[$cacheKey];
        }

        // Created from a measurement when no profile has been announced. The
        // quantity is unknown here on purpose: guessing it from a channel name
        // is exactly the kind of silent inference this project refuses to make.
        $channel = Channel::firstOrCreate(
            ['sensor_id' => $sensor->id, 'channel_key' => $channelKey],
            [
                'group_key' => $group,
                'label' => $channelKey,
                'quantity' => 'unknown',
                'unit' => $reading['unit'] ?? '',
                'value_class' => $reading['class'] ?? 'native',
            ],
        );

        return $this->channelCache[$cacheKey] = $channel;
    }

    private function touchAppliances(array $applianceIds, array $envelopes): void
    {
        $runId = null;
        foreach ($envelopes as $envelope) {
            if (is_array($envelope) && isset($envelope['run_id'])) {
                $runId = $envelope['run_id'];
                break;
            }
        }

        foreach ($applianceIds as $applianceId) {
            Appliance::where('appliance_id', $applianceId)->update([
                'last_seen_at' => now(),
                'last_ingest_at' => now(),
                'current_run_id' => $runId,
                'status' => 'online',
                'updated_at' => now(),
            ]);
        }
    }

    /** PostgreSQL integer[] literal. */
    private function pgIntArray(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return '{'.implode(',', array_map('intval', $values)).'}';
    }

    public static function newBatchUid(): string
    {
        return (string) Str::uuid();
    }
}
