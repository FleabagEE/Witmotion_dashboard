<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StructureMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the structure did, separated from what the site did to it.
 *
 * The response carries the site's own movement alongside the structure's rather
 * than only the difference. A site that shakes is a finding, and a ground
 * reading that grows over months is the ground moving - both would vanish if
 * the reference were treated as noise to subtract and discard.
 */
class StructureController extends Controller
{
    public function __invoke(Request $request, StructureMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            ...$movement->analyse($data['minutes'] ?? 60),
        ]);
    }
}
