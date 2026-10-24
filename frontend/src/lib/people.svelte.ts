import { api } from './api'
import type { Person, Role, User } from './types'

export const ROLE_NAMES: Record<Role, string> = {
  verwalter: 'Verwaltung',
  mitglied: 'Mitglied',
  gast: 'Gast',
}

export const ROLE_HINTS: Record<Role, string> = {
  verwalter: 'Darf alles: Personen, Einstellungen, Zuhause und Löschen.',
  mitglied: 'Trägt ein und hakt ab, löscht aber nichts und ändert keine Einstellungen.',
  gast: 'Sieht alles, ändert nichts.',
}

/** How someone gets in: BearID account, e-mail (BearID or emergency password), or not yet. */
export function signInWay(person: Pick<Person, 'bearid' | 'email' | 'emergency'>): string {
  const ways = [
    person.bearid ? 'BearID' : null,
    person.email ? (person.emergency ? 'E-Mail mit Notzugang' : 'E-Mail') : null,
  ].filter(Boolean)
  return ways.length ? ways.join(' oder ') : 'Noch keine Anmeldung'
}

/**
 * The signed-in person with their role, and the people of the household
 * for "who takes care of it". Loaded once per session.
 */
class PeopleStore {
  me = $state<User | null>(null)
  list = $state<Person[]>([])
  private loading: Promise<void> | null = null

  signedIn(user: User): void {
    this.me = user
    this.loading = null
    void this.load()
  }

  load(): Promise<void> {
    this.loading ??= api
      .get<{ people: Person[] }>('/people')
      .then((r) => {
        this.list = r.people
      })
      .catch(() => {
        // Without the list, ids are shown as they are
      })
    return this.loading
  }

  /** Name for an id; someone who left keeps their id */
  name(id: string | null | undefined): string {
    if (!id) return ''
    return this.list.find((p) => p.id === id)?.name ?? id
  }

  /** Manage people, settings and the home, delete entries */
  get manages(): boolean {
    return this.me?.role === 'verwalter'
  }

  /** Enter, change and tick off */
  get writes(): boolean {
    return this.me !== null && this.me.role !== 'gast'
  }

  /** Assigning only makes sense with more than one person */
  get several(): boolean {
    return this.list.length > 1
  }
}

export const people = new PeopleStore()
