import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

export interface LiveFrame {
  sensor_id: string
  group: string
  t: number
  quality: string
  values: Record<string, number>
}

let echo: Echo<'reverb'> | null = null

/**
 * Websocket connection to the appliance's live feed.
 *
 * The feed is a view, not a record. Frames arrive seconds ahead of the database
 * copy but may be dropped under load by design, so anything acted upon - an
 * alarm, a report, a threshold - comes from the stored series instead.
 */
export function liveConnection(): Echo<'reverb'> | null {
  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) return null
  if (echo) return echo

  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? '127.0.0.1',
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
  })

  return echo
}

/** Whether the live feed is actually delivering, as reported by the socket. */
export type LiveState = 'connected' | 'disconnected'

/**
 * Watch the real socket state.
 *
 * Deliberately not inferred from frame arrival. A frame proves the socket was
 * up when it was sent and says nothing about now, so a UI driven that way
 * latches to "connected" and keeps claiming it through an outage - describing
 * polled data as live. Pusher reconnects on its own; this reports what it is
 * actually doing.
 */
export function subscribeToConnectionState(
  onState: (state: LiveState) => void,
): () => void {
  const connection = liveConnection()
  if (!connection) {
    onState('disconnected')
    return () => {}
  }

  const pusher = connection.connector.pusher
  const handler = ({ current }: { current: string }) => {
    onState(current === 'connected' ? 'connected' : 'disconnected')
  }

  // Report the state we are already in, not just the next change to it.
  handler({ current: pusher.connection.state })
  pusher.connection.bind('state_change', handler)

  return () => pusher.connection.unbind('state_change', handler)
}

export function subscribeToSensor(
  sensorId: string,
  onFrame: (frame: LiveFrame) => void,
): () => void {
  const connection = liveConnection()
  if (!connection) return () => {}

  const channel = connection.channel(`sensor.${sensorId}`)
  channel.listen('.measurement', onFrame)

  return () => {
    channel.stopListening('.measurement', onFrame)
    connection.leaveChannel(`sensor.${sensorId}`)
  }
}
