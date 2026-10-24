<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { DEPOSIT_TYPES, RENT_TYPES } from '../lib/contracts'
  import { EditForm, numberField, numberText, textField } from '../lib/form.svelte'
  import { router } from '../lib/router.svelte'
  import type { Lease, ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** Creates the lease of the active object, or edits it if there is one. */
  const LABELS: Record<string, string> = {
    vermieter: 'Vermieter',
    beginn: 'Beginn',
    mietart: 'Mietart',
    kaltmiete_eur: 'Kaltmiete',
    nebenkosten_eur: 'Nebenkosten',
    kuendigungsfrist_monate: 'Kündigungsfrist',
    kaution: 'Kaution',
    paperless: 'Paperless',
  }

  const blank = {
    vermieter: '',
    beginn: '',
    mietart: 'standard',
    kaltmiete: '',
    nebenkosten: '',
    frist: '3',
    kaution: '',
    kautionArt: '',
    hinterlegt: '',
    paperless: '',
  }
  type Form = typeof blank

  let form = $state<Form>({ ...blank })
  let exists = $state(false)
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      const lease = (await api.get<{ lease: Lease | null }>('/lease')).lease
      exists = lease !== null
      if (lease) {
        const f = lease.fields
        const text = (v: unknown) => (v === undefined || v === null ? '' : String(v))
        form = {
          vermieter: text(f.vermieter),
          beginn: text(f.beginn),
          mietart: f.mietart ?? 'standard',
          kaltmiete: numberText(f.kaltmiete_eur),
          nebenkosten: numberText(f.nebenkosten_eur),
          frist: text(f.kuendigungsfrist_monate),
          kaution: numberText(f.kaution?.betrag_eur),
          kautionArt: f.kaution?.art ?? '',
          hinterlegt: text(f.kaution?.hinterlegt),
          paperless: text(f.paperless),
        }
        edit.loaded(payload(form), lease.etag)
      } else {
        // Move-in date of the home as a start
        const object = (await api.get<{ object: ObjectData | null }>('/objects/active')).object
        form.beginn = object?.einzug ?? ''
      }
      loadError = null
    } catch (e) {
      loadError = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      loading = false
    }
  }
  void load()

  function payload(f: Form): Record<string, unknown> {
    const deposit = Object.fromEntries(
      Object.entries({ betrag_eur: numberField(f.kaution), art: f.kautionArt || null, hinterlegt: textField(f.hinterlegt) }).filter(([, v]) => v !== null),
    )
    return {
      vermieter: textField(f.vermieter),
      beginn: textField(f.beginn),
      mietart: f.mietart,
      kaltmiete_eur: numberField(f.kaltmiete),
      nebenkosten_eur: numberField(f.nebenkosten),
      kuendigungsfrist_monate: numberField(f.frist),
      kaution: Object.keys(deposit).length ? deposit : null,
      paperless: textField(f.paperless),
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (exists) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put('/lease', { etag: edit.etag, changes, original })
      } else {
        await api.post('/lease', payload(form))
      }
      router.go('/mietvertrag', true)
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
</script>

<PageHeader back="/mietvertrag" title={exists ? 'Mietvertrag bearbeiten' : 'Mietvertrag eintragen'} />

{#if loading}
  <p class="muted" aria-live="polite">Lädt …</p>
{:else if loadError}
  <p class="save-error" role="alert">{loadError.text}</p>
{:else}
  <form class="form" onsubmit={submit} novalidate>
    {#if edit.conflict}
      <ConflictPanel fields={edit.conflict} labels={LABELS} onKeepMine={() => submit(undefined, edit.baseAfterConflict())} onTakeTheirs={() => { edit.conflict = null; void load() }} />
    {/if}

    <div class="card fields">
      <div class="field">
        <label for="vermieter">Vermieter</label>
        <input id="vermieter" class="input" bind:value={form.vermieter} autocomplete="off" />
      </div>
      <div class="pair">
        <div class="field">
          <label for="beginn">Beginn</label>
          <input id="beginn" class="input num" type="date" bind:value={form.beginn} aria-invalid={error('beginn') !== undefined} />
        </div>
        <div class="field">
          <label for="mietart">Mietart</label>
          <select id="mietart" class="input" bind:value={form.mietart}>
            {#each Object.entries(RENT_TYPES) as [value, label] (value)}
              <option {value}>{label}</option>
            {/each}
          </select>
        </div>
      </div>
      <span class="hint">Bei Index- und Staffelmiete gibt es keine Zustimmungsfrist, die Erhöhung gilt dann ohne dein Ja.</span>
    </div>

    <fieldset class="card fields">
      <legend>Miete {exists ? 'bei Einzug' : ''}</legend>
      <div class="pair">
        <div class="field">
          <label for="kalt">Kaltmiete €</label>
          <input id="kalt" class="input num" inputmode="decimal" bind:value={form.kaltmiete} aria-invalid={error('kaltmiete_eur') !== undefined} />
        </div>
        <div class="field">
          <label for="nk">Nebenkosten €</label>
          <input id="nk" class="input num" inputmode="decimal" bind:value={form.nebenkosten} aria-invalid={error('nebenkosten_eur') !== undefined} />
        </div>
      </div>
      {#if error('kaltmiete_eur') || error('nebenkosten_eur')}<span class="error">{error('kaltmiete_eur') ?? error('nebenkosten_eur')}</span>{/if}
      {#if exists}<span class="hint">Spätere Änderungen trägst du auf der Mietvertrags-Seite als Mieterhöhung ein.</span>{/if}
      <div class="field">
        <label for="frist">Kündigungsfrist Monate</label>
        <input id="frist" class="input num" type="number" min="1" max="24" inputmode="numeric" bind:value={form.frist} aria-invalid={error('kuendigungsfrist_monate') !== undefined} />
        <span class="hint">Für dich als Mieter meist 3 Monate.</span>
        {#if error('kuendigungsfrist_monate')}<span class="error">{error('kuendigungsfrist_monate')}</span>{/if}
      </div>
    </fieldset>

    <fieldset class="card fields">
      <legend>Kaution <span class="optional">(optional)</span></legend>
      <div class="pair">
        <div class="field">
          <label for="kaution">Betrag €</label>
          <input id="kaution" class="input num" inputmode="decimal" bind:value={form.kaution} aria-invalid={error('kaution.betrag_eur') !== undefined} />
        </div>
        <div class="field">
          <label for="art">Art</label>
          <select id="art" class="input" bind:value={form.kautionArt}>
            <option value="">–</option>
            {#each Object.entries(DEPOSIT_TYPES) as [value, label] (value)}
              <option {value}>{label}</option>
            {/each}
          </select>
        </div>
      </div>
      <div class="field">
        <label for="hinterlegt">Hinterlegt bei</label>
        <input id="hinterlegt" class="input" bind:value={form.hinterlegt} placeholder="z. B. Bank, Mietkautionskonto" />
        <span class="hint">Keine Kontonummer oder PIN – die bleiben in 1Password.</span>
      </div>
    </fieldset>

    <div class="card fields">
      <div class="field">
        <label for="paperless">Paperless-Dokument <span class="optional">(optional)</span></label>
        <input id="paperless" class="input num" inputmode="numeric" bind:value={form.paperless} />
      </div>
    </div>

    {#if edit.error}
      <p class="save-error" role="alert">{edit.error.text}</p>
    {/if}

    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : exists ? 'Änderungen speichern' : 'Mietvertrag eintragen'}
    </button>
  </form>
{/if}

<style>
  .muted {
    color: var(--ink-muted);
  }
</style>
