"""The integration chain, checked against closed-form answers.

Signal processing is easy to get plausibly wrong: a filter with the wrong sign,
an integration off by a factor of two, a phase shift nobody notices. All of them
produce output that looks like a waveform. So nothing here asserts that the code
agrees with itself - every numeric test compares against an analytic result.

For a = A sin(2*pi*f*t):

    v = -A/(2*pi*f) cos(2*pi*f*t)          amplitude A/(2*pi*f)
    d = -A/(2*pi*f)^2 sin(2*pi*f*t)        amplitude A/(2*pi*f)^2

Those two ratios are the backbone of this file.
"""

from __future__ import annotations

import math

import pytest

from qv_acq.dsp import (
    G,
    NotAWaveform,
    cosine_taper,
    decimate,
    highpass,
    integrate,
    process,
    refuse_if_not_a_waveform,
    remove_mean,
    remove_trend,
)


def sine(amplitude: float, hz: float, seconds: float, sample_hz: float,
         offset: float = 0.0, drift: float = 0.0) -> list[float]:
    n = int(seconds * sample_hz)
    return [
        amplitude * math.sin(2 * math.pi * hz * (i / sample_hz))
        + offset
        + drift * (i / sample_hz)
        for i in range(n)
    ]


# --- the guard --------------------------------------------------------------

def test_a_quasi_static_register_is_refused() -> None:
    """The measured behaviour of the WTVB01-485 acceleration register.

    2% of adjacent samples differ. Integrating it twice yields a smooth curve
    indistinguishable from a real displacement trace, which is exactly why this
    refuses rather than warns.
    """
    samples = []
    for i in range(1000):
        samples.append(0.947 if i % 50 else 0.948)

    with pytest.raises(NotAWaveform, match="not a waveform"):
        refuse_if_not_a_waveform(samples)


def test_a_real_waveform_passes_the_guard() -> None:
    # Noise alone makes a real accelerometer differ on nearly every sample.
    samples = sine(1.0, 5.0, 4.0, 200.0)
    refuse_if_not_a_waveform(samples)


def test_the_guard_explains_itself_rather_than_just_refusing() -> None:
    with pytest.raises(NotAWaveform) as exc:
        refuse_if_not_a_waveform([1.0] * 500)

    assert "Integrating it would produce" in str(exc.value)


def test_too_few_samples_is_refused() -> None:
    with pytest.raises(NotAWaveform, match="nothing to process"):
        refuse_if_not_a_waveform([1.0, 2.0])


# --- preparation ------------------------------------------------------------

def test_mean_removal() -> None:
    assert sum(remove_mean([1.0, 2.0, 3.0, 4.0])) == pytest.approx(0.0)


def test_trend_removal_flattens_a_ramp() -> None:
    # A ramp left in the record becomes a parabola after one integration.
    for residual in remove_trend([i * 3.0 + 7 for i in range(100)]):
        assert residual == pytest.approx(0.0, abs=1e-9)


def test_trend_removal_keeps_the_signal() -> None:
    clean = sine(1.0, 2.0, 4.0, 200.0)
    on_a_ramp = sine(1.0, 2.0, 4.0, 200.0, drift=0.5)

    detrended = remove_trend(on_a_ramp)
    mid = slice(100, 700)

    for a, b in zip(detrended[mid], remove_trend(clean)[mid]):
        assert a == pytest.approx(b, abs=0.02)


def test_taper_brings_the_ends_to_zero() -> None:
    tapered = cosine_taper([1.0] * 200, fraction=0.1)

    assert tapered[0] == pytest.approx(0.0)
    assert tapered[-1] == pytest.approx(0.0)
    assert tapered[100] == pytest.approx(1.0)


# --- filtering --------------------------------------------------------------

def test_the_highpass_removes_a_constant() -> None:
    filtered = highpass([5.0] * 2000, 0.5, 100.0)

    # Away from the edges, a DC input is gone.
    assert max(abs(v) for v in filtered[500:1500]) < 0.05


def test_the_highpass_passes_content_above_the_corner() -> None:
    signal = sine(1.0, 10.0, 8.0, 200.0)
    filtered = highpass(signal, 0.5, 200.0)

    original = max(abs(v) for v in signal[200:1400])
    passed = max(abs(v) for v in filtered[200:1400])

    assert passed == pytest.approx(original, rel=0.05)


def test_the_highpass_attenuates_content_below_the_corner() -> None:
    signal = sine(1.0, 0.05, 60.0, 50.0)
    filtered = highpass(signal, 1.0, 50.0)

    assert max(abs(v) for v in filtered[500:2500]) < 0.15


def test_filtering_does_not_shift_the_signal_in_time() -> None:
    """Zero phase, because a shifted peak misaligns against every other channel.

    A one-way IIR filter delays by a frequency-dependent amount. Forward and
    backward cancels it exactly - this checks the peak has not moved.
    """
    signal = sine(1.0, 5.0, 4.0, 200.0)
    filtered = highpass(signal, 0.5, 200.0)

    # Compared sample by sample, not by peak position. Every peak of a pure sine
    # is the same height, so "which one is largest" is decided by floating-point
    # noise - the first version of this test compared two peaks two periods
    # apart and called it an 80-sample phase shift.
    #
    # Well above the corner the filter should be transparent, so a zero-phase
    # implementation reproduces the input point for point. A one-way filter
    # would show a visible offset here.
    for a, b in zip(signal[400:600], filtered[400:600]):
        assert b == pytest.approx(a, abs=0.01)


# --- integration, against closed-form answers -------------------------------

def test_integrating_a_sine_gives_the_analytic_amplitude() -> None:
    amplitude, hz, rate = 4.0, 5.0, 400.0
    expected = amplitude / (2 * math.pi * hz)

    result = integrate(sine(amplitude, hz, 6.0, rate), rate)
    body = result[400:2000]

    # Peak-to-peak over two, not max(abs). Integrating from zero leaves a
    # constant - see the test below - and max(abs) would measure the signal plus
    # that constant rather than the amplitude.
    measured = (max(body) - min(body)) / 2
    assert measured == pytest.approx(expected, rel=0.02)


def test_integration_leaves_a_constant_equal_to_the_amplitude() -> None:
    """The reason the high-pass after each integration is not optional.

    Integrating A*sin(wt) from zero gives -A/w cos(wt) + A/w. The offset is
    exactly the amplitude, and a second integration would turn it into a ramp
    and then displacement walks away while looking perfectly smooth.
    """
    amplitude, hz, rate = 4.0, 5.0, 400.0
    expected_offset = amplitude / (2 * math.pi * hz)

    body = integrate(sine(amplitude, hz, 6.0, rate), rate)[400:2000]
    offset = sum(body) / len(body)

    assert offset == pytest.approx(expected_offset, rel=0.02)

    # And the filter removes it.
    filtered = highpass(integrate(sine(amplitude, hz, 6.0, rate), rate), 0.5, rate)[400:2000]
    assert abs(sum(filtered) / len(filtered)) < 0.02 * expected_offset


def test_integration_scales_with_frequency_the_way_physics_says() -> None:
    # Halving the frequency doubles the velocity amplitude for the same
    # acceleration. Any factor-of-two error in the integrator breaks this.
    rate = 400.0
    slow = max(abs(v) for v in integrate(sine(1.0, 2.5, 8.0, rate), rate)[400:2400])
    fast = max(abs(v) for v in integrate(sine(1.0, 5.0, 8.0, rate), rate)[400:2400])

    assert slow / fast == pytest.approx(2.0, rel=0.05)


# --- the whole chain --------------------------------------------------------

def test_one_g_at_ten_hz_gives_the_textbook_velocity_and_displacement() -> None:
    """The end-to-end check.

    1 g at 10 Hz is 9.80665 m/s^2, so:
        velocity     = 9.80665 / (2*pi*10)    = 0.1561 m/s = 156.1 mm/s
        displacement = 9.80665 / (2*pi*10)^2  = 0.002484 m = 2484 um
    """
    rate = 500.0
    acceleration = sine(G, 10.0, 8.0, rate)

    result = process(acceleration, rate, highpass_hz=0.5, check_is_waveform=False)
    units = result.as_engineering_units()

    mid = slice(1000, 3000)
    velocity_mm_s = max(abs(v) for v in units["velocity_mm_s"][mid])
    displacement_um = max(abs(d) for d in units["displacement_um"][mid])

    assert velocity_mm_s == pytest.approx(156.1, rel=0.05)
    assert displacement_um == pytest.approx(2484, rel=0.08)


def test_every_output_oscillates_about_zero() -> None:
    """The thing that was asked for, and the reason the chain is filtered.

    Without a high-pass between the integrations, an offset becomes a ramp and
    then a parabola, and displacement walks off to metres.
    """
    rate = 400.0
    acceleration = sine(2.0, 4.0, 10.0, rate, offset=0.3, drift=0.05)

    result = process(acceleration, rate, highpass_hz=0.3, check_is_waveform=False)

    for name, wave in (
        ("acceleration", result.acceleration),
        ("velocity", result.velocity),
        ("displacement", result.displacement),
    ):
        body = wave.samples[800:3200]
        mean = sum(body) / len(body)
        assert abs(mean) < 0.05 * wave.peak(), f"{name} is not centred on zero"


def test_a_dc_offset_does_not_become_a_ramp() -> None:
    # The classic failure: integrate a signal with an offset and displacement
    # grows without bound while looking perfectly smooth.
    rate = 200.0
    acceleration = sine(1.0, 3.0, 20.0, rate, offset=0.5)

    result = process(acceleration, rate, highpass_hz=0.2, check_is_waveform=False)

    first = result.displacement.samples[1000:1400]
    last = result.displacement.samples[3000:3400]

    assert abs(sum(last) / len(last) - sum(first) / len(first)) < 0.05 * result.displacement.peak()


def test_the_chain_refuses_a_static_series_by_default() -> None:
    with pytest.raises(NotAWaveform):
        process([0.947] * 1000, 100.0)


def test_output_carries_the_cutoff_that_produced_it() -> None:
    # A displacement trace is meaningless without the corner frequency that
    # shaped it - the number depends on the filter as much as on the structure.
    result = process(sine(1.0, 5.0, 4.0, 200.0), 200.0, highpass_hz=0.05,
                     check_is_waveform=False)

    assert result.highpass_hz == 0.05


# --- decimation -------------------------------------------------------------

def test_decimation_reduces_the_rate() -> None:
    samples, rate = decimate(sine(1.0, 2.0, 8.0, 400.0), 400.0, 4)

    assert rate == 100.0
    assert len(samples) == pytest.approx(800, abs=2)


def test_decimation_filters_before_it_drops_samples() -> None:
    """Anti-aliasing for a rate reduction we are about to perform.

    A 45 Hz component decimated to 50 Hz without filtering would fold to 5 Hz
    and be indistinguishable from real structural content.
    """
    rate = 400.0
    contaminated = [
        a + b for a, b in zip(sine(1.0, 3.0, 8.0, rate), sine(1.0, 45.0, 8.0, rate))
    ]

    reduced, new_rate = decimate(contaminated, rate, 8)   # to 50 Hz

    assert new_rate == 50.0
    # The 45 Hz content is above the new Nyquist and must be gone, not folded.
    clean = sine(1.0, 3.0, 8.0, rate)[::8]
    body = slice(50, 350)
    for a, b in zip(reduced[body], clean[body]):
        assert a == pytest.approx(b, abs=0.25)


def test_decimating_by_one_changes_nothing() -> None:
    original = sine(1.0, 2.0, 2.0, 100.0)
    samples, rate = decimate(original, 100.0, 1)

    assert rate == 100.0
    assert samples == original
