<script lang="ts">
  import { api, ApiError } from '../lib/api'
  import { EditForm, textField } from '../lib/form.svelte'
  import { ROLES, SCENARIOS } from '../lib/knowledge'
  import { router } from '../lib/router.svelte'
  import type { ContactDetail } from '../lib/types'
  import ConflictPanel from '../components/ConflictPanel.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** New contact (no id) or its data. */
  let { id = null }: { id?: string | null } = $props()

  const LABELS: Record<string, string> = { name: 'Name', firma: 'Firma', rolle: 'Rolle', gewerk: 'Gewerk', telefon: 'Telefon', mobil: 'Mobil', email: 'E-Mail', adresse: 'Adresse', notfall: 'Im Notfall' }
  const blank = { name: '', firma: '', rolle: 'handwerker', gewerk: '', telefon: '', mobil: '', email: '', adresse: '', notfall: [] as string[], zuhause: false }
  type Form = typeof blank

  let form = $state<Form>({ ...blank, notfall: [] })
  let loading = $state(true)
  let loadError = $state<ApiError | null>(null)
  const edit = new EditForm()

  async function load() {
    if (!id) {
      loading = false
      return
    }
    loading = true
    try {
      const c = await api.get<ContactDetail>(`/contacts/${encodeURIComponent(id)}`)
      const f = c.fields
      const text = (v: unknown) => (v === undefined || v === null ? '' : String(v))
      form = {
        name: f.name,
        firma: text(f.firma),
        rolle: f.rolle ?? 'sonstiges',
        gewerk: text(f.gewerk),
        telefon: c.phone ?? '',
        mobil: c.mobile ?? '',
        email: text(f.email),
        adresse: text(f.adresse),
        notfall: f.notfall ?? [],
        zuhause: c.object !== '',
      }
      edit.loaded(payload(form), c.etag)
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
      firma: textField(f.firma),
      rolle: f.rolle,
      gewerk: textField(f.gewerk),
      telefon: textField(f.telefon),
      mobil: textField(f.mobil),
      email: textField(f.email),
      adresse: textField(f.adresse),
      notfall: f.notfall.length ? f.notfall : null,
    }
  }

  function submit(event?: SubmitEvent, base?: Record<string, unknown>) {
    event?.preventDefault()
    void edit.run(async (original) => {
      if (id) {
        const changes = edit.changes(payload(form))
        if (Object.keys(changes).length > 0) await api.put(`/contacts/${encodeURIComponent(id)}`, { etag: edit.etag, changes, original })
        router.go(`/kontakte/${encodeURIComponent(id)}`, true)
      } else {
        const created = await api.post<ContactDetail>('/contacts', { ...payload(form), zuhause: form.zuhause })
        router.go(`/kontakte/${encodeURIComponent(created.id)}`, true)
      }
    }, base)
  }

  const error = (key: string) => edit.fieldErrors[key]
</script>

<PageHeader back={id ? `/kontakte/${encodeURIComponent(id)}` : '/kontakte'} title={id ? 'Kontakt bearbeiten' : 'Kontakt anlegen'} />

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
        <label for="name">Name</label>
        <input id="name" class="input" bind:value={form.name} autocomplete="name" aria-invalid={error('name') !== undefined} />
        {#if error('name')}<span class="error">{error('name')}</span>{/if}
      </div>
      <div class="pair">
        <div class="field">
          <label for="rolle">Rolle</label>
          <select id="rolle" class="input" bind:value={form.rolle}>
            {#each Object.entries(ROLES) as [value, label] (value)}<option {value}>{label}</option>{/each}
          </select>
        </div>
        <div class="field">
          <label for="gewerk">Gewerk <span class="optional">(optional)</span></label>
          <input id="gewerk" class="input" bind:value={form.gewerk} placeholder="z. B. Heizung, Elektro" />
        </div>
      </div>
      <div class="field">
        <label for="firma">Firma <span class="optional">(optional)</span></label>
        <input id="firma" class="input" bind:value={form.firma} autocomplete="organization" />
      </div>
    </div>

    <div class="card fields">
      <div class="pair">
        <div class="field">
          <label for="telefon">Telefon</label>
          <input id="telefon" class="input num" type="tel" bind:value={form.telefon} autocomplete="tel" aria-invalid={error('telefon') !== undefined} />
        </div>
        <div class="field">
          <label for="mobil">Mobil</label>
          <input id="mobil" class="input num" type="tel" bind:value={form.mobil} aria-invalid={error('mobil') !== undefined} />
        </div>
      </div>
      {#if error('telefon') || error('mobil')}<span class="error">{error('telefon') ?? error('mobil')}</span>{/if}
      <div class="field">
        <label for="email">E-Mail</label>
        <input id="email" class="input" type="email" bind:value={form.email} autocomplete="email" aria-invalid={error('email') !== undefined} />
        {#if error('email')}<span class="error">{error('email')}</span>{/if}
      </div>
      <div class="field">
        <label for="adresse">Adresse <span class="optional">(optional)</span></label>
        <input id="adresse" class="input" bind:value={form.adresse} autocomplete="street-address" />
      </div>
    </div>

    <fieldset class="card fields">
      <legend>Im Notfall anrufen bei <span class="optional">(optional)</span></legend>
      <div class="choice-chips">
        {#each Object.entries(SCENARIOS) as [value, label] (value)}
          <label><input type="checkbox" {value} bind:group={form.notfall} /><span>{label}</span></label>
        {/each}
      </div>
      <span class="hint">Dann steht die Nummer auf dem Notfallblatt.</span>
    </fieldset>

    {#if !id}
      <div class="card">
        <label class="checkbox">
          <input type="checkbox" bind:checked={form.zuhause} />
          Gehört zu diesem Zuhause (z. B. Vermieter, Hausmeister) – bleibt beim Umzug hier
        </label>
      </div>
    {/if}

    {#if edit.error}<p class="save-error" role="alert">{edit.error.text}</p>{/if}
    <button type="submit" class="btn btn-primary btn-large" disabled={edit.saving}>
      {edit.saving ? 'Speichert …' : id ? 'Änderungen speichern' : 'Kontakt anlegen'}
    </button>
  </form>
{/if}
