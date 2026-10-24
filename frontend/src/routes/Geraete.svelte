<script lang="ts">
  import { Plus, WashingMachine } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { formatDay } from '../lib/format'
  import { roomName } from '../lib/meters'
  import type { DeviceSummary } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  const devices = new Resource(async () => (await api.get<{ devices: DeviceSummary[] }>('/devices')).devices)

  /** Only what needs attention: an end coming up, or that it belongs to the landlord. */
  function chip(d: DeviceSummary): { text: string; tone: 'soon' | 'neutral' } | null {
    for (const [label, end] of [['Gewährleistung', d.evaluation.liability], ['Garantie', d.evaluation.warranty]] as const) {
      if (end && end.daysUntil >= 0 && end.status !== 'ok') return { text: `${label} bis ${formatDay(end.until)}`, tone: 'soon' }
    }
    return d.fields.eigentum === 'vermieter' ? { text: 'Vermieter', tone: 'neutral' } : null
  }

  const meta = (d: DeviceSummary) =>
    [d.fields.raum ? roomName(d.fields.raum) : '', [d.fields.hersteller, d.fields.modell].filter((v) => v && !/^\[.*\]$/.test(v)).join(' ')].filter(Boolean).join(' · ')
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <a class="btn btn-secondary" href="/geraete/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Gerät anlegen
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Geräte</h1>
  <p class="text-muted">Deine und die des Vermieters, mit Garantie und Foto vom Typenschild.</p>
</header>

<LoadState resource={devices} />

{#if devices.data}
  {#if devices.data.length === 0}
    <div class="card"><p>Noch keine Geräte. Fang mit denen an, bei denen Garantie oder Wartung zählt: Waschmaschine, Heizung, Herd.</p></div>
  {:else}
    <section class="card list-card" aria-label="Geräte">
      <ul>
        {#each devices.data as d (d.id)}
          {@const c = chip(d)}
          <li>
            <a class="list-row" href="/geraete/{encodeURIComponent(d.id)}">
              <IconTile module="geraete"><WashingMachine size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="list-text">
                <span class="list-title">{d.name}</span>
                <span class="list-meta">{meta(d) || ' '}</span>
              </span>
              {#if c}<StatusChip tone={c.tone}>{c.text}</StatusChip>{/if}
            </a>
          </li>
        {/each}
      </ul>
    </section>
  {/if}
{/if}
