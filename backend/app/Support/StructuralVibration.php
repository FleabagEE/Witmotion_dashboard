<?php

namespace App\Support;

/**
 * Guideline values for vibration effects on buildings.
 *
 * This replaces ISO 10816 for structural monitoring. The two standards answer
 * different questions and are not interchangeable:
 *
 *   ISO 10816    is the machine healthy?      RMS velocity, machine class
 *   DIN 4150-3   will the building be damaged? PEAK velocity (PPV), frequency
 *   BS 7385-2    same question, UK practice    peak component velocity
 *
 * Applying machine-condition limits to a wall would be meaningless, so the
 * standard in force is recorded per asset rather than assumed.
 *
 * ---------------------------------------------------------------------------
 * VERIFICATION STATUS: candidate, NOT verified.
 *
 * The tables below are transcribed from working knowledge of the standards, not
 * from a licensed copy of the standard text. They must be checked against
 * DIN 4150-3 and BS 7385-2 before any of them drives an alarm somebody acts on.
 * That is the same rule the register maps follow (ADR-005), and for the same
 * reason: numbers that look plausible are the dangerous kind of wrong.
 * ---------------------------------------------------------------------------
 *
 * Two further cautions, both about what these numbers mean:
 *
 *  - These are COSMETIC DAMAGE thresholds. Humans perceive vibration far below
 *    them, and occupants routinely complain long before a building is at risk.
 *    Complaint thresholds are a different standard again (BS 6472 / ISO 2631).
 *  - DIN 4150-3 evaluates the PEAK particle velocity of each component, and
 *    requires all three axes. See the notes on this appliance's limitations.
 */
final class StructuralVibration
{
    public const STATUS = 'candidate';

    /**
     * DIN 4150-3 Table 1: short-term (transient) vibration at the foundation.
     *
     * Guideline values in mm/s peak, by frequency band. The limit rises with
     * frequency because a stiff structure tolerates fast, small excursions far
     * better than slow, large ones.
     *
     * Bands: [max_hz, limit_at_low_end, limit_at_high_end] with linear
     * interpolation across each band.
     */
    public const DIN_4150_3_FOUNDATION = [
        // Line 1: commercial, industrial and structurally similar buildings
        'commercial' => [
            'label' => 'Commercial / industrial (DIN 4150-3 line 1)',
            'bands' => [[10.0, 20.0, 20.0], [50.0, 20.0, 40.0], [100.0, 40.0, 50.0]],
        ],
        // Line 2: dwellings and buildings of similar construction
        'residential' => [
            'label' => 'Residential / dwellings (DIN 4150-3 line 2)',
            'bands' => [[10.0, 5.0, 5.0], [50.0, 5.0, 15.0], [100.0, 15.0, 20.0]],
        ],
        // Line 3: structures that are particularly sensitive to vibration, and
        // do not fall under lines 1 or 2 - typically listed or historic
        'sensitive' => [
            'label' => 'Sensitive / historic structures (DIN 4150-3 line 3)',
            'bands' => [[10.0, 3.0, 3.0], [50.0, 3.0, 8.0], [100.0, 8.0, 10.0]],
        ],
    ];

    /**
     * DIN 4150-3 Table 1: horizontal vibration in the plane of the topmost
     * floor, all frequencies. This is the row most commonly used in practice,
     * because the top of a building amplifies ground motion.
     */
    public const DIN_4150_3_TOP_FLOOR = [
        'commercial' => 40.0,
        'residential' => 15.0,
        'sensitive' => 8.0,
    ];

    /**
     * DIN 4150-3 Table 3: long-term / continuous vibration, horizontal at the
     * topmost floor. Much lower than the transient values: a building tolerates
     * a single blast far better than months of traffic or plant vibration.
     */
    public const DIN_4150_3_LONG_TERM_TOP_FLOOR = [
        'commercial' => 10.0,
        'residential' => 5.0,
        'sensitive' => 2.5,
    ];

    /** BS 7385-2: transient vibration guide values for cosmetic damage. */
    public const BS_7385_2 = [
        'reinforced' => [
            'label' => 'Reinforced or framed structures, industrial and heavy commercial',
            'bands' => [[4.0, 50.0, 50.0], [100.0, 50.0, 50.0]],
        ],
        'unreinforced' => [
            'label' => 'Unreinforced or light framed, residential and light commercial',
            'bands' => [[4.0, 15.0, 15.0], [15.0, 15.0, 20.0], [40.0, 20.0, 50.0], [100.0, 50.0, 50.0]],
        ],
    ];

    /**
     * Guideline limit in mm/s for a structure class at a given frequency.
     *
     * Frequency matters: the same velocity is far more damaging at 3 Hz than at
     * 80 Hz, which is why a single number cannot express these standards.
     */
    public static function limitFor(string $standard, string $class, float $frequencyHz, string $position = 'foundation'): ?float
    {
        if ($standard === 'din4150_3' && $position === 'top_floor') {
            return self::DIN_4150_3_TOP_FLOOR[$class] ?? null;
        }
        if ($standard === 'din4150_3_long_term') {
            return self::DIN_4150_3_LONG_TERM_TOP_FLOOR[$class] ?? null;
        }

        $table = match ($standard) {
            'din4150_3' => self::DIN_4150_3_FOUNDATION,
            'bs7385_2' => self::BS_7385_2,
            default => null,
        };
        if ($table === null || ! isset($table[$class])) {
            return null;
        }

        return self::interpolate($table[$class]['bands'], $frequencyHz);
    }

    /** Linear interpolation within the band containing $frequencyHz. */
    private static function interpolate(array $bands, float $frequencyHz): float
    {
        $lowHz = 0.0;
        foreach ($bands as [$highHz, $lowLimit, $highLimit]) {
            if ($frequencyHz <= $highHz) {
                if ($highHz <= $lowHz || $lowLimit === $highLimit) {
                    return $lowLimit;
                }
                $fraction = ($frequencyHz - $lowHz) / ($highHz - $lowHz);

                return $lowLimit + $fraction * ($highLimit - $lowLimit);
            }
            $lowHz = $highHz;
        }

        // Above the tabulated range the standards do not extrapolate; hold the
        // top value rather than inventing one.
        $last = end($bands);

        return $last[2];
    }

    /**
     * Whether a standard / class / position / duration combination exists.
     *
     * Not every combination is defined. DIN 4150-3 tabulates continuous
     * vibration for the topmost floor only, so asking for a long-term limit at
     * the foundation has no answer in the standard - and inventing one would be
     * worse than refusing.
     *
     * @return string|null null if valid, otherwise the reason it is not
     */
    public static function rejectCombination(
        string $standard,
        string $class,
        string $position,
        string $duration,
    ): ?string {
        if (! in_array($standard, ['din4150_3', 'bs7385_2'], true)) {
            return "unknown standard '{$standard}'";
        }
        if (! in_array($class, self::classesFor($standard), true)) {
            return "'{$class}' is not a structure class of ".strtoupper($standard);
        }
        if (! in_array($position, ['foundation', 'top_floor'], true)) {
            return "unknown measurement position '{$position}'";
        }
        if (! in_array($duration, ['transient', 'long_term'], true)) {
            return "unknown duration '{$duration}'";
        }
        if ($duration === 'long_term') {
            if ($standard !== 'din4150_3') {
                return 'only DIN 4150-3 tabulates continuous vibration limits';
            }
            if ($position !== 'top_floor') {
                return 'DIN 4150-3 gives continuous-vibration limits for the topmost floor '
                    .'only; there is no tabulated foundation value';
            }
        }

        return null;
    }

    /** Resolve the table key for a standard, position and duration. */
    public static function tableKey(string $standard, string $position, string $duration): string
    {
        if ($standard === 'din4150_3' && $duration === 'long_term') {
            return 'din4150_3_long_term';
        }

        return $standard;
    }

    public static function classesFor(string $standard): array
    {
        return match ($standard) {
            'din4150_3', 'din4150_3_long_term' => array_keys(self::DIN_4150_3_TOP_FLOOR),
            'bs7385_2' => array_keys(self::BS_7385_2),
            default => [],
        };
    }

    /**
     * Whether this appliance can measure what the standard actually requires.
     *
     * Returns a list of reasons it cannot. An empty list means compliant.
     */
    public static function complianceGaps(bool $allAxesWorking, string $velocityKind): array
    {
        $gaps = [];

        if (! $allAxesWorking) {
            // DIN 4150-3 and BS 7385-2 both evaluate the largest of the three
            // component velocities. Two dead axes make a compliant assessment
            // impossible, not merely less complete.
            $gaps[] = 'The standard evaluates the maximum of three orthogonal components. '
                .'This sensor reports vibration velocity on the X axis only, so a compliant '
                .'assessment cannot be produced.';
        }

        if ($velocityKind !== 'peak') {
            // PPV is a peak, not an average. Converting RMS to peak needs an
            // assumption about waveform shape that transient events violate.
            $gaps[] = 'The standard is defined on peak particle velocity. This sensor reports '
                .'an aggregated velocity whose relationship to peak is not documented, so any '
                .'comparison against a guideline value is approximate at best.';
        }

        return $gaps;
    }
}
