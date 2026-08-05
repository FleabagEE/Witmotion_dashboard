<?php

namespace App\Console\Commands;

use App\Models\AlarmDefinition;
use App\Models\Sensor;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Every alarm the silo installation needs, on every sensor.
 *
 * Four quantities per unit: tilt movement from baseline, and the three
 * vibration amplitudes the device computes on-board — acceleration, velocity
 * and displacement. Twelve definitions across three sensors.
 *
 * SCOPED BY QUANTITY, NOT BY CHANNEL
 * ----------------------------------
 *
 * Each vibration definition covers X, Y and Z through the quantity filter, so
 * there is one card per quantity per sensor rather than three saying the same
 * thing. Three cards would be three places to forget to change.
 *
 * TIMERS DIFFER BECAUSE THE PHENOMENA DIFFER
 * ------------------------------------------
 *
 * Settlement is a state: a silo leans for weeks, so tilt waits an hour before
 * raising and six before clearing, and a passing lorry never reaches it.
 * Vibration is an event: a hammer blow is over in milliseconds, so it raises
 * immediately and clears in a minute. Using one set of timers for both would
 * either miss every impact or make a settling structure chatter.
 *
 * THE NUMBERS ARE PLACEHOLDERS AND SAY SO
 * ---------------------------------------
 *
 * Every definition ships unconfirmed, so alarms from them are displayed and
 * never sent until somebody puts their name to the values. That is not caution
 * for its own sake: the first vibration thresholds this appliance carried were
 * breached by somebody picking a sensor up off a bench.
 *
 * `alarms:vibration-survey` reports what the structure actually experiences
 * after a week on site. Numbers chosen from that are evidence; numbers chosen
 * now are guesses wearing a uniform.
 */
class ProvisionSiloAlarms extends Command
{
    protected $signature = 'alarms:provision-silo
        {--sensor= : one sensor_id, default every active sensor}
        {--dry-run}';

    protected $description = 'Provision tilt and vibration alarms for every silo sensor';

    /**
     * The four quantities, with starting values and the timers each deserves.
     *
     * `tilt` is handled separately through TiltCheck::provision, which knows
     * about the synthetic deviation channel.
     */
    private const VIBRATION = [
        [
            'suffix' => 'vibration-acceleration',
            'label' => 'Vibration acceleration',
            'quantity' => 'acceleration_amplitude',
            'unit' => 'g',
            // Above what handling a unit produces - measured at 0.5 g on the
            // bench - so ordinary site activity does not fill the log.
            'warning' => 0.5,
            'critical' => 2.0,
        ],
        [
            'suffix' => 'vibration-velocity',
            'label' => 'Vibration velocity',
            'quantity' => 'vibration_velocity',
            'unit' => 'mm/s',
            // The magnitudes DIN 4150-3 works in, without its frequency
            // dependence. Deliberately not presented as the standard: that
            // needs a limit resolved per sample from the dominant frequency,
            // and this is a plain threshold.
            'warning' => 3.0,
            'critical' => 10.0,
        ],
        [
            'suffix' => 'vibration-displacement',
            'label' => 'Vibration displacement',
            'quantity' => 'vibration_displacement',
            'unit' => 'um',
            // The register's range mode is not readable, so the scale behind
            // this number is itself uncertain. See docs/known-limitations.md.
            'warning' => 100.0,
            'critical' => 500.0,
        ],
    ];

    public function handle(AuditLogger $audit): int
    {
        $sensors = Sensor::query()
            ->when($this->option('sensor'), fn ($q, $id) => $q->where('sensor_id', $id))
            ->where('status', 'active')
            ->orderBy('sensor_id')
            ->get();

        if ($sensors->isEmpty()) {
            $this->error('No active sensors.');

            return self::FAILURE;
        }

        $planned = [];

        foreach ($sensors as $sensor) {
            $position = ($sensor->metadata['mounting'] ?? [])['position'] ?? 'unplaced';

            $planned[] = [
                'sensor' => $sensor,
                'position' => $position,
                'name' => "Tilt movement from baseline - {$sensor->sensor_id}",
                'kind' => 'tilt',
            ];

            foreach (self::VIBRATION as $spec) {
                $planned[] = [
                    'sensor' => $sensor,
                    'position' => $position,
                    'name' => "{$spec['label']} - {$sensor->sensor_id}",
                    'kind' => 'vibration',
                    'spec' => $spec,
                ];
            }
        }

        foreach ($planned as $row) {
            $spec = $row['spec'] ?? null;
            $this->line(sprintf('  %-8s %-40s %s',
                $row['position'], $row['name'],
                $spec ? "{$spec['warning']} / {$spec['critical']} {$spec['unit']}" : '0.5 / 3 deg'));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment(sprintf('Dry run - %d definition(s) would be written.', count($planned)));

            return self::SUCCESS;
        }

        $written = 0;

        foreach ($planned as $row) {
            /** @var Sensor $sensor */
            $sensor = $row['sensor'];

            if ($row['kind'] === 'tilt') {
                // Reuses the command that owns the synthetic deviation channel,
                // so there is one definition of what a tilt alarm is.
                TiltCheck::provision($sensor, warningDeg: 0.5, criticalDeg: 3.0);
                $written++;

                continue;
            }

            $spec = $row['spec'];

            AlarmDefinition::updateOrCreate(
                ['key' => "{$spec['suffix']}-{$sensor->sensor_id}"],
                [
                    'name' => $row['name'],
                    'sensor_id' => $sensor->id,
                    'asset_id' => $sensor->asset_id,
                    // Matched on quantity so one definition covers all three axes.
                    'channel_key' => null,
                    'quantity' => $spec['quantity'],
                    'condition_type' => 'high_threshold',
                    'unit' => $spec['unit'],
                    'warning_at' => $spec['warning'],
                    'critical_at' => $spec['critical'],
                    'hysteresis' => round($spec['warning'] * 0.2, 4),
                    // An event, not a state: raise at once, clear in a minute.
                    'persistence_seconds' => 0,
                    'clear_seconds' => 60,
                    'debounce_seconds' => 60,
                    'latching' => true,
                    'enabled' => true,
                    'source' => 'operator',
                ],
            );

            $written++;
        }

        $audit->record(
            action: 'alarm_definition.provisioned',
            summary: sprintf('%d silo alarm definition(s) provisioned across %d sensor(s), all unconfirmed',
                $written, $sensors->count()),
            actorTypeOverride: 'console',
            actorNameOverride: 'artisan alarms:provision-silo',
        );

        $this->newLine();
        $this->info(sprintf('%d definition(s) across %d sensor(s).', $written, $sensors->count()));
        $this->newLine();
        $this->warn('All of them are unconfirmed, so none will notify anybody.');
        $this->line('  That is deliberate. The first vibration thresholds this appliance');
        $this->line('  carried were breached by somebody picking a sensor up off a bench.');
        $this->newLine();
        $this->line('  After a week on site: php artisan alarms:vibration-survey');
        $this->line('  Then set the numbers from what the structure actually sees, and');
        $this->line('  confirm them in the name of whoever is accountable for it.');

        return self::SUCCESS;
    }
}
