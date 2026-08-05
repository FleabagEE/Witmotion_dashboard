<?php

namespace App\Console\Commands;

use App\Models\AlarmDefinition;
use App\Models\Sensor;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Alarm definitions for vibration acceleration and velocity.
 *
 * Scoped by quantity rather than by channel, so one definition covers X, Y and Z
 * instead of three cards saying the same thing. That works because a definition
 * with no channel_key is matched on quantity - the broadening filter in
 * AlarmEvaluator::definitionsFor.
 *
 * WHY THESE ARE NOT THE OLD DIN 4150-3 DEFINITION
 * -----------------------------------------------
 *
 * The retired structural_ppv definition resolved its limit per sample from the
 * companion dominant frequency, which is what the standard requires and what
 * makes it defensible. These are plain high thresholds: a single number per
 * level, no frequency dependence, no standard behind them.
 *
 * That is a weaker claim and the numbers here are placeholders. They exist so
 * the values are visible and editable on the Thresholds page, not because
 * anybody has judged them. Both ship unconfirmed, so alarms from them are
 * displayed and never sent until somebody puts their name to the numbers.
 *
 * WHAT 1 Hz COSTS
 * ---------------
 *
 * The appliance polls at 1 Hz for settlement. Vibration is transient: a hammer
 * blow lasts tens of milliseconds and can fall entirely between two samples.
 * These channels are computed on-device over its own full-rate window, so a
 * short event still raises the reported amplitude - but the peak is the
 * device's, not the appliance's, and one sample per second is a coarse net.
 *
 * For real vibration monitoring the poll rate belongs back at 10 Hz, and the
 * bus can carry it - it is the settlement configuration that made it 1 Hz.
 */
class ProvisionVibrationAlarms extends Command
{
    protected $signature = 'alarms:provision-vibration
        {--sensor= : sensor_id, default every active sensor}
        {--accel-warning=0.10} {--accel-critical=0.50}
        {--velocity-warning=3.0} {--velocity-critical=10.0}';

    protected $description = 'Create vibration acceleration and velocity threshold definitions';

    public function handle(AuditLogger $audit): int
    {
        $sensors = Sensor::query()
            ->when($this->option('sensor'), fn ($q, $id) => $q->where('sensor_id', $id))
            ->where('status', 'active')
            ->get();

        if ($sensors->isEmpty()) {
            $this->error('No active sensors.');

            return self::FAILURE;
        }

        foreach ($sensors as $sensor) {
            foreach ([
                [
                    'suffix' => 'vibration-acceleration',
                    'name' => "Vibration acceleration - {$sensor->sensor_id}",
                    'quantity' => 'acceleration_amplitude',
                    'unit' => 'g',
                    'warning' => (float) $this->option('accel-warning'),
                    'critical' => (float) $this->option('accel-critical'),
                ],
                [
                    'suffix' => 'vibration-velocity',
                    'name' => "Vibration velocity - {$sensor->sensor_id}",
                    'quantity' => 'vibration_velocity',
                    'unit' => 'mm/s',
                    'warning' => (float) $this->option('velocity-warning'),
                    'critical' => (float) $this->option('velocity-critical'),
                ],
            ] as $spec) {
                $definition = AlarmDefinition::updateOrCreate(
                    ['key' => "{$spec['suffix']}-{$sensor->sensor_id}"],
                    [
                        'name' => $spec['name'],
                        'sensor_id' => $sensor->id,
                        'asset_id' => $sensor->asset_id,
                        // No channel_key: matched on quantity, so one definition
                        // covers all three axes.
                        'channel_key' => null,
                        'quantity' => $spec['quantity'],
                        'condition_type' => 'high_threshold',
                        'unit' => $spec['unit'],
                        'warning_at' => $spec['warning'],
                        'critical_at' => $spec['critical'],
                        'hysteresis' => round($spec['warning'] * 0.2, 4),
                        // Short, because vibration is an event rather than a
                        // state. Settlement waits an hour to be sure; a hammer
                        // blow is over before that.
                        'persistence_seconds' => 0,
                        'clear_seconds' => 60,
                        'debounce_seconds' => 60,
                        'latching' => true,
                        'enabled' => true,
                        'source' => 'operator',
                    ],
                );

                $audit->record(
                    action: 'alarm_definition.provisioned',
                    subjectType: 'alarm_definition',
                    subjectId: (string) $definition->id,
                    summary: sprintf('%s created at %s/%s %s, unconfirmed',
                        $spec['name'], $spec['warning'], $spec['critical'], $spec['unit']),
                    actorTypeOverride: 'console',
                    actorNameOverride: 'artisan alarms:provision-vibration',
                );

                $this->line(sprintf('  %-38s warning %s / critical %s %s',
                    $spec['name'], $spec['warning'], $spec['critical'], $spec['unit']));
            }
        }

        $this->newLine();
        $this->warn('These thresholds are placeholders that nobody has judged.');
        $this->line('  They are displayed and will never be sent until somebody confirms them');
        $this->line('  on the Thresholds page. That is the point: a number this appliance');
        $this->line('  invented has not earned the right to wake anybody up.');
        $this->newLine();
        $this->line('  Note: the appliance polls at 1 Hz for settlement. Vibration is');
        $this->line('  transient, and one sample per second is a coarse net for it.');

        return self::SUCCESS;
    }
}
