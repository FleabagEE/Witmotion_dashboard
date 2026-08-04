<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Frequent on purpose: the gap between a sensor dying and anyone noticing is the
// whole point of a liveness alarm.
// A heartbeat, so the appliance can tell whether its own scheduler is alive.
//
// Every alarm on this system depends on tilt:check running every five minutes.
// This appliance ran for days with no scheduler installed at all: nothing
// evaluated settlement, nothing could have alarmed, and every page looked
// healthy. A monitor that cannot notice it has stopped monitoring is the
// failure this project exists to avoid.
Schedule::call(fn () => Cache::put('scheduler.last_tick', now()->toIso8601String(), 3600))
    ->everyMinute()
    ->name('scheduler-heartbeat');

Schedule::command('alarms:sweep')->everyMinute()->withoutOverlapping();

// An alarm nobody acknowledged is exactly what escalation exists for: the first
// message may have gone to a phone lying face-down on a desk.
Schedule::command('alarms:escalate')->everyFiveMinutes()->withoutOverlapping();

// Retained health and status, so an integration connecting at any moment learns
// the current state immediately instead of waiting for the next event.
Schedule::command('mqtt:health')->everyMinute()->withoutOverlapping();

// Replay protection expires within hours - the spool holds 10.1 hours of
// envelopes and a forwarder cannot re-offer what it no longer has - but nothing
// pruned the table. It reached 3.9 million rows and 1857 MB in three days,
// growing 1.3 million a day, for a guard whose usefulness is measured in hours.
// Daily, off-peak, and skipped if the previous run is still going.
// Settlement moves over weeks, so a five-minute cadence is ample - and the
// deviation query averages an hour of samples, which has no business on the
// ingest hot path several times a second.
Schedule::command('tilt:check')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('ingest:prune-idempotency --days=7')
    ->dailyAt('03:20')
    ->withoutOverlapping();
