<?php

namespace Tests\Feature;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Appliance;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\Sensor;
use App\Models\SensorModel;
use App\Services\AlarmEvaluator;
use App\Support\Iso10816;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlarmEngineTest extends TestCase
{
    use RefreshDatabase;

    private Sensor $sensor;
    private Asset $asset;
    private AlarmEvaluator $evaluator;
    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        $site = \DB::table('sites')->insertGetId([
            'slug' => 'plant', 'name' => 'Plant', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->asset = Asset::create([
            'site_id' => $site, 'slug' => 'pump-1', 'name' => 'Pump 1',
            'iso_10816_class' => 'class_ii', 'rated_power_kw' => 45,
        ]);
        $appliance = Appliance::create(['appliance_id' => 'QV-EDGE-TEST', 'name' => 'test']);
        $model = SensorModel::create([
            'model' => 'WTVB01-485', 'manufacturer' => 'WitMotion',
            'profile_version' => '1.0.0', 'verification_status' => 'verified',
        ]);
        $this->sensor = Sensor::create([
            'sensor_id' => 'SENSOR-001', 'appliance_id' => $appliance->id,
            'sensor_model_id' => $model->id, 'asset_id' => $this->asset->id, 'slave_id' => 80,
        ]);
        Channel::create([
            'sensor_id' => $this->sensor->id, 'channel_key' => 'vib_velocity_x',
            'group_key' => 'vibration_velocity', 'label' => 'Velocity X',
            'quantity' => 'vibration_velocity', 'unit' => 'mm/s',
        ]);
        $this->sensor->load('channels', 'model');

        $this->evaluator = new AlarmEvaluator();
        $this->t0 = Carbon::parse('2026-07-31T12:00:00Z');
    }

    private function feed(float $value, int $offsetSeconds = 0): array
    {
        return $this->evaluator->evaluate(
            $this->sensor, 'vib_velocity_x', $value, 'mm/s',
            $this->t0->copy()->addSeconds($offsetSeconds),
        );
    }

    private function definition(array $overrides = []): AlarmDefinition
    {
        return AlarmDefinition::create(array_merge([
            'key' => 'test-velocity', 'name' => 'Velocity', 'asset_id' => $this->asset->id,
            'quantity' => 'vibration_velocity', 'condition_type' => 'high_threshold',
            'unit' => 'mm/s', 'advisory_at' => 1.12, 'warning_at' => 2.80, 'critical_at' => 7.10,
            'hysteresis' => 0.0, 'persistence_seconds' => 0, 'clear_seconds' => 0,
            'debounce_seconds' => 0, 'latching' => false, 'enabled' => true,
        ], $overrides));
    }

    private function openEvent(): ?AlarmEvent
    {
        return AlarmEvent::where('sensor_id', $this->sensor->id)->orderByDesc('id')->first();
    }

    public function test_iso_thresholds_match_the_standard(): void
    {
        $classII = Iso10816::thresholds('class_ii');
        $this->assertSame(1.12, $classII['advisory']);
        $this->assertSame(2.80, $classII['warning']);
        $this->assertSame(7.10, $classII['critical']);
    }

    public function test_zones_map_to_the_standards_letters(): void
    {
        $this->assertSame('A', Iso10816::zoneFor('class_ii', 0.5));
        $this->assertSame('B', Iso10816::zoneFor('class_ii', 2.0));
        $this->assertSame('C', Iso10816::zoneFor('class_ii', 5.0));
        $this->assertSame('D', Iso10816::zoneFor('class_ii', 9.0));
    }

    public function test_normal_values_raise_nothing(): void
    {
        $this->definition();
        $this->assertSame([], $this->feed(0.4));
        $this->assertNull($this->openEvent());
    }

    public function test_crossing_a_threshold_raises_at_the_right_level(): void
    {
        $this->definition();
        $this->feed(3.0);

        $event = $this->openEvent();
        $this->assertSame('warning', $event->level);
        $this->assertSame(2.80, $event->threshold);
        $this->assertSame('mm/s', $event->unit);
    }

    public function test_escalation_updates_the_same_event(): void
    {
        $this->definition();
        $this->feed(1.5);
        $this->feed(3.0, 1);
        $this->feed(8.0, 2);

        $this->assertSame(1, AlarmEvent::count(), 'escalation must not spawn duplicate events');
        $event = $this->openEvent();
        $this->assertSame('critical', $event->level);
        $this->assertSame('critical', $event->peak_level);
        $this->assertEqualsWithDelta(8.0, $event->peak_value, 1e-9);
        $this->assertSame(3, $event->transitions()->count());
    }

    public function test_auto_clear_closes_the_event_but_keeps_the_history(): void
    {
        $this->definition();
        $this->feed(3.0);
        $this->feed(0.2, 1);

        $event = $this->openEvent();
        $this->assertSame('cleared', $event->state);
        $this->assertNotNull($event->cleared_at);
        // The excursion still happened and must remain visible.
        $this->assertSame('warning', $event->peak_level);
        $this->assertEqualsWithDelta(3.0, $event->peak_value, 1e-9);
    }

    public function test_hysteresis_prevents_a_raise_clear_storm(): void
    {
        $this->definition(['hysteresis' => 0.5]);

        $this->feed(2.85);              // raises: above 2.80
        $this->assertSame('warning', $this->openEvent()->level);

        $this->feed(2.50, 1);           // still above clear threshold 2.30
        $this->assertSame('warning', $this->openEvent()->level, 'must not clear inside the band');

        $this->feed(2.20, 2);           // now below 2.30
        $this->assertSame('advisory', $this->openEvent()->level);
    }

    public function test_persistence_delays_the_raise(): void
    {
        $this->definition(['persistence_seconds' => 30]);

        $this->feed(3.0);
        $this->assertSame('normal', $this->openEvent()->level, 'a single sample must not raise');

        $this->feed(3.0, 10);
        $this->assertSame('normal', $this->openEvent()->level);

        $this->feed(3.0, 31);
        $this->assertSame('warning', $this->openEvent()->level);
    }

    public function test_a_transient_spike_never_raises(): void
    {
        $this->definition(['persistence_seconds' => 30]);
        $this->feed(9.0);        // one noisy sample
        $this->feed(0.3, 1);     // back to normal

        $event = $this->openEvent();
        $this->assertSame('normal', $event->level);
        $this->assertSame(0, $event->transitions()->count(), 'no announcement for a single spike');
    }

    public function test_clear_persistence_is_separate_from_raise(): void
    {
        $this->definition(['persistence_seconds' => 0, 'clear_seconds' => 60]);

        $this->feed(3.0);
        $this->assertSame('warning', $this->openEvent()->level, 'raise is immediate');

        // The clear countdown starts when the value first drops, at t+10, so it
        // expires at t+70 rather than t+60.
        $this->feed(0.2, 10);
        $this->assertSame('warning', $this->openEvent()->level, 'clear must wait');

        $this->feed(0.2, 69);
        $this->assertSame('warning', $this->openEvent()->level, 'still inside the clear window');

        $this->feed(0.2, 71);
        $this->assertSame('cleared', $this->openEvent()->state);
    }

    public function test_latching_survives_the_value_returning_to_normal(): void
    {
        $this->definition(['latching' => true]);

        $this->feed(8.0);
        $this->assertSame('critical', $this->openEvent()->level);

        $this->feed(0.1, 5);
        $event = $this->openEvent();
        // The machine did something. Somebody must see it, even though the
        // evidence has gone.
        $this->assertSame('critical', $event->level);
        $this->assertSame('active', $event->state);
    }

    public function test_acknowledging_a_latched_alarm_allows_it_to_clear(): void
    {
        $this->definition(['latching' => true]);
        $this->feed(8.0);
        $this->evaluator->acknowledge($this->openEvent(), 42, 'inspected, bearing replaced');

        $this->feed(0.1, 5);

        $event = $this->openEvent();
        $this->assertSame('cleared', $event->state);
        $this->assertSame(42, $event->acknowledged_by);
        $this->assertSame('inspected, bearing replaced', $event->acknowledgement_note);
        $this->assertTrue($event->transitions()->where('reason', 'acknowledged')->exists());
    }

    public function test_debounce_limits_transition_rate(): void
    {
        $this->definition(['debounce_seconds' => 30]);
        $this->feed(3.0);
        $this->feed(8.0, 5);   // inside the debounce window

        $this->assertSame('warning', $this->openEvent()->level, 'escalation must wait out debounce');
        $this->feed(8.0, 40);
        $this->assertSame('critical', $this->openEvent()->level);
    }

    public function test_failed_reads_do_not_alarm(): void
    {
        $this->definition();
        $this->assertSame([], $this->evaluator->evaluate(
            $this->sensor, 'vib_velocity_x', null, 'mm/s', $this->t0, 'bad'
        ));
        $this->assertSame([], $this->evaluator->evaluate(
            $this->sensor, 'vib_velocity_x', 0.0, 'mm/s', $this->t0, 'bad'
        ));
        $this->assertNull($this->openEvent());
    }

    public function test_unverified_profile_cannot_drive_alarms(): void
    {
        $this->definition(['requires_verified_profile' => true]);
        $this->sensor->model->update(['verification_status' => 'candidate']);
        $this->sensor->load('model');

        $this->feed(9.0);

        // ADR-005 enforced at the point it matters: an unconfirmed register map
        // must never trigger an alarm somebody acts on.
        $this->assertNull($this->openEvent());
    }

    public function test_definitions_are_scoped_by_quantity(): void
    {
        $this->definition(['quantity' => 'temperature']);
        $this->feed(9.0);
        $this->assertNull($this->openEvent(), 'a temperature rule must ignore a velocity channel');
    }

    public function test_disabled_definitions_are_ignored(): void
    {
        $this->definition(['enabled' => false]);
        $this->feed(9.0);
        $this->assertNull($this->openEvent());
    }

    public function test_iso_defaults_are_provisioned_from_the_machine_class(): void
    {
        $definition = $this->evaluator->provisionIsoDefaults($this->asset);

        $this->assertNotNull($definition);
        $this->assertSame(1.12, $definition->advisory_at);
        $this->assertSame(2.80, $definition->warning_at);
        $this->assertSame(7.10, $definition->critical_at);
        $this->assertSame('iso10816', $definition->source);
        $this->assertGreaterThan(0, $definition->hysteresis);
    }

    public function test_iso_provisioning_is_idempotent(): void
    {
        $this->evaluator->provisionIsoDefaults($this->asset);
        $this->evaluator->provisionIsoDefaults($this->asset);
        $this->assertSame(1, AlarmDefinition::where('source', 'iso10816')->count());
    }

    public function test_inferred_class_is_labelled_as_inferred(): void
    {
        $this->asset->update(['iso_10816_class' => null, 'rated_power_kw' => 45]);
        $definition = $this->evaluator->provisionIsoDefaults($this->asset->fresh());

        $this->assertSame('class_ii', $definition->parameters['machine_class']);
        $this->assertTrue($definition->parameters['derived_from_power']);
        $this->assertSame('iso10816_inferred', $definition->source);
        $this->assertStringContainsString('confirm the mounting', $definition->description);
    }

    public function test_asset_without_class_or_power_gets_no_guessed_thresholds(): void
    {
        $this->asset->update(['iso_10816_class' => null, 'rated_power_kw' => null]);
        $this->assertNull($this->evaluator->provisionIsoDefaults($this->asset->fresh()));
    }

    public function test_provisioned_iso_alarm_fires_on_real_data(): void
    {
        $this->evaluator->provisionIsoDefaults($this->asset);

        // Under the persistence window nothing raises...
        $this->feed(8.5);
        $this->assertSame('normal', $this->openEvent()->level);

        // ...and once it holds, straight to critical: 8.5 mm/s is zone D for a
        // 45 kW machine.
        $this->feed(8.5, 15);
        $this->assertSame('critical', $this->openEvent()->level);
    }
}
