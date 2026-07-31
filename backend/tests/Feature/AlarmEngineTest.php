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
use App\Support\StructuralVibration;
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
            'site_id' => $site, 'slug' => 'wall-1', 'name' => 'North wall',
            'asset_type' => 'structure', 'monitoring_domain' => 'structural',
            'vibration_standard' => 'din4150_3', 'structure_class' => 'residential',
            'measurement_position' => 'foundation',
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


    // ---- liveness -------------------------------------------------------

    private function livenessDefinition(array $overrides = []): AlarmDefinition
    {
        return AlarmDefinition::create(array_merge([
            'key' => 'test-liveness', 'name' => 'Sensor silent',
            'sensor_id' => $this->sensor->id, 'condition_type' => 'sensor_offline',
            'unit' => 'seconds', 'advisory_at' => 60, 'warning_at' => 300, 'critical_at' => 900,
            'hysteresis' => 0, 'persistence_seconds' => 0, 'clear_seconds' => 0,
            'debounce_seconds' => 0, 'latching' => false, 'enabled' => true,
            'requires_verified_profile' => false,
        ], $overrides));
    }

    public function test_a_silent_sensor_raises_a_liveness_alarm(): void
    {
        $this->livenessDefinition();
        $this->sensor->update(['last_measurement_at' => $this->t0]);

        // Six minutes of silence: past the warning threshold.
        $this->evaluator->evaluateLiveness($this->sensor->fresh(), $this->t0->copy()->addMinutes(6));

        $event = $this->openEvent();
        $this->assertNotNull($event, 'silence must be noticed; nothing else will report it');
        $this->assertSame('warning', $event->level);
        $this->assertSame('seconds', $event->unit);
    }

    public function test_a_reporting_sensor_raises_nothing(): void
    {
        $this->livenessDefinition();
        $this->sensor->update(['last_measurement_at' => $this->t0]);

        $this->evaluator->evaluateLiveness($this->sensor->fresh(), $this->t0->copy()->addSeconds(5));

        $this->assertNull($this->openEvent());
    }

    public function test_liveness_clears_when_data_resumes(): void
    {
        $this->livenessDefinition();
        $this->sensor->update(['last_measurement_at' => $this->t0]);
        $this->evaluator->evaluateLiveness($this->sensor->fresh(), $this->t0->copy()->addMinutes(6));
        $this->assertSame('warning', $this->openEvent()->level);

        // Data arrives again.
        $this->sensor->update(['last_measurement_at' => $this->t0->copy()->addMinutes(10)]);
        $this->evaluator->evaluateLiveness($this->sensor->fresh(), $this->t0->copy()->addMinutes(10));

        $this->assertSame('cleared', $this->openEvent()->state);
    }

    public function test_a_sensor_that_never_reported_does_not_alarm(): void
    {
        $this->livenessDefinition();
        $this->sensor->update(['last_measurement_at' => null]);

        $this->evaluator->evaluateLiveness($this->sensor->fresh(), $this->t0->copy()->addDays(1));

        // Configured but never wired is not the same as having gone quiet, and
        // paging for it on every fresh install would train operators to ignore
        // the alarm that matters.
        $this->assertNull($this->openEvent());
    }

    public function test_liveness_does_not_require_a_verified_profile(): void
    {
        $this->livenessDefinition();
        $this->sensor->model->update(['verification_status' => 'candidate']);
        $this->sensor->update(['last_measurement_at' => $this->t0]);

        $this->evaluator->evaluateLiveness($this->sensor->fresh(), $this->t0->copy()->addMinutes(6));

        // Whether a sensor is talking has nothing to do with whether its
        // register map has been confirmed.
        $this->assertNotNull($this->openEvent());
    }

    public function test_liveness_thresholds_derive_from_the_slowest_channel(): void
    {
        Channel::where('sensor_id', $this->sensor->id)->update(['configured_hz' => 0.2]);
        $definition = $this->evaluator->provisionLivenessDefaults($this->sensor->fresh());

        // Slowest channel is one poll every 5 s, so five missed polls is 25 s -
        // floored at 30 s so a slow group never looks offline between polls.
        $this->assertSame(30.0, $definition->advisory_at);
        $this->assertSame(120.0, $definition->warning_at);
        $this->assertSame('liveness_derived', $definition->source);
        $this->assertFalse($definition->requires_verified_profile);
    }

    public function test_sweep_command_evaluates_liveness(): void
    {
        $this->livenessDefinition();
        $this->sensor->update(['last_measurement_at' => now()->subHour()]);

        $this->artisan('alarms:sweep')->assertSuccessful();

        $this->assertNotNull($this->openEvent());
        $this->assertSame('critical', $this->openEvent()->level);
    }

    // ---- structural, frequency-dependent ---------------------------------

    private function feedWithFrequency(float $velocity, float $frequencyHz, int $offset = 0): array
    {
        return $this->evaluator->evaluate(
            $this->sensor, 'vib_velocity_x', $velocity, 'mm/s',
            $this->t0->copy()->addSeconds($offset), 'good',
            ['vib_frequency_x' => $frequencyHz],
        );
    }

    public function test_structural_defaults_are_provisioned_from_the_standard(): void
    {
        $definition = $this->evaluator->provisionStructuralDefaults($this->asset);

        $this->assertNotNull($definition);
        $this->assertSame('structural_ppv', $definition->condition_type);
        $this->assertSame('din4150_3', $definition->parameters['standard']);
        // Static thresholds stay null: a fixed number would be wrong at most
        // frequencies, which is the whole point of the standard.
        $this->assertNull($definition->critical_at);
        $this->assertSame(StructuralVibration::STATUS, $definition->parameters['standard_tables_status']);
    }

    public function test_an_asset_without_a_standard_gets_no_alarm(): void
    {
        $this->asset->update(['vibration_standard' => null, 'structure_class' => null]);
        $this->assertNull($this->evaluator->provisionStructuralDefaults($this->asset->fresh()));
    }

    public function test_the_same_velocity_alarms_at_low_frequency_but_not_high(): void
    {
        $this->evaluator->provisionStructuralDefaults($this->asset);

        // DIN 4150-3 line 2 foundation: 5 mm/s below 10 Hz, 15 mm/s at 50 Hz.
        // 6 mm/s is past the guideline at 5 Hz...
        $this->feedWithFrequency(6.0, 5.0);
        $this->assertSame('critical', $this->openEvent()->level);
    }

    public function test_high_frequency_raises_the_limit(): void
    {
        $this->evaluator->provisionStructuralDefaults($this->asset);

        // ...but the same 6 mm/s at 50 Hz is only 40% of the 15 mm/s limit,
        // which is below even the advisory fraction. Frequency is not a detail.
        $this->feedWithFrequency(6.0, 50.0);
        $this->assertNull($this->openEvent());
    }

    public function test_without_a_dominant_frequency_nothing_is_evaluated(): void
    {
        $this->evaluator->provisionStructuralDefaults($this->asset);

        // A frequency-dependent standard cannot be applied without a frequency.
        // Assuming one to produce a number would be a silent invention.
        $this->evaluator->evaluate(
            $this->sensor, 'vib_velocity_x', 50.0, 'mm/s', $this->t0, 'good', [],
        );

        $this->assertNull($this->openEvent());
    }

    public function test_sensitive_structures_alarm_earlier_than_commercial(): void
    {
        $this->asset->update(['structure_class' => 'sensitive']);
        $this->evaluator->provisionStructuralDefaults($this->asset->fresh());

        // 3 mm/s is the guideline for a sensitive structure below 10 Hz.
        $this->feedWithFrequency(3.2, 6.0);
        $this->assertSame('critical', $this->openEvent()->level);
    }

    public function test_top_floor_position_uses_the_frequency_independent_row(): void
    {
        $this->asset->update(['measurement_position' => 'top_floor']);
        $this->evaluator->provisionStructuralDefaults($this->asset->fresh());

        // Residential top floor is 15 mm/s at every frequency. The same 8 mm/s
        // measured at the foundation at 5 Hz would be critical against the
        // 5 mm/s limit there; here it is 53% of the limit, so advisory.
        // Measurement position is not a detail either.
        $this->feedWithFrequency(8.0, 5.0);
        $event = $this->openEvent();
        $this->assertNotNull($event);
        $this->assertSame('advisory', $event->level);
        $this->assertEqualsWithDelta(7.5, $event->threshold, 1e-9);
    }

    public function test_structural_alarms_latch(): void
    {
        $this->evaluator->provisionStructuralDefaults($this->asset);

        // A blast or a piling strike is over in seconds. If the alarm cleared
        // itself nobody would ever see that the building was shaken.
        $this->feedWithFrequency(9.0, 5.0);
        $this->assertSame('critical', $this->openEvent()->level);

        $this->feedWithFrequency(0.1, 5.0, 10);
        $this->assertSame('critical', $this->openEvent()->level);
        $this->assertSame('active', $this->openEvent()->state);
    }
}
