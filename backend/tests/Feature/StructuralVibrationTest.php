<?php

namespace Tests\Feature;

use App\Support\StructuralVibration;
use PHPUnit\Framework\TestCase;

/**
 * Structural guideline values and, just as importantly, what this appliance
 * cannot legitimately claim about them.
 */
class StructuralVibrationTest extends TestCase
{
    public function test_tables_are_marked_unverified(): void
    {
        // Transcribed from working knowledge, not from a licensed copy of the
        // standard. Until somebody checks them against the source they must not
        // be presented as authoritative (ADR-005 applied to standards).
        $this->assertSame('candidate', StructuralVibration::STATUS);
    }

    public function test_din_residential_foundation_limits_rise_with_frequency(): void
    {
        // DIN 4150-3 line 2: 5 mm/s below 10 Hz, rising to 15 at 50 Hz.
        $this->assertSame(5.0, StructuralVibration::limitFor('din4150_3', 'residential', 4.0));
        $this->assertSame(5.0, StructuralVibration::limitFor('din4150_3', 'residential', 10.0));
        $this->assertSame(15.0, StructuralVibration::limitFor('din4150_3', 'residential', 50.0));
        $this->assertSame(20.0, StructuralVibration::limitFor('din4150_3', 'residential', 100.0));
    }

    public function test_interpolation_within_a_band(): void
    {
        // Halfway between 10 Hz (5 mm/s) and 50 Hz (15 mm/s) is 10 mm/s.
        $this->assertEqualsWithDelta(
            10.0, StructuralVibration::limitFor('din4150_3', 'residential', 30.0), 1e-9
        );
    }

    public function test_sensitive_structures_get_the_lowest_limits(): void
    {
        $frequency = 8.0;
        $sensitive = StructuralVibration::limitFor('din4150_3', 'sensitive', $frequency);
        $residential = StructuralVibration::limitFor('din4150_3', 'residential', $frequency);
        $commercial = StructuralVibration::limitFor('din4150_3', 'commercial', $frequency);

        $this->assertLessThan($residential, $sensitive);
        $this->assertLessThan($commercial, $residential);
        $this->assertSame(3.0, $sensitive);
    }

    public function test_top_floor_limits_are_frequency_independent(): void
    {
        foreach ([2.0, 25.0, 90.0] as $frequency) {
            $this->assertSame(
                15.0,
                StructuralVibration::limitFor('din4150_3', 'residential', $frequency, 'top_floor'),
            );
        }
    }

    public function test_long_term_limits_are_far_stricter_than_transient(): void
    {
        // A building tolerates one blast far better than months of traffic.
        $transient = StructuralVibration::limitFor('din4150_3', 'residential', 30.0, 'top_floor');
        $continuous = StructuralVibration::limitFor('din4150_3_long_term', 'residential', 30.0);

        $this->assertSame(15.0, $transient);
        $this->assertSame(5.0, $continuous);
        $this->assertLessThan($transient, $continuous);
    }

    public function test_bs7385_unreinforced_rises_across_its_bands(): void
    {
        $this->assertSame(15.0, StructuralVibration::limitFor('bs7385_2', 'unreinforced', 4.0));
        $this->assertSame(20.0, StructuralVibration::limitFor('bs7385_2', 'unreinforced', 15.0));
        $this->assertSame(50.0, StructuralVibration::limitFor('bs7385_2', 'unreinforced', 40.0));
    }

    public function test_above_the_tabulated_range_the_value_is_held_not_extrapolated(): void
    {
        // The standards stop at 100 Hz. Extending the trend past the table would
        // be inventing a guideline the standard does not give.
        $at100 = StructuralVibration::limitFor('din4150_3', 'residential', 100.0);
        $at300 = StructuralVibration::limitFor('din4150_3', 'residential', 300.0);
        $this->assertSame($at100, $at300);
    }

    public function test_unknown_standard_or_class_returns_null(): void
    {
        $this->assertNull(StructuralVibration::limitFor('iso10816', 'residential', 10.0));
        $this->assertNull(StructuralVibration::limitFor('din4150_3', 'spaceship', 10.0));
    }

    public function test_classes_are_enumerable_per_standard(): void
    {
        $this->assertSame(
            ['commercial', 'residential', 'sensitive'],
            StructuralVibration::classesFor('din4150_3'),
        );
        $this->assertSame(['reinforced', 'unreinforced'], StructuralVibration::classesFor('bs7385_2'));
    }

    public function test_single_axis_sensor_is_reported_as_non_compliant(): void
    {
        $gaps = StructuralVibration::complianceGaps(allAxesWorking: false, velocityKind: 'peak');

        $this->assertNotEmpty($gaps);
        $this->assertStringContainsString('three orthogonal components', $gaps[0]);
    }

    public function test_rms_velocity_is_reported_as_a_gap_against_ppv(): void
    {
        $gaps = StructuralVibration::complianceGaps(allAxesWorking: true, velocityKind: 'aggregate');

        $this->assertCount(1, $gaps);
        $this->assertStringContainsString('peak particle velocity', $gaps[0]);
    }

    public function test_a_compliant_setup_reports_no_gaps(): void
    {
        $this->assertSame([], StructuralVibration::complianceGaps(true, 'peak'));
    }

    public function test_current_hardware_has_both_gaps(): void
    {
        // The WTVB01-485 as it stands: X axis only (confirmed defect), and an
        // aggregate velocity of undocumented relationship to peak.
        $gaps = StructuralVibration::complianceGaps(allAxesWorking: false, velocityKind: 'aggregate');
        $this->assertCount(2, $gaps);
    }
}
