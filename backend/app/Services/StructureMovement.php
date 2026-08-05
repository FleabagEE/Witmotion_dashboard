<?php

namespace App\Services;

use App\Models\Sensor;

/**
 * What the structure did, as distinct from what the site did to it.
 *
 * A single sensor on a silo answers one question: has this reading changed. It
 * cannot answer the question anybody actually has, because a lorry passing, a
 * piling rig two fields away, a seismic event and the silo settling all produce
 * the same thing — a reading that changed.
 *
 * Three sensors, one of them at ground level, separate them:
 *
 *     site disturbance    top 0.30   mid 0.30   ground 0.30   -> silo still
 *     foundation settling top 0.25   mid 0.25   ground 0.00   -> silo leaning
 *
 * Identical on the upper two. Only the ground row tells them apart, and without
 * it the first is indistinguishable from the second — which means either alarms
 * nobody believes, or thresholds raised until the instrument is deaf.
 *
 * WHAT EACH NUMBER MEANS
 * ----------------------
 *
 * `site` is what the reference saw. It is not noise to be subtracted and
 * forgotten: a site that shakes hard enough is its own finding, and a ground
 * reading that grows over months is the ground moving.
 *
 * `structure` is each upper sensor minus the reference — movement the site does
 * not account for.
 *
 * `bending` is top minus mid, after both have had the site removed. A rigid
 * body rotation tips every sensor by the same angle whatever its height; only
 * bending tips the top more than the middle. This is the number that
 * distinguishes a silo leaning on its foundation from a silo whose shell is
 * flexing, and they are different problems with different remedies.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 *
 * The sensor reports unsigned magnitudes, so none of these carry direction. Two
 * sensors leaning opposite ways look like two sensors leaning the same way. An
 * S-curve therefore reads as ordinary bending. See docs/known-limitations.md.
 *
 * And subtraction assumes the reference is genuinely still. A ground sensor on
 * ground that is itself settling removes real movement from the answer, which
 * is why its own absolute reading is reported rather than only its difference.
 */
class StructureMovement
{
    public function __construct(private readonly TiltMonitor $tilt)
    {
    }

    /**
     * Movement of the structure, with the site's contribution separated out.
     *
     * @return array<string, mixed>
     */
    public function analyse(int $minutes = 60): array
    {
        $sensors = Sensor::where('status', 'active')->get();

        $byPosition = [];

        foreach ($sensors as $sensor) {
            $mounting = ($sensor->metadata ?? [])['mounting'] ?? [];
            $position = $mounting['position'] ?? null;

            if ($position === null) {
                continue;
            }

            $baseline = ($sensor->metadata ?? [])['tilt_baseline'] ?? null;

            $byPosition[$position] = [
                'sensor_id' => $sensor->sensor_id,
                'role' => $mounting['role'] ?? 'monitor',
                'baseline' => $baseline,
                'deviation' => $baseline
                    ? $this->tilt->deviation($sensor->sensor_id, $baseline, $minutes)
                    : null,
            ];
        }

        $reference = $byPosition['ground'] ?? null;

        // Every reading here is a movement from that sensor's own commissioning
        // baseline. Comparing sensors without baselines would compare their
        // mountings rather than their movement.
        $missing = array_keys(array_filter(
            $byPosition,
            fn ($s) => ! ($s['deviation']['available'] ?? false),
        ));

        if ($missing !== []) {
            return [
                'available' => false,
                'reason' => 'not every sensor has a usable baseline and recent quiet data',
                'missing' => $missing,
                'positions' => $byPosition,
            ];
        }

        $site = $reference ? $reference['deviation']['corrected_deviation'] : null;

        $result = [
            'available' => true,
            'window_minutes' => $minutes,
            // Reported, not merely subtracted. A site that moves is a finding in
            // itself, and a ground reading that grows over months is the ground
            // going somewhere.
            'site' => $site,
            'reference_available' => $reference !== null,
            'positions' => $byPosition,
        ];

        if ($reference === null) {
            // Usable, but every number below is "movement including whatever the
            // site was doing", and the caller must not be allowed to forget it.
            $result['warning'] = 'No ground reference. Site disturbance cannot be '
                .'separated from structural movement.';
        }

        foreach (['top', 'mid'] as $position) {
            if (! isset($byPosition[$position])) {
                continue;
            }

            $total = $byPosition[$position]['deviation']['corrected_deviation'];

            $result['structure'][$position] = $reference === null
                ? $total
                : round($total - $site, 4);
        }

        if (isset($result['structure']['top'], $result['structure']['mid'])) {
            $top = $result['structure']['top'];
            $mid = $result['structure']['mid'];

            $result['bending'] = round($top - $mid, 4);
            $result['interpretation'] = $this->interpret($top, $mid);
        }

        return $result;
    }

    /**
     * What the pattern of movement means, in words.
     *
     * Deliberately conservative about what it will claim. The thresholds here
     * are about telling shapes apart, not about whether a structure is safe -
     * that is an alarm's job, and an engineer's.
     */
    private function interpret(float $top, float $mid): array
    {
        // Below the instrument's resolution there is nothing to interpret.
        // Averaged over an hour it resolves roughly 0.005 degrees; a tenth of
        // that as a floor keeps noise from being narrated as a finding.
        $floor = 0.01;

        if (abs($top) < $floor && abs($mid) < $floor) {
            return [
                'shape' => 'still',
                'summary' => 'Neither upper sensor has moved beyond what the ground did.',
            ];
        }

        $difference = abs($top - $mid);

        if ($difference < $floor) {
            return [
                'shape' => 'rigid',
                'summary' => 'Top and middle moved together. A rigid rotation of the whole '
                    .'structure, which points at the foundation rather than the shell.',
            ];
        }

        if (abs($top) > abs($mid)) {
            return [
                'shape' => 'bending',
                'summary' => 'The top moved more than the middle. The structure is flexing '
                    .'between them rather than leaning as one piece.',
            ];
        }

        // The middle moving more than the top is not what settlement or ordinary
        // bending does. It is worth saying so rather than describing it as
        // bending, because the likeliest explanations are a loose bracket or a
        // sensor that has been disturbed.
        return [
            'shape' => 'unexpected',
            'summary' => 'The middle moved more than the top, which neither settlement nor '
                .'simple bending produces. Check the mid-height mounting before '
                .'treating this as structural.',
        ];
    }
}
