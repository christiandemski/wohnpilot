<script lang="ts">
  import { CircleAlert } from '@lucide/svelte'
  import type { Resource } from '../lib/resource.svelte'

  /** Loading and error display for a Resource; renders nothing once data is there. */
  let { resource }: { resource: Resource<unknown> } = $props()
</script>

{#if resource.error}
  <div class="card problem" role="alert">
    <CircleAlert size={20} strokeWidth={2} aria-hidden="true" />
    <span>{resource.error.text}</span>
    <button type="button" class="btn btn-secondary on-surface" onclick={() => resource.reload()}>Nochmal versuchen</button>
  </div>
{:else if resource.loading && resource.data === null}
  <p class="loading" aria-live="polite">Lädt …</p>
{/if}

<style>
  .problem {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    color: var(--due);
    font-weight: 600;
  }

  .problem span {
    flex: 1 1 200px;
    color: var(--ink);
  }

  .loading {
    padding: var(--space-6) 0;
    text-align: center;
    color: var(--ink-muted);
  }
</style>
