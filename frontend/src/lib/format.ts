/**
 * German formatting: a dot between thousands, a decimal comma ("23.702,0").
 * Done by hand so the result is the same in every browser.
 */

export const THOUSANDS = '.'
export const MINUS = '−'

/** 23702 → "23.702,0" (decimals = 1) */
export function formatNumber(value: number, decimals = 0): string {
  const fixed = Math.abs(value).toFixed(decimals)
  const [whole, fraction] = fixed.split('.')
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS)
  const isZero = Number(fixed) === 0
  return (value < 0 && !isZero ? MINUS : '') + grouped + (fraction ? ',' + fraction : '')
}

/** Like formatNumber, always with a sign: "+246,0", "−2,0", "±0" */
export function formatDelta(value: number, decimals = 0): string {
  const text = formatNumber(value, decimals)
  if (Number(Math.abs(value).toFixed(decimals)) === 0) return '±' + text
  return value > 0 ? '+' + text : text
}

/** 70.4 → "70 €"; with cents → "70,40 €" */
export function formatEuro(value: number, cents = false): string {
  return formatNumber(value, cents ? 2 : 0) + ' €'
}

/** −0.08 → "−8 %" */
export function formatPercent(ratio: number): string {
  const percent = Math.round(ratio * 100)
  return (percent > 0 ? '+' : '') + formatNumber(percent) + ' %'
}

/**
 * Reads what a person types: "23702", "23.702,5", "23 702,5", "23702,5", "1.450"
 * (dots between groups of three are thousands) and "23702.5".
 */
export function parseNumber(text: string): number | null {
  let t = text.trim().replace(/[\s  ]/g, '').replace(MINUS, '-')
  if (t === '') return null
  if (t.includes(',')) {
    if (!/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/.test(t)) return null
    t = t.replace(/\./g, '').replace(',', '.')
  } else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) {
    t = t.replace(/\./g, '')
  }
  const value = Number(t)
  return Number.isFinite(value) ? value : null
}

const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag']
const WEEKDAYS_SHORT = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa']
const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']
const MONTHS_SHORT = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.']

function parts(iso: string): { y: number; m: number; d: number; weekday: number } {
  const [y, m, d] = iso.split('-').map(Number)
  return { y, m, d, weekday: new Date(Date.UTC(y, m - 1, d)).getUTCDay() }
}

/** Running text: "24.10." */
export function formatDay(iso: string): string {
  const { m, d } = parts(iso)
  return `${String(d).padStart(2, '0')}.${String(m).padStart(2, '0')}.`
}

/** Tables: "24.10.2026" */
export function formatDate(iso: string): string {
  return formatDay(iso) + parts(iso).y
}

/** Heading: "Samstag, 24. Okt." */
export function formatLongDay(iso: string): string {
  const { m, d, weekday } = parts(iso)
  return `${WEEKDAYS[weekday]}, ${d}. ${MONTHS_SHORT[m - 1]}`
}

/** Chip: "Di 27." */
export function formatChipDay(iso: string): string {
  const { d, weekday } = parts(iso)
  return `${WEEKDAYS_SHORT[weekday]} ${d}.`
}

/** "2026-10" → "Oktober" */
export function monthName(month: string): string {
  return MONTHS[Number(month.slice(5, 7)) - 1]
}

/** "2026-10" → "O" (chart axis) */
export function monthInitial(month: string): string {
  return monthName(month)[0]
}

/** Days between two ISO dates ($to − $from). */
export function daysBetween(from: string, to: string): number {
  const a = parts(from)
  const b = parts(to)
  return Math.round((Date.UTC(b.y, b.m - 1, b.d) - Date.UTC(a.y, a.m - 1, a.d)) / 86_400_000)
}

/** "Guten Morgen" / "Guten Tag" / "Guten Abend" */
export function greeting(hour: number): string {
  if (hour < 11) return 'Guten Morgen'
  if (hour < 18) return 'Guten Tag'
  return 'Guten Abend'
}

/** "1 Tag", "3 Tage" */
export function days(n: number): string {
  return `${formatNumber(n)} ${Math.abs(n) === 1 ? 'Tag' : 'Tage'}`
}

/** The day before: end of a period whose end is given exclusive. */
export function previousDay(iso: string): string {
  const { y, m, d } = parts(iso)
  return new Date(Date.UTC(y, m - 1, d - 1)).toISOString().slice(0, 10)
}
