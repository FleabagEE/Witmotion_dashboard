import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'
import { Empty, Panel, Pill, SeverityBadge, Stat, relativeAge } from '../components/ui'

export function Overview() {
  const overview = useQuery({ queryKey: ['overview'], queryFn: api.overview, refetchInterval: 5000 })
  const sensors = useQuery({ queryKey: ['sensors'], queryFn: api.sensors, refetchInterval: 10000 })

  if (overview.isLoading) return <Empty>Loading…</Empty>
  if (overview.error) return <Empty>Could not load the overview.</Empty>

  const data = overview.data!
  const alarmTone =
    data.alarms.critical > 0 ? 'critical' : data.alarms.warning > 0 ? 'warning' : data.alarms.advisory > 0 ? 'advisory' : 'normal'

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Sensors online" value={`${data.sensors.online}/${data.sensors.total}`} tone={data.sensors.silent > 0 ? 'warning' : 'normal'} />
        <Stat label="Active alarms" value={data.alarms.active} tone={alarmTone} />
        <Stat
          label="Unacknowledged"
          value={data.alarms.unacknowledged}
          tone={data.alarms.unacknowledged > 0 ? 'advisory' : 'normal'}
        />
        <Stat
          label="Measurements stored"
          value={data.storage.measurements.toLocaleString()}
        />
      </div>

      {/* Only while something can still be judged against those tables. The
          banner used to key on the tables' status alone, so it kept warning
          about unconfirmed thresholds after the last structural check was
          disabled - a standing warning nobody can act on, which is how the real
          ones stop being read. */}
      {((data.standards.structural_tables_status !== 'verified'
        && data.standards.structural_alarms_enabled) || data.alarms.provisional > 0) && (
        <div className="rounded-lg border border-advisory/40 bg-advisory/10 px-4 py-3 text-sm">
          <strong className="text-advisory">Thresholds not confirmed.</strong>{' '}
          <span className="text-ink-dim">
            The structural guideline tables are <code>{data.standards.structural_tables_status}</code> — transcribed,
            not checked against DIN 4150-3 or BS 7385-2. Alarms raised from them are shown but will not notify anyone
            until a named person confirms the values.
            {data.alarms.provisional > 0 && ` ${data.alarms.provisional} active alarm(s) are provisional.`}
          </span>
        </div>
      )}

      {data.sensors.unverified_profiles > 0 && (
        <div className="rounded-lg border border-advisory/40 bg-advisory/10 px-4 py-3 text-sm">
          <strong className="text-advisory">{data.sensors.unverified_profiles} sensor(s) have an unverified register map.</strong>{' '}
          <span className="text-ink-dim">Their readings are decoded with values nobody has confirmed against the manufacturer's table.</span>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Panel title="Appliances" subtitle="Edge acquisition units">
          {data.appliances.length === 0 ? (
            <Empty>No appliance has reported yet.</Empty>
          ) : (
            <ul className="space-y-2">
              {data.appliances.map((a) => (
                <li key={a.appliance_id} className="flex items-center justify-between rounded border border-line bg-panel-2 px-3 py-2">
                  <div>
                    <div className="text-sm font-medium">{a.appliance_id}</div>
                    <div className="text-xs text-ink-dim">last data {relativeAge(a.seconds_since_ingest)}</div>
                  </div>
                  <SeverityBadge level={a.seconds_since_ingest !== null && a.seconds_since_ingest < 120 ? 'normal' : 'warning'}>
                    {a.seconds_since_ingest !== null && a.seconds_since_ingest < 120 ? 'reporting' : 'silent'}
                  </SeverityBadge>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="Sensors" subtitle="Click through for live channels">
          {!sensors.data || sensors.data.data.length === 0 ? (
            <Empty>No sensors registered.</Empty>
          ) : (
            <ul className="space-y-2">
              {sensors.data.data.map((s) => (
                <li key={s.sensor_id}>
                  <Link
                    to={`/sensors/${encodeURIComponent(s.sensor_id)}`}
                    className="flex items-center justify-between rounded border border-line bg-panel-2 px-3 py-2 hover:border-accent"
                  >
                    <div>
                      <div className="text-sm font-medium">{s.sensor_id}</div>
                      <div className="flex items-center gap-2 text-xs text-ink-dim">
                        <span>{s.model}</span>
                        <Pill tone={s.trustworthy ? 'ok' : 'warn'}>{s.verification_status}</Pill>
                        <span>{s.channel_count} channels</span>
                      </div>
                    </div>
                    <SeverityBadge level={s.online ? 'normal' : 'warning'}>
                      {s.online ? 'live' : relativeAge(s.silent_for_seconds)}
                    </SeverityBadge>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </div>
  )
}
