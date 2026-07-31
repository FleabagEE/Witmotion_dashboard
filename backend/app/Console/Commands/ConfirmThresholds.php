<?php

namespace App\Console\Commands;

use App\Models\AlarmDefinition;
use Illuminate\Console\Command;

class ConfirmThresholds extends Command
{
    protected $signature = 'alarms:confirm-thresholds
        {key? : Alarm definition key, or a LIKE pattern with %}
        {--by= : Name of the person confirming}
        {--reference= : Source checked against, e.g. "DIN 4150-3:2016-12 Table 1"}
        {--note= : Optional free text}
        {--list : Show unconfirmed definitions and exit}';

    protected $description = 'Record that a named person checked alarm thresholds against a real source';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listUnconfirmed();
        }

        $by = $this->option('by');
        $reference = $this->option('reference');
        if (! $by || ! $reference) {
            $this->error('--by and --reference are both required: an unattributed confirmation is worthless.');

            return self::FAILURE;
        }

        $pattern = $this->argument("key");
        if (! $pattern) {
            $this->error("provide a definition key, or use --list");

            return self::FAILURE;
        }
        $definitions = AlarmDefinition::where("key", "like", $pattern)->get();
        if ($definitions->isEmpty()) {
            $this->error('no alarm definition matches '.$pattern);

            return self::FAILURE;
        }

        foreach ($definitions as $definition) {
            $definition->confirm($by, $reference, $this->option('note'));
            $this->info("confirmed: {$definition->key}");
            $this->line("  advisory {$definition->advisory_at} / warning {$definition->warning_at} / critical {$definition->critical_at} {$definition->unit}");
        }

        $this->newLine();
        $this->comment("Recorded against: {$reference}");
        $this->comment("Confirmed by:     {$by}");
        $this->comment('Alarms from these definitions will now notify. Events already raised keep');
        $this->comment('their provisional flag: what mattered is what was known when they fired.');

        return self::SUCCESS;
    }

    private function listUnconfirmed(): int
    {
        $rows = AlarmDefinition::whereNull('thresholds_confirmed_at')
            ->get()
            ->map(fn ($d) => [
                $d->key,
                $d->source,
                $d->parameters['standard_tables_status'] ?? '-',
                $d->name,
            ]);

        if ($rows->isEmpty()) {
            $this->info('every alarm definition has confirmed thresholds');

            return self::SUCCESS;
        }

        $this->warn('Thresholds not yet confirmed by a named person.');
        $this->warn('Alarms from these definitions are shown but will NOT notify.');
        $this->newLine();
        $this->table(['key', 'source', 'tables', 'name'], $rows);

        return self::SUCCESS;
    }
}
