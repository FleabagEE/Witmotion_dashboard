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
