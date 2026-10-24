import { dueChip } from './contracts'
import { formatDate, formatDay, formatNumber } from './format'
import type { ChipTone } from './meters'
import type { DefectStatus, DeviceEnd, MaintenanceEvaluation } from './types'

/** German texts for maintenance, defects, devices and the waste calendar; the API sends codes only. */

export const RESPONSIBLE: Record<string, string> = {
  mieter: 'Du',
  vermieter: 'Vermieter',
  dienstleister: 'Dienstleister',
}

/** How a task gets done, e.g. smoke detectors: tested yourself, by a service with an appointment, or by radio. */
export const EXECUTIONS: Record<string, string> = {
  selbst: 'Selbst',
  termin: 'Extern mit Termin',
  funk: 'Automatisch per Funk',
}

export const OWNERS: Record<string, string> = { mieter: 'Deins', vermieter: 'Vom Vermieter' }

export const DEFECT_STATUSES: Record<DefectStatus, string> = {
  entdeckt: 'Entdeckt',
  gemeldet: 'Gemeldet',
  bestaetigt: 'Bestätigt',
  termin: 'Handwerker-Termin',
  behoben: 'Behoben',
}

export const HEATING: Record<string, string> = {
  gas: 'Gas',
  oel: 'Öl',
  pellets: 'Pellets',
  waermepumpe: 'Wärmepumpe',
  fernwaerme: 'Fernwärme',
  kamin: 'Kamin oder Kaminofen',
  strom: 'Strom (Nachtspeicher)',
}

export const DUTIES: Record<string, string> = {
  garten: 'Garten',
  winterdienst: 'Winterdienst',
  gehweg: 'Gehweg kehren',
  treppenhaus: 'Treppenhaus',
  dachrinne: 'Dachrinne',
  muelltonnen: 'Mülltonnen rausstellen',
}

export const BIN_COLORS: Record<string, string> = {
  'bin-rest': 'Grau',
  'bin-bio': 'Braun',
  'bin-papier': 'Blau',
  'bin-gelb': 'Gelb',
  'bin-glas': 'Grün',
}

const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']
const MONTHS_SHORT = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.']

export const MONTH_NAMES = MONTHS

/** From here on a due date shows month and year instead of the day. */
const FAR_DAYS = 120

/** "alle 12 Monate" → "jährlich" and so on */
export function intervalText(months?: number, weeks?: number): string {
  if (weeks) return weeks === 1 ? 'wöchentlich' : `alle ${weeks} Wochen`
  if (!months) return ''
  if (months === 12) return 'jährlich'
  if (months === 6) return 'halbjährlich'
  if (months === 3) return 'vierteljährlich'
  if (months === 1) return 'monatlich'
  if (months % 12 === 0) return `alle ${months / 12} Jahre`
  return `alle ${months} Monate`
}

/** "Oktober bis Februar" */
export function seasonText(season?: { von: number; bis: number } | null): string {
  if (!season) return ''
  return season.von === season.bis ? `im ${MONTHS[season.von - 1]}` : `${MONTHS[season.von - 1]} bis ${MONTHS[season.bis - 1]}`
}

/**
 * Chip of a task: like a deadline, but a seasonal task before its window
 * only says when it can start ("ab Okt.").
 */
export function maintenanceChip(e: MaintenanceEvaluation, today: string): { text: string; tone: ChipTone } {
  if (e.automatic) return { text: 'Per Funk', tone: 'ok' }
  if (e.from && today < e.from) {
    const [, m] = e.from.split('-').map(Number)
    return { text: `ab ${MONTHS_SHORT[m - 1]}`, tone: 'neutral' }
  }
  if (e.lastDone === null && e.daysUntil === 0) return { text: 'Jetzt', tone: 'soon' }
  // Months away: "Okt. 2027" says more than a day without a year.
  if (e.daysUntil > FAR_DAYS) {
    const [y, m] = e.dueDate.split('-').map(Number)
    return { text: `${MONTHS_SHORT[m - 1]} ${y}`, tone: 'neutral' }
  }
  return dueChip({ date: e.dueDate, daysUntil: e.daysUntil, status: e.status })
}

/** Line below a task: "jährlich · zuletzt 08.11.2025" or "noch nie erledigt" */
export function maintenanceMeta(
  fields: { intervall_monate?: number; intervall_wochen?: number; saison?: { von: number; bis: number }; ausfuehrung?: string },
  e: MaintenanceEvaluation,
): string {
  if (e.automatic) return 'Wird automatisch per Funk geprüft'
  const parts = [intervalText(fields.intervall_monate, fields.intervall_wochen)]
  if (fields.ausfuehrung === 'termin') parts.push('mit Termin')
  if (fields.saison) parts.push(seasonText(fields.saison))
  parts.push(e.lastDone ? `zuletzt ${formatDate(e.lastDone)}` : 'noch nie erledigt')
  return parts.filter(Boolean).join(' · ')
}

/** "Garantie bis 19.11.2027", "Gewährleistung endet in 26 Tagen", "abgelaufen am …" */
export function endText(label: string, end: DeviceEnd): string {
  if (end.daysUntil < 0) return `${label} abgelaufen am ${formatDate(end.until)}`
  if (end.status === 'soon' || end.status === 'due') return `${label} endet ${end.daysUntil === 0 ? 'heute' : `in ${end.daysUntil === 1 ? 'einem Tag' : `${formatNumber(end.daysUntil)} Tagen`}`} (${formatDay(end.until)})`
  return `${label} bis ${formatDate(end.until)}`
}

/** Why the waste calendar could not be loaded, for the person. */
export function wasteError(code: string | null): string {
  if (code === null) return ''
  const texts: Record<string, string> = {
    unreachable: 'Der Abfuhrkalender ist gerade nicht erreichbar.',
    private_address: 'Diese Adresse liegt in einem privaten Netz und wird nicht abgerufen.',
    not_a_calendar: 'Unter dieser Adresse liegt kein Kalender (ICS).',
    too_large: 'Der Kalender ist zu groß.',
    url: 'Die Adresse stimmt nicht.',
  }
  return texts[code] ?? (code.startsWith('http_') ? `Der Server des Entsorgers antwortet mit Fehler ${code.slice(5)}.` : 'Der Abfuhrkalender ließ sich nicht laden.')
}

/** Paperless link to a document, or null while Paperless is off. */
export function paperlessLink(base: string | null, id: unknown): string | null {
  if (!base || (typeof id !== 'number' && typeof id !== 'string') || String(id).trim() === '') return null
  return `${base}/documents/${encodeURIComponent(String(id))}/details`
}

/** Why a task is suggested: "Weil du mit Gas heizt" */
export function suggestionReason(code: string): string {
  const [kind, value] = code.split(':')
  if (kind === 'heizung') return `Weil du mit ${HEATING[value] ?? value} heizt`
  if (kind === 'pflicht') return `${DUTIES[value] ?? value} ist laut Mietvertrag deine Sache`
  if (kind === 'haus') return 'Weil du in einem Haus wohnst'
  return 'Für jedes Zuhause'
}
