<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, numberField, numberText, textField } from '../lib/form.svelte'
  import { OWNERS } from '../lib/house'
  import { roomName } from '../lib/meters'
  import { router } from '../lib/router.svelte'
  import { settings } from '../lib/settings.svelte'
  import type { DeviceDetail, ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New device (no id) or its data. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = {
    name: 'Gerät',
    hersteller: 'Hersteller',
    modell: 'Modell',
    seriennummer: 'Seriennummer',
    raum: 'Raum',
    eigentum: 'Gehört',
    kaufdatum: 'Gekauft',
    haendler: 'Händler',
    preis_eur: 'Preis',
    garantie_monate: 'Garantie (Monate)',
    garantie_bis: 'Garantie bis',
    baujahr: 'Baujahr',
    paperless: 'Paperless',
  }

  const blank = {
    name: '',
    hersteller: '',
    modell: '',
    seriennummer: '',
    raum: '',
    eigentum: 'mieter',
    kaufdatum: '',
    haendler: '',
    preis: '',
    garantie: '' as string | number,
    garantieBis: '',
    baujahr: '' as string | number,
    paperless: '',
  }
  type Form = typeof blank

  let form = $state<Form>({ ...blank })
  let rooms = $state<string[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()
  void settings.load()

  async function load() {
    loading = true
    try {
      rooms = (await api.get<{ object: ObjectData | null }>('/objects/active')).object?.raeume ?? []
      if (id) {
        const d = await api.get<DeviceDetail>(`/devices/${encodeURIComponent(id)}`)
        const f = d.fields
        const text = (v: unknown) => (v === undefined || v === null ? '' : String(v))
        form = {
          name: f.name,
          hersteller: text(f.hersteller),
          modell: text(f.modell),
          seriennummer: text(f.seriennummer),
          raum: text(f.raum),
          eigentum: f.eigentum ?? 'mieter',
          kaufdatum: text(f.kaufdatum),
          haendler: text(f.haendler),
          preis: numberText(f.preis_eur),
          garantie: text(f.garantie_monate),
          garantieBis: text(f.garantie_bis),
          baujahr: text(f.baujahr),
          paperless: text(f.paperless),
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
      name: textField(f.name),
      hersteller: textField(f.hersteller),
      modell: textField(f.modell),
      seriennummer: textField(f.seriennummer),
      raum: f.raum || null,
      eigentum: f.eigentum,
      kaufdatum: textField(f.kaufdatum),
      haendler: textField(f.haendler),
      preis_eur: numberField(f.preis),
      garantie_monate: numberField(f.garantie),
      garantie_bis: textField(f.garantieBis),
      baujahr: numberField(f.baujahr),
      paperless: textField(f.paperless),
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put(`/devices/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        router.go(`/geraete/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<DeviceDetail>('/devices', payload(form))
        router.go(`/geraete/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
  const own = $derived(form.eigentum === 'mieter')
</script>

<PageHeader back={id ? `/geraete/${encodeURIComponent(id)}` : '/geraete'} title={id ? 'Gerät bearbeiten' : 'Gerät anlegen'} />

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
        <label for="name">Gerät</label>
        <input id="name" class="input" bind:value={form.name} placeholder="z. B. Waschmaschine" aria-invalid={error('name') !== undefined} />
        {#if error('name')}<span class="error">{error('name')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="eigentum">Gehört</label>
          <select id="eigentum" class="input" bind:value={form.eigentum}>
            {#each Object.entries(OWNERS) as [value, label] (value)}<option {value}>{label}</option>{/each}
          </select>
        </div>
        <div class="field">
          <label for="raum">Raum</label>
          <select id="raum" class="input" bind:value={form.raum}>
            <option value="">–</option>
            {#each rooms as room (room)}<option value={room}>{roomName(room)}</option>{/each}
          </select>
        </div>
      </div>
      <div class="pair">
        <div class="field">
          <label for="hersteller">Hersteller</label>
          <input id="hersteller" class="input" bind:value={form.hersteller} />
        </div>
        <div class="field">
          <label for="modell">Modell</label>
          <input id="modell" class="input" bind:value={form.modell} />
        </div>
      </div>
      <div class="field">
        <label for="seriennummer">Seriennummer <span class="optional">(optional)</span></label>
        <input id="seriennummer" class="input num" bind:value={form.seriennummer} autocomplete="off" />
        <span class="hint">Steht auf dem Typenschild – ein Foto davon tut es auch.</span>
      </div>
    </div>

    {#if own}
      <fieldset class="card fields">
        <legend>Kauf und Garantie</legend>
        <div class="pair">
          <div class="field">
            <label for="kaufdatum">Gekauft am</label>
            <input id="kaufdatum" class="input num" type="date" bind:value={form.kaufdatum} aria-invalid={error('kaufdatum') !== undefined} />
          </div>
          <div class="field">
            <label for="preis">Preis €</label>
            <input id="preis" class="input num" inputmode="decimal" bind:value={form.preis} aria-invalid={error('preis_eur') !== undefined} />
          </div>
        </div>
        <div class="field">
          <label for="haendler">Händler <span class="optional">(optional)</span></label>
          <input id="haendler" class="input" bind:value={form.haendler} />
        </div>
        <div class="pair">
          <div class="field">
            <label for="garantie">Garantie Monate</label>
            <input id="garantie" class="input num" type="number" min="0" max="240" inputmode="numeric" bind:value={form.garantie} aria-invalid={error('garantie_monate') !== undefined} />
          </div>
          <div class="field">
            <label for="garantie-bis">oder Garantie bis</label>
            <input id="garantie-bis" class="input num" type="date" bind:value={form.garantieBis} />
          </div>
        </div>
        <span class="hint">Die gesetzliche Gewährleistung (zwei Jahre ab Kauf) rechnet Wohnpilot selbst aus.</span>
      </fieldset>
    {:else}
      <div class="card fields">
        <div class="field">
          <label for="baujahr">Baujahr <span class="optional">(optional)</span></label>
          <input id="baujahr" class="input num" type="number" min="1900" max="2100" inputmode="numeric" bind:value={form.baujahr} aria-invalid={error('baujahr') !== undefined} />
        </div>
      </div>
    {/if}

    {#if settings.paperless}
      <div class="card fields">
        <div class="field">
          <label for="paperless">Paperless-Dokument <span class="optional">(optional)</span></label>
          <input id="paperless" class="input num" inputmode="numeric" bind:value={form.paperless} placeholder="Rechnung oder Anleitung" />
        </div>
      </div>
    {/if}

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Gerät anlegen'}
    </button>
    {#if !id}<p class="text-muted text-small">Fotos fügst du auf der nächsten Seite hinzu.</p>{/if}
  </form>
{/if}
