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

export type TermState = 'running' | 'expires' | 'cancelled' | 'ended' | 'open'

/** ContractTerm::toArray: only the fields that fit the state are set. */
export interface ContractTerm {
  state: TermState
  periodStart: string | null
  countdownFrom: string | null
  periodEnd: string | null
  noticeBy: string | null
  daysUntilNotice: number | null
  status: DueStatus | null
  renewsMonths: number | null
  endsOn: string | null
  daysUntilEnd: number | null
  endIfCancelledToday: string | null
}

export interface PriceChange {
  effective: string
  noticeBy: string
  daysUntil: number
  status: DueStatus
}

export interface ContractFields {
  typ: 'vertrag'
  id: string
  kategorie: string
  sparte?: string
  anbieter: string
  bezeichnung?: string
  vertragsnummer?: string
  kosten_eur?: number
  rhythmus?: string
  beginn?: string
  mindestlaufzeit_monate?: number
  verlaengerung_monate?: number
  kuendigungsfrist_wochen?: number
  kuendigungsfrist_monate?: number
  gekuendigt_am?: string
  gekuendigt_zum?: string
  vorlauf_tage?: number
  paperless?: number | string
  versicherungssumme_eur?: number
  selbstbeteiligung_eur?: number
  bezieht_sich_auf?: string[]
  [key: string]: unknown
}

export interface ContractSummary {
  id: string
  path: string
  etag: string
  name: string
  fields: ContractFields
  insurance: boolean
  monthlyCost: number | null
  term: ContractTerm
  ended: boolean
  priceChanges: PriceChange[]
  modifiedBy: string | null
}

export interface ContractDetail extends ContractSummary {
  prices: { columns: string[]; rows: Record<string, string>[] } | null
  notes: string
}

export interface RentChange {
  validFrom: string
  coldRent: number | null
  utilities: number | null
  reason: string
  received: string | null
  agreed: string | null
}

export interface Lease {
  id: string
  path: string
  etag: string
  fields: {
    vermieter?: string
    beginn?: string
    mietart?: string
    kaltmiete_eur?: number
    nebenkosten_eur?: number
    kuendigungsfrist_monate?: number
    kaution?: { betrag_eur?: number; art?: string; hinterlegt?: string }
    paperless?: number | string
    [key: string]: unknown
  }
  rent: { cold: number | null; utilities: number | null; validFrom: string | null }
  history: RentChange[]
  consentDeadlines: { validFrom: string; received: string; consentBy: string; daysUntil: number; status: DueStatus; coldRent: number | null }[]
  tenantNotice: { noticeBy: string; endsOn: string; daysUntil: number }
  notes: string
  modifiedBy: string | null
}

export interface DeadlineEvaluation {
  status: DueStatus | 'done'
  daysUntil: number
  doneOn: string | null
  doneBy: 'manual' | 'readings' | null
  meters: { id: string; readOn: string | null }[]
}

export interface Deadline {
  id: string
  path: string
  etag: string
  object: string
  title: string
  fields: {
    titel: string
    datum: string
    ab?: string
    vorlauf_tage?: number
    bezieht_sich_auf?: string[]
    erledigt?: string
    [key: string]: unknown
  }
  windowStart: string
  evaluation: DeadlineEvaluation
  modifiedBy: string | null
  notes?: string
}
