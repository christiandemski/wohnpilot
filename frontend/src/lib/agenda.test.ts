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

  it('names back payments, objections and a missing statement', () => {
    const utility = (changes: Partial<AgendaItem>) => item({ ref: { type: 'nebenkosten', id: 'betriebskosten-2025' }, name: 'Betriebskosten 2025', category: null, ...changes })
    expect(agendaText(utility({ kind: 'nachzahlung', date: '2026-10-30', daysUntil: 6, amount: 665.8 }), today)).toMatchObject({
      title: 'Nachzahlung Betriebskosten 2025',
      meta: '665,80 € · überweisen bis 30.10.',
      href: '/nebenkosten/betriebskosten-2025',
    })
    expect(agendaText(utility({ kind: 'einwand', date: '2026-11-30' }), today).meta).toBe('Nur noch bis 30.11. · danach gilt die Abrechnung')
    const missing = utility({ ref: { type: 'nebenkosten', id: null }, name: 'Betriebskosten 2026', kind: 'abrechnung', date: '2027-12-31', daysUntil: -5, status: 'due' })
    expect(agendaText(missing, today)).toMatchObject({
      title: 'Betriebskosten 2026 fehlt noch',
      meta: 'Zu spät: Eine Nachzahlung darf er nicht mehr verlangen',
      href: '/nebenkosten',
      chip: { text: 'Frist vorbei', tone: 'neutral' },
    })
  })

  it('tells when a requested reading starts', () => {
    expect(agendaText(meldungItem, today).meta).toBe('Ablesen ab 05.12., bis 20.12.')
    expect(agendaText(meldungItem, '2026-12-06').meta).toBe('Bis 20.12.')
  })
})

describe('house entries', () => {
  it('words maintenance, appointments, follow-ups, warranties and bins', () => {
    const house = (changes: Partial<AgendaItem>) => item({ category: null, renewsMonths: undefined, periodEnd: undefined, ...changes })
    expect(agendaText(house({ ref: { type: 'wartung', id: 'heckenschnitt' }, name: 'Hecke', kind: 'wartung', date: '2027-02-28', daysUntil: 127, status: 'ok', from: '2026-11-01', lastDone: '2026-02-14' }), today)).toMatchObject({
      meta: 'Möglich ab 01.11., bis 28.02.',
      chip: { text: 'ab 01.11.', tone: 'neutral' },
      href: '/wartung/heckenschnitt',
    })
    expect(agendaText(house({ ref: { type: 'termin', id: 's' }, name: 'Schornsteinfeger', kind: 'termin', date: '2026-10-25', daysUntil: 1, status: 'ok', time: '08:00', until: '09:00', place: 'Keller' }), today)).toMatchObject({
      meta: '08:00–09:00 Uhr · Keller',
      chip: { text: 'Morgen', tone: 'soon' },
    })
    expect(agendaText(house({ ref: { type: 'geraet', id: 'w' }, name: 'Waschmaschine', kind: 'garantie', which: 'gewaehrleistung', date: '2026-11-19', daysUntil: 26 }), today).title).toBe('Gewährleistung endet: Waschmaschine')
    expect(agendaText(house({ ref: { type: 'muell', id: 'gelb' }, name: 'Gelbe Tonne', kind: 'muell', date: '2026-10-27', daysUntil: 3, status: 'ok', fullService: true }), today)).toMatchObject({
      meta: 'Abholung · Vollservice',
      chip: { text: 'Di 27.', tone: 'neutral' },
    })
  })

  it('leaves maintenance of the landlord off the next card', () => {
    const boiler = item({ ref: { type: 'wartung', id: 'heizungswartung' }, name: 'Gastherme', kind: 'wartung', date: '2026-11-01', daysUntil: 8, status: 'soon', responsible: 'vermieter' })
    expect(pickNext([boiler, item({})], null)).toEqual({ item: item({}) })
    expect(agendaText(boiler, today).meta).toBe('Macht der Vermieter · bis 01.11.')
  })

  it('never puts a pickup on the next card', () => {
    const bin = item({ ref: { type: 'muell', id: 'gelb' }, kind: 'muell', date: '2026-10-25', daysUntil: 1, status: 'ok', group: 'woche' })
    expect(pickNext([bin], null)).toBeNull()
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

describe('who takes care of it', () => {
  it('adds the name to the line under the title', () => {
    const task = item({ ref: { type: 'wartung', id: 'rauchmelder' }, name: 'Rauchmelder testen', kind: 'wartung', date: '2026-11-08', daysUntil: 15, status: 'ok', from: null, lastDone: '2025-11-08', person: { id: 'alex', name: 'Alex' } })
    expect(agendaText(task, today).meta).toMatch(/ · Alex$/)
    expect(agendaText({ ...task, person: null }, today).meta).not.toContain('Alex')
  })
})
