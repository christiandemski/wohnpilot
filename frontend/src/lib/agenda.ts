import { dueChip, months } from './contracts'
import { formatDay } from './format'
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

export function agendaText(item: AgendaItem, today: string): AgendaText {
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
    case 'frist': {
      const early = item.from !== undefined && today < item.from && (item.meterIds?.length ?? 0) > 0
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
    // The voluntary hint and the requested reading are the reading card already.
    if (item.kind === 'ablesung') continue
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
