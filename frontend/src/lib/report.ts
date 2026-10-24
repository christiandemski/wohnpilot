import { formatNumber } from './format'
import type { YearSpan } from './types'

/** Texts for the year in review. */

/** "2.462,3 kWh", or with the gap: "1.180,0 kWh, 84 von 365 Tagen abgelesen" */
export function spanText(span: YearSpan, unit: string, decimals = 1): string {
  const amount = `${formatNumber(span.amount, decimals)} ${unit}`
  if (span.coveredDays === 0) return 'Nicht abgelesen'
  return span.complete ? amount : `${amount}, ${formatNumber(span.coveredDays)} von ${formatNumber(span.days)} Tagen abgelesen`
}

/** Months as text: "12 Monate", "1 Monat" */
export function monthsText(months: number): string {
  return `${months} ${months === 1 ? 'Monat' : 'Monate'}`
}

/** Where the tax items go in the return */
export const TAX_SECTIONS: Record<string, { title: string; cap: string }> = {
  haushaltsnah: { title: 'Haushaltsnahe Dienstleistungen', cap: 'höchstens 4.000 €' },
  handwerker: { title: 'Handwerkerleistungen', cap: 'höchstens 1.200 €' },
}
