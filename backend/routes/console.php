<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Frequent on purpose: the gap between a sensor dying and anyone noticing is the
// whole point of a liveness alarm.
Schedule::command('alarms:sweep')->everyMinute()->withoutOverlapping();

// An alarm nobody acknowledged is exactly what escalation exists for: the first
// message may have gone to a phone lying face-down on a desk.
Schedule::command('alarms:escalate')->everyFiveMinutes()->withoutOverlapping();
