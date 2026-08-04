<?php

namespace App\Console\Commands;

use App\Models\AlarmDefinition;
use App\Models\AlarmEvent;
use App\Models\Sensor;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prove the whole alarm chain, end to end, without waiting for a structure to move.
 *
 * `alarms:test-notification` proves the mail transport: it calls Mail directly
 * and says whether SMTP works. That is a smaller claim than it sounds, because
 * between a raised alarm and a message there are six gates, any one of which
 * returns silently:
 *
 *     provisional thresholds, shelved, below the channel's minimum level,
 *     quiet hours, duplicate within the dedupe window, rate limited
 *
 * An appliance can have working SMTP, a correct channel, a real alarm, and still
 * notify nobody. This drives a genuine alarm event through the real dispatcher
 * and prints what every gate decided.
 *
 * The event is created inside a transaction that is always rolled back, so the
 * message is really sent while the structural record gains no fictional alarm.
 * A self-test that leaves a 4.7 degree movement in the history it is meant to
 * protect would be worse than no self-test.
 */
class AlarmsSelfTest extends Command
{
    protected $signature = 'alarms:selftest
        {--definition= : key or id, default a confirmed one}
        {--level=critical : advisory, warning or critical}';

    protected $description = 'Drive a real alarm through the real notification path and report every gate';

    public function handle(NotificationDispatcher $dispatcher, AuditLogger $audit): int
    {
        $definition = AlarmDefinition::query()
            ->when($this->option('definition'), function ($q, $key) {
                return is_numeric($key) ? $q->where('id', (int) $key) : $q->where('key', $key);
            })
            ->where('enabled', true)
            // A confirmed definition first. Defaulting to whichever came first
            // meant the test picked an unconfirmed one and reported that
            // nothing would be delivered - true of that definition, and
            // completely misleading about the appliance, which was by then
            // fully able to send.
            ->orderByRaw('thresholds_confirmed_by IS NULL')
            ->first();

        if (! $definition) {
            $this->error('No enabled alarm definition to test with.');

            return self::FAILURE;
        }

        $sensor = $definition->sensor ?? Sensor::where('status', 'active')->first();

        if (! $sensor) {
            $this->error('No active sensor to attribute a test alarm to.');

            return self::FAILURE;
        }

        $level = $this->option('level');
        $this->line("Definition : {$definition->name}");
        $this->line('Sensor     : '.$sensor->sensor_id);
        $this->line("Level      : {$level}");
        $this->line('Thresholds : '.($definition->thresholdsConfirmed()
            ? 'confirmed by '.$definition->thresholds_confirmed_by
            : 'NOT CONFIRMED — sends will be suppressed'));
        // Named, because "would an alarm reach anyone" is a question about the
        // appliance and this command can only answer it for one definition at a
        // time.
        $unconfirmed = AlarmDefinition::where('enabled', true)
            ->whereNull('thresholds_confirmed_by')
            ->pluck('name');

        if ($unconfirmed->isNotEmpty()) {
            $this->warn(sprintf(
                '%d other enabled definition(s) are unconfirmed and would send nothing:',
                $unconfirmed->count(),
            ));
            foreach ($unconfirmed as $name) {
                $this->line("  {$name}");
            }
        }

        $this->newLine();

        $results = [];

        // Rolled back unconditionally. The mail leaves the building; the
        // database is untouched.
        DB::beginTransaction();

        try {
            $event = AlarmEvent::create([
                'alarm_definition_id' => $definition->id,
                'sensor_id' => $sensor->id,
                'asset_id' => $sensor->asset_id,
                'channel_key' => $definition->channel_key ?? '__selftest__',
                'level' => $level,
                'peak_level' => $level,
                'state' => 'active',
                'provisional' => ! $definition->thresholdsConfirmed(),
                'trigger_value' => $definition->critical_at ?? 1.0,
                'peak_value' => $definition->critical_at ?? 1.0,
                'threshold' => $definition->critical_at,
                'unit' => $definition->unit,
                'raised_at' => now(),
            ]);

            $results = $dispatcher->dispatch($event);
        } finally {
            DB::rollBack();
        }

        if ($results === []) {
            $this->error('No notification channels are configured or enabled.');
            $this->line('  php artisan alarms:channel "duty engineer" email --to=you@example.com');

            return self::FAILURE;
        }

        $sent = 0;

        foreach ($results as $result) {
            $status = $result['status'] ?? 'unknown';
            $reason = $result['reason'] ?? '';

            $line = sprintf('  %-18s %-11s %s', $result['channel'] ?? '?', $status, $reason);

            if ($status === 'sent') {
                $sent++;
                $this->info($line);
            } else {
                $this->warn($line);
                $this->line('      '.$this->explain($reason));
            }
        }

        $audit->record(
            action: 'alarms.selftest',
            summary: sprintf('Self-test at %s: %d of %d channel(s) delivered',
                $level, $sent, count($results)),
            actorTypeOverride: 'console',
            actorNameOverride: 'artisan alarms:selftest',
        );

        $this->newLine();

        if ($sent === 0) {
            $this->error('Nothing was delivered. A real alarm today would reach nobody.');

            return self::FAILURE;
        }

        $this->info("{$sent} channel(s) delivered. A real alarm would reach somebody.");
        $this->line('  No alarm was recorded: the test ran inside a rolled-back transaction.');

        return self::SUCCESS;
    }

    /** Each gate, in the words of what to do about it. */
    private function explain(string $reason): string
    {
        return match ($reason) {
            'provisional_thresholds' => 'Nobody has confirmed these numbers. Thresholds page, '
                .'or alarms:confirm-thresholds. This is the usual reason.',
            'shelved' => 'Somebody silenced this alarm until a set time.',
            'below_min_level' => 'The channel only carries more severe alarms. '
                .'Raise it with alarms:channel --min-level.',
            'quiet_hours' => 'The channel is in its configured quiet window.',
            'duplicate' => 'An identical alarm was sent inside the dedupe window. '
                .'Not a fault; wait, or use a different level.',
            'rate_limited' => 'The channel has hit max_per_hour.',
            default => 'See NotificationDispatcher for this gate.',
        };
    }
}
