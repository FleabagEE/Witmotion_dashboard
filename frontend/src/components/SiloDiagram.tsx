import type { HealthState, SensorHealthRow } from '../lib/api'

/**
 * The installation, drawn.
 *
 * Two silos joined at mid-height by a concrete connection, with a sensor at the
 * top, at the joined level, and on the ground.
 *
 * A DIAGRAM THAT CAN LIE IS WORSE THAN NO DIAGRAM
 * -----------------------------------------------
 *
 * The temptation with a picture like this is to draw the structure and colour
 * the dots. That produces something reassuring that is not connected to
 * anything: three green circles on a nice illustration, still green after a
 * sensor has been unplugged for a week.
 *
 * So every mark here is driven by live state. A sensor with no data is grey and
 * says so; a sensor reporting a fault is red. The lean of the silo is drawn from
 * the measured movement rather than chosen for looks, and when there is no
 * movement to draw it stands upright and the caption says the reading is not
 * available.
 *
 * Scale is deliberately not honest. A 0.3 degree lean on a 30 ft silo is
 * invisible at any size that fits on a screen - about one pixel - so the tilt is
 * exaggerated to be legible. The caption carries the real number, and the
 * exaggeration is stated rather than left for somebody to infer from a drawing
 * that looks alarming.
 */

const TONE: Record<HealthState, { fill: string; stroke: string; label: string }> = {
  pass: { fill: 'var(--color-ok)', stroke: 'var(--color-ok)', label: 'healthy' },
  warn: { fill: 'var(--color-warning)', stroke: 'var(--color-warning)', label: 'attention' },
  fail: { fill: 'var(--color-critical)', stroke: 'var(--color-critical)', label: 'fault' },
  unknown: { fill: 'var(--color-unknown)', stroke: 'var(--color-unknown)', label: 'no data' },
}

/** Degrees of drawn lean per degree of real movement. */
const EXAGGERATION = 12

/** Beyond this the drawing stops growing, so a bench test does not look like a collapse. */
const MAX_DRAWN_DEGREES = 8

export function SiloDiagram({
  sensors, topMovement, midMovement,
}: {
  sensors: SensorHealthRow[]
  /** Degrees the top has moved beyond the site, when known. */
  topMovement?: number
  midMovement?: number
}) {
  const at = (position: string) => sensors.find((s) => s.position === position)

  const top = at('top')
  const mid = at('mid')
  const ground = at('ground')

  const drawnLean = (value: number | undefined) => {
    if (value === undefined || !Number.isFinite(value)) return 0
    const scaled = value * EXAGGERATION
    return Math.max(-MAX_DRAWN_DEGREES, Math.min(MAX_DRAWN_DEGREES, scaled))
  }

  const upperLean = drawnLean(topMovement)
  const lowerLean = drawnLean(midMovement)
  const measured = topMovement !== undefined || midMovement !== undefined

  const dot = (sensor: SensorHealthRow | undefined) =>
    TONE[sensor?.status ?? 'unknown']

  return (
    <figure className="rounded-xl border border-line bg-panel p-4">
      <figcaption className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-sm font-semibold">Installation</span>
        <span className="text-[11px] text-ink-dim">
          {measured
            ? `lean drawn ${EXAGGERATION}× actual size, to be visible`
            : 'no movement reading — drawn upright'}
        </span>
      </figcaption>

      <svg viewBox="0 0 320 240" className="w-full" role="img"
           aria-label="Two silos joined at mid height with sensors at top, mid and ground level">
        <defs>
          <linearGradient id="shell" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor="#2b3541" />
            <stop offset="45%" stopColor="#3a4655" />
            <stop offset="100%" stopColor="#232c36" />
          </linearGradient>
          <linearGradient id="concrete" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#4a5462" />
            <stop offset="100%" stopColor="#38414d" />
          </linearGradient>
        </defs>

        {/* Ground line and hatching. */}
        <line x1="10" y1="205" x2="310" y2="205" stroke="var(--color-line)" strokeWidth="1.5" />
        {Array.from({ length: 16 }, (_, i) => (
          <line key={i} x1={12 + i * 19} y1="205" x2={4 + i * 19} y2="214"
                stroke="var(--color-line)" strokeWidth="1" opacity="0.5" />
        ))}

        {/*
          The pair leans as one about a hinge at the base. The upper half takes
          the top sensor's lean and the lower half the mid sensor's, so a
          difference between them is visible as a bend rather than averaged away.
        */}
        <g transform={`rotate(${lowerLean} 160 205)`}>
          {/* Lower halves of both silos, plus the concrete connection. */}
          <rect x="52" y="120" width="72" height="85" fill="url(#shell)"
                stroke="var(--color-line)" />
          <rect x="196" y="120" width="72" height="85" fill="url(#shell)"
                stroke="var(--color-line)" />
          <rect x="124" y="138" width="72" height="42" fill="url(#concrete)"
                stroke="var(--color-line)" />
          <text x="160" y="163" textAnchor="middle" fontSize="7"
                fill="var(--color-ink-dim)" letterSpacing="0.5">CONCRETE</text>

          <g transform={`rotate(${upperLean - lowerLean} 160 138)`}>
            {/* Upper halves and domed tops. */}
            <rect x="52" y="52" width="72" height="86" fill="url(#shell)"
                  stroke="var(--color-line)" />
            <rect x="196" y="52" width="72" height="86" fill="url(#shell)"
                  stroke="var(--color-line)" />
            <path d="M52 52 Q88 30 124 52 Z" fill="url(#concrete)" stroke="var(--color-line)" />
            <path d="M196 52 Q232 30 268 52 Z" fill="url(#concrete)" stroke="var(--color-line)" />

            {/* Top sensor, on the monitored face. */}
            <g>
              <circle cx="124" cy="62" r="7" fill={dot(top).fill} opacity="0.25" />
              <circle cx="124" cy="62" r="4" fill={dot(top).fill}
                      stroke={dot(top).stroke} strokeWidth="1.5" />
              <text x="136" y="60" fontSize="8" fill="var(--color-ink)">TOP</text>
              <text x="136" y="69" fontSize="6.5" fill="var(--color-ink-dim)">
                {top?.sensor_id ?? 'not registered'}
              </text>
            </g>
          </g>

          {/* Mid sensor, at the joined level. Labelled outside the shell to the
              left: the space to its right is the concrete connection. */}
          <g>
            <circle cx="52" cy="150" r="7" fill={dot(mid).fill} opacity="0.25" />
            <circle cx="52" cy="150" r="4" fill={dot(mid).fill}
                    stroke={dot(mid).stroke} strokeWidth="1.5" />
            <text x="42" y="149" fontSize="8" fill="var(--color-ink)" textAnchor="end">MID</text>
            <text x="42" y="158" fontSize="6.5" fill="var(--color-ink-dim)" textAnchor="end">
              {mid?.sensor_id ?? 'not registered'}
            </text>
          </g>
        </g>

        {/* Ground sensor. Outside the rotating group: it is the reference, and
            drawing it leaning with the silo would defeat the entire point. */}
        <g>
          <circle cx="286" cy="200" r="7" fill={dot(ground).fill} opacity="0.25" />
          <circle cx="286" cy="200" r="4" fill={dot(ground).fill}
                  stroke={dot(ground).stroke} strokeWidth="1.5" />
          <text x="276" y="192" fontSize="8" fill="var(--color-ink)" textAnchor="end">
            GROUND <tspan fill="var(--color-ink-dim)">· reference</tspan>
          </text>
          {/* Its identity matters as much as the others'. An unregistered
              reference is the quietest way for this page to become fiction. */}
          <text x="276" y="201" fontSize="6.5" fill="var(--color-ink-dim)" textAnchor="end">
            {ground?.sensor_id ?? 'not registered'}
          </text>
        </g>
      </svg>

      <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 border-t border-line pt-3 text-[11px]">
        {(['top', 'mid', 'ground'] as const).map((position) => {
          const sensor = at(position)
          const tone = dot(sensor)

          return (
            <span key={position} className="flex items-center gap-1.5">
              <span className="inline-block h-2 w-2 rounded-full"
                    style={{ background: tone.fill }} aria-hidden />
              <span className="capitalize text-ink">{position}</span>
              <span className="text-ink-dim">{tone.label}</span>
            </span>
          )
        })}
        {measured && (
          <span className="ml-auto text-ink-dim">
            top {topMovement?.toFixed(4) ?? '—'}° · mid {midMovement?.toFixed(4) ?? '—'}°
          </span>
        )}
      </div>
    </figure>
  )
}
