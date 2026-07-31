<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Append-only record of who did what.
 *
 * Writes go straight through the query builder rather than an Eloquent model:
 * there is no model, so there is no `->update()` or `->delete()` for anybody to
 * reach for, and the database refuses both anyway.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $summary = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
        string $result = 'success',
        ?string $actorTypeOverride = null,
        ?string $actorNameOverride = null,
    ): void {
        $user = $request?->user();

        DB::table('audit_events')->insert([
            'occurred_at' => now(),
            'actor_id' => $user?->id,
            'actor_name' => $actorNameOverride ?? $user?->name ?? 'system',
            'actor_role' => $user?->role,
            'actor_type' => $actorTypeOverride ?? ($user ? 'user' : 'system'),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'summary' => $summary === null ? null : mb_substr($summary, 0, 500),
            'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'result' => $result,
        ]);
    }

    /**
     * A failed attempt is worth more than a successful one.
     *
     * Successful actions leave traces elsewhere in the system; rejected ones
     * often leave none at all, which is exactly when somebody wants to know.
     */
    public function recordFailure(
        string $action,
        string $summary,
        ?Request $request = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): void {
        $this->record(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            summary: $summary,
            request: $request,
            result: 'failure',
        );
    }
}
