<script lang="ts">
  import { Pencil } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { addDays, dueChip, euro } from '../lib/contracts'
  import { days, formatDate } from '../lib/format'
  import { DEFECT_STATUSES, RESPONSIBLE } from '../lib/house'
  import { roomName } from '../lib/meters'
  import type { DefectDetail, DefectStatus } from '../lib/types'
  import ContactLine from '../components/ContactLine.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import PhotoGallery from '../components/PhotoGallery.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Unterlagen from '../components/Unterlagen.svelte'

  let { id }: { id: string } = $props()

  const path = $derived(`/defects/${encodeURIComponent(id)}`)
  const defect = new Resource(() => api.get<DefectDetail>(path))
  const today = todayIso()
  const CHANNELS = ['E-Mail', 'Telefon', 'Brief', 'Persönlich', 'Portal']
  const ORDER: DefectStatus[] = ['entdeckt', 'gemeldet', 'bestaetigt', 'termin', 'behoben']

  let status = $state<DefectStatus>('gemeldet')
  let channel = $state('E-Mail')
  let note = $state('')
  let followUp = $state('')
  let busy = $state(false)
  let error = $state('')

  /** Suggest the next step and, while the landlord is on it, a follow-up in a week. */
  $effect(() => {
    const d = defect.data
    if (!d) return
    const next = ORDER[Math.min(ORDER.indexOf(d.evaluation.status) + 1, ORDER.length - 1)]
    status = next
  })
  $effect(() => {
    followUp = status === 'behoben' ? '' : addDays(today, 7)
  })

  async function save(event: SubmitEvent) {
    event.preventDefault()
    busy = true
    error = ''
    try {
      defect.data = await api.post<DefectDetail>(`${path}/status`, {
        status,
        kanal: status === 'gemeldet' || status === 'bestaetigt' ? channel : '',
        notiz: note.trim(),
        nachfassen: followUp || null,
      })
      note = ''
    } catch (e) {
      error = e instanceof ApiError ? (Object.values(e.fields)[0] ?? e.text) : 'Das hat nicht geklappt.'
    } finally {
      busy = false
    }
  }

  const dateText = (date: string) => (/^\d{4}-\d{2}-\d{2}$/.test(date) ? formatDate(date) : date)
</script>

<PageHeader back="/maengel">
  {#snippet action()}
    <a class="btn btn-secondary" href="/maengel/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={defect} />

{#if defect.data}
  {@const d = defect.data}
  {@const e = d.evaluation}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="maengel">Mangel{d.fields.raum ? ` · ${roomName(d.fields.raum)}` : ''}</StatusChip>
      <StatusChip tone={e.open ? 'neutral' : 'ok'}>{DEFECT_STATUSES[e.status] ?? e.status}</StatusChip>
      {#if e.followUp}
        {@const c = dueChip({ date: e.followUp.date, daysUntil: e.followUp.daysUntil, status: e.followUp.status })}
        <StatusChip tone={c.tone}>Nachfassen {c.text}</StatusChip>
      {/if}
    </span>
    <h1 class="page-title">{d.title}</h1>
    <p class="text-muted">
      {#if e.discovered}Entdeckt am {formatDate(e.discovered)}{/if}{#if e.reported}, gemeldet am {formatDate(e.reported)}{/if}{#if e.fixedOn}, behoben am {formatDate(e.fixedOn)}{/if}.
      {#if e.days !== null && e.days > 0}{e.open ? `Seit ${days(e.days)}.` : `Hat ${days(e.days)} gedauert.`}{/if}
    </p>
  </header>

  <div class="detail-columns">
    <div class="stack">
      {#if e.open}
        <form class="card stack" onsubmit={save} novalidate aria-labelledby="weiter">
          <h2 id="weiter" class="section-title">Wie geht es weiter?</h2>
          <div class="field">
            <label for="status">Neuer Stand</label>
            <select id="status" class="input" bind:value={status}>
              {#each ORDER.slice(1) as value (value)}<option {value}>{DEFECT_STATUSES[value]}</option>{/each}
            </select>
          </div>
          {#if status === 'gemeldet' || status === 'bestaetigt'}
            <div class="field">
              <label for="kanal">Wie?</label>
              <select id="kanal" class="input" bind:value={channel}>
                {#each CHANNELS as value (value)}<option {value}>{value}</option>{/each}
              </select>
            </div>
          {/if}
          <div class="field">
            <label for="notiz">Notiz <span class="optional">(optional)</span></label>
            <input id="notiz" class="input" bind:value={note} placeholder={status === 'termin' ? 'z. B. Fensterbauer am 03.11. um 9 Uhr' : ''} />
          </div>
          {#if status !== 'behoben'}
            <div class="field">
              <label for="nachfassen">Nachfassen am <span class="optional">(optional)</span></label>
              <input id="nachfassen" class="input num" type="date" bind:value={followUp} />
              <span class="hint">Tut sich bis dahin nichts, erinnert dich Wohnpilot.</span>
            </div>
          {/if}
          {#if error}<p class="save-error" role="alert">{error}</p>{/if}
          <button type="submit" class="btn btn-primary" disabled={busy}>Eintragen</button>
        </form>
      {/if}

      <section class="card stack" aria-labelledby="verlauf">
        <h2 id="verlauf" class="section-title">Verlauf</h2>
        <table class="history-table">
          <thead><tr><th scope="col">Datum</th><th scope="col">Stand</th><th scope="col">Notiz</th></tr></thead>
          <tbody>
            {#each d.history as row, i (row.date + row.status + i)}
              <tr>
                <th scope="row" class="num">{dateText(row.date)}</th>
                <td>{DEFECT_STATUSES[row.status as DefectStatus] ?? row.status}{row.channel ? ` · ${row.channel}` : ''}</td>
                <td>{row.note}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </section>

      <section class="card stack" aria-labelledby="fotos">
        <h2 id="fotos" class="section-title">Fotos</h2>
        <PhotoGallery type="mangel" {id} bind:photos={d.photos} label="Fotos vom Mangel" />
        <p class="text-muted text-small">Fotos sind dein Nachweis gegenüber dem Vermieter. Sie liegen in deiner Nextcloud.</p>
      </section>
    </div>

    <section class="card stack" aria-labelledby="angaben">
      <h2 id="angaben" class="section-title">Angaben</h2>
      {#if typeof d.fields.kontakt === 'string'}<ContactLine id={d.fields.kontakt} />{/if}
      <dl class="fact-list">
        <div><dt>Zuständig</dt><dd>{RESPONSIBLE[d.fields.zustaendig ?? 'vermieter'] ?? d.fields.zustaendig}</dd></div>
        {#if d.fields.raum}<div><dt>Raum</dt><dd>{roomName(d.fields.raum)}</dd></div>{/if}
        {#if d.fields.kleinreparatur}<div><dt>Kleinreparatur</dt><dd>ja</dd></div>{/if}
        {#if d.fields.kosten_eur !== undefined}<div><dt>Kosten</dt><dd class="num">{euro(d.fields.kosten_eur)}</dd></div>{/if}
      </dl>
      {#if d.notes}<p class="notes-text">{d.notes}</p>{/if}
      {#if d.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {d.modifiedBy}</p>{/if}
    </section>
  </div>

  <Unterlagen type="mangel" {id} />
{/if}
