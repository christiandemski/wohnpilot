import { describe, expect, it } from 'vitest'
import { agendaText, groupAgenda, pickNext, remainingAgenda } from './agenda'
import type { AgendaItem, NextReading } from './types'

const today = '2026-10-24'
const item = (changes: Partial<AgendaItem>): AgendaItem => ({
  ref: { type: 'vertrag', id: 'strom-stadtwerke' },
  name: 'Stadtwerke',
  category: 'energie',
  kind: 'kuendigung',
  date: '2026-11-02',
  daysUntil: 9,
  status: 'soon',
  group: 'bald',
  periodEnd: '2026-11-30',
  renewsMonths: 1,
  ...changes,
})

const request: NextReading = {
  kind: 'meldung',
  deadlineId: 'strom-ablesung-2026',
  title: 'Zählerstand melden',
  from: '2026-12-05',
  dueDate: '2026-12-20',
  daysUntil: 57,
  status: 'ok',
  meterIds: ['strom-haupt'],
}
const voluntary: NextReading = { kind: 'ablesung', dueDate: '2026-10-01', daysUntil: -23, status: 'open', meterIds: ['wasser-haupt'] }

const meldungItem = item({
  ref: { type: 'frist', id: 'strom-ablesung-2026' },
  name: 'Zählerstand melden',
  kind: 'frist',
  date: '2026-12-20',
  daysUntil: 57,
  status: 'ok',
  group: 'spaeter',
  from: '2026-12-05',
  meterIds: ['strom-haupt'],
})
const hint = item({ ref: { type: 'ablesung', id: null }, name: null, kind: 'ablesung', date: '2026-10-01', status: 'open', group: 'hinweis', meterIds: ['wasser-haupt'] })

describe('agendaText', () => {
  it('asks before the notice day', () => {
    expect(agendaText(item({}), today)).toMatchObject({
      title: 'Stadtwerke kündigen?',
      meta: 'Letzter Tag 02.11., sonst 1 Monat länger',
      href: '/vertraege/strom-stadtwerke',
      chip: { text: 'noch 9 Tage', tone: 'soon' },
    })
  })

  it('names special termination, consent and ends', () => {
    expect(agendaText(item({ kind: 'sonderkuendigung', date: '2026-12-31', effective: '2027-01-01', status: 'ok' }), today).meta).toBe(
      'Ab 01.01., Sonderkündigung bis 31.12.',
    )
    expect(agendaText(item({ ref: { type: 'mietvertrag', id: 'm' }, kind: 'zustimmung', validFrom: '2027-01-01', date: '2026-12-31' }), today)).toMatchObject({
      title: 'Mieterhöhung prüfen',
      href: '/mietvertrag',
    })
    expect(agendaText(item({ kind: 'vertragsende', cancelled: true, date: '2026-11-14' }), today)).toMatchObject({
      title: 'Stadtwerke endet',
      chip: { text: '14.11.', tone: 'neutral' },
    })
  })

  it('tells when a requested reading starts', () => {
    expect(agendaText(meldungItem, today).meta).toBe('Ablesen ab 05.12., bis 20.12.')
    expect(agendaText(meldungItem, '2026-12-06').meta).toBe('Bis 20.12.')
  })
})

describe('pickNext', () => {
  it('puts a close notice day before a calm request', () => {
    const agenda = [item({}), meldungItem, hint]
    const next = pickNext(agenda, request)
    expect(next).toEqual({ item: agenda[0] })
    expect(remainingAgenda(agenda, next)).toEqual([meldungItem, hint])
  })

  it('keeps a due request on top', () => {
    const due = { ...request, daysUntil: 0, status: 'due' as const }
    const next = pickNext([item({}), meldungItem], due)
    expect(next).toEqual({ reading: due })
    expect(remainingAgenda([item({}), meldungItem], next)).toEqual([item({})])
  })

  it('ranks voluntary readings below any deadline', () => {
    const calm = item({ status: 'ok', daysUntil: 300, date: '2027-08-20', group: 'spaeter' })
    expect(pickNext([calm, hint], voluntary)).toEqual({ item: calm })
    expect(pickNext([hint], voluntary)).toEqual({ reading: voluntary })
    expect(remainingAgenda([hint], { reading: voluntary })).toEqual([])
    expect(pickNext([], null)).toBeNull()
  })
})

describe('groupAgenda', () => {
  it('keeps the order of urgency and drops empty groups', () => {
    expect(groupAgenda([hint, item({ group: 'woche' })]).map((g) => g.group)).toEqual(['woche', 'hinweis'])
  })
})
