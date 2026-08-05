/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    // Built straight into the API's public directory, so the appliance serves
    // the dashboard and the API from one supervised process. Two hand-started
    // processes did not survive a power cut; see backend/routes/web.php.
    outDir: '../backend/public',
    // public/ is not ours to empty - index.php and the framework's files live
    // there. deploy/build-dashboard.sh clears the previous assets instead.
    emptyOutDir: false,
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    // The API is served separately in development. Proxying keeps the browser
    // on one origin, so there is no CORS surface and no cross-origin token.
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.tsx'],
    // Charts render to canvas, which jsdom does not implement. Tests assert on
    // the data and the text around the chart rather than on pixels - the things
    // that were wrong in the bugs these tests exist for.
    include: ['src/**/*.test.{ts,tsx}'],
  },
})
