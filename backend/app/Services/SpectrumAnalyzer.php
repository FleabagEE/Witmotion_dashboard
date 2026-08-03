<?php

namespace App\Services;

/**
 * Spectral analysis of a polled channel, with an honesty gate in front of it.
 *
 * The temptation here is to run an FFT over the stored series and draw whatever
 * comes out. That would be wrong twice over.
 *
 * First, polled Modbus is not uniformly sampled. Every reading arrives when the
 * scheduler and the bus allowed it, not on a metronome, and an FFT assumes a
 * metronome. So this uses a Lomb-Scargle periodogram, which is built for
 * unevenly sampled data and does not require resampling the record onto a grid
 * it was never measured on.
 *
 * Second, Nyquist is an upper bound for perfect uniform sampling and we have
 * neither. The appliance advertises 0.4*fs rather than fs/2 and refuses to
 * produce a spectrum beyond it, returning an explanation the UI must show. A
 * blank chart with a reason is worth more than a plausible one that is wrong.
 *
 * None of this limits what the appliance can see at high frequency: the sensor
 * computes its own dominant frequency internally at full rate, and that value
 * is returned alongside every spectrum for comparison. This service analyses
 * only what *we* sampled.
 */
class SpectrumAnalyzer
{
    /**
     * Fraction of the sample rate we are willing to claim.
     *
     * Nyquist permits 0.5. Polled sampling is jittered, which smears spectral
     * content, so the defensible band stops well short of it. Mirrors
     * qv_acq.throughput.spectral_verdict; the two must not drift apart.
     */
    public const USABLE_FRACTION = 0.40;

    /**
     * Cost is O(samples * frequencies). Beyond this the request is decimated
     * rather than allowed to tie up a request thread - and the response says so
     * rather than quietly returning a thinner analysis than was asked for.
     */
    public const MAX_SAMPLES = 4000;

    /** Frequency bins in the returned periodogram. */
    public const FREQUENCY_BINS = 256;

    /**
     * Measure the true sample rate from the timestamps themselves.
     *
     * Deliberately not read from channels.configured_hz: that records what the
     * profile asked for, drifts from the deployed poll_hz override, and on this
     * appliance is already stale. The timestamps record what actually happened.
     *
     * @param  array<int, float>  $times  seconds, ascending
     * @return array{sample_hz: float|null, jitter_ms: float|null, span_seconds: float}
     */
    public function sampling(array $times): array
    {
        $n = count($times);
        if ($n < 2) {
            return ['sample_hz' => null, 'jitter_ms' => null, 'span_seconds' => 0.0];
        }

        $deltas = [];
        for ($i = 1; $i < $n; $i++) {
            $d = $times[$i] - $times[$i - 1];
            if ($d > 0) {
                $deltas[] = $d;
            }
        }
        if ($deltas === []) {
            return ['sample_hz' => null, 'jitter_ms' => null, 'span_seconds' => 0.0];
        }

        sort($deltas);
        $count = count($deltas);
        // Median, not mean: one gap from a restart or a retry storm would drag
        // a mean far from the rate that actually held for most of the window.
        $median = $count % 2
            ? $deltas[intdiv($count, 2)]
            : ($deltas[$count / 2 - 1] + $deltas[$count / 2]) / 2;

        // Spread of the intervals about that median, in milliseconds. This is
        // the jitter that limits how far the spectrum can be trusted.
        $absoluteDeviations = array_map(fn ($d) => abs($d - $median), $deltas);
        sort($absoluteDeviations);
        $mad = $count % 2
            ? $absoluteDeviations[intdiv($count, 2)]
            : ($absoluteDeviations[$count / 2 - 1] + $absoluteDeviations[$count / 2]) / 2;

        return [
            'sample_hz' => $median > 0 ? 1.0 / $median : null,
            'jitter_ms' => $mad * 1000.0,
            'span_seconds' => $times[$n - 1] - $times[0],
        ];
    }

    /**
     * Decide whether spectral analysis up to $requestedHz is defensible.
     *
     * Ported from qv_acq.throughput.spectral_verdict so the API and the
     * acquisition service give the same answer to the same question.
     *
     * @return array{allowed: bool, explanation: string}
     */
    public function verdict(float $sampleHz, float $requestedHz): array
    {
        $usable = $sampleHz * self::USABLE_FRACTION;
        $nyquist = $sampleHz / 2;

        if ($requestedHz <= $usable) {
            return ['allowed' => true, 'explanation' => sprintf(
                'Requested %.1f Hz is within the %.1f Hz defensible band for a %.1f Hz sampled channel.',
                $requestedHz, $usable, $sampleHz,
            )];
        }

        if ($requestedHz <= $nyquist) {
            return ['allowed' => false, 'explanation' => sprintf(
                'Requested %.1f Hz sits between the defensible band (%.1f Hz) and Nyquist (%.1f Hz). '
                .'Polled Modbus sampling is non-uniform; spectra in this range smear. '
                .'Use a dedicated capture window on an otherwise idle bus.',
                $requestedHz, $usable, $nyquist,
            )];
        }

        return ['allowed' => false, 'explanation' => sprintf(
            'Requested %.1f Hz exceeds Nyquist (%.1f Hz) for this channel. Aliasing would '
            .'fabricate spectral content. Increase baud, reduce sensors per bus, or read the '
            .'sensor\'s own dominant-frequency registers, which are computed on-device at full rate.',
            $requestedHz, $nyquist,
        )];
    }

    /**
     * Lomb-Scargle normalised periodogram.
     *
     * The classical formulation for unevenly sampled data. Each trial frequency
     * gets a time offset tau that makes the sine and cosine sums orthogonal,
     * which is what makes the result meaningful without a uniform grid.
     *
     * @param  array<int, float>  $times       seconds
     * @param  array<int, float>  $values
     * @param  array<int, float>  $frequencies Hz
     * @return array<int, float>  normalised power per frequency
     */
    public function lombScargle(array $times, array $values, array $frequencies): array
    {
        $n = count($values);
        if ($n < 3) {
            return array_fill(0, count($frequencies), 0.0);
        }

        $mean = array_sum($values) / $n;
        $centred = array_map(fn ($v) => $v - $mean, $values);
        $variance = 0.0;
        foreach ($centred as $c) {
            $variance += $c * $c;
        }
        $variance /= ($n - 1);

        // A dead-flat channel has no spectrum. Returning zeros beats dividing by
        // a variance of zero and emitting NAN, which a chart would render as a
        // gap and a reader might mistake for missing data.
        if ($variance <= 0.0) {
            return array_fill(0, count($frequencies), 0.0);
        }

        $power = [];
        foreach ($frequencies as $f) {
            $omega = 2 * M_PI * $f;

            $sumSin2 = 0.0;
            $sumCos2 = 0.0;
            foreach ($times as $t) {
                $sumSin2 += sin(2 * $omega * $t);
                $sumCos2 += cos(2 * $omega * $t);
            }
            $tau = atan2($sumSin2, $sumCos2) / (2 * $omega);

            $cTerm = 0.0;
            $sTerm = 0.0;
            $cc = 0.0;
            $ss = 0.0;
            foreach ($times as $i => $t) {
                $arg = $omega * ($t - $tau);
                $cosArg = cos($arg);
                $sinArg = sin($arg);
                $cTerm += $centred[$i] * $cosArg;
                $sTerm += $centred[$i] * $sinArg;
                $cc += $cosArg * $cosArg;
                $ss += $sinArg * $sinArg;
            }

            $power[] = (1.0 / (2.0 * $variance)) * (
                ($cc > 0 ? ($cTerm * $cTerm) / $cc : 0.0)
                + ($ss > 0 ? ($sTerm * $sTerm) / $ss : 0.0)
            );
        }

        return $power;
    }

    /**
     * Probability that a peak this tall would arise from noise alone.
     *
     * Without this a reader will treat the tallest bar as a resonance no matter
     * how flat the spectrum is. A peak with a false-alarm probability of 0.6 is
     * not a finding, and the UI needs to be able to say so.
     */
    public function falseAlarmProbability(float $peakPower, int $independentFrequencies): float
    {
        if ($independentFrequencies < 1) {
            return 1.0;
        }

        $p = 1.0 - pow(1.0 - exp(-$peakPower), $independentFrequencies);

        return max(0.0, min(1.0, $p));
    }

    /**
     * Full analysis of one channel's series.
     *
     * @param  array<int, array{t: float, v: float}>  $samples ascending by t
     * @return array<string, mixed>
     */
    public function analyse(array $samples, ?float $requestedHz = null): array
    {
        $decimation = 1;
        $originalCount = count($samples);
        if ($originalCount > self::MAX_SAMPLES) {
            // Decimate evenly rather than truncate: taking the first N would
            // silently analyse a shorter window than the caller asked for.
            $decimation = (int) ceil($originalCount / self::MAX_SAMPLES);
            $samples = array_values(array_filter(
                $samples,
                fn ($_, $i) => $i % $decimation === 0,
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        $times = array_column($samples, 't');
        $values = array_column($samples, 'v');
        $sampling = $this->sampling($times);

        $base = [
            'samples' => count($samples),
            'samples_available' => $originalCount,
            // Surfaced, never silent: a decimated analysis resolves less than an
            // undecimated one and the caller is entitled to know it happened.
            'decimation' => $decimation,
            'sample_hz' => $sampling['sample_hz'],
            'jitter_ms' => $sampling['jitter_ms'],
            'span_seconds' => $sampling['span_seconds'],
        ];

        if ($sampling['sample_hz'] === null || count($samples) < 8) {
            return $base + [
                'allowed' => false,
                'explanation' => 'Not enough samples in this window to estimate a spectrum. '
                    .'Choose a longer window, or wait for the channel to accumulate history.',
                'spectrum' => null,
            ];
        }

        $sampleHz = $sampling['sample_hz'];
        $defensibleMax = $sampleHz * self::USABLE_FRACTION;
        $requested = $requestedHz ?? $defensibleMax;
        $verdict = $this->verdict($sampleHz, $requested);

        $base += [
            'defensible_max_hz' => $defensibleMax,
            'nyquist_hz' => $sampleHz / 2,
            'requested_hz' => $requested,
            'allowed' => $verdict['allowed'],
            'explanation' => $verdict['explanation'],
        ];

        if (! $verdict['allowed']) {
            return $base + ['spectrum' => null];
        }

        // The longest period observable is the window itself; anything slower
        // cannot complete a cycle here and would be fitting the trend, not a
        // frequency.
        $span = $sampling['span_seconds'];
        if ($span <= 0) {
            return $base + ['allowed' => false, 'spectrum' => null,
                'explanation' => 'All samples share one timestamp; no frequency is observable.'];
        }

        // Defensive, and currently unreachable: for uniform-ish sampling
        // span ~= (n-1)/fs, so this needs n <= 3.5, which the >= 8 sample floor
        // above already rejects. Kept because it becomes reachable the moment
        // either constant changes, and a spectrum drawn over a band that
        // contains no observable frequency would be pure artefact.
        $minHz = 1.0 / $span;
        if ($minHz >= $defensibleMax) {
            return $base + ['allowed' => false, 'spectrum' => null, 'explanation' => sprintf(
                'This window is too short to resolve anything inside the defensible band: the '
                .'slowest observable frequency is %.2f Hz and the band ends at %.2f Hz. '
                .'Choose a longer window.',
                $minHz, $defensibleMax,
            )];
        }

        $frequencies = [];
        $step = ($defensibleMax - $minHz) / (self::FREQUENCY_BINS - 1);
        for ($i = 0; $i < self::FREQUENCY_BINS; $i++) {
            $frequencies[] = $minHz + $i * $step;
        }

        $power = $this->lombScargle($times, $values, $frequencies);

        $peakIndex = 0;
        foreach ($power as $i => $p) {
            if ($p > $power[$peakIndex]) {
                $peakIndex = $i;
            }
        }

        $fap = $this->falseAlarmProbability($power[$peakIndex], count($frequencies));

        return $base + [
            'spectrum' => [
                'frequencies' => array_map(fn ($f) => round($f, 4), $frequencies),
                'power' => array_map(fn ($p) => round($p, 6), $power),
                'min_hz' => round($minHz, 4),
                'peak_hz' => round($frequencies[$peakIndex], 4),
                'peak_power' => round($power[$peakIndex], 6),
                'false_alarm_probability' => round($fap, 6),
                // The threshold is conventional, not derived. Above it, a peak
                // is not evidence of anything and must not be presented as if
                // it were.
                'peak_significant' => $fap < 0.01,
            ],
        ];
    }
}
