import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { CurrentUser, ManagedUser } from '../lib/api'

/**
 * Accounts, and the things this page must say out loud.
 *
 * The server enforces every rule independently, so what is tested here is not
 * authorisation - it is whether the page tells somebody the truth before they
 * act. Two cases matter:
 *
 *   - Setting a password signs the person out everywhere. An administrator who
 *     does not know that will assume the tablet in the site office is still
 *     logged in.
 *   - One administrator is a single point of failure. On an appliance in a
 *     plant room, losing that account means nobody can change a threshold
 *     without shell access - and it is worth saying before it happens.
 */

const state = vi.hoisted(() => ({
  users: [] as ManagedUser[],
  updated: null as { id: number; body: Record<string, unknown> } | null,
  reset: null as { id: number; password: string } | null,
}))

vi.mock('../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api')>()),
  api: {
    users: () => Promise.resolve({ data: state.users }),
    roles: () => Promise.resolve({
      data: [
        { role: 'viewer', abilities: ['read'] },
        { role: 'operator', abilities: ['read', 'acknowledge'] },
        { role: 'administrator', abilities: ['read', 'administer'] },
      ],
    }),
    updateUser: (id: number, body: Record<string, unknown>) => {
      state.updated = { id, body }
      return Promise.resolve({ data: state.users[0] })
    },
    resetPassword: (id: number, password: string) => {
      state.reset = { id, password }
      return Promise.resolve({ data: state.users[0], sessions_revoked: 2 })
    },
    createUser: () => Promise.resolve({ data: state.users[0] }),
  },
}))

const { Users } = await import('./Users')

const account = (over: Partial<ManagedUser> = {}): ManagedUser => ({
  id: 1,
  name: 'Site Administrator',
  email: 'admin@quakelogic.net',
  role: 'administrator',
  abilities: ['read', 'administer'],
  active: true,
  last_login_at: null,
  created_at: null,
  ...over,
})

const admin: CurrentUser = {
  name: 'Site Administrator',
  email: 'admin@quakelogic.net',
  role: 'administrator',
  abilities: ['read', 'acknowledge', 'configure', 'audit', 'administer'],
}

function show(who: CurrentUser = admin) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <Users user={who} />
    </QueryClientProvider>,
  )
}

describe('Users', () => {
  beforeEach(() => {
    state.users = [account()]
    state.updated = null
    state.reset = null
  })

  it('refuses the page to somebody without administer', async () => {
    show({ ...admin, role: 'engineer', abilities: ['read', 'configure'] })

    expect(await screen.findByText(/cannot manage accounts/i)).toBeInTheDocument()
  })

  it('warns when there is only one administrator', async () => {
    // Worth saying before it happens rather than after.
    show()

    expect(await screen.findByText(/one active administrator/i)).toBeInTheDocument()
  })

  it('stops warning once a second administrator exists', async () => {
    state.users = [account(), account({ id: 2, email: 'backup@quakelogic.net' })]
    show()

    await screen.findAllByText(/backup@quakelogic.net/)
    expect(screen.queryByText(/one active administrator/i)).not.toBeInTheDocument()
  })

  it('marks which account is yours', async () => {
    show()

    expect(await screen.findByText('you')).toBeInTheDocument()
  })

  it('says a password change signs the person out everywhere', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: /set password/i }))

    expect(await screen.findByText(/signs this person out everywhere/i)).toBeInTheDocument()
  })

  it('refuses a password under twelve characters', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: /set password/i }))
    fireEvent.change(await screen.findByPlaceholderText(/at least 12 characters/i), {
      target: { value: 'short' },
    })

    expect(screen.getByRole('button', { name: /set and sign out/i })).toBeDisabled()
    expect(state.reset).toBeNull()
  })

  it('reports how many sessions a reset closed', async () => {
    show()

    fireEvent.click(await screen.findByRole('button', { name: /set password/i }))
    fireEvent.change(await screen.findByPlaceholderText(/at least 12 characters/i), {
      target: { value: 'a-long-enough-password' },
    })
    fireEvent.click(screen.getByRole('button', { name: /set and sign out/i }))

    expect(await screen.findByText(/2 session\(s\) signed out/i)).toBeInTheDocument()
  })

  it('explains what each role may do', async () => {
    // Nobody should have to infer what "operator" means from its name.
    state.users = [account({ id: 3, role: 'operator', email: 'op@example.com' })]
    show()

    expect(await screen.findByText(/acknowledges alarms/i)).toBeInTheDocument()
  })

  it('surfaces a refusal from the server', async () => {
    // The last-administrator guard lives on the server. When it fires, the
    // reason has to reach the person who tried.
    const { api } = await import('../lib/api')
    vi.spyOn(api, 'updateUser').mockRejectedValue(
      new Error('This is the only active administrator. Promote somebody else first.'),
    )

    show()
    fireEvent.click(await screen.findByRole('button', { name: /deactivate/i }))

    await waitFor(() =>
      expect(screen.getByText(/only active administrator/i)).toBeInTheDocument(),
    )
  })
})
