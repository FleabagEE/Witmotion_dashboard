import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { DeliveryHealth } from '../lib/api'
import { DeliveryBanner } from './DeliveryBanner'

/**
 * The banner exists because of one morning: sixteen hours of database outage,
 * every sensor healthy, no reading delivered, and a dashboard that would have
 * shown yesterday's figures as though they were now.
 *
 * Two things it must never do. It must not cry wolf when the spool is simply
 * being patient — an operator taught to ignore this will ignore it on the day
 * the forwarder is genuinely dead. And it must never let a stale figure pass
 * for a current one.
 */

const delivery = (over: Partial<DeliveryHealth> = {}): DeliveryHealth => ({
  state: 'pass',
  summary: 'Readings are being delivered; 12 waiting.',
  action: null,
  backlog: 12,
  dead_letters: 0,
  delivered_last_cycle: 200,
  reported_at: '2026-08-06T16:40:00Z',
  age_seconds: 2,
  ...over,
})

describe('DeliveryBanner', () => {
  it('says nothing at all when readings are being delivered', () => {
    // A banner that is always on screen is furniture, and furniture is not read.
    const { container } = render(<DeliveryBanner delivery={delivery()} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('renders nothing rather than crashing before the first response', () => {
    const { container } = render(<DeliveryBanner delivery={undefined} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('reassures rather than alarms when the backlog is merely large', () => {
    render(<DeliveryBanner delivery={delivery({
      state: 'warn',
      summary: '187,671 reading(s) are waiting to be written. They are safe on disk.',
      backlog: 187671,
    })} />)

    expect(screen.getByText(/Readings are behind/)).toBeInTheDocument()
    expect(screen.getByText(/safe on disk/)).toBeInTheDocument()
    expect(screen.getByText(/187,671 waiting/)).toBeInTheDocument()
  })

  it('treats a stopped forwarder as the serious case', () => {
    render(<DeliveryBanner delivery={delivery({
      state: 'fail',
      summary: 'The forwarder stopped reporting 3 hours ago.',
      age_seconds: 10800,
    })} />)

    expect(screen.getByText(/not being delivered/)).toBeInTheDocument()
    // The point of the whole component: do not let stale figures read as live.
    expect(screen.getByText(/older than it looks/)).toBeInTheDocument()
  })

  it('gives the command that frees parked readings', () => {
    // 31,307 readings once sat behind a number with no action beside it.
    render(<DeliveryBanner delivery={delivery({
      state: 'warn',
      summary: '31,307 reading(s) are parked past the retry ceiling.',
      action: 'qv-spool retry-dead-letters --confirm',
      backlog: 31307,
      dead_letters: 31307,
    })} />)

    expect(screen.getByText('qv-spool retry-dead-letters --confirm')).toBeInTheDocument()
    expect(screen.getByText(/31,307 parked/)).toBeInTheDocument()
  })

  it('does not claim readings are parked when none are', () => {
    render(<DeliveryBanner delivery={delivery({ state: 'warn', backlog: 500, dead_letters: 0 })} />)

    expect(screen.queryByText(/parked/)).not.toBeInTheDocument()
  })

  it('shows an unknown delivery state without pretending it is healthy', () => {
    render(<DeliveryBanner delivery={delivery({
      state: 'unknown',
      summary: 'The forwarder has not reported.',
      backlog: null,
      dead_letters: null,
    })} />)

    expect(screen.getByText(/Delivery state unknown/)).toBeInTheDocument()
  })
})
