<?php

namespace App\Services;

use App\Models\AlarmEvent;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Illuminate\Support\Facades\Log;

/**
 * Publishes appliance state to MQTT for downstream integrations.
 *
 * One-way, on purpose. Measurements enter the system through the authenticated
 * ingestion API and nowhere else, so a compromised broker can mislead a SCADA
 * screen but cannot put a forged reading into the historical record.
 *
 * Publishing must never be able to break the thing it reports on. Every failure
 * here is logged and swallowed: an unreachable broker is an integration outage,
 * not a monitoring outage, and the database already holds the truth.
 */
class MqttPublisher
{
    private ?MqttClient $client = null;

    /** @var callable|null Injected in tests to observe publishes without a broker. */
    private $sink;

    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink;
    }

    public function enabled(): bool
    {
        return $this->sink !== null || (bool) config('mqtt.enabled');
    }

    public function publishAlarm(AlarmEvent $event): bool
    {
        $event->loadMissing('definition');

        return $this->publish(
            $this->topic("alarms"),
            [
                'schema_version' => '1.0',
                'event_id' => $event->id,
                'name' => $event->definition?->name,
                'sensor_id' => $event->sensor_id,
                'channel_key' => $event->channel_key,
                'level' => $event->level,
                'peak_level' => $event->peak_level,
                'state' => $event->state,
                'value' => $event->trigger_value,
                'threshold' => $event->threshold,
                'unit' => $event->unit,
                'raised_at' => $event->raised_at?->toIso8601String(),
                'acknowledged' => $event->acknowledged_at !== null,
                // Carried onto the wire so an integration cannot mistake an
                // alarm raised from unverified thresholds for a confirmed one.
                'provisional' => (bool) $event->provisional,
                'thresholds_confirmed_by' => $event->definition?->thresholds_confirmed_by,
            ],
            (int) config('mqtt.qos.alarm', 1),
            (bool) config('mqtt.retain.alarm', false),
        );
    }

    public function publishSensorStatus(string $sensorId, array $status): bool
    {
        return $this->publish(
            $this->topic("sensors/{$sensorId}/status"),
            ['schema_version' => '1.0', 'sensor_id' => $sensorId] + $status,
            (int) config('mqtt.qos.status', 0),
            (bool) config('mqtt.retain.status', true),
        );
    }

    public function publishMeasurements(string $sensorId, array $values, string $at): bool
    {
        return $this->publish(
            $this->topic("sensors/{$sensorId}/measurements"),
            [
                'schema_version' => '1.0',
                'sensor_id' => $sensorId,
                'at' => $at,
                'measurements' => $values,
            ],
            (int) config('mqtt.qos.status', 0),
            false,
        );
    }

    public function publishHealth(array $health): bool
    {
        return $this->publish(
            $this->topic('health'),
            ['schema_version' => '1.0', 'at' => now()->toIso8601String()] + $health,
            (int) config('mqtt.qos.health', 0),
            (bool) config('mqtt.retain.health', true),
        );
    }

    private function topic(string $suffix): string
    {
        return sprintf('%s/%s/%s', config('mqtt.topic_root'), config('mqtt.appliance_id'), $suffix);
    }

    private function publish(string $topic, array $payload, int $qos, bool $retain): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($this->sink !== null) {
            ($this->sink)($topic, $json, $qos, $retain);

            return true;
        }

        try {
            $this->connect()->publish($topic, $json, $qos, $retain);

            return true;
        } catch (\Throwable $exception) {
            // Integration outage, not a monitoring outage. The database already
            // holds the truth; this is a copy for somebody else's screen.
            Log::warning('mqtt publish failed', [
                'topic' => $topic, 'error' => $exception->getMessage(),
            ]);
            $this->client = null;

            return false;
        }
    }

    private function connect(): MqttClient
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return $this->client;
        }

        $settings = (new ConnectionSettings())
            ->setUsername(config('mqtt.username'))
            ->setPassword(config('mqtt.password'))
            ->setConnectTimeout((int) config('mqtt.connect_timeout', 5))
            ->setUseTls((bool) config('mqtt.tls'))
            // Last will: if this process dies, subscribers are told the
            // appliance stopped reporting rather than being left with a stale
            // "online" retained message that never changes.
            ->setLastWillTopic($this->topic('status'))
            ->setLastWillMessage(json_encode([
                'schema_version' => '1.0',
                'status' => 'offline',
                'reason' => 'unexpected disconnect',
            ]))
            ->setLastWillQualityOfService(1)
            ->setRetainLastWill(true);

        if (config('mqtt.ca_file')) {
            $settings = $settings->setTlsCertificateAuthorityFile(config('mqtt.ca_file'));
        }

        $client = new MqttClient(
            config('mqtt.host'),
            (int) config('mqtt.port'),
            config('mqtt.client_id').'-'.getmypid(),
        );
        $client->connect($settings, true);

        // Announce presence, retained, so a subscriber connecting later learns
        // the current state immediately rather than waiting for the next event.
        $client->publish($this->topic('status'), json_encode([
            'schema_version' => '1.0',
            'status' => 'online',
            'at' => now()->toIso8601String(),
        ]), 1, true);

        return $this->client = $client;
    }

    public function disconnect(): void
    {
        if ($this->client === null) {
            return;
        }
        try {
            $this->client->publish($this->topic('status'), json_encode([
                'schema_version' => '1.0', 'status' => 'offline', 'reason' => 'clean shutdown',
            ]), 1, true);
            $this->client->disconnect();
        } catch (\Throwable) {
            // Shutting down; nothing useful left to do.
        }
        $this->client = null;
    }
}
