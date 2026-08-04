<?php

namespace App\Console\Commands;

use App\Models\Sensor;
use App\Services\TiltMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Capture, inspect and compare a tilt baseline.
 *
 * A settlement monitor measures change, so it needs a starting point. The
 * baseline is the structure's orientation at commissioning, together with the
 * temperature it was captured at and the thermal model in force - because a
 * deviation means nothing without knowing what temperature it was measured
 * against.
 */
class TiltBaseline extends Command
{
    protected $signature = 'tilt:baseline
                            {action=show : show | capture | thermal}
                            {--sensor=SENSOR-001}
                            {--hours=168 : window for the thermal model}
                            {--minutes=60 : averaging window for a capture}';

    protected $description = 'Capture or inspect the tilt baseline a settlement alarm measures against';

    public function handle(TiltMonitor $monitor): int
    {
        $sensorId = $this->option('sensor');
        $sensor = Sensor::where('sensor_id', $sensorId)->first();

        if (! $sensor) {
            $this->error("No sensor {$sensorId}");

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'capture' => $this->capture($sensor, $monitor),
            'thermal' => $this->thermal($sensorId, $monitor),
            default => $this->show($sensor, $monitor),
        };
    }

    private function thermal(string $sensorId, TiltMonitor $monitor): int
    {
        $hours = (int) $this->option('hours');
        $model = $monitor->thermalModel($sensorId, $hours);

        if ($model === null) {
            $this->warn("Not enough quiet data in the last {$hours} h to fit a model.");
            $this->line('Leave the sensor undisturbed and try again - it needs 30 five-minute');
            $this->line('averages taken while nothing was touching it.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d quiet samples over %d h', $model['samples'], $hours));
        $this->line(sprintf('  temperature spanned  %.2f degC', $model['temp_range']));
        $this->line(sprintf('  tilt spanned         %.4f deg', $model['tilt_range']));
        $this->line(sprintf('  correlation          %+.3f', $model['correlation']));
        $this->line(sprintf('  slope                %+.4f deg/degC', $model['slope']));
        $this->newLine();

        $swing = abs($model['slope']) * 20;
        $this->line(sprintf('  A 20 degC day/night swing implies %.3f deg of apparent tilt.', $swing));

        if (! $model['significant']) {
            $this->warn('  NOT usable as a correction yet.');
            if ($model['disturbed'] ?? false) {
                $this->line(sprintf(
                    '  Tilt moved %.2f deg during this window. That is a re-orientation,',
                    $model['tilt_range'],
                ));
                $this->line('  not drift - the sensor was moved. Fitting temperature against it');
                $this->line('  produces a confident slope that means nothing.');
                $this->line('  Mount the sensor permanently, then fit over undisturbed data.');
            } elseif ($model['temp_range'] < 2.0) {
                $this->line(sprintf(
                    '  Temperature only varied %.2f degC. A slope fitted across that cannot be',
                    $model['temp_range'],
                ));
                $this->line('  extrapolated to a real outdoor range - it would inject more error');
                $this->line('  than it removes. Collect data across at least 2 degC.');
            } else {
                $this->line('  Correlation is too weak to attribute the movement to temperature.');
            }

            return self::SUCCESS;
        }

        $this->info('  Usable. Capture a baseline to apply it.');

        return self::SUCCESS;
    }

    private function capture(Sensor $sensor, TiltMonitor $monitor): int
    {
        $minutes = (int) $this->option('minutes');

        $now = DB::selectOne(<<<'SQL'
            SELECT avg(value) FILTER (WHERE channel_key = 'incl_tilt')   AS tilt,
                   avg(value) FILTER (WHERE channel_key = 'incl_roll')   AS roll,
                   avg(value) FILTER (WHERE channel_key = 'incl_pitch')  AS pitch,
                   avg(value) FILTER (WHERE channel_key = 'temperature') AS temp,
                   count(*) FILTER (WHERE channel_key = 'incl_tilt')     AS samples,
                   max(value) FILTER (WHERE channel_key = 'accel_amplitude_x') AS amp
            FROM measurements
            WHERE sensor_id = ? AND time > now() - (? || ' minutes')::interval
        SQL, [$sensor->sensor_id, $minutes]);

        if (! $now || $now->samples < 100) {
            $this->error(sprintf('Only %d samples in %d minutes. Need at least 100.',
                (int) ($now->samples ?? 0), $minutes));

            return self::FAILURE;
        }

        // A baseline captured while somebody was mounting the sensor records the
        // mounting, not the structure. Refused rather than warned about: every
        // later reading is measured against this number.
        if ((float) ($now->amp ?? 0) > 0.05) {
            $this->error(sprintf(
                'The sensor was disturbed during the window (%.3f g). A baseline taken',
                (float) $now->amp,
            ));
            $this->line('while it was being handled would offset every future reading.');
            $this->line('Leave it undisturbed for the full window and capture again.');

            return self::FAILURE;
        }

        $model = $monitor->thermalModel($sensor->sensor_id, (int) $this->option('hours'));
        // The window length, not just the sample count. Sampling faster than
        // the sensor's own filter adds rows, not information.
        $resolution = $monitor->resolution((int) $now->samples, $minutes * 60.0);

        $baseline = [
            'tilt' => round((float) $now->tilt, 4),
            'roll' => round((float) $now->roll, 4),
            'pitch' => round((float) $now->pitch, 4),
            'temp' => round((float) $now->temp, 2),
            'samples' => (int) $now->samples,
            'window_minutes' => $minutes,
            'effective_samples' => $resolution['effective_samples'],
            'captured_at' => now()->toIso8601String(),
            'thermal_model' => $model,
            'resolution_deg' => $resolution['averaged_deg'],
        ];

        $metadata = $sensor->metadata ?? [];
        $metadata['tilt_baseline'] = $baseline;
        $sensor->metadata = $metadata;
        $sensor->save();

        $this->info('Baseline captured.');
        $this->line(sprintf('  tilt        %.4f deg', $baseline['tilt']));
        $this->line(sprintf('  roll/pitch  %.4f / %.4f deg', $baseline['roll'], $baseline['pitch']));
        $this->line(sprintf('  temperature %.2f degC', $baseline['temp']));
        $this->line(sprintf('  averaged over %d samples -> resolution about %.4f deg',
            $baseline['samples'], $baseline['resolution_deg']));
        $this->newLine();

        if ($model && $model['significant']) {
            $this->info(sprintf('  Thermal correction active: %+.4f deg/degC', $model['slope']));
        } else {
            $this->warn('  No usable thermal model yet - deviations will be uncorrected.');
            $this->line('  Re-capture after a week of temperature variation.');
        }

        return self::SUCCESS;
    }

    private function show(Sensor $sensor, TiltMonitor $monitor): int
    {
        $baseline = ($sensor->metadata ?? [])['tilt_baseline'] ?? null;

        if (! $baseline) {
            $this->warn("No baseline for {$sensor->sensor_id}.");
            $this->line('Mount the sensor, leave it undisturbed for an hour, then:');
            $this->line('  php artisan tilt:baseline capture');

            return self::SUCCESS;
        }

        $this->line(sprintf('Baseline captured %s', $baseline['captured_at']));
        $this->line(sprintf('  tilt %.4f deg at %.2f degC', $baseline['tilt'], $baseline['temp']));
        $this->newLine();

        $deviation = $monitor->deviation($sensor->sensor_id, $baseline);

        if (! ($deviation['available'] ?? false)) {
            $this->warn('  '.($deviation['reason'] ?? 'no current reading'));

            return self::SUCCESS;
        }

        $this->line(sprintf('Now  %.4f deg at %.2f degC (%d samples)',
            $deviation['tilt_now'], $deviation['temperature_now'], $deviation['samples']));
        $this->line(sprintf('  raw deviation        %+.4f deg', $deviation['raw_deviation']));

        if ($deviation['compensated']) {
            $this->line(sprintf('  explained by temp    %+.4f deg', $deviation['thermal_component']));
            $this->info(sprintf('  MOVEMENT             %+.4f deg', $deviation['corrected_deviation']));
        } else {
            $this->warn(sprintf('  MOVEMENT             %+.4f deg (uncompensated)',
                $deviation['corrected_deviation']));
            $this->line('  No thermal model in force - some of this may be temperature.');
        }

        return self::SUCCESS;
    }
}
