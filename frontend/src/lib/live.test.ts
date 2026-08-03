import { describe, expect, it, vi, beforeEach } from 'vitest'

/**
 * The connection badge, which once lied.
 *
 * It was set on the first frame and never cleared, so during an outage it kept
 * claiming "websocket" while the chart was actually being served by one-second
 * polling. The data stayed correct; the label describing how fresh it was did
 * not - which on a monitoring appliance is the worse of the two failures.
 *
 * These pin the property that fixed it: state comes from the socket, not from
 * frames arriving. A frame only proves the socket was up when it was sent.
 */

type Handler = (s: { previous: string; current: string }) => void

class FakeConnection {
  state = 'initialized'
  private handlers: Handler[] = []

  bind(event: string, handler: Handler) {
    if (event === 'state_change') this.handlers.push(handler)
  }

  unbind(_event: string, handler: Handler) {
    this.handlers = this.handlers.filter((h) => h !== handler)
  }

  moveTo(next: string) {
    const previous = this.state
    this.state = next
    this.handlers.forEach((h) => h({ previous, current: next }))
  }

  get handlerCount() {
    return this.handlers.length
  }

  reset() {
    this.handlers = []
    this.state = 'initialized'
  }
}

const connection = new FakeConnection()

vi.mock('laravel-echo', () => ({
  default: class {
    connector = { pusher: { connection } }
    channel() {
      return { listen: () => {}, stopListening: () => {} }
    }
    leaveChannel() {}
  },
}))
vi.mock('pusher-js', () => ({ default: class {} }))

// Loaded after the mocks so the module picks them up.
const { subscribeToConnectionState } = await import('./live')

beforeEach(() => {
  // The Echo client is memoised at module level, so handlers registered by an
  // earlier test survive into the next one. Cleared here rather than asserting
  // on a delta, so the teardown test measures teardown rather than arithmetic.
  connection.reset()
  vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key')
})

describe('connection state', () => {
  it('reports the state it is already in, not just the next change', () => {
    // Subscribing after the socket connected must not leave the badge showing
    // "polling" until something happens to change it.
    connection.state = 'connected'
    const seen: string[] = []

    subscribeToConnectionState((s) => seen.push(s))

    expect(seen).toEqual(['connected'])
  })

  it('goes to disconnected when the socket drops', () => {
    connection.state = 'connected'
    const seen: string[] = []
    subscribeToConnectionState((s) => seen.push(s))

    connection.moveTo('unavailable')

    expect(seen).toEqual(['connected', 'disconnected'])
  })

  it('treats every non-connected state as disconnected', () => {
    // "connecting", "unavailable" and "failed" are all states in which frames
    // are not arriving, and the badge must not imply otherwise.
    connection.state = 'connected'
    const seen: string[] = []
    subscribeToConnectionState((s) => seen.push(s))

    connection.moveTo('connecting')
    connection.moveTo('failed')

    expect(seen.slice(1)).toEqual(['disconnected', 'disconnected'])
  })

  it('recovers when the socket comes back', () => {
    connection.state = 'unavailable'
    const seen: string[] = []
    subscribeToConnectionState((s) => seen.push(s))

    connection.moveTo('connected')

    expect(seen).toEqual(['disconnected', 'connected'])
  })

  it('unsubscribes cleanly', () => {
    connection.state = 'connected'
    const stop = subscribeToConnectionState(() => {})
    expect(connection.handlerCount).toBe(1)

    stop()

    // A page that mounts and unmounts repeatedly would otherwise accumulate
    // handlers and keep updating state for components that are gone.
    expect(connection.handlerCount).toBe(0)
  })

  it('reports disconnected when there is no websocket configured at all', async () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '')
    vi.resetModules()
    const fresh = await import('./live')

    const seen: string[] = []
    fresh.subscribeToConnectionState((s) => seen.push(s))

    // Without a key there is no socket. Saying "connected" would be the same
    // lie the original bug told.
    expect(seen).toEqual(['disconnected'])
  })
})
