import type { ReactNode } from 'react'
import type { Severity } from '../lib/api'

const SEVERITY_STYLE: Record<Severity | 'unknown', { dot: string; text: string; ring: string }> = {
  normal: { dot: 'bg-ok', text: 'text-ok', ring: 'ring-ok/30' },
  advisory: { dot: 'bg-advisory', text: 'text-advisory', ring: 'ring-advisory/30' },
  warning: { dot: 'bg-warning', text: 'text-warning', ring: 'ring-warning/30' },
  critical: { dot: 'bg-critical', text: 'text-critical', ring: 'ring-critical/30' },
  unknown: { dot: 'bg-unknown', text: 'text-unknown', ring: 'ring-unknown/30' },
}

export function severityStyle(level: string) {
  return SEVERITY_STYLE[(level as Severity) ?? 'unknown'] ?? SEVERITY_STYLE.unknown
}

export function Panel({
  title,
  subtitle,
  actions,
  children,
}: {
  title?: string
  subtitle?: ReactNode
  actions?: ReactNode
  children: ReactNode
}) {
  return (
    <section className="rounded-lg border border-line bg-panel">
      {(title || actions) && (
        <header className="flex items-start justify-between gap-4 border-b border-line px-4 py-3">
          <div>
            {title && <h2 className="text-sm font-semibold tracking-wide text-ink">{title}</h2>}
            {subtitle && <p className="mt-0.5 text-xs text-ink-dim">{subtitle}</p>}
          </div>
          {actions}
        </header>
      )}
      <div className="p-4">{children}</div>
    </section>
  )
}

/**
 * A single headline number. `caveat` is deliberately prominent rather than a
 * tooltip: on a wall display nobody hovers, and a figure whose trustworthiness
 * is in question must say so where it is read.
 */
export function Stat({
  label,
  value,
  unit,
  tone = 'normal',
  caveat,
}: {
  label: string
  value: ReactNode
  unit?: string
  tone?: Severity | 'unknown'
  caveat?: string
}) {
  const style = severityStyle(tone)
  return (
    <div className="rounded-md border border-line bg-panel-2 px-4 py-3">
      <div className="text-xs uppercase tracking-wider text-ink-dim">{label}</div>
      <div className={`tnum mt-1 text-3xl font-semibold ${tone === 'normal' ? 'text-ink' : style.text}`}>
        {value}
        {unit && <span className="ml-1 text-base font-normal text-ink-dim">{unit}</span>}
      </div>
      {caveat && <div className="mt-1 text-xs text-advisory">{caveat}</div>}
    </div>
  )
}

/** Severity is carried by shape and text as well as colour, never colour alone. */
export function SeverityBadge({
  level, children, muted = false,
}: { level: string; children?: ReactNode; muted?: boolean }) {
  const style = severityStyle(level)

  // A closed alarm keeps the severity it reached - that is its history and must
  // not be rewritten - but it should not wear the same solid red as one that is
  // live. Two retired criticals sat at the top of the alarm centre looking like
  // an emergency in progress; only a small grey pill said otherwise.
  return (
    <span
      className={
        `inline-flex items-center gap-1.5 rounded-full bg-panel-2 px-2 py-0.5 text-xs font-medium ring-1 `
        + (muted ? 'text-ink-dim ring-line' : `${style.ring} ${style.text}`)
      }
    >
      <span
        className={`h-1.5 w-1.5 rounded-full ${muted ? 'bg-ink-dim/50' : style.dot}`}
        aria-hidden
      />
      {children ?? level}
    </span>
  )
}

export function Pill({ tone, children }: { tone: 'ok' | 'warn' | 'muted'; children: ReactNode }) {
  const tones = {
    ok: 'text-ok ring-ok/30',
    warn: 'text-advisory ring-advisory/30',
    muted: 'text-ink-dim ring-line',
  }
  return (
    <span className={`rounded px-1.5 py-0.5 text-[11px] ring-1 ${tones[tone]}`}>{children}</span>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="py-8 text-center text-sm text-ink-dim">{children}</p>
}

export function relativeAge(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return 'never'
  if (seconds < 60) return `${Math.round(seconds)}s ago`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`
  return `${Math.round(seconds / 86400)}d ago`
}
