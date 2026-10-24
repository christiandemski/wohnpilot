<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, textField } from '../lib/form.svelte'
  import { router } from '../lib/router.svelte'
  import type { Appointment, DeviceSummary, MaintenanceDetail } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import ContactPicker from '../components/ContactPicker.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New appointment (no id) or its data. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = { titel: 'Was', datum: 'Tag', uhrzeit: 'Uhrzeit', bis: 'Bis', ort: 'Wo', bezieht_sich_auf: 'Gerät' }
  const blank = { kontakt: '', titel: '', datum: '', uhrzeit: '', bis: '', ort: '', geraete: [] as string[] }
  type Form = typeof blank

  let form = $state<Form>({ ...blank, geraete: [] })
  let devices = $state<DeviceSummary[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      devices = (await api.get<{ devices: DeviceSummary[] }>('/devices')).devices
      if (id) {
        const a = await api.get<Appointment>(`/appointments/${encodeURIComponent(id)}`)
        form = { kontakt: (typeof a.fields.kontakt === 'string' ? a.fields.kontakt : ''), titel: a.fields.titel, datum: a.fields.datum, uhrzeit: a.fields.uhrzeit ?? '', bis: a.fields.bis ?? '', ort: a.fields.ort ?? '', geraete: a.fields.bezieht_sich_auf ?? [] }
        edit.loaded(payload(form), a.etag)
      } else if (router.query.get('wartung')) {
        // From a task done by a service: its title and devices
        const task = await api.get<MaintenanceDetail>(`/maintenance/${encodeURIComponent(router.query.get('wartung')!)}`)
        form.titel = task.title
        form.geraete = task.fields.bezieht_sich_auf ?? []
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
      datum: textField(f.datum),
      uhrzeit: textField(f.uhrzeit),
      bis: textField(f.bis),
      ort: textField(f.ort),
      kontakt: f.kontakt || null,
      bezieht_sich_auf: f.geraete.length ? f.geraete : null,
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put(`/appointments/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        router.go(`/termine/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<Appointment>('/appointments', payload(form))
        router.go(`/termine/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
</script>

<PageHeader back={id ? `/termine/${encodeURIComponent(id)}` : '/termine'} title={id ? 'Termin bearbeiten' : 'Termin eintragen'} />

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
        <label for="titel">Was?</label>
        <input id="titel" class="input" bind:value={form.titel} placeholder="z. B. Schornsteinfeger, Fensterbauer, Besichtigung" aria-invalid={error('titel') !== undefined} />
        {#if error('titel')}<span class="error">{error('titel')}</span>{/if}
      </div>
      <div class="field">
        <label for="datum">Tag</label>
        <input id="datum" class="input num" type="date" bind:value={form.datum} aria-invalid={error('datum') !== undefined} />
        {#if error('datum')}<span class="error">{error('datum')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="uhrzeit">Von <span class="optional">(optional)</span></label>
          <input id="uhrzeit" class="input num" type="time" bind:value={form.uhrzeit} aria-invalid={error('uhrzeit') !== undefined} />
        </div>
        <div class="field">
          <label for="bis">Bis <span class="optional">(optional)</span></label>
          <input id="bis" class="input num" type="time" bind:value={form.bis} aria-invalid={error('bis') !== undefined} />
        </div>
      </div>
      {#if error('uhrzeit') || error('bis')}<span class="error">{error('uhrzeit') ?? error('bis')}</span>{/if}
      <div class="field">
        <label for="ort">Wo? <span class="optional">(optional)</span></label>
        <input id="ort" class="input" bind:value={form.ort} placeholder="z. B. Heizungskeller" />
      </div>
    </div>

    {#if devices.length > 0}
      <fieldset class="card fields">
        <legend>Gehört zu Gerät <span class="optional">(optional)</span></legend>
        <div>
          {#each devices as device (device.id)}
            <label class="checkbox">
              <input type="checkbox" value={device.id} bind:group={form.geraete} />
              {device.name}
            </label>
          {/each}
        </div>
      </fieldset>
    {/if}

    <div class="card fields">
      <ContactPicker bind:value={form.kontakt} label="Wer kommt?" />
    </div>

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Termin eintragen'}
    </button>
  </form>
{/if}
