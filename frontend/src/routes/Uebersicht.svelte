<script lang="ts">
  import { CalendarClock, CalendarDays, Gauge, House, KeyRound, Plus, Receipt, TriangleAlert, WashingMachine, Wrench } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { formatChipDay, formatDay, formatLongDay, formatNumber, formatPercent, greeting, monthName } from '../lib/format'
  import { mediumName, meterChip, requestChip, roomName } from '../lib/meters'
  import { agendaText, GROUP_TITLES, groupAgenda, pickNext, remainingAgenda } from '../lib/agenda'
  import { countdownShare } from '../lib/contracts'
  import type { AgendaItem, MeterSummary, Overview, User } from '../lib/types'
  import CategoryIcon from '../components/CategoryIcon.svelte'
  import FristKachel from '../components/FristKachel.svelte'
  import IconTile from '../components/IconTile.svelte'
  import Kennzahlkachel from '../components/Kennzahlkachel.svelte'
  import LoadState from '../components/LoadState.svelte'
  import MediumIcon from '../components/MediumIcon.svelte'
  import NextCard from '../components/NextCard.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Tonne from '../components/Tonne.svelte'

  let { user }: { user: User } = $props()

  const overview = new Resource(() => api.get<Overview>('/overview'))
  const hour = new Date().getHours()

  function joinGerman(items: string[]): string {
    return items.length <= 1 ? (items[0] ?? '') : items.slice(0, -1).join(', ') + ' und ' + items[items.length - 1]
  }

  /**
   * The "next" card: a reading the supplier asks for warns like a deadline,
   * the voluntary rhythm only offers the reading round.
   */
  function readingCard(data: Overview, next: NonNullable<Overview['next']>) {
    const meters = data.meters.filter((m) => next.meterIds.includes(m.id))
    const rooms = [...new Set(meters.map((m) => roomName(m.room)).filter(Boolean))]
    const minutes = Math.max(1, Math.round(meters.length * 0.75))
    const round = `${meters.length} Zähler${rooms.length ? ' · ' + joinGerman(rooms) : ''} · ca. ${minutes} ${minutes === 1 ? 'Minute' : 'Minuten'}`

    if (next.kind === 'meldung') {
      const early = data.today < next.from
      return {
        title: next.title,
        meta: early
          ? `Ablesen ab ${formatDay(next.from)}, melden bis ${formatDay(next.dueDate)} · ${round}`
          : `Melden bis ${formatDay(next.dueDate)} · ${round}`,
        chip: requestChip({ ...next, date: next.dueDate }, data.today),
        // A reading before the window would not count for the request.
        action: early ? null : { href: '/ablesen', text: 'Ablese-Runde starten' },
      }
    }
    if (next.status === 'open') {
      return {
        title: 'Ablesen möglich',
        meta: `Freiwillig, seit ${formatDay(next.dueDate)} · ${round}`,
        chip: { text: 'Freiwillig', tone: 'neutral' as const },
        action: { href: '/ablesen', text: 'Ablese-Runde starten' },
      }
    }
    return {
      title: 'Nächste Ablesung',
      meta: `Freiwillig · ${round}`,
      chip: { text: `ab ${formatChipDay(next.dueDate)}`, tone: 'neutral' as const },
      action: { href: '/ablesen', text: 'Jetzt schon ablesen' },
    }
  }

  /** A contract, lease or deadline on the "Als Nächstes" card. */
  function itemCard(data: Overview, item: AgendaItem) {
    const text = agendaText(item, data.today)
    const label: Record<string, string> = {
      frist: 'Frist ansehen',
      zustimmung: 'Mietvertrag ansehen',
      wartung: 'Aufgabe ansehen',
      termin: 'Termin ansehen',
      nachfassen: 'Mangel ansehen',
      garantie: 'Gerät ansehen',
    }
    return { title: text.title, meta: text.meta, chip: text.chip, action: { href: text.href, text: label[item.kind] ?? 'Vertrag ansehen' } }
  }

  // Ticking off maintenance right in "Was steht an"
  let busyTask = $state<string | null>(null)
  let doneTask = $state<{ id: string; title: string } | null>(null)
  let taskError = $state('')

  async function tick(item: AgendaItem, undo = false) {
    const id = item.ref.id ?? ''
    busyTask = id
    taskError = ''
    try {
      if (undo) {
        await api.delete(`/maintenance/${encodeURIComponent(id)}/done/${overview.data?.today}`)
        doneTask = null
      } else {
        await api.post(`/maintenance/${encodeURIComponent(id)}/done`, {})
        doneTask = { id, title: item.name ?? '' }
      }
      await overview.reload()
    } catch (e) {
      taskError = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
    } finally {
      busyTask = null
    }
  }

  /** The circle only where it can be done now (not before a season starts). */
  const tickable = (item: AgendaItem, today: string) => item.kind === 'wartung' && !(item.from && today < item.from) && item.daysUntil <= 30

  /** Later deadlines are many and calm: the first few, the rest on request. */
  const LATER_SHOWN = 3
  let showAllLater = $state(false)

  /** Daily rate this month against the same month last year, if both exist. */
  function trend(meter: MeterSummary) {
    const { amount, coveredDays, previousYear, days: monthDays } = meter.currentMonth
    if (amount === null || previousYear === null || coveredDays === 0 || previousYear <= 0) return null
    const ratio = amount / coveredDays / (previousYear / monthDays) - 1
    const tone = ratio <= -0.02 ? ('better' as const) : ratio >= 0.1 ? ('worse' as const) : ('neutral' as const)
    // PV feeds in: more is better
    const flipped = meter.medium === 'pv' ? (tone === 'better' ? 'worse' : tone === 'worse' ? 'better' : tone) : tone
    return { text: formatPercent(ratio), tone: flipped }
  }

  function barsLabel(meter: MeterSummary): string {
    const known = meter.recentMonths.filter((m) => m.amount !== null)
    if (known.length === 0) return 'Noch keine Monatswerte'
    return 'Letzte Monate: ' + known.map((m) => `${monthName(m.month)} ${formatNumber(m.amount!, 0)} ${meter.unit}`).join(', ')
  }
</script>

<header class="hello">
  <span class="greet">{greeting(hour)}, {user.name}</span>
  <h1>{formatLongDay(overview.data?.today ?? new Date().toISOString().slice(0, 10))}</h1>
</header>

<LoadState resource={overview} />

{#if overview.data}
  {@const data = overview.data}
  {#if data.object === null}
    <NextCard
      title="Richte dein Zuhause ein"
      meta="Haus oder Wohnung, Räume und Einzug – danach legst du deine Zähler an."
      action={{ href: '/zuhause/neu', text: 'Zuhause anlegen' }}
    />
  {:else}
    {@const next = pickNext(data.agenda, data.next)}
    {@const notice = data.figures.nextNotice}
    {@const showNotice = notice !== null && !(next && 'item' in next && next.item.kind === 'kuendigung' && next.item.ref.id === notice.id)}
    {@const groups = groupAgenda(remainingAgenda(data.agenda, next))}
    <div class="bento">
      {#if data.meters.length === 0}
        <div class="wide">
          <NextCard
            title="Leg deinen ersten Zähler an"
            meta="Strom, Wasser, Gas – mit Zählernummer und Raum, dann kannst du ablesen."
            action={{ href: '/zaehler/neu', text: 'Zähler anlegen' }}
          />
        </div>
      {:else if next && 'item' in next}
        <div class="wide"><NextCard {...itemCard(data, next.item)} /></div>
      {:else if next}
        <div class="wide"><NextCard {...readingCard(data, next.reading)} /></div>
      {/if}

      {#each data.meters as meter (meter.id)}
        {@const current = meter.currentMonth}
        <Kennzahlkachel
          href="/zaehler/{meter.id}"
          label="{mediumName(meter.medium)} {monthName(current.month)}{current.coveredDays > 0 && current.coveredDays < current.days ? ' bisher' : ''}"
          value={current.amount === null ? '–' : formatNumber(current.amount, current.amount < 10 ? Math.min(meter.decimals, 1) : 0)}
          unit={meter.unit}
          trend={trend(meter)}
          bars={meter.recentMonths.map((m) => m.amount)}
          barsLabel={barsLabel(meter)}
        >
          {#snippet icon()}<MediumIcon medium={meter.medium} size={18} />{/snippet}
        </Kennzahlkachel>
      {/each}

      {#if notice && showNotice}
        <FristKachel
          href="/vertraege/{encodeURIComponent(notice.id)}"
          title="{notice.name} kündigen?"
          date={notice.date}
          days={notice.daysUntil}
          share={notice.countdownFrom ? countdownShare(notice.countdownFrom, notice.date, data.today) : 0}
          status={notice.status}
        />
      {/if}

      {#if data.figures.fixedMonthly > 0}
        <Kennzahlkachel
          href="/vertraege"
          label={data.figures.rentMonthly !== null ? 'Fixkosten mit Miete' : 'Verträge im Monat'}
          value={formatNumber(Math.round(data.figures.fixedMonthly))}
          unit="€"
          module="vertraege"
        >
          {#snippet icon()}<Receipt size={18} strokeWidth={1.75} aria-hidden="true" />{/snippet}
        </Kennzahlkachel>
      {/if}

      {#if data.figures.openDefects !== null && data.figures.openDefects !== undefined}
        <Kennzahlkachel href="/maengel" label="Offene Mängel" value={formatNumber(data.figures.openDefects)} module="maengel">
          {#snippet icon()}<TriangleAlert size={18} strokeWidth={1.75} aria-hidden="true" />{/snippet}
        </Kennzahlkachel>
      {/if}

      {#if groups.length > 0}
        <section class="card list wide agenda" aria-labelledby="agenda-title">
          <div class="list-head">
            <h2 id="agenda-title">Was steht an</h2>
            <a href="/fristen">Fristen</a>
          </div>
          {#if doneTask || taskError}
            <p class="tick-status" role="status">
              {#if doneTask}
                „{doneTask.title}“ ist erledigt.
                <button type="button" class="btn btn-secondary on-surface" disabled={busyTask !== null} onclick={() => tick({ ref: { type: 'wartung', id: doneTask!.id } } as AgendaItem, true)}>Rückgängig</button>
              {/if}
              {#if taskError}<span class="save-error">{taskError}</span>{/if}
            </p>
          {/if}
          {#each groups as { group, items } (group)}
            {@const shown = group === 'spaeter' && !showAllLater ? items.slice(0, LATER_SHOWN) : items}
            <h3 class="group {group}">{GROUP_TITLES[group]}</h3>
            {#each shown as item (item.kind + item.ref.id + item.date)}
              {@const text = agendaText(item, data.today)}
              <div class="row-wrap">
              <a class="row" href={text.href}>
                {#if item.kind === 'muell'}
                  <span class="bin-tile"><Tonne color={item.color ?? 'bin-rest'} /></span>
                {:else if item.kind === 'wartung'}
                  <IconTile module="wartung"><Wrench size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else if item.kind === 'termin'}
                  <IconTile module="termine"><CalendarDays size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else if item.kind === 'nachfassen'}
                  <IconTile module="maengel"><TriangleAlert size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else if item.kind === 'garantie'}
                  <IconTile module="geraete"><WashingMachine size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else if item.kind === 'zustimmung'}
                  <IconTile module="miete"><KeyRound size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else if item.kind === 'ablesung' || (item.kind === 'frist' && item.meterIds?.length)}
                  <IconTile><Gauge size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else if item.kind === 'frist'}
                  <IconTile module="vertraege"><CalendarClock size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                {:else}
                  <IconTile module="vertraege"><CategoryIcon category={item.category ?? ''} /></IconTile>
                {/if}
                <span class="row-text">
                  <span class="row-title">{text.title}</span>
                  <span class="row-meta">{text.meta}</span>
                </span>
                <StatusChip tone={text.chip.tone}>{text.chip.text}</StatusChip>
              </a>
              {#if tickable(item, data.today)}
                <button type="button" class="done-circle" aria-label="„{item.name}“ als erledigt abhaken" disabled={busyTask !== null} onclick={() => tick(item)}></button>
              {/if}
              </div>
            {/each}
            {#if shown.length < items.length}
              <button type="button" class="more" onclick={() => (showAllLater = true)}>
                {items.length - shown.length} weitere anzeigen
              </button>
            {/if}
          {/each}
        </section>
      {/if}

      {#if data.meters.length > 0}
      <section class="card list wide" aria-labelledby="meters-title">
        <div class="list-head">
          <h2 id="meters-title">Zähler</h2>
          <a href="/zaehler">Alle</a>
        </div>
        {#each data.meters as meter (meter.id)}
          {@const chip = meterChip(meter, data.today)}
          <a class="row" href="/zaehler/{meter.id}">
            <IconTile><MediumIcon medium={meter.medium} /></IconTile>
            <span class="row-text">
              <span class="row-title">{mediumName(meter.medium)}{meter.name ? ` · ${meter.name}` : ''}</span>
              <span class="row-meta">
                {roomName(meter.room)} ·
                {#if meter.latest}zuletzt {formatDay(meter.latest.date)}{:else}noch nicht abgelesen{/if}
              </span>
            </span>
            <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
          </a>
        {/each}
      </section>
      {/if}
    </div>
  {/if}

  {#if data.object === null}
    <a class="card shortcut" href="/zuhause/neu">
      <IconTile module="miete"><House size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
      <span>Zuhause anlegen</span>
    </a>
  {:else if data.meters.length === 0}
    <a class="card shortcut" href="/zaehler/neu">
      <IconTile><Gauge size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
      <span>Zähler anlegen</span>
      <Plus size={20} strokeWidth={2} aria-hidden="true" />
    </a>
  {/if}
{/if}

<style>
  .hello {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 var(--space-1) var(--space-2);
  }

  .greet {
    font-size: 14px;
    font-weight: 500;
    color: var(--ink-muted);
  }

  h1 {
    font-size: 32px;
    line-height: 35px;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  .bento {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .wide {
    grid-column: 1 / -1;
  }

  @media (min-width: 900px) {
    .bento {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .bento > .wide:first-child {
      grid-column: span 2;
    }

    .list.wide {
      grid-column: span 2;
    }

    /* Right column next to the next card and the tiles */
    .list.agenda {
      grid-column: 3 / 5;
      grid-row: 1 / span 4;
      align-self: start;
    }
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .list-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 6px;
  }

  .list-head h2 {
    font-size: 17px;
    line-height: 22px;
    font-weight: 700;
  }

  .list-head a {
    display: flex;
    align-items: center;
    min-height: var(--touch-min);
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-muted);
    text-decoration: none;
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 52px;
    text-decoration: none;
  }

  .row-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    flex: 1;
    min-width: 0;
  }

  .row-title {
    font-size: 15px;
    line-height: 20px;
    font-weight: 600;
  }

  .row-meta {
    font-size: 13px;
    line-height: 18px;
    color: var(--ink-muted);
  }

  .row-wrap {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .row-wrap .row {
    flex: 1;
    min-width: 0;
  }

  .tick-status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    padding-bottom: var(--space-2);
    font-size: 14px;
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

  .group {
    padding-top: var(--space-2);
    font-size: 13px;
    line-height: 18px;
    font-weight: 600;
    color: var(--ink-muted);
  }

  .group.ueberfaellig {
    color: var(--due);
  }

  .more {
    align-self: flex-start;
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

  .shortcut {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 52px;
    font-weight: 600;
    text-decoration: none;
  }

  .shortcut span:last-of-type {
    flex: 1;
  }
</style>
