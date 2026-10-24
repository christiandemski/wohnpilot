<script lang="ts">
  import { CalendarClock, Check, ChevronRight, Plus } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { formatDate, formatDay } from '../lib/format'
  import { dueChip } from '../lib/contracts'
  import { meterTitle } from '../lib/meters'
  import type { Deadline, MeterSummary } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  const deadlines = new Resource(() => api.get<{ open: Deadline[]; done: Deadline[] }>('/deadlines'))
  const meters = new Resource(async () => (await api.get<{ meters: MeterSummary[] }>('/meters')).meters)
  const today = todayIso()

  let busy = $state<string | null>(null)
  let last = $state<Deadline | null>(null)
  let error = $state<ApiError | null>(null)

  async function setDone(deadline: Deadline, date: string | null) {
    busy = deadline.id
    error = null
    try {
      await api.put(`/deadlines/${encodeURIComponent(deadline.id)}/done`, { datum: date })
      last = date === null ? null : deadline
      await deadlines.reload()
    } catch (e) {
      error = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      busy = null
    }
  }

  function meta(d: Deadline): string {
    const linked = (d.fields.bezieht_sich_auf ?? []).map((id) => {
      const meter = meters.data?.find((m) => m.id === id)
      return meter ? meterTitle(meter) : id
    })
    const parts = [linked.length ? linked.join(', ') : d.object === '' ? 'Haushalt' : 'Zuhause']
    if (d.evaluation.status !== 'done' && today < d.windowStart && linked.length) parts.push(`ablesen ab ${formatDay(d.windowStart)}`)
    else parts.push(`bis ${formatDate(d.fields.datum)}`)
    return parts.join(' · ')
  }
</script>

<PageHeader back="/mehr">
  {#snippet action()}
    <a class="btn btn-secondary" href="/fristen/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Frist anlegen
    </a>
  {/snippet}
</PageHeader>

<header class="head">
  <h1>Fristen</h1>
  <p class="muted">Einmalige Termine mit Stichtag, etwa die Aufforderung der Stadtwerke, den Zählerstand zu melden.</p>
</header>

<LoadState resource={deadlines} />

<p role="status" class="status">
  {#if last}
    „{last.title}“ ist erledigt.
    <button type="button" class="btn btn-secondary on-surface" disabled={busy !== null} onclick={() => setDone(last!, null)}>Rückgängig</button>
  {/if}
  {#if error}<span class="save-error">{error.text}</span>{/if}
</p>

{#if deadlines.data}
  {@const { open, done } = deadlines.data}
  {#if open.length === 0}
    <div class="card"><p>Keine offenen Fristen.</p></div>
  {:else}
    <section class="card list" aria-labelledby="offen">
      <h2 id="offen">Offen</h2>
      <ul>
        {#each open as d (d.id)}
          {@const chip = dueChip({ date: d.fields.datum, daysUntil: d.evaluation.daysUntil, status: d.evaluation.status })}
          <li class="row">
            <a class="row-link" href="/fristen/{encodeURIComponent(d.id)}">
              <IconTile module="vertraege"><CalendarClock size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="text">
                <span class="title">{d.title}</span>
                <span class="meta">{meta(d)}</span>
              </span>
              <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
            </a>
            <button
              type="button"
              class="done-circle"
              aria-label="„{d.title}“ als erledigt abhaken"
              disabled={busy !== null}
              onclick={() => setDone(d, today)}
            ></button>
          </li>
        {/each}
      </ul>
    </section>
  {/if}

  {#if done.length > 0}
    <section class="card list" aria-labelledby="erledigt">
      <h2 id="erledigt">Erledigt</h2>
      <ul>
        {#each done as d (d.id)}
          <li class="row">
            <a class="row-link" href="/fristen/{encodeURIComponent(d.id)}">
              <span class="done-mark"><Check size={18} strokeWidth={2.25} aria-hidden="true" /></span>
              <span class="text">
                <span class="title">{d.title}</span>
                <span class="meta">
                  Erledigt am <span class="num">{formatDate(d.evaluation.doneOn!)}</span>{d.evaluation.doneBy === 'readings' ? ' durch Ablesung' : ''}
                </span>
              </span>
              <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
            </a>
          </li>
        {/each}
      </ul>
    </section>
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

  .status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
  }

  .status:empty {
    display: none;
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
  }

  .row-link {
    display: flex;
    flex: 1;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
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

  /* Erledigt-Kreis (Listenzeile.md) */
  .done-circle {
    flex-shrink: 0;
    width: var(--touch-min);
    height: var(--touch-min);
    padding: 0;
    border: 2px solid var(--line-strong);
    border-radius: 50%;
    background: none;
    cursor: pointer;
  }

  .done-circle:hover {
    border-color: var(--accent);
  }

  .done-mark {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--on-accent);
  }

  .row :global(.chevron) {
    flex-shrink: 0;
    color: var(--ink-muted);
  }
</style>
