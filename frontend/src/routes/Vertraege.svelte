<script lang="ts">
  import { ChevronRight, KeyRound, Plus } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { categoryName, contractChip, costText, euro } from '../lib/contracts'
  import type { ContractSummary, Lease } from '../lib/types'
  import CategoryIcon from '../components/CategoryIcon.svelte'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  const data = new Resource(async () => {
    const [contracts, lease] = await Promise.all([
      api.get<{ contracts: ContractSummary[] }>('/contracts'),
      api.get<{ lease: Lease | null }>('/lease'),
    ])
    return { contracts: contracts.contracts, lease: lease.lease }
  })

  /** Active contracts by category (the API sorts them), ended ones last. */
  function groups(contracts: ContractSummary[]) {
    const result: { title: string; items: ContractSummary[] }[] = []
    for (const contract of contracts) {
      const title = contract.ended ? 'Beendet' : categoryName(contract.fields.kategorie)
      const last = result[result.length - 1]
      if (last?.title === title) last.items.push(contract)
      else result.push({ title, items: [contract] })
    }
    return result
  }

  const monthly = (contracts: ContractSummary[]) => contracts.filter((c) => !c.ended).reduce((sum, c) => sum + (c.monthlyCost ?? 0), 0)
</script>

<PageHeader back="/mehr">
  {#snippet action()}
    <a class="btn btn-secondary" href="/vertraege/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Vertrag anlegen
    </a>
  {/snippet}
</PageHeader>

<header class="head">
  <h1>Verträge</h1>
  {#if data.data && data.data.contracts.length > 0}
    <p class="muted">
      Zusammen <span class="num">{euro(Math.round(monthly(data.data.contracts) * 100) / 100)}</span> im Monat{data.data.lease ? ', ohne Miete' : ''}.
    </p>
  {/if}
</header>

<LoadState resource={data} />

{#if data.data}
  {@const { contracts, lease } = data.data}
  <a class="card row lease" href="/mietvertrag">
    <IconTile module="miete"><KeyRound size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
    <span class="text">
      <span class="title">Mietvertrag</span>
      <span class="meta">
        {#if lease}
          {@const warm = (lease.rent.cold ?? 0) + (lease.rent.utilities ?? 0)}
          <span class="num">{euro(warm)}</span> warm im Monat{lease.fields.vermieter ? ` · ${lease.fields.vermieter}` : ''}
        {:else}
          Noch nicht eingetragen
        {/if}
      </span>
    </span>
    {#if lease && lease.consentDeadlines.length > 0}
      <StatusChip tone={lease.consentDeadlines[0].status === 'ok' ? 'neutral' : lease.consentDeadlines[0].status}>Mieterhöhung</StatusChip>
    {/if}
    <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
  </a>

  {#if contracts.length === 0}
    <div class="card">
      <p>Noch keine Verträge. Leg Strom, Internet, Handy und Versicherungen an, dann erinnert dich Wohnpilot rechtzeitig vor jedem Kündigungstag.</p>
    </div>
  {:else}
    {#each groups(contracts) as group (group.title)}
      <section class="card list" aria-label={group.title}>
        <h2>{group.title}</h2>
        <ul>
          {#each group.items as contract (contract.id)}
            {@const chip = contractChip(contract)}
            <li>
              <a class="row" href="/vertraege/{encodeURIComponent(contract.id)}">
                <IconTile module="vertraege"><CategoryIcon category={contract.fields.kategorie} /></IconTile>
                <span class="text">
                  <span class="title">{contract.fields.sparte ? `${contract.fields.sparte} · ` : ''}{contract.name}</span>
                  <span class="meta">{#if contract.fields.kosten_eur !== undefined}<span class="num">{costText(contract.fields.kosten_eur, contract.fields.rhythmus)}</span>{:else}Kosten fehlen{/if}</span>
                </span>
                {#if contract.priceChanges.length > 0 && !contract.ended}
                  <span class="visually-hidden">Preisänderung angekündigt.</span>
                {/if}
                <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
                <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
              </a>
            </li>
          {/each}
        </ul>
      </section>
    {/each}
  {/if}
{/if}

<style>
  .head {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 var(--space-1) var(--space-2);
  }

  h1 {
    font-size: 32px;
    line-height: 35px;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  h2 {
    padding-top: var(--space-2);
    font-size: 13px;
    line-height: 18px;
    font-weight: 600;
    color: var(--ink-muted);
  }

  .muted {
    color: var(--ink-muted);
  }

  .list {
    padding: var(--space-2) var(--space-4);
  }

  ul {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  li + li {
    border-top: 1px solid var(--line);
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 64px;
    text-decoration: none;
  }

  .lease {
    padding-top: var(--space-2);
    padding-bottom: var(--space-2);
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
