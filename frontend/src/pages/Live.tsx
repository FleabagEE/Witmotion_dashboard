import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import { WaveformCard, type Trace } from '../components/WaveformCard'
import { Empty, Pill, relativeAge } from '../components/ui'

/** Axis colours are consistent across every card, so X is always X. */
const AXIS: Trace[] = [
  { key: 'x', label: 'X', colour: '#58a6ff' },
  { key: 'y', label: 'Y', colour: '#3fb950' },
  { key: 'z', label: 'Z', colour: '#d29922' },
]

function axisTraces(prefix: string): Trace[] {
  return AXIS.map((a) => ({ ...a, key: `${prefix}_${a.key}` }))
}

/**
 * The five quantities this sensor measures, each in its own unit.
 *
 * Grouped by physical quantity rather than by register block: an operator thinks
 * "how fast is it moving", not "what is in holding register 0x3A".
 */
const CARDS = [
  { title: 'Acceleration', unit: 'g', traces: axisTraces('accel'), decimals: 3 },
  { title: 'Vibration velocity', unit: 'mm/s', traces: axisTraces('vib_velocity'), decimals: 2 },
  { title: 'Vibration displacement', unit: 'µm', traces: axisTraces('vib_displacement'), decimals: 0 },
  { title: 'Dominant frequency', unit: 'Hz', traces: axisTraces('vib_frequency'), decimals: 1 },
  {
    title: 'RMS acceleration',
    unit: 'g',
    traces: AXIS.map((a) => ({ ...a, key: `rms_accel_${a.key}` })),
    decimals: 3,
    note: 'computed on-device',
  },
  {
    title: 'Chip temperature',
    unit: '°C',
    traces: [{ key: 'temperature', label: 'T', colour: '#f0883e' }],
    decimals: 2,
  },
]

const WINDOWS = [
  { label: '5 min', seconds: 300, refetch: 2000 },
  { label: '15 min', seconds: 900, refetch: 3000 },
  { label: '1 hour', seconds: 3600, refetch: 5000 },
  { label: '6 hours', seconds: 21600, refetch: 15000 },
  { label: '24 hours', seconds: 86400, refetch: 30000 },
]

const ALL_CHANNELS = CARDS.flatMap((c) => c.traces.map((t) => t.key))

export function Live() {
  const [windowIndex, setWindowIndex] = useState(0)
  const [sensorId, setSensorId] = useState<string | null>(null)
  const active = WINDOWS[windowIndex]

  const sensors = useQuery({ queryKey: ['sensors'], queryFn: api.sensors, refetchInterval: 15000 })
  const selected = sensorId ?? sensors.data?.data[0]?.sensor_id ?? null
  const sensor = sensors.data?.data.find((s) => s.sensor_id === selected)

  // Split into two requests: twelve channels is the endpoint's ceiling, and one
  // round trip per card would let the traces drift apart in time.
  const first = useQuery({
    queryKey: ['multi', selected, active.seconds, 'a'],
    queryFn: () => api.multiSeries(selected!, ALL_CHANNELS.slice(0, 9), active.seconds),
    enabled: Boolean(selected),
    refetchInterval: active.refetch,
  })
  const second = useQuery({
    queryKey: ['multi', selected, active.seconds, 'b'],
    queryFn: () => api.multiSeries(selected!, ALL_CHANNELS.slice(9), active.seconds),
    enabled: Boolean(selected),
    refetchInterval: active.refetch,
  })

  const series = { ...(first.data?.series ?? {}), ...(second.data?.series ?? {}) }
  const resolution = first.data?.resolution

  if (sensors.isLoading) return <Empty>Loading…</Empty>
  if (!selected) return <Empty>No sensors registered yet.</Empty>

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-baseline gap-3">
          <h1 className="text-lg font-semibold">Live monitor</h1>
          {sensor && (
            <span className="flex items-center gap-2 text-xs text-ink-dim">
              <span
                className={`h-1.5 w-1.5 rounded-full ${sensor.online ? 'bg-ok' : 'bg-warning'}`}
                aria-hidden
              />
              {sensor.online ? 'live' : `silent ${relativeAge(sensor.silent_for_seconds)}`}
              <Pill tone={sensor.trustworthy ? 'ok' : 'warn'}>{sensor.verification_status}</Pill>
            </span>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {sensors.data && sensors.data.data.length > 1 && (
            <select
              value={selected}
              onChange={(e) => setSensorId(e.target.value)}
              className="rounded border border-line bg-panel-2 px-2 py-1 text-xs"
            >
              {sensors.data.data.map((s) => (
                <option key={s.sensor_id} value={s.sensor_id}>
                  {s.sensor_id}
                </option>
              ))}
            </select>
          )}
          <div className="flex gap-1">
            {WINDOWS.map((w, i) => (
              <button
                key={w.label}
                onClick={() => setWindowIndex(i)}
                className={`rounded px-2 py-1 text-xs ${
                  i === windowIndex ? 'bg-accent text-shell' : 'bg-panel-2 text-ink-dim hover:text-ink'
                }`}
              >
                {w.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
        {CARDS.map((card) => (
          <WaveformCard
            key={card.title}
            title={card.title}
            unit={card.unit}
            traces={card.traces}
            series={series}
            decimals={card.decimals}
            resolution={resolution}
            note={card.note}
          />
        ))}
      </div>
    </div>
  )
}
