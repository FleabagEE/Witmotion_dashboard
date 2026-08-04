<?php

namespace App\Console\Commands;

use App\Models\AlarmDefinition;
use App\Models\Sensor;
use App\Services\AlarmEvaluator;
use App\Services\TiltMonitor;
use Illuminate\Console\Command;

/**
 * Evaluates settlement: has the structure moved, beyond what temperature explains?
 *
 * Deliberately a scheduled command rather than part of the ingest path. Tilt is a
 * slow quantity - a silo settles over weeks - so evaluating it every few minutes
 * is ample, and the deviation query averages over an hour of samples. Running
 * that on every ingest batch would put a heavy aggregate on the hot path several
 * times a second to answer a question whose answer changes daily.
 *
 * What it feeds the alarm engine is the CORRECTED deviation: movement from the
 * commissioning baseline with the temperature component removed. Alarming on raw
 * tilt would fire every afternoon on a silo that had not moved at all.
 */
class TiltCheck extends Command
{
    protected $signature = 'tilt:check {--sensor=} {--dry-run}';

    protected $description = 'Alarm on movement from the tilt baseline, temperature excluded';

    /** The synthetic channel the deviation is evaluated as. */
    public const CHANNEL = 'tilt_deviation';

    public function handle(TiltMonitor $monitor, AlarmEvaluator $alarms): int
    {
        $sensors = Sensor::query()
            ->when($this->option('sensor'), fn ($q, $id) => $q->where('sensor_id', $id))
            ->where('status', 'active')
            ->get();

        $checked = 0;
        $raised = 0;

        foreach ($sensors as $sensor) {
            $baseline = ($sensor->metadata ?? [])['tilt_baseline'] ?? null;

            if (! $baseline) {
                // Not a fault. A sensor that has not been commissioned has
                // nothing to be measured against, and inventing a baseline from
                // whatever it reads today would silently define its current
                // lean as correct.
                $this->line("{$sensor->sensor_id}: no baseline - not commissioned for tilt");
                continue;
            }

            $deviation = $monitor->deviation($sensor->sensor_id, $baseline);

            if (! ($deviation['available'] ?? false)) {
                $this->warn("{$sensor->sensor_id}: {$deviation['reason']}");
                continue;
            }

            $checked++;
            $movement = $deviation['corrected_deviation'];

            $this->line(sprintf(
                '%s: raw %+.4f deg, temp explains %+.4f, movement %+.4f deg%s',
                $sensor->sensor_id,
                $deviation['raw_deviation'],
                $deviation['thermal_component'],
                $movement,
                $deviation['compensated'] ? '' : '  (UNCOMPENSATED)',
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            // Magnitude, not signed. A silo leaning north is no better than one
            // leaning south, and the standard question is how far it has moved.
            $changed = $alarms->evaluate(
                sensor: $sensor,
                channelKey: self::CHANNEL,
                value: abs($movement),
                unit: 'deg',
                measuredAt: now(),
            );

            $raised += count($changed);
            foreach ($changed as $event) {
                $this->warn(sprintf('  alarm %s -> %s', $event->id ?? '?', $event->level ?? '?'));
            }
        }

        $this->newLine();
        $this->line(sprintf('%d sensor(s) checked, %d alarm transition(s)', $checked, $raised));

        return self::SUCCESS;
    }

    /**
     * Creates the definition a tilt deviation is judged against.
     *
     * Thresholds are unconfirmed by construction: unlike DIN 4150-3 there is no
     * published table that says how far a silo may lean before it is a problem.
     * That depends on the structure, its foundation and its contents, and it is a
     * geotechnical engineer's judgement. So the definition ships provisional -
     * displayed, never notifying - until somebody names themselves against it.
     */
    public static function provision(
        Sensor $sensor,
        float $warningDeg,
        float $criticalDeg,
    ): AlarmDefinition {
        return AlarmDefinition::updateOrCreate(
            ['key' => "tilt-deviation-{$sensor->sensor_id}"],
            [
                'name' => "Tilt movement from baseline - {$sensor->sensor_id}",
                'sensor_id' => $sensor->id,
                'asset_id' => $sensor->asset_id,
                'channel_key' => self::CHANNEL,
                'quantity' => 'inclination',
                'condition_type' => 'high_threshold',
                'unit' => 'deg',
                'warning_at' => $warningDeg,
                'critical_at' => $criticalDeg,
                // Wide, because tilt is noisy at the tenth of a degree and an
                // alarm that chatters across a threshold gets ignored.
                'hysteresis' => $warningDeg * 0.2,
                // An hour to raise and six to clear. Settlement does not need a
                // fast alarm, and a spurious raise from a passing vehicle or
                // somebody leaning on the silo would cost more than the delay.
                'persistence_seconds' => 3600,
                'clear_seconds' => 21600,
                'debounce_seconds' => 600,
                'latching' => true,
                'enabled' => true,
                'source' => 'operator',
            ],
        );
    }
}
