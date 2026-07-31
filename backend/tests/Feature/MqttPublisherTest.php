<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\MqttPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MqttPublisherTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{topic: string, payload: array, qos: int, retain: bool}> */
    private array $published = [];
    private Sensor $sensor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mqtt.topic_root' => 'quakevault',
            'mqtt.appliance_id' => 'QV-EDGE-TEST',
        ]);

        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.0.0', 'verification_status' => 'verified',
        ]);
        $this->sensor = Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80,
        ]);
        $this->published = [];
    }

    private function publisher(): MqttPublisher
    {
        return new MqttPublisher(function (string $topic, string $json, int $qos, bool $retain): void {
            $this->published[] = [
                'topic' => $topic,
                'payload' => json_decode($json, true),
                'qos' => $qos,
                'retain' => $retain,
            ];
        });
    }

    private function alarm(bool $provisional = false): AlarmEvent
    {
        $definition = AlarmDefinition::create([
            'key' => 'd-'.uniqid(), 'name' => 'Structural vibration',
            'condition_type' => 'structural_ppv', 'unit' => 'mm/s', 'enabled' => true,
            'thresholds_confirmed_at' => $provisional ? null : now(),
            'thresholds_confirmed_by' => $provisional ? null : 'J. Engineer',
        ]);

        return AlarmEvent::create([
            'alarm_definition_id' => $definition->id, 'sensor_id' => $this->sensor->id,
            'channel_key' => 'vib_velocity_x', 'level' => 'critical', 'peak_level' => 'critical',
            'state' => 'active', 'provisional' => $provisional,
            'trigger_value' => 8.2, 'threshold' => 5.0, 'unit' => 'mm/s', 'raised_at' => now(),
        ]);
    }

    public function test_alarm_is_published_on_the_expected_topic(): void
    {
        $this->publisher()->publishAlarm($this->alarm());

        $this->assertCount(1, $this->published);
        $this->assertSame('quakevault/QV-EDGE-TEST/alarms', $this->published[0]['topic']);
    }

    public function test_alarm_payload_is_schema_versioned(): void
    {
        $this->publisher()->publishAlarm($this->alarm());
        $payload = $this->published[0]['payload'];

        $this->assertSame('1.0', $payload['schema_version']);
        $this->assertSame('critical', $payload['level']);
        $this->assertEqualsWithDelta(8.2, $payload['value'], 1e-9);
        $this->assertSame('mm/s', $payload['unit']);
    }

    public function test_alarms_use_qos_1(): void
    {
        $this->publisher()->publishAlarm($this->alarm());

        // An integration must not miss an alarm because a packet was dropped.
        $this->assertSame(1, $this->published[0]['qos']);
        // Not retained: a stale alarm replayed to every new subscriber would be
        // misread as a live one.
        $this->assertFalse($this->published[0]['retain']);
    }

    public function test_provisional_status_travels_with_the_alarm(): void
    {
        $this->publisher()->publishAlarm($this->alarm(provisional: true));
        $payload = $this->published[0]['payload'];

        // Integrations receive provisional alarms - they are consuming data,
        // not being paged - but nothing downstream may mistake one for
        // confirmed.
        $this->assertTrue($payload['provisional']);
        $this->assertNull($payload['thresholds_confirmed_by']);
    }

    public function test_confirmed_alarm_names_who_confirmed_it(): void
    {
        $this->publisher()->publishAlarm($this->alarm());
        $payload = $this->published[0]['payload'];

        $this->assertFalse($payload['provisional']);
        $this->assertSame('J. Engineer', $payload['thresholds_confirmed_by']);
    }

    public function test_sensor_status_is_retained(): void
    {
        $this->publisher()->publishSensorStatus('SENSOR-001', ['online' => true]);

        $this->assertSame('quakevault/QV-EDGE-TEST/sensors/SENSOR-001/status', $this->published[0]['topic']);
        // Retained, so a subscriber connecting later learns the state at once
        // rather than waiting for the next change.
        $this->assertTrue($this->published[0]['retain']);
    }

    public function test_health_reports_the_standard_table_status(): void
    {
        $this->publisher()->publishHealth(['sensors_total' => 1, 'standard_tables_status' => 'candidate']);

        // Downstream should not have to ask whether the guideline values were
        // ever verified.
        $this->assertSame('candidate', $this->published[0]['payload']['standard_tables_status']);
    }

    public function test_measurements_topic_includes_the_sensor(): void
    {
        $this->publisher()->publishMeasurements('SENSOR-001', ['vib_velocity_x' => 1.23], now()->toIso8601String());

        $this->assertSame(
            'quakevault/QV-EDGE-TEST/sensors/SENSOR-001/measurements',
            $this->published[0]['topic'],
        );
        $this->assertEqualsWithDelta(1.23, $this->published[0]['payload']['measurements']['vib_velocity_x'], 1e-9);
    }

    public function test_publishing_is_disabled_by_default(): void
    {
        config(['mqtt.enabled' => false]);
        $publisher = new MqttPublisher();

        // Nothing should attempt a network connection unless it was turned on.
        $this->assertFalse($publisher->enabled());
        $this->assertFalse($publisher->publishAlarm($this->alarm()));
    }

    public function test_health_command_runs_without_a_broker(): void
    {
        config(['mqtt.enabled' => false]);

        // An integration outage is not a monitoring outage; nothing here may
        // fail because a broker is unreachable or switched off.
        $this->artisan('mqtt:health')->assertSuccessful();
    }
}
