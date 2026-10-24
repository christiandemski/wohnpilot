import { api } from './api'
import type { Settings } from './types'

/**
 * Household settings the pages need (Paperless address), loaded once per
 * session. Paperless is off while the address is empty: no fields, no links.
 */
class SettingsStore {
  paperlessUrl = $state<string | null>(null)
  loaded = $state(false)
  private loading: Promise<void> | null = null

  load(): Promise<void> {
    this.loading ??= api
      .get<Settings>('/settings')
      .then((s) => this.apply(s))
      .catch(() => {
        // Without settings the app works as if Paperless were off.
        this.loaded = true
      })
    return this.loading
  }

  apply(settings: Settings): void {
    this.paperlessUrl = settings.paperlessUrl
    this.loaded = true
  }

  get paperless(): boolean {
    return this.paperlessUrl !== null
  }
}

export const settings = new SettingsStore()
