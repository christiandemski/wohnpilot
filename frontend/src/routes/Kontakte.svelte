<script lang="ts">
  import { Phone, Plus, UserRound } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { ROLES, isPlaceholder, telHref } from '../lib/knowledge'
  import type { Contact } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  const contacts = new Resource(async () => (await api.get<{ contacts: Contact[] }>('/contacts')).contacts)

  /** By role, in the order of the role list. */
  function groups(list: Contact[]) {
    const order = Object.keys(ROLES)
    const byRole = new Map<string, Contact[]>()
    for (const c of list) {
      const role = c.fields.rolle ?? 'sonstiges'
      byRole.set(role, [...(byRole.get(role) ?? []), c])
    }
    return [...byRole.entries()].sort(([a], [b]) => order.indexOf(a) - order.indexOf(b))
  }

  const number = (c: Contact) => [c.phone, c.mobile].find((n) => n && !isPlaceholder(n)) ?? null
  const meta = (c: Contact) => [c.fields.firma && c.fields.firma !== c.name ? c.fields.firma : '', c.fields.gewerk ?? ''].filter(Boolean).join(' · ')
</script>

<PageHeader back="/mehr">
  {#snippet action()}
    <a class="btn btn-secondary" href="/kontakte/neu">
      <Plus size={18} strokeWidth={2} aria-hidden="true" />
      Kontakt anlegen
    </a>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Kontakte</h1>
  <p class="text-muted">Vermieter, Handwerker, Störungsnummern – mit einem Tipp angerufen.</p>
</header>

<LoadState resource={contacts} />

{#if contacts.data}
  {#if contacts.data.length === 0}
    <div class="card"><p>Noch keine Kontakte. Fang mit Vermieter und Störungsnummern an, die brauchst du im Notfall.</p></div>
  {/if}
  {#each groups(contacts.data) as [role, items] (role)}
    <section class="card list-card" aria-label={ROLES[role] ?? role}>
      <h2 class="group-title">{ROLES[role] ?? role}</h2>
      <ul>
        {#each items as c (c.id)}
          {@const phone = number(c)}
          <li class="list-row">
            <a href="/kontakte/{encodeURIComponent(c.id)}">
              <IconTile module="kontakte"><UserRound size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
              <span class="list-text">
                <span class="list-title">{c.name}</span>
                <span class="list-meta">{meta(c) || ' '}</span>
              </span>
            </a>
            {#if phone}
              <a class="btn btn-icon call" href={telHref(phone)} aria-label="{c.name} anrufen">
                <Phone size={18} strokeWidth={1.75} aria-hidden="true" />
              </a>
            {/if}
          </li>
        {/each}
      </ul>
    </section>
  {/each}
{/if}

<style>
  .call {
    flex-shrink: 0;
    background: var(--ok-tint);
    color: var(--ok);
  }
</style>
