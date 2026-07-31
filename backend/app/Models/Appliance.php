<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An appliance authenticates as itself: its Sanctum token is issued to the
 * appliance record, not to a human user. Sanctum's guard hands the tokenable to
 * the auth system, so the model must be Authenticatable - without it every
 * token-authenticated request fails, not just tests.
 */
class Appliance extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    protected $fillable = [
        'appliance_id', 'site_id', 'name', 'software_version',
        'current_run_id', 'last_seen_at', 'last_ingest_at', 'status', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
        'last_ingest_at' => 'datetime',
    ];

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class);
    }
}
