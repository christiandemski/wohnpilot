/**
 * QR stickers. A sticker holds a short address like "/q/z/strom-haupt" that
 * the app resolves when scanned, so what a sticker opens can change later
 * without printing it again. Only `id`s go into the code, they never change.
 */

export type StickerKind = 'z' | 'g' | 'w' | 'n'

export interface Sticker {
  kind: StickerKind
  id: string
  title: string
  /** Meter number, set in the number font */
  number?: string
  detail: string
}

/** What scanning does, in words for the sticker */
export const ACTIONS: Record<StickerKind, string> = {
  z: 'Scannen zum Ablesen',
  g: 'Scannen für Gerät und Garantie',
  w: 'Scannen für Anleitung',
  n: 'Scannen für das Notfallblatt',
}

export function qrPath(kind: StickerKind, id: string): string {
  return `/q/${kind}/${encodeURIComponent(id)}`
}

/** Where a scanned sticker leads; unknown kinds go to the overview. */
export function qrTarget(kind: string, id: string): string {
  const safe = encodeURIComponent(id)
  switch (kind) {
    case 'z':
      return `/ablesen/${safe}`
    case 'g':
      return `/geraete/${safe}`
    case 'w':
      return `/wissen/${safe}`
    case 'n':
      return '/notfall'
    default:
      return '/'
  }
}

export interface SheetFormat {
  id: string
  name: string
  hint: string
  columns: number
  rows: number
  /** all in mm */
  width: number
  height: number
  top: number
  left: number
  gapX: number
  gapY: number
  /** Inside each sticker; more on sheets without margin, which printers cannot reach */
  padding: number
  /** Cut marks around each sticker, for plain paper */
  cut: boolean
}

export const FORMATS: SheetFormat[] = [
  { id: '70x37', name: '70 × 37 mm, 24 je Bogen', hint: 'z. B. Avery Zweckform 3474', columns: 3, rows: 8, width: 70, height: 37, top: 0.5, left: 0, gapX: 0, gapY: 0, padding: 4, cut: false },
  { id: '48x25', name: '48,5 × 25,4 mm, 40 je Bogen', hint: 'z. B. Avery Zweckform 3657', columns: 4, rows: 10, width: 48.5, height: 25.4, top: 21.5, left: 8, gapX: 0, gapY: 0, padding: 2, cut: false },
  { id: 'papier', name: 'Normales Papier, 18 je Bogen', hint: 'Zum Ausschneiden, 60 × 40 mm mit Schnittkanten', columns: 3, rows: 6, width: 60, height: 40, top: 16, left: 10, gapX: 5, gapY: 5, padding: 3, cut: true },
]

/** Stickers spread over sheets; `skip` leaves the first places of the first sheet free (already used). */
export function sheets<T>(stickers: T[], format: Pick<SheetFormat, 'columns' | 'rows'>, skip = 0): (T | null)[][] {
  const perSheet = format.columns * format.rows
  const places: (T | null)[] = [...Array<null>(Math.min(Math.max(0, skip), perSheet - 1)).fill(null), ...stickers]
  const result: (T | null)[][] = []
  for (let i = 0; i < places.length; i += perSheet) {
    const sheet = places.slice(i, i + perSheet)
    result.push([...sheet, ...Array<null>(perSheet - sheet.length).fill(null)])
  }
  return result
}
