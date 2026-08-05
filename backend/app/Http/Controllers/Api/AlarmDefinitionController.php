<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlarmDefinition;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reading and changing the numbers an alarm is judged against.
 *
 * Reading is open to anyone who can read: an operator who cannot see the
 * threshold cannot judge whether an alarm matters. Changing is restricted to
 * `administer`, which only the administrator role carries — the route enforces
 * it, not this class.
 *
 * WHY A THRESHOLD CHANGE IS NOT AN ORDINARY EDIT
 * ----------------------------------------------
 *
 * Raising a limit silences an alarm, and does it in a way that leaves the
 * dashboard looking healthy. That is indistinguishable from a structure that
 * stopped moving unless the change itself is recorded, so every write here
 * writes an audit entry carrying the old value and the new one, and every
 * change clears the confirmation.
 *
 * Clearing the confirmation is the part people will dislike. A threshold that
 * a named engineer signed off is a different object from the number that
 * replaced it this afternoon, and the appliance must not keep paging people on
 * the authority of a signature that was given for something else. After an
 * edit the definition is provisional again, visible on the dashboard and
 * silent, until somebody confirms the new number.
 */
class AlarmDefinitionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $definitions = AlarmDefinition::query()
            ->with('sensor:id,sensor_id')
            ->orderBy('name')
            ->get()
            ->map(fn (AlarmDefinition $d) => $this->present($d));

        return response()->json(['data' => $definitions]);
    }

    public function update(Request $request, AlarmDefinition $definition): JsonResponse
    {
        $data = $request->validate([
            'advisory_at' => ['nullable', 'numeric'],
            'warning_at' => ['nullable', 'numeric'],
            'critical_at' => ['nullable', 'numeric'],
            'hysteresis' => ['nullable', 'numeric', 'min:0'],
            'persistence_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'clear_seconds' => ['nullable', 'integer', 'min:0', 'max:604800'],
            'debounce_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'latching' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $reason = $data['reason'];
        unset($data['reason']);

        // Ordering matters: a warning above its critical would let a structure
        // pass through warning without ever reaching it.
        $warning = $data['warning_at'] ?? $definition->warning_at;
        $critical = $data['critical_at'] ?? $definition->critical_at;

        if ($warning !== null && $critical !== null && $warning > $critical) {
            return response()->json([
                'message' => 'warning_at must not exceed critical_at',
            ], 422);
        }

        $before = $this->present($definition);
        $wasConfirmed = $definition->thresholds_confirmed_by;

        $definition->fill($data);

        // Only a change to the numbers themselves invalidates a signature.
        // Toggling `enabled`, or widening the debounce, does not change what the
        // engineer put their name to.
        $numbersChanged = $definition->isDirty([
            'advisory_at', 'warning_at', 'critical_at', 'hysteresis',
        ]);

        if ($numbersChanged) {
            $definition->thresholds_confirmed_by = null;
            $definition->thresholds_confirmed_at = null;
            $definition->thresholds_reference = null;
        }

        $definition->source = 'operator';
        $definition->save();

        $this->audit->record(
            action: 'alarm_definition.updated',
            subjectType: 'alarm_definition',
            subjectId: (string) $definition->id,
            summary: sprintf('%s thresholds changed', $definition->name),
            before: $before,
            after: $this->present($definition) + ['reason' => $reason],
            request: $request,
        );

        return response()->json([
            'data' => $this->present($definition),
            // Said plainly in the response, because the dashboard will look
            // calmer after the change and the reason must not be a surprise.
            'confirmation_cleared' => $numbersChanged && $wasConfirmed !== null,
        ]);
    }

    /**
     * Record that a named person checked these numbers against a real source.
     *
     * Separate from `update` on purpose. Confirming is an assertion about the
     * outside world — that somebody opened the standard, or the structural
     * engineer's report, and checked. Letting it ride along with an edit would
     * make it possible to sign off one's own change in the same breath.
     */
    public function confirm(Request $request, AlarmDefinition $definition): JsonResponse
    {
        $data = $request->validate([
            'confirmed_by' => ['required', 'string', 'min:2', 'max:120'],
            'reference' => ['required', 'string', 'min:3', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $definition->update([
            'thresholds_confirmed_by' => $data['confirmed_by'],
            'thresholds_confirmed_at' => now(),
            'thresholds_reference' => $data['reference'],
            'thresholds_note' => $data['note'] ?? null,
        ]);

        $this->audit->record(
            action: 'alarm_definition.confirmed',
            subjectType: 'alarm_definition',
            subjectId: (string) $definition->id,
            summary: sprintf('%s confirmed by %s against %s',
                $definition->name, $data['confirmed_by'], $data['reference']),
            after: $data,
            request: $request,
        );

        return response()->json(['data' => $this->present($definition)]);
    }

    /** @return array<string, mixed> */
    private function present(AlarmDefinition $definition): array
    {
        return [
            'id' => $definition->id,
            'key' => $definition->key,
            'name' => $definition->name,
            'sensor_id' => $definition->sensor?->sensor_id,
            'channel_key' => $definition->channel_key,
            // Set instead of channel_key when one definition covers every axis;
            // the live charts look their limits up by it.
            'quantity' => $definition->quantity,
            'condition_type' => $definition->condition_type,
            'unit' => $definition->unit,
            'advisory_at' => $definition->advisory_at,
            'warning_at' => $definition->warning_at,
            'critical_at' => $definition->critical_at,
            'hysteresis' => $definition->hysteresis,
            'persistence_seconds' => $definition->persistence_seconds,
            'clear_seconds' => $definition->clear_seconds,
            'debounce_seconds' => $definition->debounce_seconds,
            'latching' => $definition->latching,
            'enabled' => $definition->enabled,
            'thresholds_confirmed_by' => $definition->thresholds_confirmed_by,
            'thresholds_confirmed_at' => $definition->thresholds_confirmed_at?->toIso8601String(),
            'thresholds_reference' => $definition->thresholds_reference,
            // The judgement field: false means alarms from this definition are
            // displayed and never sent, whatever channels exist.
            'actionable' => $definition->thresholds_confirmed_by !== null,
        ];
    }
}
