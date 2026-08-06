import type { DeliveryHealth } from '../lib/api'

/**
 * The sentence somebody needed at half past eight on a Thursday morning.
 *
 * The database had been down overnight. Every sensor was healthy, the structure
 * was still, and nothing had reached the database in sixteen hours. The
 * appliance knew all three of those things and had no way to say any of them —
 * once the dashboard came back it would have shown yesterday's readings with
 * nothing to mark them as yesterday's.
 *
 * WHY THIS IS NOT AN ALARM
 * ------------------------
 *
 * A backlog is not an emergency. It is the spool doing precisely what it exists
 * for, and the readings are on disk. Styling it like a threshold breach would
 * teach an operator to ignore it, and the one time it matters — the forwarder
 * genuinely stopped — is the time they would ignore it too.
 *
 * So the tone follows the state and the words carry the distinction: readings
 * not arriving *and safe* reads differently from readings not arriving *and
 * being lost*. Silent when everything is delivering, because a banner that is
 * always there is furniture.
 */

const TONE = {
  warn: {
    border: 'border-warning/40',
    background: 'bg-warning/5',
    text: 'text-warning',
    heading: 'Readings are behind',
  },
  fail: {
    border: 'border-critical/40',
    background: 'bg-critical/5',
    text: 'text-critical',
    heading: 'Readings are not being delivered',
  },
  unknown: {
    border: 'border-line',
    background: 'bg-panel',
    text: 'text-ink-dim',
    heading: 'Delivery state unknown',
  },
} as const

export function DeliveryBanner({ delivery }: { delivery: DeliveryHealth | undefined }) {
  // Nothing to say when it is working. The absence is the good news.
  if (!delivery || delivery.state === 'pass') return null

  const tone = TONE[delivery.state as keyof typeof TONE] ?? TONE.unknown

  return (
    <div className={`rounded-xl border px-5 py-4 ${tone.border} ${tone.background}`}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className={`text-sm font-semibold ${tone.text}`}>{tone.heading}</h3>
        {delivery.backlog !== null && (
          <span className="tnum text-[11px] text-ink-dim">
            {delivery.backlog.toLocaleString()} waiting
            {delivery.dead_letters
              ? ` · ${delivery.dead_letters.toLocaleString()} parked`
              : ''}
          </span>
        )}
      </div>

      <p className="mt-2 max-w-3xl text-xs leading-relaxed text-ink-dim">
        {delivery.summary}
      </p>

      {delivery.action && (
        <p className="mt-2 text-xs text-ink-dim">
          Release them with <code className="text-ink">{delivery.action}</code>
        </p>
      )}

      {/* Said explicitly rather than left to be inferred from a backlog number.
          The figures on this page are only as fresh as the last delivery, and
          an operator reading a stale movement figure as current is the actual
          harm this banner exists to prevent. */}
      <p className={`mt-2 text-[11px] ${delivery.state === 'fail' ? tone.text : 'text-ink-dim'}`}>
        {delivery.state === 'fail'
          ? 'Every figure on this page is older than it looks.'
          : 'Figures on this page lag by the backlog above until it clears.'}
      </p>
    </div>
  )
}
