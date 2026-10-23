<script lang="ts">
  import { House, Pencil } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { formatDate, formatNumber } from '../lib/format'
  import { roomName } from '../lib/meters'
  import type { ObjectData } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import NextCard from '../components/NextCard.svelte'

  const object = new Resource(async () => (await api.get<{ object: ObjectData | null }>('/objects/active')).object)
</script>

<header class="head">
  <h1>Zuhause</h1>
  {#if object.data}
    <a class="btn btn-secondary" href="/zuhause/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/if}
</header>

<LoadState resource={object} />

{#if !object.loading && !object.error && object.data === null}
  <NextCard
    title="Richte dein Zuhause ein"
    meta="Haus oder Wohnung, Räume und Einzug – danach legst du deine Zähler an."
    action={{ href: '/zuhause/neu', text: 'Zuhause anlegen' }}
  />
{:else if object.data}
  {@const o = object.data}
  <section class="card" aria-labelledby="objekt">
    <div class="title-row">
      <IconTile size={52} module="miete"><House size={24} strokeWidth={1.75} aria-hidden="true" /></IconTile>
      <div>
        <h2 id="objekt">{o.art === 'haus' ? 'Haus' : 'Wohnung'}</h2>
        <p class="muted">{o.adresse ?? 'Ohne Adresse'}</p>
      </div>
    </div>
    <dl class="facts">
      <div><dt>Eingezogen</dt><dd class="num">{o.einzug ? formatDate(o.einzug) : '–'}</dd></div>
      <div><dt>Wohnfläche</dt><dd class="num">{o.wohnflaeche_m2 ? `${formatNumber(o.wohnflaeche_m2)} m²` : '–'}</dd></div>
    </dl>
  </section>

  <section class="card" aria-labelledby="raeume">
    <h2 id="raeume">Räume</h2>
    {#if o.raeume.length === 0}
      <p class="muted">Noch keine Räume. Räume ordnen die Ablese-Runde: erst Keller, dann Garten.</p>
    {:else}
      <ol class="rooms">
        {#each o.raeume as room (room)}
          <li>{roomName(room)}</li>
        {/each}
      </ol>
      <p class="muted small">In dieser Reihenfolge führt dich die Ablese-Runde durchs Haus.</p>
    {/if}
  </section>
{/if}

<style>
  .head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
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
    gap: var(--space-3);
  }

  .title-row {
    display: flex;
    align-items: center;
    gap: 14px;
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
  }

  .facts {
    margin: 0;
  }

  .facts div {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-top: 1px solid var(--line);
    font-size: 14px;
  }

  .facts dt {
    color: var(--ink-muted);
  }

  .facts dd {
    margin: 0;
  }

  .rooms {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .rooms li {
    padding: 6px 12px;
    border-radius: var(--radius-full);
    background: var(--paper);
    font-size: 14px;
    font-weight: 500;
  }
</style>
