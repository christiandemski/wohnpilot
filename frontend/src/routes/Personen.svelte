<script lang="ts">
  import { Plus, UserRound } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { people, ROLE_HINTS, ROLE_NAMES, signInWay } from '../lib/people.svelte'
  import { Resource } from '../lib/resource.svelte'
  import type { Person, Role } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  const list = new Resource(async () => (await api.get<{ people: Person[] }>('/people')).people)

  const blank = { name: '', rolle: 'mitglied' as Role, email: '', bearid: '' }
  /** The person being edited ('neu' for a new one), one at a time */
  let editing = $state<string | null>(null)
  let form = $state({ ...blank })
  let busy = $state(false)
  let error = $state('')
  let fieldErrors = $state<Record<string, string>>({})
  let confirmRemove = $state(false)

  function open(person: Person | null) {
    editing = person?.id ?? 'neu'
    form = person ? { name: person.name, rolle: person.rolle, email: person.email ?? '', bearid: person.bearid ?? '' } : { ...blank }
    error = ''
    fieldErrors = {}
    confirmRemove = false
  }

  async function run(change: () => Promise<{ people: Person[] }>) {
    busy = true
    error = ''
    fieldErrors = {}
    try {
      const result = await change()
      list.data = result.people
      people.list = result.people
      editing = null
    } catch (e) {
      const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
      fieldErrors = apiError.fields
      error = apiError.text
    } finally {
      busy = false
    }
  }

  function save(event: SubmitEvent) {
    event.preventDefault()
    const body = { name: form.name.trim(), rolle: form.rolle, email: form.email.trim(), bearid: form.bearid.trim() }
    void run(() =>
      editing === 'neu'
        ? api.post<{ people: Person[] }>('/people', body)
        : api.put<{ people: Person[] }>(`/people/${encodeURIComponent(editing!)}`, body),
    )
  }

  function remove() {
    if (!confirmRemove) {
      confirmRemove = true
      return
    }
    void run(() => api.delete<{ people: Person[] }>(`/people/${encodeURIComponent(editing!)}`))
  }
</script>

{#snippet editor(person: Person | null)}
  <form class="editor" onsubmit={save} novalidate>
    <div class="field">
      <label for="p-name">Name</label>
      <input id="p-name" class="input" bind:value={form.name} autocomplete="off" aria-invalid={fieldErrors.name !== undefined} />
      {#if fieldErrors.name}<span class="error">{fieldErrors.name}</span>{/if}
    </div>
    <fieldset class="field">
      <legend>Was darf {form.name.trim() || 'die Person'}?</legend>
      <div class="roles">
        {#each Object.entries(ROLE_NAMES) as [value, label] (value)}
          <label class="role">
            <input type="radio" name="rolle" {value} bind:group={form.rolle} />
            <span><strong>{label}</strong><span class="muted small">{ROLE_HINTS[value as Role]}</span></span>
          </label>
        {/each}
      </div>
      {#if fieldErrors.rolle}<span class="error">{fieldErrors.rolle}</span>{/if}
    </fieldset>
    <div class="field">
      <label for="p-email">E-Mail <span class="optional">(optional)</span></label>
      <input id="p-email" class="input" type="email" inputmode="email" autocomplete="off" bind:value={form.email} aria-invalid={fieldErrors.email !== undefined} />
      {#if fieldErrors.email}<span class="error">{fieldErrors.email}</span>{:else}<span class="hint">Die Adresse ihres BearID-Kontos oder ihres Notzugangs.</span>{/if}
    </div>
    <div class="field">
      <label for="p-bearid">BearID-Kennung <span class="optional">(optional)</span></label>
      <input id="p-bearid" class="input num" autocomplete="off" bind:value={form.bearid} aria-invalid={fieldErrors.bearid !== undefined} />
      {#if fieldErrors.bearid}<span class="error">{fieldErrors.bearid}</span>{:else}<span class="hint">Nur nötig, wenn die E-Mail bei BearID eine andere ist.</span>{/if}
    </div>
    {#if person}<p class="muted small">Kennung in den Notizen: <span class="num">{person.id}</span></p>{/if}
    {#if error}<p class="save-error" role="alert">{error}</p>{/if}
    {#if confirmRemove}
      <p class="save-error" role="alert">{person?.name} kommt dann nicht mehr in Wohnpilot. Einträge behalten den Namen als Kennung.</p>
    {/if}
    <div class="actions">
      <button type="submit" class="btn btn-primary" disabled={busy}>{person ? 'Speichern' : 'Hinzufügen'}</button>
      <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={() => (editing = null)}>Abbrechen</button>
      {#if person && !person.self}
        <button type="button" class="btn btn-secondary on-surface danger" disabled={busy} onclick={remove}>
          {confirmRemove ? 'Wirklich entfernen' : 'Entfernen'}
        </button>
      {/if}
    </div>
  </form>
{/snippet}

<PageHeader back="/mehr">
  {#snippet action()}
    {#if people.manages && editing !== 'neu'}
      <button type="button" class="btn btn-secondary" onclick={() => open(null)}>
        <Plus size={18} strokeWidth={2} aria-hidden="true" />
        Person hinzufügen
      </button>
    {/if}
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Personen</h1>
  <p class="text-muted">Wer bei euch in Wohnpilot mitmacht. Alle sehen alle Einträge.</p>
</header>

<LoadState resource={list} />

{#if editing === 'neu'}
  <section class="card" aria-label="Person hinzufügen">
    <h2 class="group-title">Neue Person</h2>
    {@render editor(null)}
  </section>
{/if}

{#if list.data}
  <section class="card list-card" aria-label="Personen im Haushalt">
    <ul>
      {#each list.data as person (person.id)}
        <li class="person">
          <div class="list-row">
            <div class="who">
              <IconTile module="kontakte"><UserRound size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="list-text">
                <span class="list-title">{person.name}{person.self ? ' (du)' : ''}</span>
                <span class="list-meta">{ROLE_NAMES[person.rolle]} · {signInWay(person)}</span>
              </span>
            </div>
            {#if people.manages && editing !== person.id}
              <button type="button" class="btn btn-secondary on-surface" onclick={() => open(person)}>Bearbeiten</button>
            {/if}
          </div>
          {#if editing === person.id}{@render editor(person)}{/if}
        </li>
      {/each}
    </ul>
  </section>
{/if}

<section class="card info" aria-labelledby="anmelden">
  <h2 id="anmelden" class="group-title">So meldet sich jemand an</h2>
  <ol>
    <li><strong>Mit BearID:</strong> Trag die E-Mail-Adresse ein, mit der die Person bei BearID angemeldet ist, und gib Wohnpilot in BearID unter „Benutzer“ für ihr Konto frei. Dann öffnet sie Wohnpilot und ist drin.</li>
    <li><strong>Notzugang ohne BearID:</strong> Mit E-Mail und Passwort. Das Passwort legt jemand mit Zugang zum Server an: <code>php bin/account.php add &lt;E-Mail&gt; &lt;Kennung&gt;</code>.</li>
  </ol>
  {#if !people.manages}<p class="muted small">Personen hinzufügen und Rechte ändern kann, wer den Haushalt verwaltet.</p>{/if}
</section>

<style>
  .person {
    display: flex;
    flex-direction: column;
  }

  .person + .person {
    border-top: 1px solid var(--line);
  }

  .person .list-row {
    justify-content: space-between;
    gap: var(--space-3);
    border-top: 0;
  }

  .who {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
  }

  .editor {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-2) 0 var(--space-4);
  }

  fieldset {
    margin: 0;
    padding: 0;
    border: 0;
  }

  legend {
    margin-bottom: var(--space-2);
    font-weight: 600;
  }

  .roles {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .role {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    min-height: 44px;
    padding: var(--space-3);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    cursor: pointer;
  }

  .role:has(input:checked) {
    border-color: var(--accent);
    background: var(--accent-tint);
  }

  .role input {
    margin-top: 3px;
    accent-color: var(--accent);
  }

  .role > span {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    font-weight: 400;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .danger {
    color: var(--due);
  }

  .info ol {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
    padding-left: var(--space-5);
  }

  code {
    font-family: var(--font-num);
    font-size: 0.9em;
    overflow-wrap: anywhere;
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 14px;
  }
</style>
