<script lang="ts">
  import { ChevronRight, House, ListChecks, Truck } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { formatDate } from '../lib/format'
  import { people } from '../lib/people.svelte'
  import { Resource } from '../lib/resource.svelte'
  import type { ChecklistSummary, Home, MoveStatus } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  const status = new Resource(() => api.get<MoveStatus>('/move'))

  const current = $derived(status.data?.homes.find((h) => h.state === 'aktiv') ?? null)
  const planned = $derived(status.data?.homes.find((h) => h.state === 'geplant') ?? null)
  const former = $derived(status.data?.homes.filter((h) => h.state === 'frueher') ?? [])

  let moveOutDate = $state('')
  let changingDate = $state(false)
  const blankHome = { art: 'wohnung', bezeichnung: '', adresse: '', einzug: '' }
  let home = $state({ ...blankHome })
  let addingHome = $state(false)
  let confirmSwitch = $state(false)
  let busy = $state(false)
  let error = $state('')
  let fieldErrors = $state<Record<string, string>>({})

  async function run(action: () => Promise<MoveStatus>, done?: () => void) {
    busy = true
    error = ''
    fieldErrors = {}
    try {
      status.data = await action()
      done?.()
    } catch (e) {
      const apiError = e instanceof ApiError ? e : new ApiError(0, 'internal')
      fieldErrors = apiError.fields
      error = apiError.text
    } finally {
      busy = false
    }
  }

  function planMoveOut(event: SubmitEvent) {
    event.preventDefault()
    void run(() => api.post<MoveStatus>('/move/out', { auszug: moveOutDate }), () => (changingDate = false))
  }

  function createHome(event: SubmitEvent) {
    event.preventDefault()
    void run(() => api.post<MoveStatus>('/move/in', { ...home }), () => {
      addingHome = false
      home = { ...blankHome }
    })
  }

  function switchHome() {
    if (!confirmSwitch) {
      confirmSwitch = true
      return
    }
    void run(() => api.post<MoveStatus>('/move/switch', { objekt: planned!.id }), () => (confirmSwitch = false))
  }

  const homeName = (h: Pick<Home, 'art' | 'adresse'>) => h.adresse ?? (h.art === 'haus' ? 'Haus' : 'Wohnung')
  const listName = (c: ChecklistSummary) => (c.art === 'auszug' ? 'Auszugs-Checkliste' : c.art === 'einzug' ? 'Einzugs-Checkliste' : 'Checkliste')
  const progress = (c: ChecklistSummary) => (c.total === 0 ? 'leer' : c.done === c.total ? 'alles erledigt' : `${c.done} von ${c.total} erledigt`)
</script>

{#snippet checklists(list: ChecklistSummary[])}
  {#if list.length}
    <ul class="lists">
      {#each list as c (c.id)}
        <li>
          <a class="list-row" href="/umzug/{encodeURIComponent(c.id)}">
            <IconTile module="miete"><ListChecks size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
            <span class="list-text">
              <span class="list-title">{listName(c)}</span>
              <span class="list-meta">{progress(c)}</span>
            </span>
            <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
          </a>
        </li>
      {/each}
    </ul>
  {/if}
{/snippet}

<PageHeader back="/zuhause" />

<header class="page-head">
  <h1 class="page-title">Umzug</h1>
  <p class="text-muted">Auszug und Einzug mit Checklisten aus deinen Daten. Dein altes Zuhause bleibt zum Nachlesen erhalten.</p>
</header>

<LoadState resource={status} />

{#if status.data}
  {#if error && !Object.keys(fieldErrors).length}<p class="save-error" role="alert">{error}</p>{/if}

  {#if current}
    <section class="card stack" aria-labelledby="jetzt">
      <div class="title-row">
        <IconTile size={52} module="miete"><House size={24} strokeWidth={1.75} aria-hidden="true" /></IconTile>
        <div>
          <h2 id="jetzt">Jetziges Zuhause</h2>
          <p class="muted">{homeName(current)}</p>
        </div>
      </div>
      {#if current.auszug && !changingDate}
        <p>Auszug geplant zum <strong class="num">{formatDate(current.auszug)}</strong>.</p>
        {@render checklists(current.checklists.filter((c) => c.art === 'auszug'))}
        {#if people.manages}
          <button type="button" class="btn btn-secondary on-surface" onclick={() => { moveOutDate = current.auszug ?? ''; changingDate = true }}>Datum ändern</button>
        {/if}
      {:else if people.manages}
        <form class="stack" onsubmit={planMoveOut} novalidate>
          <div class="field">
            <label for="auszug">Wann ziehst du aus?</label>
            <input id="auszug" class="input num" type="date" min={current.einzug ?? undefined} bind:value={moveOutDate} aria-invalid={fieldErrors.auszug !== undefined} />
            {#if fieldErrors.auszug}<span class="error">{fieldErrors.auszug}</span>{:else}<span class="hint">Meist der letzte Tag eines Monats. Daraus berechnet Wohnpilot, bis wann die Kündigung beim Vermieter sein muss.</span>{/if}
          </div>
          <div class="actions">
            <button type="submit" class="btn btn-primary" disabled={busy || !moveOutDate}>{current.auszug ? 'Datum speichern' : 'Auszug planen'}</button>
            {#if changingDate}<button type="button" class="btn btn-secondary on-surface" onclick={() => (changingDate = false)}>Abbrechen</button>{/if}
          </div>
          {#if !current.auszug}<p class="muted small">Die Auszugs-Checkliste entsteht aus Mietvertrag, Verträgen, Zählern, offenen Mängeln und Geräten des Vermieters. Du kannst sie danach auch in der Notes-App abhaken.</p>{/if}
        </form>
      {:else}
        <p class="muted">Kein Umzug geplant.</p>
      {/if}
    </section>
  {/if}

  <section class="card stack" aria-labelledby="neu">
    <div class="title-row">
      <IconTile size={52} module="miete"><Truck size={24} strokeWidth={1.75} aria-hidden="true" /></IconTile>
      <div>
        <h2 id="neu">Neues Zuhause</h2>
        <p class="muted">{planned ? homeName(planned) : 'Noch keins angelegt'}</p>
      </div>
    </div>
    {#if planned}
      {#if planned.einzug}<p>Einzug am <strong class="num">{formatDate(planned.einzug)}</strong>.</p>{/if}
      {@render checklists(planned.checklists)}
      {#if people.manages}
        {#if confirmSwitch}
          <p class="save-error" role="alert">
            Ab dann zeigt Wohnpilot das neue Zuhause. {current ? `„${homeName(current)}“ wird archiviert, mit allen Zählern, Mängeln und Wartungen.` : ''}
            Verträge des Haushalts ziehen mit um.
          </p>
        {/if}
        <div class="actions">
          <button type="button" class="btn btn-primary" disabled={busy} onclick={switchHome}>{confirmSwitch ? 'Ja, jetzt umziehen' : 'Umgezogen: zum neuen Zuhause wechseln'}</button>
          {#if confirmSwitch}<button type="button" class="btn btn-secondary on-surface" onclick={() => (confirmSwitch = false)}>Abbrechen</button>{/if}
        </div>
      {/if}
    {:else if addingHome}
      <form class="stack" onsubmit={createHome} novalidate>
        <fieldset class="field">
          <legend>Was ist es?</legend>
          <div class="choice-chips">
            <label><input type="radio" name="art" value="wohnung" bind:group={home.art} /><span>Wohnung</span></label>
            <label><input type="radio" name="art" value="haus" bind:group={home.art} /><span>Haus</span></label>
          </div>
        </fieldset>
        <div class="field">
          <label for="bezeichnung">Kurzname</label>
          <input id="bezeichnung" class="input" bind:value={home.bezeichnung} placeholder="z. B. Wohnung am Park" />
          <span class="hint">Daraus wird der Ordnername in der Nextcloud.</span>
        </div>
        <div class="field">
          <label for="adresse">Adresse <span class="optional">(optional)</span></label>
          <input id="adresse" class="input" bind:value={home.adresse} autocomplete="street-address" aria-invalid={fieldErrors.adresse !== undefined} />
          {#if fieldErrors.adresse}<span class="error">{fieldErrors.adresse}</span>{/if}
        </div>
        <div class="field">
          <label for="einzug">Einzug</label>
          <input id="einzug" class="input num" type="date" bind:value={home.einzug} aria-invalid={fieldErrors.einzug !== undefined} />
          {#if fieldErrors.einzug}<span class="error">{fieldErrors.einzug}</span>{/if}
        </div>
        <div class="actions">
          <button type="submit" class="btn btn-primary" disabled={busy}>Anlegen, mit Einzugs-Checkliste</button>
          <button type="button" class="btn btn-secondary on-surface" onclick={() => (addingHome = false)}>Abbrechen</button>
        </div>
      </form>
    {:else if people.manages}
      <p class="muted small">Leg das neue Zuhause schon vor dem Umzug an. Es bleibt im Hintergrund, bis du wechselst.</p>
      <button type="button" class="btn btn-secondary on-surface" onclick={() => (addingHome = true)}>Neues Zuhause anlegen</button>
    {/if}
  </section>

  {#if former.length}
    <section class="card list-card" aria-labelledby="frueher">
      <h2 id="frueher" class="group-title">Frühere Zuhause</h2>
      <ul>
        {#each former as h (h.id)}
          <li class="former">
            <span class="list-text">
              <span class="list-title">{homeName(h)}</span>
              <span class="list-meta num">{h.einzug ? formatDate(h.einzug) : '?'} bis {h.auszug ? formatDate(h.auszug) : '?'}</span>
            </span>
            {@render checklists(h.checklists)}
          </li>
        {/each}
      </ul>
      <p class="muted small">Ihre Notizen liegen weiter in der Nextcloud unter <span class="num">objekte/</span>.</p>
    </section>
  {/if}
{/if}

<style>
  .stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .title-row {
    display: flex;
    align-items: center;
    gap: 14px;
  }

  h2 {
    font-size: 17px;
    line-height: 22px;
    font-weight: 700;
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

  .lists {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .lists .list-row {
    color: inherit;
  }

  .former {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    padding: var(--space-3) 0;
  }

  .former + .former {
    border-top: 1px solid var(--line);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 14px;
  }
</style>
