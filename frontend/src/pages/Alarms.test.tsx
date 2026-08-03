import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { AlarmRow, CurrentUser } from '../lib/api'

/**
 * The alarm list, where two properties matter more than layout.
 *
 * An alarm latches until somebody acknowledges it, so by the time anyone looks
 * the current reading is usually back to nothing. Showing that reading beside a
 * CRITICAL badge and a limit it does not exceed reads as a broken dashboard
 * rather than a real event - which is exactly how the first real structural
 * alarm rendered: "critical, 0.05 mm/s, limit 3".
 *
 * And a provisional alarm has to be visibly provisional. It came from a
 * threshold table nobody has confirmed against the published standard, it sent
 * no notification, and it is not a compliance statement. An operator acting on
 * one as though it were confirmed is the failure mode that matters here.
 */

const alarms = vi.hoisted(() => ({ list: [] as AlarmRow[] }))

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  api: {
    alarms: () => Promise.resolve({ data: alarms.list }),
    acknowledge: () => Promise.resolve({}),
  },
}))

const { Alarms } = await import('./Alarms')

function alarm(overrides: Partial<AlarmRow> = {}): AlarmRow {
  return {
    id: 1,
    name: 'Structural vibration (transient)',
    sensor_id: 1,
    channel_key: 'vib_velocity_x',
    level: 'critical',
    peak_level: 'critical',
    state: 'active',
    value: 0.05,
    peak_value: 5.89,
    threshold: 3,
    unit: 'mm/s',
    raised_at: '2026-08-03T23:32:45Z',
    cleared_at: null,
    acknowledged_at: null,
    provisional: true,
    actionable: false,
    thresholds_confirmed_by: null,
    ...overrides,
  } as AlarmRow
}

const operator = {
  name: 'Op', email: 'op@test', role: 'operator', abilities: ['read', 'acknowledge'],
} as unknown as CurrentUser

function renderAlarms(user: CurrentUser = operator) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <Alarms user={user} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  alarms.list = [alarm()]
})

describe('what the figure shows', () => {
  it('shows the peak that breached the limit, not the latest reading', async () => {
    renderAlarms()

    // 5.89 is what exceeded 3. 0.05 is where the structure is now.
    expect(await screen.findByText(/5\.890/)).toBeInTheDocument()
    expect(screen.getByText(/limit 3\.000/)).toBeInTheDocument()
  })

  it('still shows the current reading, secondary', async () => {
    renderAlarms()

    expect(await screen.findByText(/now 0\.050/)).toBeInTheDocument()
  })

  it('does not repeat itself when the peak is the current reading', async () => {
    alarms.list = [alarm({ value: 5.89, peak_value: 5.89 })]
    renderAlarms()

    await screen.findByText(/5\.890/)
    expect(screen.queryByText(/now 5\.890/)).not.toBeInTheDocument()
  })

  it('falls back to the current reading when no peak was recorded', async () => {
    alarms.list = [alarm({ value: 4.2, peak_value: null })]
    renderAlarms()

    expect(await screen.findByText(/4\.200/)).toBeInTheDocument()
  })
})

describe('provisional thresholds', () => {
  it('marks a provisional alarm as provisional', async () => {
    renderAlarms()

    expect(await screen.findByText('provisional')).toBeInTheDocument()
  })

  it('says plainly that no notification was sent', async () => {
    // The operator has to know nobody was paged, or they will assume somebody
    // was and that it is being handled.
    renderAlarms()

    expect(await screen.findByText(/no notification was sent/i)).toBeInTheDocument()
  })

  it('does not mark a confirmed alarm as provisional', async () => {
    alarms.list = [alarm({ provisional: false, actionable: true, thresholds_confirmed_by: 'J. Engineer' })]
    renderAlarms()

    await screen.findByText(/5\.890/)
    expect(screen.queryByText('provisional')).not.toBeInTheDocument()
  })
})

describe('who may acknowledge', () => {
  it('offers acknowledgement to an operator', async () => {
    renderAlarms()

    expect(await screen.findByRole('button', { name: /acknowledge/i })).toBeInTheDocument()
  })

  it('does not offer it to a role without the ability', async () => {
    // Hiding the control is presentation; the API refuses regardless. But a
    // screen should not offer an action it would reject.
    const viewer = {
      name: 'V', email: 'v@test', role: 'viewer', abilities: ['read'],
    } as unknown as CurrentUser
    renderAlarms(viewer)

    await screen.findByText(/5\.890/)
    expect(screen.queryByRole('button', { name: /acknowledge/i })).not.toBeInTheDocument()
    expect(screen.getByText(/can view alarms but not acknowledge/i)).toBeInTheDocument()
  })
})

describe('an empty list', () => {
  it('says there are no alarms rather than showing nothing', async () => {
    alarms.list = []
    renderAlarms()

    expect(await screen.findByText(/no alarms/i)).toBeInTheDocument()
  })
})
