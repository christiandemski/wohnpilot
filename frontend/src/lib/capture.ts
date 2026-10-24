/** Choices of the "Erfassen" sheet behind the plus button; the last one used comes first. */

export interface CaptureChoice {
  id: string
  label: string
  meta: string
  href: string
  module: string
}

export const CHOICES: CaptureChoice[] = [
  { id: 'ablesen', label: 'Zählerstände ablesen', meta: 'Ablese-Runde durch alle Zähler', href: '/ablesen', module: 'zaehler' },
  { id: 'mangel', label: 'Mangel melden', meta: 'Mit Foto, Raum und Status', href: '/maengel/neu', module: 'maengel' },
  { id: 'wartung', label: 'Wartung erledigt', meta: 'Aufgabe abhaken', href: '/wartung', module: 'wartung' },
  { id: 'termin', label: 'Termin eintragen', meta: 'Handwerker, Schornsteinfeger, Besichtigung', href: '/termine/neu', module: 'termine' },
  { id: 'frist', label: 'Frist anlegen', meta: 'Brief mit Stichtag, Ablese-Aufforderung', href: '/fristen/neu', module: 'vertraege' },
  { id: 'geraet', label: 'Gerät anlegen', meta: 'Kaufdatum, Garantie, Typenschild', href: '/geraete/neu', module: 'geraete' },
  { id: 'eingang', label: 'Datei oder Text ablegen', meta: 'Brief, Rechnung, Foto – später zuordnen', href: '/eingang', module: 'wissen' },
]

const KEY = 'wohnpilot.erfassen.zuletzt'

/** The choices with the last used one on top, the others in their usual order. */
export function ordered(lastUsed: string | null, choices: CaptureChoice[] = CHOICES): CaptureChoice[] {
  const last = choices.find((c) => c.id === lastUsed)
  return last ? [last, ...choices.filter((c) => c !== last)] : choices
}

/** Per device and only a convenience: storage may be blocked (private mode). */
export function readLastUsed(): string | null {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}

export function rememberLastUsed(id: string): void {
  try {
    localStorage.setItem(KEY, id)
  } catch {
    // Not remembered, the order stays as it is.
  }
}
