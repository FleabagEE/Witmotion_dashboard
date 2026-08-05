<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SensorHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether each sensor can still be believed.
 *
 * Deliberately says nothing about the structure. A dead sensor and a perfectly
 * still silo produce the same chart, and separating those two questions is the
 * entire point of this endpoint.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, SensorHealth $health): JsonResponse
    {
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
