import { afterEach, describe, expect, it, vi } from 'vitest'
import { encode } from 'uqr'
import { FORMATS, qrPath, qrTarget, sheets } from './stickers'
import { qrPathData } from './qr'
import { isAppPath, rememberReturn, takeReturn } from './returnTo'

describe('sticker addresses', () => {
  it('keeps the address short and leads to the right page', () => {
    expect(qrPath('z', 'strom-haupt')).toBe('/q/z/strom-haupt')
    expect(qrTarget('z', 'strom-haupt')).toBe('/ablesen/strom-haupt')
    expect(qrTarget('g', 'waschmaschine')).toBe('/geraete/waschmaschine')
    expect(qrTarget('w', 'haupthahn')).toBe('/wissen/haupthahn')
    expect(qrTarget('n', 'blatt')).toBe('/notfall')
  })

  it('sends unknown stickers to the overview and escapes ids', () => {
    expect(qrTarget('x', 'a')).toBe('/')
    expect(qrTarget('w', '../einstellungen')).toBe('/wissen/..%2Feinstellungen')
  })
})

describe('sheet formats', () => {
  it('fit on A4', () => {
    for (const f of FORMATS) {
      expect(f.left * 2 + f.columns * f.width + (f.columns - 1) * f.gapX).toBeLessThanOrEqual(210.01)
      expect(f.top + f.rows * f.height + (f.rows - 1) * f.gapY).toBeLessThanOrEqual(297)
    }
  })

  it('spreads stickers over sheets and skips used places', () => {
    const grid = { columns: 2, rows: 2 }
    expect(sheets(['a', 'b', 'c'], grid)).toEqual([['a', 'b', 'c', null]])
    expect(sheets(['a', 'b', 'c'], grid, 2)).toEqual([[null, null, 'a', 'b'], ['c', null, null, null]])
    // Never a sheet of nothing but gaps
    expect(sheets(['a'], grid, 9)).toEqual([[null, null, null, 'a']])
  })
})

describe('QR path', () => {
  it('draws exactly the dark modules', () => {
    const text = 'https://wohnpilot.example/q/z/strom-haupt'
    const qr = encode(text, { ecc: 'M', border: 0 })
    const { size, d } = qrPathData(text)
    expect(size).toBe(qr.size)
    const dark = qr.data.flat().filter(Boolean).length
    const drawn = [...d.matchAll(/h(\d+)/g)].reduce((sum, m) => sum + Number(m[1]), 0)
    expect(drawn).toBe(dark)
  })
})

describe('return after sign-in', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('accepts only paths inside the app', () => {
    expect(isAppPath('/q/z/strom')).toBe(true)
    expect(isAppPath('//evil.example')).toBe(false)
    expect(isAppPath('/\\evil.example')).toBe(false)
    expect(isAppPath('https://evil.example')).toBe(false)
    expect(isAppPath('/api/auth/logout')).toBe(false)
  })

  it('remembers a page once', () => {
    const store = new Map<string, string>()
    vi.stubGlobal('sessionStorage', {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => store.set(k, v),
      removeItem: (k: string) => store.delete(k),
    })
    rememberReturn('/')
    expect(takeReturn()).toBeNull()
    rememberReturn('/q/w/haupthahn')
    expect(takeReturn()).toBe('/q/w/haupthahn')
    expect(takeReturn()).toBeNull()
  })
})
