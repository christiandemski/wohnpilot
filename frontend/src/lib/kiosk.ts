import { formatDay, formatNumber } from './format'
import type { MonthValue } from './types'

/** Texts for the kiosk board on the wall: big, short, readable from afar. */

const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag']

/** "18:42" */
export function clockText(now: Date): string {
  return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
}

/** "Heute", "Morgen", "Dienstag, 27.10." */
export function dayText(daysUntil: number, iso: string): string {
  if (daysUntil === 0) return 'Heute'
  if (daysUntil === 1) return 'Morgen'
  const [y, m, d] = iso.split('-').map(Number)
  return `${WEEKDAYS[new Date(y, m - 1, d).getDay()]}, ${formatDay(iso)}`
}

/** "8 % unter Vorjahr": daily rate of the month so far against the same month last year (as the overview) */
export function monthCompare(month: MonthValue): string {
  const { amount, coveredDays, previousYear, days } = month
  if (amount === null || previousYear === null || coveredDays === 0 || previousYear <= 0) return ''
  const change = Math.round((amount / coveredDays / (previousYear / days) - 1) * 100)
  if (change === 0) return 'wie im Vorjahr'
  return `${formatNumber(Math.abs(change))} % ${change < 0 ? 'unter' : 'über'} Vorjahr`
}
