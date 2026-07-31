<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlarmEvent extends Model
{
    public const LEVELS = ['normal' => 0, 'advisory' => 1, 'warning' => 2, 'critical' => 3];

    protected $fillable = [
        'alarm_definition_id', 'sensor_id', 'asset_id', 'channel_key',
        'level', 'peak_level', 'state', 'trigger_value', 'peak_value', 'threshold', 'unit',
        'raised_at', 'last_evaluated_at', 'last_changed_at', 'cleared_at',
        'candidate_level', 'candidate_since',
        'acknowledged_at', 'acknowledged_by', 'acknowledgement_note', 'shelved_until', 'metadata',
    ];

    protected $casts = [
        'trigger_value' => 'float', 'peak_value' => 'float', 'threshold' => 'float',
        'raised_at' => 'datetime', 'last_evaluated_at' => 'datetime',
        'last_changed_at' => 'datetime', 'cleared_at' => 'datetime',
        'candidate_since' => 'datetime', 'acknowledged_at' => 'datetime',
        'shelved_until' => 'datetime', 'metadata' => 'array',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AlarmDefinition::class, 'alarm_definition_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(AlarmTransition::class);
    }

    public static function rank(string $level): int
    {
        return self::LEVELS[$level] ?? 0;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function isShelved(): bool
    {
        return $this->shelved_until !== null && $this->shelved_until->isFuture();
    }
}
