<script lang="ts">
  import type { Snippet } from 'svelte'
  import { ArrowLeft, X } from '@lucide/svelte'

  /** Top bar of sub pages: round back (or close) button, optional title and action. */
  let {
    back,
    close = false,
    title = '',
    action,
  }: { back: string; close?: boolean; title?: string; action?: Snippet } = $props()
</script>

<header class="bar no-print">
  <a class="btn btn-icon" href={back} aria-label={close ? 'Schließen' : 'Zurück'}>
    {#if close}
      <X size={20} strokeWidth={2} aria-hidden="true" />
    {:else}
      <ArrowLeft size={20} strokeWidth={2} aria-hidden="true" />
    {/if}
  </a>
  {#if title}<span class="title">{title}</span>{/if}
  <span class="action">{#if action}{@render action()}{/if}</span>
</header>

<style>
  .bar {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: var(--space-3);
  }

  .title {
    text-align: center;
    font-size: 15px;
    font-weight: 600;
  }

  .action {
    display: flex;
    justify-content: flex-end;
    min-width: var(--touch-min);
  }
</style>
