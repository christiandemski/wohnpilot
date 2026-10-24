/** German texts for contacts, knowledge and the emergency sheet; the API sends codes only. */

export const ROLES: Record<string, string> = {
  vermieter: 'Vermieter',
  hausverwaltung: 'Hausverwaltung',
  handwerker: 'Handwerker',
  notdienst: 'Notdienst',
  versorger: 'Versorger',
  nachbar: 'Nachbar',
  versicherung: 'Versicherung',
  sonstiges: 'Sonstiges',
}

export const CATEGORIES: Record<string, string> = {
  ort: 'Wo ist was',
  kennwert: 'Kennwerte',
  bedienung: 'Bedienung',
  notfall: 'Notfall',
  sonstiges: 'Sonstiges',
}

export const SCENARIOS: Record<string, string> = {
  wasser: 'Wasserrohrbruch',
  strom: 'Stromausfall',
  gas: 'Gasgeruch',
  heizung: 'Heizung fällt aus',
}

/** Steps until you write your own page for the emergency. */
export const DEFAULT_STEPS: Record<string, string> = {
  wasser: '1. Haupthahn zudrehen.\n2. Strom in nassen Räumen am Sicherungskasten abschalten.\n3. Wasser aufnehmen, Fotos machen.\n4. Vermieter anrufen, danach die Versicherung.',
  strom: '1. Prüfen, ob die Nachbarn auch keinen Strom haben.\n2. Wenn nein: Sicherungskasten, FI-Schalter wieder einschalten.\n3. Springt er sofort wieder raus: Geräte abstecken, einzeln wieder einstecken.\n4. Ist die ganze Straße betroffen: Störungsnummer des Netzbetreibers.',
  gas: '1. Kein Licht, keine Schalter, kein Handy im Haus – keine Funken.\n2. Fenster und Türen öffnen, Haus verlassen.\n3. Draußen die Störungsnummer anrufen oder 112.\n4. Nachbarn warnen.',
  heizung: '1. Druck an der Anlage prüfen (meist 1–2 bar).\n2. Störungsanzeige notieren, einmal die Entstörtaste drücken.\n3. Geht es nicht: Vermieter oder Heizungsnotdienst anrufen.',
}

/** "0800 123 45" → "tel:080012345" */
export function telHref(number: string): string {
  return 'tel:' + number.replace(/[^\d+]/g, '')
}

/** Placeholders like "[Telefon]" from the examples are no data. */
export function isPlaceholder(value: unknown): boolean {
  return typeof value === 'string' && /^\[.*\]$/.test(value.trim())
}

/** Where an entry of a search or of "kommt vor in" opens. */
export function routeFor(type: string, id: string): string | null {
  const e = encodeURIComponent(id)
  const routes: Record<string, string> = {
    zaehler: `/zaehler/${e}`,
    vertrag: `/vertraege/${e}`,
    mietvertrag: '/mietvertrag',
    frist: `/fristen/${e}`,
    wartung: `/wartung/${e}`,
    termin: `/termine/${e}`,
    muell: '/termine/muell',
    mangel: `/maengel/${e}`,
    geraet: `/geraete/${e}`,
    kontakt: `/kontakte/${e}`,
    wissen: `/wissen/${e}`,
    objekt: '/zuhause',
  }
  return routes[type] ?? null
}

export const TYPE_NAMES: Record<string, string> = {
  zaehler: 'Zähler',
  vertrag: 'Vertrag',
  mietvertrag: 'Mietvertrag',
  frist: 'Frist',
  wartung: 'Wartung',
  termin: 'Termin',
  muell: 'Müll',
  mangel: 'Mangel',
  geraet: 'Gerät',
  kontakt: 'Kontakt',
  wissen: 'Wissen',
  objekt: 'Zuhause',
}
