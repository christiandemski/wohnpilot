import { ApiError } from './api'

/**
 * Writes that wait for the network: meter readings, done ticks, a defect
 * with its photo. A job is one or more requests; a later one can use the
 * id the first one created ("{id}" in its path). Every request carries an
 * Idempotency-Key that stays the same for every try, so the server applies
 * it once even if an answer got lost on the way back.
 */

export interface QueuedRequest {
  method: 'POST' | 'PUT' | 'DELETE'
  path: string
  json?: unknown
  /** Multipart fields; Blobs are files */
  form?: Record<string, string | Blob>
}

export interface Job {
  id: string
  /** What it is, for "3 Einträge warten" */
  label: string
  createdAt: string
  steps: QueuedRequest[]
  /** Steps already through, and the id the first one created */
  done: number
  createdId?: string
  /** Set when the server refused it (not a network problem) */
  error?: string
}

export type Sender = (request: QueuedRequest, key: string) => Promise<unknown>

export function newJob(label: string, steps: QueuedRequest[]): Job {
  return { id: crypto.randomUUID(), label, createdAt: new Date().toISOString(), steps, done: 0 }
}

export function stepPath(job: Pick<Job, 'createdId'>, path: string): string {
  return job.createdId === undefined ? path : path.replace('{id}', encodeURIComponent(job.createdId))
}

/** Not reached or not now: try again later. Everything else is a real answer. */
export function isTransient(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 0 || error.status >= 500 || error.status === 401 || error.status === 429)
}

/**
 * Runs the steps not yet through. Returns the job as far as it got and the
 * answer of the last step; throws the server's refusal, or an ApiError with
 * status 0 when the network is gone (the job then says where to go on).
 */
export async function runJob(job: Job, send: Sender): Promise<{ job: Job; answer: unknown }> {
  let current = { ...job }
  let answer: unknown = undefined
  for (let i = current.done; i < current.steps.length; i++) {
    const step = current.steps[i]
    try {
      answer = await send({ ...step, path: stepPath(current, step.path) }, `${current.id}:${i}`)
    } catch (e) {
      throw Object.assign(e as object, { job: current })
    }
    const id = i === 0 && answer && typeof answer === 'object' && 'id' in answer ? String((answer as { id: unknown }).id) : current.createdId
    current = { ...current, done: i + 1, createdId: id }
  }
  return { job: current, answer }
}

/** The job as it got stuck, attached by runJob to the error. */
export function jobOf(error: unknown): Job | undefined {
  return (error as { job?: Job } | null)?.job
}
