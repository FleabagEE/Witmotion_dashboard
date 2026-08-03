import '@testing-library/jest-dom/vitest'
import { vi } from 'vitest'

// ECharts renders to a canvas jsdom does not implement, and ResizeObserver does
// not exist there either. Both are stubbed rather than avoided so the real
// components can be mounted: the bugs these tests exist for were in the text and
// the data around the chart, not in the drawing.
globalThis.ResizeObserver = class {
  observe() {}
  unobserve() {}
  disconnect() {}
}

vi.mock('echarts-for-react', () => ({
  default: ({ option }: { option: unknown }) => {
    // The option object is what the component computed. Exposing it as a data
    // attribute lets a test assert on the series that would have been drawn.
    return (
      <div data-testid="chart" data-option={JSON.stringify(option)} />
    )
  },
}))
