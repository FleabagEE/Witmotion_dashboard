import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import ReactECharts from 'echarts-for-react'
import { api } from '../lib/api'
import { Empty, Pill } from '../components/ui'
import type { TiltResponse, TiltSensor } from '../lib/api'

/**
 * Settlement monitoring for a structure.
 *
 * The headline number is movement from the commissioning baseline, not tilt.
 * A silo built 0.4 degrees off plumb is fine and stays fine; the same silo
 * drifting to 1.4 over a winter is the thing worth knowing, and a display that
 * led with absolute tilt would bury that change inside a number that was always
 * large.
 *
 * The time axis is days and months, not minutes. Settlement moves too slowly for
 * anything shorter to show it, and a one-minute window would only ever display
 * the instrument's own noise.
 */

const WINDOWS = [
  { label: '24 hours', days: 1 },
  { label: '7 days', days: 7 },
  { label: '30 days', days: 30 },
  { label: '90 days', days: 90 },
  { label: '1 year', days: 365 },
]

function Figure({
  label, value, unit, tone = 'normal', hint,
}: {
  label: string
  value: string
  unit: string
  tone?: 'normal' | 'ok' | 'warn' | 'critical'
  hint?: string
}) {
  const colour = {
    normal: 'text-ink',
    ok: 'text-ok',
    warn: 'text-warning',
    critical: 'text-critical',
  }[tone]

  return (
    <div className="rounded-xl border border-line bg-panel px-5 py-4">
      <div className="text-[11px] uppercase tracking-wider text-ink-dim">{label}</div>
      <div className="mt-1 flex items-baseline gap-2">
        <span className={`tnum text-3xl font-semibold ${colour}`}>{value}</span>
        <span className="text-sm text-ink-dim">{unit}</span>
      </div>
      {hint && <div className="mt-1 text-[11px] leading-snug text-ink-dim">{hint}</div>}
    </div>
  )
}

function NotCommissioned({ sensorId }: { sensorId: string }) {
  return (
    <div className="rounded-xl border border-warning/40 bg-warning/5 p-5">
      <h3 className="text-sm font-semibold text-warning">
        {sensorId} has no baseline — settlement is not being monitored
      </h3>
      <p className="mt-2 max-w-2xl text-xs leading-relaxed text-ink-dim">
        Movement is measured against the structure's orientation at commissioning.
        Without that reference there is nothing to compare against, and adopting
        whatever it reads today would silently define its current lean as correct.
      </p>
      <p className="mt-3 text-xs text-ink-dim">
        Mount the sensor, leave it undisturbed for an hour, then capture the reference:
      </p>
      <pre className="mt-2 overflow-x-auto rounded bg-bg px-3 py-2 text-[11px] text-ink-dim">
php artisan tilt:baseline capture
      </pre>
    </div>
  )
}

function SensorPanel({ sensor }: { sensor: TiltSensor }) {
  const baseline = sensor.baseline
  const deviation = sensor.deviation
  const thermal = sensor.thermal_model
  const points = sensor.series.points

  const movement = deviation?.corrected_deviation ?? null
  const tone = movement === null
    ? 'normal'
    : Math.abs(movement) >= 3 ? 'critical'
    : Math.abs(movement) >= 0.5 ? 'warn'
    : 'ok'

  const option = {
    animation: false,
    grid: { left: 58, right: 58, top: 24, bottom: 30 },
    tooltip: {
      trigger: 'axis',
      backgroundColor: '#131a22',
      borderColor: '#24313d',
      textStyle: { color: '#e6edf3', fontSize: 11 },
    },
    legend: {
      data: ['Movement', 'Temperature'],
      textStyle: { color: '#6d7f90', fontSize: 10 },
      top: 0,
    },
    xAxis: {
      type: 'time',
      axisLine: { lineStyle: { color: '#24313d' } },
      axisLabel: { color: '#6d7f90', fontSize: 10, hideOverlap: true },
      splitLine: { show: false },
    },
    yAxis: [
      {
        type: 'value',
        name: 'deg',
        nameTextStyle: { color: '#a371f7', fontSize: 10, align: 'left' },
        axisLabel: { color: '#6d7f90', fontSize: 10 },
        splitLine: { lineStyle: { color: '#1a232d' } },
        scale: true,
      },
      {
        // Temperature on its own axis, because the whole question on this page
        // is whether the movement follows it. Sharing one axis would make that
        // impossible to see.
        type: 'value',
        name: '°C',
        nameTextStyle: { color: '#f0883e', fontSize: 10, align: 'right' },
        axisLabel: { color: '#6d7f90', fontSize: 10 },
        splitLine: { show: false },
        scale: true,
      },
    ],
    series: [
      {
        name: 'Movement',
        type: 'line',
        showSymbol: false,
        lineStyle: { width: 1.8, color: '#a371f7' },
        data: points.map((p) => [p.t, p.deviation ?? p.tilt]),
        markLine: baseline
          ? {
              silent: true,
              symbol: 'none',
              lineStyle: { color: '#3fb950', type: 'dashed', width: 1 },
              label: { color: '#3fb950', fontSize: 10, formatter: 'baseline' },
              data: [{ yAxis: 0 }],
            }
          : undefined,
      },
      {
        name: 'Temperature',
        type: 'line',
        yAxisIndex: 1,
        showSymbol: false,
        lineStyle: { width: 1, color: '#f0883e', opacity: 0.7 },
        data: points.map((p) => [p.t, p.temperature]),
      },
    ],
  }

  const disturbed = points.filter((p) => p.disturbed).length

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <h2 className="text-base font-semibold">{sensor.sensor_id}</h2>
        {sensor.verification_status && (
          <Pill tone={sensor.verification_status === 'verified' ? 'ok' : 'warn'}>
            {sensor.verification_status}
          </Pill>
        )}
        {!baseline && <Pill tone="warn">no baseline</Pill>}
      </div>

      {!baseline ? (
        <NotCommissioned sensorId={sensor.sensor_id} />
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Figure
              label="Movement from baseline"
              value={movement === null ? '—' : (movement >= 0 ? '+' : '') + movement.toFixed(4)}
              unit="°"
              tone={tone}
              hint={
                deviation?.compensated
                  ? 'temperature removed'
                  : 'uncompensated — some of this may be temperature'
              }
            />
            <Figure
              label="Raw deviation"
              value={
                deviation?.raw_deviation === undefined
                  ? '—'
                  : (deviation.raw_deviation >= 0 ? '+' : '') + deviation.raw_deviation.toFixed(4)
              }
              unit="°"
              hint="before temperature is accounted for"
            />
            <Figure
              label="Explained by temperature"
              value={
                deviation?.thermal_component === undefined
                  ? '—'
                  : (deviation.thermal_component >= 0 ? '+' : '')
                    + deviation.thermal_component.toFixed(4)
              }
              unit="°"
              hint="reported, not hidden"
            />
            <Figure
              label="Current temperature"
              value={deviation?.temperature_now?.toFixed(2) ?? '—'}
              unit="°C"
              hint={`baseline taken at ${baseline.temp?.toFixed(2) ?? '—'} °C`}
            />
          </div>

          <div className="rounded-xl border border-line bg-panel">
            <header className="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-4 py-3">
              <h3 className="text-sm font-semibold">
                Movement and temperature
                <span className="ml-2 text-[11px] font-normal text-ink-dim">
                  {sensor.series.bucket} averages
                </span>
              </h3>
              {disturbed > 0 && (
                <span className="text-[11px] text-warning">
                  {disturbed} interval(s) show the sensor being disturbed
                </span>
              )}
            </header>
            <div className="px-1 pb-1 pt-2">
              <ReactECharts option={option} style={{ height: 300 }} notMerge lazyUpdate />
            </div>
          </div>

          <div className="grid gap-3 lg:grid-cols-2">
            <div className="rounded-xl border border-line bg-panel px-4 py-3 text-xs text-ink-dim">
              <div className="mb-2 text-[11px] uppercase tracking-wider">Baseline</div>
              <div className="space-y-1">
                <div>captured {new Date(baseline.captured_at).toLocaleString()}</div>
                <div>
                  tilt {baseline.tilt?.toFixed(4)}° at {baseline.temp?.toFixed(2)} °C
                </div>
                <div>averaged over {baseline.samples} samples</div>
                {baseline.resolution_deg && (
                  <div>resolution about {baseline.resolution_deg.toFixed(5)}°</div>
                )}
              </div>
            </div>

            <div className="rounded-xl border border-line bg-panel px-4 py-3 text-xs text-ink-dim">
              <div className="mb-2 text-[11px] uppercase tracking-wider">Temperature model</div>
              {!thermal ? (
                <div>Not enough undisturbed data yet.</div>
              ) : (
                <div className="space-y-1">
                  <div>
                    correlation {thermal.correlation >= 0 ? '+' : ''}
                    {thermal.correlation.toFixed(3)} · slope{' '}
                    {thermal.slope >= 0 ? '+' : ''}
                    {thermal.slope.toFixed(5)} °/°C
                  </div>
                  <div>
                    spanned {thermal.temp_range.toFixed(2)} °C, tilt moved{' '}
                    {thermal.tilt_range.toFixed(4)}°
                  </div>
                  {thermal.significant ? (
                    <div className="text-ok">Applied to the movement figure.</div>
                  ) : thermal.disturbed ? (
                    <div className="text-warning">
                      Not applied — tilt moved {thermal.tilt_range.toFixed(2)}° in this window.
                      That is a re-orientation, not drift.
                    </div>
                  ) : (
                    <div className="text-warning">
                      Not applied — temperature only spanned {thermal.temp_range.toFixed(2)} °C.
                      A slope fitted across that cannot be extrapolated.
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </section>
  )
}

export function Tilt() {
  const [windowIndex, setWindowIndex] = useState(1)
  const active = WINDOWS[windowIndex]

  const tilt = useQuery({
    queryKey: ['tilt', active.days],
    queryFn: () => api.tilt(active.days),
    // Settlement moves over weeks. Polling harder would spend the appliance's
    // time re-answering a question whose answer changes daily.
    refetchInterval: 60_000,
  })

  if (tilt.isLoading) return <Empty>Loading…</Empty>

  const data: TiltResponse | undefined = tilt.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-lg font-semibold">Structure movement</h1>
          <span className="text-xs text-ink-dim">
            settlement against the commissioning baseline
          </span>
        </div>
        <div className="flex gap-1">
          {WINDOWS.map((w, i) => (
            <button
              key={w.label}
              onClick={() => setWindowIndex(i)}
              className={`rounded px-2 py-1 text-xs ${
                i === windowIndex ? 'bg-panel-2 text-ink' : 'text-ink-dim hover:text-ink'
              }`}
            >
              {w.label}
            </button>
          ))}
        </div>
      </div>

      {!data?.sensors.length ? (
        <Empty>No sensors registered yet.</Empty>
      ) : (
        data.sensors.map((sensor) => (
          <SensorPanel key={sensor.sensor_id} sensor={sensor} />
        ))
      )}
    </div>
  )
}
