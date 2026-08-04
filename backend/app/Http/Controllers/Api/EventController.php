<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What happened, and when, in one list.
 *
 * Two records exist and neither was visible. `alarm_events` holds what the
 * structure did; `audit_events` holds what people did to the appliance. A
 * client asking "what happened last March" wants both, interleaved, because the
 * interesting answer is often that somebody changed a threshold an hour before
 * an alarm stopped appearing.
 *
 * They are visible to different people. Anyone who can read may see alarms —
 * that is the monitoring record. The audit trail needs `audit`, which the
 * auditor and administrator roles carry, because it names individuals and
 * records their actions.
 */
class EventController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'kind' => ['nullable', 'in:all,alarms,audit'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $days = $data['days'] ?? 30;
        $kind = $data['kind'] ?? 'all';
        $limit = $data['limit'] ?? 200;
        $since = now()->subDays($days);

        $mayAudit = $request->user()?->tokenCan('audit') ?? false;

        $events = [];

        if ($kind !== 'audit') {
            $events = array_merge($events, $this->alarms($since, $limit));
        }

        if ($kind !== 'alarms' && $mayAudit) {
            $events = array_merge($events, $this->audit($since, $limit));
        }

        // Newest first, across both sources. Sorting after the merge rather than
        // in SQL because the two tables have different shapes and a UNION would
        // have to flatten them into something neither one is.
        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'window_days' => $days,
            // Stated rather than silently omitted: an operator looking at this
            // list should know a second record exists that they cannot see.
            'audit_visible' => $mayAudit,
            'data' => array_slice($events, 0, $limit),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function alarms(\DateTimeInterface $since, int $limit): array
    {
        $rows = DB::table('alarm_events as e')
            ->leftJoin('alarm_definitions as d', 'd.id', '=', 'e.alarm_definition_id')
            ->leftJoin('sensors as s', 's.id', '=', 'e.sensor_id')
            ->where('e.raised_at', '>=', $since)
            ->orderByDesc('e.raised_at')
            ->limit($limit)
            ->get([
                'e.id', 'e.raised_at', 'e.cleared_at', 'e.acknowledged_at', 'e.acknowledged_by',
                'e.level', 'e.peak_level', 'e.state', 'e.channel_key', 'e.peak_value',
                'e.threshold', 'e.unit', 'e.provisional', 'd.name as definition',
                's.sensor_id as sensor',
            ]);

        return $rows->map(fn ($r) => [
            'kind' => 'alarm',
            'at' => (string) $r->raised_at,
            'level' => $r->level,
            'peak_level' => $r->peak_level,
            'state' => $r->state,
            'title' => $r->definition ?? 'Alarm',
            'sensor' => $r->sensor,
            'channel_key' => $r->channel_key,
            'value' => $r->peak_value === null ? null : (float) $r->peak_value,
            'threshold' => $r->threshold === null ? null : (float) $r->threshold,
            'unit' => $r->unit,
            'cleared_at' => $r->cleared_at,
            'acknowledged_at' => $r->acknowledged_at,
            // Carried so a reader can tell an alarm that paged somebody from one
            // that only ever appeared on a screen.
            'provisional' => (bool) $r->provisional,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function audit(\DateTimeInterface $since, int $limit): array
    {
        $rows = DB::table('audit_events')
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return collect($rows)->map(fn ($r) => [
            'kind' => 'audit',
            'at' => (string) $r->occurred_at,
            'action' => $r->action,
            'title' => $r->summary ?? $r->action,
            'actor' => $r->actor_name ?? $r->actor_type ?? 'unknown',
            'subject_type' => $r->subject_type,
            'subject_id' => $r->subject_id,
            'result' => $r->result,
            // The old and new values, for the changes where that is the point.
            'before' => $this->decode($r->before ?? null),
            'after' => $this->decode($r->after ?? null),
        ])->all();
    }

    private function decode(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        return json_decode($json, true) ?? $json;
    }
}
