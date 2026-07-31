<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = [
        'site_id', 'slug', 'name', 'asset_type', 'iso_10816_class',
        'rated_power_kw', 'nominal_rpm', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array', 'rated_power_kw' => 'float'];

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class);
    }
}
