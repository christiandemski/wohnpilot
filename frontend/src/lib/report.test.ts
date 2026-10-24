import { describe, expect, it } from 'vitest'
import { monthsText, spanText } from './report'

describe('year in review', () => {
  it('says how much of the year was read', () => {
    expect(spanText({ amount: 2462.34, coveredDays: 365, days: 365, complete: true }, 'kWh')).toBe('2.462,3 kWh')
    expect(spanText({ amount: 1180, coveredDays: 84, days: 365, complete: false }, 'kWh')).toBe('1.180,0 kWh, 84 von 365 Tagen abgelesen')
    expect(spanText({ amount: 0, coveredDays: 0, days: 366, complete: false }, 'm³')).toBe('Nicht abgelesen')
  })

  it('counts months', () => {
    expect(monthsText(1)).toBe('1 Monat')
    expect(monthsText(12)).toBe('12 Monate')
  })
})
