<script lang="ts">
  import { people } from '../lib/people.svelte'
  import { BookOpen, CalendarDays, ChevronRight, House, KeyRound, Pencil, QrCode, ReceiptText, Siren, TriangleAlert, Truck, WashingMachine, Wrench } from '@lucide/svelte'
  import type { Component } from 'svelte'
  import { api } from '../lib/api'
  import { Resource } from '../lib/resource.svelte'
  import { formatChipDay, formatDate, formatNumber } from '../lib/format'
  import { euro } from '../lib/contracts'
  import { roomName } from '../lib/meters'
  import type { Appointment, DefectSummary, DeviceSummary, Lease, MaintenanceSummary, ObjectData, Waste } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import NextCard from '../components/NextCard.svelte'

  /** Hub for everything about the house: lease, maintenance, appointments and waste, defects, devices, rooms. */
  const object = new Resource(async () => (await api.get<{ object: ObjectData | null }>('/objects/active')).object)

  /** Short state of each area; an area that fails to load just shows no state. */
  const areas = new Resource(async () => {
    const [lease, maintenance, appointments, waste, defects, devices] = await Promise.allSettled([
      api.get<{ lease: Lease | null }>('/lease'),
      api.get<{ tasks: MaintenanceSummary[] }>('/maintenance'),
      api.get<{ upcoming: Appointment[] }>('/appointments'),
      api.get<Waste>('/waste'),
      api.get<{ defects: DefectSummary[] }>('/defects'),
      api.get<{ devices: DeviceSummary[] }>('/devices'),
    ])
    const value = <T,>(r: PromiseSettledResult<T>): T | null => (r.status === 'fulfilled' ? r.value : null)
    return {
      lease: value(lease)?.lease,
      tasks: value(maintenance)?.tasks ?? null,
      appointments: value(appointments)?.upcoming ?? null,
      waste: value(waste),
      defects: value(defects)?.defects ?? null,
      devices: value(devices)?.devices ?? null,
    }
  })

  type Areas = NonNullable<typeof areas.data>

  function leaseMeta(a: Areas): string {
    if (a.lease === undefined) return ''
    if (a.lease === null) return 'Noch nicht eingetragen'
    return `${euro((a.lease.rent.cold ?? 0) + (a.lease.rent.utilities ?? 0))} warm im Monat`
  }

  function maintenanceMeta(a: Areas): string {
    if (a.tasks === null) return ''
    if (a.tasks.length === 0) return 'Rauchmelder, Heizung, Garten – mit Vorschlägen'
    const due = a.tasks.filter((t) => t.evaluation.status === 'due').length
    const soon = a.tasks.filter((t) => t.evaluation.status === 'soon').length
    if (due > 0) return `${due} fällig`
    if (soon > 0) return `${soon} bald fällig`
    return `${a.tasks.length} ${a.tasks.length === 1 ? 'Aufgabe' : 'Aufgaben'}, nichts fällig`
  }

  /** The next thing: an appointment or a pickup, whichever comes first. */
  function datesMeta(a: Areas): string {
    const next: { date: string; text: string }[] = []
    const appointment = a.appointments?.[0]
    if (appointment) next.push({ date: appointment.date, text: appointment.title })
    const pickup = a.waste?.pickups.find((p) => p.bin !== null)
    if (pickup) next.push({ date: pickup.date, text: a.waste!.bins.find((b) => b.id === pickup.bin)?.name ?? pickup.summary })
    next.sort((x, y) => x.date.localeCompare(y.date))
    if (next.length === 0) return a.waste?.configured ? 'Nichts in den nächsten Wochen' : 'Abfuhrkalender und Termine'
    return `${next[0].text} ${formatChipDay(next[0].date)}`
  }

  function defectsMeta(a: Areas): string {
    if (a.defects === null) return ''
    const open = a.defects.filter((d) => d.evaluation.open).length
    if (a.defects.length === 0) return 'Mit Foto, Status und Nachfassen'
    return open === 0 ? 'Keine offenen' : `${open} offen`
  }

  function devicesMeta(a: Areas): string {
    if (a.devices === null) return ''
    if (a.devices.length === 0) return 'Garantie und Typenschild im Blick'
    return `${a.devices.length} ${a.devices.length === 1 ? 'Gerät' : 'Geräte'}`
  }

  const links: { href: string; label: string; icon: Component; module: string; meta: (a: Areas) => string }[] = [
    { href: '/mietvertrag', label: 'Mietvertrag', icon: KeyRound, module: 'miete', meta: leaseMeta },
    { href: '/nebenkosten', label: 'Nebenkosten', icon: ReceiptText, module: 'miete', meta: () => 'Abrechnungen, Vergleich und Fristen' },
    { href: '/wartung', label: 'Wartung', icon: Wrench, module: 'wartung', meta: maintenanceMeta },
    { href: '/termine', label: 'Termine und Müll', icon: CalendarDays, module: 'termine', meta: datesMeta },
    { href: '/maengel', label: 'Mängel', icon: TriangleAlert, module: 'maengel', meta: defectsMeta },
    { href: '/geraete', label: 'Geräte', icon: WashingMachine, module: 'geraete', meta: devicesMeta },
    { href: '/wissen', label: 'Wissen', icon: BookOpen, module: 'wissen', meta: () => 'Wo ist was, Kennwerte, Bedienung' },
    { href: '/umzug', label: 'Umzug', icon: Truck, module: 'miete', meta: () => 'Auszug und Einzug mit Checklisten, frühere Zuhause' },
    { href: '/notfall', label: 'Notfallblatt', icon: Siren, module: 'maengel', meta: () => 'Was tun bei Wasser, Strom, Gas, Heizung' },
    { href: '/aufkleber', label: 'QR-Aufkleber', icon: QrCode, module: 'wissen', meta: () => 'Für Zähler, Geräte und Orte zum Ausdrucken' },
  ]
</script>

<header class="head">
  <h1>Zuhause</h1>
  {#if object.data && people.manages}
    <a class="btn btn-secondary" href="/zuhause/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/if}
</header>

<LoadState resource={object} />

{#if !object.loading && !object.error && object.data === null}
  <NextCard
    title="Richte dein Zuhause ein"
    meta="Haus oder Wohnung, Räume und Einzug – danach legst du deine Zähler an."
    action={{ href: '/zuhause/neu', text: 'Zuhause anlegen' }}
  />
{:else if object.data}
  {@const o = object.data}
  <section class="card" aria-labelledby="objekt">
    <div class="title-row">
      <IconTile size={52} module="miete"><House size={24} strokeWidth={1.75} aria-hidden="true" /></IconTile>
      <div>
        <h2 id="objekt">{o.art === 'haus' ? 'Haus' : 'Wohnung'}</h2>
        <p class="muted">{o.adresse ?? 'Ohne Adresse'}</p>
      </div>
    </div>
    <dl class="facts">
      <div><dt>Eingezogen</dt><dd class="num">{o.einzug ? formatDate(o.einzug) : '–'}</dd></div>
      {#if o.auszug}<div><dt>Auszug geplant</dt><dd class="num"><a href="/umzug">{formatDate(o.auszug)}</a></dd></div>{/if}
      <div><dt>Wohnfläche</dt><dd class="num">{o.wohnflaeche_m2 ? `${formatNumber(o.wohnflaeche_m2)} m²` : '–'}</dd></div>
    </dl>
  </section>

  <nav class="card areas" aria-label="Rund ums Haus">
    <ul>
      {#each links as link (link.href)}
        {@const Icon = link.icon}
        <li>
          <a class="row" href={link.href}>
            <IconTile module={link.module}><Icon size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
            <span class="text">
              <span class="title">{link.label}</span>
              <span class="meta">{areas.data ? link.meta(areas.data) : ' '}</span>
            </span>
            <ChevronRight size={18} strokeWidth={1.75} aria-hidden="true" class="chevron" />
          </a>
        </li>
      {/each}
    </ul>
  </nav>

  <section class="card" aria-labelledby="raeume">
    <h2 id="raeume">Räume</h2>
    {#if o.raeume.length === 0}
      <p class="muted">Noch keine Räume. Räume ordnen die Ablese-Runde: erst Keller, dann Garten.</p>
    {:else}
      <ol class="rooms">
        {#each o.raeume as room (room)}
          <li>{roomName(room)}</li>
        {/each}
      </ol>
      <p class="muted small">In dieser Reihenfolge führt dich die Ablese-Runde durchs Haus.</p>
    {/if}
  </section>
{/if}

<style>
  .head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-3);
    padding: 0 var(--space-1) var(--space-2);
  }

  h1 {
    font-size: 32px;
    line-height: 35px;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  h2 {
    font-size: 17px;
    line-height: 22px;
    font-weight: 700;
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .title-row {
    display: flex;
    align-items: center;
    gap: 14px;
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
  }

  .facts {
    margin: 0;
  }

  .facts div {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-top: 1px solid var(--line);
    font-size: 14px;
  }

  .facts dt {
    color: var(--ink-muted);
  }

  .facts dd {
    margin: 0;
  }

  .areas {
    padding: var(--space-1) var(--space-4);
  }

  .areas ul {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .areas li + li {
    border-top: 1px solid var(--line);
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 64px;
    text-decoration: none;
  }

  .text {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
  }

  .title {
    font-weight: 600;
  }

  .meta {
    min-height: 18px;
    font-size: 13px;
    line-height: 18px;
    color: var(--ink-muted);
  }

  .row :global(.chevron) {
    flex-shrink: 0;
    color: var(--ink-muted);
  }

  .rooms {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .rooms li {
    padding: 6px 12px;
    border-radius: var(--radius-full);
    background: var(--paper);
    font-size: 14px;
    font-weight: 500;
  }
</style>
