<script lang="ts">
  import { Trash2 } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { router } from '../lib/router.svelte'
  import { people } from '../lib/people.svelte'

  /**
   * "… löschen" at the end of a detail page: asks once more, deletes only
   * the version shown (ETag) and goes back to the list. If the entry changed
   * elsewhere meanwhile, nothing is deleted and the page reloads.
   */
  let {
    type,
    id,
    etag,
    what,
    title,
    back,
    hint = '',
    onChanged,
  }: {
    /** Note type as the API knows it (wartung, termin, mangel, …) */
    type: string
    id: string
    etag: string
    /** "Aufgabe", "Termin", … for the button */
    what: string
    title: string
    back: string
    /** Extra warning, e.g. what else goes with it */
    hint?: string
    onChanged?: () => void | Promise<void>
  } = $props()

  let confirming = $state(false)
  let busy = $state(false)
  let error = $state('')

  async function remove() {
    busy = true
    error = ''
    try {
      await api.delete(`/entries/${type}/${encodeURIComponent(id)}?etag=${encodeURIComponent(etag)}`)
      router.go(back, true)
    } catch (e) {
      confirming = false
      if (e instanceof ApiError && e.status === 409) {
        error = 'Inzwischen woanders geändert. Schau es dir noch einmal an, dann kannst du es löschen.'
        await onChanged?.()
      } else {
        error = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
      }
    } finally {
      busy = false
    }
  }
</script>

{#if people.manages}
<div class="delete no-print">
  {#if confirming}
    <p class="text-small" role="alert">„{title}“ löschen?{hint ? ` ${hint}` : ''} In Nextcloud landet die Notiz im Papierkorb.</p>
    <div class="actions">
      <button type="button" class="btn btn-secondary on-surface danger" disabled={busy} onclick={remove}>
        <Trash2 size={16} strokeWidth={1.75} aria-hidden="true" />
        {busy ? 'Löscht …' : 'Endgültig löschen'}
      </button>
      <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={() => (confirming = false)}>Abbrechen</button>
    </div>
  {:else}
    <button type="button" class="btn btn-secondary on-surface" onclick={() => (confirming = true)}>
      <Trash2 size={16} strokeWidth={1.75} aria-hidden="true" />
      {what} löschen
    </button>
  {/if}
  {#if error}<p class="save-error" role="alert">{error}</p>{/if}
</div>
{/if}

<style>
  .delete {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-2);
    padding-top: var(--space-3);
    border-top: 1px solid var(--line);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .danger {
    color: var(--due);
  }
</style>
