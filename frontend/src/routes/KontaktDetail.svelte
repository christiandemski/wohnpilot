<script lang="ts">
  import { Download, Mail, Pencil, Phone } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { ROLES, SCENARIOS, TYPE_NAMES, isPlaceholder, routeFor, telHref } from '../lib/knowledge'
  import type { ContactDetail } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import DeleteEntry from '../components/DeleteEntry.svelte'

  let { id }: { id: string } = $props()

  const contact = new Resource(() => api.get<ContactDetail>(`/contacts/${encodeURIComponent(id)}`))
  const shown = (value: unknown) => (typeof value === 'string' && value !== '' && !isPlaceholder(value) ? value : null)
</script>

<PageHeader back="/kontakte">
  {#snippet action()}
    <a class="btn btn-secondary" href="/kontakte/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={contact} />

{#if contact.data}
  {@const c = contact.data}
  {@const f = c.fields}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="kontakte">{ROLES[f.rolle ?? 'sonstiges'] ?? f.rolle}{f.gewerk ? ` · ${f.gewerk}` : ''}</StatusChip>
      {#if c.object}<StatusChip tone="neutral">Zuhause</StatusChip>{/if}
    </span>
    <h1 class="page-title">{c.name}</h1>
    {#if shown(f.firma) && f.firma !== c.name}<p class="text-muted">{f.firma}</p>{/if}
  </header>

  <div class="actions">
    {#each [['Telefon', c.phone], ['Mobil', c.mobile]] as [label, number] (label)}
      {#if shown(number)}
        <a class="btn btn-primary" href={telHref(number!)}>
          <Phone size={18} strokeWidth={1.75} aria-hidden="true" />
          {label} <span class="num">{number}</span>
        </a>
      {/if}
    {/each}
    {#if shown(f.email)}
      <a class="btn btn-secondary" href="mailto:{f.email}">
        <Mail size={18} strokeWidth={1.75} aria-hidden="true" />
        E-Mail
      </a>
    {/if}
    <a class="btn btn-secondary" href="/api/contacts/{encodeURIComponent(id)}/vcard" download>
      <Download size={18} strokeWidth={1.75} aria-hidden="true" />
      Ins Adressbuch
    </a>
  </div>

  <div class="detail-columns">
    <section class="card stack" aria-labelledby="daten">
      <h2 id="daten" class="section-title">Angaben</h2>
      <dl class="fact-list">
        {#if shown(f.email)}<div><dt>E-Mail</dt><dd>{f.email}</dd></div>{/if}
        {#if shown(f.adresse)}<div><dt>Adresse</dt><dd>{f.adresse}</dd></div>{/if}
        {#if f.notfall?.length}<div><dt>Im Notfall bei</dt><dd>{f.notfall.map((s) => SCENARIOS[s] ?? s).join(', ')}</dd></div>{/if}
      </dl>
      {#if c.notes}<p class="notes-text">{c.notes}</p>{/if}
      {#if c.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {c.modifiedBy}</p>{/if}
      <DeleteEntry type="kontakt" id={c.id} etag={c.etag} what="Kontakt" title={c.name} back="/kontakte" hint={c.usedIn.length ? `Er steht noch in ${c.usedIn.length === 1 ? 'einem Eintrag' : `${c.usedIn.length} Einträgen`}, dort fehlt er danach.` : ''} onChanged={() => contact.reload()} />
    </section>

    <section class="card list-card" aria-labelledby="vorkommen">
      <h2 id="vorkommen" class="group-title">Kommt vor in</h2>
      {#if c.usedIn.length === 0}
        <p class="text-muted empty">Noch nirgends verknüpft. Bei Mängeln, Wartungen, Terminen und Verträgen kannst du den Kontakt auswählen.</p>
      {:else}
        <ul>
          {#each c.usedIn as use (use.type + use.id)}
            {@const href = routeFor(use.type, use.id)}
            <li>
              {#if href}
                <a class="list-row" {href}>
                  <span class="list-text">
                    <span class="list-title">{use.title}</span>
                    <span class="list-meta">{TYPE_NAMES[use.type] ?? use.type}</span>
                  </span>
                </a>
              {/if}
            </li>
          {/each}
        </ul>
      {/if}
    </section>
  </div>
{/if}

<style>
  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    padding: 0 var(--space-1) var(--space-2);
  }

  .empty {
    padding: var(--space-2) 0 var(--space-3);
    font-size: 14px;
  }
</style>
