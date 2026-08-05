import { useMemo, useState } from 'react'
import ReactECharts from 'echarts-for-react'
import type { SeriesPoint } from '../lib/api'

export interface Trace {
  key: string
  label: string
  colour: string
}

/**
 * One physical quantity, plotted against time.
 *
 * The unit is stated on the axis and beside the live value, never left implied:
 * 0.98 means nothing until you know whether it is g, mm/s or micrometres. Traces
 * for the three axes are distinguished by colour and by a legend label, so the
 * card is still readable when colour is not.
 */
export function WaveformCard({
  title,
  unit,
  traces,
  series,
  decimals = 3,
  resolution,
  note,
  error,
  limits,
  offsetRemovable = false,
}: {
  title: string
  unit: string
  traces: Trace[]
  series: Record<string, SeriesPoint[]> | undefined
  decimals?: number
  resolution?: 'raw_bucketed' | 'hourly_rollup'
  note?: string
  /** Request failure, shown in place of the empty-window message. */
  error?: string | null
  /**
   * Alarm thresholds for this quantity, drawn on the chart.
   *
   * A live trace with no limit on it answers "what is it doing" and not "is that
   * a lot", which is the question somebody watching actually has. The lines come
   * from the same definitions the alarm engine judges against, so the chart
   * cannot drift away from what would actually fire.
   */
  limits?: { warning: number | null; critical: number | null; confirmed: boolean } | null
  /**
   * Offer to plot deviation from each trace's mean instead of absolute value.
   *
   * For acceleration this is the difference between a readable chart and an
   * unreadable one. At rest the three axes read roughly 0.95, 0.18 and 0.21 g -
   * that is gravity resolved onto the sensor's tilt, a static bias carrying no
   * vibration information. It forces the axis to span 0.77 g while the actual
   * vibration is under 0.01 g, so the signal ends up occupying about one
   * percent of the plot height and tapping the structure appears to do nothing.
   *
   * Off by default, though. Tilting the sensor IS a change in the static
   * offset, so removing it hides exactly what a tilt test is looking for - and
   * the absolute value in g is what an operator reads first. The vibration view
   * is one click away, and orientation now has its own cards in degrees.
   *
   * The offset itself is reported below the chart, so nothing is discarded
   * silently either way.
   */
  offsetRemovable?: boolean
}) {
  const [removeOffset, setRemoveOffset] = useState(false)
  const latest = useMemo(() => {
    const out: Record<string, number | null> = {}
    traces.forEach((t) => {
      const points = series?.[t.key] ?? []
      for (let i = points.length - 1; i >= 0; i--) {
        if (points[i].v !== null) {
          out[t.key] = points[i].v
          return
        }
      }
      out[t.key] = null
    })
    return out
  }, [series, traces])

  /**
   * The static offset removed from each trace, over the visible window.
   *
   * Computed per window rather than held fixed, so that re-levelling the sensor
   * or switching window does not leave the chart centred on a stale baseline.
   */
  const offsets = useMemo(() => {
    const out: Record<string, number> = {}
    traces.forEach((t) => {
      const values = (series?.[t.key] ?? []).map((p) => p.v).filter((v): v is number => v !== null)
      out[t.key] = values.length ? values.reduce((a, b) => a + b, 0) / values.length : 0
    })
    return out
  }, [series, traces])

  const active = removeOffset && offsetRemovable

  const option = useMemo(
    () => ({
      animation: false,
      grid: { left: 52, right: 12, top: 10, bottom: 22 },
      tooltip: {
        trigger: 'axis',
        backgroundColor: '#131a22',
        borderColor: '#24313d',
        textStyle: { color: '#e6edf3', fontSize: 11 },
        valueFormatter: (v: number) =>
          v == null ? '—' : `${active ? (v >= 0 ? '+' : '') : ''}${v.toFixed(decimals)} ${unit}`,
      },
      xAxis: {
        type: 'time',
        axisLine: { lineStyle: { color: '#24313d' } },
        axisLabel: { color: '#6d7f90', fontSize: 10, hideOverlap: true },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        // Named so the chart cannot be misread as absolute when it is not.
        name: active ? `Δ ${unit}` : unit,
        nameLocation: 'end',
        nameGap: 8,
        nameTextStyle: { color: '#6d7f90', fontSize: 10, align: 'left' },
        scale: true,
        axisLabel: {
          color: '#6d7f90',
          fontSize: 10,
          formatter: (v: number) => (Math.abs(v) >= 1000 ? v.toExponential(1) : String(+v.toFixed(decimals))),
        },
        splitLine: { lineStyle: { color: '#1a232d' } },
      },
      series: traces.map((t, i) => ({
        name: t.label,
        type: 'line',
        showSymbol: false,
        smooth: false,
        lineStyle: { width: 1.5, color: t.colour },
        itemStyle: { color: t.colour },
        data: (series?.[t.key] ?? []).map((p) => [
          p.t,
          p.v === null ? null : active ? p.v - offsets[t.key] : p.v,
        ]),
        // Drawn once, on the first trace, or three identical lines would be
        // stacked on top of each other. Suppressed when the offset is removed:
        // a limit is an absolute value, and against a deviation trace it would
        // be in the wrong place by exactly the offset that was subtracted.
        markLine: i === 0 && limits && !active
          ? {
              silent: true,
              symbol: 'none',
              precision: decimals,
              label: {
                fontSize: 9,
                color: '#8b98a5',
                formatter: (p: { name?: string }) => p.name ?? '',
              },
              data: [
                limits.warning !== null && {
                  yAxis: limits.warning,
                  name: limits.confirmed ? 'warning' : 'warning (unconfirmed)',
                  lineStyle: { color: '#d29922', type: 'dashed', width: 1 },
                },
                limits.critical !== null && {
                  yAxis: limits.critical,
                  name: limits.confirmed ? 'critical' : 'critical (unconfirmed)',
                  lineStyle: { color: '#f85149', type: 'dashed', width: 1 },
                },
              ].filter(Boolean),
            }
          : undefined,
      })),
    }),
    [series, traces, unit, decimals, active, offsets, limits],
  )

  const hasData = traces.some((t) => (series?.[t.key] ?? []).some((p) => p.v !== null))

  return (
    <section className="flex flex-col rounded-xl border border-line bg-panel">
      <header className="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-sm font-semibold tracking-wide">{title}</h2>
          <p className="mt-0.5 text-[11px] text-ink-dim">
            {active ? `Δ ${unit} from static offset` : unit}
            {resolution === 'hourly_rollup' && ' · hourly averages, peaks flattened'}
            {note && ` · ${note}`}
          </p>
          {offsetRemovable && (
            <button
              type="button"
              onClick={() => setRemoveOffset((v) => !v)}
              className="mt-1 rounded border border-line px-1.5 py-0.5 text-[10px] text-ink-dim hover:text-ink"
            >
              {active ? 'show absolute' : 'remove static offset'}
            </button>
          )}
        </div>
        <div className="flex shrink-0 gap-3">
          {traces.map((t) => (
            <div key={t.key} className="text-right">
              <div className="flex items-center justify-end gap-1 text-[10px] uppercase tracking-wider text-ink-dim">
                <span className="h-1.5 w-1.5 rounded-full" style={{ background: t.colour }} aria-hidden />
                {t.label}
              </div>
              <div className="tnum text-sm font-semibold" style={{ color: t.colour }}>
                {latest[t.key] === null || latest[t.key] === undefined
                  ? '—'
                  : latest[t.key]!.toFixed(decimals)}
              </div>
            </div>
          ))}
        </div>
      </header>

      <div className="relative px-1 pb-1 pt-2">
        <ReactECharts option={option} style={{ height: 176 }} notMerge lazyUpdate />
        {(!hasData || error) && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
            <span
              className={`rounded px-3 py-1 text-xs ${
                error ? 'bg-critical/15 text-critical' : 'bg-panel-2/90 text-ink-dim'
              }`}
            >
              {/* A rejected request and an empty window look identical on an
                  empty chart and are not the same problem. */}
              {error ?? 'no data in this window'}
            </span>
          </div>
        )}
      </div>

      {/* What was subtracted, stated plainly. For acceleration this is the
          sensor's tilt against gravity: a change here means the mounting moved,
          which is worth seeing rather than hiding inside a chart transform. */}
      {active && hasData && (
        <p className="border-t border-line px-4 py-1.5 text-[10px] text-ink-dim">
          offset removed —{' '}
          {traces.map((t, i) => (
            <span key={t.key}>
              {i > 0 && ', '}
              {t.label} {offsets[t.key].toFixed(decimals)} {unit}
            </span>
          ))}
        </p>
      )}
    </section>
  )
}
