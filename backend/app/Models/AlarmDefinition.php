<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlarmDefinition extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'asset_id', 'sensor_id', 'channel_key', 'quantity',
        'condition_type', 'unit', 'advisory_at', 'warning_at', 'critical_at',
        'hysteresis', 'persistence_seconds', 'clear_seconds', 'debounce_seconds',
        'latching', 'enabled', 'requires_verified_profile', 'source', 'parameters',
        'thresholds_confirmed_at', 'thresholds_confirmed_by', 'thresholds_reference',
        'thresholds_note',
    ];

    protected $casts = [
        'advisory_at' => 'float', 'warning_at' => 'float', 'critical_at' => 'float',
        'hysteresis' => 'float', 'latching' => 'boolean', 'enabled' => 'boolean',
        'requires_verified_profile' => 'boolean', 'parameters' => 'array',
        'thresholds_confirmed_at' => 'datetime',
    ];

    /**
     * Whether a named person has checked these numbers against a real source.
     *
     * The shipped structural values are transcribed from working knowledge, not
     * from the copyrighted standard text. Until somebody who owns the risk
     * confirms them, alarms they produce are provisional and never notify.
     */
    public function thresholdsConfirmed(): bool
    {
        return $this->thresholds_confirmed_at !== null;
    }

    public function confirm(string $by, string $reference, ?string $note = null): self
    {
        $before = [
            'advisory_at' => $this->advisory_at,
            'warning_at' => $this->warning_at,
            'critical_at' => $this->critical_at,
            'previously_confirmed_at' => $this->thresholds_confirmed_at?->toIso8601String(),
        ];

        $this->forceFill([
            'thresholds_confirmed_at' => now(),
            'thresholds_confirmed_by' => $by,
            'thresholds_reference' => $reference,
            'thresholds_note' => $note,
        ])->save();

        // The highest-consequence configuration act in the product: it is what
        // turns numbers nobody has checked into alarms that page people.
        app(\App\Services\AuditLogger::class)->record(
            action: 'alarm.thresholds_confirmed',
            subjectType: 'alarm_definition',
            subjectId: (string) $this->id,
            summary: "Thresholds for {$this->key} confirmed by {$by} against {$reference}",
            before: $before,
            after: ['confirmed_by' => $by, 'reference' => $reference, 'note' => $note],
            request: request(),
            actorTypeOverride: 'user',
            actorNameOverride: $by,
        );

        return $this;
    }

    /** The sensor this definition is scoped to, if any. Null means every sensor. */
    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlarmEvent::class);
    }

    /** Raise thresholds by level, highest severity first. */
    public function thresholds(): array
    {
        return array_filter([
            'critical' => $this->critical_at,
            'warning' => $this->warning_at,
            'advisory' => $this->advisory_at,
        ], fn ($value) => $value !== null);
    }
}
