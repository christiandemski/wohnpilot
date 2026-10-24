<script lang="ts">
  import { api } from '../lib/api'
  import { ROLES } from '../lib/knowledge'
  import type { Contact } from '../lib/types'

  /** Choose the contact an entry links to (`kontakt`), e.g. the landlord for a defect. */
  let { value = $bindable(''), label = 'Kontakt', id = 'kontakt' }: { value: string; label?: string; id?: string } = $props()

  let contacts = $state<Contact[]>([])
  api
    .get<{ contacts: Contact[] }>('/contacts')
    .then((r) => (contacts = r.contacts))
    .catch(() => (contacts = []))
</script>

<div class="field">
  <label for={id}>{label} <span class="optional">(optional)</span></label>
  <select {id} class="input" bind:value>
    <option value="">–</option>
    {#each contacts as contact (contact.id)}
      <option value={contact.id}>{contact.name}{contact.fields.rolle ? ` · ${ROLES[contact.fields.rolle] ?? contact.fields.rolle}` : ''}</option>
    {/each}
    {#if value && !contacts.some((c) => c.id === value)}<option {value}>{value}</option>{/if}
  </select>
  <span class="hint">Fehlt jemand? <a href="/kontakte/neu">Kontakt anlegen</a></span>
</div>
