import { describe, expect, it } from 'vitest'
import { calendarEndHint, endText, intervalText, maintenanceChip, maintenanceMeta, paperlessLink, seasonText, wasteError } from './house'
import type { MaintenanceEvaluation } from './types'

const today = '2026-10-24'
const evaluation = (changes: Partial<MaintenanceEvaluation>): MaintenanceEvaluation => ({
  dueDate: '2026-11-08',
  from: null,
  daysUntil: 15,
  status: 'ok',
  lastDone: '2025-11-08',
  inSeason: null,
  missedSeason: false,
  automatic: false,
  ...changes,
})

describe('maintenance texts', () => {
  it('names intervals and seasons', () => {
    expect([intervalText(12), intervalText(6), intervalText(24), intervalText(5), intervalText(undefined, 2), intervalText()]).toEqual([
      'jährlich',
      'halbjährlich',
      'alle 2 Jahre',
      'alle 5 Monate',
      'alle 2 Wochen',
      '',
    ])
    expect(seasonText({ von: 10, bis: 2 })).toBe('Oktober bis Februar')
    expect(seasonText({ von: 3, bis: 3 })).toBe('im März')
  })

  it('shows the due date like a deadline', () => {
    expect(maintenanceChip(evaluation({}), today)).toEqual({ text: 'bis 08.11.', tone: 'neutral' })
    expect(maintenanceChip(evaluation({ daysUntil: 8, status: 'soon' }), today)).toEqual({ text: 'noch 8 Tage', tone: 'soon' })
    expect(maintenanceChip(evaluation({ daysUntil: -3, status: 'due' }), today)).toEqual({ text: 'Überfällig · 3 Tage', tone: 'due' })
  })

  it('says "jetzt" for a task never done and when a season starts', () => {
    expect(maintenanceChip(evaluation({ lastDone: null, dueDate: today, daysUntil: 0, status: 'due' }), today)).toEqual({ text: 'Jetzt', tone: 'soon' })
    expect(maintenanceChip(evaluation({ from: '2027-10-01', dueDate: '2028-02-29', daysUntil: 493 }), today)).toEqual({ text: 'ab Okt.', tone: 'neutral' })
    expect(maintenanceChip(evaluation({ from: '2026-10-01', dueDate: '2027-02-28', daysUntil: 127 }), today).text).toBe('Feb. 2027')
    expect(maintenanceChip(evaluation({ from: '2026-10-01', dueDate: '2026-12-31', daysUntil: 68 }), today).text).toBe('bis 31.12.')
    expect(maintenanceChip(evaluation({ dueDate: '2027-10-24', daysUntil: 365 }), today)).toEqual({ text: 'Okt. 2027', tone: 'neutral' })
  })

  it('has nothing to do for tasks checked by radio', () => {
    expect(maintenanceChip(evaluation({ automatic: true, daysUntil: -300 }), today)).toEqual({ text: 'Per Funk', tone: 'ok' })
    expect(maintenanceMeta({ intervall_monate: 12, ausfuehrung: 'funk' }, evaluation({ automatic: true }))).toBe('Wird automatisch per Funk geprüft')
    expect(maintenanceMeta({ intervall_monate: 12, ausfuehrung: 'termin' }, evaluation({}))).toBe('jährlich · mit Termin · zuletzt 08.11.2025')
  })

  it('sums up interval, season and last time', () => {
    expect(maintenanceMeta({ intervall_monate: 12, saison: { von: 10, bis: 2 } }, evaluation({ lastDone: '2026-02-14' }))).toBe('jährlich · Oktober bis Februar · zuletzt 14.02.2026')
    expect(maintenanceMeta({ intervall_monate: 12 }, evaluation({ lastDone: null }))).toBe('jährlich · noch nie erledigt')
  })
})

describe('devices and more', () => {
  it('describes warranty and liability ends', () => {
    expect(endText('Garantie', { until: '2027-11-19', daysUntil: 391, status: 'ok' })).toBe('Garantie bis 19.11.2027')
    expect(endText('Gewährleistung', { until: '2026-11-19', daysUntil: 26, status: 'soon' })).toBe('Gewährleistung endet in 26 Tagen (19.11.)')
    expect(endText('Garantie', { until: '2025-01-09', daysUntil: -653, status: 'due' })).toBe('Garantie abgelaufen am 09.01.2025')
  })

  it('explains waste calendar problems', () => {
    expect(wasteError(null)).toBe('')
    expect(wasteError('http_404')).toBe('Der Server des Entsorgers antwortet mit Fehler 404.')
    expect(wasteError('private_address')).toMatch(/privaten Netz/)
  })

  it('links to Paperless only when it is set up', () => {
    expect(paperlessLink(null, 1234)).toBeNull()
    expect(paperlessLink('https://paperless.example.org', '')).toBeNull()
    expect(paperlessLink('https://paperless.example.org', 1234)).toBe('https://paperless.example.org/documents/1234/details')
  })
})

describe('calendarEndHint', () => {
  const file = { file: 'anhaenge/muell/efh/abfuhrkalender.ics', error: null }
  it('says when a downloaded calendar runs out', () => {
    expect(calendarEndHint({ ...file, calendarEnds: '2026-12-23' }, '2026-10-24')).toBe('')
    expect(calendarEndHint({ ...file, calendarEnds: '2026-12-23' }, '2026-11-23')).toBe('Deine Kalenderdatei reicht nur bis 23.12.2026. Lad die Datei fürs nächste Jahr hoch, sobald es sie gibt.')
    expect(calendarEndHint({ ...file, calendarEnds: null }, '2027-01-02')).toMatch(/keine Abholung mehr/)
  })
  it('is quiet for an address or when loading failed', () => {
    expect(calendarEndHint({ file: null, calendarEnds: null, error: null }, '2026-10-24')).toBe('')
    expect(calendarEndHint({ ...file, calendarEnds: null, error: 'file_missing' }, '2026-10-24')).toBe('')
  })
})
