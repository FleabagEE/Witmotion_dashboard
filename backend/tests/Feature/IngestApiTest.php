<?php

namespace Tests\Feature;

use App\Models\Appliance;
use App\Models\Channel;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\IngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::create([
            'appliance_id' => 'QV-EDGE-TEST',
            'name' => 'Test appliance',
            'status' => 'online',
        ]);
    }

    private function authenticate(array $abilities = ['ingest']): void
    {
        Sanctum::actingAs($this->appliance, $abilities);
    }

    private function envelope(int $sequence = 1, array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0',
            'appliance_id' => 'QV-EDGE-TEST',
            'run_id' => 'run-a',
            'adapter_id' => 'RS485-ADAPTER-001',
            'bus_id' => 'BUS-001',
            'sensor_id' => 'SENSOR-001',
            'sensor_model' => 'WTVB01-485',
            'profile_version' => '1.0.0',
            'slave_id' => 80,
            'group' => 'acceleration',
            'sequence' => $sequence,
            'timestamp_utc' => '2026-07-31T12:00:0'.($sequence % 10).'.000000Z',
            'measurements' => [
                'accel_z' => [
                    'value' => 0.993, 'unit' => 'g', 'quality' => 'good',
                    'class' => 'native', 'raw' => [2033],
                ],
            ],
            'quality' => ['status' => 'good', 'crc_valid' => true, 'stale' => false, 'latency_ms' => 30.5],
            'simulated' => false,
        ], $overrides);
    }

    public function test_health_requires_authentication(): void
    {
        $this->getJson('/api/internal/v1/ingest/health')->assertUnauthorized();
    }

    public function test_health_reports_contract(): void
    {
        $this->authenticate();
        $this->getJson('/api/internal/v1/ingest/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'schema_version' => '1.0']);
    }

    public function test_token_without_ingest_ability_is_refused(): void
    {
        // A read-only token must not be able to write measurements.
        $this->authenticate(['read']);
        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope()],
        ])->assertForbidden();
    }

    public function test_accepts_a_batch_and_stores_measurements(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1), $this->envelope(2)],
        ]);

        $response->assertStatus(202)->assertJson([
            'offered' => 2, 'accepted' => 2, 'duplicates' => 0, 'rejected' => 0,
        ]);

        $this->assertSame(2, DB::table('measurements')->count());
        $row = DB::table('measurements')->first();
        $this->assertSame(0.993, (float) $row->value);
        $this->assertSame('g', $row->unit);
        $this->assertSame('{2033}', $row->raw_registers);
    }

    public function test_replaying_a_batch_is_a_no_op(): void
    {
        $this->authenticate();
        $payload = ['measurements' => [$this->envelope(1), $this->envelope(2)]];

        $this->postJson('/api/internal/v1/ingest/batch', $payload)->assertStatus(202);
        $this->postJson('/api/internal/v1/ingest/batch', $payload)
            ->assertStatus(202)
            ->assertJson(['accepted' => 0, 'duplicates' => 2]);

        // The point: a forwarder that crashes after writing but before marking
        // delivered must not double-insert.
        $this->assertSame(2, DB::table('measurements')->count());
    }

    public function test_same_sequence_from_a_new_run_is_not_a_duplicate(): void
    {
        $this->authenticate();

        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1, ['run_id' => 'run-a'])],
        ])->assertStatus(202);

        // Sequence numbers restart at 1 when the service restarts. These are
        // genuinely different measurements and must both survive.
        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1, ['run_id' => 'run-b'])],
        ])->assertStatus(202)->assertJson(['accepted' => 1, 'duplicates' => 0]);

        $this->assertSame(2, DB::table('measurements')->count());
    }

    public function test_invalid_envelopes_are_itemised_without_failing_the_batch(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [
                $this->envelope(1),
                ['schema_version' => '1.0', 'appliance_id' => 'X'],       // missing fields
                $this->envelope(3, ['schema_version' => '9.9']),          // unsupported version
                $this->envelope(4, ['sequence' => 0]),                    // bad sequence
            ],
        ]);

        $response->assertStatus(202)->assertJson(['offered' => 4, 'accepted' => 1, 'rejected' => 3]);
        $errors = $response->json('errors');
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('missing required field', $errors[0]['error']);
        $this->assertStringContainsString('unsupported schema_version', $errors[1]['error']);

        // Good data in a partly bad batch must still land.
        $this->assertSame(1, DB::table('measurements')->count());
    }

    public function test_batch_size_is_bounded(): void
    {
        $this->authenticate();
        $envelopes = [];
        for ($i = 1; $i <= IngestService::MAX_BATCH + 1; $i++) {
            $envelopes[] = $this->envelope($i);
        }

        $this->postJson('/api/internal/v1/ingest/batch', ['measurements' => $envelopes])
            ->assertStatus(422)
            ->assertJsonValidationErrors('measurements');
    }

    public function test_empty_batch_is_refused(): void
    {
        $this->authenticate();
        $this->postJson('/api/internal/v1/ingest/batch', ['measurements' => []])
            ->assertStatus(422);
    }

    public function test_unknown_sensor_is_provisioned_rather_than_rejected(): void
    {
        $this->authenticate();

        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1, ['sensor_id' => 'SENSOR-NEW'])],
        ])->assertStatus(202)->assertJson(['accepted' => 1]);

        $sensor = Sensor::where('sensor_id', 'SENSOR-NEW')->first();
        $this->assertNotNull($sensor, 'measurement must not be lost because the profile arrived later');

        // Provisioned from a measurement, so nothing about the map is confirmed.
        $this->assertSame('unverified', $sensor->model->verification_status);
        $this->assertSame('unknown', $sensor->channels()->first()->quantity);
    }

    public function test_profile_registration_records_provenance(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/internal/v1/ingest/profile', [
            'appliance_id' => 'QV-EDGE-TEST',
            'sensor_id' => 'SENSOR-001',
            'sensor_model' => 'WTVB01-485',
            'manufacturer' => 'WitMotion',
            'profile_version' => '1.0.0',
            'verification_status' => 'verified',
            'slave_id' => 80,
            'capabilities' => ['acceleration', 'vibration_velocity'],
            'channels' => [
                [
                    'channel_key' => 'accel_z', 'group_key' => 'acceleration',
                    'label' => 'Acceleration Z', 'quantity' => 'acceleration', 'unit' => 'g',
                    'register_address' => 54, 'data_type' => 'int16',
                    'scale' => 0.00048828125, 'range_min' => -16, 'range_max' => 16,
                ],
                [
                    'channel_key' => 'vib_velocity_x', 'group_key' => 'vibration_velocity',
                    'quantity' => 'vibration_velocity', 'unit' => 'mm/s',
                    'register_address' => 58, 'scale' => 0.01,
                ],
            ],
        ]);

        $response->assertOk()->assertJson([
            'sensor_model' => 'WTVB01-485',
            'verification_status' => 'verified',
            'trustworthy' => true,
            'channels' => 2,
        ]);

        $channel = Channel::where('channel_key', 'accel_z')->first();
        $this->assertSame(54, $channel->register_address);
        $this->assertEqualsWithDelta(0.00048828125, $channel->scale, 1e-12);
        $this->assertSame('acceleration', $channel->quantity);
    }

    public function test_profile_registration_is_idempotent(): void
    {
        $this->authenticate();
        $payload = [
            'appliance_id' => 'QV-EDGE-TEST',
            'sensor_id' => 'SENSOR-001',
            'sensor_model' => 'WTVB01-485',
            'profile_version' => '1.0.0',
            'channels' => [['channel_key' => 'accel_z', 'group_key' => 'acceleration', 'unit' => 'g']],
        ];

        $this->postJson('/api/internal/v1/ingest/profile', $payload)->assertOk();
        $this->postJson('/api/internal/v1/ingest/profile', $payload)->assertOk();

        $this->assertSame(1, Sensor::where('sensor_id', 'SENSOR-001')->count());
        $this->assertSame(1, Channel::count());
        $this->assertSame(1, SensorModel::count());
    }

    public function test_unverified_profile_is_recorded_as_untrustworthy(): void
    {
        $this->authenticate();

        $this->postJson('/api/internal/v1/ingest/profile', [
            'appliance_id' => 'QV-EDGE-TEST',
            'sensor_id' => 'SENSOR-002',
            'sensor_model' => 'SOME-NEW-SENSOR',
            'profile_version' => '0.1.0',
            'verification_status' => 'candidate',
            'channels' => [['channel_key' => 'x', 'group_key' => 'g', 'unit' => 'g']],
        ])->assertOk()->assertJson(['trustworthy' => false]);
    }

    public function test_appliance_last_seen_is_updated(): void
    {
        $this->authenticate();
        $this->assertNull($this->appliance->last_ingest_at);

        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1)],
        ])->assertStatus(202);

        $this->appliance->refresh();
        $this->assertNotNull($this->appliance->last_ingest_at);
        $this->assertSame('run-a', $this->appliance->current_run_id);
        $this->assertSame('online', $this->appliance->status);
    }

    public function test_bad_quality_measurements_are_stored_not_discarded(): void
    {
        $this->authenticate();

        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1, [
                'quality' => ['status' => 'bad'],
                'measurements' => ['accel_z' => [
                    'value' => null, 'unit' => 'g', 'quality' => 'bad', 'class' => 'native', 'raw' => [],
                ]],
            ])],
        ])->assertStatus(202)->assertJson(['accepted' => 1]);

        // A failed read is evidence of a problem and must be visible in history,
        // not silently dropped.
        $row = DB::table('measurements')->first();
        $this->assertNull($row->value);
        $this->assertSame('bad', $row->quality);
        $this->assertSame('bad', DB::table('ingested_polls')->value('quality'));
    }

    public function test_measurements_land_in_the_hypertable(): void
    {
        $this->authenticate();
        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [$this->envelope(1)],
        ])->assertStatus(202);

        $isHypertable = DB::selectOne(
            "SELECT count(*) AS n FROM timescaledb_information.hypertables WHERE hypertable_name = 'measurements'"
        );
        $this->assertSame(1, (int) $isHypertable->n);
    }

    public function test_sub_second_timestamps_survive_ingestion(): void
    {
        $this->authenticate();

        // The query grammar's date format is 'Y-m-d H:i:s', so binding a Carbon
        // straight into the insert drops microseconds even though the column is
        // timestamptz(6). That silently collapsed the eight readings a second
        // this appliance takes onto a single timestamp, which capped spectral
        // analysis of the stored record at 0.4 Hz when the sampling supports
        // 3.2 Hz. The data looked complete the whole time - only the timing
        // was gone.
        $this->postJson('/api/internal/v1/ingest/batch', [
            'measurements' => [
                $this->envelope(1, ['timestamp_utc' => '2026-07-31T12:00:00.111111Z']),
                $this->envelope(2, ['timestamp_utc' => '2026-07-31T12:00:00.222222Z']),
                $this->envelope(3, ['timestamp_utc' => '2026-07-31T12:00:00.333333Z']),
            ],
        ])->assertStatus(202);

        $distinct = DB::table('measurements')->distinct()->count('time');

        $this->assertSame(3, $distinct, 'sub-second timestamps were collapsed onto one second');
    }
}
