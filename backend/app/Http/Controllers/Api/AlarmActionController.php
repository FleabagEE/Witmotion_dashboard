<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlarmEvent;
use App\Services\AlarmEvaluator;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlarmActionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

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

        $this->audit->record(
            action: 'alarm.acknowledged',
            subjectType: 'alarm_event',
            subjectId: (string) $alarm->id,
            summary: sprintf(
                '%s alarm on %s acknowledged at %s %s',
                $alarm->level, $alarm->channel_key, $alarm->trigger_value, $alarm->unit,
            ),
            after: ['note' => $data['note'] ?? null, 'level' => $alarm->level],
            request: $request,
        );

        return response()->json([
            'id' => $alarm->id,
            'acknowledged_at' => $alarm->acknowledged_at->toIso8601String(),
            'acknowledged_by' => $request->user()->name,
        ]);
    }
}
