<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlarmEvent;
use App\Services\AlarmEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlarmActionController extends Controller
{
    public function acknowledge(Request $request, AlarmEvent $alarm, AlarmEvaluator $evaluator): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        if ($alarm->acknowledged_at !== null) {
            return response()->json([
                'message' => 'already acknowledged',
                'acknowledged_at' => $alarm->acknowledged_at->toIso8601String(),
            ], 409);
        }

        $evaluator->acknowledge($alarm, $request->user()->id, $data['note'] ?? null);

        return response()->json([
            'id' => $alarm->id,
            'acknowledged_at' => $alarm->acknowledged_at->toIso8601String(),
            'acknowledged_by' => $request->user()->name,
        ]);
    }
}
