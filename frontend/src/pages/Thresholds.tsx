import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type AlarmDefinitionRow, type CurrentUser } from '../lib/api'
import { Empty, Panel, Pill } from '../components/ui'

/**
 * The numbers an alarm is judged against.
 *
 * Readable by anyone. Editable only with `administer`, and the server enforces
 * that independently - this page hides the controls, which is a courtesy, not a
 * security boundary.
 *
 * Two things are deliberately awkward here. A change cannot be saved without a
 * written reason, and changing any of the numbers visibly strips the engineer's
 * sign-off. Both exist because raising a limit silences an alarm and leaves the
 * dashboard looking healthy, which is indistinguishable from a structure that
 * stopped moving.
 */

function Field({
  label, value, unit, onChange, disabled,
}: {
  label: string
  value: number | null
  unit: string | null
  onChange: (v: string) => void
  disabled: boolean
}) {
  return (
    <label className="block">
      <span className="text-[11px] uppercase tracking-wider text-ink-dim">{label}</span>
      <span className="mt-1 flex items-baseline gap-1">
        <input
          type="number"
          step="any"
          value={value ?? ''}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
          className="tnum w-28 rounded border border-line bg-panel px-2 py-1 text-sm outline-none focus:border-accent disabled:opacity-50"
        />
        <span className="text-xs text-ink-dim">{unit}</span>
      </span>
    </label>
  )
}

function DefinitionCard({ definition, canEdit }: { definition: AlarmDefinitionRow; canEdit: boolean }) {
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [reason, setReason] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [confirmedBy, setConfirmedBy] = useState('')
  const [reference, setReference] = useState('')
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['alarm-definitions'] })
    queryClient.invalidateQueries({ queryKey: ['alarms'] })
  }

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { reason }
      for (const [key, raw] of Object.entries(draft)) {
        if (raw !== '') body[key] = Number(raw)
      }
      return api.updateThreshold(definition.id, body as never)
    },
    onSuccess: (result) => {
      setDraft({}); setReason(''); setError(null)
      setNotice(
        result.confirmation_cleared
          ? 'Saved. The previous sign-off no longer applies to these numbers, so this '
            + 'alarm is now shown but not sent until somebody confirms them.'
          : 'Saved.',
      )
      invalidate()
    },
    onError: (e: Error) => { setError(e.message); setNotice(null) },
  })

  const confirm = useMutation({
    mutationFn: () => api.confirmThreshold(definition.id, {
      confirmed_by: confirmedBy, reference,
    }),
    onSuccess: () => {
      setConfirming(false); setConfirmedBy(''); setReference(''); setError(null)
      setNotice('Confirmed. Alarms from this definition can now be sent.')
      invalidate()
    },
    onError: (e: Error) => setError(e.message),
  })

  const edited = Object.values(draft).some((v) => v !== '')
  const value = (key: keyof AlarmDefinitionRow) =>
    draft[key] !== undefined ? (draft[key] === '' ? null : Number(draft[key])) : (definition[key] as number | null)

  return (
    <li className="rounded-lg border border-line bg-panel-2 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{definition.name}</span>
          {definition.sensor_id && (
            <span className="text-xs text-ink-dim">{definition.sensor_id}</span>
          )}
          <span className="text-xs text-ink-dim">{definition.channel_key}</span>
          {!definition.enabled && <Pill tone="muted">disabled</Pill>}
          {definition.actionable
            ? <Pill tone="ok">confirmed</Pill>
            : <Pill tone="warn">shown, not sent</Pill>}
        </div>
      </div>

      {/* The judgement, said in words rather than left to a coloured pill. */}
      {!definition.actionable && (
        <p className="mt-2 max-w-3xl text-xs leading-relaxed text-advisory">
          Nobody has put their name to these numbers, so alarms raised from them
          appear here and are never sent to anyone. That is deliberate: an
          unverified threshold has earned a place on the dashboard and nothing more.
        </p>
      )}
      {definition.actionable && definition.thresholds_confirmed_by && (
        <p className="mt-2 text-xs text-ink-dim">
          Confirmed by {definition.thresholds_confirmed_by}
          {definition.thresholds_reference && <> against {definition.thresholds_reference}</>}
          {definition.thresholds_confirmed_at
            && <> on {new Date(definition.thresholds_confirmed_at).toLocaleDateString()}</>}
        </p>
      )}

      <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Advisory at" value={value('advisory_at')} unit={definition.unit}
          disabled={!canEdit} onChange={(v) => setDraft({ ...draft, advisory_at: v })} />
        <Field label="Warning at" value={value('warning_at')} unit={definition.unit}
          disabled={!canEdit} onChange={(v) => setDraft({ ...draft, warning_at: v })} />
        <Field label="Critical at" value={value('critical_at')} unit={definition.unit}
          disabled={!canEdit} onChange={(v) => setDraft({ ...draft, critical_at: v })} />
        <Field label="Hysteresis" value={value('hysteresis')} unit={definition.unit}
          disabled={!canEdit} onChange={(v) => setDraft({ ...draft, hysteresis: v })} />
      </div>

      <div className="mt-3 flex flex-wrap gap-4 text-[11px] text-ink-dim">
        <span>raise after {definition.persistence_seconds}s</span>
        <span>clear after {definition.clear_seconds}s</span>
        <span>debounce {definition.debounce_seconds}s</span>
        <span>{definition.latching ? 'latching' : 'not latching'}</span>
      </div>

      {canEdit && edited && (
        <div className="mt-3 space-y-2 rounded border border-advisory/40 bg-advisory/5 p-3">
          <p className="text-xs text-advisory">
            Changing a threshold changes when this structure is called unsafe.
            The old and new values are recorded with your name.
          </p>
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Why are you changing this? (required)"
            className="w-full rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent"
          />
          <div className="flex gap-2">
            <button
              onClick={() => save.mutate()}
              disabled={reason.trim().length < 3 || save.isPending}
              className="rounded bg-accent px-3 py-1 text-xs font-medium text-shell disabled:opacity-40"
            >
              Save
            </button>
            <button
              onClick={() => { setDraft({}); setReason('') }}
              className="rounded border border-line px-3 py-1 text-xs"
            >
              Discard
            </button>
          </div>
        </div>
      )}

      {canEdit && !definition.actionable && !confirming && (
        <button
          onClick={() => setConfirming(true)}
          className="mt-3 rounded border border-line px-3 py-1 text-xs hover:text-ink"
        >
          Confirm these numbers
        </button>
      )}

      {canEdit && confirming && (
        <div className="mt-3 space-y-2 rounded border border-line p-3">
          <p className="text-xs text-ink-dim">
            Record who checked these against a real source. This is an assertion
            about the outside world, not a formality — it is what allows the
            appliance to page people.
          </p>
          <input value={confirmedBy} onChange={(e) => setConfirmedBy(e.target.value)}
            placeholder="Who checked them (name)"
            className="w-full rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent" />
          <input value={reference} onChange={(e) => setReference(e.target.value)}
            placeholder="What they checked against (report, standard, section)"
            className="w-full rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent" />
          <div className="flex gap-2">
            <button
              onClick={() => confirm.mutate()}
              disabled={confirmedBy.trim().length < 2 || reference.trim().length < 3 || confirm.isPending}
              className="rounded bg-accent px-3 py-1 text-xs font-medium text-shell disabled:opacity-40"
            >
              Confirm
            </button>
            <button onClick={() => setConfirming(false)} className="rounded border border-line px-3 py-1 text-xs">
              Cancel
            </button>
          </div>
        </div>
      )}

      {notice && <p className="mt-3 text-xs text-ok">{notice}</p>}
      {error && <p className="mt-3 text-xs text-critical">{error}</p>}
    </li>
  )
}

export function Thresholds({ user }: { user: CurrentUser }) {
  const canEdit = user.abilities.includes('administer')
  const definitions = useQuery({
    queryKey: ['alarm-definitions'],
    queryFn: api.alarmDefinitions,
  })

  return (
    <Panel
      title="Alarm thresholds"
      subtitle={
        canEdit
          ? 'Changing these changes when a structure is called unsafe. Every change is recorded.'
          : `Your role (${user.role}) can view thresholds but not change them.`
      }
    >
      {definitions.isLoading ? (
        <Empty>Loading…</Empty>
      ) : !definitions.data?.data.length ? (
        <Empty>No alarm definitions.</Empty>
      ) : (
        <ul className="space-y-3">
          {definitions.data.data.map((d) => (
            <DefinitionCard key={d.id} definition={d} canEdit={canEdit} />
          ))}
        </ul>
      )}
    </Panel>
  )
}
