import { describe, expect, it } from 'vitest'
import { mergeLiveFrames } from './merge'
import type { SeriesPoint } from './api'
import type { LiveFrame } from './live'

function stored(...ts: number[]): Record<string, SeriesPoint[]> {
  return { accel_x: ts.map((t) => ({ t, v: t / 1000, lo: null, hi: null })) }
}

function frame(t: number, values: Record<string, number> = { accel_x: 9 }): LiveFrame {
  return { sensor_id: 'SENSOR-001', group: 'motion', t, quality: 'good', values }
}

describe('merging the live feed onto the record', () => {
  it('appends a frame newer than everything stored', () => {
    const merged = mergeLiveFrames(stored(1000, 2000), [frame(3000)])

    expect(merged!.accel_x.map((p) => p.t)).toEqual([1000, 2000, 3000])
  })

  it('drops a frame the record already covers', () => {
    // Every reading eventually arrives on the durable path. Without this the
    // same moment would be drawn twice, at slightly different bucket edges -
    // which on a vibration chart reads as a spike, and a spike reads as an
    // event.
    const merged = mergeLiveFrames(stored(1000, 2000, 3000), [frame(2500)])

    expect(merged!.accel_x.map((p) => p.t)).toEqual([1000, 2000, 3000])
  })

  it('drops a frame exactly at the newest stored point', () => {
    const merged = mergeLiveFrames(stored(1000, 2000), [frame(2000)])

    expect(merged!.accel_x).toHaveLength(2)
  })

  it('keeps the record itself untouched', () => {
    const original = stored(1000, 2000)
    const before = JSON.stringify(original)

    mergeLiveFrames(original, [frame(3000)])

    // The stored series is the system of record. A merge for display must not
    // mutate it, or a later refetch would compare against something already
    // altered.
    expect(JSON.stringify(original)).toBe(before)
  })

  it('only takes the channels a frame actually carries', () => {
    const merged = mergeLiveFrames(
      { accel_x: stored(1000).accel_x, temperature: stored(1000).accel_x },
      [frame(3000, { accel_x: 9 })],
    )

    expect(merged!.accel_x).toHaveLength(2)
    // A channel absent from the frame is absent, not zero. Plotting a missing
    // reading as 0 would look like a still structure rather than no data.
    expect(merged!.temperature).toHaveLength(1)
  })

  it('carries the live value into lo and hi as well', () => {
    // A live frame is one instant, so its min and max are itself. Leaving them
    // null would break any consumer reading the band.
    const merged = mergeLiveFrames(stored(1000), [frame(3000, { accel_x: 0.5 })])
    const appended = merged!.accel_x[1]

    expect(appended).toEqual({ t: 3000, v: 0.5, lo: 0.5, hi: 0.5 })
  })

  it('appends several frames in order', () => {
    const merged = mergeLiveFrames(stored(1000), [frame(2000), frame(3000), frame(4000)])

    expect(merged!.accel_x.map((p) => p.t)).toEqual([1000, 2000, 3000, 4000])
  })

  it('handles a channel with no stored history yet', () => {
    // A newly provisioned channel has an empty series. A frame must still show,
    // rather than being compared against a newest-point that does not exist.
    const merged = mergeLiveFrames({ accel_x: [] }, [frame(3000)])

    expect(merged!.accel_x).toHaveLength(1)
  })

  it('returns the record unchanged when there are no frames', () => {
    const original = stored(1000, 2000)

    expect(mergeLiveFrames(original, [])).toBe(original)
  })

  it('returns undefined before the record has loaded', () => {
    // Showing live frames alone would draw a chart with no history and no
    // context, which reads as a sensor that just started.
    expect(mergeLiveFrames(undefined, [frame(3000)])).toBeUndefined()
  })
})
