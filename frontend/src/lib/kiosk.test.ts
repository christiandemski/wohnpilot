import { describe, expect, it } from 'vitest'
import { clockText, dayText, monthCompare } from './kiosk'

describe('kiosk texts', () => {
  it('shows the time with two digits', () => {
    expect(clockText(new Date(2026, 9, 24, 8, 5))).toBe('08:05')
  })

  it('names the day of a pickup', () => {
    expect(dayText(0, '2026-10-24')).toBe('Heute')
    expect(dayText(1, '2026-10-25')).toBe('Morgen')
    expect(dayText(3, '2026-10-27')).toBe('Dienstag, 27.10.')
  })

  it('compares the month so far by the daily rate', () => {
    expect(monthCompare({ month: '2026-10', amount: 230, coveredDays: 23, days: 31, previousYear: 341 })).toBe('9 % unter Vorjahr')
    expect(monthCompare({ month: '2026-10', amount: 120, coveredDays: 10, days: 31, previousYear: 310 })).toBe('20 % über Vorjahr')
    expect(monthCompare({ month: '2026-10', amount: 120, coveredDays: 10, days: 31, previousYear: null })).toBe('')
  })
})
