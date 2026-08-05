<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What vibration this structure actually sees, so a threshold can be chosen
 * from evidence rather than invented.
 *
 * The placeholder limits shipped with the vibration definitions were breached
 * by somebody picking the sensor up off a bench. That is the whole problem with
 * choosing a number before you know what normal looks like: it sounds
 * reasonable, and the structure disagrees.
 *
 * So the definitions record and never notify, and this reads back what they
 * recorded. After a week on site the distribution answers the question that
 * guesswork cannot: what does a lorry produce, what does a delivery produce,
 * and where is the line above all of it.
 */
class VibrationSurvey extends Command
{
    protected $signature = 'alarms:vibration-survey
        {--days=7 : window to summarise}
        {--sensor= : sensor_id, default all}';

    protected $description = 'Summarise recorded vibration, to choose thresholds from evidence';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);

        $rows = DB::table('measurements')
            ->when($this->option('sensor'), fn ($q, $id) => $q->where('sensor_id', $id))
            ->where('time', '>=', $since)
            ->whereIn('channel_key', [
                'accel_amplitude_x', 'accel_amplitude_y', 'accel_amplitude_z',
                'vib_velocity_x', 'vib_velocity_y', 'vib_velocity_z',
            ])
            ->where('quality', 'good')
            ->groupBy('channel_key')
            ->orderBy('channel_key')
            ->get([
                'channel_key',
                DB::raw('count(*) as n'),
                DB::raw('round(avg(value)::numeric, 4) as mean'),
                // Percentiles rather than a mean and a max. The mean of a quiet
                // structure is the noise floor and says nothing; the max is one
                // event, possibly somebody leaning on it. What sets a threshold
                // is the shape in between.
                DB::raw('round(percentile_cont(0.50) within group (order by value)::numeric, 4) as p50'),
                DB::raw('round(percentile_cont(0.95) within group (order by value)::numeric, 4) as p95'),
                DB::raw('round(percentile_cont(0.999) within group (order by value)::numeric, 4) as p999'),
                DB::raw('round(max(value)::numeric, 4) as peak'),
                DB::raw('max(unit) as unit'),
            ]);

        if ($rows->isEmpty()) {
            $this->error("No vibration readings in the last {$days} day(s).");

            return self::FAILURE;
        }

        $this->line(sprintf('Vibration over %d day(s), good readings only', $days));
        $this->newLine();
        $this->line(sprintf('  %-20s %9s %9s %9s %9s %9s  %s',
            'channel', 'median', 'p95', 'p99.9', 'peak', 'samples', 'unit'));

        foreach ($rows as $r) {
            $this->line(sprintf('  %-20s %9s %9s %9s %9s %9s  %s',
                $r->channel_key, $r->p50, $r->p95, $r->p999, $r->peak,
                number_format($r->n), $r->unit));
        }

        $this->newLine();
        $this->line('Choosing from this:');
        $this->line('  p99.9 is roughly "the busiest minute of a normal day". A warning');
        $this->line('  below it will fire on ordinary site activity; a critical near the');
        $this->line('  peak will only fire on something that has already happened before.');
        $this->newLine();
        $this->line('  Neither is a structural judgement. What the structure can tolerate');
        $this->line('  is a question for whoever is accountable for it - this only says');
        $this->line('  what it has been experiencing.');

        // The poll rate bounds what any of this can mean.
        $rate = DB::table('measurements')
            ->where('channel_key', 'accel_amplitude_x')
            ->where('time', '>=', now()->subMinutes(5))
            ->count() / 300;

        if ($rate < 5) {
            $this->newLine();
            $this->warn(sprintf('Sampled at about %.1f Hz.', $rate));
            $this->line('  Vibration is transient. These figures come from the device\'s own');
            $this->line('  full-rate window, so short events do register - but at this poll');
            $this->line('  rate the appliance sees one value per sample and the shape');
            $this->line('  between them is the device\'s summary, not a measurement.');
        }

        return self::SUCCESS;
    }
}
