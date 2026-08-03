import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import { WaveformCard, type Trace } from '../components/WaveformCard'
import { Empty, Pill, relativeAge } from '../components/ui'
import { subscribeToSensor, subscribeToConnectionState, type LiveFrame } from '../lib/live'
import type { SeriesPoint } from '../lib/api'

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
interface CardSpec {
  title: string
  unit: string
  traces: Trace[]
  decimals: number
  note?: string
}

const CARDS: CardSpec[] = [
  { title: 'Acceleration', unit: 'g', traces: axisTraces('accel'), decimals: 3 },
  { title: 'Vibration velocity', unit: 'mm/s', traces: axisTraces('vib_velocity'), decimals: 2 },
  { title: 'Vibration displacement', unit: 'µm', traces: axisTraces('vib_displacement'), decimals: 0 },
  { title: 'Dominant frequency', unit: 'Hz', traces: axisTraces('vib_frequency'), decimals: 1 },
  {
    title: 'Chip temperature',
    unit: '°C',
    traces: [{ key: 'temperature', label: 'T', colour: '#f0883e' }],
    decimals: 2,
  },
]

/**
 * Points are chosen so the bucket width is close to the sensor's own poll
 * interval: bucketing coarser than the data throws away detail, finer just
 * interpolates nothing.
 */
const WINDOWS = [
  { label: '1 min', seconds: 60, points: 500, refetch: 1000 },
  { label: '5 min', seconds: 300, points: 600, refetch: 1000 },
  { label: '15 min', seconds: 900, points: 600, refetch: 2000 },
  { label: '1 hour', seconds: 3600, points: 600, refetch: 5000 },
  { label: '6 hours', seconds: 21600, points: 600, refetch: 15000 },
  { label: '24 hours', seconds: 86400, points: 600, refetch: 30000 },
]

const ALL_CHANNELS = CARDS.flatMap((c) => c.traces.map((t) => t.key))

export function Live() {
  const [windowIndex, setWindowIndex] = useState(0)
  const [sensorId, setSensorId] = useState<string | null>(null)
  const active = WINDOWS[windowIndex]

  const sensors = useQuery({ queryKey: ['sensors'], queryFn: api.sensors, refetchInterval: 15000 })
  const selected = sensorId ?? sensors.data?.data[0]?.sensor_id ?? null
  const sensor = sensors.data?.data.find((s) => s.sensor_id === selected)

  // Every card in one request. Splitting it would let each half land at a
  // slightly different moment, which on a chart reads as a skew between cards
  // that is not in the data.
  const feed = useQuery({
    queryKey: ['multi', selected, active.seconds],
    queryFn: () => api.multiSeries(selected!, ALL_CHANNELS, active.seconds, active.points),
    enabled: Boolean(selected),
    refetchInterval: active.refetch,
    // Keep the previous window on screen while the next one loads, so the
    // charts do not blank on every refresh.
    placeholderData: (previous) => previous,
  })

  const stored = feed.data?.series
  const resolution = feed.data?.resolution

  // Frames arriving over the websocket, appended to whatever the last stored
  // fetch returned. This is what removes the store-and-forward latency: the
  // chart shows a reading about as soon as the sensor produced it, while the
  // authoritative copy still travels the durable path behind it.
  const [liveFrames, setLiveFrames] = useState<LiveFrame[]>([])
  const [connected, setConnected] = useState(false)

  // Driven by the socket itself rather than by frames arriving. A frame only
  // proves the socket was up when it was sent, so treating one as evidence of
  // a live connection leaves the badge stuck on "websocket" through an outage,
  // describing polled data as live. On a disconnect the buffered frames are
  // dropped too: they would otherwise sit on the chart looking current until
  // the time window slid past them.
  useEffect(() => {
    return subscribeToConnectionState((state) => {
      const live = state === 'connected'
      setConnected(live)
      if (!live) setLiveFrames([])
    })
  }, [])
  const windowMs = active.seconds * 1000
  const windowRef = useRef(windowMs)
  windowRef.current = windowMs

  useEffect(() => {
    if (!selected) return
    setLiveFrames([])
    const unsubscribe = subscribeToSensor(selected, (frame) => {
      setLiveFrames((previous) => {
        const cutoff = Date.now() - windowRef.current
        const next = [...previous, frame].filter((f) => f.t >= cutoff)
        // Bounded: a long window at a high poll rate would otherwise grow the
        // buffer until the tab slows down.
        return next.length > 4000 ? next.slice(next.length - 4000) : next
      })
    })
    return unsubscribe
  }, [selected])

  // Live frames are only ever appended after the newest stored point, so a
  // websocket frame can never overwrite or reorder recorded history.
  const series = useMemo(() => {
    if (!stored) return undefined
    if (liveFrames.length === 0) return stored

    const merged: Record<string, SeriesPoint[]> = {}
    for (const [key, points] of Object.entries(stored)) {
      const lastStored = points.length ? points[points.length - 1].t : 0
      const extra: SeriesPoint[] = []
      for (const frame of liveFrames) {
        const value = frame.values[key]
        if (value !== undefined && frame.t > lastStored) {
          extra.push({ t: frame.t, v: value, lo: value, hi: value })
        }
      }
      merged[key] = extra.length ? [...points, ...extra] : points
    }
    return merged
  }, [stored, liveFrames])

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
              <Pill tone={connected ? 'ok' : 'muted'}>
                {connected ? 'websocket' : 'polling'}
              </Pill>
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
