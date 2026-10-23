<script lang="ts">
  import type { Snippet } from 'svelte'

  /** Module icon tile (Designsystem: Ikonografie). */
  let { size = 40, module = 'zaehler', children }: { size?: 36 | 40 | 52; module?: string; children: Snippet } = $props()
</script>

<span
  class="tile"
  class:s36={size === 36}
  class:s52={size === 52}
  style:--tile-bg="var(--module-{module}-tint)"
  style:--tile-fg="var(--module-{module}-ink)"
>
  {@render children()}
</span>

<style>
  .tile {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    background: var(--tile-bg);
    color: var(--tile-fg);
  }

  .s36 {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-xs);
  }

  .s52 {
    width: 52px;
    height: 52px;
    border-radius: 18px;
  }
</style>
