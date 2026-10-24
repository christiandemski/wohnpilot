<script lang="ts">
  import { Camera, Plus, TriangleAlert } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { dueChip, euro } from '../lib/contracts'
  import { formatDate } from '../lib/format'
  import { DEFECT_STATUSES } from '../lib/house'
  import { roomName } from '../lib/meters'
  import type { DefectSummary, MinorRepairYear } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  const defects = new Resource(async () => (await api.get<{ defects: DefectSummary[] }>('/defects')).defects)
  const repairs = new Resource(() => api.get<MinorRepairYear>('/defects/minor-repairs'))

  /** Open: the follow-up day if there is one, else the status. */
  function chip(d: DefectSummary) {
    const e = d.evaluation
    if (!e.open) return { text: 'Behoben', tone: 'ok' as const }
    if (e.followUp) {
      const c = dueChip({ date: e.followUp.date, daysUntil: e.followUp.daysUntil, status: e.followUp.status })
      return { text: c.tone === 'neutral' ? `Nachfassen ${c.text}` : `Nachfassen · ${c.text}`, tone: c.tone }
    }
    return { text: DEFECT_STATUSES[e.status] ?? e.status, tone: 'neutral' as const }
  }

  function meta(d: DefectSummary): string {
    const e = d.evaluation
    const parts = [d.fields.raum ? roomName(d.fields.raum) : '']
    if (e.open) {
      parts.push(DEFECT_STATUSES[e.status] ?? e.status)
      if (e.discovered) parts.push(`seit ${formatDate(e.discovered)}`)
    } else if (e.fixedOn) {
      parts.push(`behoben am ${formatDate(e.fixedOn)}`)
    }
    return parts.filter(Boolean).join(' · ')
  }
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    <a class="btn btn-secondary" href="/maengel/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Mangel melden
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Mängel</h1>
</header>

<LoadState resource={defects} />

{#if defects.data}
  {@const open = defects.data.filter((d) => d.evaluation.open)}
  {@const fixed = defects.data.filter((d) => !d.evaluation.open)}
  {#if defects.data.length === 0}
    <div class="card"><p>Keine Mängel. Wenn etwas kaputt ist, meldest du es hier mit Foto – dann weißt du später, wann du es wem gesagt hast.</p></div>
  {/if}
  {#each [{ title: 'Offen', items: open }, { title: 'Behoben', items: fixed }] as group (group.title)}
    {#if group.items.length > 0}
      <section class="card list-card" aria-label={group.title}>
        <h2 class="group-title">{group.title}</h2>
        <ul>
          {#each group.items as d (d.id)}
            {@const c = chip(d)}
            <li>
              <a class="list-row" href="/maengel/{encodeURIComponent(d.id)}">
                <IconTile module="maengel"><TriangleAlert size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
                <span class="list-text">
                  <span class="list-title">{d.title}</span>
                  <span class="list-meta">
                    {meta(d)}
                    {#if d.photoCount > 0}· <Camera size={12} strokeWidth={2} aria-hidden="true" /> <span class="num">{d.photoCount}</span><span class="visually-hidden"> Fotos</span>{/if}
                  </span>
                </span>
                <StatusChip tone={c.tone}>{c.text}</StatusChip>
              </a>
            </li>
          {/each}
        </ul>
      </section>
    {/if}
  {/each}
{/if}

{#if repairs.data && (repairs.data.cases.length > 0 || repairs.data.perYear !== null)}
  {@const r = repairs.data}
  <section class="card stack" aria-labelledby="klein">
    <h2 id="klein" class="section-title">Kleinreparaturen {r.year}</h2>
    <p>
      <span class="num">{euro(r.total)}</span>
      {#if r.perYear !== null}von <span class="num">{euro(r.perYear)}</span> im Jahr{/if}
      {#if r.left !== null && !r.overYear}· es bleiben <span class="num">{euro(r.left)}</span>{/if}
    </p>
    {#if r.overYear}<p class="save-error">Die Jahresgrenze ist überschritten: Weitere Kleinreparaturen zahlt der Vermieter.</p>{/if}
    {#if r.cases.some((c) => c.overLimit)}
      <p class="text-muted text-small">Teurer als {euro(r.perCase ?? 0)} pro Fall ist keine Kleinreparatur mehr: Das zahlt der Vermieter ganz, es zählt nicht mit.</p>
    {/if}
    {#if r.perCase === null}
      <p class="text-muted text-small">Trag die Grenzen aus dem Mietvertrag unter „Zuhause bearbeiten“ ein, dann rechnet Wohnpilot mit.</p>
    {/if}
  </section>
{/if}
