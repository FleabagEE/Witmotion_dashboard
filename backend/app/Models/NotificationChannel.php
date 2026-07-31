<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    protected $fillable = [
        'key', 'name', 'transport', 'config', 'enabled', 'escalation_only', 'min_level',
        'quiet_from', 'quiet_to', 'max_per_hour', 'dedupe_window_seconds',
        'escalate_after_minutes', 'escalates_to',
    ];

    protected $casts = ['config' => 'array', 'enabled' => 'boolean', 'escalation_only' => 'boolean'];

    /** Whether this channel carries alarms at the given severity. */
    public function carries(string $level): bool
    {
        return AlarmEvent::rank($level) >= AlarmEvent::rank($this->min_level);
    }

    /**
     * Quiet hours suppress everything except critical.
     *
     * A critical alarm at 3am is precisely what someone signed up for; anything
     * less is how a channel gets muted permanently.
     */
    public function isQuietFor(string $level, \DateTimeInterface $at): bool
    {
        if ($level === 'critical' || ! $this->quiet_from || ! $this->quiet_to) {
            return false;
        }

        $now = $at->format('H:i:s');
        $from = $this->quiet_from;
        $to = $this->quiet_to;

        // A window that wraps midnight is the normal case for overnight quiet.
        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }
}
