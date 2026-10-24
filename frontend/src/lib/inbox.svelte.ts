import { api } from './api'
import { inStore } from './idb'
import type { ContractSummary, Deadline, DefectSummary, DeviceSummary, KnowledgePage, Lease } from './types'

/**
 * The inbox ("Eingang"): photos, PDFs and texts shared to the app (Android
 * share menu) or added on the inbox page (iPhone: choose or paste). They
 * stay on this device until you assign them to an entry.
 */

export interface InboxItem {
  id: string
  kind: 'image' | 'pdf' | 'text'
  name: string
  blob?: Blob
  text?: string
  createdAt: string
}

/** Where a file can go: the entry types with documents and photos (backend DocumentService, PhotoService) */
export interface Target {
  type: 'mangel' | 'geraet' | 'vertrag' | 'mietvertrag' | 'wissen' | 'frist'
  label: string
  href: (id: string) => string
  load: () => Promise<{ id: string; title: string }[]>
}

export const TARGETS: Target[] = [
  { type: 'mangel', label: 'Mangel', href: (id) => `/maengel/${encodeURIComponent(id)}`, load: async () => (await api.get<{ defects: DefectSummary[] }>('/defects')).defects.map((d) => ({ id: d.id, title: d.title })) },
  { type: 'vertrag', label: 'Vertrag', href: (id) => `/vertraege/${encodeURIComponent(id)}`, load: async () => (await api.get<{ contracts: ContractSummary[] }>('/contracts')).contracts.map((c) => ({ id: c.id, title: c.name })) },
  {
    type: 'mietvertrag',
    label: 'Mietvertrag',
    href: () => '/mietvertrag',
    load: async () => {
      const lease = (await api.get<{ lease: Lease | null }>('/lease')).lease
      return lease ? [{ id: lease.id, title: 'Mietvertrag' }] : []
    },
  },
  { type: 'geraet', label: 'Gerät', href: (id) => `/geraete/${encodeURIComponent(id)}`, load: async () => (await api.get<{ devices: DeviceSummary[] }>('/devices')).devices.map((d) => ({ id: d.id, title: d.name })) },
  { type: 'frist', label: 'Frist', href: (id) => `/fristen/${encodeURIComponent(id)}`, load: async () => (await api.get<{ open: Deadline[] }>('/deadlines')).open.map((d) => ({ id: d.id, title: d.title })) },
  { type: 'wissen', label: 'Wissen', href: (id) => `/wissen/${encodeURIComponent(id)}`, load: async () => (await api.get<{ pages: KnowledgePage[] }>('/knowledge')).pages.map((p) => ({ id: p.id, title: p.title })) },
]

/** "Brief_Vermieter-2026.pdf" → "Brief Vermieter 2026"; an empty name gets the day */
export function titleFrom(name: string, kind: InboxItem['kind'], day: string): string {
  const base = name.replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim()
  // Camera names like "IMG_20261024_101500" or "PXL_…" say nothing
  if (base === '' || /^(img|pxl|dsc|photo|image|scan)\s?\d/i.test(base)) return `${kind === 'pdf' ? 'Dokument' : 'Foto'} vom ${day}`
  return base
}

export function kindOf(type: string): InboxItem['kind'] | null {
  if (type === 'application/pdf') return 'pdf'
  if (type.startsWith('image/')) return 'image'
  return null
}

class Inbox {
  items = $state<InboxItem[]>([])

  async load(): Promise<void> {
    try {
      this.items = (await inStore('eingang', 'readonly', (s) => s.getAll() as IDBRequest<InboxItem[]>)).sort((a, b) => b.createdAt.localeCompare(a.createdAt))
    } catch {
      this.items = []
    }
  }

  async add(items: Omit<InboxItem, 'id' | 'createdAt'>[]): Promise<void> {
    const now = new Date().toISOString()
    const added = items.map((item) => ({ ...item, id: crypto.randomUUID(), createdAt: now }))
    this.items = [...added, ...this.items]
    try {
      for (const item of added) await inStore('eingang', 'readwrite', (s) => s.put(item))
    } catch {
      // kept for this visit only
    }
  }

  async remove(id: string): Promise<void> {
    this.items = this.items.filter((i) => i.id !== id)
    try {
      await inStore('eingang', 'readwrite', (s) => s.delete(id))
    } catch {
      // was only in memory
    }
  }

  async clear(): Promise<void> {
    this.items = []
    try {
      await inStore('eingang', 'readwrite', (s) => s.clear())
    } catch {
      // nothing stored
    }
  }
}

export const inbox = new Inbox()

/** A shared text handed to a new defect or knowledge page, without putting it in the URL */
const DRAFT = 'wohnpilot-draft'

export function handDraft(draft: Draft): void {
  try {
    sessionStorage.setItem(DRAFT, JSON.stringify(draft))
  } catch {
    // the form starts empty
  }
}

export function takeDraft(): Draft | null {
  try {
    const raw = sessionStorage.getItem(DRAFT)
    sessionStorage.removeItem(DRAFT)
    return raw ? (JSON.parse(raw) as Draft) : null
  } catch {
    return null
  }
}

export interface Draft {
  titel: string
  text: string
  /** The text after the title on one line, for short fields */
  rest: string
}

/** First line as title (shortened), the whole text, and the rest */
export function splitText(text: string): Draft {
  const lines = text.trim().split(/\r?\n/)
  const first = lines[0].trim()
  const long = first.length > 80
  const titel = long ? `${first.slice(0, 77).trimEnd()} …` : first
  const rest = (long ? lines : lines.slice(1)).map((l) => l.trim()).filter(Boolean).join(' ')
  return { titel, text: text.trim(), rest }
}
