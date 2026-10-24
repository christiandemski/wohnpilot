<script lang="ts">
  import { Plus, Trash2 } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { formatChipDay } from '../lib/format'
  import { BIN_COLORS, wasteError } from '../lib/house'
  import type { Bin, Waste } from '../lib/types'
  import PageHeader from '../components/PageHeader.svelte'
  import Tonne from '../components/Tonne.svelte'

  /** Waste calendar: address of the municipal ICS, full service, bins with keyword. */
  let url = $state('')
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
      waste = await api.put<Waste>('/waste', { kalender_url: url.trim(), vollservice: fullService, tonnen: bins })
      bins = waste.bins.map((b) => ({ ...b }))
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'internal')
      fieldErrors = err.fields
      error = err.text
    } finally {
      saving = false
    }
  }

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
      <div class="field">
        <label for="url">Adresse des Abfuhrkalenders (ICS)</label>
        <input id="url" class="input" type="url" inputmode="url" bind:value={url} placeholder="https://…/abfuhrkalender.ics" aria-invalid={fieldErrors.kalender_url !== undefined} />
        <span class="hint">Auf der Seite deines Entsorgers: Abfuhrkalender für deine Straße, dann „Kalender abonnieren“ oder „iCal“. Die Adresse kopieren, nicht die Datei.</span>
        {#if fieldErrors.kalender_url}<span class="error">{fieldErrors.kalender_url}</span>{/if}
      </div>
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
              <span class="hint">{nextFor(bin) ? `Nächste Abholung ${formatChipDay(nextFor(bin)!)}` : waste?.url ? 'Kein Termin gefunden' : ''}</span>
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
