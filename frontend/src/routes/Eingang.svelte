<script lang="ts">
  import { BookOpen, CircleCheck, ClipboardPaste, FileText, Paperclip, Trash2, TriangleAlert } from '@lucide/svelte'
  import { onMount, untrack } from 'svelte'
  import { ApiError } from '../lib/api'
  import { formatDay } from '../lib/format'
  import { handDraft, inbox, kindOf, splitText, TARGETS, titleFrom, type InboxItem, type Target } from '../lib/inbox.svelte'
  import { outbox } from '../lib/outbox.svelte'
  import { shrink } from '../lib/photos'
  import { todayIso } from '../lib/resource.svelte'
  import { router } from '../lib/router.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /**
   * Inbox: what was shared to the app (Android) or chosen and pasted here
   * (iPhone) waits on this device until it is filed with an entry.
   */
  const shared = router.query.get('geteilt')
  let pasted = $state('')
  let fileInput: HTMLInputElement | undefined = $state()
  let notice = $state<{ text: string; href?: string } | null>(null)

  /** Per item: chosen area, entry and title */
  let choice = $state<Record<string, { type: Target['type']; entry: string; title: string; busy: boolean; error: string }>>({})
  let entries = $state<Partial<Record<Target['type'], { id: string; title: string }[] | 'error'>>>({})
  const previews = new Map<string, string>()

  onMount(() => {
    void inbox.load()
    // Images or text from the clipboard anywhere on the page
    const onPaste = (event: ClipboardEvent) => {
      const files = [...(event.clipboardData?.files ?? [])]
      if (files.length > 0 && !(event.target instanceof HTMLTextAreaElement)) {
        event.preventDefault()
        void addFiles(files)
      }
    }
    document.addEventListener('paste', onPaste)
    return () => {
      document.removeEventListener('paste', onPaste)
      for (const url of previews.values()) URL.revokeObjectURL(url)
    }
  })

  const preview = (item: InboxItem) => {
    if (!item.blob) return ''
    if (!previews.has(item.id)) previews.set(item.id, URL.createObjectURL(item.blob))
    return previews.get(item.id)!
  }

  // A choice for every new item, and the entries for its area
  $effect(() => {
    for (const item of inbox.items) {
      untrack(() => {
        choice[item.id] ??= { type: 'mangel', entry: '', title: titleFrom(item.name, item.kind, formatDay(todayIso())), busy: false, error: '' }
        if (item.kind !== 'text') void loadEntries(choice[item.id].type)
      })
    }
  })

  async function loadEntries(type: Target['type']) {
    if (entries[type] !== undefined) return
    entries[type] = []
    try {
      entries[type] = await TARGETS.find((t) => t.type === type)!.load()
    } catch {
      entries[type] = 'error'
    }
  }

  async function addFiles(files: File[]) {
    const items = files.flatMap((file) => {
      const kind = kindOf(file.type)
      return kind && kind !== 'text' ? [{ kind, name: file.name, blob: file as Blob }] : []
    })
    if (items.length < files.length) notice = { text: 'Nur Fotos und PDFs kommen in den Eingang.' }
    await inbox.add(items)
  }

  async function choose(event: Event) {
    const input = event.currentTarget as HTMLInputElement
    const files = [...(input.files ?? [])]
    input.value = ''
    await addFiles(files)
  }

  async function addText() {
    if (pasted.trim() === '') return
    await inbox.add([{ kind: 'text', name: '', text: pasted.trim() }])
    pasted = ''
  }

  async function assign(item: InboxItem) {
    const c = choice[item.id]
    const target = TARGETS.find((t) => t.type === c.type)!
    if (!c.entry || !item.blob) {
      c.error = 'Wähl aus, wozu es gehört.'
      return
    }
    c.busy = true
    c.error = ''
    const entryTitle = (entries[c.type] as { id: string; title: string }[]).find((e) => e.id === c.entry)?.title ?? c.entry
    try {
      const photo = item.kind === 'image'
      const blob = photo ? await shrink(item.blob as File).catch(() => item.blob!) : item.blob
      const sent = await outbox.send(`${photo ? 'Foto' : 'Dokument'} zu ${target.label} „${entryTitle}“`, [
        photo
          ? { method: 'POST', path: `/photos/${c.type}/${encodeURIComponent(c.entry)}`, form: { foto: blob, alt: c.title.trim() } }
          : { method: 'POST', path: `/documents/${c.type}/${encodeURIComponent(c.entry)}`, form: { dokument: blob, titel: c.title.trim() } },
      ])
      await inbox.remove(item.id)
      notice = sent.queued
        ? { text: `„${c.title}“ wartet auf Netz und geht dann zu ${target.label} „${entryTitle}“.` }
        : { text: `„${c.title}“ liegt jetzt bei ${target.label} „${entryTitle}“.`, href: target.href(c.entry) }
    } catch (e) {
      c.error = e instanceof ApiError ? (Object.values(e.fields)[0] ?? e.text) : 'Das hat nicht geklappt.'
    } finally {
      c.busy = false
    }
  }

  async function asDraft(item: InboxItem, href: string) {
    handDraft(splitText(item.text ?? ''))
    await inbox.remove(item.id)
    router.go(href)
  }
</script>

<PageHeader back="/mehr" />

<header class="page-head">
  <h1 class="page-title">Eingang</h1>
  <p class="text-muted">Fotos, PDFs und Texte, die du mit Wohnpilot geteilt hast. Sie liegen nur auf diesem Gerät, bis du sie einem Eintrag zuordnest.</p>
</header>

{#if shared === 'fehler'}
  <p class="save-error" role="alert">Das Teilen hat nicht geklappt. Versuch es noch einmal oder wähl die Datei hier aus.</p>
{:else if shared && Number(shared) > 0}
  <p class="notice" role="status"><CircleCheck size={18} strokeWidth={1.75} aria-hidden="true" /> {shared === '1' ? 'Ein Element ist' : `${shared} Elemente sind`} angekommen.</p>
{/if}
{#if notice}
  <p class="notice" role="status">
    <CircleCheck size={18} strokeWidth={1.75} aria-hidden="true" />
    <span>{notice.text}{#if notice.href}{' '}<a href={notice.href}>Ansehen</a>{/if}</span>
  </p>
{/if}

<section class="card stack" aria-labelledby="hinzu">
  <h2 id="hinzu" class="section-title">Hinzufügen</h2>
  <input bind:this={fileInput} class="visually-hidden" type="file" accept="image/*,application/pdf" multiple tabindex="-1" aria-hidden="true" onchange={choose} />
  <button type="button" class="btn btn-secondary on-surface add" onclick={() => fileInput?.click()}>
    <Paperclip size={18} strokeWidth={1.75} aria-hidden="true" />
    Fotos oder PDFs wählen
  </button>
  <div class="field">
    <label for="einfuegen">Text einfügen</label>
    <textarea id="einfuegen" class="input" rows="3" bind:value={pasted} placeholder="z. B. eine Nachricht vom Vermieter"></textarea>
  </div>
  <button type="button" class="btn btn-secondary on-surface add" disabled={pasted.trim() === ''} onclick={addText}>
    <ClipboardPaste size={18} strokeWidth={1.75} aria-hidden="true" />
    In den Eingang
  </button>
  <p class="text-muted text-small">Auf Android teilst du direkt aus Galerie, Mail oder Dateien an Wohnpilot. Auf dem iPhone wählst du hier Dateien oder fügst Text und Bilder ein.</p>
</section>

{#if inbox.items.length === 0}
  <p class="text-muted empty">Der Eingang ist leer.</p>
{:else}
  <ul class="items">
    {#each inbox.items as item (item.id)}
      {@const c = choice[item.id]}
      <li class="card stack">
        <div class="item-head">
          {#if item.kind === 'image'}
            <img src={preview(item)} alt="" class="thumb" />
          {:else if item.kind === 'pdf'}
            <a class="thumb pdf" href={preview(item)} target="_blank" rel="noopener" aria-label="PDF ansehen">
              <FileText size={28} strokeWidth={1.75} aria-hidden="true" />
            </a>
          {/if}
          <div class="item-text">
            {#if item.kind === 'text'}
              <p class="shared-text">{item.text}</p>
            {:else}
              <span class="item-name">{item.name || (item.kind === 'pdf' ? 'PDF' : 'Foto')}</span>
            {/if}
            <span class="text-muted text-small">{item.kind === 'pdf' ? 'PDF' : item.kind === 'image' ? 'Foto' : 'Text'} · {formatDay(item.createdAt.slice(0, 10))}</span>
          </div>
          <button type="button" class="btn btn-icon" aria-label="Aus dem Eingang löschen" onclick={() => void inbox.remove(item.id)}>
            <Trash2 size={18} strokeWidth={1.75} aria-hidden="true" />
          </button>
        </div>

        {#if !c}
          <!-- set up by the effect above -->
        {:else if item.kind === 'text'}
          <div class="actions">
            <button type="button" class="btn btn-secondary on-surface" onclick={() => asDraft(item, '/maengel/neu')}>
              <TriangleAlert size={18} strokeWidth={1.75} aria-hidden="true" />
              Als Mangel melden
            </button>
            <button type="button" class="btn btn-secondary on-surface" onclick={() => asDraft(item, '/wissen/neu')}>
              <BookOpen size={18} strokeWidth={1.75} aria-hidden="true" />
              Als Wissensseite
            </button>
          </div>
        {:else}
          {@const list = entries[c.type]}
          <div class="field">
            <label for="titel-{item.id}">Titel</label>
            <input id="titel-{item.id}" class="input" bind:value={c.title} />
          </div>
          <div class="pair">
            <div class="field">
              <label for="art-{item.id}">Gehört zu</label>
              <select id="art-{item.id}" class="input" bind:value={c.type} onchange={() => { c.entry = ''; void loadEntries(c.type) }}>
                {#each TARGETS as t (t.type)}<option value={t.type}>{t.label}</option>{/each}
              </select>
            </div>
            <div class="field">
              <label for="eintrag-{item.id}">Eintrag</label>
              <select id="eintrag-{item.id}" class="input" bind:value={c.entry} disabled={!Array.isArray(list) || list.length === 0}>
                <option value="">{list === 'error' ? 'Gerade nicht ladbar' : Array.isArray(list) && list.length === 0 ? 'Noch keiner' : 'Bitte wählen'}</option>
                {#if Array.isArray(list)}
                  {#each list as e (e.id)}<option value={e.id}>{e.title}</option>{/each}
                {/if}
              </select>
            </div>
          </div>
          {#if c.error}<p class="save-error" role="alert">{c.error}</p>{/if}
          <button type="button" class="btn btn-primary" disabled={c.busy} onclick={() => assign(item)}>{c.busy ? 'Ordnet zu …' : 'Zuordnen'}</button>
        {/if}
      </li>
    {/each}
  </ul>
{/if}

<style>
  .add {
    align-self: flex-start;
  }

  .notice {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    background: var(--ok-tint);
    color: var(--ok);
    font-weight: 600;
  }

  .empty {
    padding: 0 var(--space-1);
  }

  .items {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .item-head {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
  }

  .thumb {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    border-radius: var(--radius-xs);
    object-fit: cover;
  }

  .thumb.pdf {
    display: grid;
    place-items: center;
    background: var(--paper);
    color: var(--ink-muted);
  }

  .item-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .item-name {
    font-weight: 600;
    overflow-wrap: anywhere;
  }

  .shared-text {
    display: -webkit-box;
    overflow: hidden;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    -webkit-line-clamp: 6;
    line-clamp: 6;
    -webkit-box-orient: vertical;
  }

  .pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }
</style>
