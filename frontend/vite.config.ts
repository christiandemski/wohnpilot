/// <reference types="vitest/config" />
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { svelte } from '@sveltejs/vite-plugin-svelte'
import { defineConfig, type Plugin } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// OCR runs in the browser with Tesseract, served from this host (no CDN):
// the worker, the LSTM-only cores (the worker picks one by CPU features)
// and the English model, which reads digits well.
const require = createRequire(import.meta.url)
const packageDir = (name: string) => dirname(require.resolve(`${name}/package.json`))
const OCR_FILES: Record<string, string> = {
  'worker.min.js': join(packageDir('tesseract.js'), 'dist/worker.min.js'),
  'tesseract-core-lstm.wasm.js': join(packageDir('tesseract.js-core'), 'tesseract-core-lstm.wasm.js'),
  'tesseract-core-simd-lstm.wasm.js': join(packageDir('tesseract.js-core'), 'tesseract-core-simd-lstm.wasm.js'),
  'tesseract-core-relaxedsimd-lstm.wasm.js': join(packageDir('tesseract.js-core'), 'tesseract-core-relaxedsimd-lstm.wasm.js'),
  'eng.traineddata.gz': join(packageDir('@tesseract.js-data/eng'), '4.0.0_best_int/eng.traineddata.gz'),
}

function ocrAssets(): Plugin {
  return {
    name: 'wohnpilot-ocr-assets',
    configureServer(server) {
      server.middlewares.use('/ocr/', (req, res, next) => {
        const file = OCR_FILES[(req.url ?? '').replace(/^\//, '').split('?')[0]]
        if (!file) return next()
        res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream')
        res.end(readFileSync(file))
      })
    },
    generateBundle() {
      for (const [name, file] of Object.entries(OCR_FILES)) {
        this.emitFile({ type: 'asset', fileName: `ocr/${name}`, source: readFileSync(file) })
      }
    },
  }
}

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
    ocrAssets(),
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
        // The app shell is precached. API answers are cached as they come in, for reading
        // offline (network first, the cache after 5 s); logging out deletes them (lib/api.ts).
        globPatterns: ['**/*.{js,css,html,woff2,png,svg,ico}'],
        // OCR is large and only needed when photographing a meter: cached on first use instead
        globIgnores: ['ocr/**'],
        runtimeCaching: [
          { urlPattern: /\/ocr\//, handler: 'CacheFirst', options: { cacheName: 'wohnpilot-ocr' } },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/api/') && !url.pathname.startsWith('/api/auth/'),
            method: 'GET',
            handler: 'NetworkFirst',
            options: {
              cacheName: 'wohnpilot-api',
              networkTimeoutSeconds: 5,
              expiration: { maxEntries: 400, maxAgeSeconds: 60 * 60 * 24 * 30 },
            },
          },
        ],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        cleanupOutdatedCaches: true,
        // Push messages and taps on them (public/push-sw.js)
        importScripts: ['push-sw.js'],
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
