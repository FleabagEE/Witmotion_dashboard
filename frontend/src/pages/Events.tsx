import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, type EventRow } from '../lib/api'
import { Empty, Panel, Pill, SeverityBadge } from '../components/ui'

/**
 * What happened, and when.
 *
 * Two records interleaved: what the structure did, and what people did to the
 * appliance. They are shown together because the interesting answer is often
 * that somebody changed a threshold an hour before the alarms stopped, and that
 * is invisible if the two are read separately.
 */

const WINDOWS = [
  { label: '7 days', days: 7 },
  { label: '30 days', days: 30 },
  { label: '90 days', days: 90 },
  { label: '1 year', days: 365 },
]

const KINDS = [
  { label: 'Everything', kind: 'all' },
  { label: 'Alarms', kind: 'alarms' },
  { label: 'Changes', kind: 'audit' },
] as const

function Values({ before, after }: { before: unknown; after: unknown }) {
  const b = before as Record<string, unknown> | null
  const a = after as Record<string, unknown> | null
  if (!b && !a) return null

  // Only the keys that actually moved. A threshold edit writes the whole
  // definition on both sides, and showing all of it buries the one number that
  // changed among twenty that did not.
  const keys = Array.from(new Set([...Object.keys(b ?? {}), ...Object.keys(a ?? {})]))
    .filter((k) => JSON.stringify(b?.[k]) !== JSON.stringify(a?.[k]))
    .filter((k) => k !== 'reason')

  const reason = (a?.reason ?? null) as string | null

  return (
    <div className="mt-2 space-y-1">
      {keys.map((k) => (
        <div key={k} className="flex flex-wrap items-baseline gap-2 text-[11px]">
          <span className="text-ink-dim">{k}</span>
          <span className="tnum text-ink-dim line-through">{String(b?.[k] ?? '—')}</span>
          <span className="text-ink-dim">→</span>
          <span className="tnum text-ink">{String(a?.[k] ?? '—')}</span>
        </div>
      ))}
      {reason && (
        <div className="text-[11px] italic text-ink-dim">“{reason}”</div>
      )}
    </div>
  )
}

function Row({ event }: { event: EventRow }) {
  const when = new Date(event.at)

  return (
    <li className="rounded border border-line bg-panel-2 px-3 py-2">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          {event.kind === 'alarm' ? (
            <SeverityBadge level={event.level ?? 'normal'} muted={event.state !== 'active'} />
          ) : (
            <Pill tone="muted">change</Pill>
          )}
          <span className="text-sm">{event.title}</span>
          {event.sensor && <span className="text-xs text-ink-dim">{event.sensor}</span>}
          {event.provisional && <Pill tone="warn">not sent</Pill>}
        </div>
        <span className="tnum text-[11px] text-ink-dim">
          {when.toLocaleString()}
        </span>
      </div>

      {event.kind === 'alarm' && event.value !== null && (
        <div className="mt-1 text-[11px] text-ink-dim">
          peak <span className="tnum text-ink">{event.value?.toFixed(3)}</span> {event.unit}
          {event.threshold !== null && <> against a limit of {event.threshold?.toFixed(3)}</>}
          {event.acknowledged_at && <> · acknowledged {new Date(event.acknowledged_at).toLocaleString()}</>}
          {!event.acknowledged_at && event.cleared_at && <> · cleared without acknowledgement</>}
        </div>
      )}

      {event.kind === 'audit' && (
        <>
          <div className="mt-1 text-[11px] text-ink-dim">
            {event.action} · by {event.actor}
            {event.result && event.result !== 'success' && ` · ${event.result}`}
          </div>
          <Values before={event.before} after={event.after} />
        </>
      )}
    </li>
  )
}

export function Events() {
  const [days, setDays] = useState(30)
  const [kind, setKind] = useState<'all' | 'alarms' | 'audit'>('all')

  const events = useQuery({
    queryKey: ['events', days, kind],
    queryFn: () => api.events(days, kind),
    refetchInterval: 30_000,
  })

  const button = (active: boolean) =>
    `rounded px-2 py-1 text-xs ${active ? 'bg-panel-2 text-ink' : 'text-ink-dim hover:text-ink'}`

  return (
    <Panel
      title="Event history"
      subtitle="What the structure did, and what people did to the appliance."
      actions={
        <div className="flex flex-wrap gap-3">
          <div className="flex gap-1">
            {KINDS.map((k) => (
              <button key={k.kind} onClick={() => setKind(k.kind)} className={button(kind === k.kind)}>
                {k.label}
              </button>
            ))}
          </div>
          <div className="flex gap-1">
            {WINDOWS.map((w) => (
              <button key={w.days} onClick={() => setDays(w.days)} className={button(days === w.days)}>
                {w.label}
              </button>
            ))}
          </div>
        </div>
      }
    >
      {/* Said rather than silently omitted. A list missing half of what happened
          would let somebody conclude nothing did. */}
      {events.data && !events.data.audit_visible && (
        <p className="mb-3 rounded border border-line px-3 py-2 text-xs text-ink-dim">
          Configuration changes and sign-ins are also recorded. Your role cannot
          read them; an auditor or administrator can.
        </p>
      )}

      {events.isLoading ? (
        <Empty>Loading…</Empty>
      ) : !events.data?.data.length ? (
        <Empty>Nothing recorded in this window.</Empty>
      ) : (
        <ul className="space-y-2">
          {events.data.data.map((e, i) => (
            <Row key={`${e.kind}-${e.at}-${i}`} event={e} />
          ))}
        </ul>
      )}
    </Panel>
  )
}
