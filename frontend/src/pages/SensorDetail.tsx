import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import ReactECharts from 'echarts-for-react'
import { api } from '../lib/api'
import { Empty, Panel, Pill } from '../components/ui'

const WINDOWS = [
  { label: '15m', hours: 0.25 },
  { label: '1h', hours: 1 },
  { label: '6h', hours: 6 },
  { label: '24h', hours: 24 },
  { label: '7d', hours: 168 },
]

export function SensorDetail() {
  const { sensorId = '' } = useParams()
  const [channelKey, setChannelKey] = useState<string | null>(null)
  const [windowHours, setWindowHours] = useState(1)

  const channels = useQuery({
    queryKey: ['channels', sensorId],
    queryFn: () => api.channels(sensorId),
  })
  const latest = useQuery({
    queryKey: ['latest', sensorId],
    queryFn: () => api.latest(sensorId),
    refetchInterval: 3000,
  })

  const selected = channelKey ?? channels.data?.data[0]?.channel_key ?? null
  const fromIso = useMemo(
    () => new Date(Date.now() - windowHours * 3600_000).toISOString(),
    [windowHours],
  )

  const series = useQuery({
    queryKey: ['series', sensorId, selected, fromIso],
    queryFn: () => api.series(sensorId, selected!, fromIso),
    enabled: Boolean(selected),
    refetchInterval: windowHours <= 1 ? 5000 : 30000,
  })

  const latestByKey = useMemo(() => {
    const map = new Map<string, number | null>()
    latest.data?.data.forEach((r) => map.set(r.channel_key, r.value))
    return map
  }, [latest.data])

  const channelInfo = channels.data?.data.find((c) => c.channel_key === selected)

  const option = useMemo(() => {
    const points = series.data?.data ?? []
    const isRollup = series.data?.resolution === 'hourly_rollup'
    return {
      grid: { left: 56, right: 16, top: 16, bottom: 32 },
      tooltip: { trigger: 'axis' },
      xAxis: {
        type: 'time',
        axisLine: { lineStyle: { color: '#24313d' } },
        axisLabel: { color: '#8b9bab' },
      },
      yAxis: {
        type: 'value',
        name: channelInfo?.unit ?? '',
        nameTextStyle: { color: '#8b9bab' },
        splitLine: { lineStyle: { color: '#1a232d' } },
        axisLabel: { color: '#8b9bab' },
      },
      series: [
        // On a rollup the band shows the hourly min-max, so a viewer can see
        // that a flat average may hide a large excursion.
        ...(isRollup
          ? [
              {
                type: 'line',
                name: 'range',
                data: points.map((p) => [p.t, p.max]),
                lineStyle: { opacity: 0 },
                areaStyle: { color: 'rgba(88,166,255,0.12)', origin: 'start' },
                symbol: 'none',
                stack: undefined,
              },
            ]
          : []),
        {
          type: 'line',
          name: isRollup ? 'hourly mean' : 'value',
          data: points.map((p) => [p.t, p.value]),
          showSymbol: false,
          lineStyle: { color: '#58a6ff', width: 1.6 },
        },
      ],
    }
  }, [series.data, channelInfo])

  if (channels.isLoading) return <Empty>Loading…</Empty>
  if (channels.error) return <Empty>Sensor not found.</Empty>

  const allChannels = channels.data?.data ?? []
  const grouped = new Map<string, typeof allChannels>()
  allChannels.forEach((c) => {
    const list = grouped.get(c.group_key) ?? []
    list.push(c)
    grouped.set(c.group_key, list)
  })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h1 className="text-xl font-semibold">{sensorId}</h1>
        <div className="text-xs text-ink-dim">
          {latest.data?.data[0] ? `updated ${new Date(latest.data.data[0].at).toLocaleTimeString()}` : 'no recent data'}
        </div>
      </div>

      <Panel
        title={channelInfo?.label ?? 'Channel'}
        subtitle={
          channelInfo && (
            <span>
              {channelInfo.quantity} · register 0x{channelInfo.register_address?.toString(16)} · scale {channelInfo.scale}
              {series.data && (
                <>
                  {' · '}
                  <Pill tone={series.data.resolution === 'raw_bucketed' ? 'ok' : 'muted'}>
                    {series.data.resolution === 'raw_bucketed' ? 'raw samples' : 'hourly average'}
                  </Pill>
                </>
              )}
            </span>
          )
        }
        actions={
          <div className="flex gap-1">
            {WINDOWS.map((w) => (
              <button
                key={w.label}
                onClick={() => setWindowHours(w.hours)}
                className={`rounded px-2 py-1 text-xs ${
                  windowHours === w.hours ? 'bg-accent text-shell' : 'bg-panel-2 text-ink-dim hover:text-ink'
                }`}
              >
                {w.label}
              </button>
            ))}
          </div>
        }
      >
        {series.data?.resolution === 'hourly_rollup' && (
          <p className="mb-3 text-xs text-advisory">
            Showing hourly averages. Short peaks are flattened — use a shorter window to see raw samples.
          </p>
        )}
        <ReactECharts option={option} style={{ height: 320 }} notMerge />
      </Panel>

      {[...grouped.entries()].map(([group, list]) => (
        <Panel key={group} title={group.replace(/_/g, ' ')}>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {list.map((c) => {
              const value = latestByKey.get(c.channel_key)
              const active = c.channel_key === selected
              return (
                <button
                  key={c.channel_key}
                  onClick={() => setChannelKey(c.channel_key)}
                  className={`rounded border px-3 py-2 text-left ${
                    active ? 'border-accent bg-panel-2' : 'border-line bg-panel-2 hover:border-ink-dim'
                  }`}
                >
                  <div className="truncate text-xs text-ink-dim">{c.label}</div>
                  <div className="tnum mt-0.5 text-lg">
                    {value === null || value === undefined ? (
                      <span className="text-unknown">—</span>
                    ) : (
                      value.toFixed(Math.abs(value) < 10 ? 3 : 1)
                    )}
                    <span className="ml-1 text-xs text-ink-dim">{c.unit}</span>
                  </div>
                </button>
              )
            })}
          </div>
        </Panel>
      ))}
    </div>
  )
}
