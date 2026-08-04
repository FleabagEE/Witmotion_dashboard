import { useEffect, useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { api, getToken, setToken, type CurrentUser } from './lib/api'
import { Login } from './pages/Login'
import { Overview } from './pages/Overview'
import { Live } from './pages/Live'
import { Tilt } from './pages/Tilt'
import { SensorDetail } from './pages/SensorDetail'
import { Signal } from './pages/Signal'
import { Kiosk } from './pages/Kiosk'
import { Alarms } from './pages/Alarms'
import { Thresholds } from './pages/Thresholds'
import { Users } from './pages/Users'
import { Events } from './pages/Events'

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: true } },
})

function Nav({ user, onSignOut }: { user: CurrentUser; onSignOut: () => void }) {
  const { pathname } = useLocation()

  // Thresholds are visible to everyone who can read - an operator who cannot
  // see the limit cannot judge whether an alarm matters. The page itself
  // decides what is editable, and the server decides again.
  const links = [
    { to: '/', label: 'Movement' },
    { to: '/live', label: 'Live' },
    { to: '/system', label: 'System' },
    { to: '/signal', label: 'Signal' },
    { to: '/alarms', label: 'Alarms' },
    { to: '/thresholds', label: 'Thresholds' },
    { to: '/events', label: 'History' },
  ]

  // Administration is only shown to those who can use it. The server refuses
  // the requests regardless, so this is decluttering rather than a control.
  if (user.abilities.includes('administer')) {
    links.push({ to: '/users', label: 'Users' })
  }

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

  // A kiosk session gets the wall display and nothing else - no navigation, no
  // sign-out, no route to anywhere. The token can only read, so this is
  // presentation rather than the security boundary, but a screen in a corridor
  // should not offer controls it would refuse anyway.
  if (user.role === 'kiosk') {
    return (
      <QueryClientProvider client={queryClient}>
        <Kiosk />
      </QueryClientProvider>
    )
  }

  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Nav user={user} onSignOut={signOut} />
        <main className="mx-auto max-w-[1800px] px-4 py-5">
          <Routes>
            {/* Movement is the landing page: this appliance monitors settlement.
                Live stays reachable for commissioning checks. */}
            <Route path="/" element={<Tilt />} />
            <Route path="/live" element={<Live />} />
            <Route path="/system" element={<Overview />} />
            <Route path="/sensors/:sensorId" element={<SensorDetail />} />
            <Route path="/signal" element={<Signal />} />
            <Route path="/kiosk" element={<Kiosk />} />
            <Route path="/alarms" element={<Alarms user={user} />} />
            <Route path="/thresholds" element={<Thresholds user={user} />} />
            <Route path="/users" element={<Users user={user} />} />
            <Route path="/events" element={<Events />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
