<script lang="ts">
  import { Plus, Wrench } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { RESPONSIBLE, intervalText, maintenanceChip, maintenanceMeta, seasonText, suggestionReason } from '../lib/house'
  import { roomName } from '../lib/meters'
  import type { MaintenanceSuggestion, MaintenanceSummary } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  /** Recurring tasks with the Erledigt-Kreis, and suggestions that fit the home. */
  const tasks = new Resource(async () => (await api.get<{ tasks: MaintenanceSummary[] }>('/maintenance')).tasks)
  const suggestions = new Resource(async () => (await api.get<{ suggestions: MaintenanceSuggestion[] }>('/maintenance/suggestions')).suggestions)
  const today = todayIso()

  let busy = $state<string | null>(null)
  let last = $state<MaintenanceSummary | null>(null)
  let error = $state('')

  async function act(id: string, run: () => Promise<unknown>) {
    busy = id
    error = ''
    try {
      await run()
    } catch (e) {
      error = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
    } finally {
      busy = null
    }
  }

  const done = (task: MaintenanceSummary) =>
    act(task.id, async () => {
      await api.post(`/maintenance/${encodeURIComponent(task.id)}/done`, {})
      last = task
      await tasks.reload()
    })

  const undo = (task: MaintenanceSummary) =>
    act(task.id, async () => {
      await api.delete(`/maintenance/${encodeURIComponent(task.id)}/done/${today}`)
      last = null
      await tasks.reload()
    })

  const adopt = (suggestion: MaintenanceSuggestion) =>
    act(suggestion.id, async () => {
      await api.post('/maintenance', { vorschlag: suggestion.id })
      await Promise.all([tasks.reload(), suggestions.reload()])
    })

  const responsible = (task: MaintenanceSummary) => {
    const who = task.fields.zustaendig ?? 'mieter'
    return who === 'mieter' ? '' : ` · ${RESPONSIBLE[who] ?? who}`
  }
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <a class="btn btn-secondary" href="/wartung/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Aufgabe anlegen
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Wartung</h1>
  <p class="text-muted">Wiederkehrende Aufgaben. Tipp auf den Kreis, wenn du eine erledigt hast.</p>
</header>

<LoadState resource={tasks} />

<p role="status" class="status">
  {#if last}
    „{last.title}“ ist erledigt.
    <button type="button" class="btn btn-secondary on-surface" disabled={busy !== null} onclick={() => undo(last!)}>Rückgängig</button>
  {/if}
  {#if error}<span class="save-error">{error}</span>{/if}
</p>

{#if tasks.data}
  {#if tasks.data.length === 0}
    <div class="card"><p>Noch keine Aufgaben. Übernimm unten Vorschläge oder leg eine eigene an.</p></div>
  {:else}
    <section class="card list-card" aria-label="Aufgaben">
      <ul>
        {#each tasks.data as task (task.id)}
          {@const chip = maintenanceChip(task.evaluation, today)}
          <li class="list-row">
            <a href="/wartung/{encodeURIComponent(task.id)}">
              <IconTile module="wartung"><Wrench size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="list-text">
                <span class="list-title">{task.title}</span>
                <span class="list-meta">{maintenanceMeta(task.fields, task.evaluation)}{responsible(task)}</span>
              </span>
              <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
            </a>
            {#if !task.evaluation.automatic}
              <button type="button" class="done-circle" aria-label="„{task.title}“ als erledigt abhaken" disabled={busy !== null} onclick={() => done(task)}></button>
            {/if}
          </li>
        {/each}
      </ul>
    </section>
  {/if}
{/if}

{#if suggestions.data && suggestions.data.length > 0}
  <section class="stack" aria-labelledby="vorschlaege">
    <h2 id="vorschlaege" class="section-title suggestions-title">Vorschläge für dein Zuhause</h2>
    <ul class="suggestions">
      {#each suggestions.data as suggestion (suggestion.id)}
        <li class="card suggestion">
          <span class="list-text">
            <span class="list-title">{suggestion.titel}</span>
            <span class="list-meta">
              {[intervalText(suggestion.intervall_monate), seasonText(suggestion.saison), suggestion.raum ? roomName(suggestion.raum) : '', suggestion.zustaendig !== 'mieter' ? RESPONSIBLE[suggestion.zustaendig] : ''].filter(Boolean).join(' · ')}
            </span>
            <span class="list-meta">{suggestionReason(suggestion.grund)}</span>
          </span>
          <button type="button" class="btn btn-secondary on-surface" disabled={busy !== null} onclick={() => adopt(suggestion)}>
            <Plus size={16} strokeWidth={2} aria-hidden="true" />
            Übernehmen
          </button>
        </li>
      {/each}
    </ul>
  </section>
{/if}

<style>
  .status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
  }

  .status:empty {
    display: none;
  }

  .suggestions-title {
    padding: var(--space-3) var(--space-1) 0;
  }

  .suggestions {
    display: grid;
    gap: var(--space-2);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  @media (min-width: 900px) {
    .suggestions {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  .suggestion {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .suggestion .btn {
    flex-shrink: 0;
    font-size: 14px;
  }
</style>
