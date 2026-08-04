import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { AlarmBanner } from './AlarmBanner'
import { api, type AlarmRow } from '../lib/api'

/**
 * What the banner must and must not say.
 *
 * The one that matters is the last: a raised alarm that reached nobody looks
 * identical on a dashboard to one that paged the duty engineer, and those are
 * very different situations for whoever is reading it at 3 a.m.
 */

const alarm = (over: Partial<AlarmRow> = {}): AlarmRow => ({
  id: 1,
  name: 'Tilt movement from baseline',
  sensor_id: 1,
  channel_key: 'tilt_deviation',
  level: 'critical',
  peak_level: 'critical',
  state: 'active',
  value: 0.1,
  peak_value: 4.7,
  threshold: 3,
  unit: 'deg',
  raised_at: '2026-08-04T10:00:00Z',
  cleared_at: null,
  acknowledged_at: null,
  provisional: false,
  actionable: true,
  thresholds_confirmed_by: 'A. Engineer',
  ...over,
})

function show(rows: AlarmRow[]) {
  vi.spyOn(api, 'alarms').mockResolvedValue({ data: rows })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter><AlarmBanner /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AlarmBanner', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('says nothing when nothing is wrong', async () => {
    const { container } = show([])
    await new Promise((r) => setTimeout(r, 20))
    expect(container.textContent).toBe('')
  })

  it('ignores cleared and retired alarms', async () => {
    const { container } = show([
      alarm({ state: 'cleared' }),
      alarm({ id: 2, state: 'retired' }),
    ])
    await new Promise((r) => setTimeout(r, 20))
    expect(container.textContent).toBe('')
  })

  it('shows the peak against the limit, not the current value', async () => {
    // By the time anyone looks, the current reading is usually back to nothing.
    show([alarm()])
    expect(await screen.findByText(/4\.700/)).toBeInTheDocument()
    expect(screen.getByText(/limit 3\.000/)).toBeInTheDocument()
  })

  it('leads with the worst level when several are active', async () => {
    show([alarm({ level: 'warning' }), alarm({ id: 2, level: 'critical' })])
    expect(await screen.findByText('critical')).toBeInTheDocument()
    expect(screen.getByText('2 active alarms')).toBeInTheDocument()
  })

  it('says when the alarm reached nobody', async () => {
    show([alarm({ provisional: true })])
    expect(await screen.findByText('nobody was notified')).toBeInTheDocument()
  })

  it('does not claim nobody was notified when the alarm was sent', async () => {
    show([alarm({ provisional: false })])
    await screen.findByText('critical')
    expect(screen.queryByText(/nobody was notified/)).not.toBeInTheDocument()
  })
})
