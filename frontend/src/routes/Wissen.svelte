<script lang="ts">
  import { BookOpen, Plus, Siren } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { CATEGORIES } from '../lib/knowledge'
  import { roomName } from '../lib/meters'
  import type { KnowledgePage } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  const pages = new Resource(async () => (await api.get<{ pages: KnowledgePage[] }>('/knowledge')).pages)

  function groups(list: KnowledgePage[]) {
    const byCategory = new Map<string, KnowledgePage[]>()
    for (const page of list) byCategory.set(page.category, [...(byCategory.get(page.category) ?? []), page])
    return [...byCategory.entries()]
  }
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <a class="btn btn-secondary" href="/wissen/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Seite anlegen
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Wissen</h1>
  <p class="text-muted">Wo ist was, Kennwerte, Bedienung – damit es nicht nur in einem Kopf steckt.</p>
</header>

<a class="card emergency" href="/notfall">
  <IconTile module="maengel"><Siren size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
  <span class="list-text">
    <span class="list-title">Notfallblatt</span>
    <span class="list-meta">Wasserrohrbruch, Stromausfall, Gasgeruch, Heizung – mit Nummern</span>
  </span>
</a>

<LoadState resource={pages} />

{#if pages.data}
  {#if pages.data.length === 0}
    <div class="card"><p>Noch keine Seiten. Fang mit Haupthahn und Sicherungskasten an – die brauchst du im Notfall zuerst.</p></div>
  {/if}
  {#each groups(pages.data) as [category, items] (category)}
    <section class="card list-card" aria-label={CATEGORIES[category] ?? category}>
      <h2 class="group-title">{CATEGORIES[category] ?? category}</h2>
      <ul>
        {#each items as page (page.id)}
          <li>
            <a class="list-row" href="/wissen/{encodeURIComponent(page.id)}">
              <IconTile module="wissen"><BookOpen size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="list-text">
                <span class="list-title">{page.title}</span>
                <span class="list-meta">{page.fields.raum ? roomName(page.fields.raum) : ' '}</span>
              </span>
            </a>
          </li>
        {/each}
      </ul>
    </section>
  {/each}
{/if}

<style>
  .emergency {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    text-decoration: none;
  }
</style>
