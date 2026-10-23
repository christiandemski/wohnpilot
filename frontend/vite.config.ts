/// <reference types="vitest/config" />
import { svelte } from '@sveltejs/vite-plugin-svelte'
import { defineConfig } from 'vite'

// Development: `composer serve` in backend/ runs the API on :8080, Vite proxies /api there.
// Build: the app lands in backend/public next to index.php, so one host serves both
// and the service worker can control the whole app from "/".
export default defineConfig({
  plugins: [svelte()],
  server: {
    port: 5173,
    proxy: { '/api': 'http://localhost:8080' },
  },
  build: {
    outDir: '../backend/public',
    // index.php and .htaccess live there too; scripts/clean.mjs removes old build output instead
    emptyOutDir: false,
  },
  test: {
    include: ['src/**/*.test.ts'],
  },
})
