<script lang="ts">
  import { Printer } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { router } from '../lib/router.svelte'
  import { CATEGORIES, isPlaceholder } from '../lib/knowledge'
  import { groupMeterNumber, meterTitle, roomName } from '../lib/meters'
  import { qrPathData } from '../lib/qr'
  import { ACTIONS, FORMATS, qrPath, sheets, type Sticker } from '../lib/stickers'
  import type { DeviceSummary, KnowledgePage, MeterSummary } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** Printable A4 sheet of QR stickers for meters, devices and places in the house. */
  const all = new Resource(async () => {
    const [meters, devices, pages] = await Promise.all([
      api.get<{ meters: MeterSummary[] }>('/meters'),
      api.get<{ devices: DeviceSummary[] }>('/devices'),
      api.get<{ pages: KnowledgePage[] }>('/knowledge'),
    ])
    // Placeholders like "[Hersteller]" from the templates stay off the sticker
    const real = (v: string | undefined) => (v && !isPlaceholder(v) ? v : '')
    const join = (...parts: (string | undefined)[]) => parts.map(real).filter(Boolean).join(' · ')
    const groups: { title: string; stickers: Sticker[]; preset: (s: Sticker) => boolean }[] = [
      {
        title: 'Zähler',
        stickers: meters.meters.map((m) => ({ kind: 'z', id: m.id, title: meterTitle(m), number: real(m.number) ? groupMeterNumber(m.number) : undefined, detail: join(m.room ? roomName(m.room) : '') })),
        preset: () => true,
      },
      {
        title: 'Geräte',
        stickers: devices.devices.map((d) => ({ kind: 'g', id: d.id, title: d.name, detail: join([real(d.fields.hersteller), real(d.fields.modell)].filter(Boolean).join(' '), d.fields.raum ? roomName(d.fields.raum) : '') })),
        preset: () => true,
      },
      {
        title: 'Wissen',
        stickers: [
          ...pages.pages.map((p): Sticker => ({ kind: 'w', id: p.id, title: p.title, detail: join(CATEGORIES[p.category] ?? p.category, p.fields.raum ? roomName(p.fields.raum) : '') })),
          { kind: 'n', id: 'blatt', title: 'Notfallblatt', detail: 'Wasser, Strom, Gas, Heizung' },
        ],
        // Places are what you look for at the spot
        preset: (s) => s.kind === 'w' && pages.pages.find((p) => p.id === s.id)?.category === 'ort',
      },
    ]
    return groups.filter((g) => g.stickers.length > 0)
  })

  const key = (s: Sticker) => `${s.kind}:${s.id}`
  /** From a detail page: just that one sticker */
  const only = router.query.get('nur')
  let chosen = $state<Record<string, boolean>>({})
  $effect(() => {
    if (!all.data) return
    const preset: Record<string, boolean> = {}
    for (const g of all.data) for (const s of g.stickers) preset[key(s)] = only ? key(s) === only : g.preset(s)
    chosen = preset
  })

  let formatId = $state(FORMATS[0].id)
  let firstPlace = $state(1)
  const format = $derived(FORMATS.find((f) => f.id === formatId) ?? FORMATS[0])
  const perSheet = $derived(format.columns * format.rows)
  const selected = $derived((all.data ?? []).flatMap((g) => g.stickers).filter((s) => chosen[key(s)]))
  const pages = $derived(sheets(selected, format, (Math.min(Math.max(1, firstPlace || 1), perSheet)) - 1))

  const origin = window.location.origin
  const codes = $derived(new Map(selected.map((s) => [key(s), qrPathData(origin + qrPath(s.kind, s.id))])))

  /** A4 at 96 dpi, for scaling the preview to the screen */
  const A4_WIDTH_PX = (210 / 25.4) * 96
  let previewWidth = $state(0)
  const scale = $derived(previewWidth > 0 ? Math.min(1, previewWidth / A4_WIDTH_PX) : 1)

  function setGroup(stickers: Sticker[], on: boolean) {
    for (const s of stickers) chosen[key(s)] = on
  }
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <button type="button" class="btn btn-secondary" disabled={selected.length === 0} onclick={() => window.print()}>
      <Printer size={16} strokeWidth={1.75} aria-hidden="true" />
      Drucken
    </button>
  {/snippet}
</PageHeader>

<header class="page-head no-print">
  <h1 class="page-title">QR-Aufkleber</h1>
  <p class="text-muted">Klebe sie an Zähler, Geräte und Orte im Haus. Mit der Handykamera gescannt, öffnet ein Aufkleber direkt das Ablesen, das Gerät oder die Anleitung.</p>
</header>

<LoadState resource={all} />

{#if all.data}
  <div class="detail-columns no-print">
    <section class="card stack" aria-labelledby="auswahl">
      <h2 id="auswahl" class="section-title">Was soll drauf?</h2>
      {#each all.data as group (group.title)}
        {@const count = group.stickers.filter((s) => chosen[key(s)]).length}
        <div class="group" role="group" aria-labelledby="gruppe-{group.title}">
          <div class="group-head">
            <span id="gruppe-{group.title}" class="group-title">{group.title}</span>
            <button type="button" class="btn btn-secondary on-surface" onclick={() => setGroup(group.stickers, count < group.stickers.length)}>
              {count < group.stickers.length ? 'Alle' : 'Keine'}<span class="visually-hidden"> {group.title}</span>
            </button>
          </div>
          {#each group.stickers as s (key(s))}
            <label class="checkbox">
              <input type="checkbox" bind:checked={chosen[key(s)]} />
              <span class="choice">
                <span>{s.title}</span>
                {#if s.number || s.detail}<span class="text-muted text-small">{#if s.number}<span class="num">{s.number}</span>{s.detail ? ' · ' : ''}{/if}{s.detail}</span>{/if}
              </span>
            </label>
          {/each}
        </div>
      {/each}
    </section>

    <section class="card stack" aria-labelledby="bogen">
      <h2 id="bogen" class="section-title">Bogen</h2>
      <fieldset class="field">
        <legend>Format</legend>
        {#each FORMATS as f (f.id)}
          <label class="checkbox">
            <input type="radio" name="format" value={f.id} bind:group={formatId} />
            <span class="choice">
              <span>{f.name}</span>
              <span class="text-muted text-small">{f.hint}</span>
            </span>
          </label>
        {/each}
      </fieldset>
      <div class="field">
        <label for="erste">Erster freier Platz</label>
        <input id="erste" class="input num" type="number" inputmode="numeric" min="1" max={perSheet} bind:value={firstPlace} />
        <span class="hint">Bei einem angebrochenen Bogen: gezählt von links oben, Zeile für Zeile.</span>
      </div>
      <p class="text-small text-muted">
        {selected.length === 0 ? 'Nichts ausgewählt.' : `${selected.length} Aufkleber auf ${pages.length} ${pages.length === 1 ? 'Bogen' : 'Bögen'}.`}
        Drucke in Originalgröße (100 %), nicht „An Seite anpassen“.
      </p>
    </section>
  </div>

  {#if selected.length > 0}
    <h2 class="section-title no-print">Vorschau</h2>
    <div class="previews" bind:clientWidth={previewWidth}>
      {#each pages as page, p (p)}
        <div class="preview" style:height="{(297 / 25.4) * 96 * scale}px">
          <div
            class="sheet"
            data-theme="light"
            style:--w="{format.width}mm"
            style:--h="{format.height}mm"
            style:--pad="{format.padding}mm"
            style:--title="{Math.max(2.4, format.height * 0.085)}mm"
            style:--small="{Math.max(1.8, format.height * 0.06)}mm"
            style:grid-template-columns="repeat({format.columns}, {format.width}mm)"
            style:grid-auto-rows="{format.height}mm"
            style:padding="{format.top}mm 0 0 {format.left}mm"
            style:column-gap="{format.gapX}mm"
            style:row-gap="{format.gapY}mm"
            style:transform="scale({scale})"
            aria-label="Bogen {p + 1}"
          >
            {#each page as s, i (s ? key(s) : `leer-${i}`)}
              {#if s}
                {@const code = codes.get(key(s))}
                <div class="sticker" class:cut={format.cut}>
                  {#if code}
                    <svg class="qr" viewBox="-2 -2 {code.size + 4} {code.size + 4}" role="img" aria-label="QR-Code für {s.title}">
                      <rect x="-2" y="-2" width={code.size + 4} height={code.size + 4} class="qr-bg" />
                      <path d={code.d} class="qr-fg" shape-rendering="crispEdges" />
                    </svg>
                  {/if}
                  <div class="label">
                    <strong class="label-title">{s.title}</strong>
                    {#if s.number}<span class="label-detail num">{s.number}</span>{/if}
                    {#if s.detail}<span class="label-detail">{s.detail}</span>{/if}
                    {#if format.height >= 30}<span class="label-action">{ACTIONS[s.kind]}</span>{/if}
                  </div>
                </div>
              {:else}
                <div class="sticker empty" class:cut={format.cut} aria-hidden="true"></div>
              {/if}
            {/each}
          </div>
        </div>
      {/each}
    </div>
  {/if}
{/if}

<style>
  .group {
    display: flex;
    flex-direction: column;
  }

  .group + .group {
    padding-top: var(--space-2);
    border-top: 1px solid var(--line);
  }

  .group-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
  }

  .group-head .group-title {
    padding-top: 0;
  }

  .choice {
    display: flex;
    flex-direction: column;
    padding: var(--space-1) 0;
  }

  .field legend {
    margin-bottom: var(--space-1);
    font-size: 14px;
    font-weight: 600;
  }

  fieldset.field {
    margin: 0;
    padding: 0;
    border: 0;
  }

  .previews {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .preview {
    overflow: hidden;
  }

  .sheet {
    display: grid;
    box-sizing: border-box;
    width: 210mm;
    height: 297mm;
    align-content: start;
    background: var(--surface);
    color: var(--ink);
    box-shadow: 0 0 0 1px var(--line);
    transform-origin: top left;
  }

  .sticker {
    display: flex;
    gap: 2mm;
    box-sizing: border-box;
    align-items: center;
    width: var(--w);
    height: var(--h);
    padding: var(--pad);
    overflow: hidden;
  }

  .sticker.cut {
    outline: 0.2mm dashed var(--line-strong);
    outline-offset: -0.1mm;
  }

  .qr {
    flex-shrink: 0;
    height: 100%;
    aspect-ratio: 1;
  }

  .qr-bg {
    fill: var(--surface);
  }

  .qr-fg {
    fill: var(--ink);
  }

  .label {
    display: flex;
    flex-direction: column;
    gap: 0.6mm;
    min-width: 0;
    overflow: hidden;
  }

  .label-title {
    font-size: var(--title);
    line-height: 1.15;
    overflow-wrap: anywhere;
  }

  .label-detail,
  .label-action {
    font-size: var(--small);
    line-height: 1.2;
  }

  .label-detail {
    overflow-wrap: anywhere;
  }

  .label-action {
    color: var(--ink-muted);
  }

  @media print {
    .previews {
      display: block;
    }

    .preview {
      height: auto !important;
      overflow: visible;
    }

    .sheet {
      page: stickers;
      transform: none !important;
      box-shadow: none;
      print-color-adjust: exact;
    }

    .preview + .preview .sheet {
      break-before: page;
    }
  }
</style>
