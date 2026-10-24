<script lang="ts">
  import { Camera, Trash2, X } from '@lucide/svelte'
  import { ApiError } from '../lib/api'
  import { deletePhoto, uploadPhoto, type Photo, type PhotoOwner } from '../lib/photos'

  /**
   * Photos of one entry: thumbnails, a full view, adding from camera or
   * gallery, removing with a second tap. The photos live in Nextcloud next
   * to the note; the note shows them as images too.
   */
  let {
    type,
    id,
    photos = $bindable([]),
    label = 'Fotos',
  }: { type: PhotoOwner; id: string; photos: Photo[]; label?: string } = $props()

  let busy = $state(false)
  let error = $state('')
  let open = $state<Photo | null>(null)
  let confirming = $state(false)
  let input: HTMLInputElement | undefined = $state()

  async function add(event: Event) {
    const files = [...((event.currentTarget as HTMLInputElement).files ?? [])]
    if (files.length === 0) return
    busy = true
    error = ''
    try {
      for (const file of files) photos = await uploadPhoto(type, id, file)
    } catch (e) {
      error = e instanceof ApiError ? (Object.values(e.fields)[0] ?? e.text) : 'Das Foto ließ sich nicht lesen. Versuch ein anderes.'
    } finally {
      busy = false
      if (input) input.value = ''
    }
  }

  async function remove(photo: Photo) {
    busy = true
    error = ''
    try {
      photos = await deletePhoto(type, id, photo.path)
      open = null
    } catch (e) {
      error = e instanceof ApiError ? e.text : ''
    } finally {
      busy = false
      confirming = false
    }
  }

  function show(photo: Photo) {
    open = photo
    confirming = false
  }
</script>

<div class="gallery">
  <ul class="thumbs" aria-label={label}>
    {#each photos as photo (photo.path)}
      <li>
        <button type="button" class="thumb" onclick={() => show(photo)} aria-label={photo.alt ? `Foto: ${photo.alt}` : 'Foto ansehen'}>
          <img src={photo.url} alt="" loading="lazy" />
        </button>
      </li>
    {/each}
    <li>
      <label class="thumb add" class:busy>
        <input bind:this={input} type="file" accept="image/*" multiple onchange={add} disabled={busy} />
        <Camera size={22} strokeWidth={1.75} aria-hidden="true" />
        <span>{busy ? 'Lädt …' : 'Foto'}</span>
      </label>
    </li>
  </ul>
  {#if error}<p class="error" role="alert">{error}</p>{/if}
</div>

{#if open}
  <div class="viewer" role="dialog" aria-modal="true" aria-label={open.alt || 'Foto'}>
    <div class="bar">
      {#if confirming}
        <button type="button" class="btn btn-secondary danger" disabled={busy} onclick={() => remove(open!)}>Wirklich löschen</button>
      {:else}
        <button type="button" class="btn btn-icon on-dark" aria-label="Foto löschen" onclick={() => (confirming = true)}>
          <Trash2 size={20} strokeWidth={1.75} aria-hidden="true" />
        </button>
      {/if}
      <button type="button" class="btn btn-icon on-dark" aria-label="Schließen" onclick={() => (open = null)}>
        <X size={20} strokeWidth={2} aria-hidden="true" />
      </button>
    </div>
    <img src={open.url} alt={open.alt} />
    {#if open.alt}<p class="caption">{open.alt}</p>{/if}
  </div>
{/if}

<style>
  .gallery {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .thumbs {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
    gap: var(--space-2);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .thumb {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    aspect-ratio: 1;
    padding: 0;
    overflow: hidden;
    border: 0;
    border-radius: var(--radius-sm);
    background: var(--paper);
    cursor: pointer;
  }

  .thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .add {
    position: relative;
    flex-direction: column;
    gap: var(--space-1);
    box-shadow: inset 0 0 0 1px var(--line-strong);
    color: var(--ink-muted);
    font-size: 13px;
    font-weight: 600;
  }

  .add input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
  }

  .add:focus-within {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
  }

  .busy {
    opacity: 0.6;
  }

  .error {
    font-size: 13px;
    font-weight: 600;
    color: var(--due);
  }

  /* Full view: always dark, like a photo viewer */
  .viewer {
    position: fixed;
    inset: 0;
    z-index: 20;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
    padding: var(--space-4);
    background: color-mix(in srgb, var(--inverse) 96%, transparent);
  }

  .bar {
    position: absolute;
    top: calc(var(--space-4) + env(safe-area-inset-top));
    right: var(--space-4);
    display: flex;
    gap: var(--space-2);
  }

  .on-dark {
    color: var(--on-inverse);
    background: color-mix(in srgb, var(--on-inverse) 14%, transparent);
  }

  .danger {
    background: var(--due);
    color: var(--on-accent);
  }

  .viewer img {
    max-width: 100%;
    max-height: 80dvh;
    border-radius: var(--radius-sm);
    object-fit: contain;
  }

  .caption {
    color: var(--on-inverse);
    font-size: 14px;
  }
</style>
