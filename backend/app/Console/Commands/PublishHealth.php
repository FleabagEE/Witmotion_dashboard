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
            ]);
        }

        $mqtt->disconnect();
        $this->info($published ? 'health published' : 'health publish failed (see log)');

        return self::SUCCESS;
    }
}
