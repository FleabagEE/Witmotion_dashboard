<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\Channel;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Sensor $sensor;

    protected function setUp(): void
    {
        parent::setUp();

        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test', 'status' => 'online']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.0.0', 'verification_status' => 'verified',
        ]);
        $this->sensor = Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80,
            'last_measurement_at' => now(),
        ]);
        Channel::create([
            'sensor_id' => $this->sensor->id, 'channel_key' => 'vib_velocity_x',
            'group_key' => 'vibration_velocity', 'label' => 'Velocity X',
            'quantity' => 'vibration_velocity', 'unit' => 'mm/s',
            'register_address' => 0x3A, 'scale' => 0.01,
        ]);
    }

    private function user(string $role = Roles::VIEWER): User
    {
        // One account per role, reused within a test: several helpers may ask
        // for the same role in a single case.
        return User::firstOrCreate(
            ['email' => "{$role}@example.test"],
            [
                'name' => ucfirst($role),
                'password' => Hash::make('correct-horse-battery'),
                'role' => $role,
                'active' => true,
            ],
        );
    }

    private function tokenFor(string $role = Roles::VIEWER): string
    {
        return $this->user($role)->createToken('test', Roles::abilitiesFor($role))->plainTextToken;
    }

    private function asRole(string $role = Roles::VIEWER): self
    {
        // The auth manager is a singleton for the lifetime of a test, and the
        // guard caches the first resolved user. Without forgetting it, a second
        // request in the same test silently reuses the first identity - which
        // would make a role-separation test pass for the wrong reason.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($role));
    }

    private function seedMeasurements(int $count = 30): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'time' => now()->subMinutes($count - $i)->toDateTimeString(),
                'appliance_id' => 'QV-EDGE-TEST', 'sensor_id' => 'SENSOR-001',
                'channel_key' => 'vib_velocity_x', 'value' => 1.0 + ($i / 100),
                'unit' => 'mm/s', 'quality' => 'good', 'source_type' => 'native',
                'sequence' => $i + 1, 'run_id' => 'run-a', 'ingested_at' => now(),
            ];
        }
        DB::table('measurements')->insert($rows);
    }

    // ---- authentication --------------------------------------------------

    public function test_login_returns_a_token_and_role_abilities(): void
    {
        $this->user(Roles::ENGINEER);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'engineer@example.test', 'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role', 'engineer')
            ->assertJsonPath('user.abilities', ['read', 'acknowledge', 'configure']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_wrong_password_and_unknown_email_look_identical(): void
    {
        $this->user();

        $wrong = $this->postJson('/api/v1/login', [
            'email' => 'viewer@example.test', 'password' => 'nope',
        ]);
        $unknown = $this->postJson('/api/v1/login', [
            'email' => 'ghost@example.test', 'password' => 'nope',
        ]);

        // Differing responses would tell an attacker which accounts exist.
        $this->assertSame($wrong->status(), $unknown->status());
        $this->assertSame(
            $wrong->json('errors.email'), $unknown->json('errors.email'),
        );
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        $this->user()->update(['active' => false]);

        $this->postJson('/api/v1/login', [
            'email' => 'viewer@example.test', 'password' => 'correct-horse-battery',
        ])->assertStatus(422);
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'viewer@example.test', 'password' => 'nope']);
        }

        $this->postJson('/api/v1/login', ['email' => 'viewer@example.test', 'password' => 'nope'])
            ->assertStatus(429);
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $this->getJson('/api/v1/overview')->assertUnauthorized();
    }

    // ---- role separation -------------------------------------------------

    public function test_kiosk_can_read(): void
    {
        $this->asRole(Roles::KIOSK)->getJson('/api/v1/overview')->assertOk();
    }

    public function test_kiosk_cannot_acknowledge_an_alarm(): void
    {
        $alarm = $this->makeAlarm();

        // A screen on a wall in a public corridor must not be able to silence
        // an alarm.
        $this->asRole(Roles::KIOSK)
            ->postJson("/api/v1/alarms/{$alarm->id}/acknowledge")
            ->assertForbidden();
    }

    public function test_viewer_cannot_acknowledge_but_operator_can(): void
    {
        $alarm = $this->makeAlarm();
        $this->asRole(Roles::VIEWER)
            ->postJson("/api/v1/alarms/{$alarm->id}/acknowledge")->assertForbidden();

        $this->asRole(Roles::OPERATOR)
            ->postJson("/api/v1/alarms/{$alarm->id}/acknowledge", ['note' => 'checked on site'])
            ->assertOk();

        $this->assertNotNull($alarm->fresh()->acknowledged_at);
    }

    public function test_double_acknowledgement_is_refused(): void
    {
        $alarm = $this->makeAlarm();
        $this->asRole(Roles::OPERATOR)->postJson("/api/v1/alarms/{$alarm->id}/acknowledge")->assertOk();
        $this->asRole(Roles::OPERATOR)->postJson("/api/v1/alarms/{$alarm->id}/acknowledge")->assertStatus(409);
    }

    // ---- overview --------------------------------------------------------

    public function test_overview_reports_sensor_and_alarm_state(): void
    {
        $this->makeAlarm('critical');

        $response = $this->asRole()->getJson('/api/v1/overview')->assertOk();

        $response->assertJsonPath('sensors.total', 1)
            ->assertJsonPath('sensors.online', 1)
            ->assertJsonPath('alarms.active', 1)
            ->assertJsonPath('alarms.critical', 1)
            ->assertJsonPath('alarms.unacknowledged', 1);
    }

    public function test_overview_surfaces_unverified_profiles(): void
    {
        $this->sensor->model->update(['verification_status' => 'candidate']);

        $this->asRole()->getJson('/api/v1/overview')
            ->assertJsonPath('sensors.unverified_profiles', 1);
    }

    public function test_overview_reports_the_standards_status(): void
    {
        // The dashboard must be able to tell an operator that the structural
        // tables have not been checked against the standard text.
        $this->asRole()->getJson('/api/v1/overview')
            ->assertJsonPath('standards.structural_tables_status', 'candidate');
    }

    public function test_silent_sensor_is_counted_as_silent(): void
    {
        $this->sensor->update(['last_measurement_at' => now()->subHour()]);

        $this->asRole()->getJson('/api/v1/overview')
            ->assertJsonPath('sensors.online', 0)
            ->assertJsonPath('sensors.silent', 1);
    }

    // ---- sensors and channels -------------------------------------------

    public function test_sensor_list_carries_trust_and_liveness(): void
    {
        $response = $this->asRole()->getJson('/api/v1/sensors')->assertOk();

        $response->assertJsonPath('data.0.sensor_id', 'SENSOR-001')
            ->assertJsonPath('data.0.trustworthy', true)
            ->assertJsonPath('data.0.online', true)
            ->assertJsonPath('data.0.verification_status', 'verified');
    }

    public function test_channels_expose_decoding_provenance(): void
    {
        $response = $this->asRole()
            ->getJson('/api/v1/sensors/SENSOR-001/channels')->assertOk();

        // A displayed value must always be explainable.
        $response->assertJsonPath('data.0.register_address', 58)
            ->assertJsonPath('data.0.unit', 'mm/s');
        $this->assertEqualsWithDelta(0.01, $response->json('data.0.scale'), 1e-9);
    }

    public function test_unknown_sensor_returns_404(): void
    {
        $this->asRole()->getJson('/api/v1/sensors/NOPE/channels')->assertNotFound();
    }

    public function test_latest_returns_one_row_per_channel(): void
    {
        $this->seedMeasurements();

        $response = $this->asRole()->getJson('/api/v1/sensors/SENSOR-001/latest')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('vib_velocity_x', $response->json('data.0.channel_key'));
        // Newest sample wins.
        $this->assertEqualsWithDelta(1.29, $response->json('data.0.value'), 1e-6);
    }

    // ---- series ----------------------------------------------------------

    public function test_short_window_uses_raw_samples(): void
    {
        $this->seedMeasurements();

        $response = $this->asRole()->getJson('/api/v1/series?'.http_build_query([
            'sensor_id' => 'SENSOR-001', 'channel_key' => 'vib_velocity_x',
            'from' => now()->subHour()->toIso8601String(), 'to' => now()->toIso8601String(),
        ]))->assertOk();

        $response->assertJsonPath('resolution', 'raw_bucketed');
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_long_window_uses_the_hourly_rollup(): void
    {
        $response = $this->asRole()->getJson('/api/v1/series?'.http_build_query([
            'sensor_id' => 'SENSOR-001', 'channel_key' => 'vib_velocity_x',
            'from' => now()->subDays(7)->toIso8601String(), 'to' => now()->toIso8601String(),
        ]))->assertOk();

        // Stated, not hidden: a rollup point is an hourly average, and reading
        // it as instantaneous would understate peaks.
        $response->assertJsonPath('resolution', 'hourly_rollup');
    }

    public function test_series_bounds_the_response_size(): void
    {
        $this->seedMeasurements(200);

        $response = $this->asRole()->getJson('/api/v1/series?'.http_build_query([
            'sensor_id' => 'SENSOR-001', 'channel_key' => 'vib_velocity_x',
            'from' => now()->subHours(4)->toIso8601String(), 'to' => now()->toIso8601String(),
            'max_points' => 20,
        ]))->assertOk();

        $this->assertLessThanOrEqual(20, count($response->json('data')));
    }

    public function test_series_rejects_a_reversed_window(): void
    {
        $this->asRole()->getJson('/api/v1/series?'.http_build_query([
            'sensor_id' => 'SENSOR-001', 'channel_key' => 'vib_velocity_x',
            'from' => now()->toIso8601String(), 'to' => now()->subHour()->toIso8601String(),
        ]))->assertStatus(422);
    }

    // ---- alarms ----------------------------------------------------------

    public function test_alarm_list_flags_provisional_events(): void
    {
        $this->makeAlarm('warning', provisional: true);

        $response = $this->asRole()->getJson('/api/v1/alarms')->assertOk();

        // The dashboard must render these differently: they fired against
        // numbers nobody has verified.
        $response->assertJsonPath('data.0.provisional', true)
            ->assertJsonPath('data.0.actionable', false);
    }

    public function test_confirmed_alarm_is_actionable(): void
    {
        $this->makeAlarm('warning', provisional: false);

        $this->asRole()->getJson('/api/v1/alarms')
            ->assertJsonPath('data.0.provisional', false)
            ->assertJsonPath('data.0.actionable', true);
    }

    public function test_alarms_can_be_filtered_to_unacknowledged(): void
    {
        $acknowledged = $this->makeAlarm('warning');
        $acknowledged->update(['acknowledged_at' => now()]);
        $this->makeAlarm('critical');

        $response = $this->asRole()->getJson('/api/v1/alarms?unacknowledged_only=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('critical', $response->json('data.0.level'));
    }

    private function makeAlarm(string $level = 'warning', bool $provisional = false): AlarmEvent
    {
        $definition = AlarmDefinition::create([
            'key' => 'test-'.uniqid(), 'name' => 'Structural vibration',
            'quantity' => 'vibration_velocity', 'condition_type' => 'high_threshold',
            'unit' => 'mm/s', 'warning_at' => 5.0, 'critical_at' => 15.0,
            'enabled' => true,
            'thresholds_confirmed_at' => $provisional ? null : now(),
            'thresholds_confirmed_by' => $provisional ? null : 'J. Engineer',
        ]);

        return AlarmEvent::create([
            'alarm_definition_id' => $definition->id, 'sensor_id' => $this->sensor->id,
            'channel_key' => 'vib_velocity_x', 'level' => $level, 'peak_level' => $level,
            'state' => 'active', 'provisional' => $provisional,
            'trigger_value' => 6.0, 'threshold' => 5.0, 'unit' => 'mm/s', 'raised_at' => now(),
        ]);
    }
}
