<?php

namespace App\Console\Commands;

use App\Models\AlarmDefinition;
use App\Services\AlarmEvaluator;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Retire an alarm definition and close what it left open.
 *
 * Setting `enabled = false` by hand is not enough and fails in a quiet way. The
 * definition stops being evaluated, so its open events are never looked at
 * again - they stay "active" on the dashboard permanently, with nothing in the
 * system able to close them. This does both halves.
 *
 * Retiring is deliberately not the same as acknowledging. Acknowledging records
 * that a named person read the alarm and accepted it; that is a human act and
 * this command will not forge it. Retiring records that the check itself was
 * switched off. Both close the event, and the history keeps which one happened.
 */
class AlarmsDisable extends Command
{
    protected $signature = 'alarms:disable {definition : id or key} {--reason=} {--dry-run}';

    protected $description = 'Disable an alarm definition and retire its open events';

    public function handle(AlarmEvaluator $evaluator, AuditLogger $audit): int
    {
        $key = $this->argument('definition');

        $definition = AlarmDefinition::query()
            ->when(is_numeric($key), fn ($q) => $q->where('id', (int) $key), fn ($q) => $q->where('key', $key))
            ->first();

        if (! $definition) {
            $this->error("No alarm definition matching '{$key}'.");

            return self::FAILURE;
        }

        $open = $definition->events()->where('state', 'active')->get();

        $this->line("Definition #{$definition->id}: {$definition->name}");
        $this->line("  condition  {$definition->condition_type}");
        $this->line('  enabled    '.($definition->enabled ? 'yes' : 'no'));
        $this->line('  open events '.$open->count());

        foreach ($open as $event) {
            $this->line(sprintf(
                '    #%d  %s  %s  peak %s %s',
                $event->id,
                $event->channel_key,
                $event->level,
                $event->peak_value,
                $event->unit,
            ));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run - nothing changed.');

            return self::SUCCESS;
        }

        $definition->enabled = false;
        $definition->save();

        $retired = $evaluator->retireOrphanedEvents();

        $audit->record(
            action: 'alarm_definition.disabled',
            subjectType: 'alarm_definition',
            subjectId: (string) $definition->id,
            summary: sprintf(
                '%s disabled, %d open event(s) retired',
                $definition->name,
                count($retired),
            ),
            after: ['reason' => $this->option('reason')],
            // Named, because an audit trail that attributes an operator action
            // to nobody is worse than no trail at all.
            actorTypeOverride: 'console',
            actorNameOverride: 'artisan alarms:disable',
        );

        $this->newLine();
        $this->info(sprintf('Disabled. %d event(s) retired.', count($retired)));

        if ($this->option('reason')) {
            $this->line('  reason: '.$this->option('reason'));
        } else {
            // Not fatal, but a disabled check with no recorded reason is the
            // kind of thing nobody can explain six months later.
            $this->warn('  No --reason given. The audit entry will not say why.');
        }

        return self::SUCCESS;
    }
}
