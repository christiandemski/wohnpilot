<script lang="ts">
  import { Phone } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { ROLES, isPlaceholder, telHref } from '../lib/knowledge'
  import type { Contact } from '../lib/types'

  /** The linked contact on a detail page: name, role and a call button. */
  let { id }: { id: string } = $props()

  let contact = $state<Contact | null>(null)
  $effect(() => {
    api
      .get<Contact>(`/contacts/${encodeURIComponent(id)}`)
      .then((c) => (contact = c))
      .catch(() => (contact = null))
  })

  const number = $derived(contact ? [contact.phone, contact.mobile].find((n) => n && !isPlaceholder(n)) ?? null : null)
</script>

<div class="line">
  <span class="who">
    <span class="label">Kontakt</span>
    <a href="/kontakte/{encodeURIComponent(id)}">{contact?.name ?? id}</a>
    {#if contact?.fields.rolle}<span class="role">{ROLES[contact.fields.rolle] ?? contact.fields.rolle}</span>{/if}
  </span>
  {#if number}
    <a class="btn btn-secondary on-surface call" href={telHref(number)} aria-label="{contact?.name} anrufen">
      <Phone size={16} strokeWidth={1.75} aria-hidden="true" />
      Anrufen
    </a>
  {/if}
</div>

<style>
  .line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 8px 0;
    border-bottom: 1px solid var(--line);
    font-size: 14px;
  }

  .who {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0 var(--space-2);
  }

  .label,
  .role {
    color: var(--ink-muted);
  }

  .call {
    flex-shrink: 0;
    font-size: 14px;
  }
</style>
