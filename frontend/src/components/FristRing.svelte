<script lang="ts">
  /** Ring of the days left until a deadline (docs/design/komponenten/FristRing.md). Decorative: days stand as text next to it. */
  let { days, share, status }: { days: number; share: number; status: 'ok' | 'soon' | 'due' } = $props()

  const R = 27
  const LENGTH = 2 * Math.PI * R
</script>

<span class="ring" aria-hidden="true">
  <svg width="64" height="64" viewBox="0 0 64 64">
    <circle class="track" cx="32" cy="32" r={R} />
    {#if share > 0}
      <circle class="arc {status}" cx="32" cy="32" r={R} stroke-dasharray="{LENGTH * share} {LENGTH}" transform="rotate(-90 32 32)" />
    {/if}
  </svg>
  <span class="center">
    <span class="num n">{Math.max(0, days)}</span>
    <span class="u">{Math.abs(days) === 1 ? 'Tag' : 'Tage'}</span>
  </span>
</span>

<style>
  .ring {
    position: relative;
    display: inline-flex;
    flex-shrink: 0;
    width: 64px;
    height: 64px;
  }

  .track {
    fill: none;
    stroke: var(--paper);
    stroke-width: 8;
  }

  .arc {
    fill: none;
    stroke-width: 8;
    stroke-linecap: round;
  }

  .ok {
    stroke: var(--ok);
  }

  .soon {
    stroke: var(--soon);
  }

  .due {
    stroke: var(--due);
  }

  .center {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }

  .n {
    font-size: 20px;
    line-height: 1;
    font-weight: 600;
  }

  .u {
    font-size: 10px;
    color: var(--ink-muted);
  }
</style>
