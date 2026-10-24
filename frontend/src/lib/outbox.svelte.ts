import { ApiError, request } from './api'
import { isTransient, jobOf, newJob, runJob, type Job, type QueuedRequest } from './queue'

/**
 * The waiting writes of this device, kept in IndexedDB (photos included)
 * until they went through. Sends again when the network is back, when the
 * app comes to the front and every minute while something waits.
 */

const DB = 'wohnpilot'
const STORE = 'outbox'
const RETRY_MS = 60_000

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB, 1)
    open.onupgradeneeded = () => open.result.createObjectStore(STORE, { keyPath: 'id' })
    open.onsuccess = () => resolve(open.result)
    open.onerror = () => reject(open.error)
  })
}

async function store<T>(mode: IDBTransactionMode, work: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb()
  try {
    return await new Promise<T>((resolve, reject) => {
      const tx = db.transaction(STORE, mode)
      const req = work(tx.objectStore(STORE))
      tx.oncomplete = () => resolve(req.result)
      tx.onerror = () => reject(tx.error)
    })
  } finally {
    db.close()
  }
}

function send(step: QueuedRequest, key: string): Promise<unknown> {
  let body: unknown = step.json
  if (step.form) {
    const form = new FormData()
    for (const [name, value] of Object.entries(step.form)) {
      if (value instanceof Blob) form.set(name, value, name === 'foto' ? 'foto.jpg' : name)
      else form.set(name, value)
    }
    body = form
  }
  return request(step.method, step.path, body ?? (step.method === 'DELETE' ? undefined : {}), { 'Idempotency-Key': key })
}

/** `createdId`: what the first step created, e.g. a new defect before its photo */
export type Sent<T> = { queued: false; answer: T; createdId?: string } | { queued: true }

class Outbox {
  jobs = $state<Job[]>([])
  online = $state(typeof navigator === 'undefined' ? true : navigator.onLine)
  sending = $state(false)
  private started = false
  private timer: ReturnType<typeof setInterval> | undefined

  /** After signing in: load what waits and keep sending. */
  async start(): Promise<void> {
    if (this.started) return
    this.started = true
    window.addEventListener('online', () => {
      this.online = true
      void this.flush()
    })
    window.addEventListener('offline', () => (this.online = false))
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') void this.flush()
    })
    this.timer = setInterval(() => {
      if (this.jobs.length > 0) void this.flush()
    }, RETRY_MS)
    try {
      this.jobs = (await store('readonly', (s) => s.getAll() as IDBRequest<Job[]>)).sort((a, b) => a.createdAt.localeCompare(b.createdAt))
    } catch {
      // No IndexedDB (private mode in old Safari): writes just need the network
    }
    void this.flush()
  }

  /**
   * Sends now; without network the job waits here and `queued` is true.
   * A refusal by the server (e.g. a validation error) is thrown as usual.
   */
  async send<T>(label: string, steps: QueuedRequest[]): Promise<Sent<T>> {
    const job = newJob(label, steps)
    try {
      const result = await runJob(job, send)
      return { queued: false, answer: result.answer as T, createdId: result.job.createdId }
    } catch (e) {
      if (!isTransient(e)) throw e
      await this.keep(jobOf(e) ?? job)
      return { queued: true }
    }
  }

  /** Sends what waits, oldest first; stops at the first network problem. */
  async flush(): Promise<void> {
    if (this.sending || this.jobs.length === 0) return
    this.sending = true
    try {
      for (const job of this.jobs.filter((j) => j.error === undefined)) {
        try {
          await runJob(job, send)
          await this.drop(job.id)
        } catch (e) {
          const stuck = jobOf(e) ?? job
          if (isTransient(e)) {
            await this.keep(stuck)
            break
          }
          await this.keep({ ...stuck, error: e instanceof ApiError ? errorText(e) : 'Das hat nicht geklappt.' })
        }
      }
    } finally {
      this.sending = false
    }
  }

  /**
   * Takes back a done tick: one still waiting here is just dropped, one that
   * went through gets its undo request (which may wait itself).
   */
  async undo(done: Pick<QueuedRequest, 'method' | 'path'>, label: string, request: QueuedRequest): Promise<Sent<unknown>> {
    const waiting = this.waiting(done.method, done.path)
    if (waiting) {
      await this.drop(waiting.id)
      return { queued: false, answer: undefined }
    }
    return this.send(label, [request])
  }

  /** A path still waiting to be sent, e.g. a done tick */
  waiting(method: QueuedRequest['method'], path: string): Job | undefined {
    return this.jobs.find((j) => j.steps.some((s, i) => i >= j.done && s.method === method && s.path === path))
  }

  async discard(id: string): Promise<void> {
    await this.drop(id)
  }

  /** On logout: nothing of this person stays on the device. */
  async clear(): Promise<void> {
    this.jobs = []
    clearInterval(this.timer)
    try {
      await store('readwrite', (s) => s.clear())
    } catch {
      // nothing stored
    }
  }

  private async keep(job: Job): Promise<void> {
    const others = this.jobs.filter((j) => j.id !== job.id)
    this.jobs = [...others, job].sort((a, b) => a.createdAt.localeCompare(b.createdAt))
    try {
      await store('readwrite', (s) => s.put($state.snapshot(job) as Job))
    } catch {
      // kept in memory for this visit
    }
  }

  private async drop(id: string): Promise<void> {
    this.jobs = this.jobs.filter((j) => j.id !== id)
    try {
      await store('readwrite', (s) => s.delete(id))
    } catch {
      // was only in memory
    }
  }
}

function errorText(e: ApiError): string {
  const field = Object.values(e.fields ?? {})[0]
  return field ?? e.text
}

export const outbox = new Outbox()
