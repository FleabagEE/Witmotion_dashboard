<?php

namespace App\Console\Commands;

use App\Models\NotificationChannel;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Create or update a route from an alarm to a person.
 *
 * The appliance shipped with none. Every other part of the chain existed and was
 * tested - detection, thresholds, latching, escalation, an alarm centre - and
 * the last hop, the one that reaches somebody who is not looking at the
 * dashboard, had zero rows and had never delivered anything.
 *
 * On a remote silo that is the difference between a monitor and an ornament.
 */
class NotifyChannel extends Command
{
    protected $signature = 'alarms:channel
        {name : identifier for this route}
        {transport : log, email or webhook}
        {--to= : email address, or webhook URL}
        {--min-level=warning : advisory, warning or critical}
        {--max-per-hour=6}
        {--dedupe-seconds=900}
        {--disable}';

    protected $description = 'Route alarms to a log, an address or a webhook';

    public function handle(AuditLogger $audit): int
    {
        $transport = $this->argument('transport');

        if (! in_array($transport, ['log', 'email', 'webhook'], true)) {
            $this->error("Unknown transport '{$transport}'. Use log, email or webhook.");

            return self::FAILURE;
        }

        $to = $this->option('to');

        if ($transport !== 'log' && ! $to) {
            $this->error("--to is required for {$transport}.");

            return self::FAILURE;
        }

        // Keyed on `key`, which is the stable identifier the dispatcher and the
        // audit trail use; `name` is the human label and may be edited.
        $channel = NotificationChannel::updateOrCreate(
            ['key' => \Illuminate\Support\Str::slug($this->argument('name'))],
            [
                'name' => $this->argument('name'),
                'transport' => $transport,
                'config' => $to ? ['to' => $to] : [],
                'enabled' => ! $this->option('disable'),
                'min_level' => $this->option('min-level'),
                'max_per_hour' => (int) $this->option('max-per-hour'),
                'dedupe_window_seconds' => (int) $this->option('dedupe-seconds'),
            ],
        );

        $audit->record(
            action: 'notification_channel.configured',
            subjectType: 'notification_channel',
            subjectId: (string) $channel->id,
            summary: sprintf('%s via %s at or above %s',
                $channel->name, $channel->transport, $channel->min_level),
            actorTypeOverride: 'console',
            actorNameOverride: 'artisan alarms:channel',
        );

        $this->info(sprintf('%s: %s, %s, at or above %s',
            $channel->name, $channel->transport,
            $channel->enabled ? 'enabled' : 'disabled', $channel->min_level));

        // Saying this every time, because a channel that exists and never fires
        // looks identical to one that works until the day it matters.
        $provisional = \App\Models\AlarmDefinition::where('enabled', true)
            ->whereNull('thresholds_confirmed_by')->count();

        if ($provisional > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d enabled definition(s) have thresholds nobody has confirmed.',
                $provisional,
            ));
            $this->line('  Alarms from them are suppressed before this channel is reached.');
            $this->line('  Confirm them with alarms:confirm-thresholds, or this route stays silent.');
        }

        return self::SUCCESS;
    }
}
