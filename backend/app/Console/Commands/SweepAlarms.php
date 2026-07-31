<?php

namespace App\Console\Commands;

use App\Models\Sensor;
use App\Services\AlarmEvaluator;
use Illuminate\Console\Command;

class SweepAlarms extends Command
{
    protected $signature = 'alarms:sweep';

    protected $description = 'Evaluate liveness alarms for sensors that have gone quiet';

    public function handle(AlarmEvaluator $evaluator): int
    {
        // Threshold alarms are driven by arriving data. Liveness cannot be:
        // a sensor that stops answering produces nothing to trigger on, so
        // silence has to be looked for deliberately.
        $checked = 0;
        $changed = 0;

        Sensor::with('channels', 'model')
            ->where('status', 'active')
            ->chunkById(200, function ($sensors) use ($evaluator, &$checked, &$changed): void {
                foreach ($sensors as $sensor) {
                    $checked++;
                    $changed += count($evaluator->evaluateLiveness($sensor));
                }
            });

        $this->info("liveness sweep: {$checked} sensor(s) checked, {$changed} alarm change(s)");

        return self::SUCCESS;
    }
}
