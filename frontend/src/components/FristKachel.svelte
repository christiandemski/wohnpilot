<script lang="ts">
  import { formatDay } from '../lib/format'
  import FristRing from './FristRing.svelte'

  /** Bento tile with the FristRing (FristRing.md): the whole tile links to the contract. */
  let {
    href,
    title,
    date,
    days,
    share,
    status,
  }: { href: string; title: string; date: string; days: number; share: number; status: 'ok' | 'soon' | 'due' } = $props()
</script>

<a class="tile card" {href}>
  <FristRing {days} {share} {status} />
  <span class="text">
    <span class="title">{title}</span>
    <span class="when {status}">
      {#if days < 0}verpasst am {formatDay(date)}{:else if days === 0}heute fällig{:else}bis {formatDay(date)}{/if}
    </span>
  </span>
</a>

<style>
  .tile {
    display: flex;
    flex-direction: column;
    gap: 10px;
    text-decoration: none;
  }

  .text {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .title {
    font-size: 15px;
    line-height: 20px;
    font-weight: 600;
  }

  .when {
    font-size: 13px;
    line-height: 18px;
    font-weight: 500;
    color: var(--ink-muted);
  }

  .when.soon {
    color: var(--soon-text);
  }

  .when.due {
    color: var(--due);
  }
</style>
