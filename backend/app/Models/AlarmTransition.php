<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlarmTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'alarm_event_id', 'from_level', 'to_level', 'reason',
        'value', 'threshold', 'actor_id', 'occurred_at',
    ];

    protected $casts = [
        'value' => 'float', 'threshold' => 'float', 'occurred_at' => 'datetime',
    ];
}
