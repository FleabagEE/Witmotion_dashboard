<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sensor extends Model
{
    protected $fillable = [
        'sensor_id', 'appliance_id', 'rs485_bus_id', 'sensor_model_id', 'asset_id',
        'slave_id', 'mount_location', 'mount_method', 'status', 'installed_on',
        'last_measurement_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'installed_on' => 'date',
        'last_measurement_at' => 'datetime',
    ];

    public function appliance(): BelongsTo
    {
        return $this->belongsTo(Appliance::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(SensorModel::class, 'sensor_model_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
