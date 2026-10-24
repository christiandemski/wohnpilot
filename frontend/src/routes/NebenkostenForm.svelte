<script lang="ts">
  import { Plus, X } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { euro } from '../lib/contracts'
  import { EditForm, numberField, numberText, textField } from '../lib/form.svelte'
  import { parseNumber } from '../lib/format'
  import { todayIso } from '../lib/resource.svelte'
  import { router } from '../lib/router.svelte'
  import { CONSUMPTION, CONSUMPTION_FIELDS } from '../lib/utilities'
  import type { UtilityDetail, UtilityPosition, UtilitySummary } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import ContactPicker from '../components/ContactPicker.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New statement (no id) or its data and positions. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = {
    art: 'Art', von: 'Von', bis: 'Bis', zugang: 'Erhalten am', kontakt: 'Kontakt', abrechner: 'Abgerechnet von', abrechnung: 'Abgerechnet in',
    vorauszahlungen_eur: 'Vorauszahlungen', neue_vorauszahlung_eur: 'Neue Vorauszahlung', neue_vorauszahlung_ab: 'Ab', verbrauch: 'Verbrauch', positionen: 'Positionen',
  }

  type Row = { kostenart: string; betrag: string; steuer: '' | 'haushaltsnah' | 'handwerker'; steuerbetrag: string }
  const lastYear = String(new Date().getFullYear() - 1)
  const blank = {
    art: 'betriebskosten' as 'betriebskosten' | 'heizkosten',
    von: `${lastYear}-01-01`,
    bis: `${lastYear}-12-31`,
    zugang: todayIso(),
    kontakt: '',
    abrechner: '',
    abrechnung: '',
    vorauszahlungen: '',
    neue: '',
    neueAb: '',
    verbrauch: {} as Record<string, string>,
  }
  type Form = typeof blank

  let form = $state<Form>({ ...blank, verbrauch: {} })
  let rows = $state<Row[]>([])
  let originalPositions: UtilityPosition[] = []
  let statements = $state<UtilitySummary[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  const toRow = (p: UtilityPosition): Row => ({ kostenart: p.kind, betrag: numberText(p.amount), steuer: p.taxKind ?? '', steuerbetrag: numberText(p.taxAmount ?? undefined) })
  const emptyRow = (kostenart = ''): Row => ({ kostenart, betrag: '', steuer: '', steuerbetrag: '' })

  async function load() {
    loading = true
    try {
      statements = (await api.get<{ statements: UtilitySummary[] }>('/utilities')).statements
      if (id) {
        const s = await api.get<UtilityDetail>(`/utilities/${encodeURIComponent(id)}`)
        const f = s.fields
        form = {
          art: s.kind,
          von: f.von,
          bis: f.bis,
          zugang: f.zugang ?? '',
          kontakt: typeof f.kontakt === 'string' ? f.kontakt : '',
          abrechner: f.abrechner ?? '',
          abrechnung: f.abrechnung ?? '',
          vorauszahlungen: numberText(f.vorauszahlungen_eur),
          neue: numberText(f.neue_vorauszahlung_eur),
          neueAb: f.neue_vorauszahlung_ab ?? '',
          verbrauch: Object.fromEntries(Object.entries(f.verbrauch ?? {}).map(([k, v]) => [k, numberText(v)])),
        }
        rows = s.positions.map(toRow)
        originalPositions = s.positions
        edit.loaded(payload(form), s.etag)
      } else {
        // The landlord as contact, if there is one
        form.kontakt = 'vermieter'
        await prefill()
      }
      if (rows.length === 0) rows = [emptyRow()]
      loadError = null
    } catch (e) {
      loadError = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      loading = false
    }
  }
  void load()

  /** A new statement starts with the kinds of cost of the last one of its kind. */
  async function prefill() {
    const last = statements.find((s) => s.kind === form.art)
    if (!last) {
      rows = [emptyRow()]
      return
    }
    const detail = await api.get<UtilityDetail>(`/utilities/${encodeURIComponent(last.id)}`)
    rows = detail.positions.map((p) => ({ ...emptyRow(p.kind), steuer: p.taxKind ?? '' }))
    if (form.art === 'heizkosten') form.abrechner = detail.fields.abrechner ?? ''
  }

  async function changeKind(art: Form['art']) {
    form.art = art
    if (!id && rows.every((r) => r.betrag.trim() === '')) await prefill()
  }

  function payload(f: Form): Record<string, unknown> {
    const heating = f.art === 'heizkosten'
    const consumption = Object.fromEntries(
      Object.entries(f.verbrauch)
        .filter(([key, v]) => CONSUMPTION_FIELDS[f.art].includes(key) && v.trim() !== '')
        .map(([key, v]) => [key, numberField(v)]),
    )
    return {
      art: f.art,
      von: textField(f.von),
      bis: textField(f.bis),
      zugang: textField(f.zugang),
      kontakt: heating ? null : f.kontakt || null,
      abrechner: heating ? textField(f.abrechner) : null,
      abrechnung: heating ? f.abrechnung || null : null,
      vorauszahlungen_eur: heating ? null : numberField(f.vorauszahlungen),
      neue_vorauszahlung_eur: heating ? null : numberField(f.neue),
      neue_vorauszahlung_ab: heating ? null : textField(f.neueAb),
      verbrauch: Object.keys(consumption).length ? consumption : null,
    }
  }

  const positions = () =>
    rows
      .filter((r) => r.kostenart.trim() !== '' || r.betrag.trim() !== '')
      .map((r) => ({ kostenart: r.kostenart.trim(), betrag: numberField(r.betrag), steuer: r.steuer || null, steuerbetrag: r.steuer ? numberField(r.steuerbetrag) : null }))

  const total = $derived(rows.reduce((sum, r) => sum + (parseNumber(r.betrag) ?? 0), 0))

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put(`/utilities/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        await api.put(`/utilities/${encodeURIComponent(id)}/positions`, { positionen: positions(), original: originalPositions })
        router.go(`/nebenkosten/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<UtilityDetail>('/utilities', { ...payload(form), positionen: positions() })
        router.go(`/nebenkosten/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
  const rowError = (i: number) => ['kostenart', 'betrag', 'steuer', 'steuerbetrag'].map((k) => error(`positionen.${i}.${k}`)).find(Boolean)
  const landlordStatements = $derived(statements.filter((s) => s.kind === 'betriebskosten'))
</script>

<PageHeader back={id ? `/nebenkosten/${encodeURIComponent(id)}` : '/nebenkosten'} title={id ? 'Abrechnung bearbeiten' : 'Abrechnung erfassen'} />

{#if loading}
  <p class="text-muted" aria-live="polite">Lädt …</p>
{:else if loadError}
  <p class="save-error" role="alert">{loadError.text}</p>
{:else}
  <form class="form" onsubmit={submit} novalidate>
    {#if edit.conflict}
      <ConflictPanel fields={edit.conflict} labels={LABELS} onKeepMine={() => submit(undefined, edit.baseAfterConflict())} onTakeTheirs={() => { edit.conflict = null; void load() }} />
    {/if}

    <div class="card fields">
      <div class="field">
        <span id="art-label" class="label">Was für eine Abrechnung?</span>
        <div class="choice-chips" role="radiogroup" aria-labelledby="art-label">
          <label><input type="radio" name="art" value="betriebskosten" checked={form.art === 'betriebskosten'} onchange={() => changeKind('betriebskosten')} /><span>Betriebskosten vom Vermieter</span></label>
          <label><input type="radio" name="art" value="heizkosten" checked={form.art === 'heizkosten'} onchange={() => changeKind('heizkosten')} /><span>Heizkosten vom Messdienst</span></label>
        </div>
      </div>
      <div class="pair">
        <div class="field">
          <label for="von">Zeitraum von</label>
          <input id="von" class="input num" type="date" bind:value={form.von} aria-invalid={error('von') !== undefined} />
        </div>
        <div class="field">
          <label for="bis">bis</label>
          <input id="bis" class="input num" type="date" bind:value={form.bis} aria-invalid={error('bis') !== undefined} />
        </div>
      </div>
      {#if error('von') || error('bis')}<span class="error">{error('von') ?? error('bis')}</span>{/if}
      <div class="field">
        <label for="zugang">Erhalten am</label>
        <input id="zugang" class="input num" type="date" bind:value={form.zugang} max={todayIso()} aria-invalid={error('zugang') !== undefined} />
        <span class="hint">Ab diesem Tag laufen deine Fristen: 30 Tage zum Prüfen und Zahlen, zwölf Monate für Einwände.</span>
      </div>
    </div>

    {#if form.art === 'betriebskosten'}
      <div class="card fields">
        <div class="field">
          <label for="vorauszahlungen">Deine Vorauszahlungen im Zeitraum, zusammen €</label>
          <input id="vorauszahlungen" class="input num" inputmode="decimal" bind:value={form.vorauszahlungen} aria-invalid={error('vorauszahlungen_eur') !== undefined} placeholder="z. B. 3.000" />
          {#if error('vorauszahlungen_eur')}<span class="error">{error('vorauszahlungen_eur')}</span>{/if}
        </div>
        <div class="pair">
          <div class="field">
            <label for="neue">Neue Vorauszahlung € im Monat <span class="optional">(optional)</span></label>
            <input id="neue" class="input num" inputmode="decimal" bind:value={form.neue} aria-invalid={error('neue_vorauszahlung_eur') !== undefined} />
          </div>
          <div class="field">
            <label for="neue-ab">ab</label>
            <input id="neue-ab" class="input num" type="date" bind:value={form.neueAb} />
          </div>
        </div>
        <ContactPicker bind:value={form.kontakt} label="Vom Vermieter oder der Verwaltung" />
      </div>
    {:else}
      <div class="card fields">
        <div class="field">
          <label for="abrechner">Messdienst <span class="optional">(optional)</span></label>
          <input id="abrechner" class="input" bind:value={form.abrechner} placeholder="z. B. ista, Techem, Brunata" />
        </div>
        <div class="field">
          <label for="abrechnung">Abgerechnet vom Vermieter in</label>
          <select id="abrechnung" class="input" bind:value={form.abrechnung}>
            <option value="">– noch nicht oder gar nicht –</option>
            {#each landlordStatements as s (s.id)}<option value={s.id}>{s.title}</option>{/each}
          </select>
          <span class="hint">Steht der Betrag als Posten in der Betriebskostenabrechnung, zählt Wohnpilot ihn nur dort.</span>
        </div>
      </div>
    {/if}

    <fieldset class="card fields">
      <legend>Verbrauch laut Abrechnung <span class="optional">(optional)</span></legend>
      <div class="pair">
        {#each CONSUMPTION_FIELDS[form.art] as key (key)}
          <div class="field">
            <label for="v-{key}">{CONSUMPTION[key].label} {CONSUMPTION[key].unit}</label>
            <input id="v-{key}" class="input num" inputmode="decimal" value={form.verbrauch[key] ?? ''} oninput={(e) => (form.verbrauch[key] = (e.currentTarget as HTMLInputElement).value)} />
          </div>
        {/each}
      </div>
      <span class="hint">Wohnpilot vergleicht ihn mit deinen Ablesungen im selben Zeitraum.</span>
    </fieldset>

    <fieldset class="card fields">
      <legend>Positionen</legend>
      <datalist id="kostenarten">
        {#each ['Grundsteuer', 'Wasser und Abwasser', 'Niederschlagswasser', 'Müllabfuhr', 'Straßenreinigung', 'Gebäudeversicherung', 'Haftpflichtversicherung', 'Schornsteinfeger', 'Gartenpflege', 'Hausmeister', 'Allgemeinstrom', 'Heiz- und Warmwasserkosten', 'Brennstoff Gas', 'Wartung Heizung', 'Verbrauchserfassung'] as k (k)}<option value={k}></option>{/each}
      </datalist>
      <ol class="rows">
        {#each rows as row, i (i)}
          <li class="row">
            <div class="row-line">
              <label for="k-{i}" class="visually-hidden">Kostenart {i + 1}</label>
              <input id="k-{i}" class="input" list="kostenarten" bind:value={row.kostenart} placeholder="Kostenart" />
              <button type="button" class="btn btn-icon" aria-label="Position {i + 1} entfernen" onclick={() => (rows = rows.filter((_, j) => j !== i))}>
                <X size={18} strokeWidth={1.75} aria-hidden="true" />
              </button>
            </div>
            <div class="row-grid">
              <label for="b-{i}" class="visually-hidden">Betrag {i + 1} in €</label>
              <input id="b-{i}" class="input num" inputmode="decimal" bind:value={row.betrag} placeholder="Betrag €" aria-invalid={error(`positionen.${i}.betrag`) !== undefined} />
              <label for="s-{i}" class="visually-hidden">Steuer § 35a für Position {i + 1}</label>
              <select id="s-{i}" class="input" bind:value={row.steuer}>
                <option value="">Ohne § 35a</option>
                <option value="haushaltsnah">§ 35a haushaltsnah</option>
                <option value="handwerker">§ 35a Handwerker</option>
              </select>
            </div>
            {#if row.steuer}
              <div class="row-grid">
                <label for="sb-{i}" class="tax-label">davon Arbeitsanteil</label>
                <input id="sb-{i}" class="input num" inputmode="decimal" bind:value={row.steuerbetrag} placeholder="€" />
              </div>
            {/if}
            {#if rowError(i)}<span class="error">{rowError(i)}</span>{/if}
          </li>
        {/each}
      </ol>
      <button type="button" class="btn btn-secondary on-surface start" onclick={() => (rows = [...rows, emptyRow()])}>
        <Plus size={18} strokeWidth={2} aria-hidden="true" />
        Position hinzufügen
      </button>
      <p class="sum">Summe <span class="num">{euro(total)}</span></p>
      <span class="hint">Bei § 35a zählt nur der Arbeitsanteil; er steht meist extra in der Abrechnung.</span>
    </fieldset>

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Abrechnung speichern'}
    </button>
    {#if !id}<p class="text-muted text-small">Das PDF der Abrechnung legst du danach unter „Unterlagen“ oder über den Eingang ab.</p>{/if}
  </form>
{/if}

<style>
  .rows {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .row {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding-bottom: var(--space-3);
    border-bottom: 1px solid var(--line);
  }

  .row-line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .row-line .input {
    flex: 1;
    min-width: 0;
  }

  .row-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
    gap: var(--space-2);
    align-items: center;
    padding-right: calc(var(--touch-min) + var(--space-2));
  }

  .tax-label {
    font-size: 13px;
    color: var(--ink-muted);
  }

  .start {
    align-self: flex-start;
  }

  .sum {
    display: flex;
    justify-content: space-between;
    font-weight: 700;
  }
</style>
