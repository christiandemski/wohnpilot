<script lang="ts">
  import { CalendarDays, Plus, Settings2 } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { formatChipDay, formatDate, formatLongDay } from '../lib/format'
  import { wasteError } from '../lib/house'
  import type { Appointment, Waste } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Tonne from '../components/Tonne.svelte'

  /** Appointments at the house and the waste collection from the municipal calendar. */
  const appointments = new Resource(() => api.get<{ upcoming: Appointment[]; past: Appointment[] }>('/appointments'))
  const waste = new Resource(() => api.get<Waste>('/waste'))
  let showPast = $state(false)

  /** Pickups of the next four weeks, bins only (a hazardous-waste truck is no bin). */
  const soon = (w: Waste) => w.pickups.filter((p) => p.bin !== null).slice(0, 8)
  const binOf = (w: Waste, id: string | null) => w.bins.find((b) => b.id === id)
  const when = (a: Appointment) => (a.time ? `${a.time}${a.fields.bis ? `–${a.fields.bis}` : ''} Uhr` : 'ganztägig')
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <a class="btn btn-secondary" href="/termine/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Termin eintragen
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Termine und Müll</h1>
</header>

<LoadState resource={appointments} />

{#if appointments.data}
  {@const { upcoming, past } = appointments.data}
  <section class="card list-card" aria-labelledby="termine">
    <h2 id="termine" class="group-title">Termine</h2>
    {#if upcoming.length === 0}
      <p class="empty">Keine anstehenden Termine.</p>
    {:else}
      <ul>
        {#each upcoming as a (a.id)}
          <li>
            <a class="list-row" href="/termine/{encodeURIComponent(a.id)}">
              <IconTile module="termine"><CalendarDays size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="list-text">
                <span class="list-title">{a.title}</span>
                <span class="list-meta">{formatLongDay(a.date)} · {when(a)}{a.fields.ort ? ` · ${a.fields.ort}` : ''}</span>
              </span>
              <StatusChip tone={a.daysUntil <= 1 ? 'soon' : 'neutral'}>{a.daysUntil === 0 ? 'Heute' : a.daysUntil === 1 ? 'Morgen' : formatChipDay(a.date)}</StatusChip>
            </a>
          </li>
        {/each}
      </ul>
    {/if}
    {#if past.length > 0}
      <button type="button" class="more" onclick={() => (showPast = !showPast)} aria-expanded={showPast}>
        {showPast ? 'Vergangene ausblenden' : `Vergangene anzeigen (${past.length})`}
      </button>
      {#if showPast}
        <ul>
          {#each past as a (a.id)}
            <li>
              <a class="list-row" href="/termine/{encodeURIComponent(a.id)}">
                <span class="list-text">
                  <span class="list-title">{a.title}</span>
                  <span class="list-meta">{formatDate(a.date)}</span>
                </span>
              </a>
            </li>
          {/each}
        </ul>
      {/if}
    {/if}
  </section>
{/if}

{#if waste.data}
  {@const w = waste.data}
  <section class="card list-card" aria-labelledby="muell">
    <div class="section-head">
      <h2 id="muell" class="group-title">Müllabfuhr</h2>
      <a class="btn btn-icon" href="/termine/muell" aria-label="Abfuhrkalender einrichten">
        <Settings2 size={18} strokeWidth={1.75} aria-hidden="true" />
      </a>
    </div>
    {#if !w.url}
      <p class="empty">
        Hinterleg den Abfuhrkalender deines Entsorgers (ICS-Adresse), dann stehen die Abholtermine hier.
        <a href="/termine/muell">Jetzt einrichten</a>
      </p>
    {:else}
      {#if w.error}<p class="problem" role="status">{wasteError(w.error)}{w.fetchedAt ? ' Angezeigt wird der letzte Stand.' : ''}</p>{/if}
      {#if soon(w).length === 0 && !w.error}
        <p class="empty">Keine Abholung in den nächsten Wochen. Passen die Stichworte der Tonnen zum Kalender?</p>
      {:else}
        <ul>
          {#each soon(w) as pickup (pickup.date + pickup.bin)}
            {@const bin = binOf(w, pickup.bin)}
            <li class="list-row">
              <span class="bin-tile"><Tonne color={bin?.farbe ?? 'bin-rest'} /></span>
              <span class="list-text">
                <span class="list-title">{bin?.name ?? pickup.summary}</span>
                <span class="list-meta">Abholung{w.fullService ? ' · Vollservice' : ''}</span>
              </span>
              <StatusChip tone="neutral">{formatChipDay(pickup.date)}</StatusChip>
            </li>
          {/each}
        </ul>
      {/if}
    {/if}
  </section>
{/if}

<style>
  .empty {
    padding: var(--space-2) 0 var(--space-3);
    color: var(--ink-muted);
  }

  .problem {
    padding: var(--space-2) 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--soon-text);
  }

  .more {
    min-height: var(--touch-min);
    padding: 0;
    border: 0;
    background: none;
    color: var(--ink-muted);
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }

  .bin-tile {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    background: var(--bin-tint);
  }
</style>
