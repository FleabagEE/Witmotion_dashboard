<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeliveryHealth;
use App\Services\SensorHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether each sensor can still be believed, and whether its readings are
 * arriving.
 *
 * Deliberately says nothing about the structure. A dead sensor and a perfectly
 * still silo produce the same chart, and separating those two questions is the
 * entire point of this endpoint.
 *
 * `delivery` is a third question, added after a sixteen-hour database outage
 * during which every sensor was healthy, the structure was still, and no
 * reading reached the database. Sensor health alone reports that as silence,
 * which reads as a broken instrument — and sends somebody to a silo to check a
 * cable that is not broken.
 */
class HealthController extends Controller
{
    public function __invoke(
        Request $request,
        SensorHealth $health,
        DeliveryHealth $delivery,
    ): JsonResponse {
        $data = $request->validate([
            'seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
        ]);

        $sensors = $health->all($data['seconds'] ?? 120);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'window_seconds' => $data['seconds'] ?? 120,
            // The worst thing true of any sensor. A wall display needs one word,
            // and it must be the bad one when there is a bad one.
            'status' => $this->worst(array_column($sensors, 'status')),
            'sensors' => $sensors,
            // Not folded into `status`. A backlog is not a sick sensor, and
            // collapsing the two would let a patient, correctly-working spool
            // turn every instrument on the page amber.
            'delivery' => $delivery->current(),
        ]);
    }

    /** @param list<string> $states */
    private function worst(array $states): string
    {
        foreach (['fail', 'warn', 'unknown'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return $states === [] ? 'unknown' : 'pass';
    }
}
