<script lang="ts">
  import { LogOut, RefreshCw } from '@lucide/svelte'
  import { api, ApiError, logout } from '../lib/api'
  import { theme, type ThemeChoice } from '../lib/theme.svelte'
  import type { User } from '../lib/types'
  import Segmentschalter from '../components/Segmentschalter.svelte'

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
  <p>Angemeldet als <strong>{user.name}</strong> über BearID.</p>
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

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
  }
</style>
