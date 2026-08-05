import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { EventRow } from '../lib/api'

/**
 * The record of what happened, and what it must not leave out.
 *
 * The property that matters is the one about absence. An operator cannot read
 * the audit trail, and a list that quietly omitted it would let somebody
 * conclude nothing had happened when in fact they were not allowed to see it.
 * So the page says a second record exists rather than silently serving half of
 * one.
 *
 * The other is that a threshold change shows only the fields that moved. An
 * edit writes the whole definition on both sides, and printing all of it buries
 * the one number that changed among twenty that did not - which is the number
 * an investigation is looking for.
 */

const state = vi.hoisted(() => ({
  auditVisible: true,
  rows: [] as EventRow[],
}))

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  api: {
    events: () => Promise.resolve({
      generated_at: '2026-08-05T00:00:00Z',
      window_days: 30,
      audit_visible: state.auditVisible,
      data: state.rows,
    }),
  },
}))

const { Events } = await import('./Events')

const alarm = (over: Partial<EventRow> = {}): EventRow => ({
  kind: 'alarm',
  at: '2026-08-04 23:03:38',
  title: 'Tilt movement from baseline',
  level: 'critical',
  state: 'active',
  sensor: 'SENSOR-001',
  channel_key: 'tilt_deviation',
  value: 35.3,
  threshold: 3,
  unit: 'deg',
  cleared_at: null,
  acknowledged_at: null,
  provisional: false,
  ...over,
})

const change = (over: Partial<EventRow> = {}): EventRow => ({
  kind: 'audit',
  at: '2026-08-04 22:00:00',
  title: 'Tilt threshold changed',
  action: 'alarm_definition.updated',
  actor: 'Site Administrator',
  result: 'success',
  before: { critical_at: 3, warning_at: 0.5, latching: true },
  after: { critical_at: 25, warning_at: 0.5, latching: true, reason: 'fewer alarms' },
  ...over,
})

function show() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <Events />
    </QueryClientProvider>,
  )
}

describe('Events', () => {
  beforeEach(() => {
    state.auditVisible = true
    state.rows = [alarm(), change()]
  })

  it('shows both records together', async () => {
    // The interesting answer is often that somebody changed a threshold an hour
    // before the alarms stopped, which is invisible if they are read apart.
    show()

    expect(await screen.findByText('Tilt movement from baseline')).toBeInTheDocument()
    expect(screen.getByText('Tilt threshold changed')).toBeInTheDocument()
  })

  it('tells a reader who cannot see the audit trail that it exists', async () => {
    state.auditVisible = false
    state.rows = [alarm()]

    show()

    expect(await screen.findByText(/your role cannot read them/i)).toBeInTheDocument()
  })

  it('says nothing about the audit trail to somebody who can read it', async () => {
    show()

    await screen.findByText('Tilt threshold changed')
    expect(screen.queryByText(/your role cannot read them/i)).not.toBeInTheDocument()
  })

  it('shows only the fields a change actually moved', async () => {
    show()

    // critical_at moved; warning_at and latching did not and must not be listed.
    expect(await screen.findByText('critical_at')).toBeInTheDocument()
    expect(screen.queryByText('latching')).not.toBeInTheDocument()
    expect(screen.queryByText('warning_at')).not.toBeInTheDocument()
  })

  it('shows the old value and the new one', async () => {
    show()

    expect(await screen.findByText('3')).toBeInTheDocument()
    expect(screen.getByText('25')).toBeInTheDocument()
  })

  it('shows the stated reason', async () => {
    show()

    expect(await screen.findByText(/fewer alarms/)).toBeInTheDocument()
  })

  it('marks an alarm that reached nobody', async () => {
    state.rows = [alarm({ provisional: true })]
    show()

    expect(await screen.findByText('not sent')).toBeInTheDocument()
  })

  it('shows the peak against the limit', async () => {
    show()

    expect(await screen.findByText(/35\.300/)).toBeInTheDocument()
    expect(screen.getByText(/3\.000/)).toBeInTheDocument()
  })

  it('says when an alarm cleared without anybody acknowledging it', async () => {
    state.rows = [alarm({ cleared_at: '2026-08-04 23:30:00', acknowledged_at: null })]
    show()

    expect(await screen.findByText(/cleared without acknowledgement/i)).toBeInTheDocument()
  })

  it('says plainly when the window holds nothing', async () => {
    state.rows = []
    show()

    expect(await screen.findByText(/nothing recorded in this window/i)).toBeInTheDocument()
  })
})
