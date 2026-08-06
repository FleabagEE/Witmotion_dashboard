import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api, type HealthState, type SensorHealthRow } from '../lib/api'
import { Empty } from '../components/ui'
import { SiloDiagram } from '../components/SiloDiagram'
import { DeliveryBanner } from '../components/DeliveryBanner'

/**
 * The whole installation on one screen.
 *
 * Three sensors reported separately would leave the reader to do the
 * subtraction that matters. A lorry passing moves all three; the silo settling
 * moves two. The question is never "what did the top sensor read" but "what did
 * the structure do", and that is a comparison, not a reading.
 *
 * So the movement panel leads, and the sensors below it answer a different
 * question: can these instruments still be believed. Those two must not be
 * mixed, because a dead sensor and a still structure produce the same chart.
 */

const TONE: Record<HealthState, { ring: string; text: string; dot: string; label: string }> = {
  pass: { ring: 'ring-ok/30', text: 'text-ok', dot: 'bg-ok', label: 'healthy' },
  warn: { ring: 'ring-warning/40', text: 'text-warning', dot: 'bg-warning', label: 'attention' },
  fail: { ring: 'ring-critical/40', text: 'text-critical', dot: 'bg-critical', label: 'fault' },
  unknown: { ring: 'ring-line', text: 'text-ink-dim', dot: 'bg-ink-dim', label: 'unknown' },
}

const POSITION_ORDER = ['top', 'mid', 'ground'] as const

function Figure({
  label, value, unit, hint, tone,
}: {
  label: string
  value: string
  unit?: string
  hint?: string
  tone?: 'ok' | 'warn' | 'critical'
}) {
  const colour = tone
    ? { ok: 'text-ok', warn: 'text-warning', critical: 'text-critical' }[tone]
    : 'text-ink'

  return (
    <div className="rounded-xl border border-line bg-panel px-5 py-4">
      <div className="text-[11px] uppercase tracking-wider text-ink-dim">{label}</div>
      <div className="mt-1 flex items-baseline gap-1.5">
        <span className={`tnum text-3xl font-semibold ${colour}`}>{value}</span>
        {unit && <span className="text-sm text-ink-dim">{unit}</span>}
      </div>
      {hint && <div className="mt-1 text-[11px] leading-snug text-ink-dim">{hint}</div>}
    </div>
  )
}

function SensorCard({ sensor }: { sensor: SensorHealthRow }) {
  const tone = TONE[sensor.status]
  const failing = Object.entries(sensor.checks).filter(([, c]) => c.state !== 'pass')

  return (
    <div className={`rounded-xl border border-line bg-panel p-4 ring-1 ${tone.ring}`}>
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <span className={`h-2 w-2 rounded-full ${tone.dot}`} aria-hidden />
            <span className="text-sm font-semibold capitalize">{sensor.position ?? 'unplaced'}</span>
            {sensor.role === 'reference' && (
              // Worth saying on the card. Somebody reading three identical
              // panels has no other way to know which one is the yardstick.
              <span className="rounded bg-panel-2 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-ink-dim">
                reference
              </span>
            )}
          </div>
          <div className="mt-0.5 text-xs text-ink-dim">{sensor.sensor_id}</div>
        </div>
        <span className={`text-[11px] font-medium uppercase tracking-wider ${tone.text}`}>
          {tone.label}
        </span>
      </div>

      <div className="mt-3 grid grid-cols-2 gap-3 text-xs">
        <div>
          <div className="text-[10px] uppercase tracking-wider text-ink-dim">Gravity</div>
          <div className="tnum mt-0.5 text-ink">
            {sensor.gravity_magnitude?.toFixed(4) ?? '—'} <span className="text-ink-dim">g</span>
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wider text-ink-dim">Chip</div>
          <div className="tnum mt-0.5 text-ink">
            {sensor.temperature?.toFixed(1) ?? '—'} <span className="text-ink-dim">°C</span>
          </div>
        </div>
      </div>

      {failing.length > 0 && (
        <ul className="mt-3 space-y-1 border-t border-line pt-2">
          {failing.map(([name, check]) => (
            <li key={name} className="text-[11px] leading-snug">
              <span className={TONE[check.state].text}>{name.replace(/_/g, ' ')}</span>
              <span className="text-ink-dim"> — {check.detail}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function Silo() {
  const health = useQuery({
    queryKey: ['sensor-health'],
    queryFn: () => api.sensorHealth(),
    refetchInterval: 15_000,
  })

  const structure = useQuery({
    queryKey: ['structure'],
    queryFn: () => api.structure(),
    // Settlement moves over weeks. Asking harder would re-answer a question
    // whose answer changes daily.
    refetchInterval: 60_000,
  })

  const sensors = [...(health.data?.sensors ?? [])].sort(
    (a, b) =>
      POSITION_ORDER.indexOf(a.position as never) - POSITION_ORDER.indexOf(b.position as never),
  )

  const s = structure.data
  const shape = s?.interpretation?.shape

  const movementTone = (v: number | undefined) =>
    v === undefined ? undefined : Math.abs(v) >= 3 ? 'critical' : Math.abs(v) >= 0.5 ? 'warn' : 'ok'

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-lg font-semibold">Structure</h1>
          <span className="text-xs text-ink-dim">
            three sensors, ground referenced
          </span>
        </div>
        <Link to="/health" className="text-xs text-ink-dim hover:text-ink">
          State of health →
        </Link>
      </div>

      {/* Above the movement figures, because it governs whether they can be
          read as current at all. A stale number presented as live is worse than
          no number. */}
      <DeliveryBanner delivery={health.data?.delivery} />

      {/* Movement first, because it is the question. */}
      {structure.isLoading ? (
        <Empty>Loading…</Empty>
      ) : !s?.available ? (
        <div className="rounded-xl border border-warning/40 bg-warning/5 px-5 py-4">
          <h3 className="text-sm font-semibold text-warning">
            Structural movement cannot be measured yet
          </h3>
          <p className="mt-2 max-w-3xl text-xs leading-relaxed text-ink-dim">
            {s?.reason ?? 'Waiting for data.'}
            {s?.missing?.length ? ` Missing: ${s.missing.join(', ')}.` : ''} Every reading
            here is movement from a sensor's own commissioning baseline. Comparing
            sensors without baselines would compare how they were bolted on rather
            than what they experienced.
          </p>
          <p className="mt-2 text-xs text-ink-dim">
            Mount each sensor, leave it undisturbed for an hour, then capture its
            reference with <code>tilt:baseline capture</code>.
          </p>
        </div>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Figure
              label="Top"
              value={s.structure?.top !== undefined
                ? (s.structure.top >= 0 ? '+' : '') + s.structure.top.toFixed(4) : '—'}
              unit="°"
              tone={movementTone(s.structure?.top)}
              hint="beyond what the site did"
            />
            <Figure
              label="Mid"
              value={s.structure?.mid !== undefined
                ? (s.structure.mid >= 0 ? '+' : '') + s.structure.mid.toFixed(4) : '—'}
              unit="°"
              tone={movementTone(s.structure?.mid)}
              hint="beyond what the site did"
            />
            <Figure
              label="Bending"
              value={s.bending !== undefined
                ? (s.bending >= 0 ? '+' : '') + s.bending.toFixed(4) : '—'}
              unit="°"
              hint="top minus mid. zero is a rigid lean"
            />
            <Figure
              label="Site"
              value={s.site !== null && s.site !== undefined ? s.site.toFixed(4) : '—'}
              unit="°"
              hint="what the ground itself moved"
            />
          </div>

          {s.interpretation && (
            <div
              className={`rounded-xl border px-5 py-4 ${
                shape === 'unexpected'
                  ? 'border-warning/40 bg-warning/5'
                  : 'border-line bg-panel'
              }`}
            >
              <div className="text-[11px] uppercase tracking-wider text-ink-dim">
                Reading of the pattern
              </div>
              <p className="mt-1 max-w-3xl text-sm leading-relaxed">
                {s.interpretation.summary}
              </p>
            </div>
          )}

          {s.warning && (
            <p className="rounded-xl border border-warning/40 bg-warning/5 px-5 py-3 text-xs text-warning">
              {s.warning}
            </p>
          )}
        </>
      )}

      <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        <SiloDiagram
          sensors={sensors}
          topMovement={s?.available ? s.structure?.top : undefined}
          midMovement={s?.available ? s.structure?.mid : undefined}
        />

        <div className="rounded-xl border border-line bg-panel p-4">
          <div className="text-[11px] uppercase tracking-wider text-ink-dim">
            What this installation can and cannot tell you
          </div>
          <ul className="mt-2 space-y-2 text-xs leading-relaxed text-ink-dim">
            <li>
              <span className="text-ink">Can:</span> how far the structure has moved,
              and whether it moved as one piece or bent between the two heights.
            </li>
            <li>
              <span className="text-ink">Can:</span> separate a passing lorry or a
              distant blast from the silo settling, because the ground sensor sees
              the first and not the second.
            </li>
            <li>
              {/* Said here rather than buried in a document, because a single
                  movement figure invites the question. */}
              <span className="text-warning">Cannot:</span> say which way it leaned.
              These sensors report the size of each acceleration component and not
              its sign, so a lean north and a lean south read identically.
            </li>
            <li>
              <span className="text-warning">Cannot:</span> tell solar bowing from
              settlement on a sunny day. Concrete warmed on one side really does
              lean, and that is the structure moving rather than an instrument
              error.
            </li>
          </ul>
        </div>
      </div>

      {/* Then the instruments, as a separate question. */}
      <section aria-label="Sensors">
        <div className="mb-2 flex items-baseline justify-between">
          <h2 className="text-sm font-semibold">Sensors</h2>
          <span className="text-[11px] text-ink-dim">
            can these readings be believed
          </span>
        </div>

        {health.isLoading ? (
          <Empty>Loading…</Empty>
        ) : sensors.length === 0 ? (
          <Empty>No sensors registered.</Empty>
        ) : (
          <div className="grid gap-3 lg:grid-cols-3">
            {sensors.map((sensor) => (
              <SensorCard key={sensor.sensor_id} sensor={sensor} />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
