import { describe, expect, it } from 'vitest'
import { meterChip } from './meters'
import type { ReadingRequest } from './types'

const today = '2026-10-24'
const schedule = { dueDate: '2026-11-01', daysUntil: 8, status: 'ok' as const }
const request = (changes: Partial<ReadingRequest>): ReadingRequest => ({
  id: 'strom-ablesung-2026',
  title: 'Zählerstand melden',
  from: '2026-10-20',
  date: '2026-12-20',
  daysUntil: 57,
  status: 'ok',
  ...changes,
})

describe('meterChip', () => {
  it('keeps the voluntary rhythm neutral', () => {
    expect(meterChip({ schedule, request: null }, today)).toEqual({ text: 'ab 01.11.', tone: 'neutral' })
    expect(meterChip({ schedule: { dueDate: '2026-08-01', daysUntil: -84, status: 'open' }, request: null }, today)).toEqual({
      text: 'Ablesen möglich',
      tone: 'neutral',
    })
  })

  it('shows a requested reading like a deadline', () => {
    expect(meterChip({ schedule, request: request({}) }, today)).toEqual({ text: 'bis 20.12.', tone: 'neutral' })
    expect(meterChip({ schedule, request: request({ daysUntil: 9, status: 'soon' }) }, today)).toEqual({ text: 'noch 9 Tage', tone: 'soon' })
    expect(meterChip({ schedule, request: request({ daysUntil: 0, status: 'due' }) }, today)).toEqual({ text: 'Heute melden', tone: 'due' })
    expect(meterChip({ schedule, request: request({ daysUntil: -2, status: 'due' }) }, today)).toEqual({ text: 'Überfällig · 2 Tage', tone: 'due' })
  })

  it('says when the reading window opens', () => {
    expect(meterChip({ schedule, request: request({ from: '2026-12-05' }) }, today)).toEqual({ text: 'ab 05.12.', tone: 'neutral' })
  })
})
