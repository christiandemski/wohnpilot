<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, numberField, textField } from '../lib/form.svelte'
  import { EXECUTIONS, MONTH_NAMES, RESPONSIBLE } from '../lib/house'
  import { roomName } from '../lib/meters'
  import { router } from '../lib/router.svelte'
  import type { DeviceSummary, MaintenanceDetail, ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import ContactPicker from '../components/ContactPicker.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New recurring task (no id) or its settings. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = {
    titel: 'Aufgabe',
    intervall_monate: 'Intervall (Monate)',
    intervall_wochen: 'Intervall (Wochen)',
    saison: 'Saison',
    ab: 'Erstmals fällig',
    zustaendig: 'Zuständig',
    ausfuehrung: 'Wie',
    raum: 'Raum',
    vorlauf_tage: 'Erinnern vorher',
    bezieht_sich_auf: 'Gerät',
  }

  const blank = {
    kontakt: '',
    titel: '',
    intervall: '12' as string | number,
    einheit: 'monate',
    saisonal: false,
    von: '10',
    bis: '2',
    ab: '',
    zustaendig: 'mieter',
    ausfuehrung: 'selbst',
    raum: '',
    vorlauf: '' as string | number,
    geraete: [] as string[],
  }
  type Form = typeof blank

  let form = $state<Form>({ ...blank, geraete: [] })
  let rooms = $state<string[]>([])
  let devices = $state<DeviceSummary[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      const [object, deviceList] = await Promise.all([
        api.get<{ object: ObjectData | null }>('/objects/active'),
        api.get<{ devices: DeviceSummary[] }>('/devices'),
      ])
      rooms = object.object?.raeume ?? []
      devices = deviceList.devices
      if (id) {
        const t = await api.get<MaintenanceDetail>(`/maintenance/${encodeURIComponent(id)}`)
        const f = t.fields
        form = {
          kontakt: (typeof f.kontakt === 'string' ? f.kontakt : ''),
          titel: f.titel,
          intervall: String(f.intervall_wochen ?? f.intervall_monate ?? ''),
          einheit: f.intervall_wochen ? 'wochen' : 'monate',
          saisonal: f.saison !== undefined,
          von: String(f.saison?.von ?? 10),
          bis: String(f.saison?.bis ?? 2),
          ab: f.ab ?? '',
          zustaendig: f.zustaendig ?? 'mieter',
          ausfuehrung: f.ausfuehrung ?? 'selbst',
          raum: f.raum ?? '',
          vorlauf: f.vorlauf_tage === undefined ? '' : String(f.vorlauf_tage),
          geraete: f.bezieht_sich_auf ?? [],
        }
        edit.loaded(payload(form), t.etag)
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
    const interval = numberField(f.intervall)
    return {
      titel: textField(f.titel),
      // One interval: the other goes as null and is removed.
      intervall_monate: f.einheit === 'monate' ? interval : null,
      intervall_wochen: f.einheit === 'wochen' ? interval : null,
      saison: f.saisonal ? { von: Number(f.von), bis: Number(f.bis) } : null,
      ab: textField(f.ab),
      zustaendig: f.zustaendig,
      kontakt: f.kontakt || null,
      // Yourself is the default and is not written.
      ausfuehrung: f.ausfuehrung === 'selbst' ? null : f.ausfuehrung,
      raum: f.raum || null,
      vorlauf_tage: numberField(f.vorlauf),
      bezieht_sich_auf: f.geraete.length ? f.geraete : null,
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put(`/maintenance/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        router.go(`/wartung/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<MaintenanceDetail>('/maintenance', payload(form))
        router.go(`/wartung/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
</script>

<PageHeader back={id ? `/wartung/${encodeURIComponent(id)}` : '/wartung'} title={id ? 'Aufgabe bearbeiten' : 'Aufgabe anlegen'} />

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
        <label for="titel">Was ist zu tun?</label>
        <input id="titel" class="input" bind:value={form.titel} placeholder="z. B. Dunstabzug: Filter wechseln" aria-invalid={error('titel') !== undefined} />
        {#if error('titel')}<span class="error">{error('titel')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="intervall">Alle</label>
          <input id="intervall" class="input num" type="number" min="1" inputmode="numeric" bind:value={form.intervall} aria-invalid={error('intervall_monate') !== undefined || error('intervall_wochen') !== undefined} />
        </div>
        <div class="field">
          <label for="einheit">Einheit</label>
          <select id="einheit" class="input" bind:value={form.einheit}>
            <option value="monate">Monate</option>
            <option value="wochen">Wochen</option>
          </select>
        </div>
      </div>
      {#if error('intervall_monate') || error('intervall_wochen')}<span class="error">{error('intervall_monate') ?? error('intervall_wochen')}</span>{/if}
      <div class="pair">
        <div class="field">
          <label for="zustaendig">Zuständig</label>
          <select id="zustaendig" class="input" bind:value={form.zustaendig}>
            {#each Object.entries(RESPONSIBLE) as [value, label] (value)}<option {value}>{label}</option>{/each}
          </select>
        </div>
        <div class="field">
          <label for="raum">Raum <span class="optional">(optional)</span></label>
          <select id="raum" class="input" bind:value={form.raum}>
            <option value="">–</option>
            {#each rooms as room (room)}<option value={room}>{roomName(room)}</option>{/each}
          </select>
        </div>
      </div>
    </div>

    <fieldset class="card fields">
      <legend>Wie wird es erledigt?</legend>
      <div class="choice-chips">
        {#each Object.entries(EXECUTIONS) as [value, label] (value)}
          <label><input type="radio" name="ausfuehrung" {value} bind:group={form.ausfuehrung} /><span>{label}</span></label>
        {/each}
      </div>
      <span class="hint">
        {form.ausfuehrung === 'funk'
          ? 'Zum Beispiel Rauchwarnmelder, die der Messdienst aus der Ferne prüft: Wohnpilot erinnert nicht daran.'
          : form.ausfuehrung === 'termin'
            ? 'Ein Dienstleister kommt vorbei. Du trägst seinen Termin ein und hakst danach ab.'
            : 'Du erledigst es selbst und hakst es ab.'}
      </span>
    </fieldset>

    <fieldset class="card fields">
      <legend>Saison</legend>
      <label class="checkbox">
        <input type="checkbox" bind:checked={form.saisonal} />
        Nur in bestimmten Monaten (z. B. Heckenschnitt Oktober bis Februar)
      </label>
      {#if form.saisonal}
        <div class="pair">
          <div class="field">
            <label for="von">Von</label>
            <select id="von" class="input" bind:value={form.von}>
              {#each MONTH_NAMES as name, i (name)}<option value={String(i + 1)}>{name}</option>{/each}
            </select>
          </div>
          <div class="field">
            <label for="bis">Bis</label>
            <select id="bis" class="input" bind:value={form.bis}>
              {#each MONTH_NAMES as name, i (name)}<option value={String(i + 1)}>{name}</option>{/each}
            </select>
          </div>
        </div>
        <span class="hint">Einmal pro Saison: fällig bis zum Ende des Zeitraums.</span>
        {#if error('saison')}<span class="error">{error('saison')}</span>{/if}
      {/if}
    </fieldset>

    <div class="card fields">
      <div class="pair">
        <div class="field">
          <label for="ab">Erstmals fällig <span class="optional">(optional)</span></label>
          <input id="ab" class="input num" type="date" bind:value={form.ab} aria-invalid={error('ab') !== undefined} />
        </div>
        <div class="field">
          <label for="vorlauf">Erinnern Tage vorher</label>
          <input id="vorlauf" class="input num" type="number" min="0" max="365" inputmode="numeric" bind:value={form.vorlauf} placeholder="14" />
        </div>
      </div>
      <span class="hint">Ohne Datum ist eine neue Aufgabe sofort fällig. Danach zählt das Intervall ab der letzten Erledigung.</span>
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
      <ContactPicker bind:value={form.kontakt} label="Wer erledigt es? (Dienstleister, Handwerker)" />
    </div>

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Aufgabe anlegen'}
    </button>
  </form>
{/if}
