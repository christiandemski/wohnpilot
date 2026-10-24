<script lang="ts">
  import { Bell, BellOff, CalendarClock, CalendarPlus, ChevronRight, Copy, FileText, LogOut, RefreshCw } from '@lucide/svelte'
  import { api, ApiError, logout } from '../lib/api'
  import { theme, type ThemeChoice } from '../lib/theme.svelte'
  import type { User } from '../lib/types'
  import { Resource } from '../lib/resource.svelte'
  import { formatDate } from '../lib/format'
  import { disablePush, enablePush, isAppleMobile, pushState, testPush, type PushState } from '../lib/push'
  import IconTile from '../components/IconTile.svelte'
  import Segmentschalter from '../components/Segmentschalter.svelte'

  const modules = [
    { href: '/vertraege', label: 'Verträge', meta: 'Strom, Internet, Versicherungen', icon: FileText, module: 'vertraege' },
    { href: '/fristen', label: 'Fristen', meta: 'Einmalige Termine mit Stichtag', icon: CalendarClock, module: 'vertraege' },
  ]

  let { user }: { user: User } = $props()

  let choice = $state<string>(theme.choice)
  $effect(() => theme.set(choice as ThemeChoice))

  // Morning push on this device
  let push = $state<PushState | null>(null)
  let pushKey: string | null = null
  let pushBusy = $state(false)
  let pushMessage = $state('')

  pushState()
    .then((result) => {
      push = result.state
      pushKey = result.publicKey
    })
    .catch(() => (push = 'unsupported'))

  async function pushAction(run: () => Promise<void>) {
    pushBusy = true
    pushMessage = ''
    try {
      await run()
    } catch (e) {
      pushMessage = e instanceof ApiError ? e.text : 'Das hat nicht geklappt. Versuch es bitte noch einmal.'
    } finally {
      pushBusy = false
    }
  }

  const turnOn = () => pushAction(async () => {
    push = await enablePush(pushKey!)
  })
  const turnOff = () => pushAction(async () => {
    await disablePush()
    push = 'inactive'
  })
  const sendTest = () =>
    pushAction(async () => {
      const result = await testPush()
      pushMessage = result === 'sent' ? 'Testnachricht ist unterwegs.' : result === 'gone' ? 'Dieses Gerät ist nicht mehr angemeldet. Schalt die Mitteilungen bitte neu ein.' : 'Der Push-Dienst hat nicht geantwortet. Versuch es später noch einmal.'
      if (result === 'gone') push = 'inactive'
    })

  // Calendar subscription: the link is shown once, right after it is made.
  const calendar = new Resource(() => api.get<{ active: boolean; createdAt: string | null }>('/calendar'))
  let calendarUrl = $state('')
  let calendarBusy = $state(false)
  let calendarError = $state('')
  let copied = $state(false)

  async function calendarAction(run: () => Promise<void>) {
    calendarBusy = true
    calendarError = ''
    try {
      await run()
    } catch (e) {
      calendarError = e instanceof ApiError ? e.text : ''
    } finally {
      calendarBusy = false
    }
  }

  const createLink = () =>
    calendarAction(async () => {
      const result = await api.post<{ active: boolean; createdAt: string; url: string }>('/calendar')
      calendarUrl = result.url
      copied = false
      calendar.data = { active: true, createdAt: result.createdAt }
    })

  const removeLink = () =>
    calendarAction(async () => {
      await api.delete('/calendar')
      calendarUrl = ''
      calendar.data = { active: false, createdAt: null }
    })

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(calendarUrl)
      copied = true
    } catch {
      copied = false
    }
  }

  let syncState = $state<'idle' | 'running' | 'done' | 'failed'>('idle')
  let syncError = $state('')

  async function sync() {
    syncState = 'running'
    try {
      await api.post('/sync')
      syncState = 'done'
    } catch (e) {
      syncState = 'failed'
      syncError = e instanceof ApiError ? e.text : ''
    }
  }
</script>

<header class="head"><h1>Mehr</h1></header>

<nav class="card modules" aria-label="Bereiche">
  <ul>
    {#each modules as item (item.href)}
      {@const Icon = item.icon}
      <li>
        <a class="row" href={item.href}>
          <IconTile module={item.module}><Icon size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
          <span class="text">
            <span class="title">{item.label}</span>
            <span class="meta">{item.meta}</span>
          </span>
          <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
        </a>
      </li>
    {/each}
  </ul>
</nav>

<section class="card" aria-labelledby="mitteilungen">
  <h2 id="mitteilungen">Mitteilungen</h2>
  <p class="muted">Morgens um 8 Uhr eine Nachricht, wenn eine Frist näher rückt: 30, 14, 7 und 3 Tage vorher, am Vortag und am Tag selbst. An ruhigen Tagen kommt nichts.</p>
  {#if push === null}
    <p class="muted small" aria-live="polite">Prüft …</p>
  {:else if push === 'unsupported'}
    <p>
      {isAppleMobile()
        ? 'Auf iPhone und iPad gehen Mitteilungen nur in der App auf dem Home-Bildschirm: Teilen › Zum Home-Bildschirm, dann dort einschalten.'
        : 'Dieser Browser kann hier keine Mitteilungen empfangen.'}
    </p>
  {:else if push === 'off'}
    <p>Mitteilungen sind auf dem Server noch nicht eingerichtet.</p>
  {:else if push === 'denied'}
    <p>Du hast Mitteilungen für Wohnpilot blockiert. Erlaub sie in den Einstellungen des Browsers, dann kannst du sie hier einschalten.</p>
  {:else if push === 'active'}
    <p><strong>Auf diesem Gerät eingeschaltet.</strong></p>
    <div class="actions">
      <button type="button" class="btn btn-secondary on-surface" disabled={pushBusy} onclick={sendTest}>
        <Bell size={18} strokeWidth={1.75} aria-hidden="true" />
        Test senden
      </button>
      <button type="button" class="btn btn-secondary on-surface" disabled={pushBusy} onclick={turnOff}>
        <BellOff size={18} strokeWidth={1.75} aria-hidden="true" />
        Ausschalten
      </button>
    </div>
  {:else}
    <button type="button" class="btn btn-secondary on-surface" disabled={pushBusy} onclick={turnOn}>
      <Bell size={18} strokeWidth={1.75} aria-hidden="true" />
      Auf diesem Gerät einschalten
    </button>
  {/if}
  <p role="status" class="small">{pushMessage}</p>
</section>

<section class="card" aria-labelledby="kalender">
  <h2 id="kalender">Kalender</h2>
  <p class="muted">Kündigungstage und Fristen als Abo in deinem Kalender, mit Erinnerung eine Woche und einen Tag vorher.</p>
  {#if calendarUrl}
    <div class="field link-field">
      <label for="kalender-link">Dein Kalender-Link</label>
      <input id="kalender-link" class="input num" readonly value={calendarUrl} onfocus={(e) => e.currentTarget.select()} />
      <span class="hint">Nur jetzt sichtbar. Wer den Link hat, sieht deine Fristen – teil ihn nicht.</span>
    </div>
    <div class="actions">
      <a class="btn btn-primary" href={calendarUrl.replace(/^https?:/, 'webcal:')}>
        <CalendarPlus size={18} strokeWidth={1.75} aria-hidden="true" />
        Im Kalender abonnieren
      </a>
      <button type="button" class="btn btn-secondary on-surface" onclick={copyLink}>
        <Copy size={18} strokeWidth={1.75} aria-hidden="true" />
        {copied ? 'Kopiert' : 'Link kopieren'}
      </button>
    </div>
    <p class="muted small">iPhone: Einstellungen › Kalender › Accounts › Kalenderabo hinzufügen. Google Kalender: Weitere Kalender › Per URL.</p>
  {:else if calendar.data?.active}
    <p>Dein Kalender-Link ist aktiv{calendar.data.createdAt ? ` seit ${formatDate(calendar.data.createdAt.slice(0, 10))}` : ''}.</p>
    <div class="actions">
      <button type="button" class="btn btn-secondary on-surface" disabled={calendarBusy} onclick={createLink}>Neuen Link erzeugen</button>
      <button type="button" class="btn btn-secondary on-surface" disabled={calendarBusy} onclick={removeLink}>Link löschen</button>
    </div>
    <p class="muted small">Ein neuer Link ersetzt den alten; dein Kalender braucht dann das neue Abo.</p>
  {:else if calendar.data}
    <button type="button" class="btn btn-secondary on-surface" disabled={calendarBusy} onclick={createLink}>
      <CalendarPlus size={18} strokeWidth={1.75} aria-hidden="true" />
      Kalender-Link erstellen
    </button>
  {/if}
  {#if calendarError}<p class="save-error" role="alert">{calendarError}</p>{/if}
</section>

<section class="card" aria-labelledby="darstellung">
  <h2 id="darstellung">Darstellung</h2>
  <Segmentschalter
    idPrefix="theme"
    label="Farbschema"
    bind:value={choice}
    options={[
      { value: 'system', label: 'Wie Gerät' },
      { value: 'light', label: 'Hell' },
      { value: 'dark', label: 'Dunkel' },
    ]}
  />
</section>

<section class="card" aria-labelledby="daten">
  <h2 id="daten">Daten</h2>
  <p class="muted">Deine Einträge liegen als Notizen im Ordner „Wohnpilot“ deiner Nextcloud. Änderungen aus der Notes-App holt Wohnpilot von selbst; hier geht es sofort.</p>
  <button type="button" class="btn btn-secondary on-surface" onclick={sync} disabled={syncState === 'running'}>
    <RefreshCw size={18} strokeWidth={1.75} aria-hidden="true" />
    {syncState === 'running' ? 'Liest ein …' : 'Jetzt aus der Nextcloud einlesen'}
  </button>
  <p role="status" class="small">
    {#if syncState === 'done'}Alles auf dem neuesten Stand.{:else if syncState === 'failed'}{syncError}{/if}
  </p>
</section>

<section class="card" aria-labelledby="konto">
  <h2 id="konto">Konto</h2>
  <p>Angemeldet als <strong>{user.name}</strong>.</p>
  <button type="button" class="btn btn-secondary on-surface" onclick={logout}>
    <LogOut size={18} strokeWidth={1.75} aria-hidden="true" />
    Abmelden
  </button>
</section>

<style>
  .head {
    padding: 0 var(--space-1) var(--space-2);
  }

  h1 {
    font-size: 32px;
    line-height: 35px;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  h2 {
    font-size: 17px;
    line-height: 22px;
    font-weight: 700;
  }

  .card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-3);
  }

  .card :global(.track) {
    align-self: stretch;
  }

  .modules {
    padding: var(--space-1) var(--space-4);
  }

  .modules ul {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .modules li + li {
    border-top: 1px solid var(--line);
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 64px;
    text-decoration: none;
  }

  .text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
  }

  .title {
    font-weight: 600;
  }

  .meta {
    font-size: 13px;
    line-height: 18px;
    color: var(--ink-muted);
  }

  .row :global(.chevron) {
    flex-shrink: 0;
    color: var(--ink-muted);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .link-field {
    align-self: stretch;
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
  }
</style>
