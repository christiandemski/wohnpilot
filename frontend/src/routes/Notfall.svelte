<script lang="ts">
  import { Pencil, Phone, Printer } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { DEFAULT_STEPS, ROLES, SCENARIOS, isPlaceholder, telHref } from '../lib/knowledge'
  import { roomName } from '../lib/meters'
  import type { EmergencyScenario } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import Markdown from '../components/Markdown.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** What to do when: steps, where things are, whom to call. Also as print-out for the fridge door. */
  const sheet = new Resource(async () => (await api.get<{ scenarios: EmergencyScenario[] }>('/emergency')).scenarios)
  const number = (c: EmergencyScenario['contacts'][number]) => [c.phone, c.mobile].find((n) => n && !isPlaceholder(n)) ?? null
</script>

<PageHeader back="/wissen">
  {#snippet action()}
    <button type="button" class="btn btn-secondary" onclick={() => window.print()}>
      <Printer size={16} strokeWidth={1.75} aria-hidden="true" />
      Drucken
    </button>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Notfallblatt</h1>
  <p class="emergency-call">Bei Gefahr für Menschen: <a href="tel:112" class="num">112</a></p>
</header>

<LoadState resource={sheet} />

{#if sheet.data}
  <div class="scenarios">
    {#each sheet.data as s (s.id)}
      <section class="card stack scenario" aria-labelledby="notfall-{s.id}">
        <div class="section-head">
          <h2 id="notfall-{s.id}" class="section-title">{SCENARIOS[s.id] ?? s.id}</h2>
          <a class="btn btn-icon no-print" href={s.steps ? `/wissen/${encodeURIComponent(s.steps.id)}/bearbeiten` : `/wissen/neu?notfall=${s.id}`} aria-label="Schritte für {SCENARIOS[s.id]} bearbeiten">
            <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
          </a>
        </div>
        <Markdown text={s.steps?.text || DEFAULT_STEPS[s.id] || ''} path="wissen/notfall.md" />
        {#if !s.steps}<p class="text-muted text-small no-print">Allgemeine Schritte – über den Stift passt du sie an dein Zuhause an.</p>{/if}

        {#if s.places.length > 0}
          <ul class="places">
            {#each s.places as place (place.id)}
              <li>
                {#if place.photo}<img src={place.photo.url} alt="" class="thumb" />{/if}
                <span>
                  <a href="/wissen/{encodeURIComponent(place.id)}"><strong>{place.title}</strong></a>{place.room ? ` · ${roomName(place.room)}` : ''}
                  {#if place.hint}<br /><span class="text-muted">{place.hint}</span>{/if}
                </span>
              </li>
            {/each}
          </ul>
        {/if}

        {#if s.contacts.length > 0}
          <ul class="contacts">
            {#each s.contacts as c (c.id)}
              {@const n = number(c)}
              <li>
                <span><strong>{c.name}</strong> <span class="text-muted">{ROLES[c.role] ?? c.role}</span></span>
                {#if n}
                  <a class="btn btn-secondary on-surface" href={telHref(n)}>
                    <Phone size={16} strokeWidth={1.75} aria-hidden="true" />
                    <span class="num">{n}</span>
                  </a>
                {/if}
              </li>
            {/each}
          </ul>
        {/if}
      </section>
    {/each}
  </div>
{/if}

<style>
  .emergency-call {
    font-size: 17px;
    font-weight: 600;
    color: var(--due);
  }

  .scenarios {
    display: grid;
    gap: var(--space-3);
  }

  @media (min-width: 900px) {
    .scenarios {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  .places,
  .contacts {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .places li {
    display: flex;
    gap: var(--space-3);
    align-items: center;
    font-size: 14px;
  }

  .thumb {
    width: 56px;
    height: 56px;
    flex-shrink: 0;
    border-radius: var(--radius-xs);
    object-fit: cover;
  }

  .contacts li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    padding-top: var(--space-2);
    border-top: 1px solid var(--line);
    font-size: 14px;
  }

  @media print {
    .no-print {
      display: none !important;
    }

    .scenario {
      break-inside: avoid;
      border: 1px solid var(--line-strong);
    }
  }
</style>
