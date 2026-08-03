const TOKEN_KEY = 'qv.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = getToken()
  const response = await fetch(`/api/v1${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  })

  if (response.status === 401) {
    // The token is gone or expired. Drop it so the app returns to the login
    // screen rather than retrying forever against a dead credential.
    setToken(null)
    throw new ApiError('Session expired', 401)
  }

  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(body.message ?? `Request failed (${response.status})`, response.status)
  }
  return body as T
}

export const api = {
  login: (email: string, password: string) =>
    request<{ token: string; user: CurrentUser }>('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password, device_name: 'dashboard' }),
    }),
  me: () => request<CurrentUser>('/me'),
  overview: () => request<Overview>('/overview'),
  sensors: () => request<{ data: SensorSummary[] }>('/sensors'),
  channels: (sensorId: string) =>
    request<{ data: ChannelInfo[] }>(`/sensors/${encodeURIComponent(sensorId)}/channels`),
  latest: (sensorId: string) =>
    request<{ data: LatestReading[] }>(`/sensors/${encodeURIComponent(sensorId)}/latest`),
  series: (sensorId: string, channelKey: string, fromIso: string, maxPoints = 400) =>
    request<Series>(
      `/series?sensor_id=${encodeURIComponent(sensorId)}&channel_key=${encodeURIComponent(channelKey)}` +
        `&from=${encodeURIComponent(fromIso)}&max_points=${maxPoints}`,
    ),
  multiSeries: (sensorId: string, channels: string[], seconds: number, maxPoints = 300) =>
    request<MultiSeries>(
      `/series/multi?sensor_id=${encodeURIComponent(sensorId)}` +
        `&channels=${encodeURIComponent(channels.join(','))}` +
        `&seconds=${seconds}&max_points=${maxPoints}`,
    ),
  spectrum: (sensorId: string, channelKey: string, seconds: number) =>
    request<Spectrum>(
      `/spectrum?sensor_id=${encodeURIComponent(sensorId)}` +
        `&channel_key=${encodeURIComponent(channelKey)}&seconds=${seconds}`,
    ),
  alarms: (unacknowledgedOnly = false) =>
    request<{ data: AlarmRow[] }>(`/alarms${unacknowledgedOnly ? '?unacknowledged_only=1' : ''}`),
  acknowledge: (id: number, note: string) =>
    request<unknown>(`/alarms/${id}/acknowledge`, {
      method: 'POST',
      body: JSON.stringify({ note }),
    }),
}

export type Severity = 'normal' | 'advisory' | 'warning' | 'critical'

export interface CurrentUser {
  name: string
  email: string
  role: string
  abilities: string[]
}

export interface Overview {
  generated_at: string
  sensors: { total: number; online: number; silent: number; unverified_profiles: number }
  alarms: {
    active: number
    critical: number
    warning: number
    advisory: number
    unacknowledged: number
    provisional: number
  }
  appliances: {
    appliance_id: string
    name: string
    status: string
    last_ingest_at: string | null
    seconds_since_ingest: number | null
  }[]
  storage: { measurements: number; oldest: string | null }
  standards: { structural_tables_status: string }
}

export interface SensorSummary {
  sensor_id: string
  appliance_id: string | null
  model: string | null
  profile_version: string | null
  verification_status: string | null
  trustworthy: boolean
  slave_id: number | null
  status: string
  mount_location: string | null
  last_measurement_at: string | null
  silent_for_seconds: number | null
  online: boolean
  channel_count: number
}

export interface ChannelInfo {
  channel_key: string
  group_key: string
  label: string
  quantity: string
  unit: string
  value_class: string
  register_address: number | null
  scale: number | null
  range: { min: number | null; max: number | null }
  configured_hz: number | null
}

export interface LatestReading {
  channel_key: string
  value: number | null
  unit: string
  quality: string
  source_type: string
  at: string
}

export interface Series {
  sensor_id: string
  channel_key: string
  from: string
  to: string
  resolution: 'raw_bucketed' | 'hourly_rollup'
  data: { t: string; value: number | null; min: number | null; max: number | null; samples: number }[]
}

export interface SeriesPoint {
  t: number
  v: number | null
  lo: number | null
  hi: number | null
}

export interface MultiSeries {
  sensor_id: string
  from: string
  to: string
  resolution: 'raw_bucketed' | 'hourly_rollup'
  bucket_seconds: number
  series: Record<string, SeriesPoint[]>
}

export interface AlarmRow {
  id: number
  name: string | null
  sensor_id: number
  channel_key: string
  level: Severity
  peak_level: Severity
  state: string
  value: number | null
  peak_value: number | null
  threshold: number | null
  unit: string | null
  raised_at: string | null
  cleared_at: string | null
  acknowledged_at: string | null
  provisional: boolean
  actionable: boolean
  thresholds_confirmed_by: string | null
}

export interface Spectrum {
  sensor_id: string
  channel_key: string
  unit: string | null
  window_seconds: number
  verification_status: string | null
  analysis: {
    samples: number
    samples_available: number
    /** >1 means the window was thinned to bound cost; surfaced, never silent. */
    decimation: number
    sample_hz: number | null
    jitter_ms: number | null
    span_seconds: number
    defensible_max_hz?: number
    nyquist_hz?: number
    requested_hz?: number
    allowed: boolean
    explanation: string
    spectrum: {
      frequencies: number[]
      power: number[]
      min_hz: number
      detrended: boolean
      /** Bottom bins barred from being reported: drift is not vibration. */
      trend_bins_excluded: number
      lowest_reportable_hz: number
      peak_hz: number
      peak_power: number
      false_alarm_probability: number
      /** False means the tallest bar is noise and must not be read as a finding. */
      peak_significant: boolean
    } | null
  }
  device_reported: {
    channel_key: string
    unit: string
    mean_hz: number
    min_hz: number
    max_hz: number
    samples: number
    note: string
  } | null
}
