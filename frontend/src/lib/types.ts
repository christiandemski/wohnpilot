/** Shapes of the JSON API (backend/src/App/*Service.php). */

export type DueStatus = 'ok' | 'soon' | 'due'

/** Voluntary reading rhythm: never warns, from the suggested date on it is "open". */
export type ScheduleStatus = 'ok' | 'open'

/** A reading the supplier asks for (a deadline linked to the meter). */
export interface ReadingRequest {
  id: string
  title: string
  date: string
  from: string
  daysUntil: number
  status: DueStatus
}

export interface Reading {
  date: string
  value: number
  occasion: string
  photo: string
  by: string
}

export interface MonthValue {
  month: string
  amount: number | null
  coveredDays: number
  days: number
  previousYear: number | null
}

export interface YearValue {
  year: number
  amount: number
  coveredDays: number
  days: number
}

export interface Tariff {
  arbeitspreis_ct?: number
  grundpreis_eur_monat?: number
  abschlag_eur?: number
}

export interface MeterSummary {
  id: string
  path: string
  etag: string
  name: string | null
  medium: string
  unit: string
  number: string
  room: string
  digits: number
  decimals: number
  interval: string
  tariff: Tariff | null
  latest: Reading | null
  schedule: { dueDate: string; daysUntil: number; status: ScheduleStatus }
  request: ReadingRequest | null
  check: { typicalDailyRate: number | null; highFactor: number }
  currentMonth: MonthValue
  recentMonths: MonthValue[]
  modifiedBy: string | null
}

export interface Estimate {
  periodStart: string
  periodEnd: string
  projectedAmount: number
  cost: number
  paid: number
  balance: number
  suggestedInstalment: number | null
}

export interface MeterDetail extends MeterSummary {
  readings: Reading[]
  months: MonthValue[]
  years: YearValue[]
  estimate: Estimate | null
  notes: string
}

export interface ObjectData {
  id: string
  art: 'haus' | 'wohnung'
  adresse?: string
  einzug?: string
  wohnflaeche_m2?: number
  raeume: string[]
  etag: string
  [key: string]: unknown
}

export interface Overview {
  today: string
  household: { name: string | null }
  object: ObjectData | null
  next: NextReading | null
  meters: MeterSummary[]
}

export type NextReading =
  | { kind: 'ablesung'; dueDate: string; daysUntil: number; status: ScheduleStatus; meterIds: string[] }
  | {
      kind: 'meldung'
      deadlineId: string
      title: string
      from: string
      dueDate: string
      daysUntil: number
      status: DueStatus
      meterIds: string[]
    }

export interface User {
  id: string
  name: string
}
