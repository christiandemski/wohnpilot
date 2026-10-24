<script lang="ts">
  import { Check, CircleCheck, Settings, WifiOff } from '@lucide/svelte'
  import { onMount } from 'svelte'
  import { agendaText } from '../lib/agenda'
  import { api, ApiError } from '../lib/api'
  import { formatLongDay, formatNumber, monthName } from '../lib/format'
  import { clockText, dayText, monthCompare } from '../lib/kiosk'
  import { meterTitle } from '../lib/meters'
  import { theme } from '../lib/theme.svelte'
  import type { AgendaItem, KioskBoard } from '../lib/types'
  import MediumIcon from '../components/MediumIcon.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Tonne from '../components/Tonne.svelte'

  /**
   * The tablet on the wall: always dark, three big tiles, readable from
   * across the room. Paired once with a code from "Mehr"; ticking off
   * maintenance is the only thing to touch.
   */
  const REFRESH_MS = 60_000

  let board = $state<KioskBoard | null>(null)
  let phase = $state<'loading' | 'ready' | 'unpaired' | 'offline'>('loading')
  let now = $state(new Date())
  let code = $state('')
  let name = $state('Tablet')
  let pairError = $state('')
  let pairing = $state(false)
  let busy = $state<string | null>(null)
  let justDone = $state<{ id: string; title: string } | null>(null)
  let menu = $state(false)

  async function load() {
    try {
      board = await api.get<KioskBoard>('/kiosk/board')
      phase = 'ready'
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) phase = 'unpaired'
      else if (board) phase = 'offline'
      else phase = e instanceof ApiError && e.status === 0 ? 'offline' : 'unpaired'
    }
  }

  async function pair(event: SubmitEvent) {
    event.preventDefault()
    pairing = true
    pairError = ''
    try {
      await api.post('/kiosk/pair', { code: code.replace(/\D/g, ''), name: name.trim() })
      code = ''
      await load()
    } catch (e) {
      pairError =
        e instanceof ApiError && e.status === 429
          ? 'Zu viele falsche Codes. Warte eine Viertelstunde.'
          : e instanceof ApiError && e.status === 422
            ? 'Der Code stimmt nicht oder ist abgelaufen. Erzeug in Wohnpilot einen neuen.'
            : 'Das hat nicht geklappt.'
    } finally {
      pairing = false
    }
  }

  async function tick(item: AgendaItem) {
    const id = item.ref.id ?? ''
    busy = id
    try {
      await api.post(`/kiosk/maintenance/${encodeURIComponent(id)}/done`, {})
      justDone = { id, title: item.name ?? '' }
      await load()
    } finally {
      busy = null
    }
  }

  async function undo() {
    if (!justDone || !board) return
    busy = justDone.id
    try {
      await api.delete(`/kiosk/maintenance/${encodeURIComponent(justDone.id)}/done/${board.today}`)
      justDone = null
      await load()
    } finally {
      busy = null
    }
  }

  async function unpair() {
    await api.post('/kiosk/unpair', {})
    board = null
    menu = false
    phase = 'unpaired'
  }

  const tickable = (item: AgendaItem) => item.kind === 'wartung' && !(item.from && board && board.today < item.from)

  onMount(() => {
    // The wall is always dark (docs/design: Kiosk)
    const root = document.documentElement
    root.setAttribute('data-theme', 'dark')

    void load()
    const refresh = setInterval(() => void load(), REFRESH_MS)
    const clock = setInterval(() => (now = new Date()), 15_000)

    // Keep the screen on while the board is shown
    let lock: { release: () => Promise<void> } | null = null
    const wake = async () => {
      try {
        lock = (await (navigator as Navigator & { wakeLock?: { request: (t: 'screen') => Promise<{ release: () => Promise<void> }> } }).wakeLock?.request('screen')) ?? null
      } catch {
        // not allowed (battery saver) – the tablet's own settings decide
      }
    }
    const visible = () => {
      if (document.visibilityState === 'visible') {
        void wake()
        void load()
      }
    }
    void wake()
    document.addEventListener('visibilitychange', visible)
    return () => {
      clearInterval(refresh)
      clearInterval(clock)
      document.removeEventListener('visibilitychange', visible)
      void lock?.release()
      theme.apply()
    }
  })

  const electricity = $derived(board?.meters.find((m) => m.medium === 'strom') ?? board?.meters[0] ?? null)
</script>

<svelte:head><title>Wohnpilot · Kiosk</title></svelte:head>

<div class="kiosk">
  {#if phase === 'unpaired'}
    <form class="pair" onsubmit={pair}>
      <h1>Tablet koppeln</h1>
      <p class="muted">Öffne Wohnpilot auf dem Handy oder am Computer: <strong>Mehr › Kiosk-Tablet › Tablet koppeln</strong>. Gib den sechsstelligen Code hier ein.</p>
      <label for="code">Code</label>
      <input id="code" class="code num" inputmode="numeric" autocomplete="one-time-code" maxlength="7" bind:value={code} placeholder="000000" />
      <label for="name">Name des Geräts</label>
      <input id="name" class="name" bind:value={name} maxlength="60" />
      {#if pairError}<p class="error" role="alert">{pairError}</p>{/if}
      <button type="submit" class="btn btn-primary btn-large" disabled={pairing || code.replace(/\D/g, '').length !== 6}>{pairing ? 'Koppelt …' : 'Koppeln'}</button>
    </form>
  {:else if board}
    {@const b = board}
    <header class="head">
      <div>
        <p class="date">{formatLongDay(b.today)}{b.household ? ` · ${b.household}` : ''}</p>
        <p class="clock num">{clockText(now)}</p>
      </div>
      <div class="head-side">
        {#if phase === 'offline'}<span class="offline"><WifiOff size={20} strokeWidth={1.75} aria-hidden="true" /> Keine Verbindung</span>{/if}
        <button type="button" class="btn btn-icon" aria-label="Einstellungen" aria-expanded={menu} onclick={() => (menu = !menu)}>
          <Settings size={22} strokeWidth={1.75} aria-hidden="true" />
        </button>
      </div>
    </header>
    {#if menu}
      <div class="menu">
        <p>Gekoppelt für {b.user.name}.</p>
        <button type="button" class="btn btn-secondary" onclick={unpair}>Dieses Tablet entkoppeln</button>
      </div>
    {/if}

    <main class="tiles">
      <section class="tile" aria-labelledby="abfuhr">
        <h2 id="abfuhr">Nächste Abfuhr</h2>
        {#if b.waste.pickups.length > 0}
          {@const first = b.waste.pickups[0]}
          <div class="pickup">
            <Tonne color={first.color} size={64} />
            <div>
              <p class="big-text">{first.name}</p>
              <p class="muted">{dayText(first.daysUntil, first.date)}</p>
            </div>
          </div>
          <p class="muted">{b.waste.fullService ? 'Vollservice · nichts zu tun' : first.daysUntil <= 1 ? 'Heute Abend rausstellen' : 'Am Vorabend rausstellen'}</p>
          <ul class="later">
            {#each b.waste.pickups.slice(1) as p (p.date + p.name)}
              <li><Tonne color={p.color} size={22} /> {p.name} · {dayText(p.daysUntil, p.date)}</li>
            {/each}
          </ul>
        {:else}
          <p class="muted">{b.waste.configured ? 'Keine Abholung in Sicht.' : 'Kein Abfuhrkalender eingerichtet.'}</p>
        {/if}
      </section>

      <section class="tile" aria-labelledby="ansteht">
        <h2 id="ansteht">Steht an</h2>
        {#if justDone}
          <p class="done-line" role="status">
            <CircleCheck size={22} strokeWidth={1.75} aria-hidden="true" /> „{justDone.title}“ erledigt
            <button type="button" class="btn btn-secondary" disabled={busy !== null} onclick={undo}>Rückgängig</button>
          </p>
        {/if}
        {#if b.items.length === 0}
          <p class="muted">Nichts in den nächsten sieben Tagen.</p>
        {:else}
          <ul class="items">
            {#each b.items.slice(0, 6) as item (item.kind + item.ref.id + item.date)}
              {@const text = agendaText(item, b.today)}
              <li>
                <div class="item-text">
                  <span class="item-title">{text.title}</span>
                  <span class="muted">{text.meta}</span>
                </div>
                <StatusChip tone={text.chip.tone}>{text.chip.text}</StatusChip>
                {#if tickable(item)}
                  <button type="button" class="tick" aria-label="„{item.name}“ als erledigt abhaken" disabled={busy !== null} onclick={() => tick(item)}>
                    <Check size={26} strokeWidth={2.25} aria-hidden="true" />
                  </button>
                {/if}
              </li>
            {/each}
          </ul>
          {#if b.items.length > 6}<p class="muted">und {b.items.length - 6} weitere</p>{/if}
        {/if}
      </section>

      <section class="tile" aria-labelledby="verbrauch">
        {#if electricity}
          {@const m = electricity}
          <h2 id="verbrauch"><MediumIcon medium={m.medium} size={22} /> {meterTitle(m)} im {monthName(m.currentMonth.month)}</h2>
          <p class="figure num">{m.currentMonth.amount === null ? '–' : formatNumber(m.currentMonth.amount, 0)}<span class="unit">{m.unit}</span></p>
          {#if monthCompare(m.currentMonth)}<p class="muted">{monthCompare(m.currentMonth)}</p>{/if}
          <ul class="later">
            {#each b.meters.filter((x) => x.id !== m.id) as other (other.id)}
              <li><MediumIcon medium={other.medium} size={20} /> {meterTitle(other)}: <span class="num">{other.currentMonth.amount === null ? '–' : `${formatNumber(other.currentMonth.amount, other.decimals > 1 ? 1 : 0)} ${other.unit}`}</span></li>
            {/each}
          </ul>
        {:else}
          <h2 id="verbrauch">Verbrauch</h2>
          <p class="muted">Noch keine Zähler.</p>
        {/if}
      </section>
    </main>
  {:else}
    <p class="muted center" aria-live="polite">{phase === 'offline' ? 'Keine Verbindung. Wohnpilot versucht es gleich wieder.' : 'Lädt …'}</p>
  {/if}
</div>

<style>
  .kiosk {
    display: flex;
    flex-direction: column;
    gap: var(--space-6);
    min-height: 100dvh;
    padding: var(--space-6);
    background: var(--paper);
    color: var(--ink);
    /* Read from across the room */
    font-size: 18px;
  }

  .muted {
    color: var(--ink-muted);
  }

  .center {
    margin: auto;
    font-size: 20px;
  }

  .head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .date {
    font-size: 22px;
    color: var(--ink-muted);
  }

  .clock {
    font-size: 72px;
    line-height: 1;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }

  .head-side {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .offline {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--soon-text);
  }

  .menu {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-4);
    border-radius: var(--radius-lg);
    background: var(--surface);
  }

  .tiles {
    display: grid;
    flex: 1;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--space-4);
    align-items: start;
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-6);
    border-radius: var(--radius-xl);
    background: var(--surface);
  }

  h2 {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 20px;
    font-weight: 600;
    color: var(--ink-muted);
  }

  .pickup {
    display: flex;
    align-items: center;
    gap: var(--space-4);
  }

  .big-text {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.1;
  }

  .later {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 17px;
    color: var(--ink-muted);
  }

  .later li {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .items {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .items li {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 72px;
    border-top: 1px solid var(--line);
  }

  .items li:first-child {
    border-top: 0;
  }

  .item-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
    font-size: 15px;
  }

  .item-title {
    font-size: 20px;
    font-weight: 600;
  }

  .tick {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 56px;
    height: 56px;
    border: 2px solid var(--line-strong);
    border-radius: 50%;
    background: none;
    color: var(--ink-muted);
    cursor: pointer;
  }

  .tick:hover,
  .tick:focus-visible {
    border-color: var(--accent);
    color: var(--ink);
  }

  .done-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    color: var(--ok);
    font-size: 17px;
  }

  .figure {
    font-size: 64px;
    font-weight: 700;
    line-height: 1;
    font-variant-numeric: tabular-nums;
  }

  .unit {
    margin-left: var(--space-2);
    font-size: 24px;
    font-weight: 500;
    color: var(--ink-muted);
  }

  .pair {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    width: 100%;
    max-width: 440px;
    margin: auto;
  }

  .pair h1 {
    font-size: 32px;
    font-weight: 700;
  }

  .pair label {
    font-weight: 600;
  }

  .code,
  .name {
    min-height: 56px;
    padding: 0 var(--space-4);
    border: 1px solid var(--line-strong);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--ink);
    font-size: 20px;
  }

  .code {
    font-size: 40px;
    letter-spacing: 0.3em;
    text-align: center;
  }

  .error {
    color: var(--due);
    font-weight: 600;
  }
</style>
