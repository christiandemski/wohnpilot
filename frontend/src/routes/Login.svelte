<script lang="ts">
  import { CircleAlert, LogIn } from '@lucide/svelte'
  import { api, ApiError, devLogin } from '../lib/api'
  import type { User } from '../lib/types'

  /**
   * Sign in: BearID first (passkey or password + code there), e-mail and
   * password of Wohnpilot as the fallback.
   */
  let { error, reason, onSignedIn }: { error: ApiError | null; reason: string | null; onSignedIn: (user: User) => void } = $props()

  interface Methods {
    bearid: boolean
    password: boolean
    redirect: 'dev' | null
  }

  let methods = $state<Methods | null>(null)
  let email = $state('')
  let password = $state('')
  let busy = $state(false)
  let formError = $state<ApiError | null>(null)

  api
    .get<Methods>('/auth/methods')
    .then((m) => (methods = m))
    .catch(() => (methods = { bearid: false, password: true, redirect: null }))

  const REASONS: Record<string, string> = {
    fehlgeschlagen: 'Die Anmeldung hat nicht geklappt. Versuch es bitte noch einmal.',
    'nicht-freigegeben': 'Wohnpilot ist für dein BearID-Konto nicht freigegeben. Der Admin kann es unter „Benutzer“ freigeben.',
    'kein-mitglied': 'Dein Konto gehört (noch) nicht zu diesem Haushalt.',
  }

  const message = $derived(
    formError
      ? formError.code === 'validation'
        ? 'Bitte E-Mail und Passwort eingeben.'
        : formError.text
      : reason
        ? (REASONS[reason] ?? REASONS.fehlgeschlagen)
        : error && error.code !== 'unauthorized'
          ? error.text
          : '',
  )

  async function submit(event: SubmitEvent) {
    event.preventDefault()
    busy = true
    formError = null
    try {
      const result = await api.post<{ user: User }>('/auth/password', { email, password })
      password = ''
      onSignedIn(result.user)
    } catch (e) {
      formError = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      busy = false
    }
  }
</script>

<main class="login">
  <div class="card box">
    <div class="intro">
      <h1>Wohnpilot</h1>
      <p class="muted">Dein Zuhause im Blick – Zähler, Fristen, Termine.</p>
    </div>

    {#if message}
      <p class="problem" role="alert">
        <CircleAlert size={18} strokeWidth={2} aria-hidden="true" />
        <span>{message}</span>
      </p>
    {/if}

    {#if methods?.bearid}
      <a class="btn btn-primary" href="/api/auth/bearid">
        <LogIn size={20} strokeWidth={2} aria-hidden="true" />
        Mit BearID anmelden
      </a>
      <p class="or" aria-hidden="true"><span>oder mit E-Mail</span></p>
    {/if}

    {#if methods?.password}
      <form class="form" onsubmit={submit} novalidate>
        <div class="field">
          <label for="login-email">E-Mail</label>
          <input id="login-email" class="input" type="email" autocomplete="username" inputmode="email" bind:value={email} required />
        </div>
        <div class="field">
          <label for="login-password">Passwort</label>
          <input id="login-password" class="input" type="password" autocomplete="current-password" bind:value={password} required />
        </div>
        <button type="submit" class="btn {methods.bearid ? 'btn-secondary on-surface' : 'btn-primary'}" disabled={busy}>
          {busy ? 'Meldet an …' : 'Anmelden'}
        </button>
      </form>
    {/if}

    {#if methods?.redirect === 'dev'}
      <button type="button" class="btn btn-inverse" onclick={devLogin}>Entwicklungs-Login</button>
    {/if}
  </div>
</main>

<style>
  .login {
    display: grid;
    place-items: center;
    min-height: 100dvh;
    padding: var(--space-4);
  }

  .box {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 380px;
    padding: var(--space-6);
    border-radius: var(--radius-xl);
  }

  .intro {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  h1 {
    font-size: 32px;
    line-height: 35px;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  .muted {
    color: var(--ink-muted);
  }

  .problem {
    display: flex;
    gap: var(--space-2);
    align-items: flex-start;
    color: var(--due);
    font-weight: 600;
  }

  .problem span {
    color: var(--ink);
  }

  .or {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--ink-muted);
  }

  .or::before,
  .or::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--line);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }
</style>
