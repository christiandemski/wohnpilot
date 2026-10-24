/**
 * Meter digits from a photo, read on the phone (Tesseract, no cloud).
 * Tesseract and its model (about 7 MB) load only when the first photo is
 * taken and come from this host. The result is only a suggestion: the
 * person checks it on the counter wheels before saving.
 */

export interface MeterHint {
  /** Integer places of the counter and decimal places (red wheels) */
  places: number
  decimals: number
  /** Last reading, and where the meter should be today by the usual consumption */
  last: number | null
  expected: number | null
  /** Meter number, so its digits are never taken for the value */
  number?: string
}

export interface Candidate {
  value: number
  /** Integer places as read, leading zeros of the wheels included */
  places: number
}

/**
 * Values a piece of OCR text could mean, e.g. "23702 0" → 23702.0 on a
 * 5 + 1 meter. Each run of digits counts as a whole and word by word,
 * since OCR puts gaps between wheels or joins neighbouring print.
 */
export function candidates(text: string, hint: Pick<MeterHint, 'places' | 'decimals' | 'number'>): Candidate[] {
  const numberDigits = (hint.number ?? '').replace(/\D/g, '')
  const found = new Map<number, Candidate>()
  const add = (value: number, places: number) => {
    if (Number.isFinite(value) && !found.has(value)) found.set(value, { value, places })
  }
  const runs = text.match(/\d[\d .,]*\d|\d/g) ?? []
  const tokens = new Set([...runs, ...runs.flatMap((run) => run.split(/\s+/))])
  for (const token of tokens) {
    const digits = token.replace(/\D/g, '')
    if (digits === '') continue
    if (numberDigits.length >= 4 && (numberDigits.includes(digits) || digits.includes(numberDigits))) continue
    // A printed decimal mark: the last one counts
    const mark = Math.max(token.lastIndexOf(','), token.lastIndexOf('.'))
    if (mark > 0 && /\d/.test(token.slice(mark + 1))) {
      const whole = token.slice(0, mark).replace(/\D/g, '')
      const fraction = token.slice(mark + 1).replace(/\D/g, '')
      if (whole !== '') add(Number(`${whole}.${fraction}`), whole.length)
    }
    // The wheels in a row, the red ones last
    if (hint.decimals > 0 && digits.length === hint.places + hint.decimals) {
      add(Number(digits) / 10 ** hint.decimals, hint.places)
    }
    add(Number(digits), digits.length)
  }
  return [...found.values()]
}

/** The most likely value: close to where the meter should be, never below the last reading. */
export function pickReading(text: string, hint: MeterHint): number | null {
  const found = candidates(text, hint)
  if (found.length === 0) return null
  const target = hint.expected ?? hint.last
  if (target === null) {
    // First reading: the one that fills the counter best
    return found.sort((a, b) => Math.abs(a.places - hint.places) - Math.abs(b.places - hint.places) || b.value - a.value)[0].value
  }
  const usable = found.map((c) => c.value).filter((v) => hint.last === null || v >= hint.last)
  if (usable.length === 0) return null
  const best = usable.sort((a, b) => Math.abs(a - target) - Math.abs(b - target))[0]
  // Far off (another number on the meter, a misread wheel): better no suggestion
  const tolerance = Math.max(Math.abs(target) * 0.2, 10 ** (hint.places - 2))
  return Math.abs(best - target) <= tolerance ? best : null
}

type Worker = Awaited<ReturnType<typeof import('tesseract.js')['createWorker']>>
let worker: Promise<Worker> | null = null

/** Tesseract, created on first use and kept for the reading round. */
function getWorker(onProgress?: (share: number) => void): Promise<Worker> {
  worker ??= (async () => {
    const { createWorker, OEM, PSM } = await import('tesseract.js')
    const w = await createWorker('eng', OEM.LSTM_ONLY, {
      workerPath: '/ocr/worker.min.js',
      corePath: '/ocr/',
      langPath: '/ocr/',
      workerBlobURL: false,
      logger: (m: { status: string; progress: number }) => {
        if (m.status.startsWith('loading')) onProgress?.(m.progress)
      },
    })
    await w.setParameters({ tessedit_char_whitelist: '0123456789,.', tessedit_pageseg_mode: PSM.SPARSE_TEXT })
    return w
  })()
  worker.catch(() => (worker = null))
  return worker
}

export async function stopOcr(): Promise<void> {
  const w = worker
  worker = null
  if (w) await (await w).terminate()
}

/** Grey and high in contrast, once as is and once inverted (white digits on black wheels). */
async function prepare(photo: Blob): Promise<HTMLCanvasElement[]> {
  const bitmap = await createImageBitmap(photo)
  const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height))
  const make = (invert: boolean) => {
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    const context = canvas.getContext('2d')!
    context.filter = `grayscale(1) contrast(1.6)${invert ? ' invert(1)' : ''}`
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    return canvas
  }
  const result = [make(false), make(true)]
  bitmap.close()
  return result
}

/** A suggested value for the photo, or null when nothing plausible was found. */
export async function readMeter(photo: Blob, hint: MeterHint, onProgress?: (share: number) => void): Promise<number | null> {
  const w = await getWorker(onProgress)
  let text = ''
  for (const image of await prepare(photo)) {
    text += '\n' + (await w.recognize(image)).data.text
    const value = pickReading(text, hint)
    if (value !== null) return value
  }
  return null
}
