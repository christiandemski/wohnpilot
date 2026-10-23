<script lang="ts">
  import type { MonthValue } from '../lib/types'
  import { formatNumber, monthInitial, monthName } from '../lib/format'

  /**
   * Monthly consumption as bars, last year's value of the same month as a
   * dot. The current month is the accent bar. Hover or focus shows the
   * values; a table below gives the same numbers as text.
   */
  let { months, unit, decimals = 0 }: { months: MonthValue[]; unit: string; decimals?: number } = $props()

  const WIDTH = 326
  const HEIGHT = 150
  const TOP = 10
  const BASE = 120
  const BAR = 18

  const step = $derived(WIDTH / months.length)
  const max = $derived(Math.max(1, ...months.flatMap((m) => [m.amount ?? 0, m.previousYear ?? 0])))
  const y = (value: number) => BASE - (value / max) * (BASE - TOP)

  let active = $state<number | null>(null)

  function round(value: number | null): string {
    return value === null ? '–' : `${formatNumber(value, value < 10 ? Math.min(decimals, 1) : 0)} ${unit}`
  }

  function barPath(x: number, top: number): string {
    // Rounded data end (4 px) at the top, square on the baseline.
    const r = Math.min(4, BASE - top)
    return `M${x},${BASE} V${top + r} Q${x},${top} ${x + r},${top} H${x + BAR - r} Q${x + BAR},${top} ${x + BAR},${top + r} V${BASE} Z`
  }

  const summary = $derived.by(() => {
    const comparable = months.filter((m) => m.amount !== null && m.previousYear !== null && m.coveredDays === m.days)
    if (comparable.length === 0) return `Monatsverbrauch ${monthName(months[0].month)} bis ${monthName(months[months.length - 1].month)}`
    const below = comparable.filter((m) => (m.amount ?? 0) < (m.previousYear ?? 0)).length
    return `Monatsverbrauch ${monthName(months[0].month)} bis ${monthName(months[months.length - 1].month)}, ${below} von ${comparable.length} Monaten unter dem Vorjahr`
  })
</script>

<div class="chart">
  <svg role="img" aria-label={summary} viewBox="0 0 {WIDTH} {HEIGHT}" preserveAspectRatio="xMidYMid meet">
    <line x1="0" x2={WIDTH} y1={BASE + 0.5} y2={BASE + 0.5} class="axis" />
    {#each months as month, i (month.month)}
      {@const x = i * step + (step - BAR) / 2}
      {@const isCurrent = i === months.length - 1}
      {#if month.amount !== null && month.amount > 0}
        <path d={barPath(x, y(month.amount))} class:bar={!isCurrent} class:current={isCurrent} class:dim={active !== null && active !== i} />
      {/if}
      {#if month.previousYear !== null}
        <circle cx={x + BAR / 2} cy={y(month.previousYear) - 4} r="4" class="prev" class:dim={active !== null && active !== i} />
      {/if}
      <text x={x + BAR / 2} y={HEIGHT - 10} class="tick" class:strong={isCurrent}>{monthInitial(month.month)}</text>
      <!-- Hit area wider than the bar, keyboard focusable -->
      <rect
        x={i * step}
        y="0"
        width={step}
        height={HEIGHT}
        class="hit"
        role="button"
        tabindex="0"
        aria-label="{monthName(month.month)}: {round(month.amount)}, Vorjahr {round(month.previousYear)}"
        onpointerenter={() => (active = i)}
        onpointerleave={() => (active = null)}
        onfocus={() => (active = i)}
        onblur={() => (active = null)}
      />
    {/each}
  </svg>

  {#if active !== null}
    {@const m = months[active]}
    <div class="tooltip" style:left="{((active + 0.5) / months.length) * 100}%" aria-hidden="true">
      <strong>{monthName(m.month)}{m.coveredDays > 0 && m.coveredDays < m.days ? ' bisher' : ''}</strong>
      <span class="num">{round(m.amount)}</span>
      <span class="muted">Vorjahr <span class="num">{round(m.previousYear)}</span></span>
    </div>
  {/if}
</div>

<details class="table">
  <summary>Als Tabelle</summary>
  <table>
    <thead>
      <tr><th scope="col">Monat</th><th scope="col">Verbrauch</th><th scope="col">Vorjahr</th></tr>
    </thead>
    <tbody>
      {#each months.toReversed() as m (m.month)}
        <tr>
          <th scope="row">{monthName(m.month)} {m.month.slice(0, 4)}</th>
          <td class="num">{round(m.amount)}</td>
          <td class="num">{round(m.previousYear)}</td>
        </tr>
      {/each}
    </tbody>
  </table>
</details>

<style>
  .chart {
    position: relative;
  }

  svg {
    display: block;
    width: 100%;
    height: auto;
    overflow: visible;
  }

  .axis {
    stroke: var(--line);
    stroke-width: 1;
  }

  .bar {
    fill: var(--line);
  }

  .current {
    fill: var(--accent);
  }

  .prev {
    fill: var(--ink);
    stroke: var(--surface);
    stroke-width: 2;
  }

  .dim {
    opacity: 0.45;
  }

  .tick {
    font-family: var(--font-ui);
    font-size: 11px;
    fill: var(--ink-muted);
    text-anchor: middle;
  }

  .tick.strong {
    font-weight: 700;
    fill: var(--ink);
  }

  .hit {
    fill: transparent;
    cursor: default;
    outline: none;
  }

  .hit:focus-visible {
    stroke: var(--accent);
    stroke-width: 2;
  }

  .tooltip {
    position: absolute;
    top: -8px;
    transform: translate(-50%, -100%);
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 8px 10px;
    border-radius: var(--radius-xs);
    background: var(--inverse);
    color: var(--on-inverse);
    font-size: 12px;
    line-height: 16px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 1;
  }

  .tooltip .muted {
    color: var(--on-inverse-muted);
  }

  .table summary {
    min-height: var(--touch-min);
    display: flex;
    align-items: center;
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-muted);
    cursor: pointer;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  th,
  td {
    padding: 6px 4px;
    border-bottom: 1px solid var(--line);
    text-align: right;
  }

  th:first-child {
    text-align: left;
    font-weight: 500;
  }

  thead th {
    color: var(--ink-muted);
    font-weight: 600;
  }
</style>
