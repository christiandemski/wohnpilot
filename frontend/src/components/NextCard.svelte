<script lang="ts">
  import { ArrowRight, CircleCheck } from '@lucide/svelte'
  import StatusChip from './StatusChip.svelte'

  /** The one dark "Als Nächstes" card of the overview (docs/design/komponenten/NextCard.md). */
  let {
    title,
    meta = '',
    chip = null,
    action = null,
    done = false,
  }: {
    title: string
    meta?: string
    chip?: { text: string; tone: 'due' | 'soon' | 'ok' | 'neutral' } | null
    action?: { href: string; text: string } | null
    done?: boolean
  } = $props()
</script>

<section class="next" aria-labelledby="next-title">
  <div class="head">
    <span class="label">Als Nächstes</span>
    {#if chip}
      <StatusChip tone={chip.tone} onInverse>{chip.text}</StatusChip>
    {/if}
  </div>
  <div class="body">
    <h2 id="next-title" class="title">
      {#if done}<CircleCheck size={24} strokeWidth={2} aria-hidden="true" class="done-icon" />{/if}
      {title}
    </h2>
    {#if meta}<p class="meta">{meta}</p>{/if}
  </div>
  {#if action}
    <a class="btn btn-primary" href={action.href}>
      {action.text}
      <ArrowRight size={20} strokeWidth={2} aria-hidden="true" />
    </a>
  {/if}
</section>

<style>
  .next {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: var(--space-5);
    border-radius: var(--radius-xl);
    background: var(--inverse);
    color: var(--on-inverse);
  }

  .head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
  }

  .label {
    font-size: 13px;
    font-weight: 500;
    color: var(--on-inverse-muted);
  }

  .body {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 24px;
    line-height: 29px;
    font-weight: 700;
    letter-spacing: -0.01em;
  }

  .title :global(.done-icon) {
    color: var(--ok);
    flex-shrink: 0;
  }

  .meta {
    font-size: 14px;
    line-height: 20px;
    color: var(--on-inverse-muted);
  }
</style>
