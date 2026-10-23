import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config'

// Generates favicon, PWA and Apple touch icons from public/logo.svg (npm run icons).
export default defineConfig({
  preset: {
    ...minimal2023Preset,
    maskable: { ...minimal2023Preset.maskable, padding: 0.1, resizeOptions: { background: '#1f6f6b' } },
    apple: { ...minimal2023Preset.apple, padding: 0.1, resizeOptions: { background: '#1f6f6b' } },
  },
  images: ['public/logo.svg'],
})
