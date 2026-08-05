<?php

namespace App\Console\Commands;

use App\Models\AlarmEvent;
use App\Models\Sensor;
use App\Services\MqttPublisher;
use App\Support\StructuralVibration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishHealth extends Command
{
    protected $signature = 'mqtt:health';

    protected $description = 'Publish appliance health to MQTT for integrations';

    /**
     * The latest value on every channel, once a minute.
     *
     * `mqtt-topics.md` has documented this topic since the appliance was built
     * and nothing ever published to it. A documented topic that never carries a
     * message is worse than an undocumented one: an integrator writes a
     * subscriber against it, sees silence, and has no way to tell a broken
     * appliance from a quiet structure.
     *
     * A snapshot rather than a stream. Acquisition runs at 1 Hz across twenty
     * channels on three sensors; forwarding all of it would put sixty messages
     * a second on a broker to say what the durable record already holds. What an
     * integration wants from MQTT is current state, and it can ask the API for
     * history.
     */
    private function publishSnapshot(MqttPublisher $mqtt, string $sensorId, \Illuminate\Support\Carbon $now): void
    {
        $rows = \Illuminate\Support\Facades\DB::select(<<<'SQL'
            SELECT DISTINCT ON (channel_key) channel_key, value, unit, quality, time
            FROM measurements
            WHERE sensor_id = ? AND time > now() - interval '5 minutes'
            ORDER BY channel_key, time DESC
        SQL, [$sensorId]);

        if ($rows === []) {
            // Silence rather than a snapshot of nothing. A subscriber seeing no
            // message learns the same thing the status topic already told it.
            return;
        }

        $values = [];
        $latest = null;

        foreach ($rows as $row) {
            $values[$row->channel_key] = [
                'value' => $row->value === null ? null : round((float) $row->value, 6),
                'unit' => $row->unit,
                // Carried, because a reading the appliance did not believe must
                // not arrive downstream looking like one it did.
                'quality' => $row->quality,
            ];
            $latest = max($latest ?? $row->time, $row->time);
        }

        $mqtt->publishMeasurements($sensorId, $values, (string) ($latest ?? $now));
    }

    public function handle(MqttPublisher $mqtt): int
    {
        if (! $mqtt->enabled()) {
            $this->comment('MQTT is disabled (set MQTT_ENABLED=true)');

            return self::SUCCESS;
        }

        $now = now();
        $sensors = Sensor::where('status', 'active')->get();
        $online = $sensors->filter(
            fn ($s) => $s->last_measurement_at
                && $s->last_measurement_at->diffInSeconds($now, absolute: true) <= 120,
        );

        $published = $mqtt->publishHealth([
            'sensors_total' => $sensors->count(),
            'sensors_online' => $online->count(),
            'alarms_active' => AlarmEvent::where('state', 'active')->where('level', '!=', 'normal')->count(),
            'alarms_provisional' => AlarmEvent::where('state', 'active')->where('provisional', true)->count(),
            'measurements_total' => DB::table('measurements')->count(),
            // Downstream should be able to see that guideline values are not
            // verified without having to ask.
            'standard_tables_status' => StructuralVibration::STATUS,
        ]);

        foreach ($sensors as $sensor) {
            $silentFor = $sensor->last_measurement_at
                ? (int) $sensor->last_measurement_at->diffInSeconds($now, absolute: true)
                : null;
            $mqtt->publishSensorStatus($sensor->sensor_id, [
                'online' => $silentFor !== null && $silentFor <= 120,
                'silent_for_seconds' => $silentFor,
                'model' => $sensor->model?->model,
                'verification_status' => $sensor->model?->verification_status,
                // Where this sensor sits and what it is for. An integration
                // reading three identical-looking units otherwise has no way to
                // know which one is the ground reference, and treating the
                // reference as a structural sensor inverts everything.
                'position' => ($sensor->metadata['mounting'] ?? [])['position'] ?? null,
                'role' => ($sensor->metadata['mounting'] ?? [])['role'] ?? null,
            ]);

            $this->publishSnapshot($mqtt, $sensor->sensor_id, $now);
        }

        $mqtt->disconnect();
        $this->info($published ? 'health published' : 'health publish failed (see log)');

        return self::SUCCESS;
    }
}
