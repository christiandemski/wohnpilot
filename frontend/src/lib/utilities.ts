import { euro } from './contracts'
import { formatDate, formatNumber } from './format'
import type { ChipTone } from './meters'
import type { UtilitySummary } from './types'

/** Texts for utility statements (Nebenkosten); the API sends amounts and dates. */

export const KINDS: Record<string, string> = {
  betriebskosten: 'Betriebskosten',
  heizkosten: 'Heizkosten',
}

export const TAX_KINDS: Record<string, string> = {
  haushaltsnah: 'Haushaltsnahe Dienstleistung',
  handwerker: 'Handwerkerleistung',
}

/** Billed consumption: key in `verbrauch` → label and unit */
export const CONSUMPTION: Record<string, { label: string; unit: string }> = {
  wasser_m3: { label: 'Wasser', unit: 'm³' },
  warmwasser_m3: { label: 'Warmwasser', unit: 'm³' },
  waerme_kwh: { label: 'Wärme', unit: 'kWh' },
  gas_kwh: { label: 'Gas', unit: 'kWh' },
  gas_m3: { label: 'Gas', unit: 'm³' },
  strom_kwh: { label: 'Strom', unit: 'kWh' },
}

/** Fields the form offers for each kind of statement */
export const CONSUMPTION_FIELDS: Record<string, string[]> = {
  betriebskosten: ['wasser_m3', 'strom_kwh'],
  heizkosten: ['waerme_kwh', 'warmwasser_m3', 'gas_kwh'],
}

/** "01.01.–31.12.2025", "01.12.2024–30.11.2025" */
export function periodText(from: string, to: string): string {
  if (!from || !to) return ''
  return from.slice(0, 4) === to.slice(0, 4) ? `${formatDate(from).slice(0, 6)}–${formatDate(to)}` : `${formatDate(from)}–${formatDate(to)}`
}

/** The result in one chip: back payment, credit, or billed through the landlord */
export function resultChip(s: Pick<UtilitySummary, 'balance' | 'billedIn' | 'deliveredLate'> & { fields: { bezahlt?: string } }): { text: string; tone: ChipTone } {
  if (s.balance === null) return { text: s.billedIn ? 'Über den Vermieter' : 'Ohne Vorauszahlung', tone: 'neutral' }
  if (Math.abs(s.balance) < 0.005) return { text: 'Ausgeglichen', tone: 'ok' }
  if (s.balance < 0) return { text: `Guthaben ${euro(-s.balance)}`, tone: 'ok' }
  if (s.deliveredLate) return { text: 'Nachzahlung entfällt', tone: 'ok' }
  if (s.fields.bezahlt) return { text: `Nachgezahlt ${euro(s.balance)}`, tone: 'neutral' }
  return { text: `Nachzahlung ${euro(s.balance)}`, tone: 'soon' }
}

/** "+2,2 %", "−4,0 %"; nothing to compare gives "" */
export function changeText(change: number | null): string {
  if (change === null) return ''
  if (change === 0) return '±0 %'
  return `${change > 0 ? '+' : '−'}${formatNumber(Math.abs(change), 1)} %`
}

/** Consumption difference of billed against read: only notable above 5 % */
export function checkTone(difference: number | null): ChipTone {
  if (difference === null) return 'neutral'
  return Math.abs(difference) > 5 ? 'soon' : 'ok'
}

/** Kinds of cost from earlier statements of the same kind, for a new one */
export function kindsFrom(statements: { kind: string; positions: { kind: string }[] }[], kind: string): string[] {
  const seen = new Set<string>()
  for (const s of statements) {
    if (s.kind !== kind) continue
    for (const p of s.positions) seen.add(p.kind)
  }
  return [...seen]
}
