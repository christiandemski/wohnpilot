<script lang="ts">
  import { Pencil, QrCode } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { CATEGORIES, SCENARIOS } from '../lib/knowledge'
  import { roomName } from '../lib/meters'
  import type { DeviceSummary, KnowledgeDetail } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import Markdown from '../components/Markdown.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import PhotoGallery from '../components/PhotoGallery.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Unterlagen from '../components/Unterlagen.svelte'
  import DeleteEntry from '../components/DeleteEntry.svelte'

  let { id }: { id: string } = $props()

  const page = new Resource(() => api.get<KnowledgeDetail>(`/knowledge/${encodeURIComponent(id)}`))
  const devices = new Resource(async () => (await api.get<{ devices: DeviceSummary[] }>('/devices')).devices)
  const deviceName = (deviceId: string) => devices.data?.find((d) => d.id === deviceId)?.name ?? deviceId

  /** Photos show in the gallery; their lines would repeat them in the text. */
  const withoutImages = (text: string) => text.replace(/^\s*!\[[^\]\n]*\]\([^)]*\)\s*$/gm, '').trim()
</script>

<PageHeader back="/wissen">
  {#snippet action()}
    <a class="btn btn-secondary" href="/wissen/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={page} />

{#if page.data}
  {@const p = page.data}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="wissen">{CATEGORIES[p.category] ?? p.category}{p.fields.raum ? ` · ${roomName(p.fields.raum)}` : ''}</StatusChip>
      {#each p.fields.notfall ?? [] as scenario (scenario)}<StatusChip tone="neutral">Notfall: {SCENARIOS[scenario] ?? scenario}</StatusChip>{/each}
    </span>
    <h1 class="page-title">{p.title}</h1>
  </header>

  <div class="detail-columns">
    <section class="card stack" aria-label="Inhalt">
      {#if withoutImages(p.text)}
        <Markdown text={withoutImages(p.text)} path={p.path} />
      {:else}
        <p class="text-muted">Noch kein Text. Unter „Bearbeiten“ schreibst du, was man wissen muss.</p>
      {/if}
      {#if p.fields.bezieht_sich_auf?.length}
        <p class="text-small">
          Gerät:
          {#each p.fields.bezieht_sich_auf as deviceId, i (deviceId)}{#if i > 0}, {/if}<a href="/geraete/{encodeURIComponent(deviceId)}">{deviceName(deviceId)}</a>{/each}
        </p>
      {/if}
      <a class="btn btn-secondary on-surface" href="/aufkleber?nur=w:{encodeURIComponent(p.id)}">
            <QrCode size={16} strokeWidth={1.75} aria-hidden="true" />
            QR-Aufkleber drucken
          </a>
          {#if p.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {p.modifiedBy}</p>{/if}
          <DeleteEntry type="wissen" id={p.id} etag={p.etag} what="Seite" title={p.title} back="/wissen" hint={'Mit Fotos und Unterlagen.'} onChanged={() => page.reload()} />
    </section>

    <section class="card stack" aria-labelledby="fotos">
      <h2 id="fotos" class="section-title">Fotos</h2>
      <PhotoGallery type="wissen" {id} bind:photos={p.photos} label="Fotos zur Seite" />
      <p class="text-muted text-small">Ein Foto sagt oft mehr: wo genau der Hahn sitzt, wie der Kasten beschriftet ist.</p>
    </section>
  </div>

  <Unterlagen type="wissen" {id} />
{/if}
