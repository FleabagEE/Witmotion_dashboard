import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type CurrentUser, type ManagedUser } from '../lib/api'
import { Empty, Panel, Pill } from '../components/ui'

/**
 * Accounts and what each may do.
 *
 * The server enforces every rule here independently. This page hides controls
 * an administrator should not use, which is a courtesy to the person, not a
 * security boundary — a hidden button still permits the request.
 *
 * Roles are not editable free text. They come from the API, with the abilities
 * each carries, so nobody has to guess what "engineer" means from its name.
 */

const ROLE_NOTES: Record<string, string> = {
  viewer: 'Reads everything. Cannot acknowledge or change anything.',
  operator: 'Reads, and acknowledges alarms. Cannot change what counts as an alarm.',
  engineer: 'Operator, plus acquisition configuration. Cannot change thresholds.',
  administrator: 'Everything, including thresholds and accounts.',
  auditor: 'Reads, and reads the audit trail. Cannot change or acknowledge.',
  kiosk: 'Read-only, for an unattended screen in a public area.',
}

function RoleSelect({
  value, roles, disabled, onChange,
}: {
  value: string
  roles: { role: string; abilities: string[] }[]
  disabled: boolean
  onChange: (v: string) => void
}) {
  return (
    <select
      value={value}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
      className="rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent disabled:opacity-50"
    >
      {roles.map((r) => (
        <option key={r.role} value={r.role}>{r.role}</option>
      ))}
    </select>
  )
}

function UserRow({
  user, roles, isSelf,
}: {
  user: ManagedUser
  roles: { role: string; abilities: string[] }[]
  isSelf: boolean
}) {
  const queryClient = useQueryClient()
  const [password, setPassword] = useState('')
  const [resetting, setResetting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const done = (message: string) => {
    setError(null); setNotice(message)
    queryClient.invalidateQueries({ queryKey: ['users'] })
  }

  const update = useMutation({
    mutationFn: (body: { role?: string; active?: boolean }) => api.updateUser(user.id, body),
    onSuccess: () => done('Saved.'),
    onError: (e: Error) => { setNotice(null); setError(e.message) },
  })

  const reset = useMutation({
    mutationFn: () => api.resetPassword(user.id, password),
    onSuccess: (r) => {
      setPassword(''); setResetting(false)
      done(`Password set. ${r.sessions_revoked} session(s) signed out.`)
    },
    onError: (e: Error) => { setNotice(null); setError(e.message) },
  })

  return (
    <li className="rounded-lg border border-line bg-panel-2 p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{user.name}</span>
            {isSelf && <Pill tone="muted">you</Pill>}
            {!user.active && <Pill tone="warn">deactivated</Pill>}
          </div>
          <div className="mt-0.5 text-xs text-ink-dim">{user.email}</div>
          <div className="mt-1 text-[11px] text-ink-dim">
            {ROLE_NOTES[user.role] ?? user.abilities.join(', ')}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <RoleSelect
            value={user.role}
            roles={roles}
            disabled={update.isPending}
            onChange={(role) => update.mutate({ role })}
          />
          <button
            onClick={() => update.mutate({ active: !user.active })}
            disabled={update.isPending}
            className="rounded border border-line px-2 py-1 text-xs hover:text-ink disabled:opacity-40"
          >
            {user.active ? 'Deactivate' : 'Reactivate'}
          </button>
          <button
            onClick={() => setResetting(!resetting)}
            className="rounded border border-line px-2 py-1 text-xs hover:text-ink"
          >
            Set password
          </button>
        </div>
      </div>

      {resetting && (
        <div className="mt-3 space-y-2 rounded border border-line p-3">
          <p className="text-xs text-ink-dim">
            Setting a password signs this person out everywhere. An account whose
            password changed but whose sessions live on has not been locked out.
          </p>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="At least 12 characters"
            className="w-full rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent"
          />
          <div className="flex gap-2">
            <button
              onClick={() => reset.mutate()}
              disabled={password.length < 12 || reset.isPending}
              className="rounded bg-accent px-3 py-1 text-xs font-medium text-shell disabled:opacity-40"
            >
              Set and sign out
            </button>
            <button
              onClick={() => { setResetting(false); setPassword('') }}
              className="rounded border border-line px-3 py-1 text-xs"
            >
              Cancel
            </button>
          </div>
        </div>
      )}

      <div className="mt-2 flex flex-wrap gap-3 text-[11px] text-ink-dim">
        <span>
          last signed in{' '}
          {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'never'}
        </span>
      </div>

      {notice && <p className="mt-2 text-xs text-ok">{notice}</p>}
      {error && <p className="mt-2 text-xs text-critical">{error}</p>}
    </li>
  )
}

function NewUser({ roles }: { roles: { role: string; abilities: string[] }[] }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', email: '', role: 'viewer', password: '' })
  const [error, setError] = useState<string | null>(null)

  const create = useMutation({
    mutationFn: () => api.createUser(form),
    onSuccess: () => {
      setOpen(false)
      setForm({ name: '', email: '', role: 'viewer', password: '' })
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e: Error) => setError(e.message),
  })

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="rounded border border-line px-3 py-1 text-xs hover:text-ink"
      >
        Add a user
      </button>
    )
  }

  const field = (key: keyof typeof form, placeholder: string, type = 'text') => (
    <input
      type={type}
      value={form[key]}
      onChange={(e) => setForm({ ...form, [key]: e.target.value })}
      placeholder={placeholder}
      className="w-full rounded border border-line bg-panel px-2 py-1 text-xs outline-none focus:border-accent"
    />
  )

  return (
    <div className="space-y-2 rounded-lg border border-line bg-panel-2 p-4">
      <div className="grid gap-2 sm:grid-cols-2">
        {field('name', 'Full name')}
        {field('email', 'Email address', 'email')}
        {field('password', 'Password, at least 12 characters', 'password')}
        <RoleSelect
          value={form.role}
          roles={roles}
          disabled={false}
          onChange={(role) => setForm({ ...form, role })}
        />
      </div>
      <p className="text-[11px] text-ink-dim">{ROLE_NOTES[form.role]}</p>
      <div className="flex gap-2">
        <button
          onClick={() => create.mutate()}
          disabled={create.isPending || form.password.length < 12 || !form.email || !form.name}
          className="rounded bg-accent px-3 py-1 text-xs font-medium text-shell disabled:opacity-40"
        >
          Create
        </button>
        <button onClick={() => setOpen(false)} className="rounded border border-line px-3 py-1 text-xs">
          Cancel
        </button>
      </div>
      {error && <p className="text-xs text-critical">{error}</p>}
    </div>
  )
}

export function Users({ user }: { user: CurrentUser }) {
  const users = useQuery({ queryKey: ['users'], queryFn: api.users })
  const roles = useQuery({ queryKey: ['roles'], queryFn: api.roles })

  if (!user.abilities.includes('administer')) {
    return (
      <Panel title="Users">
        <Empty>Your role ({user.role}) cannot manage accounts.</Empty>
      </Panel>
    )
  }

  const admins = users.data?.data.filter((u) => u.role === 'administrator' && u.active).length ?? 0

  return (
    <Panel
      title="Users"
      subtitle="Roles decide what each person may do. The server enforces them independently of this page."
      actions={roles.data ? <NewUser roles={roles.data.data} /> : undefined}
    >
      {/* Said before it becomes a problem rather than after. Losing the last
          administrator on an appliance in a plant room is not recoverable from
          the dashboard. */}
      {admins === 1 && (
        <p className="mb-3 rounded border border-advisory/40 bg-advisory/10 px-3 py-2 text-xs text-advisory">
          There is one active administrator. If that account is lost, nobody can
          change a threshold or add a user without shell access to the appliance.
          Consider a second one.
        </p>
      )}

      {users.isLoading || roles.isLoading ? (
        <Empty>Loading…</Empty>
      ) : !users.data?.data.length ? (
        <Empty>No accounts.</Empty>
      ) : (
        <ul className="space-y-3">
          {users.data.data.map((u) => (
            <UserRow
              key={u.id}
              user={u}
              roles={roles.data?.data ?? []}
              isSelf={u.email === user.email}
            />
          ))}
        </ul>
      )}
    </Panel>
  )
}
