<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reading, seconds fresher than the database copy.
 *
 * Broadcast straight from the acquisition feed. This is a view, not a record:
 * the authoritative copy still arrives through the spool and the ingestion API,
 * and anything acted upon should come from there.
 */
class LiveMeasurement implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly array $frame)
    {
    }

    public function broadcastOn(): Channel
    {
        // Per-sensor channel, so a dashboard watching one sensor is not woken by
        // every other sensor on the appliance.
        return new Channel('sensor.'.($this->frame['sensor_id'] ?? 'unknown'));
    }

    public function broadcastAs(): string
    {
        return 'measurement';
    }

    public function broadcastWith(): array
    {
        return $this->frame;
    }
}
