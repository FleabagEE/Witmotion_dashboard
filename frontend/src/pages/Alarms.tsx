import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type CurrentUser } from '../lib/api'
import { Empty, Panel, Pill, SeverityBadge } from '../components/ui'

export function Alarms({ user }: { user: CurrentUser }) {
  const queryClient = useQueryClient()
  const [unackOnly, setUnackOnly] = useState(false)
  const [note, setNote] = useState<Record<number, string>>({})

  const alarms = useQuery({
    queryKey: ['alarms', unackOnly],
    queryFn: () => api.alarms(unackOnly),
    refetchInterval: 5000,
  })

  const acknowledge = useMutation({
    mutationFn: ({ id, text }: { id: number; text: string }) => api.acknowledge(id, text),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['alarms'] }),
  })

  const canAcknowledge = user.abilities.includes('acknowledge')

  return (
    <Panel
      title="Alarm centre"
      subtitle={canAcknowledge ? undefined : `Your role (${user.role}) can view alarms but not acknowledge them.`}
      actions={
        <label className="flex items-center gap-2 text-xs text-ink-dim">
          <input type="checkbox" checked={unackOnly} onChange={(e) => setUnackOnly(e.target.checked)} />
          Unacknowledged only
        </label>
      }
    >
      {alarms.isLoading ? (
        <Empty>Loading…</Empty>
      ) : !alarms.data?.data.length ? (
        <Empty>No alarms. </Empty>
      ) : (
        <ul className="space-y-3">
          {alarms.data.data.map((a) => (
            <li key={a.id} className="rounded border border-line bg-panel-2 p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <SeverityBadge level={a.level} muted={a.state !== 'active'} />
                  <span className="text-sm font-medium">{a.name ?? 'Alarm'}</span>
                  <span className="text-xs text-ink-dim">{a.channel_key}</span>
                  {a.state === 'cleared' && <Pill tone="muted">cleared</Pill>}
                  {/* Distinct from cleared on purpose. Cleared means the
                      measurement came back within limits; retired means the
                      check was switched off and nothing ever observed a
                      recovery. Showing them the same way would put a fact in
                      the record that was never measured. */}
                  {a.state === 'retired' && <Pill tone="muted">check disabled</Pill>}
                  {a.provisional && <Pill tone="warn">provisional</Pill>}
                </div>
                {/* The peak, not the latest reading. An alarm latches until it
                    is acknowledged, so by the time anyone looks the current
                    value is usually back to nothing - showing 0.05 against a
                    limit of 3 beside a CRITICAL badge reads as a broken
                    dashboard. What breached the limit is the peak. */}
                <div className="tnum text-sm">
                  {(a.peak_value ?? a.value)?.toFixed(3)}{' '}
                  <span className="text-ink-dim">{a.unit}</span>
                  {a.threshold !== null && (
                    <span className="ml-2 text-xs text-ink-dim">limit {a.threshold?.toFixed(3)}</span>
                  )}
                  {a.peak_value !== null && a.value !== null
                    && a.peak_value !== a.value && (
                    <div className="text-xs font-normal text-ink-dim">
                      peak · now {a.value?.toFixed(3)} {a.unit}
                    </div>
                  )}
                </div>
              </div>

              <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-ink-dim">
                <span>raised {a.raised_at ? new Date(a.raised_at).toLocaleString() : '—'}</span>
                {a.peak_level !== a.level && <span>peaked at {a.peak_level}</span>}
                {a.acknowledged_at && <span>acknowledged {new Date(a.acknowledged_at).toLocaleString()}</span>}
              </div>

              {a.provisional && (
                <p className="mt-2 rounded border border-advisory/40 bg-advisory/10 px-2 py-1.5 text-xs text-advisory">
                  Raised against thresholds nobody has confirmed against the standard text. Shown for information;
                  no notification was sent.
                </p>
              )}

              {canAcknowledge && !a.acknowledged_at && a.state === 'active' && (
                <div className="mt-3 flex gap-2">
                  <input
                    value={note[a.id] ?? ''}
                    onChange={(e) => setNote({ ...note, [a.id]: e.target.value })}
                    placeholder="What did you find?"
                    className="flex-1 rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent"
                  />
                  <button
                    onClick={() => acknowledge.mutate({ id: a.id, text: note[a.id] ?? '' })}
                    disabled={acknowledge.isPending}
                    className="rounded bg-accent px-3 py-1 text-xs font-medium text-shell disabled:opacity-50"
                  >
                    Acknowledge
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}
