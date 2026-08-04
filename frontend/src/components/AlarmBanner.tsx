import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api, type AlarmRow } from '../lib/api'

/**
 * Active alarms, on every page.
 *
 * An alarm that only exists on the Alarms tab is an alarm nobody sees, because
 * nobody watching a live chart thinks to go and look. This sits in the shell
 * above every page.
 *
 * It is deliberately not a toast. A notification that fades assumes somebody
 * was in front of the screen at the moment it appeared, which on an appliance in
 * a plant room is exactly the assumption that does not hold. This stays until
 * the alarm is acknowledged.
 *
 * It also says when an alarm reached nobody. A raised alarm that was suppressed
 * for unconfirmed thresholds looks identical, on a dashboard, to one that paged
 * the duty engineer twenty minutes ago - and those are very different situations
 * for whoever is reading it.
 */

function highest(alarms: AlarmRow[]): 'critical' | 'warning' | 'advisory' {
  if (alarms.some((a) => a.level === 'critical')) return 'critical'
  if (alarms.some((a) => a.level === 'warning')) return 'warning'
  return 'advisory'
}

export function AlarmBanner() {
  const alarms = useQuery({
    queryKey: ['alarms', false],
    queryFn: () => api.alarms(false),
    // Faster than the pages it sits above: this is the thing somebody needs to
    // learn about without having navigated anywhere.
    refetchInterval: 10_000,
  })

  const active = (alarms.data?.data ?? []).filter(
    (a) => a.state === 'active' && a.level !== 'normal',
  )

  if (active.length === 0) return null

  const level = highest(active)
  const unsent = active.filter((a) => a.provisional).length
  const unacknowledged = active.filter((a) => !a.acknowledged_at).length

  const tone = {
    critical: 'border-critical/50 bg-critical/10 text-critical',
    warning: 'border-warning/50 bg-warning/10 text-warning',
    advisory: 'border-advisory/50 bg-advisory/10 text-advisory',
  }[level]

  const worst = active.find((a) => a.level === level)

  return (
    <Link
      to="/alarms"
      className={`block border-b px-4 py-2 transition-opacity hover:opacity-90 ${tone}`}
    >
      <div className="mx-auto flex max-w-[1800px] flex-wrap items-center gap-x-3 gap-y-1">
        <span className="flex h-2 w-2 shrink-0 rounded-full bg-current" aria-hidden />
        <span className="text-sm font-semibold uppercase tracking-wide">{level}</span>

        <span className="text-sm">
          {active.length === 1
            ? worst?.name ?? 'Alarm active'
            : `${active.length} active alarms`}
          {worst?.channel_key && active.length === 1 && (
            <span className="ml-2 opacity-70">{worst.channel_key}</span>
          )}
        </span>

        {worst && active.length === 1 && worst.peak_value !== null && (
          <span className="tnum text-sm opacity-90">
            peak {worst.peak_value?.toFixed(3)} {worst.unit}
            {worst.threshold !== null && (
              <span className="opacity-70"> · limit {worst.threshold?.toFixed(3)}</span>
            )}
          </span>
        )}

        <span className="ml-auto flex flex-wrap items-center gap-3 text-xs">
          {unsent > 0 && (
            // The distinction that matters most when reading this at 3 a.m.
            <span className="rounded bg-current/15 px-2 py-0.5">
              {unsent === active.length ? 'nobody was notified' : `${unsent} not sent`}
            </span>
          )}
          {unacknowledged > 0 && <span className="opacity-80">{unacknowledged} unacknowledged</span>}
          <span className="opacity-70">Open →</span>
        </span>
      </div>
    </Link>
  )
}
