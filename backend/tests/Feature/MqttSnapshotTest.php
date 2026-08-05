<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\MqttPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the appliance publishes about each sensor.
 *
 * `mqtt-topics.md` documented a measurements topic from the day the appliance
 * was built and nothing ever published to it. A documented topic that never
 * carries a message is worse than an undocumented one: an integrator writes a
 * subscriber, sees silence, and cannot tell a broken appliance from a quiet
 * structure.
 */
class MqttSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{topic:string, payload:array}> */
    private array $published = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mqtt.enabled' => true]);

        // The sink receives the encoded payload, as the broker would.
        $this->app->bind(MqttPublisher::class, fn () => new MqttPublisher(
            function (string $topic, string $payload) {
                $this->published[] = [
                    'topic' => $topic,
                    'payload' => json_decode($payload, true) ?? [],
                ];

                return true;
            },
        ));
    }

    private function silo(): void
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-001', 'name' => 't']);
        $model = SensorModel::create(['model' => 'WTVB01-485', 'manufacturer' => 'W',
            'profile_version' => '1.2.0', 'verification_status' => 'verified']);

        foreach ([['SENSOR-001', 'top', 'monitor'], ['SENSOR-003', 'ground', 'reference']] as [$id, $pos, $role]) {
            Sensor::create([
                'sensor_id' => $id, 'appliance_id' => $appliance->id,
                'sensor_model_id' => $model->id, 'slave_id' => 0x50, 'status' => 'active',
                'metadata' => ['mounting' => ['position' => $pos, 'role' => $role]],
            ]);
        }
    }

    private function reading(string $sensorId, string $channel, float $value,
                             string $unit = 'g', string $quality = 'good'): void
    {
        $this->seq++;
        DB::table('measurements')->insert([
            'time' => now()->subSeconds($this->seq)->format('Y-m-d H:i:s.uP'),
            'appliance_id' => 'QV-EDGE-001', 'sensor_id' => $sensorId,
            'channel_key' => $channel, 'value' => $value, 'unit' => $unit,
            'quality' => $quality, 'source_type' => 'native', 'sequence' => $this->seq,
            'run_id' => 'r1', 'ingested_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function firstOn(string $needle): ?array
    {
        foreach ($this->published as $message) {
            if (str_contains($message['topic'], $needle)) {
                return $message['payload'];
            }
        }

        return null;
    }

    public function test_it_publishes_a_snapshot_for_each_sensor(): void
    {
        $this->silo();
        $this->reading('SENSOR-001', 'accel_z', 0.99);
        $this->reading('SENSOR-003', 'accel_z', 0.98);

        $this->artisan('mqtt:health')->assertSuccessful();

        $this->assertNotNull($this->firstOn('SENSOR-001/measurements'));
        $this->assertNotNull($this->firstOn('SENSOR-003/measurements'));
    }

    public function test_a_snapshot_carries_the_quality_of_each_reading(): void
    {
        // A value the appliance did not believe must not arrive downstream
        // looking like one it did.
        $this->silo();
        $this->reading('SENSOR-001', 'accel_z', 0.99);
        $this->reading('SENSOR-001', 'vib_frequency_x', 404.0, 'Hz', 'implausible');

        $this->artisan('mqtt:health');
        $payload = $this->firstOn('SENSOR-001/measurements');

        $this->assertSame('good', $payload['measurements']['accel_z']['quality']);
        $this->assertSame('implausible', $payload['measurements']['vib_frequency_x']['quality']);
    }

    public function test_sensor_status_says_which_one_is_the_reference(): void
    {
        // Three identical units otherwise look interchangeable, and treating
        // the reference as a structural sensor inverts every interpretation.
        $this->silo();
        $this->reading('SENSOR-003', 'accel_z', 0.98);

        $this->artisan('mqtt:health');
        $payload = $this->firstOn('SENSOR-003/status');

        $this->assertSame('ground', $payload['position']);
        $this->assertSame('reference', $payload['role']);
    }

    public function test_a_silent_sensor_produces_no_snapshot(): void
    {
        // Silence rather than a snapshot of nothing: the status topic already
        // says the sensor has gone quiet, and an empty payload would only add
        // ambiguity.
        $this->silo();
        $this->reading('SENSOR-001', 'accel_z', 0.99);

        $this->artisan('mqtt:health');

        $this->assertNotNull($this->firstOn('SENSOR-001/measurements'));
        $this->assertNull($this->firstOn('SENSOR-003/measurements'));
    }

    public function test_it_takes_the_latest_value_on_each_channel(): void
    {
        $this->silo();
        $this->reading('SENSOR-001', 'accel_z', 0.10);   // older
        DB::table('measurements')->insert([
            'time' => now()->format('Y-m-d H:i:s.uP'),
            'appliance_id' => 'QV-EDGE-001', 'sensor_id' => 'SENSOR-001',
            'channel_key' => 'accel_z', 'value' => 0.99, 'unit' => 'g',
            'quality' => 'good', 'source_type' => 'native', 'sequence' => 999,
            'run_id' => 'r1', 'ingested_at' => now(),
        ]);

        $this->artisan('mqtt:health');
        $payload = $this->firstOn('SENSOR-001/measurements');

        $this->assertEqualsWithDelta(0.99, $payload['measurements']['accel_z']['value'], 1e-6);
    }
}
