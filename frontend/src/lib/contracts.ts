import { daysBetween, days, formatDate, formatDay, formatEuro, formatNumber } from './format'
import type { ChipTone } from './meters'
import type { ContractSummary, ContractTerm, DueStatus } from './types'

/** German texts for contracts, the lease and deadlines; the API sends codes only. */

export const CATEGORIES: Record<string, string> = {
  energie: 'Energie',
  internet: 'Internet',
  mobilfunk: 'Mobilfunk',
  rundfunk: 'Rundfunk',
  streaming: 'Streaming',
  versicherung: 'Versicherung',
  mitgliedschaft: 'Mitgliedschaft',
  sonstiges: 'Sonstiges',
}

/** For the select in the form */
export const RHYTHMS: Record<string, string> = {
  monatlich: 'Monatlich',
  quartalsweise: 'Vierteljährlich',
  halbjaehrlich: 'Halbjährlich',
  jaehrlich: 'Jährlich',
  einmalig: 'Einmalig',
}

/** After an amount: "85 € im Monat" */
const PER_RHYTHM: Record<string, string> = {
  monatlich: 'im Monat',
  quartalsweise: 'im Quartal',
  halbjaehrlich: 'im Halbjahr',
  jaehrlich: 'im Jahr',
  einmalig: 'einmalig',
}

export const RENT_TYPES: Record<string, string> = {
  standard: 'Normale Miete',
  index: 'Indexmiete',
  staffel: 'Staffelmiete',
}

export const DEPOSIT_TYPES: Record<string, string> = {
  kautionskonto: 'Mietkautionskonto',
  barkaution: 'Barkaution',
  buergschaft: 'Bürgschaft',
  verpfaendung: 'Verpfändetes Sparbuch',
  sonstiges: 'Sonstiges',
}

export function categoryName(category: string | undefined): string {
  return category ? (CATEGORIES[category] ?? category) : ''
}

export function months(n: number): string {
  return `${formatNumber(n)} ${n === 1 ? 'Monat' : 'Monate'}`
}

export function weeks(n: number): string {
  return `${formatNumber(n)} ${n === 1 ? 'Woche' : 'Wochen'}`
}

/** Amounts with cents only if there are some: "85 €", "96,40 €" */
export function euro(value: number): string {
  return formatEuro(value, Math.round(value * 100) % 100 !== 0)
}

/** "85 € im Monat", "96,40 € im Jahr" */
export function costText(cost: number | undefined, rhythm: string | undefined): string {
  if (cost === undefined || cost === null) return ''
  return `${euro(cost)} ${PER_RHYTHM[rhythm ?? 'monatlich'] ?? rhythm}`
}

/** "4 Wochen", "3 Monate", or "" */
export function noticePeriodText(fields: { kuendigungsfrist_wochen?: number; kuendigungsfrist_monate?: number }): string {
  if (fields.kuendigungsfrist_monate) return months(fields.kuendigungsfrist_monate)
  if (fields.kuendigungsfrist_wochen) return weeks(fields.kuendigungsfrist_wochen)
  return ''
}

/**
 * Chip for anything with a last day: "Überfällig · 2 Tage", "Heute",
 * "noch 9 Tage" (soon), "bis 31.12." (in time).
 */
export function dueChip(item: { date: string; daysUntil: number; status: DueStatus | string }): { text: string; tone: ChipTone } {
  if (item.status === 'done') return { text: 'Erledigt', tone: 'ok' }
  if (item.daysUntil < 0) return { text: `Überfällig · ${days(-item.daysUntil)}`, tone: 'due' }
  if (item.daysUntil === 0) return { text: 'Heute', tone: 'due' }
  if (item.status === 'soon' || item.status === 'due') return { text: `noch ${days(item.daysUntil)}`, tone: item.status }
  return { text: `bis ${formatDay(item.date)}`, tone: 'neutral' }
}

/** The chip in the contract list and the head of the detail page. */
export function contractChip(contract: Pick<ContractSummary, 'term'>): { text: string; tone: ChipTone } {
  const t = contract.term
  switch (t.state) {
    case 'running':
      return dueChip({ date: t.noticeBy!, daysUntil: t.daysUntilNotice!, status: t.status! })
    case 'cancelled':
      return { text: `Gekündigt zum ${formatDay(t.endsOn!)}`, tone: 'neutral' }
    case 'expires':
      return { text: `Endet ${formatDay(t.endsOn!)}`, tone: 'neutral' }
    case 'ended':
      return { text: 'Beendet', tone: 'neutral' }
    default:
      return { text: 'Jederzeit kündbar', tone: 'neutral' }
  }
}

/**
 * Headline and explanation of the term.
 * $start is the contract start: the first period is the minimum term.
 */
export function termText(term: ContractTerm, start?: string): { title: string; text: string } {
  switch (term.state) {
    case 'running': {
      const renews = term.renewsMonths ? ` Ohne Kündigung verlängert er sich jeweils um ${months(term.renewsMonths)}.` : ''
      const end = term.periodStart === start ? `Die Mindestlaufzeit endet am ${formatDate(term.periodEnd!)}.` : `Die Laufzeit endet am ${formatDate(term.periodEnd!)}.`
      return {
        title: term.daysUntilNotice! < 0 ? 'Kündigungstag verpasst' : `Kündigen bis ${formatDay(term.noticeBy!)}`,
        text: end + renews,
      }
    }
    case 'cancelled':
      return { title: `Gekündigt zum ${formatDay(term.endsOn!)}`, text: `Der Vertrag endet am ${formatDate(term.endsOn!)}, noch ${days(term.daysUntilEnd!)}.` }
    case 'expires':
      return { title: `Endet am ${formatDay(term.endsOn!)}`, text: `Feste Laufzeit ohne Verlängerung: Der Vertrag endet am ${formatDate(term.endsOn!)} von selbst.` }
    case 'ended':
      return { title: 'Beendet', text: `Der Vertrag ist am ${formatDate(term.endsOn!)} ausgelaufen.` }
    default:
      return {
        title: 'Jederzeit kündbar',
        text: term.endIfCancelledToday
          ? `Keine feste Laufzeit. Kündigst du heute, endet der Vertrag am ${formatDate(term.endIfCancelledToday)}.`
          : 'Keine feste Laufzeit und keine Kündigungsfrist eingetragen.',
      }
  }
}

/**
 * Points of the Fristen-Lineal (FristRing.md): period start, today, notice
 * day, end. The ruler shows the last stretch before the notice day, at least
 * twice the time from notice day to end, so the dates do not crowd.
 * Positions are 0–1.
 */
export function ruler(term: ContractTerm, today: string) {
  if (term.state !== 'running' || !term.noticeBy || !term.periodEnd || !term.countdownFrom) return null
  const tail = Math.max(1, daysBetween(term.noticeBy, term.periodEnd))
  const lead = Math.max(2 * tail, 30)
  const span = daysBetween(term.countdownFrom, term.noticeBy) > lead ? lead : daysBetween(term.countdownFrom, term.noticeBy)
  const from = addDays(term.noticeBy, -span)
  const total = Math.max(1, daysBetween(from, term.periodEnd))
  const at = (day: string) => Math.min(1, Math.max(0, daysBetween(from, day) / total))
  return {
    from,
    to: term.periodEnd,
    noticeBy: term.noticeBy,
    today: at(today),
    notice: at(term.noticeBy),
    /** True if the left end is the real period start, not a cut. */
    fromIsStart: from === term.countdownFrom,
  }
}

/** Share of the countdown to the notice day that has passed (the arc of the FristRing). */
export function countdownShare(from: string, until: string, today: string): number {
  const total = daysBetween(from, until)
  if (total <= 0) return 1
  return Math.min(1, Math.max(0, daysBetween(from, today) / total))
}

export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10)
}
