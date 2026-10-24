<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, numberField, textField } from '../lib/form.svelte'
  import { mediumName, meterTitle } from '../lib/meters'
  import { router } from '../lib/router.svelte'
  import type { Deadline, MeterSummary } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /**
   * New deadline (no id) or its data. With ?zaehler=<id> it starts as the
   * supplier's request to report that meter's reading.
   */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = {
    titel: 'Titel',
    datum: 'Stichtag',
    ab: 'Ab',
    vorlauf_tage: 'Erinnern vorher',
    bezieht_sich_auf: 'Zähler',
    erledigt: 'Erledigt',
  }

  const blank = { titel: '', datum: '', ab: '', vorlauf_tage: '', zaehler: [] as string[], haushalt: false }
  type Form = typeof blank

  let form = $state<Form>({ ...blank, zaehler: [] })
  let meters = $state<MeterSummary[]>([])
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    loading = true
    try {
      meters = (await api.get<{ meters: MeterSummary[] }>('/meters')).meters
      if (id) {
        const d = await api.get<Deadline>(`/deadlines/${encodeURIComponent(id)}`)
        form = {
          titel: d.fields.titel,
          datum: d.fields.datum,
          ab: d.fields.ab ?? '',
          vorlauf_tage: d.fields.vorlauf_tage === undefined ? '' : String(d.fields.vorlauf_tage),
          zaehler: d.fields.bezieht_sich_auf ?? [],
          haushalt: d.object === '',
        }
        edit.loaded(payload(form), d.etag)
      } else {
        const meter = meters.find((m) => m.id === router.query.get('zaehler'))
        if (meter) {
          form.zaehler = [meter.id]
          form.titel = `Zählerstand ${mediumName(meter.medium)} melden`
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
    return {
      titel: textField(f.titel),
      datum: textField(f.datum),
      ab: textField(f.ab),
      vorlauf_tage: numberField(f.vorlauf_tage),
      bezieht_sich_auf: f.zaehler.length ? f.zaehler : null,
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) {
          await api.put(`/deadlines/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        }
        router.go(`/fristen/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<Deadline>('/deadlines', { ...payload(form), haushalt: form.haushalt })
        router.go(`/fristen/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
  const forMeters = $derived(form.zaehler.length > 0)
</script>

<PageHeader back={id ? `/fristen/${encodeURIComponent(id)}` : '/fristen'} title={id ? 'Frist bearbeiten' : 'Frist anlegen'} />

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
        <label for="titel">Was ist zu tun?</label>
        <input id="titel" class="input" bind:value={form.titel} aria-invalid={error('titel') !== undefined} placeholder="z. B. Zählerstand Strom an die Stadtwerke melden" />
        {#if error('titel')}<span class="error">{error('titel')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="ab">{forMeters ? 'Ablesen ab' : 'Frühestens ab'} <span class="optional">(optional)</span></label>
          <input id="ab" class="input num" type="date" bind:value={form.ab} aria-invalid={error('ab') !== undefined} />
        </div>
        <div class="field">
          <label for="datum">{forMeters ? 'Melden bis' : 'Erledigen bis'}</label>
          <input id="datum" class="input num" type="date" bind:value={form.datum} aria-invalid={error('datum') !== undefined} />
        </div>
      </div>
      {#if error('ab') || error('datum')}<span class="error">{error('ab') ?? error('datum')}</span>{/if}
      <span class="hint">
        {forMeters
          ? 'Beide Daten stehen im Brief des Versorgers. Ohne „ab“ zählen Ablesungen ab 30 Tage vor dem Stichtag.'
          : 'Der Stichtag ist der letzte Tag, an dem es erledigt sein muss.'}
      </span>
      <div class="field">
        <label for="vorlauf">Erinnern Tage vorher <span class="optional">(optional)</span></label>
        <input id="vorlauf" class="input num" type="number" min="0" max="365" inputmode="numeric" bind:value={form.vorlauf_tage} placeholder="14" aria-invalid={error('vorlauf_tage') !== undefined} />
      </div>
    </div>

    {#if meters.length > 0}
      <fieldset class="card fields">
        <legend>Zählerstand melden <span class="optional">(optional)</span></legend>
        <div>
          {#each meters as meter (meter.id)}
            <label class="checkbox">
              <input type="checkbox" value={meter.id} bind:group={form.zaehler} />
              {meterTitle(meter)}
            </label>
          {/each}
        </div>
        <span class="hint">Sind alle gewählten Zähler im Zeitraum abgelesen, hakt Wohnpilot die Frist von selbst ab.</span>
      </fieldset>
    {/if}

    {#if !id}
      <div class="card">
        <label class="checkbox">
          <input type="checkbox" bind:checked={form.haushalt} />
          Gehört zum Haushalt, nicht zu diesem Zuhause (zieht beim Umzug mit)
        </label>
      </div>
    {/if}

    {#if edit.error}
      <p class="save-error" role="alert">{edit.error.text}</p>
    {/if}

    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Frist anlegen'}
    </button>
  </form>
{/if}

<style>
  .muted {
    color: var(--ink-muted);
  }
</style>
