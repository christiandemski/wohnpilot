<script lang="ts">
  import { CalendarClock, ChevronRight, FileText, KeyRound, LogOut, RefreshCw } from '@lucide/svelte'
  import { api, ApiError, logout } from '../lib/api'
  import { theme, type ThemeChoice } from '../lib/theme.svelte'
  import type { User } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import Segmentschalter from '../components/Segmentschalter.svelte'

  const modules = [
    { href: '/vertraege', label: 'Verträge', meta: 'Strom, Internet, Versicherungen', icon: FileText, module: 'vertraege' },
    { href: '/mietvertrag', label: 'Mietvertrag', meta: 'Miete, Kaution, Kündigung', icon: KeyRound, module: 'miete' },
    { href: '/fristen', label: 'Fristen', meta: 'Einmalige Termine mit Stichtag', icon: CalendarClock, module: 'vertraege' },
  ]

  let { user }: { user: User } = $props()

  let choice = $state<string>(theme.choice)
  $effect(() => theme.set(choice as ThemeChoice))

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

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
  }
</style>
