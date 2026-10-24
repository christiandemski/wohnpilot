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
  agenda: AgendaItem[]
  figures: Figures
}

export type AgendaGroup = 'ueberfaellig' | 'woche' | 'bald' | 'spaeter' | 'hinweis'

/** One dated thing of "Was steht an" (AgendaService); kind decides which extra fields are set. */
export interface AgendaItem {
  ref: { type: 'vertrag' | 'mietvertrag' | 'frist' | 'ablesung' | 'wartung' | 'termin' | 'mangel' | 'geraet' | 'muell'; id: string | null }
  name: string | null
  category: string | null
  kind: 'kuendigung' | 'vertragsende' | 'sonderkuendigung' | 'zustimmung' | 'frist' | 'ablesung' | 'wartung' | 'termin' | 'nachfassen' | 'garantie' | 'muell'
  date: string
  daysUntil: number
  status: DueStatus | 'open'
  group: AgendaGroup
  periodEnd?: string
  renewsMonths?: number | null
  countdownFrom?: string | null
  cancelled?: boolean
  effective?: string
  validFrom?: string
  received?: string
  coldRent?: number | null
  from?: string | null
  meterIds?: string[]
  /** wartung */
  lastDone?: string | null
  appointment?: boolean
  responsible?: string
  /** termin */
  time?: string | null
  until?: string | null
  place?: string | null
  /** nachfassen */
  defectStatus?: string
  /** garantie */
  which?: 'garantie' | 'gewaehrleistung'
  /** muell */
  color?: string
  fullService?: boolean
}

export interface Figures {
  contractsMonthly: number
  rentMonthly: number | null
  fixedMonthly: number
  nextNotice: {
    id: string
    name: string
    category: string
    date: string
    daysUntil: number
    status: DueStatus
    countdownFrom: string | null
  } | null
  /** null while the house modules are not there */
  openDefects: number | null
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

export interface PhotoRef {
  path: string
  alt: string
  url: string
}

export interface MaintenanceEvaluation {
  dueDate: string
  /** Seasonal tasks: first day it may be done */
  from: string | null
  daysUntil: number
  status: DueStatus
  lastDone: string | null
  inSeason: boolean | null
  missedSeason: boolean
  /** Checked by radio: never due */
  automatic: boolean
}

export interface MaintenanceSummary {
  id: string
  path: string
  etag: string
  title: string
  fields: {
    titel: string
    intervall_monate?: number
    intervall_wochen?: number
    saison?: { von: number; bis: number }
    ab?: string
    zustaendig?: string
    raum?: string
    vorlauf_tage?: number
    pflicht?: string
    ausfuehrung?: 'selbst' | 'termin' | 'funk'
    bezieht_sich_auf?: string[]
    [key: string]: unknown
  }
  evaluation: MaintenanceEvaluation
  modifiedBy: string | null
}

export interface MaintenanceDetail extends MaintenanceSummary {
  history: { date: string; by: string; note: string }[]
  photos: PhotoRef[]
  notes: string
}

export interface MaintenanceSuggestion {
  id: string
  titel: string
  intervall_monate: number
  zustaendig: string
  saison: { von: number; bis: number } | null
  raum: string | null
  grund: string
}

export interface Appointment {
  id: string
  path: string
  etag: string
  title: string
  date: string
  time: string | null
  daysUntil: number
  fields: { titel: string; datum: string; uhrzeit?: string; bis?: string; ort?: string; bezieht_sich_auf?: string[]; [key: string]: unknown }
  modifiedBy: string | null
  notes?: string
}

export type DefectStatus = 'entdeckt' | 'gemeldet' | 'bestaetigt' | 'termin' | 'behoben'

export interface DefectSummary {
  id: string
  path: string
  etag: string
  title: string
  fields: { titel: string; raum?: string; zustaendig?: string; kleinreparatur?: boolean; kosten_eur?: number; nachfassen?: string; [key: string]: unknown }
  evaluation: {
    status: DefectStatus
    open: boolean
    discovered: string | null
    reported: string | null
    fixedOn: string | null
    days: number | null
    followUp: { date: string; daysUntil: number; status: DueStatus } | null
  }
  photoCount: number
  modifiedBy: string | null
}

export interface DefectDetail extends DefectSummary {
  history: { date: string; status: string; channel: string; note: string }[]
  photos: PhotoRef[]
  notes: string
}

export interface MinorRepairYear {
  year: number
  cases: { id: string; title: string; date: string; cost: number; overLimit: boolean }[]
  total: number
  perCase: number | null
  perYear: number | null
  left: number | null
  overYear: boolean
}

export interface DeviceEnd {
  until: string
  daysUntil: number
  status: DueStatus
}

export interface DeviceSummary {
  id: string
  path: string
  etag: string
  name: string
  fields: {
    name: string
    hersteller?: string
    modell?: string
    seriennummer?: string
    raum?: string
    eigentum?: string
    kaufdatum?: string
    haendler?: string
    preis_eur?: number
    garantie_monate?: number
    garantie_bis?: string
    baujahr?: number
    paperless?: number | string
    [key: string]: unknown
  }
  evaluation: { warranty: DeviceEnd | null; liability: DeviceEnd | null }
  photoCount: number
  modifiedBy: string | null
}

export interface DeviceDetail extends DeviceSummary {
  maintenance: { id: string; title: string; evaluation: MaintenanceEvaluation }[]
  photos: PhotoRef[]
  notes: string
}

export interface Bin {
  id: string
  name: string
  farbe: string
  stichwort?: string
}

export interface Waste {
  configured: boolean
  id: string | null
  etag: string | null
  fullService: boolean
  url: string | null
  bins: Bin[]
  pickups: { date: string; time: string | null; summary: string; bin: string | null }[]
  next: Record<string, string>
  fetchedAt: number | null
  error: string | null
}

export interface Settings {
  paperlessUrl: string | null
  readingDay: number
}

export interface Contact {
  id: string
  path: string
  etag: string
  /** '' = belongs to the household, else to that home */
  object: string
  name: string
  fields: {
    name: string
    firma?: string
    rolle?: string
    gewerk?: string
    telefon?: string
    mobil?: string
    email?: string
    adresse?: string
    notfall?: string[]
    [key: string]: unknown
  }
  phone: string | null
  mobile: string | null
  modifiedBy: string | null
}

export interface ContactDetail extends Contact {
  usedIn: { type: string; id: string; title: string }[]
  notes: string
}

export interface DocumentRef {
  path: string
  title: string
  url: string
}

export interface KnowledgePage {
  id: string
  path: string
  etag: string
  title: string
  category: string
  fields: { titel: string; kategorie?: string; raum?: string; notfall?: string[]; szenario?: string; bezieht_sich_auf?: string[]; [key: string]: unknown }
  modifiedBy: string | null
}

export interface KnowledgeDetail extends KnowledgePage {
  text: string
  photos: PhotoRef[]
  documents: DocumentRef[]
}

export interface EmergencyScenario {
  id: string
  steps: { id: string; text: string } | null
  places: { id: string; title: string; room: string; hint: string; photo: PhotoRef | null }[]
  contacts: { id: string; name: string; role: string; phone: string | null; mobile: string | null }[]
}

export interface SearchResult {
  type: string
  id: string
  title: string
  snippet: string
  score: number
}
