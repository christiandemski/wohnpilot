/**
 * Logic of the roll counter (Rollenzaehler): a meter value as a row of
 * digit wheels, and the plausibility check against the last reading.
 * The check mirrors backend/src/Domain/Plausibility.php.
 */

export interface Wheels {
  digits: number[]
  decimals: number
}

/** 23702.0 with 5 + 1 places → [2,3,7,0,2,0] */
export function toWheels(value: number, places: number, decimals: number): Wheels {
  const scaled = Math.round(Math.abs(value) * 10 ** decimals)
  const text = String(scaled).padStart(places + decimals, '0').slice(-(places + decimals))
  return { digits: [...text].map(Number), decimals }
}

export function fromWheels(wheels: Wheels): number {
  const scaled = Number(wheels.digits.join(''))
  return scaled / 10 ** wheels.decimals
}

/** Turns one wheel by ±1, 9 → 0 and 0 → 9 like a real counter wheel; other wheels stay. */
export function turn(wheels: Wheels, index: number, step: 1 | -1): Wheels {
  const digits = wheels.digits.slice()
  digits[index] = (digits[index] + step + 10) % 10
  return { ...wheels, digits }
}

/** Number of integer places shown: at least the meter's, more if the value needs it. */
export function placesFor(value: number, meterPlaces: number): number {
  return Math.max(meterPlaces, String(Math.trunc(Math.abs(value))).length)
}

export type CheckResult = 'first' | 'ok' | 'lower' | 'high'

export const HIGH_FACTOR = 2
const MIN_EXPECTED = 1

export interface Check {
  result: CheckResult
  delta: number | null
  days: number | null
  perDay: number | null
}

export function check(
  value: number,
  last: { value: number; days: number } | null,
  typicalDailyRate: number | null,
  highFactor = HIGH_FACTOR,
): Check {
  if (last === null) return { result: 'first', delta: null, days: null, perDay: null }
  const delta = value - last.value
  const perDay = last.days > 0 ? delta / last.days : null
  const expected = typicalDailyRate !== null && last.days > 0 ? typicalDailyRate * last.days : null
  const threshold = expected !== null ? Math.max(expected * highFactor, MIN_EXPECTED) : null
  // Compare on the meter's resolution so 23 702,0 − 23 702,0 is never "lower" by float noise.
  const rounded = Math.round(delta * 1e6) / 1e6
  const result: CheckResult = rounded < 0 ? 'lower' : threshold !== null && rounded > threshold ? 'high' : 'ok'
  return { result, delta, days: last.days, perDay }
}
