import { useState, type FormEvent } from 'react'
import { api, setToken, type CurrentUser } from '../lib/api'

export function Login({ onSignedIn }: { onSignedIn: (user: CurrentUser) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const result = await api.login(email, password)
      setToken(result.token)
      onSignedIn(result.user)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Sign in failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center px-4">
      <form onSubmit={submit} className="w-full max-w-sm rounded-lg border border-line bg-panel p-6">
        <h1 className="text-lg font-semibold">QuakeVault</h1>
        <p className="mt-1 text-sm text-ink-dim">Structural vibration monitoring</p>

        <label className="mt-6 block text-xs uppercase tracking-wider text-ink-dim" htmlFor="email">
          Email
        </label>
        <input
          id="email"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="mt-1 w-full rounded border border-line bg-panel-2 px-3 py-2 text-sm outline-none focus:border-accent"
        />

        <label className="mt-4 block text-xs uppercase tracking-wider text-ink-dim" htmlFor="password">
          Password
        </label>
        <input
          id="password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="mt-1 w-full rounded border border-line bg-panel-2 px-3 py-2 text-sm outline-none focus:border-accent"
        />

        {error && (
          <p role="alert" className="mt-4 rounded border border-critical/40 bg-critical/10 px-3 py-2 text-sm text-critical">
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={busy}
          className="mt-6 w-full rounded bg-accent px-3 py-2 text-sm font-medium text-shell disabled:opacity-50"
        >
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  )
}
