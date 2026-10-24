<script lang="ts">
  import { Pencil } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { days, formatLongDay } from '../lib/format'
  import type { Appointment, DeviceSummary } from '../lib/types'
  import ContactLine from '../components/ContactLine.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import DeleteEntry from '../components/DeleteEntry.svelte'

  let { id }: { id: string } = $props()

  const appointment = new Resource(() => api.get<Appointment>(`/appointments/${encodeURIComponent(id)}`))
  const devices = new Resource(async () => (await api.get<{ devices: DeviceSummary[] }>('/devices')).devices)
  const deviceName = (deviceId: string) => devices.data?.find((d) => d.id === deviceId)?.name ?? deviceId
</script>

<PageHeader back="/termine">
  {#snippet action()}
    <a class="btn btn-secondary" href="/termine/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={appointment} />

{#if appointment.data}
  {@const a = appointment.data}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="termine">Termin</StatusChip>
      {#if a.daysUntil >= 0}<StatusChip tone={a.daysUntil <= 1 ? 'soon' : 'neutral'}>{a.daysUntil === 0 ? 'Heute' : a.daysUntil === 1 ? 'Morgen' : `in ${days(a.daysUntil)}`}</StatusChip>{/if}
    </span>
    <h1 class="page-title">{a.title}</h1>
    <p class="when">
      {formatLongDay(a.date)}{a.time ? `, ${a.time}${a.fields.bis ? `–${a.fields.bis}` : ''} Uhr` : ''}
    </p>
  </header>

  <section class="card stack" aria-label="Angaben">
    {#if typeof a.fields.kontakt === 'string'}<ContactLine id={a.fields.kontakt} />{/if}
    <dl class="fact-list">
      {#if a.fields.ort}<div><dt>Wo</dt><dd>{a.fields.ort}</dd></div>{/if}
      {#if a.fields.bezieht_sich_auf?.length}
        <div>
          <dt>Gerät</dt>
          <dd>
            {#each a.fields.bezieht_sich_auf as deviceId, i (deviceId)}
              {#if i > 0}, {/if}<a href="/geraete/{encodeURIComponent(deviceId)}">{deviceName(deviceId)}</a>
            {/each}
          </dd>
        </div>
      {/if}
    </dl>
    {#if a.notes}<p class="notes-text">{a.notes}</p>{/if}
    {#if a.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {a.modifiedBy}</p>{/if}
    <DeleteEntry type="termin" id={a.id} etag={a.etag} what="Termin" title={a.title} back="/termine" hint={''} onChanged={() => appointment.reload()} />
  </section>
{/if}

<style>
  .when {
    font-size: 17px;
    font-weight: 600;
  }
</style>
