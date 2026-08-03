import { useEffect, useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { api, getToken, setToken, type CurrentUser } from './lib/api'
import { Login } from './pages/Login'
import { Overview } from './pages/Overview'
import { Live } from './pages/Live'
import { SensorDetail } from './pages/SensorDetail'
import { Signal } from './pages/Signal'
import { Alarms } from './pages/Alarms'

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: true } },
})

function Nav({ user, onSignOut }: { user: CurrentUser; onSignOut: () => void }) {
  const { pathname } = useLocation()
  const links = [
    { to: '/', label: 'Live' },
    { to: '/system', label: 'System' },
    { to: '/signal', label: 'Signal' },
    { to: '/alarms', label: 'Alarms' },
  ]

  return (
    <header className="border-b border-line bg-panel">
      <div className="mx-auto flex max-w-[1800px] items-center justify-between px-4 py-3">
        <div className="flex items-center gap-6">
          <Link to="/" className="text-sm font-semibold tracking-wide">
            QuakeVault
          </Link>
          <nav className="flex gap-1">
            {links.map((l) => (
              <Link
                key={l.to}
                to={l.to}
                className={`rounded px-2 py-1 text-sm ${
                  pathname === l.to ? 'bg-panel-2 text-ink' : 'text-ink-dim hover:text-ink'
                }`}
              >
                {l.label}
              </Link>
            ))}
          </nav>
        </div>
        <div className="flex items-center gap-3 text-xs text-ink-dim">
          <span>
            {user.name} · {user.role}
          </span>
          <button onClick={onSignOut} className="rounded border border-line px-2 py-1 hover:text-ink">
            Sign out
          </button>
        </div>
      </div>
    </header>
  )
}

export default function App() {
  const [user, setUser] = useState<CurrentUser | null>(null)
  const [checking, setChecking] = useState(true)

  useEffect(() => {
    if (!getToken()) {
      setChecking(false)
      return
    }
    api
      .me()
      .then(setUser)
      .catch(() => setToken(null))
      .finally(() => setChecking(false))
  }, [])

  function signOut() {
    setToken(null)
    setUser(null)
    queryClient.clear()
  }

  if (checking) return null
  if (!user) return <Login onSignedIn={setUser} />

  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Nav user={user} onSignOut={signOut} />
        <main className="mx-auto max-w-[1800px] px-4 py-5">
          <Routes>
            <Route path="/" element={<Live />} />
            <Route path="/system" element={<Overview />} />
            <Route path="/sensors/:sensorId" element={<SensorDetail />} />
            <Route path="/signal" element={<Signal />} />
            <Route path="/alarms" element={<Alarms user={user} />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
