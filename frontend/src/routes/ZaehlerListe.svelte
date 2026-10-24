<script lang="ts">
  import { ChevronRight, Plus } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { formatDate, formatNumber } from '../lib/format'
  import { meterChip, meterTitle, roomName } from '../lib/meters'
  import type { MeterSummary } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import MediumIcon from '../components/MediumIcon.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  const meters = new Resource(async () => (await api.get<{ meters: MeterSummary[] }>('/meters')).meters)
</script>

<header class="head">
  <h1>Zähler</h1>
  <a class="btn btn-secondary" href="/zaehler/neu">
    <Plus size={18} strokeWidth={2} aria-hidden="true" />
    Zähler anlegen
  </a>
</header>

<LoadState resource={meters} />

{#if meters.data}
  {#if meters.data.length === 0}
    <div class="card">
      <p>Noch keine Zähler. Leg Strom, Wasser oder Gas mit Zählernummer und Raum an.</p>
    </div>
  {:else}
    <ul class="card list">
      {#each meters.data as meter (meter.id)}
        {@const chip = meterChip(meter, todayIso())}
        <li>
          <a class="row" href="/zaehler/{encodeURIComponent(meter.id)}">
            <IconTile><MediumIcon medium={meter.medium} /></IconTile>
            <span class="text">
              <span class="title">{meterTitle(meter)}</span>
              <span class="meta">
                {roomName(meter.room)} ·
                {#if meter.latest}
                  <span class="num">{formatNumber(meter.latest.value, meter.decimals)} {meter.unit}</span> am {formatDate(meter.latest.date)}
                {:else}
                  noch nicht abgelesen
                {/if}
              </span>
            </span>
            <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
            <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
          </a>
        </li>
      {/each}
    </ul>
  {/if}
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

  .list {
    margin: 0;
    padding: var(--space-2) var(--space-4);
    list-style: none;
  }

  .list li + li {
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
    flex-direction: column;
    gap: 1px;
    flex: 1;
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

  @media (max-width: 420px) {
    .row :global(.chevron) {
      display: none;
    }
  }
</style>
