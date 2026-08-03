import type { SeriesPoint } from './api'
import type { LiveFrame } from './live'

/**
 * Merges websocket frames onto the end of the stored series.
 *
 * Two sources describe the same readings. The stored series is the record,
 * arriving through the spool and the ingestion API about a second behind. The
 * websocket frames are the same readings seconds earlier, and lossy by design.
 *
 * The rule that keeps them from contradicting each other: a live frame is only
 * ever appended *after* the newest stored point. Every reading eventually
 * arrives on the durable path, so without that guard each one would be drawn
 * twice - once when it was live and again when its recorded copy landed, at a
 * marginally different bucket boundary.
 *
 * Extracted from the page so it can be tested directly. It is the piece most
 * able to be quietly wrong: a duplicate looks like a spike, and a spike on a
 * vibration chart looks like an event.
 */
export function mergeLiveFrames(
  stored: Record<string, SeriesPoint[]> | undefined,
  frames: LiveFrame[],
): Record<string, SeriesPoint[]> | undefined {
  if (!stored) return undefined
  if (frames.length === 0) return stored

  const merged: Record<string, SeriesPoint[]> = {}

  for (const [key, points] of Object.entries(stored)) {
    const lastStored = points.length ? points[points.length - 1].t : 0
    const extra: SeriesPoint[] = []

    for (const frame of frames) {
      const value = frame.values[key]
      if (value !== undefined && frame.t > lastStored) {
        extra.push({ t: frame.t, v: value, lo: value, hi: value })
      }
    }

    merged[key] = extra.length ? [...points, ...extra] : points
  }

  return merged
}
