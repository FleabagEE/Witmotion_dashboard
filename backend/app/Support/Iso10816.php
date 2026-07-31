<?php

namespace App\Support;

/**
 * ISO 10816-3 vibration severity zones.
 *
 * The standard grades broadband RMS vibration velocity into four zones:
 *
 *   A  newly commissioned machinery
 *   B  acceptable for unrestricted long-term operation
 *   C  unsatisfactory for long-term operation; suitable only for a limited period
 *   D  severe enough to cause damage
 *
 * The boundaries depend on machine size and mounting, which is why the class
 * lives on the asset rather than on the sensor: moving a sensor from a 5 kW pump
 * to a 200 kW compressor must change its limits without reconfiguring it.
 *
 * Mapped to alarm levels as advisory at A/B, warning at B/C, critical at C/D. A
 * machine in zone B is fine, so advisory here means "no longer as-new", not
 * "something is wrong".
 *
 * These are the standard's boundaries for rigid and flexible mounting; they are
 * defaults for a machine class, not a substitute for a baseline measured on the
 * specific machine. Where a real baseline exists, prefer deviation-from-baseline.
 */
final class Iso10816
{
    /** @var array<string, array{label: string, advisory: float, warning: float, critical: float}> */
    public const CLASSES = [
        'class_i' => [
            'label' => 'Class I - small machines up to 15 kW',
            'advisory' => 0.71, 'warning' => 1.80, 'critical' => 4.50,
        ],
        'class_ii' => [
            'label' => 'Class II - medium machines 15-75 kW, or up to 300 kW on special foundations',
            'advisory' => 1.12, 'warning' => 2.80, 'critical' => 7.10,
        ],
        'class_iii' => [
            'label' => 'Class III - large machines on rigid foundations',
            'advisory' => 1.80, 'warning' => 4.50, 'critical' => 11.2,
        ],
        'class_iv' => [
            'label' => 'Class IV - large machines on soft foundations',
            'advisory' => 2.80, 'warning' => 7.10, 'critical' => 18.0,
        ],
    ];

    public const UNIT = 'mm/s';

    /** Hysteresis as a fraction of the raise threshold. */
    public const HYSTERESIS_FRACTION = 0.10;

    public static function thresholds(?string $class): ?array
    {
        return self::CLASSES[$class] ?? null;
    }

    /**
     * Infer a class from rated power when none was set explicitly.
     *
     * A guess, and labelled as one: mounting stiffness matters as much as power
     * and cannot be inferred from a nameplate. Callers record the source so an
     * operator can see the limits were derived rather than specified.
     */
    public static function classFromPower(?float $ratedPowerKw): ?string
    {
        if ($ratedPowerKw === null || $ratedPowerKw <= 0) {
            return null;
        }
        if ($ratedPowerKw <= 15.0) {
            return 'class_i';
        }
        if ($ratedPowerKw <= 75.0) {
            return 'class_ii';
        }

        // Above 75 kW the split between class III and IV is about foundation
        // stiffness, not size. Assume the more conservative (rigid) limits and
        // let the operator widen them deliberately.
        return 'class_iii';
    }

    public static function zoneFor(?string $class, float $velocityRms): string
    {
        $thresholds = self::thresholds($class);
        if ($thresholds === null) {
            return 'unknown';
        }
        if ($velocityRms >= $thresholds['critical']) {
            return 'D';
        }
        if ($velocityRms >= $thresholds['warning']) {
            return 'C';
        }
        if ($velocityRms >= $thresholds['advisory']) {
            return 'B';
        }

        return 'A';
    }
}
