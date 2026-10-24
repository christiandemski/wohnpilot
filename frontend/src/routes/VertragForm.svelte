<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { CATEGORIES, RHYTHMS } from '../lib/contracts'
  import { EditForm, numberField, numberText, textField } from '../lib/form.svelte'
  import { meterTitle } from '../lib/meters'
  import { router } from '../lib/router.svelte'
  import type { ContractDetail, MeterSummary } from '../lib/types'
  import CategoryIcon from '../components/CategoryIcon.svelte'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New contract (no id) or its master data. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = {
    kategorie: 'Art',
    sparte: 'Sparte',
    anbieter: 'Anbieter',
    bezeichnung: 'Eigener Name',
    vertragsnummer: 'Vertragsnummer',
    kosten_eur: 'Kosten',
    rhythmus: 'Zahlweise',
    beginn: 'Beginn',
    mindestlaufzeit_monate: 'Mindestlaufzeit',
    verlaengerung_monate: 'Verlängerung',
    kuendigungsfrist_wochen: 'Kündigungsfrist (Wochen)',
    kuendigungsfrist_monate: 'Kündigungsfrist (Monate)',
    gekuendigt_am: 'Gekündigt am',
    gekuendigt_zum: 'Gekündigt zum',
    vorlauf_tage: 'Erinnern vorher',
    paperless: 'Paperless',
    versicherungssumme_eur: 'Versicherungssumme',
    selbstbeteiligung_eur: 'Selbstbeteiligung',
    bezieht_sich_auf: 'Zähler',
  }

  const blank = {
    kategorie: 'energie',
    sparte: '',
    anbieter: '',
    bezeichnung: '',
    vertragsnummer: '',
    kosten_eur: '',
    rhythmus: 'monatlich',
    beginn: '',
    mindestlaufzeit_monate: '',
    verlaengerung_monate: '',
    frist: '',
    fristEinheit: 'wochen',
    gekuendigt_am: '',
    gekuendigt_zum: '',
    vorlauf_tage: '',
    paperless: '',
    versicherungssumme_eur: '',
    selbstbeteiligung_eur: '',
    zaehler: [] as string[],
  }
  type Form = typeof blank

  let form = $state<Form>({ ...blank })
  let meters = $state<MeterSummary[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      meters = (await api.get<{ meters: MeterSummary[] }>('/meters')).meters
      if (id) {
        const c = await api.get<ContractDetail>(`/contracts/${encodeURIComponent(id)}`)
        const f = c.fields
        const text = (v: unknown) => (v === undefined || v === null ? '' : String(v))
        form = {
          kategorie: f.kategorie,
          sparte: text(f.sparte),
          anbieter: text(f.anbieter),
          bezeichnung: text(f.bezeichnung),
          vertragsnummer: text(f.vertragsnummer),
          kosten_eur: numberText(f.kosten_eur),
          rhythmus: f.rhythmus ?? 'monatlich',
          beginn: text(f.beginn),
          mindestlaufzeit_monate: text(f.mindestlaufzeit_monate),
          verlaengerung_monate: text(f.verlaengerung_monate),
          frist: text(f.kuendigungsfrist_monate ?? f.kuendigungsfrist_wochen),
          fristEinheit: f.kuendigungsfrist_monate !== undefined ? 'monate' : 'wochen',
          gekuendigt_am: text(f.gekuendigt_am),
          gekuendigt_zum: text(f.gekuendigt_zum),
          vorlauf_tage: text(f.vorlauf_tage),
          paperless: text(f.paperless),
          versicherungssumme_eur: numberText(f.versicherungssumme_eur),
          selbstbeteiligung_eur: numberText(f.selbstbeteiligung_eur),
          zaehler: f.bezieht_sich_auf ?? [],
        }
        edit.loaded(payload(form), c.etag)
      }
      loadError = null
    } catch (e) {
      loadError = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      loading = false
    }
  }
  void load()

  const insurance = $derived(form.kategorie === 'versicherung')

  function payload(f: Form): Record<string, unknown> {
    const notice = numberField(f.frist)
    return {
      kategorie: f.kategorie,
      sparte: textField(f.sparte),
      anbieter: textField(f.anbieter),
      bezeichnung: textField(f.bezeichnung),
      vertragsnummer: textField(f.vertragsnummer),
      kosten_eur: numberField(f.kosten_eur),
      rhythmus: f.rhythmus,
      beginn: textField(f.beginn),
      mindestlaufzeit_monate: numberField(f.mindestlaufzeit_monate),
      verlaengerung_monate: numberField(f.verlaengerung_monate),
      // One notice period: the other one goes as null and is removed.
      kuendigungsfrist_wochen: f.fristEinheit === 'wochen' ? notice : null,
      kuendigungsfrist_monate: f.fristEinheit === 'monate' ? notice : null,
      gekuendigt_am: textField(f.gekuendigt_am),
      gekuendigt_zum: textField(f.gekuendigt_zum),
      vorlauf_tage: numberField(f.vorlauf_tage),
      paperless: textField(f.paperless),
      versicherungssumme_eur: numberField(f.versicherungssumme_eur),
      selbstbeteiligung_eur: numberField(f.selbstbeteiligung_eur),
      bezieht_sich_auf: f.zaehler.length ? f.zaehler : null,
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) {
          await api.put(`/contracts/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        }
        router.go(`/vertraege/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<ContractDetail>('/contracts', payload(form))
        router.go(`/vertraege/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
  const back = $derived(id ? `/vertraege/${encodeURIComponent(id)}` : '/vertraege')
</script>

<PageHeader {back} title={id ? 'Vertrag bearbeiten' : 'Vertrag anlegen'} />

{#if loading}
  <p class="muted" aria-live="polite">Lädt …</p>
{:else if loadError}
  <p class="save-error" role="alert">{loadError.text}</p>
{:else}
  <form class="form" onsubmit={submit} novalidate>
    {#if edit.conflict}
      <ConflictPanel fields={edit.conflict} labels={LABELS} onKeepMine={() => submit(undefined, edit.baseAfterConflict())} onTakeTheirs={() => { edit.conflict = null; void load() }} />
    {/if}

    <fieldset class="card">
      <legend>Art</legend>
      <div class="choices">
        {#each Object.entries(CATEGORIES) as [value, label] (value)}
          <label class="choice">
            <input type="radio" name="kategorie" {value} bind:group={form.kategorie} />
            <span><CategoryIcon category={value} size={18} />{label}</span>
          </label>
        {/each}
      </div>
    </fieldset>

    <div class="card fields">
      <div class="field">
        <label for="anbieter">Anbieter</label>
        <input id="anbieter" class="input" bind:value={form.anbieter} autocomplete="organization" aria-invalid={error('anbieter') !== undefined} placeholder="z. B. Stadtwerke" />
        {#if error('anbieter')}<span class="error">{error('anbieter')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="sparte">Sparte <span class="optional">(optional)</span></label>
          <input id="sparte" class="input" bind:value={form.sparte} placeholder={insurance ? 'z. B. Hausrat' : 'z. B. Strom'} />
        </div>
        <div class="field">
          <label for="bezeichnung">Eigener Name <span class="optional">(optional)</span></label>
          <input id="bezeichnung" class="input" bind:value={form.bezeichnung} />
        </div>
      </div>
      <div class="field">
        <label for="vertragsnummer">{insurance ? 'Versicherungsnummer' : 'Vertragsnummer'}</label>
        <input id="vertragsnummer" class="input num" bind:value={form.vertragsnummer} autocomplete="off" />
      </div>
      <div class="pair">
        <div class="field">
          <label for="kosten">{insurance ? 'Beitrag €' : 'Kosten €'}</label>
          <input id="kosten" class="input num" inputmode="decimal" bind:value={form.kosten_eur} aria-invalid={error('kosten_eur') !== undefined} />
        </div>
        <div class="field">
          <label for="rhythmus">Zahlweise</label>
          <select id="rhythmus" class="input" bind:value={form.rhythmus}>
            {#each Object.entries(RHYTHMS) as [value, label] (value)}
              <option {value}>{label}</option>
            {/each}
          </select>
        </div>
      </div>
      {#if error('kosten_eur')}<span class="error">{error('kosten_eur')}</span>{/if}
    </div>

    <fieldset class="card fields">
      <legend>Laufzeit und Kündigung</legend>
      <div class="field">
        <label for="beginn">Beginn</label>
        <input id="beginn" class="input num" type="date" bind:value={form.beginn} aria-invalid={error('beginn') !== undefined} />
        {#if error('beginn')}<span class="error">{error('beginn')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="mindestlaufzeit">Mindestlaufzeit Monate</label>
          <input id="mindestlaufzeit" class="input num" type="number" min="0" max="240" inputmode="numeric" bind:value={form.mindestlaufzeit_monate} aria-invalid={error('mindestlaufzeit_monate') !== undefined} />
        </div>
        <div class="field">
          <label for="verlaengerung">Verlängert sich um Monate</label>
          <input id="verlaengerung" class="input num" type="number" min="0" max="120" inputmode="numeric" bind:value={form.verlaengerung_monate} aria-invalid={error('verlaengerung_monate') !== undefined} />
        </div>
      </div>
      <span class="hint">Leer lassen, wenn der Vertrag keine feste Laufzeit hat. Seit 2022 verlängern sich neue Verträge meist nur noch um einen Monat.</span>
      <div class="pair">
        <div class="field">
          <label for="frist">Kündigungsfrist</label>
          <input id="frist" class="input num" type="number" min="0" inputmode="numeric" bind:value={form.frist} aria-invalid={error('kuendigungsfrist_wochen') !== undefined || error('kuendigungsfrist_monate') !== undefined} />
        </div>
        <div class="field">
          <label for="einheit">Einheit</label>
          <select id="einheit" class="input" bind:value={form.fristEinheit}>
            <option value="wochen">Wochen</option>
            <option value="monate">Monate</option>
          </select>
        </div>
      </div>
      {#if error('kuendigungsfrist_wochen') || error('kuendigungsfrist_monate')}
        <span class="error">{error('kuendigungsfrist_wochen') ?? error('kuendigungsfrist_monate')}</span>
      {/if}
      <div class="field">
        <label for="vorlauf">Erinnern Tage vorher <span class="optional">(optional)</span></label>
        <input id="vorlauf" class="input num" type="number" min="0" max="365" inputmode="numeric" bind:value={form.vorlauf_tage} placeholder="30" aria-invalid={error('vorlauf_tage') !== undefined} />
        <span class="hint">Ab dann steht der Kündigungstag oben in „Was steht an“.</span>
      </div>
    </fieldset>

    {#if insurance}
      <fieldset class="card fields">
        <legend>Versicherung</legend>
        <div class="pair">
          <div class="field">
            <label for="summe">Versicherungssumme €</label>
            <input id="summe" class="input num" inputmode="decimal" bind:value={form.versicherungssumme_eur} aria-invalid={error('versicherungssumme_eur') !== undefined} />
          </div>
          <div class="field">
            <label for="sb">Selbstbeteiligung €</label>
            <input id="sb" class="input num" inputmode="decimal" bind:value={form.selbstbeteiligung_eur} aria-invalid={error('selbstbeteiligung_eur') !== undefined} />
          </div>
        </div>
      </fieldset>
    {/if}

    {#if meters.length > 0 && (form.kategorie === 'energie' || form.zaehler.length > 0)}
      <fieldset class="card fields">
        <legend>Gehört zu Zähler <span class="optional">(optional)</span></legend>
        <div>
          {#each meters as meter (meter.id)}
            <label class="checkbox">
              <input type="checkbox" value={meter.id} bind:group={form.zaehler} />
              {meterTitle(meter)}
            </label>
          {/each}
        </div>
      </fieldset>
    {/if}

    {#if id}
      <fieldset class="card fields">
        <legend>Gekündigt <span class="optional">(wenn du gekündigt hast)</span></legend>
        <div class="pair">
          <div class="field">
            <label for="gekuendigt-am">Kündigung abgeschickt</label>
            <input id="gekuendigt-am" class="input num" type="date" bind:value={form.gekuendigt_am} />
          </div>
          <div class="field">
            <label for="gekuendigt-zum">Bestätigt zum</label>
            <input id="gekuendigt-zum" class="input num" type="date" bind:value={form.gekuendigt_zum} />
          </div>
        </div>
        <span class="hint">Ohne Bestätigung rechnet Wohnpilot das Vertragsende aus dem Tag der Kündigung.</span>
      </fieldset>
    {/if}

    <div class="card fields">
      <div class="field">
        <label for="paperless">Paperless-Dokument <span class="optional">(optional)</span></label>
        <input id="paperless" class="input num" inputmode="numeric" bind:value={form.paperless} placeholder="Nummer des Vertrags in Paperless" />
      </div>
    </div>

    {#if edit.error}
      <p class="save-error" role="alert">{edit.error.text}</p>
    {/if}

    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Vertrag anlegen'}
    </button>
  </form>
{/if}

<style>
  .choices {
    clear: both;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    padding-top: var(--space-3);
  }

  .choice {
    position: relative;
  }

  .choice input {
    position: absolute;
    inset: 0;
    opacity: 0;
  }

  .choice span {
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

  .choice input:checked + span {
    background: var(--inverse);
    color: var(--on-inverse);
    box-shadow: none;
    font-weight: 600;
  }

  .choice input:focus-visible + span {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
  }

  .muted {
    color: var(--ink-muted);
  }
</style>
