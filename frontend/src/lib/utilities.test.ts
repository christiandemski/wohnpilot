import { describe, expect, it } from 'vitest'
import { changeText, checkTone, kindsFrom, periodText, resultChip } from './utilities'

const base = { billedIn: null, deliveredLate: false, fields: {} as { bezahlt?: string } }

describe('utility statements', () => {
  it('writes the period short within a year', () => {
    expect(periodText('2025-01-01', '2025-12-31')).toBe('01.01.–31.12.2025')
    expect(periodText('2024-12-01', '2025-11-30')).toBe('01.12.2024–30.11.2025')
  })

  it('says what the result means for you', () => {
    expect(resultChip({ ...base, balance: 665.8 })).toEqual({ text: 'Nachzahlung 665,80 €', tone: 'soon' })
    expect(resultChip({ ...base, balance: -120 })).toEqual({ text: 'Guthaben 120 €', tone: 'ok' })
    expect(resultChip({ ...base, balance: 665.8, fields: { bezahlt: '2026-10-28' } }).text).toBe('Nachgezahlt 665,80 €')
    expect(resultChip({ ...base, balance: 665.8, deliveredLate: true })).toEqual({ text: 'Nachzahlung entfällt', tone: 'ok' })
    expect(resultChip({ ...base, balance: null, billedIn: 'betriebskosten-2025' }).text).toBe('Über den Vermieter')
  })

  it('shows changes with sign', () => {
    expect(changeText(1.9)).toBe('+1,9 %')
    expect(changeText(-4)).toBe('−4,0 %')
    expect(changeText(null)).toBe('')
    expect(checkTone(2.2)).toBe('ok')
    expect(checkTone(-8)).toBe('soon')
  })

  it('offers the kinds of cost of earlier statements', () => {
    const earlier = [
      { kind: 'betriebskosten', positions: [{ kind: 'Grundsteuer' }, { kind: 'Müllabfuhr' }] },
      { kind: 'heizkosten', positions: [{ kind: 'Brennstoff Gas' }] },
      { kind: 'betriebskosten', positions: [{ kind: 'Grundsteuer' }, { kind: 'Schornsteinfeger' }] },
    ]
    expect(kindsFrom(earlier, 'betriebskosten')).toEqual(['Grundsteuer', 'Müllabfuhr', 'Schornsteinfeger'])
  })
})
