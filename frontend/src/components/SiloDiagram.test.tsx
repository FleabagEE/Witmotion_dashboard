import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { SensorHealthRow } from '../lib/api'
import { SiloDiagram } from './SiloDiagram'

/**
 * A picture of the installation is the easiest thing on this appliance to get
 * wrong without anybody noticing, because a wrong drawing still looks like a
 * drawing. These tests hold it to the same standard as a number:
 *
 *   - a dead sensor must not be drawn green
 *   - a sensor that was never registered must not be drawn at all as healthy
 *   - the ground reference must not lean with the structure it is a reference for
 *   - the drawn lean must come from the measurement, and stop growing before
 *     a bench test looks like a collapse
 */

const sensor = (over: Partial<SensorHealthRow> = {}): SensorHealthRow => ({
  sensor_id: 'SENSOR-001',
  position: 'top',
  role: 'monitor',
  port: '/dev/quakevault-rs485-p1',
  model: 'WTVB01-485',
  verification_status: 'verified',
  temperature: 26.4,
  gravity_magnitude: 1.0008,
  silent_for_seconds: 1,
  status: 'pass',
  checks: { reporting: { state: 'pass', detail: '60 samples' } },
  ...over,
})

const three = (over: Partial<Record<'top' | 'mid' | 'ground', Partial<SensorHealthRow>>> = {}) => [
  sensor({ sensor_id: 'SENSOR-001', position: 'top', ...over.top }),
  sensor({ sensor_id: 'SENSOR-002', position: 'mid', ...over.mid }),
  sensor({ sensor_id: 'SENSOR-003', position: 'ground', role: 'reference', ...over.ground }),
]

/** The rotate() angle of the group a given element sits inside, if any. */
function leanAround(element: Element): number | null {
  for (let node: Element | null = element; node; node = node.parentElement) {
    const transform = node.getAttribute?.('transform')
    const match = transform?.match(/rotate\((-?[\d.]+)/)

    if (match) return Number(match[1])
  }

  return null
}

describe('SiloDiagram', () => {
  it('colours each sensor from its live status', () => {
    const { container } = render(
      <SiloDiagram sensors={three({ mid: { status: 'fail' } })} />,
    )

    const fills = [...container.querySelectorAll('circle')].map((c) => c.getAttribute('fill'))

    expect(fills).toContain('var(--color-ok)')
    expect(fills).toContain('var(--color-critical)')
  })

  it('does not draw a silent sensor as healthy', () => {
    // The failure this whole component exists to prevent: three green circles
    // on a nice illustration, a week after a sensor was unplugged.
    const { container } = render(
      <SiloDiagram sensors={three({ top: { status: 'fail' }, mid: { status: 'fail' }, ground: { status: 'fail' } })} />,
    )

    const fills = [...container.querySelectorAll('circle')].map((c) => c.getAttribute('fill'))

    expect(fills).not.toContain('var(--color-ok)')
    expect(screen.queryByText('healthy')).not.toBeInTheDocument()
  })

  it('says which position is missing rather than drawing it silently', () => {
    render(<SiloDiagram sensors={[sensor({ position: 'top' })]} />)

    expect(screen.getAllByText('not registered')).toHaveLength(2)
    expect(screen.getAllByText('no data').length).toBeGreaterThan(0)
  })

  it('stands upright and says so when movement is not available', () => {
    const { container } = render(<SiloDiagram sensors={three()} />)

    expect(screen.getByText(/drawn upright/)).toBeInTheDocument()
    expect(
      [...container.querySelectorAll('g[transform]')].every(
        (g) => leanAround(g) === 0,
      ),
    ).toBe(true)
  })

  it('leans from the measurement, and states the exaggeration', () => {
    const { container } = render(
      <SiloDiagram sensors={three()} topMovement={0.4} midMovement={0.2} />,
    )

    // 0.2 x 12 = 2.4 on the lower half; the upper half adds the difference.
    const groups = [...container.querySelectorAll('g[transform]')]
    const angles = groups.map((g) => Number(g.getAttribute('transform')!.match(/rotate\((-?[\d.]+)/)![1]))

    expect(angles.some((a) => Math.abs(a - 2.4) < 1e-9)).toBe(true)
    expect(screen.getByText(/12× actual size/)).toBeInTheDocument()
    expect(screen.getByText(/top 0\.4000° · mid 0\.2000°/)).toBeInTheDocument()
  })

  it('clamps the drawn lean so a bench test does not look like a collapse', () => {
    // 35 degrees is what the tilt test on the desk produced. Drawn at 12x it
    // would be 420 degrees - the silo would spin past upright and read as fine.
    const { container } = render(
      <SiloDiagram sensors={three()} topMovement={35} midMovement={35} />,
    )

    const angles = [...container.querySelectorAll('g[transform]')].map(
      (g) => Number(g.getAttribute('transform')!.match(/rotate\((-?[\d.]+)/)![1]),
    )

    expect(Math.max(...angles.map(Math.abs))).toBeLessThanOrEqual(8)
  })

  it('does not lean the ground reference with the structure', () => {
    // A reference that moves with the thing it references measures nothing.
    const { container } = render(
      <SiloDiagram sensors={three()} topMovement={0.4} midMovement={0.4} />,
    )

    const groundLabel = screen.getByText('SENSOR-003')

    expect(leanAround(groundLabel)).toBeNull()
    // And the structure really is leaning, so this is not vacuously true.
    expect(container.querySelector('g[transform*="rotate(4.8"]')).not.toBeNull()
  })

  it('carries a text description for anyone not reading the picture', () => {
    render(<SiloDiagram sensors={three()} />)

    expect(
      screen.getByRole('img', { name: /two silos joined at mid height/i }),
    ).toBeInTheDocument()
  })
})
