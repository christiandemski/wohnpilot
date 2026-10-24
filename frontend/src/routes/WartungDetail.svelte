<script lang="ts">
  import { CalendarPlus, Check, Pencil, Radio } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { formatDate } from '../lib/format'
  import { EXECUTIONS, RESPONSIBLE, intervalText, maintenanceChip, seasonText } from '../lib/house'
  import { roomName } from '../lib/meters'
  import type { DeviceSummary, MaintenanceDetail } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import PhotoGallery from '../components/PhotoGallery.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  let { id }: { id: string } = $props()

  const path = $derived(`/maintenance/${encodeURIComponent(id)}`)
  const task = new Resource(() => api.get<MaintenanceDetail>(path))
  const devices = new Resource(async () => (await api.get<{ devices: DeviceSummary[] }>('/devices')).devices)
  const today = todayIso()

  let note = $state('')
  let busy = $state(false)
  let error = $state('')
  /** Date of a done row made here, which can be taken back */
  let justDone = $state<string | null>(null)

  async function act(run: () => Promise<MaintenanceDetail>) {
    busy = true
    error = ''
    try {
      task.data = await run()
    } catch (e) {
      error = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
    } finally {
      busy = false
    }
  }

  const done = () =>
    act(async () => {
      const result = await api.post<MaintenanceDetail>(`${path}/done`, { notiz: note.trim() })
      justDone = today
      note = ''
      return result
    })

  const undo = () =>
    act(async () => {
      const result = await api.delete<MaintenanceDetail>(`${path}/done/${justDone}`)
      justDone = null
      return result
    })

  const dateText = (date: string) => (/^\d{4}-\d{2}-\d{2}$/.test(date) ? formatDate(date) : date)
  const deviceName = (deviceId: string) => devices.data?.find((d) => d.id === deviceId)?.name ?? deviceId
</script>

<PageHeader back="/wartung">
  {#snippet action()}
    <a class="btn btn-secondary" href="/wartung/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={task} />

{#if task.data}
  {@const t = task.data}
  {@const e = t.evaluation}
  {@const chip = maintenanceChip(e, today)}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="wartung">Wartung{t.fields.raum ? ` · ${roomName(t.fields.raum)}` : ''}</StatusChip>
      <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
    </span>
    <h1 class="page-title">{t.title}</h1>
    <p class="text-muted">
      {#if e.automatic}
        Wird automatisch per Funk geprüft – du musst nichts tun.
      {:else if e.from && today < e.from}
        Möglich ab {formatDate(e.from)}, erledigt bis {formatDate(e.dueDate)}.
      {:else if e.from}
        In dieser Saison bis {formatDate(e.dueDate)}.
      {:else if e.lastDone === null && e.daysUntil <= 0}
        Noch nie erledigt.
      {:else}
        Fällig am {formatDate(e.dueDate)}.
      {/if}
      {#if e.missedSeason}Die letzte Saison ist ohne Erledigung vorbeigegangen.{/if}
    </p>
  </header>

  <div class="detail-columns">
    <div class="stack">
      {#if e.automatic}
        <section class="card info" aria-label="Funkprüfung">
          <Radio size={20} strokeWidth={1.75} aria-hidden="true" />
          <p>Der Dienstleister prüft per Funk aus der Ferne. Bekommst du einen Prüfbericht, kannst du ihn als Foto hier ablegen.</p>
        </section>
      {:else}
      <section class="card stack" aria-labelledby="erledigen">
        <h2 id="erledigen" class="section-title">Erledigt?</h2>
        <div class="field">
          <label for="notiz">Notiz <span class="optional">(optional)</span></label>
          <input id="notiz" class="input" bind:value={note} placeholder="z. B. Batterie getauscht" />
        </div>
        <div class="actions">
          <button type="button" class="btn btn-primary" disabled={busy} onclick={done}>
            <Check size={18} strokeWidth={2.25} aria-hidden="true" />
            Heute erledigt
          </button>
          {#if justDone}
            <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={undo}>Rückgängig</button>
          {/if}
          {#if t.fields.ausfuehrung === 'termin'}
            <a class="btn btn-secondary on-surface" href="/termine/neu?wartung={encodeURIComponent(t.id)}">
              <CalendarPlus size={18} strokeWidth={1.75} aria-hidden="true" />
              Termin eintragen
            </a>
          {/if}
        </div>
        {#if t.fields.ausfuehrung === 'termin'}<p class="text-muted text-small">Kommt ein Dienstleister, trag seinen Termin ein und hak die Aufgabe nach dem Besuch ab.</p>{/if}
        {#if error}<p class="save-error" role="alert">{error}</p>{/if}
      </section>
      {/if}

      <section class="card stack" aria-labelledby="verlauf">
        <h2 id="verlauf" class="section-title">Verlauf</h2>
        {#if t.history.length === 0}
          <p class="text-muted">Noch nicht erledigt.</p>
        {:else}
          <table class="history-table">
            <thead><tr><th scope="col">Datum</th><th scope="col">Wer</th><th scope="col">Notiz</th></tr></thead>
            <tbody>
              {#each t.history as row, i (row.date + row.by + i)}
                <tr><th scope="row" class="num">{dateText(row.date)}</th><td>{row.by}</td><td>{row.note}</td></tr>
              {/each}
            </tbody>
          </table>
        {/if}
      </section>
    </div>

    <div class="stack">
      <section class="card stack" aria-labelledby="aufgabe">
        <h2 id="aufgabe" class="section-title">Aufgabe</h2>
        <dl class="fact-list">
          <div><dt>Intervall</dt><dd>{intervalText(t.fields.intervall_monate, t.fields.intervall_wochen) || '–'}</dd></div>
          {#if t.fields.saison}<div><dt>Saison</dt><dd>{seasonText(t.fields.saison)}</dd></div>{/if}
          <div><dt>Wie</dt><dd>{EXECUTIONS[t.fields.ausfuehrung ?? 'selbst']}</dd></div>
          <div><dt>Zuständig</dt><dd>{RESPONSIBLE[t.fields.zustaendig ?? 'mieter'] ?? t.fields.zustaendig}</dd></div>
          {#if t.fields.raum}<div><dt>Raum</dt><dd>{roomName(t.fields.raum)}</dd></div>{/if}
          {#if t.fields.bezieht_sich_auf?.length}
            <div>
              <dt>Gerät</dt>
              <dd>
                {#each t.fields.bezieht_sich_auf as deviceId, i (deviceId)}
                  {#if i > 0}, {/if}<a href="/geraete/{encodeURIComponent(deviceId)}">{deviceName(deviceId)}</a>
                {/each}
              </dd>
            </div>
          {/if}
        </dl>
        {#if t.notes}<p class="notes-text">{t.notes}</p>{/if}
        {#if t.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {t.modifiedBy}</p>{/if}
      </section>

      <section class="card stack" aria-labelledby="fotos">
        <h2 id="fotos" class="section-title">Fotos</h2>
        <PhotoGallery type="wartung" {id} bind:photos={t.photos} label="Fotos zur Aufgabe" />
      </section>
    </div>
  </div>
{/if}

<style>
  .info {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    color: var(--ok);
  }

  .info p {
    color: var(--ink);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }
</style>
