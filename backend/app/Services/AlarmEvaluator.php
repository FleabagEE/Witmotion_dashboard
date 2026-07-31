<?php

namespace App\Services;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\AlarmTransition;
use App\Models\Asset;
use App\Models\Sensor;
use App\Support\StructuralVibration;
use Illuminate\Support\Carbon;

/**
 * Evaluates measurements against alarm definitions.
 *
 * The hard part of an alarm engine is not deciding that 8.2 exceeds 7.1. It is
 * everything around that:
 *
 *  - hysteresis, so a value resting on a boundary does not raise and clear
 *    hundreds of times;
 *  - persistence, so one noisy sample does not wake somebody at 03:00;
 *  - debounce, so a flapping input cannot generate a transition storm;
 *  - latching, so a transient excursion still demands acknowledgement after the
 *    value has returned to normal - the machine did something, and somebody
 *    should see it even if the evidence is gone.
 *
 * An alarm is only as trustworthy as the register map behind it, so definitions
 * default to requiring a verified profile (ADR-005).
 */
class AlarmEvaluator
{
    /** @var array<int, array<int, AlarmDefinition>> */
    private array $definitionCache = [];

    /**
     * @return array<int, AlarmEvent> events created or changed by this reading
     */
    public function evaluate(
        Sensor $sensor,
        string $channelKey,
        ?float $value,
        ?string $unit,
        Carbon $measuredAt,
        string $quality = 'good',
        array $siblings = [],
    ): array {
        // A failed read is not a low reading. Alarming on it would report a
        // machine as healthy when in fact nothing was measured at all; sensor
        // liveness is a separate condition type.
        if ($value === null || $quality === 'bad') {
            return [];
        }

        $changed = [];
        foreach ($this->definitionsFor($sensor, $channelKey) as $definition) {
            if ($definition->condition_type === 'structural_ppv'
                && ! $this->resolveStructuralThresholds($definition, $channelKey, $siblings)) {
                // Without the companion dominant frequency the standard's limit
                // is undefined. Guessing a frequency to get a number would be
                // exactly the silent inference this project refuses to make.
                continue;
            }
            $event = $this->applyDefinition($definition, $sensor, $channelKey, $value, $unit, $measuredAt);
            if ($event !== null) {
                $changed[] = $event;
            }
        }

        return $changed;
    }

    /** @return iterable<AlarmDefinition> */
    private function definitionsFor(Sensor $sensor, string $channelKey): iterable
    {
        $cacheKey = $sensor->id;
        if (! isset($this->definitionCache[$cacheKey])) {
            $this->definitionCache[$cacheKey] = AlarmDefinition::query()
                ->where('enabled', true)
                ->whereIn('condition_type', ['high_threshold', 'structural_ppv'])
                ->where(fn ($q) => $q->whereNull('sensor_id')->orWhere('sensor_id', $sensor->id))
                ->where(fn ($q) => $q->whereNull('asset_id')->orWhere('asset_id', $sensor->asset_id))
                ->get()
                ->all();
        }

        $channelQuantity = $sensor->channels->firstWhere('channel_key', $channelKey)?->quantity;

        foreach ($this->definitionCache[$cacheKey] as $definition) {
            if ($definition->channel_key !== null && $definition->channel_key !== $channelKey) {
                continue;
            }
            if ($definition->quantity !== null && $definition->quantity !== $channelQuantity) {
                continue;
            }
            if ($definition->requires_verified_profile && ! ($sensor->model?->isTrustworthy() ?? false)) {
                continue;
            }
            yield $definition;
        }
    }

    /**
     * Resolve a frequency-dependent structural limit for this sample.
     *
     * DIN 4150-3 and BS 7385-2 grade by frequency, so the threshold is not a
     * property of the alarm - it is a property of the measurement. The limit is
     * taken from the dominant frequency the device reported for the SAME axis,
     * so a horizontal excursion is judged against the horizontal frequency.
     */
    private function resolveStructuralThresholds(
        AlarmDefinition $definition,
        string $channelKey,
        array $siblings,
    ): bool {
        $parameters = $definition->parameters ?? [];
        $axis = str_contains($channelKey, '_') ? substr($channelKey, strrpos($channelKey, '_') + 1) : null;
        if ($axis === null) {
            return false;
        }

        $frequency = $siblings['vib_frequency_'.$axis] ?? null;
        if ($frequency === null || $frequency <= 0.0) {
            return false;
        }

        $limit = StructuralVibration::limitFor(
            StructuralVibration::tableKey(
                (string) ($parameters['standard'] ?? ''),
                (string) ($parameters['position'] ?? 'foundation'),
                (string) ($parameters['duration'] ?? 'transient'),
            ),
            (string) ($parameters['structure_class'] ?? ''),
            (float) $frequency,
            (string) ($parameters['position'] ?? 'foundation'),
        );
        if ($limit === null) {
            return false;
        }

        $fractions = $parameters['level_fractions'] ?? ['advisory' => 0.5, 'warning' => 0.75, 'critical' => 1.0];
        // Set on the in-memory instance only; never persisted, because the
        // values are valid for this sample's frequency and no other.
        $definition->advisory_at = round($limit * $fractions['advisory'], 4);
        $definition->warning_at = round($limit * $fractions['warning'], 4);
        $definition->critical_at = round($limit * $fractions['critical'], 4);
        $definition->hysteresis = round($limit * 0.1, 4);

        return true;
    }

    private function applyDefinition(
        AlarmDefinition $definition,
        Sensor $sensor,
        string $channelKey,
        float $value,
        ?string $unit,
        Carbon $at,
    ): ?AlarmEvent {
        $event = AlarmEvent::where('alarm_definition_id', $definition->id)
            ->where('sensor_id', $sensor->id)
            ->where('channel_key', $channelKey)
            ->where('state', 'active')
            ->first();

        $currentLevel = $event?->level ?? 'normal';
        $target = $this->targetLevel($definition, $value, $currentLevel);

        if ($event === null && $target === 'normal') {
            return null;
        }

        if ($event === null) {
            $event = new AlarmEvent([
                'alarm_definition_id' => $definition->id,
                'sensor_id' => $sensor->id,
                'asset_id' => $sensor->asset_id,
                'channel_key' => $channelKey,
                'level' => 'normal',
                'peak_level' => 'normal',
                'state' => 'active',
                // Stamped at creation from the definition in force. An event
                // raised on unconfirmed numbers stays marked as such for its
                // whole life, even if the thresholds are confirmed later - what
                // matters is what was known when it fired.
                'provisional' => ! $definition->thresholdsConfirmed(),
                'unit' => $unit ?? $definition->unit,
                'raised_at' => $at,
            ]);
            $event->save();
        }

        $event->last_evaluated_at = $at;
        $event->trigger_value = $value;
        if ($event->peak_value === null || $value > $event->peak_value) {
            $event->peak_value = $value;
        }

        // Latching: a raised alarm stays raised until somebody acknowledges it,
        // even once the value recovers.
        if ($definition->latching && ! $event->isAcknowledged()
            && AlarmEvent::rank($target) < AlarmEvent::rank($currentLevel)) {
            $event->candidate_level = null;
            $event->candidate_since = null;
            $event->save();

            return null;
        }

        if ($target === $currentLevel) {
            $event->candidate_level = null;
            $event->candidate_since = null;
            $event->save();

            return null;
        }

        // Persistence: the new level must hold before it is announced. Raising
        // and clearing get separate budgets, because operators usually want a
        // fast raise and a slow clear.
        $required = AlarmEvent::rank($target) > AlarmEvent::rank($currentLevel)
            ? $definition->persistence_seconds
            : $definition->clear_seconds;

        if ($event->candidate_level !== $target) {
            $event->candidate_level = $target;
            $event->candidate_since = $at;
            $event->save();

            if ($required > 0) {
                return null;
            }
        }

        if ($required > 0 && $event->candidate_since !== null
            && $event->candidate_since->diffInSeconds($at) < $required) {
            $event->save();

            return null;
        }

        if ($definition->debounce_seconds > 0 && $event->last_changed_at !== null
            && $event->last_changed_at->diffInSeconds($at) < $definition->debounce_seconds) {
            $event->save();

            return null;
        }

        return $this->transition($event, $definition, $currentLevel, $target, $value, $at);
    }

    private function transition(
        AlarmEvent $event,
        AlarmDefinition $definition,
        string $from,
        string $to,
        float $value,
        Carbon $at,
    ): AlarmEvent {
        $event->level = $to;
        $event->threshold = $definition->thresholds()[$to] ?? null;
        $event->last_changed_at = $at;
        $event->candidate_level = null;
        $event->candidate_since = null;

        if (AlarmEvent::rank($to) > AlarmEvent::rank($event->peak_level)) {
            $event->peak_level = $to;
        }
        if ($from === 'normal') {
            $event->raised_at = $at;
        }

        if ($to === 'normal') {
            // Auto-clear. The event is closed but kept: the history of what
            // happened is the point, not the current value.
            $event->state = 'cleared';
            $event->cleared_at = $at;
        }

        $event->save();

        // Notify on escalation only. Telling somebody an alarm got less severe,
        // or cleared on its own, is noise that trains people to ignore the
        // channel - and the dashboard already shows it.
        if (AlarmEvent::rank($to) > AlarmEvent::rank($from) && $to !== 'normal') {
            try {
                app(NotificationDispatcher::class)->dispatch($event);
            } catch (\Throwable $exception) {
                // A notification fault must never roll back the alarm itself.
                \Illuminate\Support\Facades\Log::error('notification dispatch failed', [
                    'alarm_event_id' => $event->id, 'error' => $exception->getMessage(),
                ]);
            }
        }

        AlarmTransition::create([
            'alarm_event_id' => $event->id,
            'from_level' => $from,
            'to_level' => $to,
            'reason' => $to === 'normal' ? 'auto_clear' : (
                AlarmEvent::rank($to) > AlarmEvent::rank($from) ? 'escalation' : 'de_escalation'
            ),
            'value' => $value,
            'threshold' => $event->threshold,
            'occurred_at' => $at,
        ]);

        return $event;
    }

    /**
     * Highest level the value satisfies, with hysteresis applied on the way down.
     */
    private function targetLevel(AlarmDefinition $definition, float $value, string $currentLevel): string
    {
        $thresholds = $definition->thresholds();
        $hysteresis = $definition->hysteresis;

        foreach ($thresholds as $level => $raiseAt) {
            // Rising into a level uses the raise threshold; staying in one uses
            // the lower clear threshold, so a value hovering on the boundary
            // does not oscillate.
            $effective = AlarmEvent::rank($level) <= AlarmEvent::rank($currentLevel)
                ? $raiseAt - $hysteresis
                : $raiseAt;

            if ($value >= $effective) {
                return $level;
            }
        }

        return 'normal';
    }

    /**
     * Evaluate liveness conditions for one sensor.
     *
     * A sensor that stops answering produces no measurements, so nothing would
     * ever trigger an evaluation. This is driven by a sweep instead, and it
     * matters more than any threshold alarm: silence is indistinguishable from
     * "everything is fine" unless something actively looks for it.
     *
     * Expressed as seconds-since-last-data fed through the same threshold
     * machinery, so hysteresis, persistence, debounce and latching all apply
     * without a second implementation.
     *
     * @return array<int, AlarmEvent>
     */
    public function evaluateLiveness(Sensor $sensor, ?Carbon $now = null): array
    {
        $now ??= now();
        $last = $sensor->last_measurement_at;
        $silentFor = $last === null
            ? null
            : max(0.0, (float) $last->diffInSeconds($now, absolute: true));

        $changed = [];
        foreach ($this->livenessDefinitionsFor($sensor) as $definition) {
            if ($silentFor === null) {
                // Never reported at all. Not the same as having gone quiet, and
                // alarming on it would page somebody for every sensor that has
                // been configured but not yet wired.
                continue;
            }
            $event = $this->applyDefinition(
                $definition, $sensor, $definition->channel_key ?? '__sensor__',
                $silentFor, 'seconds', $now,
            );
            if ($event !== null) {
                $changed[] = $event;
            }
        }

        return $changed;
    }

    /** @return iterable<AlarmDefinition> */
    private function livenessDefinitionsFor(Sensor $sensor): iterable
    {
        $definitions = AlarmDefinition::query()
            ->where('enabled', true)
            ->where('condition_type', 'sensor_offline')
            ->where(fn ($q) => $q->whereNull('sensor_id')->orWhere('sensor_id', $sensor->id))
            ->where(fn ($q) => $q->whereNull('asset_id')->orWhere('asset_id', $sensor->asset_id))
            ->get();

        foreach ($definitions as $definition) {
            // Liveness deliberately ignores requires_verified_profile: whether a
            // sensor is talking at all has nothing to do with whether its
            // register map has been confirmed.
            yield $definition;
        }
    }

    /**
     * Default liveness alarm for a sensor, derived from its actual poll rates.
     *
     * Thresholds come from the slowest configured channel, so a group polled
     * once a minute does not look offline between polls.
     */
    public function provisionLivenessDefaults(Sensor $sensor): AlarmDefinition
    {
        $slowestHz = (float) ($sensor->channels()->whereNotNull('configured_hz')->min('configured_hz') ?: 1.0);
        $period = $slowestHz > 0 ? 1.0 / $slowestHz : 1.0;
        $advisory = max(30.0, $period * 5);

        return AlarmDefinition::updateOrCreate(
            ['key' => "liveness:sensor:{$sensor->id}"],
            [
                'name' => "Sensor silent - {$sensor->sensor_id}",
                'description' => sprintf(
                    'Raises when no measurement has arrived. Derived from the slowest configured '
                    .'channel (%.3f Hz, one poll every %.1f s).', $slowestHz, $period,
                ),
                'sensor_id' => $sensor->id,
                'condition_type' => 'sensor_offline',
                'unit' => 'seconds',
                'advisory_at' => round($advisory, 1),
                'warning_at' => round($advisory * 4, 1),
                'critical_at' => round($advisory * 12, 1),
                'hysteresis' => round($advisory * 0.2, 1),
                'persistence_seconds' => 0,
                'clear_seconds' => 0,
                'debounce_seconds' => 0,
                'latching' => false,
                'enabled' => true,
                'requires_verified_profile' => false,
                'source' => 'liveness_derived',
                // Self-confirming: these numbers come from this appliance's own
                // poll configuration, not from an external standard, so their
                // provenance is checkable in the config file. Nothing for a
                // human to verify against a document that does not exist.
                'thresholds_confirmed_at' => now(),
                'thresholds_confirmed_by' => 'system (derived)',
                'thresholds_reference' => sprintf(
                    'Derived from the slowest configured channel rate (%.3f Hz) for sensor %s',
                    $slowestHz, $sensor->sensor_id,
                ),
                'parameters' => ['slowest_hz' => $slowestHz],
            ],
        );
    }

    public function acknowledge(AlarmEvent $event, ?int $userId, ?string $note = null): AlarmEvent
    {
        $event->acknowledged_at = now();
        $event->acknowledged_by = $userId;
        $event->acknowledgement_note = $note;
        $event->save();

        AlarmTransition::create([
            'alarm_event_id' => $event->id,
            'from_level' => $event->level,
            'to_level' => $event->level,
            'reason' => 'acknowledged',
            'value' => $event->trigger_value,
            'actor_id' => $userId,
            'occurred_at' => now(),
        ]);

        return $event;
    }

    /**
     * Provision structural alarms for an asset, one per applicable duration.
     *
     * A building exposed to both blasting and traffic needs both evaluated:
     * DIN 4150-3 gives separate, much stricter limits for continuous vibration,
     * because a structure tolerates one blast far better than months of traffic.
     * Only combinations the standard actually tabulates are created - it defines
     * continuous limits for the topmost floor only, and inventing a foundation
     * value would be worse than having none.
     *
     * @return array<int, AlarmDefinition>
     */
    public function provisionStructuralDefaults(Asset $asset): array
    {
        $standard = $asset->vibration_standard;
        $class = $asset->structure_class;
        $position = $asset->measurement_position ?: 'foundation';

        if ($standard === null || $class === null) {
            return [];
        }

        $created = [];
        foreach (['transient', 'long_term'] as $duration) {
            if (StructuralVibration::rejectCombination($standard, $class, $position, $duration) !== null) {
                continue;
            }
            $created[] = $this->structuralDefinition($asset, $standard, $class, $position, $duration);
        }

        return $created;
    }

    private function structuralDefinition(
        Asset $asset,
        string $standard,
        string $class,
        string $position,
        string $duration,
    ): AlarmDefinition {
        $transient = $duration === 'transient';

        return AlarmDefinition::updateOrCreate(
            ['key' => "structural:asset:{$asset->id}:{$duration}"],
            [
                'name' => sprintf(
                    'Structural vibration (%s) - %s',
                    $transient ? 'transient' : 'continuous', $asset->name,
                ),
                'description' => sprintf(
                    '%s, %s structure, measured at %s, %s vibration. Limits are resolved per '
                    .'sample from the measured dominant frequency. Standard tables are %s.',
                    strtoupper(str_replace('_', ' ', $standard)), $class,
                    str_replace('_', ' ', $position),
                    $transient ? 'short-term / transient' : 'long-term / continuous',
                    StructuralVibration::STATUS,
                ),
                'asset_id' => $asset->id,
                'quantity' => 'vibration_velocity',
                'condition_type' => 'structural_ppv',
                'unit' => 'mm/s',
                'advisory_at' => null,
                'warning_at' => null,
                'critical_at' => null,
                'hysteresis' => 0.0,
                // A transient event is over in seconds, so it must raise at once
                // and latch. A continuous condition should be sustained before it
                // is announced, and may clear on its own once it stops.
                'persistence_seconds' => $transient ? 0 : 300,
                'clear_seconds' => $transient ? 30 : 600,
                'debounce_seconds' => $transient ? 5 : 60,
                'latching' => $transient,
                'enabled' => true,
                'requires_verified_profile' => true,
                'source' => 'structural_standard',
                'parameters' => [
                    'standard' => $standard,
                    'structure_class' => $class,
                    'position' => $position,
                    'duration' => $duration,
                    'level_fractions' => ['advisory' => 0.5, 'warning' => 0.75, 'critical' => 1.0],
                    'standard_tables_status' => StructuralVibration::STATUS,
                ],
            ],
        );
    }
}
