<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\Appliance;
use App\Models\NotificationChannel;
use App\Models\Sensor;
use App\Models\SensorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The command that answers "would a real alarm actually reach anyone today?"
 *
 * alarms:test-notification proves SMTP, which is a smaller claim than it sounds.
 * Between a raised alarm and a message sit six gates that each return silently,
 * so an appliance can have working mail, a correct channel, a genuine alarm, and
 * still notify nobody.
 */
class AlarmSelfTestTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(bool $confirmed): AlarmDefinition
    {
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 't']);
        $model = SensorModel::create(['model' => 'WTVB01-485', 'manufacturer' => 'W',
            'profile_version' => '1.2.0', 'verification_status' => 'verified']);
        $sensor = Sensor::create(['sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80, 'status' => 'active']);

        NotificationChannel::create([
            'key' => 'duty', 'name' => 'duty', 'transport' => 'log',
            'config' => [], 'enabled' => true, 'min_level' => 'warning',
            'max_per_hour' => 10, 'dedupe_window_seconds' => 0,
        ]);

        return AlarmDefinition::create([
            'key' => 'tilt-selftest', 'name' => 'Tilt movement', 'sensor_id' => $sensor->id,
            'channel_key' => 'tilt_deviation', 'condition_type' => 'high_threshold',
            'unit' => 'deg', 'warning_at' => 0.5, 'critical_at' => 3.0, 'enabled' => true,
            'thresholds_confirmed_by' => $confirmed ? 'A. Researcher' : null,
            'thresholds_confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    public function test_it_fails_when_an_alarm_would_reach_nobody(): void
    {
        $this->scenario(confirmed: false);

        $this->artisan('alarms:selftest')
            ->expectsOutputToContain('provisional_thresholds')
            ->assertFailed();
    }

    public function test_it_succeeds_once_somebody_has_confirmed_the_numbers(): void
    {
        $this->scenario(confirmed: true);

        $this->artisan('alarms:selftest')->assertSuccessful();
    }

    public function test_it_leaves_no_alarm_in_the_record(): void
    {
        // A self-test that leaves a fictional movement in the history it exists
        // to protect would be worse than no self-test.
        $this->scenario(confirmed: true);

        $this->artisan('alarms:selftest')->assertSuccessful();

        $this->assertDatabaseCount('alarm_events', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_it_records_that_a_test_was_run(): void
    {
        $this->scenario(confirmed: true);
        $this->artisan('alarms:selftest');

        // The alarm is rolled back; the fact that somebody checked is not.
        $this->assertDatabaseHas('audit_events', ['action' => 'alarms.selftest']);
    }

    public function test_it_fails_when_no_channel_exists_at_all(): void
    {
        $this->scenario(confirmed: true);
        DB::table('notification_channels')->delete();

        $this->artisan('alarms:selftest')
            ->expectsOutputToContain('No notification channels')
            ->assertFailed();
    }

    public function test_a_channel_that_only_carries_critical_reports_why(): void
    {
        $this->scenario(confirmed: true);
        NotificationChannel::where('key', 'duty')->update(['min_level' => 'critical']);

        $this->artisan('alarms:selftest', ['--level' => 'warning'])
            ->expectsOutputToContain('below_min_level')
            ->assertFailed();
    }

    public function test_it_prefers_a_confirmed_definition(): void
    {
        // Defaulting to whichever definition came first made the command report
        // that nothing would be delivered on an appliance that was, by then,
        // fully able to send. True of that definition; misleading about the
        // appliance.
        $confirmed = $this->scenario(confirmed: true);

        AlarmDefinition::create([
            'key' => 'liveness-first', 'name' => 'Sensor silent',
            'condition_type' => 'sensor_offline', 'unit' => 'seconds',
            'critical_at' => 360, 'enabled' => true,
        ]);

        $this->artisan('alarms:selftest')
            ->expectsOutputToContain($confirmed->name)
            ->assertSuccessful();
    }

    public function test_it_names_the_definitions_that_would_still_send_nothing(): void
    {
        $this->scenario(confirmed: true);
        AlarmDefinition::create([
            'key' => 'liveness-x', 'name' => 'Sensor silent', 'condition_type' => 'sensor_offline',
            'unit' => 'seconds', 'critical_at' => 360, 'enabled' => true,
        ]);

        $this->artisan('alarms:selftest')
            ->expectsOutputToContain('Sensor silent')
            ->assertSuccessful();
    }
}
