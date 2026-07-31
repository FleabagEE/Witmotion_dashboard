# Dashboard

React 19 + TypeScript + Tailwind 4 + TanStack Query + ECharts, built with Vite.

```bash
npm install
npm run dev     # http://127.0.0.1:5173, proxies /api to 127.0.0.1:8000
npm run build
```

## Conventions worth keeping

**Severity is never carried by colour alone.** Every badge pairs a colour with a
dot and a text label, and the palette varies in lightness as well as hue, so it
survives the common forms of colour blindness and a washed-out wall display.

**Measurements use tabular figures.** Digits must not jitter as values update, or
a screen read from across a room becomes unreadable.

**Trustworthiness travels with the number.** A channel shows its register and
scale; a chart states whether it is showing raw samples or hourly averages; an
alarm raised from unconfirmed thresholds is labelled provisional wherever it
appears. A dashboard that shows a figure without that context invites somebody to
act on it.

**Dark by default.** These screens live in plant rooms and site offices, often on
a wall. A bright background at 2am is hostile.
