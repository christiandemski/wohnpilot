<script lang="ts">
  import type { Snippet } from 'svelte'

  /** StatusChip: always a word, never colour alone (docs/design/komponenten/StatusChip.md). */
  let {
    tone = 'neutral',
    module = 'zaehler',
    onInverse = false,
    children,
  }: {
    tone?: 'due' | 'soon' | 'ok' | 'neutral' | 'module'
    module?: string
    onInverse?: boolean
    children: Snippet
  } = $props()
</script>

<span
  class="chip {tone}"
  class:on-inverse={onInverse}
  style:--module-bg={tone === 'module' ? `var(--module-${module}-tint)` : undefined}
  style:--module-fg={tone === 'module' ? `var(--module-${module}-ink)` : undefined}
>
  {@render children()}
</span>

<style>
  .chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    padding: 4px 10px;
    border-radius: var(--radius-full);
    font-size: 12px;
    line-height: 16px;
    font-weight: 600;
    white-space: nowrap;
  }

  .due {
    background: var(--due-tint);
    color: var(--due);
  }

  .soon {
    background: var(--soon-tint);
    color: var(--soon-text);
  }

  .ok {
    background: var(--ok-tint);
    color: var(--ok);
  }

  .neutral {
    background: var(--paper);
    color: var(--ink);
  }

  .module {
    background: var(--module-bg);
    color: var(--module-fg);
  }

  /* On the dark NextCard (values from NextCard.md) */
  .on-inverse.due {
    background: color-mix(in srgb, var(--due-on-inverse) 20%, transparent);
    color: var(--due-on-inverse);
  }

  .on-inverse.soon {
    background: color-mix(in srgb, var(--soon) 25%, transparent);
    color: var(--soon);
  }

  .on-inverse.ok {
    background: color-mix(in srgb, var(--ok) 25%, transparent);
    color: var(--on-inverse);
  }

  .on-inverse.neutral {
    background: color-mix(in srgb, var(--on-inverse) 12%, transparent);
    color: var(--on-inverse);
  }
</style>
