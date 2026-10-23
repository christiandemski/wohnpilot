import { ApiError } from './api'

/** Loads data once and on reload(), with loading and error state for the page. */
export class Resource<T> {
  data = $state<T | null>(null)
  error = $state<ApiError | null>(null)
  loading = $state(true)

  constructor(private readonly load: () => Promise<T>) {
    void this.reload()
  }

  async reload(): Promise<void> {
    this.loading = true
    try {
      this.data = await this.load()
      this.error = null
    } catch (e) {
      this.error = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      this.loading = false
    }
  }
}

/** Today in the device's time zone as ISO date. */
export function todayIso(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}
