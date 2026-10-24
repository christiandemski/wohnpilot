<script lang="ts">
  import { Search } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { TYPE_NAMES, routeFor } from '../lib/knowledge'
  import { router } from '../lib/router.svelte'
  import type { SearchResult } from '../lib/types'

  /** Full-text search over everything; the words stay in the address so "back" brings you here again. */
  let query = $state(router.query.get('q') ?? '')
  let type = $state(router.query.get('typ') ?? '')
  let results = $state<SearchResult[] | null>(null)
  let error = $state('')
  let timer: ReturnType<typeof setTimeout> | undefined
  let input: HTMLInputElement | undefined = $state()

  async function run() {
    const q = query.trim()
    history.replaceState(null, '', `/suche${q ? `?q=${encodeURIComponent(q)}${type ? `&typ=${type}` : ''}` : ''}`)
    if (q.length < 2) {
      results = null
      return
    }
    try {
      results = (await api.get<{ results: SearchResult[] }>(`/search?q=${encodeURIComponent(q)}${type ? `&typ=${encodeURIComponent(type)}` : ''}`)).results
      error = ''
    } catch (e) {
      error = e instanceof ApiError ? e.text : 'Die Suche hat nicht geklappt.'
    }
  }

  /** Searches while typing, a moment after the last key. */
  function typed() {
    clearTimeout(timer)
    timer = setTimeout(run, 250)
  }

  $effect(() => {
    input?.focus()
    if (query) void run()
  })

  const FILTERS: [string, string][] = [['', 'Alles'], ['wissen', 'Wissen'], ['kontakt', 'Kontakte'], ['geraet', 'Geräte'], ['mangel', 'Mängel'], ['vertrag', 'Verträge'], ['wartung', 'Wartung']]
</script>

<header class="page-head">
  <h1 class="page-title">Suche</h1>
</header>

<form class="search" role="search" onsubmit={(e) => { e.preventDefault(); void run() }}>
  <label for="suche" class="visually-hidden">Suchbegriff</label>
  <Search size={20} strokeWidth={1.75} aria-hidden="true" class="icon" />
  <input id="suche" bind:this={input} class="input" type="search" bind:value={query} oninput={typed} placeholder="z. B. Haupthahn, Seriennummer, Fensterbauer" autocomplete="off" enterkeyhint="search" />
</form>

<div class="choice-chips filters" role="radiogroup" aria-label="Bereich">
  {#each FILTERS as [value, label] (value)}
    <label><input type="radio" name="typ" {value} bind:group={type} onchange={run} /><span>{label}</span></label>
  {/each}
</div>

{#if error}<p class="save-error" role="alert">{error}</p>{/if}

<p class="visually-hidden" aria-live="polite">{results === null ? '' : `${results.length} Treffer`}</p>

{#if results !== null}
  {#if results.length === 0}
    <div class="card"><p>Nichts gefunden. Weniger Wörter helfen, Umlaute sind egal.</p></div>
  {:else}
    <section class="card list-card" aria-label="Treffer">
      <ul>
        {#each results as r (r.type + r.id)}
          {@const href = routeFor(r.type, r.id)}
          {#if href}
            <li>
              <a class="list-row result" {href}>
                <span class="list-text">
                  <span class="list-title">{r.title}</span>
                  <span class="list-meta">{TYPE_NAMES[r.type] ?? r.type}{r.snippet ? ` · ${r.snippet}` : ''}</span>
                </span>
              </a>
            </li>
          {/if}
        {/each}
      </ul>
    </section>
  {/if}
{/if}

<style>
  .search {
    position: relative;
  }

  .search :global(.icon) {
    position: absolute;
    top: 50%;
    left: var(--space-3);
    transform: translateY(-50%);
    color: var(--ink-muted);
  }

  .search .input {
    min-height: 52px;
    padding-left: 44px;
    border-radius: var(--radius-full);
    font-size: 17px;
  }

  .filters {
    padding: var(--space-1) 0;
  }

  .result {
    padding: var(--space-2) 0;
  }
</style>
