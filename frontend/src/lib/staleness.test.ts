import { describe, expect, it } from 'vitest'
import { freshness, STALE_AFTER_MS } from './staleness'

const NOW = 1_000_000

describe('kiosk freshness', () => {
  it('is fresh when every reading is recent', () => {
    const result = freshness([{ value: 1, at: NOW - 1000 }, { value: 2, at: NOW - 500 }], NOW)

    expect(result.stale).toBe(false)
    expect(result.ageMs).toBe(1000)
  })

  it('ages by the stalest tile, not the freshest', () => {
    // A display is only as current as its oldest figure. Taking the newest
    // would let three frozen tiles hide behind one that still updates.
    const result = freshness(
      [{ value: 1, at: NOW - 60_000 }, { value: 2, at: NOW - 100 }],
      NOW,
    )

    expect(result.ageMs).toBe(60_000)
    expect(result.stale).toBe(true)
  })

  it('goes stale past the threshold', () => {
    expect(freshness([{ value: 1, at: NOW - STALE_AFTER_MS - 1 }], NOW).stale).toBe(true)
    expect(freshness([{ value: 1, at: NOW - STALE_AFTER_MS + 1 }], NOW).stale).toBe(false)
  })

  it('treats a missing reading as stale rather than absent', () => {
    // The failure this guards: a tile with no data showing a dash while the
    // header still says "live", so the screen looks healthy.
    const result = freshness([{ value: 1, at: NOW - 100 }, null], NOW)

    expect(result.stale).toBe(true)
    expect(result.ageMs).toBe(Number.POSITIVE_INFINITY)
  })

  it('is stale when there are no readings at all', () => {
    expect(freshness([], NOW).stale).toBe(true)
  })

  it('goes stale as the clock advances, with no new readings', () => {
    // The property that needed a ticking clock in the component: without one a
    // frozen feed keeps whatever age it had when the last frame arrived, and
    // never becomes stale at all.
    const readings = [{ value: 1, at: NOW }]

    expect(freshness(readings, NOW + 1000).stale).toBe(false)
    expect(freshness(readings, NOW + 30_000).stale).toBe(true)
  })
})
