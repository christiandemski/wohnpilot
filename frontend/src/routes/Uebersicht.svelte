<script lang="ts">
  import { Gauge, House, Plus } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { formatChipDay, formatDay, formatLongDay, formatNumber, formatPercent, greeting, monthName } from '../lib/format'
  import { mediumName, meterChip, requestChip, roomName } from '../lib/meters'
  import type { MeterSummary, Overview, User } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import Kennzahlkachel from '../components/Kennzahlkachel.svelte'
  import LoadState from '../components/LoadState.svelte'
  import MediumIcon from '../components/MediumIcon.svelte'
  import NextCard from '../components/NextCard.svelte'
  import StatusChip from '../components/StatusChip.svelte'

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
  function nextCard(data: Overview) {
    const next = data.next!
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
  {:else if data.meters.length === 0}
    <NextCard
      title="Leg deinen ersten Zähler an"
      meta="Strom, Wasser, Gas – mit Zählernummer und Raum, dann kannst du ablesen."
      action={{ href: '/zaehler/neu', text: 'Zähler anlegen' }}
    />
  {:else}
    <div class="bento">
      {#if data.next}
        <div class="wide"><NextCard {...nextCard(data)} /></div>
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
