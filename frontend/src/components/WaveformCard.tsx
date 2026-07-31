import { useMemo } from 'react'
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
}: {
  title: string
  unit: string
  traces: Trace[]
  series: Record<string, SeriesPoint[]> | undefined
  decimals?: number
  resolution?: 'raw_bucketed' | 'hourly_rollup'
  note?: string
}) {
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

  const option = useMemo(
    () => ({
      animation: false,
      grid: { left: 52, right: 12, top: 10, bottom: 22 },
      tooltip: {
        trigger: 'axis',
        backgroundColor: '#131a22',
        borderColor: '#24313d',
        textStyle: { color: '#e6edf3', fontSize: 11 },
        valueFormatter: (v: number) => (v == null ? '—' : `${v.toFixed(decimals)} ${unit}`),
      },
      xAxis: {
        type: 'time',
        axisLine: { lineStyle: { color: '#24313d' } },
        axisLabel: { color: '#6d7f90', fontSize: 10, hideOverlap: true },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        name: unit,
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
      series: traces.map((t) => ({
        name: t.label,
        type: 'line',
        showSymbol: false,
        smooth: false,
        lineStyle: { width: 1.5, color: t.colour },
        itemStyle: { color: t.colour },
        data: (series?.[t.key] ?? []).map((p) => [p.t, p.v]),
      })),
    }),
    [series, traces, unit, decimals],
  )

  const hasData = traces.some((t) => (series?.[t.key] ?? []).some((p) => p.v !== null))

  return (
    <section className="flex flex-col rounded-xl border border-line bg-panel">
      <header className="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
        <div>
          <h2 className="text-sm font-semibold tracking-wide">{title}</h2>
          <p className="mt-0.5 text-[11px] text-ink-dim">
            {unit}
            {resolution === 'hourly_rollup' && ' · hourly averages, peaks flattened'}
            {note && ` · ${note}`}
          </p>
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
        {!hasData && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
            <span className="rounded bg-panel-2/90 px-3 py-1 text-xs text-ink-dim">
              no data in this window
            </span>
          </div>
        )}
      </div>
    </section>
  )
}
