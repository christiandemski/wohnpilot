import { dueChip, euro, months } from './contracts'
import { formatChipDay, formatDay } from './format'
import type { ChipTone } from './meters'
import type { AgendaGroup, AgendaItem, NextReading } from './types'

/** German texts for "Was steht an"; the API sends kinds and dates only. */

export const GROUP_TITLES: Record<AgendaGroup, string> = {
  ueberfaellig: 'Überfällig',
  woche: 'Diese Woche',
  bald: 'In den nächsten 30 Tagen',
  spaeter: 'Später',
  hinweis: 'Außerdem',
}

export interface AgendaText {
  title: string
  meta: string
  href: string
  chip: { text: string; tone: ChipTone }
}

/** With the name of who takes care of it, if someone does */
export function agendaText(item: AgendaItem, today: string): AgendaText {
  const text = baseText(item, today)
  return item.person ? { ...text, meta: text.meta ? `${text.meta} · ${item.person.name}` : item.person.name } : text
}

function baseText(item: AgendaItem, today: string): AgendaText {
  const name = item.name ?? ''
  const chip = dueChip({ date: item.date, daysUntil: item.daysUntil, status: item.status })
  const contract = `/vertraege/${encodeURIComponent(item.ref.id ?? '')}`
  switch (item.kind) {
    case 'kuendigung':
      return {
        title: `${name} kündigen?`,
        meta: item.renewsMonths
          ? `Letzter Tag ${formatDay(item.date)}, sonst ${months(item.renewsMonths)} länger`
          : `Letzter Tag ${formatDay(item.date)}`,
        href: contract,
        chip,
      }
    case 'vertragsende':
      return {
        title: item.cancelled ? `${name} endet` : `${name} läuft aus`,
        meta: item.cancelled ? `Gekündigt zum ${formatDay(item.date)}` : `Feste Laufzeit bis ${formatDay(item.date)}`,
        href: contract,
        chip: { text: formatDay(item.date), tone: 'neutral' },
      }
    case 'sonderkuendigung':
      return {
        title: `${name}: neue Preise`,
        meta: `Ab ${formatDay(item.effective!)}, Sonderkündigung bis ${formatDay(item.date)}`,
        href: contract,
        chip,
      }
    case 'zustimmung':
      return {
        title: 'Mieterhöhung prüfen',
        meta: `Ab ${formatDay(item.validFrom!)}, zustimmen bis ${formatDay(item.date)}`,
        href: '/mietvertrag',
        chip,
      }
    case 'wartung':
      return {
        title: name,
        meta: item.from && today < item.from
          ? `Möglich ab ${formatDay(item.from)}, bis ${formatDay(item.date)}`
          : item.appointment
            ? `Mit Termin · bis ${formatDay(item.date)}`
            : item.responsible === 'vermieter'
              ? `Macht der Vermieter · bis ${formatDay(item.date)}`
              : item.lastDone
                ? `Wartung · zuletzt ${formatDay(item.lastDone)}`
                : 'Wartung · noch nie erledigt',
        href: `/wartung/${encodeURIComponent(item.ref.id ?? '')}`,
        chip: item.from && today < item.from ? { text: `ab ${formatDay(item.from)}`, tone: 'neutral' } : item.lastDone === null && item.daysUntil === 0 ? { text: 'Jetzt', tone: 'soon' } : chip,
      }
    case 'termin':
      return {
        title: name,
        meta: [item.time ? `${item.time}${item.until ? `–${item.until}` : ''} Uhr` : 'Termin', item.place].filter(Boolean).join(' · '),
        href: `/termine/${encodeURIComponent(item.ref.id ?? '')}`,
        chip: { text: item.daysUntil === 0 ? 'Heute' : item.daysUntil === 1 ? 'Morgen' : formatChipDay(item.date), tone: item.daysUntil <= 1 ? 'soon' : 'neutral' },
      }
    case 'nachfassen':
      return {
        title: `Nachfassen: ${name}`,
        meta: 'Mangel · Hat sich der Vermieter gerührt?',
        href: `/maengel/${encodeURIComponent(item.ref.id ?? '')}`,
        chip,
      }
    case 'garantie':
      return {
        title: `${item.which === 'garantie' ? 'Garantie' : 'Gewährleistung'} endet: ${name}`,
        meta: `Am ${formatDay(item.date)} · Mängel jetzt noch melden`,
        href: `/geraete/${encodeURIComponent(item.ref.id ?? '')}`,
        chip,
      }
    case 'muell':
      return {
        title: name,
        meta: item.fullService ? 'Abholung · Vollservice' : 'Am Vorabend rausstellen',
        href: '/termine',
        chip: { text: item.daysUntil === 0 ? 'Heute' : item.daysUntil === 1 ? 'Morgen' : formatChipDay(item.date), tone: !item.fullService && item.daysUntil <= 1 ? 'soon' : 'neutral' },
      }
    case 'nachzahlung':
      return {
        title: `Nachzahlung ${name}`,
        meta: `${item.amount != null ? `${euro(item.amount)} · ` : ''}überweisen bis ${formatDay(item.date)}`,
        href: `/nebenkosten/${encodeURIComponent(item.ref.id ?? '')}`,
        chip,
      }
    case 'einwand':
      return {
        title: `Einwände gegen ${name}`,
        meta: `Nur noch bis ${formatDay(item.date)} · danach gilt die Abrechnung`,
        href: `/nebenkosten/${encodeURIComponent(item.ref.id ?? '')}`,
        chip,
      }
    case 'abrechnung':
      return {
        title: `${name} fehlt noch`,
        meta: item.daysUntil >= 0
          ? `Der Vermieter muss bis ${formatDay(item.date)} abrechnen`
          : 'Zu spät: Eine Nachzahlung darf er nicht mehr verlangen',
        href: '/nebenkosten',
        chip: item.daysUntil >= 0 ? chip : { text: 'Frist vorbei', tone: 'neutral' },
      }
    case 'frist': {
      const early = !!item.from && today < item.from && (item.meterIds?.length ?? 0) > 0
      return {
        title: name,
        meta: early ? `Ablesen ab ${formatDay(item.from!)}, bis ${formatDay(item.date)}` : `Bis ${formatDay(item.date)}`,
        href: `/fristen/${encodeURIComponent(item.ref.id ?? '')}`,
        chip,
      }
    }
    default: {
      const count = item.meterIds?.length ?? 0
      return {
        title: 'Ablesen möglich',
        meta: `Freiwillig · ${count} Zähler`,
        href: '/ablesen',
        chip: { text: 'Freiwillig', tone: 'neutral' },
      }
    }
  }
}

const RANK: Record<string, number> = { due: 0, soon: 1, ok: 2, open: 3 }

/**
 * What goes on the dark "Als Nächstes" card: the most urgent thing, a
 * reading or an agenda item. Voluntary readings rank below every deadline.
 */
export function pickNext(agenda: AgendaItem[], reading: NextReading | null): { item: AgendaItem } | { reading: NextReading } | null {
  const readingRank = reading === null ? Infinity : reading.kind === 'meldung' ? RANK[reading.status] : reading.status === 'open' ? 3 : 4
  let best: AgendaItem | null = null
  for (const item of agenda) {
    // The voluntary hint and the requested reading are the reading card already;
    // a pickup is never "the next thing".
    if (item.kind === 'ablesung' || item.kind === 'muell') continue
    // The landlord's maintenance is for your information only.
    if (item.kind === 'wartung' && item.responsible === 'vermieter') continue
    if (reading?.kind === 'meldung' && item.ref.type === 'frist' && item.ref.id === reading.deadlineId) continue
    if (best === null || RANK[item.status] < RANK[best.status] || (RANK[item.status] === RANK[best.status] && item.date < best.date)) best = item
  }
  if (best === null) return reading === null ? null : { reading }
  const bestRank = RANK[best.status]
  if (reading === null || bestRank < readingRank || (bestRank === readingRank && best.date < reading.dueDate)) return { item: best }
  return { reading }
}

/** The agenda without what the "Als Nächstes" card shows already. */
export function remainingAgenda(agenda: AgendaItem[], next: ReturnType<typeof pickNext>): AgendaItem[] {
  if (next === null) return agenda
  if ('item' in next) return agenda.filter((i) => i !== next.item)
  const reading = next.reading
  return agenda.filter((i) =>
    reading.kind === 'meldung' ? !(i.ref.type === 'frist' && i.ref.id === reading.deadlineId) : i.kind !== 'ablesung',
  )
}

export function groupAgenda(agenda: AgendaItem[]): { group: AgendaGroup; items: AgendaItem[] }[] {
  const order: AgendaGroup[] = ['ueberfaellig', 'woche', 'bald', 'spaeter', 'hinweis']
  return order.map((group) => ({ group, items: agenda.filter((i) => i.group === group) })).filter((g) => g.items.length > 0)
}
