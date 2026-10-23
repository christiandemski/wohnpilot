/// <reference types="vitest/config" />
import { readFileSync } from 'node:fs'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// Manifest colours come from the design tokens, not from hard-coded values.
const tokens = JSON.parse(readFileSync(new URL('../docs/design/tokens.json', import.meta.url), 'utf8'))
const color = (name: string, theme: 'light' | 'dark' = 'light'): string =>
  tokens.color.tokens.find((t: { name: string }) => t.name === name).value[theme]

// Development: `composer serve` in backend/ runs the API on :8080, Vite proxies /api there.
// Build: the app lands in backend/public next to index.php, so one host serves both
// and the service worker can control the whole app from "/".
export default defineConfig({
  plugins: [
    svelte(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      includeAssets: ['favicon.ico', 'apple-touch-icon-180x180.png', 'logo.svg'],
      manifest: {
        id: '/',
        name: 'Wohnpilot',
        short_name: 'Wohnpilot',
        description: 'Dein Zuhause im Blick – Zähler, Fristen, Termine.',
        lang: 'de',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        orientation: 'portrait',
        background_color: color('paper'),
        theme_color: color('paper'),
        icons: [
          { src: 'pwa-64x64.png', sizes: '64x64', type: 'image/png' },
          { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: 'maskable-icon-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
        shortcuts: [{ name: 'Zählerstände ablesen', short_name: 'Ablesen', url: '/ablesen', icons: [{ src: 'pwa-192x192.png', sizes: '192x192' }] }],
      },
      workbox: {
        // Only the app shell is cached. API answers are never cached (offline queue comes in stage 4).
        globPatterns: ['**/*.{js,css,html,woff2,png,svg,ico}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        cleanupOutdatedCaches: true,
      },
    }),
  ],
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
