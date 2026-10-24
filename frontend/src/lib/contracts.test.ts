import { describe, expect, it } from 'vitest'
import { contractChip, costText, dueChip, noticePeriodText, ruler, termText } from './contracts'
import type { ContractTerm } from './types'

const today = '2026-10-24'
const term = (changes: Partial<ContractTerm>): ContractTerm => ({
  state: 'running',
  periodStart: '2024-12-01',
  countdownFrom: '2024-12-01',
  periodEnd: '2026-11-30',
  noticeBy: '2026-11-02',
  daysUntilNotice: 9,
  status: 'soon',
  renewsMonths: 1,
  endsOn: null,
  daysUntilEnd: null,
  endIfCancelledToday: '2026-11-30',
  ...changes,
})

describe('dueChip', () => {
  it('says how urgent a deadline is', () => {
    expect(dueChip({ date: '2026-12-31', daysUntil: 68, status: 'ok' })).toEqual({ text: 'bis 31.12.', tone: 'neutral' })
    expect(dueChip({ date: '2026-11-02', daysUntil: 9, status: 'soon' })).toEqual({ text: 'noch 9 Tage', tone: 'soon' })
    expect(dueChip({ date: '2026-10-24', daysUntil: 0, status: 'due' })).toEqual({ text: 'Heute', tone: 'due' })
    expect(dueChip({ date: '2026-10-23', daysUntil: -1, status: 'due' })).toEqual({ text: 'Überfällig · 1 Tag', tone: 'due' })
    expect(dueChip({ date: '2026-10-23', daysUntil: -1, status: 'done' })).toEqual({ text: 'Erledigt', tone: 'ok' })
  })
})

describe('contract texts', () => {
  it('formats costs and notice periods', () => {
    expect(costText(85, 'monatlich')).toBe('85 € im Monat')
    expect(costText(96.4, 'jaehrlich')).toBe('96,40 € im Jahr')
    expect(costText(undefined, 'monatlich')).toBe('')
    expect(noticePeriodText({ kuendigungsfrist_wochen: 4 })).toBe('4 Wochen')
    expect(noticePeriodText({ kuendigungsfrist_monate: 1 })).toBe('1 Monat')
    expect(noticePeriodText({})).toBe('')
  })

  it('explains the term', () => {
    expect(termText(term({}), '2024-12-01')).toEqual({
      title: 'Kündigen bis 02.11.',
      text: 'Die Mindestlaufzeit endet am 30.11.2026. Ohne Kündigung verlängert er sich jeweils um 1 Monat.',
    })
    expect(termText(term({ periodStart: '2026-12-01', periodEnd: '2026-12-31' }), '2024-12-01').text).toMatch(/^Die Laufzeit endet am 31\.12\.2026\./)
    expect(termText(term({ state: 'cancelled', endsOn: '2026-11-14', daysUntilEnd: 21 })).title).toBe('Gekündigt zum 14.11.')
    expect(termText(term({ state: 'open', endIfCancelledToday: null })).title).toBe('Jederzeit kündbar')
  })

  it('shows the state in the chip', () => {
    expect(contractChip({ term: term({}) })).toEqual({ text: 'noch 9 Tage', tone: 'soon' })
    expect(contractChip({ term: term({ state: 'cancelled', endsOn: '2026-11-14' }) }).text).toBe('Gekündigt zum 14.11.')
  })
})

describe('ruler', () => {
  it('shows the last stretch before the notice day', () => {
    const r = ruler(term({}), today)!
    // 28 days from notice day to end → 56 days before the notice day
    expect(r.from).toBe('2026-09-07')
    expect(r.fromIsStart).toBe(false)
    expect(r.notice).toBeCloseTo(56 / 84)
    expect(r.today).toBeCloseTo(47 / 84)
  })

  it('starts at the period start when it is closer', () => {
    const r = ruler(term({ countdownFrom: '2026-10-20' }), today)!
    expect(r.from).toBe('2026-10-20')
    expect(r.fromIsStart).toBe(true)
  })

  it('is only drawn for running contracts', () => {
    expect(ruler(term({ state: 'cancelled' }), today)).toBeNull()
  })
})
