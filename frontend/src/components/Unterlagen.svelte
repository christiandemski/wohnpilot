<script lang="ts">
  import { FileText, Trash2, Upload } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { formatDay } from '../lib/format'
  import { titleFrom } from '../lib/inbox.svelte'
  import type { Photo, PhotoOwner } from '../lib/photos'
  import { todayIso } from '../lib/resource.svelte'
  import type { DocumentRef } from '../lib/types'
  import PhotoGallery from './PhotoGallery.svelte'

  /**
   * Documents of an entry (PDF: letters, invoices, the contract itself) and,
   * where the page has no gallery of its own, photos such as a photographed
   * letter. They live in Nextcloud next to the note, linked from its text.
   */
  let { type, id, photos: withPhotos = false }: { type: PhotoOwner; id: string; photos?: boolean } = $props()

  let documents = $state<DocumentRef[]>([])
  let photos = $state<Photo[]>([])
  let loaded = $state(false)
  let busy = $state(false)
  let error = $state('')
  let confirming = $state<string | null>(null)
  let input: HTMLInputElement | undefined = $state()
  const base = $derived(`/documents/${type}/${encodeURIComponent(id)}`)

  $effect(() => {
    const path = base
    api
      .get<{ documents: DocumentRef[]; photos: Photo[] }>(path)
      .then((files) => {
        documents = files.documents
        photos = files.photos
      })
      .catch(() => (error = 'Die Unterlagen ließen sich gerade nicht laden.'))
      .finally(() => (loaded = true))
  })

  async function add(event: Event) {
    const files = [...((event.currentTarget as HTMLInputElement).files ?? [])]
    ;(event.currentTarget as HTMLInputElement).value = ''
    busy = true
    error = ''
    try {
      for (const file of files) {
        const form = new FormData()
        form.set('dokument', file)
        form.set('titel', titleFrom(file.name, 'pdf', formatDay(todayIso())))
        documents = (await api.upload<{ documents: DocumentRef[] }>(base, form)).documents
      }
    } catch (e) {
      error = e instanceof ApiError ? (Object.values(e.fields)[0] ?? e.text) : 'Das hat nicht geklappt.'
    } finally {
      busy = false
    }
  }

  async function remove(doc: DocumentRef) {
    busy = true
    error = ''
    try {
      documents = (await api.delete<{ documents: DocumentRef[] }>(`${base}?path=${encodeURIComponent(doc.path)}`)).documents
    } catch (e) {
      error = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
    } finally {
      busy = false
      confirming = null
    }
  }
</script>

<section class="card stack" aria-labelledby="unterlagen-{id}">
  <h2 id="unterlagen-{id}" class="section-title">Unterlagen</h2>
  {#if documents.length > 0}
    <ul class="docs">
      {#each documents as doc (doc.path)}
        <li>
          <a class="doc" href={doc.url} target="_blank" rel="noopener">
            <FileText size={20} strokeWidth={1.75} aria-hidden="true" />
            <span>{doc.title}</span>
          </a>
          {#if confirming === doc.path}
            <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={() => remove(doc)}>Löschen</button>
          {:else}
            <button type="button" class="btn btn-icon" aria-label="„{doc.title}“ löschen" onclick={() => (confirming = doc.path)}>
              <Trash2 size={18} strokeWidth={1.75} aria-hidden="true" />
            </button>
          {/if}
        </li>
      {/each}
    </ul>
  {:else if loaded && !error}
    <p class="text-muted text-small">Noch keine PDFs. Briefe und Rechnungen teilst du am schnellsten über den Eingang.</p>
  {/if}
  <input bind:this={input} class="visually-hidden" type="file" accept="application/pdf" multiple tabindex="-1" aria-hidden="true" onchange={add} />
  <button type="button" class="btn btn-secondary on-surface add" disabled={busy} onclick={() => input?.click()}>
    <Upload size={18} strokeWidth={1.75} aria-hidden="true" />
    {busy ? 'Lädt hoch …' : 'PDF hinzufügen'}
  </button>
  {#if withPhotos && loaded}
    <PhotoGallery {type} {id} bind:photos label="Fotos" />
  {/if}
  {#if error}<p class="save-error" role="alert">{error}</p>{/if}
</section>

<style>
  .docs {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .docs li {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    border-top: 1px solid var(--line);
  }

  .docs li:first-child {
    border-top: 0;
  }

  .doc {
    display: flex;
    flex: 1;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
    min-height: var(--touch-min);
    overflow-wrap: anywhere;
  }

  .add {
    align-self: flex-start;
  }
</style>
