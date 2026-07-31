<?php

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

class EscalateAlarms extends Command
{
    protected $signature = 'alarms:escalate';

    protected $description = 'Escalate alarms that were notified but never acknowledged';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $count = $dispatcher->escalateStale();
        $this->info("escalated {$count} alarm(s)");

        return self::SUCCESS;
    }
}
