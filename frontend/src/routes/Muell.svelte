<script lang="ts">
  import { FileUp, Plus, Trash2 } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { formatChipDay, formatDate } from '../lib/format'
  import { BIN_COLORS, calendarEndHint, wasteError } from '../lib/house'
  import { todayIso } from '../lib/resource.svelte'
  import type { Bin, Waste } from '../lib/types'
  import PageHeader from '../components/PageHeader.svelte'
  import Segmentschalter from '../components/Segmentschalter.svelte'
  import Tonne from '../components/Tonne.svelte'

  /**
   * Waste calendar: address of the municipal ICS or, if there is only a
   * download, the file itself; full service, bins with keyword.
   */
  let source = $state<'link' | 'datei'>('link')
  let url = $state('')
  let uploading = $state(false)
  let uploadError = $state('')
  let fullService = $state(false)
  let bins = $state<Bin[]>([])
  let waste = $state<Waste | null>(null)
  let loading = $state(true)
  let saving = $state(false)
  let error = $state('')
  let fieldErrors = $state<Record<string, string>>({})

  async function load() {
    loading = true
    try {
      waste = await api.get<Waste>('/waste')
      url = waste.url ?? ''
      source = waste.file ? 'datei' : 'link'
      fullService = waste.fullService
      bins = waste.bins.map((b) => ({ ...b }))
    } catch (e) {
      error = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
    } finally {
      loading = false
    }
  }
  void load()

  async function save(event: SubmitEvent) {
    event.preventDefault()
    saving = true
    error = ''
    fieldErrors = {}
    try {
      // With a file, the address stays empty and the file is kept
      waste = await api.put<Waste>('/waste', { kalender_url: source === 'link' ? url.trim() : '', vollservice: fullService, tonnen: bins })
      bins = waste.bins.map((b) => ({ ...b }))
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'internal')
      fieldErrors = err.fields
      error = err.text
    } finally {
      saving = false
    }
  }

  async function upload(event: Event & { currentTarget: HTMLInputElement }) {
    const file = event.currentTarget.files?.[0]
    event.currentTarget.value = ''
    if (!file) return
    uploading = true
    uploadError = ''
    try {
      const form = new FormData()
      form.append('kalender', file)
      waste = await api.upload<Waste>('/waste/calendar', form)
      url = ''
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'internal')
      const problem = (err.details.fields as Record<string, string> | undefined)?.kalender
      uploadError = problem ? wasteError(problem) : err.text
    } finally {
      uploading = false
    }
  }

  const fileName = (path: string) => path.split('/').pop() ?? path
  const endHint = $derived(waste ? calendarEndHint(waste, todayIso()) : '')

  const addBin = () => (bins = [...bins, { id: '', name: '', farbe: 'bin-glas', stichwort: '' }])
  const removeBin = (i: number) => (bins = bins.filter((_, j) => j !== i))
  const nextFor = (bin: Bin) => waste?.next[bin.id]
</script>

<PageHeader back="/termine" title="Abfuhrkalender" />

{#if loading}
  <p class="text-muted" aria-live="polite">Lädt …</p>
{:else}
  <form class="form" onsubmit={save} novalidate>
    <div class="card fields">
      <Segmentschalter
        idPrefix="quelle"
        label="Was bietet dein Entsorger an?"
        bind:value={source}
        options={[
          { value: 'link', label: 'Abo-Link' },
          { value: 'datei', label: 'ICS-Datei' },
        ]}
      />
      {#if source === 'link'}
        <div class="field">
          <label for="url">Adresse des Abfuhrkalenders (ICS)</label>
          <input id="url" class="input" type="url" inputmode="url" bind:value={url} placeholder="https://…/abfuhrkalender.ics" aria-invalid={fieldErrors.kalender_url !== undefined} />
          <span class="hint">Auf der Seite deines Entsorgers: Abfuhrkalender für deine Straße, dann „Kalender abonnieren“ oder „iCal“. Die Adresse kopieren, nicht die Datei. Bleibt immer aktuell.</span>
          {#if fieldErrors.kalender_url}<span class="error">{fieldErrors.kalender_url}</span>{/if}
          {#if waste?.file}<span class="hint">Speichern ersetzt die hochgeladene Datei durch den Link.</span>{/if}
        </div>
      {:else}
        <div class="field">
          {#if waste?.file}
            <p>
              <strong>{fileName(waste.file)}</strong> liegt in deiner Nextcloud{waste.calendarEnds ? `, mit Abholungen bis ${formatDate(waste.calendarEnds)}` : ''}.
            </p>
          {/if}
          <label class="btn btn-secondary on-surface upload" class:busy={uploading}>
            <FileUp size={18} strokeWidth={1.75} aria-hidden="true" />
            {uploading ? 'Lädt hoch …' : waste?.file ? 'Neue Datei hochladen' : 'ICS-Datei hochladen'}
            <input type="file" accept=".ics,text/calendar" disabled={uploading} onchange={upload} />
          </label>
          <span class="hint">Lad beim Entsorger den Kalender für deine Straße als ICS- oder iCal-Datei herunter und wähl ihn hier aus. Eine Datei gilt meist ein Jahr; 30 Tage vor dem letzten Termin weist dich Wohnpilot unter Termine darauf hin.</span>
          {#if uploadError}<span class="error" role="alert">{uploadError}</span>{/if}
        </div>
      {/if}
      {#if endHint}<p class="save-error" role="status">{endHint}</p>{/if}
      <label class="checkbox">
        <input type="checkbox" bind:checked={fullService} />
        Vollservice: Der Entsorger holt die Tonnen selbst raus
      </label>
      <span class="hint">{fullService ? 'Wohnpilot zeigt die Abholung nur als Info.' : 'Du stellst die Tonnen selbst raus. Am Vorabend steht die Tonne in der Morgen-Mitteilung.'}</span>
    </div>

    <fieldset class="card fields">
      <legend>Tonnen</legend>
      <span class="hint">Das Stichwort ordnet die Termine aus dem Kalender der Tonne zu, z. B. „Gelb“ für „Gelbe Tonne“.</span>
      <ul class="bins">
        {#each bins as bin, i (i)}
          <li>
            <span class="bin-tile" aria-hidden="true"><Tonne color={bin.farbe} /></span>
            <div class="bin-fields">
              <div class="pair">
                <div class="field">
                  <label for="name-{i}">Name</label>
                  <input id="name-{i}" class="input" bind:value={bin.name} aria-invalid={fieldErrors[`tonnen.${i}`] !== undefined} />
                </div>
                <div class="field">
                  <label for="farbe-{i}">Farbe</label>
                  <select id="farbe-{i}" class="input" bind:value={bin.farbe}>
                    {#each Object.entries(BIN_COLORS) as [value, label] (value)}<option {value}>{label}</option>{/each}
                  </select>
                </div>
              </div>
              <div class="field">
                <label for="stichwort-{i}">Stichwort im Kalender</label>
                <input id="stichwort-{i}" class="input" bind:value={bin.stichwort} placeholder={bin.name} />
              </div>
              <span class="hint">{nextFor(bin) ? `Nächste Abholung ${formatChipDay(nextFor(bin)!)}` : waste?.url || waste?.file ? 'Kein Termin gefunden' : ''}</span>
            </div>
            <button type="button" class="btn btn-icon" aria-label="{bin.name || 'Tonne'} entfernen" onclick={() => removeBin(i)}>
              <Trash2 size={18} strokeWidth={1.75} aria-hidden="true" />
            </button>
          </li>
        {/each}
      </ul>
      <button type="button" class="btn btn-secondary on-surface" onclick={addBin}>
        <Plus size={16} strokeWidth={2} aria-hidden="true" />
        Tonne hinzufügen
      </button>
    </fieldset>

    {#if waste?.error}<p class="save-error" role="status">{wasteError(waste.error)}</p>{/if}
    {#if error && !waste?.error}<p class="save-error" role="alert">{error}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={saving}>{saving ? 'Speichert und lädt …' : 'Speichern'}</button>
  </form>
{/if}

<style>
  .upload {
    position: relative;
    align-self: flex-start;
    cursor: pointer;
  }

  .upload input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
  }

  .upload:focus-within {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
  }

  .upload.busy {
    opacity: 0.6;
  }

  .bins {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .bins li {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    padding-bottom: var(--space-3);
    border-bottom: 1px solid var(--line);
  }

  .bin-fields {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
  }

  .bin-tile {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    margin-top: 22px;
    border-radius: var(--radius-sm);
    background: var(--bin-tint);
  }
</style>
