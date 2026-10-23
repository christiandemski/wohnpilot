<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { formatNumber, parseNumber } from '../lib/format'
  import { DEFAULT_DECIMALS, DEFAULT_UNITS, INTERVALS, MEDIA, roomName } from '../lib/meters'
  import { todayIso } from '../lib/resource.svelte'
  import { router } from '../lib/router.svelte'
  import type { MeterDetail, ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import MediumIcon from '../components/MediumIcon.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New meter (no id) or master data of an existing one. */
  let { id = null }: { id?: string | null } = $props()

  interface Form {
    medium: string
    bezeichnung: string
    zaehlernummer: string
    raum: string
    einheit: string
    stellen: string
    nachkommastellen: string
    ablese_intervall: string
    arbeitspreis_ct: string
    grundpreis_eur_monat: string
    abschlag_eur: string
    start: string
  }

  const LABELS: Record<string, string> = {
    medium: 'Art',
    bezeichnung: 'Bezeichnung',
    zaehlernummer: 'Zählernummer',
    raum: 'Raum',
    einheit: 'Einheit',
    stellen: 'Stellen vor dem Komma',
    nachkommastellen: 'Nachkommastellen',
    ablese_intervall: 'Ablesen',
    tarif: 'Tarif',
    'tarif.arbeitspreis_ct': 'Arbeitspreis',
    'tarif.grundpreis_eur_monat': 'Grundpreis',
    'tarif.abschlag_eur': 'Abschlag',
  }

  let form = $state<Form>({
    medium: 'strom',
    bezeichnung: '',
    zaehlernummer: '',
    raum: '',
    einheit: 'kWh',
    stellen: '6',
    nachkommastellen: '1',
    ablese_intervall: 'monatlich',
    arbeitspreis_ct: '',
    grundpreis_eur_monat: '',
    abschlag_eur: '',
    start: '',
  })
  let original: Record<string, unknown> = {}
  let etag = ''
  let rooms = $state<string[]>([])
  let loading = $state(true)
  let saving = $state(false)
  let error = $state<ApiError | null>(null)
  let fieldErrors = $state<Record<string, string>>({})
  let conflict = $state<Record<string, { mine: unknown; theirs: unknown }> | null>(null)
  let unitTouched = false

  async function load() {
    loading = true
    try {
      const object = (await api.get<{ object: ObjectData | null }>('/objects/active')).object
      rooms = object?.raeume ?? []
      if (id) {
        const m = await api.get<MeterDetail>(`/meters/${encodeURIComponent(id)}`)
        etag = m.etag
        const decimal = (n: number | undefined) => (n === undefined ? '' : formatNumber(n, 2).replace(/,?0+$/, '').replace(/ /g, ''))
        form = {
          medium: m.medium,
          bezeichnung: m.name ?? '',
          zaehlernummer: m.number,
          raum: m.room,
          einheit: m.unit,
          stellen: String(m.digits),
          nachkommastellen: String(m.decimals),
          ablese_intervall: m.interval,
          arbeitspreis_ct: decimal(m.tariff?.arbeitspreis_ct),
          grundpreis_eur_monat: decimal(m.tariff?.grundpreis_eur_monat),
          abschlag_eur: decimal(m.tariff?.abschlag_eur),
          start: '',
        }
        unitTouched = true
        original = toPayload(form)
      } else if (rooms.length > 0) {
        form.raum = rooms[0]
      }
    } catch (e) {
      error = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      loading = false
    }
  }
  void load()

  function chooseMedium(medium: string) {
    form.medium = medium
    if (!unitTouched) {
      form.einheit = DEFAULT_UNITS[medium] ?? ''
      form.nachkommastellen = String(DEFAULT_DECIMALS[medium] ?? 0)
    }
  }

  function number(text: string): number | null | string {
    if (text.trim() === '') return null
    return parseNumber(text) ?? text
  }

  function toPayload(f: Form): Record<string, unknown> {
    const tarif = {
      arbeitspreis_ct: number(f.arbeitspreis_ct),
      grundpreis_eur_monat: number(f.grundpreis_eur_monat),
      abschlag_eur: number(f.abschlag_eur),
    }
    const cleanTarif = Object.fromEntries(Object.entries(tarif).filter(([, v]) => v !== null))
    return {
      medium: f.medium,
      bezeichnung: f.bezeichnung.trim() || null,
      zaehlernummer: f.zaehlernummer.trim() || null,
      raum: f.raum || null,
      einheit: f.einheit.trim(),
      stellen: Number(f.stellen),
      nachkommastellen: Number(f.nachkommastellen),
      ablese_intervall: f.ablese_intervall,
      tarif: Object.keys(cleanTarif).length ? cleanTarif : null,
    }
  }

  /** Only fields that differ from what was loaded, so others' edits stay. */
  function changes(): Record<string, unknown> {
    const now = toPayload(form)
    return Object.fromEntries(Object.entries(now).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(original[key])))
  }

  async function submit(event?: SubmitEvent, base: Record<string, unknown> = original) {
    event?.preventDefault()
    saving = true
    error = null
    fieldErrors = {}
    try {
      if (id) {
        const changed = changes()
        if (Object.keys(changed).length > 0) {
          await api.put<MeterDetail>(`/meters/${encodeURIComponent(id)}`, { etag, changes: changed, original: base })
        }
        router.go(`/zaehler/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<MeterDetail>('/meters', toPayload(form))
        const start = parseNumber(form.start)
        if (start !== null) {
          await api.post(`/meters/${encodeURIComponent(created.id)}/readings`, { date: todayIso(), value: start, occasion: 'regulär' })
        }
        router.go(`/zaehler/${encodeURIComponent(created.id)}`, true)
      }
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'internal')
      if (err.code === 'conflict' && err.details.fields) {
        conflict = err.details.fields as Record<string, { mine: unknown; theirs: unknown }>
      } else {
        error = err
        fieldErrors = err.fields
      }
    } finally {
      saving = false
    }
  }

  function keepMine() {
    // Base the next attempt on what is in Nextcloud now, so the merge goes through.
    const base = { ...original }
    for (const [field, values] of Object.entries(conflict ?? {})) base[field] = values.theirs
    conflict = null
    void submit(undefined, base)
  }

  function takeTheirs() {
    conflict = null
    void load()
  }

  const fieldError = (key: string) => fieldErrors[key] ?? (key === 'tarif' ? fieldErrors['tarif.arbeitspreis_ct'] ?? fieldErrors['tarif.grundpreis_eur_monat'] ?? fieldErrors['tarif.abschlag_eur'] : undefined)
</script>

<PageHeader back={id ? `/zaehler/${encodeURIComponent(id)}` : '/zaehler'} title={id ? 'Zähler bearbeiten' : 'Zähler anlegen'} />

{#if loading}
  <p class="muted" aria-live="polite">Lädt …</p>
{:else}
  <form class="form" onsubmit={submit} novalidate>
    {#if conflict}
      <ConflictPanel fields={conflict} labels={LABELS} onKeepMine={keepMine} onTakeTheirs={takeTheirs} />
    {/if}

    <fieldset class="card">
      <legend>Art</legend>
      <div class="media">
        {#each Object.entries(MEDIA) as [value, label] (value)}
          <label class="medium">
            <input type="radio" name="medium" {value} checked={form.medium === value} onchange={() => chooseMedium(value)} />
            <span><MediumIcon medium={value} size={18} />{label}</span>
          </label>
        {/each}
      </div>
    </fieldset>

    <div class="card fields">
      <div class="field">
        <label for="bezeichnung">Bezeichnung <span class="optional">(optional)</span></label>
        <input id="bezeichnung" class="input" bind:value={form.bezeichnung} placeholder="z. B. Hauptzähler, Garten" />
      </div>
      <div class="field">
        <label for="zaehlernummer">Zählernummer</label>
        <input id="zaehlernummer" class="input num" bind:value={form.zaehlernummer} autocomplete="off" />
        <span class="hint">Steht auf dem Zähler, oft unter einem Barcode.</span>
      </div>
      <div class="field">
        <label for="raum">Raum</label>
        <select id="raum" class="input" bind:value={form.raum} aria-invalid={fieldError('raum') !== undefined}>
          {#if rooms.length === 0}<option value="">– kein Raum –</option>{/if}
          {#each rooms as room (room)}
            <option value={room}>{roomName(room)}</option>
          {/each}
        </select>
        {#if fieldError('raum')}<span class="error">{fieldError('raum')}</span>{/if}
      </div>
      <div class="field">
        <label for="intervall">Ablesen</label>
        <select id="intervall" class="input" bind:value={form.ablese_intervall}>
          {#each Object.entries(INTERVALS) as [value, label] (value)}
            <option {value}>{label}</option>
          {/each}
        </select>
      </div>
    </div>

    <fieldset class="card fields">
      <legend>Anzeige am Zähler</legend>
      <div class="three">
        <div class="field">
          <label for="stellen">Stellen</label>
          <input id="stellen" class="input num" type="number" min="1" max="9" inputmode="numeric" bind:value={form.stellen} aria-invalid={fieldError('stellen') !== undefined} />
        </div>
        <div class="field">
          <label for="nachkomma">Nachkomma</label>
          <input id="nachkomma" class="input num" type="number" min="0" max="4" inputmode="numeric" bind:value={form.nachkommastellen} aria-invalid={fieldError('nachkommastellen') !== undefined} />
        </div>
        <div class="field">
          <label for="einheit">Einheit</label>
          <input id="einheit" class="input" bind:value={form.einheit} oninput={() => (unitTouched = true)} aria-invalid={fieldError('einheit') !== undefined} />
        </div>
      </div>
      <span class="hint">Zähl die schwarzen und roten Ziffern am Zähler: schwarz = Stellen, rot = Nachkomma.</span>
      {#if fieldError('stellen') || fieldError('nachkommastellen') || fieldError('einheit')}
        <span class="error">{fieldError('stellen') ?? fieldError('nachkommastellen') ?? fieldError('einheit')}</span>
      {/if}
    </fieldset>

    <fieldset class="card fields">
      <legend>Tarif <span class="optional">(optional, für die Kosten-Hochrechnung)</span></legend>
      <div class="three">
        <div class="field">
          <label for="arbeitspreis">Arbeitspreis ct/{form.einheit || 'Einheit'}</label>
          <input id="arbeitspreis" class="input num" inputmode="decimal" bind:value={form.arbeitspreis_ct} />
        </div>
        <div class="field">
          <label for="grundpreis">Grundpreis €/Monat</label>
          <input id="grundpreis" class="input num" inputmode="decimal" bind:value={form.grundpreis_eur_monat} />
        </div>
        <div class="field">
          <label for="abschlag">Abschlag €/Monat</label>
          <input id="abschlag" class="input num" inputmode="decimal" bind:value={form.abschlag_eur} />
        </div>
      </div>
      {#if fieldError('tarif')}<span class="error">{fieldError('tarif')}</span>{/if}
    </fieldset>

    {#if !id}
      <div class="card fields">
        <div class="field">
          <label for="start">Aktueller Stand <span class="optional">(optional)</span></label>
          <input id="start" class="input num" inputmode="decimal" bind:value={form.start} placeholder="z. B. 23702,0" />
          <span class="hint">Wird als erste Ablesung mit heutigem Datum gespeichert.</span>
        </div>
      </div>
    {/if}

    {#if error}
      <p class="save-error" role="alert">{error.text}</p>
    {/if}

    <button type="submit" class="btn btn-primary btn-large" disabled={saving}>
      {saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Zähler anlegen'}
    </button>
  </form>
{/if}

<style>
  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  fieldset {
    margin: 0;
    border: 0;
    min-width: 0;
  }

  legend {
    float: left;
    width: 100%;
    margin-bottom: var(--space-3);
    padding: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .fields {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  fieldset.fields legend {
    margin-bottom: 0;
  }

  .three {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--space-3);
    align-items: end;
  }

  @media (max-width: 420px) {
    fieldset:last-of-type .three {
      grid-template-columns: 1fr;
    }
  }

  .optional {
    font-weight: 400;
    color: var(--ink-muted);
  }

  .media {
    clear: both;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .medium {
    position: relative;
  }

  .medium input {
    position: absolute;
    inset: 0;
    opacity: 0;
  }

  .medium span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: var(--touch-min);
    padding: 0 14px;
    border-radius: var(--radius-full);
    box-shadow: inset 0 0 0 1px var(--line-strong);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
  }

  .medium input:checked + span {
    background: var(--inverse);
    color: var(--on-inverse);
    box-shadow: none;
    font-weight: 600;
  }

  .medium input:focus-visible + span {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
  }

  .save-error {
    color: var(--due);
    font-weight: 600;
  }

  .muted {
    color: var(--ink-muted);
  }
</style>
