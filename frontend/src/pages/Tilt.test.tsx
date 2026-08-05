import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { TiltResponse } from '../lib/api'

/**
 * The settlement page, and four things it must not do.
 *
 *   - It must not hide the readings when no baseline exists. It did: 21 hours
 *     of good tilt and temperature rendered as a single orange panel, because
 *     the whole page sat behind the missing-baseline notice.
 *   - It must say how much of the averaging window it threw away. A figure
 *     averaged over nine surviving minutes deserves less trust than one over
 *     sixty, and the number alone does not say which it is.
 *   - Its figures must reconcile. Current tilt, baseline and movement were once
 *     three numbers that could not all be true, because one came from the
 *     disturbance-filtered average and another from the raw chart bucket.
 *   - It must say that it cannot report direction. The sensor transmits
 *     unsigned magnitudes; a single movement figure invites "which way", and
 *     an instrument that cannot answer should say so where the number is read.
 */

const state = vi.hoisted(() => ({ response: null as TiltResponse | null }))

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  api: { tilt: () => Promise.resolve(state.response) },
}))

vi.mock('echarts-for-react', () => ({ default: () => <div data-testid="chart" /> }))

const { Tilt } = await import('./Tilt')

function response(over: Record<string, unknown> = {}): TiltResponse {
  return {
    generated_at: '2026-08-05T00:00:00Z',
    window_days: 7,
    sensors: [
      {
        sensor_id: 'SENSOR-001',
        verification_status: 'verified',
        baseline: {
          tilt: 0.6615, roll: 0.12, pitch: -0.65, temp: 25.56, samples: 5226,
          captured_at: '2026-08-04T16:24:40Z', resolution_deg: 0.00487,
        },
        mounting: null,
        deviation: {
          available: true,
          method: 'gravity_vector',
          samples: 90,
          disturbed_minutes: 51,
          window_minutes: 60,
          tilt_now: 0.6417,
          roll_now: 0.1,
          pitch_now: -0.6,
          temperature_now: 25.64,
          baseline_tilt: 0.6615,
          baseline_temp: 25.56,
          raw_deviation: -0.0198,
          thermal_component: 0,
          corrected_deviation: -0.0198,
          compensated: false,
        },
        thermal_model: null,
        series: {
          bucket: '1 hour', bucket_seconds: 3600, commissioned_at: '2026-08-04T16:24:40Z',
          points: [{
            t: 1, tilt: 0.6417, roll: 0, pitch: 0, temperature: 25.6,
            deviation: -0.0198, disturbed: false, disturbed_minutes: 0,
            total_minutes: 60, pre_commissioning: false,
          }],
        },
        ...over,
      },
    ],
  } as unknown as TiltResponse
}

function show() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <Tilt />
    </QueryClientProvider>,
  )
}

describe('Tilt', () => {
  beforeEach(() => { state.response = response() })

  it('shows the readings even with no baseline', async () => {
    // The bug: 21 hours of data rendered as one orange panel.
    state.response = response({ baseline: null, deviation: null })

    show()

    expect(await screen.findByText(/has no baseline/i)).toBeInTheDocument()
    // The notice is a banner, not a replacement for the page.
    expect(screen.getByText('Current tilt')).toBeInTheDocument()
    expect(screen.getByTestId('chart')).toBeInTheDocument()
  })

  it('says how much of the window it discarded', async () => {
    show()

    expect(await screen.findByText(/51 of 60 min discarded/i)).toBeInTheDocument()
  })

  it('reconciles current tilt, baseline and movement', async () => {
    // 0.6417 - 0.6615 = -0.0198. Three numbers that must all be true together.
    show()

    expect(await screen.findByText('-0.0198')).toBeInTheDocument()
    expect(screen.getByText('0.6417')).toBeInTheDocument()
    expect(screen.getByText(/baseline 0\.6615/)).toBeInTheDocument()
  })

  it('says it cannot report direction', async () => {
    show()

    expect(await screen.findByText(/Magnitude only/i)).toBeInTheDocument()
    expect(screen.getByText(/not which way/i)).toBeInTheDocument()
  })

  it('warns when a baseline predates gravity-vector referencing', async () => {
    state.response = response({
      deviation: { ...response().sensors[0].deviation!, method: 'reported_tilt' },
    })

    show()

    expect(await screen.findByText(/predates gravity-vector referencing/i)).toBeInTheDocument()
  })

  it('says when the reading is uncompensated for temperature', async () => {
    // Some of the movement may be thermal, and the page must not imply it is
    // not. The discarded-minutes message outranks it when both apply, which is
    // the right order: a figure built from nine minutes is the more urgent
    // caveat.
    state.response = response({
      deviation: { ...response().sensors[0].deviation!, disturbed_minutes: 0 },
    })

    show()

    expect(await screen.findByText(/uncompensated/i)).toBeInTheDocument()
  })

  it('says when too few points exist to show a trend', async () => {
    show()

    expect(await screen.findByText(/too few to show a trend yet/i)).toBeInTheDocument()
  })

  it('says plainly when no sensor is registered', async () => {
    state.response = { generated_at: '', window_days: 7, sensors: [] } as unknown as TiltResponse

    show()

    expect(await screen.findByText(/no sensors registered/i)).toBeInTheDocument()
  })
})
