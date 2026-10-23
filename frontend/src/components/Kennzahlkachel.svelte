<script lang="ts">
  import type { Snippet } from 'svelte'
  import IconTile from './IconTile.svelte'

  /** Bento tile with one figure (docs/design/komponenten/Kennzahlkachel.md). */
  let {
    href,
    label,
    value,
    unit = '',
    trend = null,
    bars = [],
    barsLabel = '',
    module = 'zaehler',
    icon,
  }: {
    href: string
    label: string
    value: string
    unit?: string
    trend?: { text: string; tone: 'better' | 'worse' | 'neutral' } | null
    /** up to six periods, the last one is the current */
    bars?: (number | null)[]
    barsLabel?: string
    module?: string
    icon: Snippet
  } = $props()

  const max = $derived(Math.max(1, ...bars.map((b) => b ?? 0)))
</script>

<a class="tile card" {href}>
  <span class="top">
    <IconTile size={36} {module}>{@render icon()}</IconTile>
    {#if trend}
      <span class="trend {trend.tone}">{trend.text}</span>
    {/if}
  </span>
  <span class="figure">
    <span class="label">{label}</span>
    <span class="value"><span class="kpi num">{value}</span>{#if unit}<span class="unit">{unit}</span>{/if}</span>
  </span>
  {#if bars.length > 0}
    <span class="bars" role="img" aria-label={barsLabel}>
      {#each bars as bar, i (i)}
        <span
          class="bar"
          class:current={i === bars.length - 1}
          class:empty={bar === null}
          style:height="{bar === null ? 4 : Math.max(4, (bar / max) * 32)}px"
        ></span>
      {/each}
    </span>
  {/if}
</a>

<style>
  .tile {
    display: flex;
    flex-direction: column;
    gap: 10px;
    color: var(--ink);
    text-decoration: none;
    min-width: 0;
  }

  .top {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .trend {
    font-size: 12px;
    line-height: 16px;
    font-weight: 600;
    color: var(--ink-muted);
  }

  .trend.better {
    color: var(--ok);
  }

  .trend.worse {
    color: var(--due);
  }

  .figure {
    display: flex;
    flex-direction: column;
  }

  .label {
    font-size: 13px;
    line-height: 18px;
    color: var(--ink-muted);
  }

  .value {
    display: flex;
    align-items: baseline;
    gap: var(--space-1);
    min-width: 0;
  }

  .kpi {
    font-size: 30px;
    line-height: 34px;
    font-weight: 600;
    letter-spacing: -0.03em;
    white-space: nowrap;
  }

  .unit {
    font-size: 14px;
    color: var(--ink-muted);
  }

  .bars {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    height: 32px;
  }

  .bar {
    flex: 1;
    border-radius: 4px;
    background: var(--line);
  }

  .bar.current {
    background: var(--accent);
  }

  .bar.empty {
    background: transparent;
    box-shadow: inset 0 -2px 0 var(--line);
  }
</style>
