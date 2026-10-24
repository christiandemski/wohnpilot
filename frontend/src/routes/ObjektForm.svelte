<script lang="ts">
  import { untrack } from 'svelte'
  import { ArrowDown, ArrowUp, Plus, X } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { parseNumber } from '../lib/format'
  import { DUTIES, HEATING } from '../lib/house'
  import { ROOMS, roomName } from '../lib/meters'
  import { router } from '../lib/router.svelte'
  import type { ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import Segmentschalter from '../components/Segmentschalter.svelte'

  /** Create the home (first setup) or edit the active one. */
  let { edit = false }: { edit?: boolean } = $props()

  const LABELS: Record<string, string> = {
    art: 'Art',
    adresse: 'Adresse',
    einzug: 'Einzug',
    wohnflaeche_m2: 'Wohnfläche',
    raeume: 'Räume',
    heizung: 'Heizung',
    mieterpflichten: 'Mieterpflichten',
    kleinreparatur_grenze_eur: 'Kleinreparatur je Fall',
    kleinreparatur_jahresgrenze_eur: 'Kleinreparatur im Jahr',
  }

  let art = $state('haus')
  let bezeichnung = $state('')
  let adresse = $state('')
  let einzug = $state('')
  let flaeche = $state('')
  let raeume = $state<string[]>(['keller', 'kueche', 'bad', 'wohnzimmer', 'schlafzimmer', 'flur'])
  let customRoom = $state('')
  let heizung = $state<string[]>([])
  let pflichten = $state<string[]>([])
  let grenzeFall = $state('')
  let grenzeJahr = $state('')
  // The page is created per route, so reading the initial value is intended.
  const editing = untrack(() => edit)
  let loading = $state(editing)
  let saving = $state(false)
  let error = $state<ApiError | null>(null)
  let fieldErrors = $state<Record<string, string>>({})
  let conflict = $state<Record<string, { mine: unknown; theirs: unknown }> | null>(null)
  let objectId = ''
  let etag = ''
  let original: Record<string, unknown> = {}

  async function load() {
    loading = true
    try {
      const o = (await api.get<{ object: ObjectData | null }>('/objects/active')).object
      if (!o) {
        router.go('/zuhause/neu', true)
        return
      }
      objectId = o.id
      etag = o.etag
      art = o.art
      adresse = o.adresse ?? ''
      einzug = o.einzug ?? ''
      flaeche = o.wohnflaeche_m2 ? String(o.wohnflaeche_m2).replace('.', ',') : ''
      raeume = [...o.raeume]
      heizung = Array.isArray(o.heizung) ? [...(o.heizung as string[])] : []
      pflichten = Array.isArray(o.mieterpflichten) ? [...(o.mieterpflichten as string[])] : []
      grenzeFall = typeof o.kleinreparatur_grenze_eur === 'number' ? String(o.kleinreparatur_grenze_eur).replace('.', ',') : ''
      grenzeJahr = typeof o.kleinreparatur_jahresgrenze_eur === 'number' ? String(o.kleinreparatur_jahresgrenze_eur).replace('.', ',') : ''
      original = payload()
    } catch (e) {
      error = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      loading = false
    }
  }
  if (editing) void load()

  function payload(): Record<string, unknown> {
    return {
      art,
      adresse: adresse.trim() || null,
      einzug: einzug || null,
      wohnflaeche_m2: flaeche.trim() === '' ? null : (parseNumber(flaeche) ?? flaeche),
      raeume,
      heizung: heizung.length ? heizung : null,
      mieterpflichten: pflichten.length ? pflichten : null,
      kleinreparatur_grenze_eur: grenzeFall.trim() === '' ? null : (parseNumber(grenzeFall) ?? grenzeFall),
      kleinreparatur_jahresgrenze_eur: grenzeJahr.trim() === '' ? null : (parseNumber(grenzeJahr) ?? grenzeJahr),
    }
  }

  function toggleRoom(room: string) {
    raeume = raeume.includes(room) ? raeume.filter((r) => r !== room) : [...raeume, room]
  }

  function addCustomRoom(event: SubmitEvent | MouseEvent) {
    event.preventDefault()
    const slug = customRoom
      .trim()
      .toLowerCase()
      .replace(/ä/g, 'ae')
      .replace(/ö/g, 'oe')
      .replace(/ü/g, 'ue')
      .replace(/ß/g, 'ss')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
    if (slug && !raeume.includes(slug)) raeume = [...raeume, slug]
    customRoom = ''
  }

  function move(room: string, step: -1 | 1) {
    const i = raeume.indexOf(room)
    const j = i + step
    if (j < 0 || j >= raeume.length) return
    const next = raeume.slice()
    ;[next[i], next[j]] = [next[j], next[i]]
    raeume = next
  }

  async function submit(event?: SubmitEvent, base: Record<string, unknown> = original) {
    event?.preventDefault()
    saving = true
    error = null
    fieldErrors = {}
    try {
      if (edit) {
        const now = payload()
        const changes = Object.fromEntries(Object.entries(now).filter(([k, v]) => JSON.stringify(v) !== JSON.stringify(original[k])))
        if (Object.keys(changes).length > 0) {
          await api.put(`/objects/${encodeURIComponent(objectId)}`, { etag, changes, original: base })
        }
        router.go('/zuhause', true)
      } else {
        await api.post('/objects', { ...payload(), bezeichnung: bezeichnung.trim() || null })
        router.go('/zaehler/neu', true)
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
    const base = { ...original }
    for (const [field, values] of Object.entries(conflict ?? {})) base[field] = values.theirs
    conflict = null
    void submit(undefined, base)
  }

  const suggestions = $derived(Object.keys(ROOMS).filter((r) => !raeume.includes(r)))
</script>

<PageHeader back={edit ? '/zuhause' : '/'} title={edit ? 'Zuhause bearbeiten' : 'Zuhause anlegen'} />

{#if loading}
  <p class="muted" aria-live="polite">Lädt …</p>
{:else}
  <form class="form" onsubmit={submit} novalidate>
    {#if conflict}
      <ConflictPanel
        fields={conflict}
        labels={LABELS}
        format={(v) => (Array.isArray(v) ? v.map((r) => roomName(String(r))).join(', ') : v === null || v === undefined || v === '' ? '–' : String(v))}
        onKeepMine={keepMine}
        onTakeTheirs={() => {
          conflict = null
          void load()
        }}
      />
    {/if}

    <div class="card fields">
      <div class="field">
        <span class="label" id="art-label">Art</span>
        <Segmentschalter idPrefix="art" label="Art" bind:value={art} options={[{ value: 'haus', label: 'Haus' }, { value: 'wohnung', label: 'Wohnung' }]} />
      </div>
      {#if !edit}
        <div class="field">
          <label for="bezeichnung">Kurzname <span class="optional">(optional)</span></label>
          <input id="bezeichnung" class="input" bind:value={bezeichnung} placeholder="z. B. EFH Musterstraße" />
          <span class="hint">Daraus entsteht der Ordnername in der Nextcloud.</span>
        </div>
      {/if}
      <div class="field">
        <label for="adresse">Adresse</label>
        <input id="adresse" class="input" bind:value={adresse} autocomplete="street-address" placeholder="Straße Nr., PLZ Ort" aria-invalid={fieldErrors.adresse !== undefined} />
        {#if fieldErrors.adresse}<span class="error">{fieldErrors.adresse}</span>{/if}
      </div>
      <div class="two">
        <div class="field">
          <label for="einzug">Einzug</label>
          <input id="einzug" class="input" type="date" bind:value={einzug} aria-invalid={fieldErrors.einzug !== undefined} />
          {#if fieldErrors.einzug}<span class="error">{fieldErrors.einzug}</span>{/if}
        </div>
        <div class="field">
          <label for="flaeche">Wohnfläche m²</label>
          <input id="flaeche" class="input num" inputmode="decimal" bind:value={flaeche} aria-invalid={fieldErrors.wohnflaeche_m2 !== undefined} />
          {#if fieldErrors.wohnflaeche_m2}<span class="error">{fieldErrors.wohnflaeche_m2}</span>{/if}
        </div>
      </div>
    </div>

    <fieldset class="card fields">
      <legend>Räume</legend>
      <p class="hint">Die Reihenfolge bestimmt die Ablese-Runde – oben fängst du an.</p>
      {#if raeume.length > 0}
        <ol class="chosen">
          {#each raeume as room, i (room)}
            <li>
              <span>{roomName(room)}</span>
              <span class="tools">
                <button type="button" class="btn btn-icon" aria-label="{roomName(room)} nach oben" disabled={i === 0} onclick={() => move(room, -1)}>
                  <ArrowUp size={16} strokeWidth={2} aria-hidden="true" />
                </button>
                <button type="button" class="btn btn-icon" aria-label="{roomName(room)} nach unten" disabled={i === raeume.length - 1} onclick={() => move(room, 1)}>
                  <ArrowDown size={16} strokeWidth={2} aria-hidden="true" />
                </button>
                <button type="button" class="btn btn-icon" aria-label="{roomName(room)} entfernen" onclick={() => toggleRoom(room)}>
                  <X size={16} strokeWidth={2} aria-hidden="true" />
                </button>
              </span>
            </li>
          {/each}
        </ol>
      {/if}
      {#if suggestions.length > 0}
        <div class="suggestions" aria-label="Vorschläge">
          {#each suggestions as room (room)}
            <button type="button" class="suggestion" onclick={() => toggleRoom(room)}>
              <Plus size={14} strokeWidth={2} aria-hidden="true" />
              {roomName(room)}
            </button>
          {/each}
        </div>
      {/if}
      <div class="field">
        <label for="custom-room">Eigener Raum</label>
        <div class="add">
          <input id="custom-room" class="input" bind:value={customRoom} placeholder="z. B. Heizungsraum" onkeydown={(e) => e.key === 'Enter' && addCustomRoom(e as unknown as MouseEvent)} />
          <button type="button" class="btn btn-inverse" onclick={addCustomRoom} disabled={customRoom.trim() === ''}>Hinzufügen</button>
        </div>
      </div>
    </fieldset>

    <fieldset class="card fields">
      <legend>Heizung</legend>
      <div class="choice-chips">
        {#each Object.entries(HEATING) as [value, label] (value)}
          <label><input type="checkbox" {value} bind:group={heizung} /><span>{label}</span></label>
        {/each}
      </div>
      <span class="hint">Mehrere möglich, etwa Gas und Kaminofen. Daraus schlägt Wohnpilot Wartungen vor.</span>
      {#if fieldErrors.heizung}<span class="error">{fieldErrors.heizung}</span>{/if}
    </fieldset>

    <fieldset class="card fields">
      <legend>Laut Mietvertrag deine Sache</legend>
      <div class="choice-chips">
        {#each Object.entries(DUTIES) as [value, label] (value)}
          <label><input type="checkbox" {value} bind:group={pflichten} /><span>{label}</span></label>
        {/each}
      </div>
      <div class="two">
        <div class="field">
          <label for="grenze-fall">Kleinreparatur je Fall €</label>
          <input id="grenze-fall" class="input num" inputmode="decimal" bind:value={grenzeFall} placeholder="z. B. 100" aria-invalid={fieldErrors.kleinreparatur_grenze_eur !== undefined} />
        </div>
        <div class="field">
          <label for="grenze-jahr">Kleinreparatur im Jahr €</label>
          <input id="grenze-jahr" class="input num" inputmode="decimal" bind:value={grenzeJahr} placeholder="z. B. 300" aria-invalid={fieldErrors.kleinreparatur_jahresgrenze_eur !== undefined} />
        </div>
      </div>
      <span class="hint">Steht im Mietvertrag unter „Kleinreparaturen“. Ohne Klausel trägt alles der Vermieter.</span>
    </fieldset>

    {#if error}
      <p class="save-error" role="alert">{error.text}</p>
    {/if}

    <button type="submit" class="btn btn-primary btn-large" disabled={saving}>
      {saving ? 'Speichert …' : edit ? 'Änderungen speichern' : 'Zuhause anlegen'}
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
    padding: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .fields {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .two {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .optional {
    font-weight: 400;
    color: var(--ink-muted);
  }

  .hint {
    font-size: 13px;
    color: var(--ink-muted);
  }

  .chosen {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .chosen li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
    padding: 2px 0;
    border-bottom: 1px solid var(--line);
    font-weight: 500;
  }

  .tools {
    display: flex;
  }

  .tools .btn-icon {
    background: transparent;
  }

  .suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .suggestion {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-height: var(--touch-min);
    padding: 0 12px;
    border: 0;
    border-radius: var(--radius-full);
    background: var(--paper);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
  }

  .add {
    display: flex;
    gap: var(--space-2);
  }

  .save-error {
    color: var(--due);
    font-weight: 600;
  }

  .muted {
    color: var(--ink-muted);
  }
</style>
