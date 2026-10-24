<script lang="ts">
  import { Pencil, QrCode } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { euro } from '../lib/contracts'
  import { formatDate } from '../lib/format'
  import { OWNERS, endText, maintenanceChip, paperlessLink } from '../lib/house'
  import { roomName } from '../lib/meters'
  import { settings } from '../lib/settings.svelte'
  import type { DeviceDetail } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import PhotoGallery from '../components/PhotoGallery.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Unterlagen from '../components/Unterlagen.svelte'

  let { id }: { id: string } = $props()

  const device = new Resource(() => api.get<DeviceDetail>(`/devices/${encodeURIComponent(id)}`))
  const today = todayIso()
  void settings.load()

  /** Placeholders like "[Modell]" from the examples are no data. */
  const shown = (value: unknown) => (typeof value === 'string' && /^\[.*\]$/.test(value) ? '' : value === undefined || value === null ? '' : String(value))
</script>

<PageHeader back="/geraete">
  {#snippet action()}
    <a class="btn btn-secondary" href="/geraete/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={device} />

{#if device.data}
  {@const d = device.data}
  {@const f = d.fields}
  {@const paperless = paperlessLink(settings.paperlessUrl, f.paperless)}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="geraete">Gerät{f.raum ? ` · ${roomName(f.raum)}` : ''}</StatusChip>
      {#if f.eigentum === 'vermieter'}<StatusChip tone="neutral">Vom Vermieter</StatusChip>{/if}
    </span>
    <h1 class="page-title">{d.name}</h1>
    {#if shown(f.hersteller) || shown(f.modell)}<p class="text-muted">{[shown(f.hersteller), shown(f.modell)].filter(Boolean).join(' ')}</p>{/if}
  </header>

  <div class="detail-columns">
    <div class="stack">
      {#if d.evaluation.warranty || d.evaluation.liability}
        <section class="card stack" aria-labelledby="garantie">
          <h2 id="garantie" class="section-title">Garantie</h2>
          {#each [['Gewährleistung', d.evaluation.liability], ['Garantie', d.evaluation.warranty]] as const as [label, end] (label)}
            {#if end}
              <p class:soon={end.daysUntil >= 0 && end.status !== 'ok'}>{endText(label, end)}</p>
            {/if}
          {/each}
          <p class="text-muted text-small">Gewährleistung: zwei Jahre ab Kauf gegenüber dem Händler. Garantie: freiwillig vom Hersteller, Bedingungen im Garantieschein.</p>
        </section>
      {:else if f.eigentum === 'vermieter'}
        <section class="card">
          <p>Das Gerät gehört dem Vermieter. Geht es kaputt, meldest du es als Mangel; Reparatur und Ersatz sind seine Sache.</p>
        </section>
      {/if}

      <section class="card stack" aria-labelledby="fotos">
        <h2 id="fotos" class="section-title">Fotos</h2>
        <PhotoGallery type="geraet" {id} bind:photos={d.photos} label="Fotos vom Gerät" />
        <p class="text-muted text-small">Typenschild, Rechnung, Zustand – für Reparatur, Garantie und die Hausratversicherung.</p>
      </section>

      {#if d.maintenance.length > 0}
        <section class="card list-card" aria-labelledby="wartung">
          <h2 id="wartung" class="group-title">Wartung</h2>
          <ul>
            {#each d.maintenance as task (task.id)}
              {@const chip = maintenanceChip(task.evaluation, today)}
              <li>
                <a class="list-row" href="/wartung/{encodeURIComponent(task.id)}">
                  <span class="list-text"><span class="list-title">{task.title}</span></span>
                  <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
                </a>
              </li>
            {/each}
          </ul>
        </section>
      {/if}
    </div>

    <section class="card stack" aria-labelledby="daten">
      <h2 id="daten" class="section-title">Daten</h2>
      <dl class="fact-list">
        <div><dt>Gehört</dt><dd>{OWNERS[f.eigentum ?? 'mieter'] ?? f.eigentum}</dd></div>
        {#if shown(f.seriennummer)}<div><dt>Seriennummer</dt><dd class="num">{shown(f.seriennummer)}</dd></div>{/if}
        {#if f.kaufdatum}<div><dt>Gekauft</dt><dd class="num">{formatDate(f.kaufdatum)}</dd></div>{/if}
        {#if shown(f.haendler)}<div><dt>Händler</dt><dd>{shown(f.haendler)}</dd></div>{/if}
        {#if f.preis_eur !== undefined}<div><dt>Preis</dt><dd class="num">{euro(f.preis_eur)}</dd></div>{/if}
        {#if f.baujahr}<div><dt>Baujahr</dt><dd class="num">{f.baujahr}</dd></div>{/if}
        {#if paperless}<div><dt>Paperless</dt><dd class="num"><a href={paperless} target="_blank" rel="noopener">Dokument {f.paperless}</a></dd></div>{/if}
      </dl>
      {#if d.notes}<p class="notes-text">{d.notes}</p>{/if}
      <a class="btn btn-secondary on-surface" href="/aufkleber?nur=g:{encodeURIComponent(d.id)}">
            <QrCode size={16} strokeWidth={1.75} aria-hidden="true" />
            QR-Aufkleber drucken
          </a>
          {#if d.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {d.modifiedBy}</p>{/if}
    </section>
  </div>

  <Unterlagen type="geraet" {id} />
{/if}

<style>
  .soon {
    color: var(--soon-text);
    font-weight: 600;
  }
</style>
