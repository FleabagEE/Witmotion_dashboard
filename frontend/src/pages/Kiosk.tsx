import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import { subscribeToConnectionState, subscribeToSensor, type LiveFrame } from '../lib/live'
import { relativeAge } from '../components/ui'
import { freshness } from '../lib/staleness'

/**
 * Wall display for a control room.
 *
 * Designed to be read from across a room by somebody who is not operating it:
 * few numbers, large, and every one of them carrying its unit and its state.
 * There is nothing to click. A kiosk token can only read, so even a screen
 * somebody can reach cannot acknowledge an alarm or change a setting.
 *
 * The hardest requirement is the least visible one: it has to be trustworthy
 * when unattended. A frozen dashboard showing a plausible number is worse than a
 * blank one, so staleness is stated on the face of it rather than inferred from
 * a trace that stopped moving.
 */

interface Tile {
  key: string
  label: string
  unit: string
  decimals: number
  /** Channels combined into one figure - the worst axis, for a wall display. */
  channels: string[]
}

const TILES: Tile[] = [
  { key: 'velocity', label: 'Velocity', unit: 'mm/s', decimals: 2,
    channels: ['vib_velocity_x', 'vib_velocity_y', 'vib_velocity_z'] },
  { key: 'displacement', label: 'Displacement', unit: 'µm', decimals: 0,
    channels: ['vib_displacement_x', 'vib_displacement_y', 'vib_displacement_z'] },
  { key: 'frequency', label: 'Dominant frequency', unit: 'Hz', decimals: 1,
    channels: ['vib_frequency_x', 'vib_frequency_y', 'vib_frequency_z'] },
  { key: 'tilt', label: 'Tilt from vertical', unit: '°', decimals: 2,
    channels: ['incl_tilt'] },
]

export function Kiosk() {
  const [frames, setFrames] = useState<Record<string, { value: number; at: number }>>({})
  const [connected, setConnected] = useState(false)
  const [now, setNow] = useState(() => Date.now())

  const sensors = useQuery({ queryKey: ['sensors'], queryFn: api.sensors, refetchInterval: 15000 })
  const alarms = useQuery({ queryKey: ['alarms'], queryFn: () => api.alarms(), refetchInterval: 10000 })
  const sensorId = sensors.data?.data[0]?.sensor_id ?? null
  const sensor = sensors.data?.data.find((s) => s.sensor_id === sensorId)

  // Drives the staleness check. Without a ticking clock a frozen feed keeps
  // whatever age it had when the last frame arrived, which is exactly the
  // failure this is meant to catch.
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(timer)
  }, [])

  useEffect(() => subscribeToConnectionState((s) => setConnected(s === 'connected')), [])

  useEffect(() => {
    if (!sensorId) return
    return subscribeToSensor(sensorId, (frame: LiveFrame) => {
      setFrames((previous) => {
        const next = { ...previous }
        for (const [key, value] of Object.entries(frame.values)) {
          next[key] = { value, at: frame.t }
        }
        return next
      })
    })
  }, [sensorId])

  // Falls back to the stored series whenever the socket is not delivering, so
  // the screen keeps working through a Reverb restart.
  const latest = useQuery({
    queryKey: ['kiosk-latest', sensorId],
    queryFn: () => api.latest(sensorId!),
    enabled: Boolean(sensorId),
    refetchInterval: connected ? 10000 : 2000,
  })

  const values = useMemo(() => {
    const out: Record<string, { value: number; at: number } | null> = {}
    for (const tile of TILES) {
      let best: { value: number; at: number } | null = null
      for (const channel of tile.channels) {
        const live = frames[channel]
        const stored = latest.data?.data.find((r) => r.channel_key === channel)
        const candidate = live
          ?? (stored?.value != null
            ? { value: stored.value, at: new Date(stored.at).getTime() }
            : null)
        if (candidate && (best === null || Math.abs(candidate.value) > Math.abs(best.value))) {
          best = candidate
        }
      }
      out[tile.key] = best
    }
    return out
  }, [frames, latest.data])

  const active = (alarms.data?.data ?? []).filter((a) => a.state === 'active' && a.level !== 'normal')
  const worst = active.find((a) => a.level === 'critical') ?? active[0]

  // Ages by the stalest tile, and treats a missing one as stale. See
  // lib/staleness - a frozen screen showing a plausible number is the failure
  // this exists to prevent.
  const { ageMs: feedAge, stale } = freshness(Object.values(values), now)

  return (
    <div className="flex min-h-screen flex-col bg-bg p-6 text-ink">
      <header className="flex items-baseline justify-between border-b border-line pb-4">
        <div className="flex items-baseline gap-5">
          <h1 className="text-3xl font-semibold tracking-wide">QuakeVault</h1>
          <span className="text-xl text-ink-dim">{sensor?.sensor_id ?? '—'}</span>
        </div>
        <div className="flex items-center gap-4 text-lg">
          <span
            className={`h-3 w-3 rounded-full ${stale ? 'bg-critical' : 'bg-ok'}`}
            aria-hidden
          />
          <span className={stale ? 'text-critical' : 'text-ink-dim'}>
            {stale
              ? `no data — last reading ${relativeAge(Math.round(feedAge / 1000))}`
              : connected ? 'live' : 'live · polling'}
          </span>
        </div>
      </header>

      {/* The alarm banner takes the whole width when it exists. On a wall
          display a small badge in a corner is not a notification. */}
      {worst && (
        <div
          className={`mt-6 rounded-2xl px-8 py-6 ${
            worst.level === 'critical'
              ? 'bg-critical/15 text-critical'
              : 'bg-warning/15 text-warning'
          }`}
        >
          <div className="text-2xl uppercase tracking-widest opacity-80">
            {worst.level}
            {worst.provisional && ' · provisional threshold'}
          </div>
          <div className="mt-1 text-4xl font-semibold">{worst.name}</div>
          <div className="mt-2 text-2xl opacity-90">
            {worst.value?.toFixed(2)} {worst.unit} against {worst.threshold} {worst.unit}
          </div>
        </div>
      )}

      <div className="mt-6 grid flex-1 grid-cols-1 gap-6 sm:grid-cols-2">
        {TILES.map((tile) => {
          const reading = values[tile.key]
          return (
            <section
              key={tile.key}
              className="flex flex-col justify-center rounded-2xl border border-line bg-panel px-8 py-8"
            >
              <div className="text-xl uppercase tracking-widest text-ink-dim">{tile.label}</div>
              <div className="mt-3 flex items-baseline gap-4">
                <span
                  className={`tnum text-7xl font-semibold ${stale ? 'text-ink-dim' : 'text-ink'}`}
                >
                  {reading ? reading.value.toFixed(tile.decimals) : '—'}
                </span>
                <span className="text-3xl text-ink-dim">{tile.unit}</span>
              </div>
              {tile.channels.length > 1 && (
                <div className="mt-2 text-base text-ink-dim">largest of the three axes</div>
              )}
            </section>
          )
        })}
      </div>

      <footer className="mt-6 flex items-center justify-between border-t border-line pt-4 text-base text-ink-dim">
        <span>
          {sensor?.verification_status === 'verified'
            ? 'register map verified'
            : `register map ${sensor?.verification_status ?? 'unknown'}`}
        </span>
        {/* Said plainly on the face of the display: nothing here has been
            confirmed against a standard, so no reading on this screen is a
            compliance statement. */}
        <span>guideline values unconfirmed · not a compliance readout</span>
      </footer>
    </div>
  )
}
