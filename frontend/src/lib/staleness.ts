/**
 * How old the newest reading on screen is, and whether that is too old.
 *
 * Split out of the kiosk display because it decides something an unattended
 * screen gets wrong in the worst way: a frozen feed still shows a plausible
 * number, and a plausible number on a wall is read as current. Staleness has to
 * be stated on the face of the display rather than inferred from a trace that
 * stopped moving.
 */

/** Beyond this the reading is old enough to mislead somebody reading the wall. */
export const STALE_AFTER_MS = 15_000

export interface Reading {
  value: number
  at: number
}

export interface Freshness {
  ageMs: number
  stale: boolean
}

/**
 * Age is taken from the OLDEST of the displayed readings, not the newest.
 *
 * A display showing four figures is only as current as its stalest one. Taking
 * the newest would let three frozen tiles hide behind one that still updates.
 */
export function freshness(
  readings: Array<Reading | null | undefined>,
  now: number,
): Freshness {
  const present = readings.filter((r): r is Reading => Boolean(r))

  if (present.length === 0 || present.length < readings.length) {
    // A tile with no reading at all is not fresh, and cannot be aged.
    return { ageMs: Number.POSITIVE_INFINITY, stale: true }
  }

  const ageMs = Math.max(...present.map((r) => now - r.at))

  return { ageMs, stale: ageMs > STALE_AFTER_MS }
}
