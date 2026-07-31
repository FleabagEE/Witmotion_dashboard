<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlarmDefinition extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'asset_id', 'sensor_id', 'channel_key', 'quantity',
        'condition_type', 'unit', 'advisory_at', 'warning_at', 'critical_at',
        'hysteresis', 'persistence_seconds', 'clear_seconds', 'debounce_seconds',
        'latching', 'enabled', 'requires_verified_profile', 'source', 'parameters',
    ];

    protected $casts = [
        'advisory_at' => 'float', 'warning_at' => 'float', 'critical_at' => 'float',
        'hysteresis' => 'float', 'latching' => 'boolean', 'enabled' => 'boolean',
        'requires_verified_profile' => 'boolean', 'parameters' => 'array',
    ];

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
