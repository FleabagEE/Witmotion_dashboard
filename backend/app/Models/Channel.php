<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Channel extends Model
{
    protected $fillable = [
        'sensor_id', 'channel_key', 'group_key', 'label', 'quantity', 'unit',
        'value_class', 'register_address', 'data_type', 'scale', 'offset',
        'range_min', 'range_max', 'enabled', 'configured_hz', 'measured_hz', 'jitter_ms',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'scale' => 'float',
        'offset' => 'float',
        'range_min' => 'float',
        'range_max' => 'float',
        'configured_hz' => 'float',
        'measured_hz' => 'float',
        'jitter_ms' => 'float',
    ];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }
}
