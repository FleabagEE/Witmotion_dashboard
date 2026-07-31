<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensorModel extends Model
{
    protected $fillable = [
        'model', 'manufacturer', 'protocol', 'profile_version',
        'verification_status', 'capabilities', 'limitations',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'limitations' => 'array',
    ];

    /**
     * Only a verified register map may drive alarms (ADR-005). The backend
     * re-checks rather than trusting whatever the appliance asserts.
     */
    public function isTrustworthy(): bool
    {
        return $this->verification_status === 'verified';
    }
}
