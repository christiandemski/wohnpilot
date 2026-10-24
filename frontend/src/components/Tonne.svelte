<script lang="ts">
  /** Filled waste bin in the colour of the bin (docs/design/komponenten/Tonne.md); the name always stands next to it. */
  let { color, size = 24, dark = false }: { color: string; size?: number; dark?: boolean } = $props()
</script>

<svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" class:dark style:--bin="var(--{color})">
  <rect class="lid" x="3.5" y="4" width="17" height="3.5" rx="1.25" />
  <path class="body" d="M5 8.5h14l-1.2 11.1a1.8 1.8 0 0 1-1.8 1.6H8a1.8 1.8 0 0 1-1.8-1.6Z" />
  <path class="ribs" d="M10 11.5v6.5M14 11.5v6.5" />
</svg>

<style>
  .lid,
  .body {
    fill: var(--bin);
    stroke: var(--ink);
    stroke-width: 1.25;
  }

  .ribs {
    fill: none;
    stroke: var(--ink);
    stroke-width: 1.25;
    stroke-linecap: round;
  }

  /* On dark ground (kiosk): no outline, ribs in paper */
  .dark .lid,
  .dark .body {
    stroke: none;
  }

  .dark .ribs {
    stroke: var(--paper);
  }
</style>
