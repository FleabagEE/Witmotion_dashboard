<?php

namespace App\Services;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\AlarmTransition;
use App\Models\Asset;
use App\Models\Sensor;
use App\Support\Iso10816;
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
    ): array {
        // A failed read is not a low reading. Alarming on it would report a
        // machine as healthy when in fact nothing was measured at all; sensor
        // liveness is a separate condition type.
        if ($value === null || $quality === 'bad') {
            return [];
        }

        $changed = [];
        foreach ($this->definitionsFor($sensor, $channelKey) as $definition) {
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
                ->where('condition_type', 'high_threshold')
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
     * Create ISO 10816 velocity alarms for an asset's machine class.
     *
     * Defaults, not a substitute for a baseline measured on the machine itself.
     * The source is recorded so an operator can tell derived limits from ones
     * somebody chose deliberately.
     */
    public function provisionIsoDefaults(Asset $asset): ?AlarmDefinition
    {
        $class = $asset->iso_10816_class ?: Iso10816::classFromPower($asset->rated_power_kw);
        $thresholds = Iso10816::thresholds($class);
        if ($thresholds === null) {
            return null;
        }

        $derived = $asset->iso_10816_class === null;

        return AlarmDefinition::updateOrCreate(
            ['key' => "iso10816:asset:{$asset->id}"],
            [
                'name' => "ISO 10816 vibration velocity - {$asset->name}",
                'description' => $thresholds['label'].($derived
                    ? ' (class inferred from rated power; confirm the mounting)'
                    : ''),
                'asset_id' => $asset->id,
                'quantity' => 'vibration_velocity',
                'condition_type' => 'high_threshold',
                'unit' => Iso10816::UNIT,
                'advisory_at' => $thresholds['advisory'],
                'warning_at' => $thresholds['warning'],
                'critical_at' => $thresholds['critical'],
                'hysteresis' => round($thresholds['warning'] * Iso10816::HYSTERESIS_FRACTION, 4),
                'persistence_seconds' => 10,
                'clear_seconds' => 60,
                'debounce_seconds' => 5,
                'latching' => false,
                'enabled' => true,
                'requires_verified_profile' => true,
                'source' => $derived ? 'iso10816_inferred' : 'iso10816',
                'parameters' => ['machine_class' => $class, 'derived_from_power' => $derived],
            ],
        );
    }
}
