<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, textField } from '../lib/form.svelte'
  import { CATEGORIES, DEFAULT_STEPS, SCENARIOS } from '../lib/knowledge'
  import { roomName } from '../lib/meters'
  import { router } from '../lib/router.svelte'
  import { takeDraft } from '../lib/inbox.svelte'
  import type { DeviceSummary, KnowledgeDetail, ObjectData } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /**
   * New page (no id) or its text and data. With ?notfall=wasser it starts as
   * the steps for that emergency, prefilled with the usual ones.
   */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = { titel: 'Titel', kategorie: 'Art', raum: 'Raum', notfall: 'Notfall', szenario: 'Notfall', bezieht_sich_auf: 'Gerät', text: 'Text' }
  const blank = { titel: '', kategorie: 'ort', raum: '', notfall: [] as string[], szenario: 'wasser', geraete: [] as string[], text: '' }
  type Form = typeof blank

  let form = $state<Form>({ ...blank, notfall: [], geraete: [] })
  let rooms = $state<string[]>([])
  let devices = $state<DeviceSummary[]>([])
  let originalText = ''
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      const [object, deviceList] = await Promise.all([api.get<{ object: ObjectData | null }>('/objects/active'), api.get<{ devices: DeviceSummary[] }>('/devices')])
      rooms = object.object?.raeume ?? []
      devices = deviceList.devices
      if (id) {
        const p = await api.get<KnowledgeDetail>(`/knowledge/${encodeURIComponent(id)}`)
        form = {
          titel: p.fields.titel,
          kategorie: p.category,
          raum: p.fields.raum ?? '',
          notfall: p.fields.notfall ?? [],
          szenario: p.fields.szenario ?? 'wasser',
          geraete: p.fields.bezieht_sich_auf ?? [],
          text: p.text,
        }
        originalText = p.text
        edit.loaded(payload(form), p.etag)
      } else {
        const draft = takeDraft()
        if (draft) {
          form.titel = draft.titel
          form.text = draft.text
          form.kategorie = 'sonstiges'
        }
        const scenario = router.query.get('notfall')
        if (scenario && SCENARIOS[scenario]) {
          form.kategorie = 'notfall'
          form.szenario = scenario
          form.titel = SCENARIOS[scenario]
          form.text = DEFAULT_STEPS[scenario] ?? ''
        }
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
    const emergency = f.kategorie === 'notfall'
    return {
      titel: textField(f.titel),
      kategorie: f.kategorie,
      raum: f.raum || null,
      notfall: !emergency && f.notfall.length ? f.notfall : null,
      szenario: emergency ? f.szenario : null,
      bezieht_sich_auf: f.geraete.length ? f.geraete : null,
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        const textChanged = form.text.trim() !== originalText.trim()
        if (Object.keys(changes).length > 0 || textChanged) {
          await api.put(`/knowledge/${encodeURIComponent(id)}`, {
            etag: edit.etag,
            changes: textChanged ? { ...changes, text: form.text } : changes,
            original: textChanged ? { ...original, text: originalText } : original,
          })
        }
        router.go(`/wissen/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<KnowledgeDetail>('/knowledge', { ...payload(form), text: form.text })
        router.go(`/wissen/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  function keepMine() {
    // The text the other side saved becomes the base, so the next save goes through.
    if (edit.conflict?.text) originalText = String(edit.conflict.text.theirs)
    submit(undefined, edit.baseAfterConflict())
  }

  const error = (key: string) => edit.fieldErrors[key]
</script>

<PageHeader back={id ? `/wissen/${encodeURIComponent(id)}` : '/wissen'} title={id ? 'Seite bearbeiten' : 'Seite anlegen'} />

{#if loading}
  <p class="text-muted" aria-live="polite">Lädt …</p>
{:else if loadError}
  <p class="save-error" role="alert">{loadError.text}</p>
{:else}
  <form class="form" onsubmit={submit} novalidate>
    {#if edit.conflict}
      <ConflictPanel fields={edit.conflict} labels={LABELS} onKeepMine={keepMine} onTakeTheirs={() => { edit.conflict = null; void load() }} />
    {/if}

    <div class="card fields">
      <div class="field">
        <label for="titel">Titel</label>
        <input id="titel" class="input" bind:value={form.titel} placeholder="z. B. Haupthahn, Sicherungskasten, Wandfarbe Flur" aria-invalid={error('titel') !== undefined} />
        {#if error('titel')}<span class="error">{error('titel')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="kategorie">Art</label>
          <select id="kategorie" class="input" bind:value={form.kategorie}>
            {#each Object.entries(CATEGORIES) as [value, label] (value)}<option {value}>{label}</option>{/each}
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
      {#if form.kategorie === 'notfall'}
        <div class="field">
          <label for="szenario">Für welchen Notfall?</label>
          <select id="szenario" class="input" bind:value={form.szenario}>
            {#each Object.entries(SCENARIOS) as [value, label] (value)}<option {value}>{label}</option>{/each}
          </select>
          <span class="hint">Die Schritte stehen dann oben auf dem Notfallblatt.</span>
        </div>
      {:else}
        <fieldset class="fields">
          <legend class="label">Im Notfall wichtig bei <span class="optional">(optional)</span></legend>
          <div class="choice-chips">
            {#each Object.entries(SCENARIOS) as [value, label] (value)}
              <label><input type="checkbox" {value} bind:group={form.notfall} /><span>{label}</span></label>
            {/each}
          </div>
          <span class="hint">Dann steht der Ort auf dem Notfallblatt, etwa der Haupthahn beim Wasserrohrbruch.</span>
        </fieldset>
      {/if}
    </div>

    <div class="card fields">
      <div class="field">
        <label for="text">Text</label>
        <textarea id="text" class="input text" bind:value={form.text} rows="12"></textarea>
        <span class="hint">Schreib einfach los. Listen mit „1.“ oder „-“, eine Tabelle mit | Spalte | Spalte | – wie in der Notes-App.</span>
      </div>
    </div>

    {#if devices.length > 0}
      <fieldset class="card fields">
        <legend>Gehört zu Gerät <span class="optional">(optional)</span></legend>
        <div>
          {#each devices as device (device.id)}
            <label class="checkbox"><input type="checkbox" value={device.id} bind:group={form.geraete} />{device.name}</label>
          {/each}
        </div>
      </fieldset>
    {/if}

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Seite anlegen'}
    </button>
  </form>
{/if}

<style>
  .text {
    min-height: 260px;
    font-family: var(--font-num);
    font-size: 14px;
    line-height: 21px;
  }

  .label {
    font-size: 13px;
    font-weight: 600;
  }
</style>
