<?php

namespace Tests\Feature;

use App\Services\SpectrumAnalyzer;
use Tests\TestCase;

/**
 * The analyser decides what the appliance is willing to claim about frequency
 * content. Two failure modes matter and both are silent: drawing a spectrum the
 * sampling cannot support, and presenting a noise peak as a resonance.
 */
class SpectrumAnalyzerTest extends TestCase
{
    private SpectrumAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SpectrumAnalyzer();
    }

    /** @return array<int, array{t: float, v: float}> */
    private function sine(float $hz, float $sampleHz, float $seconds, float $jitter = 0.0): array
    {
        $samples = [];
        $n = (int) ($sampleHz * $seconds);
        for ($i = 0; $i < $n; $i++) {
            // Deterministic pseudo-jitter: a real polled bus never lands on the
            // metronome, and a test on perfectly uniform data would not
            // exercise what Lomb-Scargle is here for.
            $wobble = $jitter * sin($i * 12.9898);
            $t = $i / $sampleHz + $wobble;
            $samples[] = ['t' => $t, 'v' => sin(2 * M_PI * $hz * $t)];
        }

        return $samples;
    }

    // --- the gate ----------------------------------------------------------

    public function test_a_request_inside_the_defensible_band_is_allowed(): void
    {
        $verdict = $this->analyzer->verdict(sampleHz: 8.0, requestedHz: 3.0);

        $this->assertTrue($verdict['allowed']);
        $this->assertStringContainsString('3.2 Hz defensible band', $verdict['explanation']);
    }

    public function test_the_band_between_defensible_and_nyquist_is_refused_with_a_reason(): void
    {
        // 8 Hz sampling: defensible to 3.2 Hz, Nyquist at 4 Hz. Textbook theory
        // permits 3.8 Hz; jittered polling does not.
        $verdict = $this->analyzer->verdict(sampleHz: 8.0, requestedHz: 3.8);

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('smear', $verdict['explanation']);
    }

    public function test_beyond_nyquist_the_explanation_names_aliasing(): void
    {
        $verdict = $this->analyzer->verdict(sampleHz: 8.0, requestedHz: 50.0);

        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('fabricate', $verdict['explanation']);
        // And points at the honest alternative rather than just refusing.
        $this->assertStringContainsString('on-device', $verdict['explanation']);
    }

    public function test_the_gate_matches_the_acquisition_services_constant(): void
    {
        // qv_acq.throughput uses 0.40. If these drift apart the API and the
        // acquisition service will answer the same question differently.
        $this->assertSame(0.40, SpectrumAnalyzer::USABLE_FRACTION);
    }

    // --- sample rate is measured, not assumed ------------------------------

    public function test_the_sample_rate_comes_from_the_timestamps(): void
    {
        $sampling = $this->analyzer->sampling(array_map(fn ($i) => $i / 8.0, range(0, 79)));

        $this->assertEqualsWithDelta(8.0, $sampling['sample_hz'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sampling['jitter_ms'], 0.01);
    }

    public function test_one_long_gap_does_not_drag_the_measured_rate(): void
    {
        // A service restart leaves a hole. The median must ignore it; a mean
        // would report a rate that never held at any point in the window.
        $times = array_map(fn ($i) => $i / 8.0, range(0, 39));
        $times = array_merge($times, array_map(fn ($i) => 120.0 + $i / 8.0, range(0, 39)));

        $this->assertEqualsWithDelta(8.0, $this->analyzer->sampling($times)['sample_hz'], 0.01);
    }

    public function test_jitter_is_reported(): void
    {
        $times = [];
        for ($i = 0; $i < 80; $i++) {
            $times[] = $i / 8.0 + 0.01 * ($i % 2);
        }

        $this->assertGreaterThan(0.0, $this->analyzer->sampling($times)['jitter_ms']);
    }

    // --- does it actually find the right frequency? ------------------------

    public function test_it_recovers_a_known_tone_from_unevenly_sampled_data(): void
    {
        // 1 Hz tone, 8 Hz polling, 60 s, with jitter - the real situation.
        $result = $this->analyzer->analyse($this->sine(hz: 1.0, sampleHz: 8.0, seconds: 60, jitter: 0.01));

        $this->assertTrue($result['allowed']);
        $this->assertEqualsWithDelta(1.0, $result['spectrum']['peak_hz'], 0.05);
        $this->assertTrue($result['spectrum']['peak_significant']);
    }

    public function test_a_real_tone_is_statistically_significant(): void
    {
        $result = $this->analyzer->analyse($this->sine(hz: 2.0, sampleHz: 8.0, seconds: 120, jitter: 0.005));

        $this->assertLessThan(0.01, $result['spectrum']['false_alarm_probability']);
    }

    public function test_a_flat_channel_produces_no_peak_and_no_nan(): void
    {
        // Temperature sitting still. A zero variance must not divide through
        // and emit NAN, which a chart renders as a gap that reads as missing
        // data rather than as an absence of vibration.
        $samples = array_map(fn ($i) => ['t' => $i / 8.0, 'v' => 23.5], range(0, 199));
        $result = $this->analyzer->analyse($samples);

        $this->assertSame(0.0, $result['spectrum']['peak_power']);
        $this->assertFalse($result['spectrum']['peak_significant']);
        foreach ($result['spectrum']['power'] as $p) {
            $this->assertFalse(is_nan($p), 'spectrum contains NAN');
        }
    }

    public function test_noise_does_not_yield_a_significant_peak(): void
    {
        // A linear congruential generator, not a product of sinusoids: sin(ai)
        // * cos(bi) is by product-to-sum two pure tones, so it would be a test
        // of whether the analyser can find a tone, dressed up as noise.
        //
        // The tallest bar in a noise spectrum is still the tallest bar; only
        // the false-alarm probability separates it from a finding.
        $samples = [];
        $seed = 20260803;
        for ($i = 0; $i < 400; $i++) {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            $samples[] = ['t' => $i / 8.0, 'v' => $seed / 2147483648 - 0.5];
        }
        $result = $this->analyzer->analyse($samples);

        $this->assertFalse(
            $result['spectrum']['peak_significant'],
            'pseudo-noise produced a peak reported as significant',
        );
    }

    // --- refusals ----------------------------------------------------------

    public function test_too_few_samples_is_refused_with_advice(): void
    {
        $samples = array_map(fn ($i) => ['t' => $i / 8.0, 'v' => (float) $i], range(0, 3));
        $result = $this->analyzer->analyse($samples);

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['spectrum']);
        $this->assertStringContainsString('longer window', $result['explanation']);
    }

    public function test_a_window_with_no_elapsed_time_is_refused(): void
    {
        // Every sample carrying the same timestamp. No frequency is observable
        // in zero elapsed time, and dividing by that span would emit INF.
        $samples = array_map(fn ($i) => ['t' => 100.0, 'v' => (float) $i], range(0, 19));
        $result = $this->analyzer->analyse($samples);

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['spectrum']);
    }

    public function test_a_request_beyond_the_band_returns_no_spectrum_at_all(): void
    {
        $result = $this->analyzer->analyse(
            $this->sine(hz: 1.0, sampleHz: 8.0, seconds: 60),
            requestedHz: 100.0,
        );

        // Not a truncated spectrum, not a best effort: nothing.
        $this->assertFalse($result['allowed']);
        $this->assertNull($result['spectrum']);
    }

    // --- no silent caps ----------------------------------------------------

    public function test_decimation_is_reported_rather_than_applied_quietly(): void
    {
        $result = $this->analyzer->analyse($this->sine(hz: 1.0, sampleHz: 8.0, seconds: 1200));

        $this->assertGreaterThan(SpectrumAnalyzer::MAX_SAMPLES, $result['samples_available']);
        $this->assertGreaterThan(1, $result['decimation']);
        $this->assertLessThanOrEqual(SpectrumAnalyzer::MAX_SAMPLES, $result['samples']);
    }

    public function test_decimation_keeps_the_whole_window_not_just_the_start(): void
    {
        $samples = $this->sine(hz: 1.0, sampleHz: 8.0, seconds: 1200);
        $result = $this->analyzer->analyse($samples);

        // Truncating to the first N would silently analyse a shorter window
        // than was asked for, and report a span to match.
        $this->assertEqualsWithDelta(1200.0, $result['span_seconds'], 5.0);
    }

    // --- false alarm probability -------------------------------------------

    public function test_a_towering_peak_is_effectively_certain(): void
    {
        $this->assertLessThan(1e-6, $this->analyzer->falseAlarmProbability(30.0, 256));
    }

    public function test_a_shallow_peak_is_indistinguishable_from_noise(): void
    {
        $this->assertGreaterThan(0.5, $this->analyzer->falseAlarmProbability(2.0, 256));
    }

    public function test_probability_stays_within_bounds(): void
    {
        foreach ([0.0, 0.5, 5.0, 50.0, 500.0] as $power) {
            $p = $this->analyzer->falseAlarmProbability($power, 256);
            $this->assertGreaterThanOrEqual(0.0, $p);
            $this->assertLessThanOrEqual(1.0, $p);
        }
    }

    // --- drift must not masquerade as vibration ----------------------------

    public function test_a_linear_ramp_is_removed(): void
    {
        $times = array_map(fn ($i) => $i / 8.0, range(0, 99));
        $values = array_map(fn ($t) => 5.0 + 2.0 * $t, $times);

        foreach ($this->analyzer->detrend($times, $values) as $residual) {
            $this->assertEqualsWithDelta(0.0, $residual, 1e-9);
        }
    }

    public function test_a_drifting_but_still_sensor_reports_no_finding(): void
    {
        // The case that prompted this: an accelerometer sitting motionless on a
        // desk, its bias creeping with temperature. Before detrending this
        // reported a "significant component" at the bottom bin with a
        // false-alarm probability of zero - drift wearing a resonance's
        // clothes.
        $samples = [];
        for ($i = 0; $i < 800; $i++) {
            $t = $i / 8.0;
            $samples[] = ['t' => $t, 'v' => 0.947 + 0.00002 * $t];
        }
        $result = $this->analyzer->analyse($samples);

        $this->assertFalse(
            $result['spectrum']['peak_significant'],
            'a monotonic drift was reported as a real spectral component',
        );
    }

    public function test_a_real_tone_survives_detrending(): void
    {
        // Detrending must not cost us the signal it is protecting.
        $samples = [];
        for ($i = 0; $i < 800; $i++) {
            $t = $i / 8.0;
            $samples[] = ['t' => $t, 'v' => 0.947 + 0.0001 * $t + 0.05 * sin(2 * M_PI * 1.5 * $t)];
        }
        $result = $this->analyzer->analyse($samples);

        $this->assertEqualsWithDelta(1.5, $result['spectrum']['peak_hz'], 0.05);
        $this->assertTrue($result['spectrum']['peak_significant']);
    }

    public function test_the_trend_bins_are_plotted_but_not_reportable(): void
    {
        $result = $this->analyzer->analyse($this->sine(hz: 1.0, sampleHz: 8.0, seconds: 300));
        $spectrum = $result['spectrum'];

        // Still returned in full, so the drift remains visible on the chart...
        $this->assertCount(SpectrumAnalyzer::FREQUENCY_BINS, $spectrum['frequencies']);
        // ...but the reported peak can never come from the excluded bins.
        $this->assertGreaterThanOrEqual($spectrum['lowest_reportable_hz'], $spectrum['peak_hz']);
        $this->assertGreaterThan($spectrum['min_hz'], $spectrum['lowest_reportable_hz']);
        $this->assertTrue($spectrum['detrended']);
    }

    // --- a transient is an event, not a spectrum ---------------------------

    public function test_a_short_burst_in_a_long_window_is_called_transient(): void
    {
        // Three seconds of shaking inside a fifteen-minute record - exactly the
        // bench test that exposed this. Before the check it reported a peak at
        // 0.026 Hz with a false-alarm probability of zero, which described the
        // window length, not the structure.
        $samples = [];
        for ($i = 0; $i < 3600; $i++) {
            $t = $i / 4.0;                       // 4 Hz over 900 s
            $shaking = $t > 400 && $t < 403;     // a 3 s event
            $samples[] = ['t' => $t, 'v' => $shaking ? 300 * sin(2 * M_PI * 2.3 * $t) : 0.5];
        }
        $result = $this->analyzer->analyse($samples);

        $this->assertTrue($result['spectrum']['transient']);
        $this->assertFalse(
            $result['spectrum']['peak_significant'],
            'a transient produced a peak reported as a real spectral component',
        );
        $this->assertStringContainsString('Narrow the window', $result['spectrum']['transient_note']);
    }

    public function test_sustained_vibration_is_not_called_transient(): void
    {
        // The check must not fire on the thing it is protecting: a structure
        // ringing steadily is stationary and has a real spectrum.
        $result = $this->analyzer->analyse($this->sine(hz: 1.5, sampleHz: 8.0, seconds: 300));

        $this->assertFalse($result['spectrum']['transient']);
        $this->assertTrue($result['spectrum']['peak_significant']);
        $this->assertEqualsWithDelta(1.5, $result['spectrum']['peak_hz'], 0.05);
    }

    public function test_energy_concentration_separates_the_two_cases(): void
    {
        $steady = array_map(fn ($i) => sin($i / 3.0), range(0, 999));
        $burst = array_map(fn ($i) => ($i > 500 && $i < 540) ? 100.0 : 0.0, range(0, 999));

        // Ten blocks, so stationary content sits near a tenth.
        $this->assertLessThan(0.2, $this->analyzer->energyConcentration($steady));
        $this->assertGreaterThan(0.9, $this->analyzer->energyConcentration($burst));
    }
}
