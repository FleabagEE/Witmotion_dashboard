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
    <div className="rounded-xl border border-warning/40 bg-warning/5 px-5 py-4">
      <h3 className="text-sm font-semibold text-warning">
        {sensorId} has no baseline — movement is not being measured
      </h3>
      <p className="mt-2 max-w-3xl text-xs leading-relaxed text-ink-dim">
        The readings below are live and correct; what is missing is the reference
        to compare them against. Adopting whatever the sensor reads today would
        silently define its current lean as correct, so the deviation figures stay
        blank until somebody captures the reference deliberately.
      </p>
      <p className="mt-3 text-xs text-ink-dim">
        Mount the sensor, leave it undisturbed for an hour, then:
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

  const withTilt = points.filter((p) => p.tilt !== null)
  const withTemp = points.filter((p) => p.temperature !== null)

  // Prefer the disturbance-filtered figures, so tilt, baseline and movement
  // reconcile. The chart buckets are the fallback for an uncommissioned sensor,
  // where there is no deviation to read them from.
  const latestTilt = deviation?.tilt_now
    ?? (withTilt.length ? withTilt[withTilt.length - 1].tilt : null)
  const latestTemp = deviation?.temperature_now
    ?? (withTemp.length ? withTemp[withTemp.length - 1].temperature : null)

  const movement = deviation?.corrected_deviation ?? null
  const tone = movement === null
    ? 'normal'
    : Math.abs(movement) >= 3 ? 'critical'
    : Math.abs(movement) >= 0.5 ? 'warn'
    : 'ok'

  const disturbed = points.filter((p) => p.disturbed && !p.pre_commissioning).length
  const plotted = points.filter((p) => (baseline ? p.deviation : p.tilt) !== null).length
  const bucketMs = sensor.series.bucket_seconds * 1000

  // Contiguous runs of disturbed buckets, collapsed into bands. One band per
  // bucket would draw hundreds of hairlines on a 90-day view.
  const bands: Array<[number, number, string]> = []
  let run: [number, number, string] | null = null
  for (const p of points) {
    const kind = p.pre_commissioning ? 'pre' : p.disturbed ? 'disturbed' : ''
    if (!kind) { if (run) { bands.push(run); run = null } ; continue }
    if (run && run[2] === kind) run[1] = p.t + bucketMs
    else { if (run) bands.push(run); run = [p.t, p.t + bucketMs, kind] }
  }
  if (run) bands.push(run)

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
      data: [baseline ? 'Movement' : 'Tilt', 'Temperature'],
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
        name: baseline ? 'Movement' : 'Tilt',
        type: 'line',
        // Markers when the series is sparse. A freshly commissioned sensor has
        // one or two points on a seven-day view, and a line joining fewer than
        // two of them draws nothing at all - the chart then looks like it is
        // plotting only temperature, which is how this was noticed.
        showSymbol: plotted <= 30,
        symbolSize: 5,
        lineStyle: { width: 1.8, color: '#a371f7' },
        data: points.map((p) => [p.t, baseline ? p.deviation : p.tilt]),
        // Excluded buckets break the line rather than being bridged across.
        // Connecting them would draw a straight segment through time nobody
        // measured and make it look like data.
        connectNulls: false,
        markArea: {
          silent: true,
          itemStyle: { opacity: 1 },
          data: bands.map(([from, to, kind]) => [
            {
              xAxis: from,
              itemStyle: {
                color: kind === 'pre' ? 'rgba(109,127,144,0.10)' : 'rgba(240,136,62,0.12)',
              },
              label: {
                show: false,
              },
            },
            { xAxis: to },
          ]),
        },
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

      {!baseline && <NotCommissioned sensorId={sensor.sensor_id} />}

      {/* Stated on the page, not buried in a document. A single movement figure
          invites the question "which way", and this instrument cannot answer
          it - so the page says so rather than leaving somebody to assume. */}
      <div className="rounded-xl border border-line bg-panel px-5 py-3">
        <p className="max-w-3xl text-xs leading-relaxed text-ink-dim">
          <span className="font-medium text-ink">Magnitude only.</span>{' '}
          The WTVB01-485 reports the size of each acceleration component and not
          its sign, so this page can say how far the structure has leaned but not
          which way. A lean of 0.3° north and one of 0.3° south read identically.
          Comparing two sensors at different heights still separates foundation
          settlement from bending, because that depends on how much each has
          moved rather than in which direction.
        </p>
      </div>

      {deviation?.method === 'reported_tilt' && (
        <div className="rounded-xl border border-warning/40 bg-warning/5 px-5 py-3">
          <h3 className="text-sm font-semibold text-warning">
            Baseline predates gravity-vector referencing
          </h3>
          <p className="mt-1 max-w-3xl text-xs leading-relaxed text-ink-dim">
            Movement is being measured as a change in reported tilt, which is the
            angle between the sensor's Z axis and gravity. On a unit bolted to a
            vertical wall that measure is blind to rotation about its own Z — a
            structure leaning sideways relative to the sensor would not move this
            number at all. Recapture the baseline to measure the rotation of the
            gravity vector instead, which has no such blind spot.
          </p>
        </div>
      )}

      <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Figure
              label={baseline ? 'Movement from baseline' : 'Movement'}
              value={movement === null ? '—' : (movement >= 0 ? '+' : '') + movement.toFixed(4)}
              unit="°"
              tone={tone}
              hint={
                !baseline
                  ? 'needs a baseline'
                  : deviation?.disturbed_minutes
                    // A figure averaged over the few quiet minutes of a busy hour
                    // is worth less than one averaged over a still one, and the
                    // number alone does not say which it is.
                    ? `${deviation.disturbed_minutes} of ${deviation.window_minutes} min discarded — sensor was handled`
                    : deviation?.compensated
                      ? 'temperature removed'
                      : 'uncompensated — some of this may be temperature'
              }
            />
            <Figure
              label="Current tilt"
              value={latestTilt === null ? '—' : latestTilt.toFixed(4)}
              unit="°"
              hint={
                baseline
                  ? `baseline ${baseline.tilt?.toFixed(4)}° — quiet samples only`
                  : 'from vertical'
              }
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
              value={latestTemp === null ? '—' : latestTemp.toFixed(2)}
              unit="°C"
              hint={baseline ? `baseline taken at ${baseline.temp?.toFixed(2) ?? '—'} °C` : undefined}
            />
          </div>

          <div className="rounded-xl border border-line bg-panel">
            <header className="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-4 py-3">
              <h3 className="text-sm font-semibold">
                {baseline ? 'Movement' : 'Tilt'} and temperature
                <span className="ml-2 text-[11px] font-normal text-ink-dim">
                  {sensor.series.bucket} averages
                </span>
              </h3>
              <span className="flex flex-wrap items-center gap-3 text-[11px]">
                {baseline && (
                  <span className="text-ink-dim">
                    <span className="mr-1 inline-block h-2 w-3 rounded-sm bg-[#6d7f90]/25 align-middle" />
                    before commissioning — movement undefined
                  </span>
                )}
                {disturbed > 0 && (
                  <span className="text-warning">
                    <span className="mr-1 inline-block h-2 w-3 rounded-sm bg-[#f0883e]/30 align-middle" />
                    {disturbed} interval(s) discarded — sensor handled
                  </span>
                )}
                <span className={plotted < 3 ? 'text-warning' : 'text-ink-dim'}>
                  {plotted} point(s) plotted
                  {plotted < 3 && baseline && ' — too few to show a trend yet'}
                </span>
              </span>
            </header>
            <div className="px-1 pb-1 pt-2">
              <ReactECharts option={option} style={{ height: 300 }} notMerge lazyUpdate />
            </div>
          </div>

          <div className="grid gap-3 lg:grid-cols-2">
            <div className="rounded-xl border border-line bg-panel px-4 py-3 text-xs text-ink-dim">
              <div className="mb-2 text-[11px] uppercase tracking-wider">Baseline</div>
              {baseline ? (
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
              ) : (
                <div>Not captured. Movement cannot be measured until it is.</div>
              )}
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
