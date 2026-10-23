import { describe, expect, it } from 'vitest'
import {
  daysBetween,
  formatChipDay,
  formatDate,
  formatDay,
  formatDelta,
  formatEuro,
  formatLongDay,
  formatNumber,
  formatPercent,
  parseNumber,
} from './format'

const N = ' '

describe('formatNumber', () => {
  it('groups thousands with a narrow no-break space and uses a decimal comma', () => {
    expect(formatNumber(23702, 1)).toBe(`23${N}702,0`)
    expect(formatNumber(3160.4)).toBe(`3${N}160`)
    expect(formatNumber(1234567.891, 3)).toBe(`1${N}234${N}567,891`)
    expect(formatNumber(999)).toBe('999')
    expect(formatNumber(0.5, 1)).toBe('0,5')
  })

  it('never uses the dot as separator like Intl de-DE does', () => {
    expect(formatNumber(23702, 1)).not.toContain('.')
  })

  it('uses a real minus and no negative zero', () => {
    expect(formatNumber(-1234.5, 1)).toBe(`−1${N}234,5`)
    expect(formatNumber(-0.04, 1)).toBe('0,0')
  })

  it('formats deltas, euros and percentages', () => {
    expect(formatDelta(246, 1)).toBe('+246,0')
    expect(formatDelta(-2, 1)).toBe('−2,0')
    expect(formatDelta(0.01, 1)).toBe('±0,0')
    expect(formatEuro(1069.37)).toBe(`1${N}069 €`)
    expect(formatPercent(-0.08)).toBe(`−8${N}%`)
    expect(formatPercent(0.124)).toBe(`+12${N}%`)
  })
})

describe('parseNumber', () => {
  it.each([
    ['23702', 23702],
    ['23702,5', 23702.5],
    ['23 702,5', 23702.5],
    [`23${N}702,5`, 23702.5],
    ['23.702,5', 23702.5],
    ['23702.5', 23702.5],
    ['−3,5', -3.5],
  ])('reads %s', (text, expected) => {
    expect(parseNumber(text)).toBe(expected)
  })

  it.each(['', 'viel', '2.37.02,0'])('rejects %s', (text) => {
    expect(parseNumber(text)).toBeNull()
  })
})

describe('dates', () => {
  it('formats like the design system', () => {
    expect(formatDay('2026-10-01')).toBe('01.10.')
    expect(formatDate('2026-10-24')).toBe('24.10.2026')
    expect(formatLongDay('2026-10-24')).toBe('Samstag, 24. Okt.')
    expect(formatChipDay('2026-10-27')).toBe('Di 27.')
  })

  it('counts days across month ends and leap years', () => {
    expect(daysBetween('2026-10-01', '2026-10-24')).toBe(23)
    expect(daysBetween('2028-02-01', '2028-03-01')).toBe(29)
    expect(daysBetween('2026-11-02', '2026-11-01')).toBe(-1)
  })
})
