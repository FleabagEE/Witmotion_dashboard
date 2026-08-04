import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import ReactECharts from 'echarts-for-react'
import { api } from '../lib/api'
import { Empty, Pill } from '../components/ui'
import type { Spectrum } from '../lib/api'

/**
 * Frequency content of one channel.
 *
 * Two answers sit side by side on this page because they come from different
 * places and reach different distances. The sensor computes its own dominant
 * frequency internally at full rate and is good to 300 Hz. We can only analyse
 * what we sampled over a 9600-baud Modbus bus, which is defensible to about
 * 3 Hz. Showing only ours would badly understate the appliance; showing only
 * the sensor's would hide that our own record cannot corroborate it.
 */

const CHANNELS = [
  { key: 'accel_x', label: 'Acceleration X' },
  { key: 'accel_y', label: 'Acceleration Y' },
  { key: 'accel_z', label: 'Acceleration Z' },
  { key: 'vib_velocity_x', label: 'Velocity X' },
  { key: 'vib_velocity_y', label: 'Velocity Y' },
  { key: 'vib_velocity_z', label: 'Velocity Z' },
]

const WINDOWS = [
  { label: '1 min', seconds: 60 },
  { label: '5 min', seconds: 300 },
  { label: '15 min', seconds: 900 },
  { label: '1 hour', seconds: 3600 },
]

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-lg border border-line bg-panel-2 px-3 py-2">
      <div className="text-[10px] uppercase tracking-wider text-ink-dim">{label}</div>
      <div className="tnum mt-0.5 text-sm font-semibold">{value}</div>
      {hint && <div className="mt-0.5 text-[10px] text-ink-dim">{hint}</div>}
    </div>
  )
}

export function Signal() {
  const [channel, setChannel] = useState(CHANNELS[0].key)
  const [windowIndex, setWindowIndex] = useState(1)
  const active = WINDOWS[windowIndex]

  const sensors = useQuery({ queryKey: ['sensors'], queryFn: api.sensors, refetchInterval: 30000 })
  const [sensorId, setSensorId] = useState<string | null>(null)
  const selected = sensorId ?? sensors.data?.data[0]?.sensor_id ?? null

  const spectrum = useQuery({
    queryKey: ['spectrum', selected, channel, active.seconds],
    queryFn: () => api.spectrum(selected!, channel, active.seconds),
    enabled: Boolean(selected),
    refetchInterval: 10000,
  })

  if (sensors.isLoading) return <Empty>Loading…</Empty>
  if (!selected) return <Empty>No sensors registered yet.</Empty>

  const data: Spectrum | undefined = spectrum.data
  const a = data?.analysis
  const s = a?.spectrum

  const option = s && {
    animation: false,
    grid: { left: 54, right: 16, top: 16, bottom: 34 },
    tooltip: {
      trigger: 'axis',
      backgroundColor: '#131a22',
      borderColor: '#24313d',
      textStyle: { color: '#e6edf3', fontSize: 11 },
    },
    xAxis: {
      type: 'value',
      name: 'Hz',
      nameLocation: 'middle',
      nameGap: 20,
      nameTextStyle: { color: '#6d7f90', fontSize: 10 },
      min: s.min_hz,
      max: Math.max(...s.frequencies),
      axisLine: { lineStyle: { color: '#24313d' } },
      axisLabel: { color: '#6d7f90', fontSize: 10 },
      splitLine: { show: false },
    },
    yAxis: {
      type: 'value',
      name: 'power',
      nameTextStyle: { color: '#6d7f90', fontSize: 10, align: 'left' },
      axisLabel: { color: '#6d7f90', fontSize: 10 },
      splitLine: { lineStyle: { color: '#1a232d' } },
    },
    series: [
      {
        type: 'line',
        showSymbol: false,
        lineStyle: { width: 1.5, color: '#58a6ff' },
        areaStyle: { color: 'rgba(88,166,255,0.12)' },
        data: s.frequencies.map((f, i) => [f, s.power[i]]),
        // The peak is marked only when it is statistically distinguishable from
        // noise. Marking the tallest bar unconditionally would turn every flat
        // spectrum into an apparent finding.
        markLine: s.peak_significant
          ? {
              silent: true,
              symbol: 'none',
              lineStyle: { color: '#3fb950', type: 'dashed' },
              label: { color: '#3fb950', fontSize: 10, formatter: `${s.peak_hz} Hz` },
              data: [{ xAxis: s.peak_hz }],
            }
          : undefined,
      },
    ],
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-lg font-semibold">Signal analysis</h1>
          {data?.verification_status && (
            <Pill tone={data.verification_status === 'verified' ? 'ok' : 'warn'}>
              {data.verification_status}
            </Pill>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {sensors.data && sensors.data.data.length > 1 && (
            <select
              value={selected}
              onChange={(e) => setSensorId(e.target.value)}
              className="rounded border border-line bg-panel-2 px-2 py-1 text-xs"
            >
              {sensors.data.data.map((x) => (
                <option key={x.sensor_id} value={x.sensor_id}>
                  {x.sensor_id}
                </option>
              ))}
            </select>
          )}
          <select
            value={channel}
            onChange={(e) => setChannel(e.target.value)}
            className="rounded border border-line bg-panel-2 px-2 py-1 text-xs"
          >
            {CHANNELS.map((c) => (
              <option key={c.key} value={c.key}>
                {c.label}
              </option>
            ))}
          </select>
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
      </div>

      {a && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Stat
            label="Measured sample rate"
            value={a.sample_hz ? `${a.sample_hz.toFixed(2)} Hz` : '—'}
            hint="from the timestamps, not the configuration"
          />
          <Stat
            label="Timing jitter"
            value={a.jitter_ms != null ? `${a.jitter_ms.toFixed(1)} ms` : '—'}
            hint="why the band stops short of Nyquist"
          />
          <Stat
            label="Defensible band"
            value={a.defensible_max_hz ? `${a.defensible_max_hz.toFixed(2)} Hz` : '—'}
            hint={a.nyquist_hz ? `Nyquist would be ${a.nyquist_hz.toFixed(2)} Hz` : undefined}
          />
          <Stat
            label="Samples analysed"
            value={`${a.samples ?? 0}`}
            hint={a.decimation > 1 ? `decimated ${a.decimation}:1 from ${a.samples_available}` : undefined}
          />
        </div>
      )}

      <section className="rounded-xl border border-line bg-panel">
        <header className="border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold">
            What this appliance sampled
            <span className="ml-2 font-normal text-[11px] text-ink-dim">
              Lomb-Scargle periodogram · unevenly spaced samples · linear trend removed
            </span>
          </h2>
        </header>

        <div className="px-1 pb-1 pt-2">
          {option ? (
            <ReactECharts option={option} style={{ height: 260 }} notMerge lazyUpdate />
          ) : (
            <div className="flex h-[260px] items-center justify-center px-8 text-center">
              <p className="max-w-xl text-xs leading-relaxed text-ink-dim">
                {a?.explanation ?? 'Loading…'}
              </p>
            </div>
          )}
        </div>

        {s?.transient && (
          <p className="border-t border-line bg-warning/5 px-4 py-2 text-[11px] leading-relaxed text-warning">
            {s.transient_note}
          </p>
        )}

        {s && (
          <p className="border-t border-line px-4 py-2 text-[11px] text-ink-dim">
            <span className="mr-2 text-ink-dim">
              Below {s.lowest_reportable_hz} Hz is plotted but never reported: slow drift is not
              vibration.
            </span>
            <br />
            {s.peak_significant ? (
              <>
                Strongest component <span className="text-ok">{s.peak_hz} Hz</span>, false-alarm
                probability {s.false_alarm_probability.toExponential(1)}.
              </>
            ) : (
              <>
                {s.transient ? (
                  <>
                    Energy is present but concentrated in time, so it has a moment rather than a
                    frequency. No component is reported.
                  </>
                ) : (
                  <>
                    No component here is distinguishable from noise (strongest is {s.peak_hz} Hz
                    with a false-alarm probability of {s.false_alarm_probability.toFixed(2)}). A
                    still structure has no spectrum to find.
                  </>
                )}
              </>
            )}
          </p>
        )}
      </section>

      {/* The counterweight. Without this the page would read as "the appliance
          can only see 3 Hz", which is true of our sampling and false of the
          instrument. */}
      <section className="rounded-xl border border-line bg-panel">
        <header className="border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold">
            What the sensor reports
            <span className="ml-2 font-normal text-[11px] text-ink-dim">
              computed on-device at full rate · not limited by the poll rate
            </span>
          </h2>
        </header>
        <div className="px-4 py-3">
          {data?.device_reported ? (
            <>
              <div className="grid gap-3 sm:grid-cols-3">
                <Stat label="Mean" value={`${data.device_reported.mean_hz} Hz`} />
                <Stat label="Minimum" value={`${data.device_reported.min_hz} Hz`} />
                <Stat label="Maximum" value={`${data.device_reported.max_hz} Hz`} />
              </div>
              {data.device_reported.rejected_samples > 0 && (
                // Excluding them silently is only half a fix. These figures
                // used to include readings past the register's declared range -
                // a 381 Hz maximum the appliance had itself rejected. Filtering
                // them out without saying so replaces a wrong number with a
                // quiet one, and a window that is mostly out of range would
                // then summarise exactly like a clean one.
                <p className="mt-3 rounded border border-advisory/40 bg-advisory/10 px-2 py-1.5 text-[11px] leading-relaxed text-advisory">
                  {data.device_reported.rejected_samples} reading(s) in this window fell outside the
                  register's declared range and are excluded from these figures. Persistent
                  out-of-range readings point at the scaling or the register map, not the structure.
                </p>
              )}
              <p className="mt-3 text-[11px] leading-relaxed text-ink-dim">
                {data.device_reported.note}
              </p>
            </>
          ) : (
            <p className="text-xs text-ink-dim">
              No on-device frequency reading for this axis in the selected window.
            </p>
          )}
        </div>
      </section>
    </div>
  )
}
