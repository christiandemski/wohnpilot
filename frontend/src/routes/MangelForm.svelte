<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, numberField, numberText, textField } from '../lib/form.svelte'
  import { RESPONSIBLE } from '../lib/house'
  import { roomName } from '../lib/meters'
  import { todayIso } from '../lib/resource.svelte'
  import { router } from '../lib/router.svelte'
  import type { DefectDetail, ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** Report a defect (no id; photos come on the next page) or change its data. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = { titel: 'Was', raum: 'Raum', zustaendig: 'Zuständig', kleinreparatur: 'Kleinreparatur', kosten_eur: 'Kosten', nachfassen: 'Nachfassen' }

  const blank = { titel: '', raum: '', zustaendig: 'vermieter', klein: false, kosten: '', nachfassen: '', entdeckt: todayIso(), notiz: '' }
  type Form = typeof blank

  let form = $state<Form>({ ...blank })
  let rooms = $state<string[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      rooms = (await api.get<{ object: ObjectData | null }>('/objects/active')).object?.raeume ?? []
      if (id) {
        const d = await api.get<DefectDetail>(`/defects/${encodeURIComponent(id)}`)
        form = {
          ...blank,
          titel: d.fields.titel,
          raum: d.fields.raum ?? '',
          zustaendig: d.fields.zustaendig ?? 'vermieter',
          klein: d.fields.kleinreparatur === true,
          kosten: numberText(d.fields.kosten_eur),
          nachfassen: d.fields.nachfassen ?? '',
        }
        edit.loaded(payload(form), d.etag)
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
    return {
      titel: textField(f.titel),
      raum: f.raum || null,
      zustaendig: f.zustaendig,
      kleinreparatur: f.klein ? true : null,
      kosten_eur: numberField(f.kosten),
      nachfassen: textField(f.nachfassen),
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put(`/defects/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        router.go(`/maengel/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<DefectDetail>('/defects', { ...payload(form), entdeckt: form.entdeckt, notiz: form.notiz.trim() })
        router.go(`/maengel/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
</script>

<PageHeader back={id ? `/maengel/${encodeURIComponent(id)}` : '/maengel'} title={id ? 'Mangel bearbeiten' : 'Mangel melden'} />

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
        <label for="titel">Was ist kaputt?</label>
        <input id="titel" class="input" bind:value={form.titel} placeholder="z. B. Fenster im Schlafzimmer zieht" aria-invalid={error('titel') !== undefined} />
        {#if error('titel')}<span class="error">{error('titel')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="raum">Raum</label>
          <select id="raum" class="input" bind:value={form.raum}>
            <option value="">–</option>
            {#each rooms as room (room)}<option value={room}>{roomName(room)}</option>{/each}
          </select>
        </div>
        <div class="field">
          <label for="zustaendig">Wer behebt es?</label>
          <select id="zustaendig" class="input" bind:value={form.zustaendig}>
            <option value="vermieter">{RESPONSIBLE.vermieter}</option>
            <option value="mieter">{RESPONSIBLE.mieter}</option>
          </select>
        </div>
      </div>
      {#if !id}
        <div class="field">
          <label for="entdeckt">Entdeckt am</label>
          <input id="entdeckt" class="input num" type="date" bind:value={form.entdeckt} max={todayIso()} />
        </div>
        <div class="field">
          <label for="notiz">Beschreibung <span class="optional">(optional)</span></label>
          <input id="notiz" class="input" bind:value={form.notiz} placeholder="z. B. Zugluft am Rahmen unten links" />
        </div>
      {/if}
    </div>

    <fieldset class="card fields">
      <legend>Kleinreparatur <span class="optional">(optional)</span></legend>
      <label class="checkbox">
        <input type="checkbox" bind:checked={form.klein} />
        Zahlst du laut Mietvertrag (Kleinreparatur)
      </label>
      {#if form.klein}
        <div class="field">
          <label for="kosten">Kosten €</label>
          <input id="kosten" class="input num" inputmode="decimal" bind:value={form.kosten} aria-invalid={error('kosten_eur') !== undefined} />
          {#if error('kosten_eur')}<span class="error">{error('kosten_eur')}</span>{/if}
        </div>
      {/if}
    </fieldset>

    {#if id}
      <div class="card fields">
        <div class="field">
          <label for="nachfassen">Nachfassen am <span class="optional">(optional)</span></label>
          <input id="nachfassen" class="input num" type="date" bind:value={form.nachfassen} />
        </div>
      </div>
    {/if}

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Mangel melden'}
    </button>
    {#if !id}<p class="text-muted text-small">Fotos fügst du auf der nächsten Seite hinzu.</p>{/if}
  </form>
{/if}
