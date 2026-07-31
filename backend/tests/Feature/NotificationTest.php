<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\NotificationChannel;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Sensor $sensor;
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.0.0', 'verification_status' => 'verified',
        ]);
        $this->sensor = Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'slave_id' => 80,
        ]);
        $this->sent = [];
    }

    private function dispatcher(): NotificationDispatcher
    {
        return new NotificationDispatcher([
            'log' => function (array $message): void { $this->sent[] = $message['subject']; },
            'broken' => function (): void { throw new \RuntimeException('transport exploded'); },
        ]);
    }

    private function channel(array $overrides = []): NotificationChannel
    {
        return NotificationChannel::create(array_merge([
            'key' => 'ops', 'name' => 'Operations', 'transport' => 'log',
            'enabled' => true, 'min_level' => 'warning',
            'max_per_hour' => 30, 'dedupe_window_seconds' => 900,
        ], $overrides));
    }

    private function alarm(string $level = 'critical', bool $provisional = false): AlarmEvent
    {
        $definition = AlarmDefinition::create([
            'key' => 'd-'.uniqid(), 'name' => 'Structural vibration',
            'condition_type' => 'high_threshold', 'unit' => 'mm/s',
            'warning_at' => 5.0, 'critical_at' => 15.0, 'enabled' => true,
            'thresholds_confirmed_at' => $provisional ? null : now(),
            'thresholds_confirmed_by' => $provisional ? null : 'J. Engineer',
            'thresholds_reference' => $provisional ? null : 'DIN 4150-3 Table 1',
        ]);

        return AlarmEvent::create([
            'alarm_definition_id' => $definition->id, 'sensor_id' => $this->sensor->id,
            'channel_key' => 'vib_velocity_x', 'level' => $level, 'peak_level' => $level,
            'state' => 'active', 'provisional' => $provisional,
            'trigger_value' => 16.0, 'threshold' => 15.0, 'unit' => 'mm/s', 'raised_at' => now(),
        ]);
    }

    // ---- the happy path --------------------------------------------------

    public function test_a_confirmed_alarm_is_delivered(): void
    {
        $this->channel();
        $results = $this->dispatcher()->dispatch($this->alarm());

        $this->assertSame('sent', $results[0]['status']);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('CRITICAL', $this->sent[0]);
    }

    public function test_the_message_states_who_confirmed_the_thresholds(): void
    {
        $this->channel();
        $this->dispatcher()->dispatch($this->alarm());

        $body = DB::table('notification_deliveries')->value('body');
        // The recipient should be able to see whose judgement they are acting on.
        $this->assertStringContainsString('confirmed by J. Engineer', $body);
        $this->assertStringContainsString('DIN 4150-3 Table 1', $body);
    }

    // ---- everything it refuses to send -----------------------------------

    public function test_provisional_alarms_never_notify(): void
    {
        $this->channel();
        $results = $this->dispatcher()->dispatch($this->alarm(provisional: true));

        // Visible on the dashboard; that is the extent of what unverified
        // numbers have earned.
        $this->assertSame('suppressed', $results[0]['status']);
        $this->assertSame('provisional_thresholds', $results[0]['reason']);
        $this->assertSame([], $this->sent);
    }

    public function test_below_the_severity_floor_is_skipped(): void
    {
        $this->channel(['min_level' => 'critical']);
        $results = $this->dispatcher()->dispatch($this->alarm('warning'));

        $this->assertSame('skipped', $results[0]['status']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_repeat_of_the_same_condition_is_deduplicated(): void
    {
        $this->channel();
        $dispatcher = $this->dispatcher();

        $dispatcher->dispatch($this->alarm());
        $second = $dispatcher->dispatch($this->alarm());

        $this->assertSame('suppressed', $second[0]['status']);
        $this->assertSame('duplicate', $second[0]['reason']);
        $this->assertCount(1, $this->sent, 'the same news must not be sent twice');
    }

    public function test_rate_limit_caps_a_flapping_input(): void
    {
        $this->channel(['max_per_hour' => 2, 'dedupe_window_seconds' => 0]);
        $dispatcher = $this->dispatcher();

        for ($i = 0; $i < 5; $i++) {
            $dispatcher->dispatch($this->alarm());
        }

        // A flapping input must not be able to empty somebody's mailbox.
        $this->assertCount(2, $this->sent);
        $this->assertSame(
            3,
            DB::table('notification_deliveries')->where('suppressed_reason', 'rate_limited')->count(),
        );
    }

    public function test_quiet_hours_suppress_a_warning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 02:00:00'));
        $this->channel(['quiet_from' => '22:00:00', 'quiet_to' => '07:00:00']);

        $results = $this->dispatcher()->dispatch($this->alarm('warning'));

        $this->assertSame('quiet_hours', $results[0]['reason']);
        Carbon::setTestNow();
    }

    public function test_quiet_hours_never_suppress_critical(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 02:00:00'));
        $this->channel(['quiet_from' => '22:00:00', 'quiet_to' => '07:00:00']);

        $results = $this->dispatcher()->dispatch($this->alarm('critical'));

        // A critical alarm at 3am is exactly what somebody signed up for.
        $this->assertSame('sent', $results[0]['status']);
        Carbon::setTestNow();
    }

    public function test_a_shelved_alarm_is_suppressed(): void
    {
        $this->channel();
        $alarm = $this->alarm();
        $alarm->update(['shelved_until' => now()->addHour()]);

        $this->assertSame('shelved', $this->dispatcher()->dispatch($alarm)[0]['reason']);
    }

    public function test_a_disabled_channel_receives_nothing(): void
    {
        $this->channel(['enabled' => false]);
        $this->assertSame([], $this->dispatcher()->dispatch($this->alarm()));
    }

    // ---- failures --------------------------------------------------------

    public function test_a_failed_delivery_is_recorded_not_swallowed(): void
    {
        $this->channel(['transport' => 'broken']);
        $results = $this->dispatcher()->dispatch($this->alarm());

        // "Nobody was told" is precisely what an investigation needs to establish.
        $this->assertSame('failed', $results[0]['status']);
        $delivery = DB::table('notification_deliveries')->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('transport exploded', $delivery->last_error);
    }

    public function test_an_unknown_transport_fails_loudly(): void
    {
        $this->channel(['transport' => 'carrier_pigeon']);
        $this->assertSame('failed', $this->dispatcher()->dispatch($this->alarm())[0]['status']);
    }

    public function test_every_suppression_records_its_reason(): void
    {
        $this->channel();
        $this->dispatcher()->dispatch($this->alarm(provisional: true));

        // "Why was I not told" must always have an answer.
        $this->assertSame(
            'provisional_thresholds',
            DB::table('notification_deliveries')->value('suppressed_reason'),
        );
    }

    // ---- escalation ------------------------------------------------------

    public function test_unacknowledged_alarms_escalate(): void
    {
        $this->channel(['escalate_after_minutes' => 15, 'escalates_to' => 'manager']);
        $this->channel([
            'key' => 'manager', 'name' => 'Manager', 'min_level' => 'warning',
            'escalation_only' => true,
        ]);

        $dispatcher = $this->dispatcher();
        $dispatcher->dispatch($this->alarm());
        // Only the primary channel hears the first message.
        $this->assertCount(1, $this->sent);

        // The first message may have gone to a phone lying face-down on a desk.
        DB::table('notification_deliveries')->update(['sent_at' => now()->subMinutes(30)]);
        $escalated = $dispatcher->escalateStale();

        $this->assertSame(1, $escalated);
        $this->assertCount(2, $this->sent);
    }

    public function test_acknowledged_alarms_do_not_escalate(): void
    {
        $this->channel(['escalate_after_minutes' => 15, 'escalates_to' => 'manager']);
        $this->channel([
            'key' => 'manager', 'name' => 'Manager', 'min_level' => 'warning',
            'escalation_only' => true,
        ]);

        $dispatcher = $this->dispatcher();
        $alarm = $this->alarm();
        $dispatcher->dispatch($alarm);
        $alarm->update(['acknowledged_at' => now()]);
        DB::table('notification_deliveries')->update(['sent_at' => now()->subMinutes(30)]);

        $this->assertSame(0, $dispatcher->escalateStale());
    }

    public function test_escalation_waits_for_the_configured_delay(): void
    {
        $this->channel(['escalate_after_minutes' => 60, 'escalates_to' => 'manager']);
        $this->channel([
            'key' => 'manager', 'name' => 'Manager', 'min_level' => 'warning',
            'escalation_only' => true,
        ]);

        $dispatcher = $this->dispatcher();
        $dispatcher->dispatch($this->alarm());
        DB::table('notification_deliveries')->update(['sent_at' => now()->subMinutes(10)]);

        $this->assertSame(0, $dispatcher->escalateStale());
    }

    public function test_an_escalation_only_channel_is_skipped_on_first_dispatch(): void
    {
        $this->channel(['key' => 'manager', 'name' => 'Manager', 'escalation_only' => true]);

        // Otherwise escalating simply tells the same person a second time.
        $this->assertSame([], $this->dispatcher()->dispatch($this->alarm()));
    }
}
