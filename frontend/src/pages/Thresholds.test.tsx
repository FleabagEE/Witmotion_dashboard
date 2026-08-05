import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { AlarmDefinitionRow, CurrentUser } from '../lib/api'

/**
 * The page that decides when a structure is called unsafe.
 *
 * Three properties matter more than layout, and each protects against a failure
 * that would look like nothing going wrong:
 *
 *   - An operator must not be able to change a threshold. Raising one silences
 *     an alarm and leaves the dashboard looking healthy, which is
 *     indistinguishable from a structure that stopped moving.
 *   - A change must be impossible to save without a written reason, because a
 *     threshold nobody explained is a threshold nobody can review.
 *   - Editing the numbers must visibly strip the engineer's sign-off. A
 *     signature given for 3 degrees is not a signature for 12, and the page
 *     will look calmer afterwards - the reason must not be a surprise.
 */

const state = vi.hoisted(() => ({
  definitions: [] as AlarmDefinitionRow[],
  updated: null as { id: number; body: Record<string, unknown> } | null,
  confirmationCleared: false,
}))

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  api: {
    alarmDefinitions: () => Promise.resolve({ data: state.definitions }),
    updateThreshold: (id: number, body: Record<string, unknown>) => {
      state.updated = { id, body }
      return Promise.resolve({
        data: state.definitions[0],
        confirmation_cleared: state.confirmationCleared,
      })
    },
    confirmThreshold: () => Promise.resolve({ data: state.definitions[0] }),
  },
}))

const { Thresholds } = await import('./Thresholds')

const definition = (over: Partial<AlarmDefinitionRow> = {}): AlarmDefinitionRow => ({
  id: 1,
  key: 'tilt-deviation-SENSOR-001',
  name: 'Tilt movement from baseline',
  sensor_id: 'SENSOR-001',
  channel_key: 'tilt_deviation',
  quantity: null,
  condition_type: 'high_threshold',
  unit: 'deg',
  advisory_at: null,
  warning_at: 0.5,
  critical_at: 3,
  hysteresis: 0.1,
  persistence_seconds: 3600,
  clear_seconds: 21600,
  debounce_seconds: 600,
  latching: true,
  enabled: true,
  thresholds_confirmed_by: null,
  thresholds_confirmed_at: null,
  thresholds_reference: null,
  actionable: false,
  ...over,
})

const user = (role: string, abilities: string[]): CurrentUser => ({
  name: 'Test', email: 't@example.com', role, abilities,
})

function show(who: CurrentUser) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <Thresholds user={who} />
    </QueryClientProvider>,
  )
}

describe('Thresholds', () => {
  beforeEach(() => {
    state.definitions = [definition()]
    state.updated = null
    state.confirmationCleared = false
  })

  it('lets anyone read the numbers', async () => {
    // An operator who cannot see the limit cannot judge whether an alarm matters.
    show(user('operator', ['read', 'acknowledge']))

    expect(await screen.findByDisplayValue('3')).toBeInTheDocument()
    expect(screen.getByDisplayValue('0.5')).toBeInTheDocument()
  })

  it('does not let an operator change them', async () => {
    show(user('operator', ['read', 'acknowledge']))

    const critical = await screen.findByDisplayValue('3')
    expect(critical).toBeDisabled()
    expect(screen.getByText(/can view thresholds but not change them/i)).toBeInTheDocument()
  })

  it('does not let an engineer change them either', async () => {
    // Engineers hold `configure`, which covers acquisition. A threshold decides
    // when a structure is called unsafe, and that is a step above.
    show(user('engineer', ['read', 'acknowledge', 'configure']))

    expect(await screen.findByDisplayValue('3')).toBeDisabled()
  })

  it('lets an administrator edit', async () => {
    show(user('administrator', ['read', 'acknowledge', 'configure', 'audit', 'administer']))

    expect(await screen.findByDisplayValue('3')).toBeEnabled()
  })

  it('refuses to save without a reason', async () => {
    show(user('administrator', ['read', 'administer']))

    fireEvent.change(await screen.findByDisplayValue('3'), { target: { value: '0.25' } })

    const save = await screen.findByRole('button', { name: /^save$/i })
    expect(save).toBeDisabled()
    expect(state.updated).toBeNull()
  })

  it('sends the reason with the change', async () => {
    show(user('administrator', ['read', 'administer']))

    fireEvent.change(await screen.findByDisplayValue('3'), { target: { value: '0.25' } })
    fireEvent.change(screen.getByPlaceholderText(/why are you changing this/i), {
      target: { value: 'Engineer set H/250' },
    })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => expect(state.updated).not.toBeNull())
    expect(state.updated?.body.reason).toBe('Engineer set H/250')
    expect(state.updated?.body.critical_at).toBe(0.25)
  })

  it('says when a change stripped the sign-off', async () => {
    // The page will look calmer afterwards, and that must not be a surprise.
    state.definitions = [definition({
      thresholds_confirmed_by: 'A. Engineer', actionable: true,
    })]
    state.confirmationCleared = true

    show(user('administrator', ['read', 'administer']))

    fireEvent.change(await screen.findByDisplayValue('3'), { target: { value: '12' } })
    fireEvent.change(screen.getByPlaceholderText(/why are you changing this/i), {
      target: { value: 'fewer alarms' },
    })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    expect(await screen.findByText(/no longer applies/i)).toBeInTheDocument()
  })

  it('says plainly when an alarm will never be sent', async () => {
    show(user('operator', ['read']))

    expect(await screen.findByText('shown, not sent')).toBeInTheDocument()
    expect(screen.getByText(/never sent to anyone/i)).toBeInTheDocument()
  })

  it('shows who stands behind a confirmed threshold', async () => {
    state.definitions = [definition({
      thresholds_confirmed_by: 'R. Structural',
      thresholds_reference: 'Report GR-2026-114',
      actionable: true,
    })]

    show(user('operator', ['read']))

    expect(await screen.findByText('confirmed')).toBeInTheDocument()
    expect(screen.getByText(/R\. Structural/)).toBeInTheDocument()
    expect(screen.getByText(/Report GR-2026-114/)).toBeInTheDocument()
  })
})
