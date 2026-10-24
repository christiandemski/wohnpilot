/**
 * Talks to the backend under /api. The session cookie does the auth; every
 * write sends X-Requested-With so other sites cannot write on your behalf.
 */

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    public details: Record<string, unknown> = {},
  ) {
    super(code)
  }

  /** German text for the person in front of the screen. */
  get text(): string {
    return MESSAGES[this.code] ?? 'Da ist etwas schiefgegangen. Versuch es bitte gleich noch einmal.'
  }

  /** field → German problem description, for form errors */
  get fields(): Record<string, string> {
    const fields = (this.details.fields ?? {}) as Record<string, unknown>
    return Object.fromEntries(
      Object.entries(fields).map(([field, problem]) => [field, FIELD_PROBLEMS[String(problem)] ?? 'Bitte prüfen.']),
    )
  }
}

const MESSAGES: Record<string, string> = {
  unauthorized: 'Bitte melde dich an.',
  forbidden: 'Du gehörst (noch) nicht zu diesem Haushalt.',
  not_found: 'Den Eintrag gibt es nicht (mehr).',
  validation: 'Bitte prüf die markierten Felder.',
  conflict: 'Jemand hat den Eintrag inzwischen geändert.',
  storage_unavailable: 'Die Nextcloud ist gerade nicht erreichbar. Deine Eingabe ist noch nicht gespeichert.',
  no_object: 'Leg zuerst dein Zuhause an.',
  offline: 'Du bist offline. Deine Eingabe ist noch nicht gespeichert.',
  invalid_login: 'E-Mail oder Passwort stimmt nicht.',
  rate_limited: 'Zu viele Fehlversuche. Warte bitte 15 Minuten oder melde dich mit BearID an.',
  exists: 'Das gibt es schon.',
  no_column: 'Der Notiz fehlt die Spalte dafür. Ergänz sie in der Notes-App.',
  invalid_note: 'Die Notiz hat kein gültiges Datum. Bitte in der Notes-App prüfen.',
  not_released: 'Wohnpilot ist für dein BearID-Konto nicht freigegeben. Der Admin kann es unter „Benutzer“ freigeben.',
}

const FIELD_PROBLEMS: Record<string, string> = {
  required: 'Bitte ausfüllen.',
  date: 'Bitte ein gültiges Datum.',
  future: 'Das Datum liegt in der Zukunft.',
  number: 'Bitte eine Zahl.',
  negative: 'Bitte keine negative Zahl.',
  range: 'Wert außerhalb des Bereichs.',
  unknown: 'Bitte aus der Liste wählen.',
  too_long: 'Das ist zu lang.',
  text: 'Bitte als Text.',
  id: 'Ungültige Auswahl.',
  one_of: 'Bitte nur Wochen oder Monate.',
  after_date: 'Muss vor dem Stichtag liegen.',
  too_large: 'Das Foto ist zu groß.',
  time: 'Bitte eine Uhrzeit wie 08:30.',
  url: 'Bitte eine Adresse mit https://.',
  season: 'Bitte zwei Monate auswählen.',
  after_start: 'Muss nach dem Beginn liegen.',
  not_an_image: 'Das ist kein Foto (JPEG, PNG oder WebP).',
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  let response: Response
  try {
    response = await fetch('/api' + path, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(method === 'GET' ? {} : { 'X-Requested-With': 'wohnpilot' }),
        // Forms with files set their own multipart Content-Type.
        ...(body === undefined || body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      },
      body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'offline')
  }
  if (response.status === 204) return undefined as T
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    const { error, ...details } = data as { error?: string }
    throw new ApiError(response.status, error ?? 'internal', details)
  }
  return data as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form),
}

/** Only with APP_ENV=dev: sign in as DEV_LOGIN_USER. */
export function devLogin(): void {
  window.location.href = '/api/auth/login'
}

/** Logs out here and, if signed in there, at BearID too (its cookie would sign you in again). */
export async function logout(): Promise<void> {
  const result = await api.post<{ redirect: string | null }>('/auth/logout')
  window.location.href = result?.redirect ?? '/'
}
