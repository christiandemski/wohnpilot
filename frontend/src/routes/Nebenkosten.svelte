<script lang="ts">
  import { Flame, Plus, ReceiptText } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { euro } from '../lib/contracts'
  import { formatDay } from '../lib/format'
  import { Resource } from '../lib/resource.svelte'
  import { periodText, resultChip } from '../lib/utilities'
  import type { Lease, UtilitySummary } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  /** Utility statements of the home: the landlord's and the metering service's, newest first. */
  const statements = new Resource(async () => (await api.get<{ statements: UtilitySummary[] }>('/utilities')).statements)
  const lease = new Resource(async () => (await api.get<{ lease: Lease | null }>('/lease')).lease)

  /** By the year the period ends in */
  const years = $derived.by(() => {
    const groups = new Map<string, UtilitySummary[]>()
    for (const s of statements.data ?? []) {
      const year = s.to.slice(0, 4)
      groups.set(year, [...(groups.get(year) ?? []), s])
    }
    return [...groups.entries()]
  })

  function meta(s: UtilitySummary): string {
    const parts = [periodText(s.from, s.to), euro(s.total)]
    if (s.payment && s.payment.daysUntil >= 0) parts.push(`überweisen bis ${formatDay(s.payment.date)}`)
    else if (s.received) parts.push(`erhalten ${formatDay(s.received)}`)
    return parts.join(' · ')
  }
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <a class="btn btn-secondary" href="/nebenkosten/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Abrechnung erfassen
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Nebenkosten</h1>
  <p class="text-muted">
    Die Abrechnungen vom Vermieter und vom Messdienst, mit Vergleich zum Vorjahr und deinen Fristen.
    {#if lease.data?.rent.utilities != null}Deine Vorauszahlung: <span class="num">{euro(lease.data.rent.utilities)}</span> im Monat.{/if}
  </p>
</header>

<LoadState resource={statements} />

{#if statements.data}
  {#if statements.data.length === 0}
    <div class="card stack">
      <p>Noch keine Abrechnung. Trag die nächste ein, sobald sie kommt: Wohnpilot rechnet Nachzahlung oder Guthaben aus, vergleicht mit dem Vorjahr und erinnert dich an die Fristen.</p>
      <a class="btn btn-primary" href="/nebenkosten/neu">Abrechnung erfassen</a>
    </div>
  {:else}
    {#each years as [year, list] (year)}
      <section class="card list-card" aria-labelledby="jahr-{year}">
        <h2 id="jahr-{year}" class="group-title">{year}</h2>
        <ul>
          {#each list as s (s.id)}
            {@const chip = resultChip(s)}
            <li>
              <a class="list-row" href="/nebenkosten/{encodeURIComponent(s.id)}">
                <IconTile module="miete">
                  {#if s.kind === 'heizkosten'}<Flame size={20} strokeWidth={1.75} aria-hidden="true" />{:else}<ReceiptText size={20} strokeWidth={1.75} aria-hidden="true" />{/if}
                </IconTile>
                <span class="list-text">
                  <span class="list-title">{s.title}</span>
                  <span class="list-meta">{meta(s)}</span>
                </span>
                <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
              </a>
            </li>
          {/each}
        </ul>
      </section>
    {/each}
  {/if}
{/if}

<style>
  .group-title {
    padding: var(--space-3) var(--space-1) 0;
  }
</style>
