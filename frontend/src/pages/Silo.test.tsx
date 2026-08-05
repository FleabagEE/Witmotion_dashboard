import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import type { SensorHealthRow, StructureResponse } from '../lib/api'

/**
 * The diagram and the cards both name the sensors, so assertions about the
 * cards are scoped to the cards. An unscoped match would pass on either, which
 * means it would keep passing if the card list disappeared entirely.
 */
const cards = () => within(screen.getByRole('region', { name: 'Sensors' }))

/**
 * The installation on one screen, and the separation it must preserve.
 *
 * Two questions live on this page and they must not blur. "What did the
 * structure do" is a comparison across sensors; "can these readings be
 * believed" is a property of each instrument. A dead sensor and a perfectly
 * still silo produce the same chart, so the page that answers the first must
 * not be reassured by the second.
 */

const state = vi.hoisted(() => ({
  health: null as { sensors: SensorHealthRow[]; status: string } | null,
  structure: null as StructureResponse | null,
}))

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  api: {
    sensorHealth: () => Promise.resolve(state.health),
    structure: () => Promise.resolve(state.structure),
  },
}))

const { Silo } = await import('./Silo')

const sensor = (over: Partial<SensorHealthRow> = {}): SensorHealthRow => ({
  sensor_id: 'SENSOR-001',
  position: 'top',
  role: 'monitor',
  port: '/dev/quakevault-rs485-p1',
  model: 'WTVB01-485',
  verification_status: 'verified',
  temperature: 26.4,
  gravity_magnitude: 1.0008,
  silent_for_seconds: 1,
  status: 'pass',
  checks: { reporting: { state: 'pass', detail: '60 samples' } },
  ...over,
})

function show() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter><Silo /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Silo', () => {
  beforeEach(() => {
    state.health = {
      status: 'pass',
      sensors: [
        sensor(),
        sensor({ sensor_id: 'SENSOR-002', position: 'mid' }),
        sensor({ sensor_id: 'SENSOR-003', position: 'ground', role: 'reference' }),
      ],
    }
    state.structure = {
      generated_at: '2026-08-05T19:00:00Z',
      available: true,
      window_minutes: 60,
      site: 0.02,
      reference_available: true,
      structure: { top: 0.4, mid: 0.15 },
      bending: 0.25,
      interpretation: { shape: 'bending', summary: 'The top moved more than the middle.' },
    }
  })

  it('orders the sensors top, mid, ground', async () => {
    // Reading order should match the structure, not the database.
    show()
    await screen.findAllByText('SENSOR-001')

    // Case-sensitive: the cards carry lowercase text capitalised by CSS, while
    // the movement figures above are titled "Top" and "Mid". Matching loosely
    // picked up both and compared the wrong list.
    const labels = cards().getAllByText(/^(top|mid|ground)$/).map((n) => n.textContent)
    expect(labels).toEqual(['top', 'mid', 'ground'])
  })

  it('marks which sensor is the reference', async () => {
    // Three identical panels otherwise give no clue which one is the yardstick.
    show()
    expect(await screen.findByText('reference')).toBeInTheDocument()
  })

  it('reports what the site did, not only what the structure did', async () => {
    // A ground reading that grows over months is the ground moving, and
    // subtracting it silently would hide that.
    show()
    expect(await screen.findByText('Site')).toBeInTheDocument()
    expect(screen.getByText('0.0200')).toBeInTheDocument()
  })

  it('shows bending separately from movement', async () => {
    show()
    expect(await screen.findByText('Bending')).toBeInTheDocument()
    expect(screen.getByText('+0.2500')).toBeInTheDocument()
  })

  it('states the reading of the pattern in words', async () => {
    show()
    expect(await screen.findByText(/top moved more than the middle/i)).toBeInTheDocument()
  })

  it('explains why movement is unavailable rather than showing nothing', async () => {
    state.structure = {
      generated_at: '', available: false,
      reason: 'not every sensor has a usable baseline and recent quiet data',
      missing: ['mid', 'ground'],
    }

    show()

    expect(await screen.findByText(/cannot be measured yet/i)).toBeInTheDocument()
    expect(screen.getByText(/mid, ground/)).toBeInTheDocument()
    // The sensors are still shown: they are a separate question.
    expect(cards().getByText('SENSOR-001')).toBeInTheDocument()
  })

  it('shows an unhealthy sensor even when the structure reads fine', async () => {
    // The separation that matters. A silent sensor must not be hidden by a
    // reassuring movement figure.
    state.health!.sensors[1] = sensor({
      sensor_id: 'SENSOR-002', position: 'mid', status: 'fail',
      checks: { reporting: { state: 'fail', detail: 'Silent for 900s.' } },
    })

    show()

    expect(await screen.findByText(/Silent for 900s/)).toBeInTheDocument()
    expect(cards().getByText('fault')).toBeInTheDocument()
  })

  it('warns when there is no ground reference', async () => {
    state.structure = {
      ...state.structure!,
      reference_available: false,
      warning: 'No ground reference. Site disturbance cannot be separated from structural movement.',
    }

    show()

    expect(await screen.findByText(/cannot be separated/i)).toBeInTheDocument()
  })
})
