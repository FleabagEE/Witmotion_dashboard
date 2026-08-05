import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { WaveformCard } from './WaveformCard'
import type { SeriesPoint } from '../lib/api'

/**
 * The acceleration card, where two real bugs landed.
 *
 * First the axis was unreadable, because gravity's static offset spans 0.77 g
 * while the vibration is under 0.01 g. Then removing that offset hid tilt
 * entirely - tilt IS a change in the static offset - so a tilt test appeared to
 * do nothing. These pin both, and the disclosure that keeps either mode honest.
 */

function series(key: string, values: number[]): Record<string, SeriesPoint[]> {
  return {
    [key]: values.map((v, i) => ({ t: 1_000 + i * 100, v, lo: v, hi: v })),
  }
}

const TRACES = [{ key: 'accel_x', label: 'X', colour: '#58a6ff' }]

function plotted(): number[] {
  const option = JSON.parse(screen.getByTestId('chart').dataset.option!)
  return option.series[0].data.map((p: [number, number]) => p[1])
}

describe('absolute by default', () => {
  it('plots the reading as measured, gravity included', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={series('accel_x', [0.95, 0.96, 0.95])} offsetRemovable decimals={4} />)

    // Tilting changes the static offset. If the card removed it by default, a
    // tilt test would show a flat line and the operator would conclude the
    // sensor was dead.
    expect(plotted()).toEqual([0.95, 0.96, 0.95])
    expect(screen.getByRole('button')).toHaveTextContent('remove static offset')
  })

  it('shows the latest absolute value in the header', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={series('accel_x', [0.95, 0.9624])} offsetRemovable decimals={4} />)

    expect(screen.getByText('0.9624')).toBeInTheDocument()
  })
})

describe('removing the static offset', () => {
  it('centres the trace on zero so vibration is visible', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={series('accel_x', [0.94, 0.95, 0.96])} offsetRemovable decimals={4} />)

    fireEvent.click(screen.getByRole('button'))

    // Mean is 0.95: the same data now spans 0.02 g instead of sitting on 0.95.
    const values = plotted()
    expect(values[0]).toBeCloseTo(-0.01, 6)
    expect(values[1]).toBeCloseTo(0, 6)
    expect(values[2]).toBeCloseTo(0.01, 6)
  })

  it('says what it removed, per trace', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={series('accel_x', [0.94, 0.96])} offsetRemovable decimals={4} />)

    fireEvent.click(screen.getByRole('button'))

    // Not silently discarded: the offset is the sensor's tilt against gravity,
    // and a change in it means the mounting moved.
    expect(screen.getByText(/offset removed/)).toBeInTheDocument()
    expect(screen.getByText(/0\.9500/)).toBeInTheDocument()
  })

  it('labels the axis as a delta so it cannot be read as absolute', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={series('accel_x', [0.94, 0.96])} offsetRemovable decimals={4} />)

    fireEvent.click(screen.getByRole('button'))

    const option = JSON.parse(screen.getByTestId('chart').dataset.option!)
    expect(option.yAxis.name).toBe('Δ g')
  })

  it('is not offered on cards where it makes no sense', () => {
    render(<WaveformCard title="Velocity" unit="mm/s"
      traces={[{ key: 'vib_velocity_x', label: 'X', colour: '#58a6ff' }]}
      series={series('vib_velocity_x', [0, 1.5])} decimals={2} />)

    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})

describe('missing data', () => {
  it('says so rather than drawing an empty chart', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={{ accel_x: [] }} decimals={4} />)

    expect(screen.getByText('no data in this window')).toBeInTheDocument()
  })

  it('shows a dash for a channel that read nothing, never zero', () => {
    // A missing reading plotted as 0 looks like a still structure rather than
    // an absent one.
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={{ accel_x: [{ t: 1, v: null, lo: null, hi: null }] }} decimals={4} />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('keeps a null as a gap in the plotted series', () => {
    render(<WaveformCard title="Acceleration" unit="g" traces={TRACES}
      series={{ accel_x: [
        { t: 1, v: 0.9, lo: 0.9, hi: 0.9 },
        { t: 2, v: null, lo: null, hi: null },
        { t: 3, v: 0.9, lo: 0.9, hi: 0.9 },
      ] }} decimals={4} />)

    expect(plotted()).toEqual([0.9, null, 0.9])
  })
})

describe('what the card discloses', () => {
  it('warns when the resolution has flattened peaks', () => {
    render(<WaveformCard title="Velocity" unit="mm/s"
      traces={[{ key: 'vib_velocity_x', label: 'X', colour: '#58a6ff' }]}
      series={series('vib_velocity_x', [1, 2])} resolution="hourly_rollup" decimals={2} />)

    expect(screen.getByText(/peaks flattened/)).toBeInTheDocument()
  })

  it('never leaves the unit implied', () => {
    // 0.98 means nothing until you know whether it is g, mm/s or micrometres.
    render(<WaveformCard title="Displacement" unit="µm"
      traces={[{ key: 'vib_displacement_x', label: 'X', colour: '#58a6ff' }]}
      series={series('vib_displacement_x', [10, 20])} decimals={0} />)

    const option = JSON.parse(screen.getByTestId('chart').dataset.option!)
    expect(option.yAxis.name).toBe('µm')
  })
})

describe('WaveformCard threshold lines', () => {
  const series = { a: [{ t: 1, v: 0.2, lo: null, hi: null }] }
  const traces = [{ key: 'a', label: 'X', colour: '#58a6ff' }]

  it('renders without limits', () => {
    const { container } = render(
      <WaveformCard title="Velocity" unit="mm/s" traces={traces} series={series} />,
    )
    expect(container.textContent).toContain('Velocity')
  })

  it('accepts limits without throwing', () => {
    // The chart itself is an ECharts canvas and not readable here; what this
    // guards is that the prop threads through and the option builder does not
    // fall over on a null threshold, which is the common shape - an advisory
    // level that nobody set.
    const { container } = render(
      <WaveformCard
        title="Velocity" unit="mm/s" traces={traces} series={series}
        limits={{ warning: 3, critical: null, confirmed: false }}
      />,
    )
    expect(container.textContent).toContain('Velocity')
  })
})
