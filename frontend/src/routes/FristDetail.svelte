<script lang="ts">
  import { Check, Gauge, Pencil } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { days, formatDate } from '../lib/format'
  import { dueChip } from '../lib/contracts'
  import { meterTitle } from '../lib/meters'
  import type { Deadline, MeterSummary } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  let { id }: { id: string } = $props()

  const path = $derived(`/deadlines/${encodeURIComponent(id)}`)
  const deadline = new Resource(() => api.get<Deadline>(path))
  const meters = new Resource(async () => (await api.get<{ meters: MeterSummary[] }>('/meters')).meters)
  const today = todayIso()

  let busy = $state(false)
  let error = $state<ApiError | null>(null)

  async function setDone(date: string | null) {
    busy = true
    error = null
    try {
      deadline.data = await api.put<Deadline>(`${path}/done`, { datum: date })
    } catch (e) {
      error = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      busy = false
    }
  }

  const meterName = (meterId: string) => {
    const meter = meters.data?.find((m) => m.id === meterId)
    return meter ? meterTitle(meter) : meterId
  }
</script>

<PageHeader back="/fristen">
  {#snippet action()}
    <a class="btn btn-secondary" href="/fristen/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={deadline} />

{#if deadline.data}
  {@const d = deadline.data}
  {@const e = d.evaluation}
  {@const chip = dueChip({ date: d.fields.datum, daysUntil: e.daysUntil, status: e.status })}
  {@const linked = e.meters}
  <div class="detail">
    <div class="head">
      <span class="chips">
        <StatusChip tone="module" module="vertraege">Frist · {d.object === '' ? 'Haushalt' : 'Zuhause'}</StatusChip>
        <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
      </span>
      <h1>{d.title}</h1>
      <span class="muted">
        Bis <span class="num">{formatDate(d.fields.datum)}</span>{#if e.status !== 'done' && e.daysUntil > 0}, noch {days(e.daysUntil)}{/if}
      </span>
    </div>

    {#if linked.length > 0}
      <section class="card" aria-labelledby="zaehler">
        <h2 id="zaehler">Zählerstände</h2>
        <p class="muted small">
          Zählt ab dem <span class="num">{formatDate(d.windowStart)}</span>{d.fields.ab ? '' : ' (30 Tage vor dem Stichtag)'}. Sind alle abgelesen, ist die Frist von selbst erledigt; melden musst du die Stände trotzdem beim Versorger.
        </p>
        <ul class="meters">
          {#each linked as m (m.id)}
            <li>
              <span class="meter-name">{meterName(m.id)}</span>
              {#if m.readOn}
                <StatusChip tone="ok"><Check size={14} strokeWidth={2.25} aria-hidden="true" />Abgelesen {formatDate(m.readOn)}</StatusChip>
              {:else if today < d.windowStart}
                <StatusChip tone="neutral">ab {formatDate(d.windowStart)}</StatusChip>
              {:else}
                <a class="btn btn-secondary on-surface" href="/ablesen/{encodeURIComponent(m.id)}">
                  <Gauge size={16} strokeWidth={1.75} aria-hidden="true" />
                  Ablesen
                </a>
              {/if}
            </li>
          {/each}
        </ul>
      </section>
    {/if}

    <section class="card" aria-labelledby="stand">
      <h2 id="stand">{e.status === 'done' ? 'Erledigt' : 'Offen'}</h2>
      {#if e.status === 'done'}
        <p>
          Erledigt am <span class="num">{formatDate(e.doneOn!)}</span>{e.doneBy === 'readings' ? ', alle Zählerstände sind da.' : '.'}
        </p>
        {#if e.doneBy === 'manual'}
          <div class="actions">
            <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={() => setDone(null)}>Wieder öffnen</button>
          </div>
        {/if}
      {:else}
        <div class="actions">
          <button type="button" class="btn btn-primary" disabled={busy} onclick={() => setDone(today)}>
            <Check size={18} strokeWidth={2.25} aria-hidden="true" />
            Heute erledigt
          </button>
        </div>
      {/if}
      {#if error}<p class="save-error" role="alert">{error.text}</p>{/if}
      {#if d.notes}<p class="notes">{d.notes}</p>{/if}
      {#if d.modifiedBy}<p class="muted small">Zuletzt geändert von {d.modifiedBy}</p>{/if}
    </section>
  </div>
{/if}

<style>
  .detail {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .head {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    padding: var(--space-2) var(--space-1) 0;
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  h1 {
    margin-top: 6px;
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

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
    line-height: 18px;
  }

  .meters {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .meters li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    min-height: 52px;
    border-top: 1px solid var(--line);
  }

  .meter-name {
    font-weight: 600;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .notes {
    white-space: pre-line;
  }
</style>
