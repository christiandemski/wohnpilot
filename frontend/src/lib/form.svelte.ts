import { ApiError } from './api'
import { parseNumber } from './format'

export type Conflict = Record<string, { mine: unknown; theirs: unknown }>

/**
 * Saving an edit form: sends only changed fields with the loaded values as
 * base, so others' edits stay; shows a conflict instead of overwriting.
 */
export class EditForm {
  saving = $state(false)
  error = $state<ApiError | null>(null)
  fieldErrors = $state<Record<string, string>>({})
  conflict = $state<Conflict | null>(null)
  original: Record<string, unknown> = {}
  etag = ''

  loaded(payload: Record<string, unknown>, etag: string): void {
    this.original = payload
    this.etag = etag
  }

  /** Fields of $now that differ from what was loaded. */
  changes(now: Record<string, unknown>): Record<string, unknown> {
    return Object.fromEntries(Object.entries(now).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(this.original[key] ?? null)))
  }

  /** Runs $save with the base for the merge; keeps errors and conflicts for the page. */
  async run(save: (base: Record<string, unknown>) => Promise<void>, base: Record<string, unknown> = this.original): Promise<void> {
    this.saving = true
    this.error = null
    this.fieldErrors = {}
    try {
      await save(base)
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'internal')
      if (err.code === 'conflict' && err.details.fields) {
        this.conflict = err.details.fields as Conflict
      } else {
        this.error = err
        this.fieldErrors = err.fields
      }
    } finally {
      this.saving = false
    }
  }

  /** The base for "keep mine": what is in Nextcloud now, so the merge goes through. */
  baseAfterConflict(): Record<string, unknown> {
    const base = { ...this.original }
    for (const [field, values] of Object.entries(this.conflict ?? {})) base[field] = values.theirs
    this.conflict = null
    return base
  }
}

/** Text field → number for the API; text that is no number goes as is, so the backend names the problem. */
export function numberField(text: string | number | null | undefined): number | string | null {
  if (typeof text === 'number') return Number.isFinite(text) ? text : null
  if (text === null || text === undefined || text.trim() === '') return null
  return parseNumber(text) ?? text
}

export function textField(text: string): string | null {
  return text.trim() === '' ? null : text.trim()
}

/** Number → text in the form: "13,99" */
export function numberText(value: unknown): string {
  return typeof value === 'number' ? String(value).replace('.', ',') : ''
}
