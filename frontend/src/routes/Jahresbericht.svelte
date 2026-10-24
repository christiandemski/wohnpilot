<script lang="ts">
  import { CircleAlert, Download, Printer } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { euro } from '../lib/contracts'
  import { formatDate, formatDay } from '../lib/format'
  import { meterTitle } from '../lib/meters'
  import { monthsText, spanText, TAX_SECTIONS } from '../lib/report'
  import { Resource } from '../lib/resource.svelte'
  import { router } from '../lib/router.svelte'
  import { changeText, resultChip } from '../lib/utilities'
  import type { YearReport } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  /** The year in review: costs, consumption, work in the house and the § 35a list. Printable, the list also as CSV. */
  const years = new Resource(async () => (await api.get<{ years: number[] }>('/reports')).years)
  let year = $state(Number(router.query.get('jahr')) || new Date().getFullYear())
  const report = new Resource(() => api.get<YearReport>(`/reports/${year}`))

  function choose(y: number) {
    year = y
    router.go(`/bericht?jahr=${y}`, true)
    void report.reload()
  }

  const kindTotal = (r: YearReport, kind: 'haushaltsnah' | 'handwerker') => r.tax.totals[kind]
</script>

<PageHeader back="/mehr">
  {#snippet action()}
    <button type="button" class="btn btn-secondary" onclick={() => window.print()}>
      <Printer size={16} strokeWidth={1.75} aria-hidden="true" />
      Drucken
    </button>
  {/snippet}
</PageHeader>

<header class="page-head">
  <h1 class="page-title">Jahresbericht {year}</h1>
  {#if years.data && years.data.length > 1}
    <div class="choice-chips no-print" role="radiogroup" aria-label="Jahr">
      {#each years.data as y (y)}
        <label><input type="radio" name="jahr" value={y} checked={y === year} onchange={() => choose(y)} /><span class="num">{y}</span></label>
      {/each}
    </div>
  {/if}
</header>

<LoadState resource={report} />

{#if report.data}
  {@const r = report.data}
  {#if !r.complete}
    <p class="text-muted">Das Jahr läuft noch: Stand heute, die Miete schon bis Dezember gerechnet.</p>
  {/if}

  <div class="detail-columns">
    <div class="stack">
      <section class="card stack" aria-labelledby="wohnen">
        <h2 id="wohnen" class="section-title">Wohnkosten</h2>
        <dl class="fact-list">
          {#if r.housing.rent.months > 0}
            <div><dt>Kaltmiete, {monthsText(r.housing.rent.months)}</dt><dd class="num">{euro(r.housing.rent.cold)}</dd></div>
            <div><dt>Nebenkosten-Vorauszahlungen</dt><dd class="num">{euro(r.housing.rent.utilities)}</dd></div>
          {/if}
          {#each r.housing.contracts as c (c.id)}
            <div><dt>{c.name} <span class="text-muted">· {monthsText(c.months)}</span></dt><dd class="num">{euro(c.total)}</dd></div>
          {/each}
          <div class="sum"><dt>Zusammen</dt><dd class="num">{euro(r.housing.total)}</dd></div>
        </dl>
        <p class="text-muted text-small">Verträge mit ihrem heutigen Preis. Nachzahlungen aus Abrechnungen stehen unten.</p>
      </section>

      {#if r.statements.length > 0}
        <section class="card stack" aria-labelledby="nebenkosten">
          <h2 id="nebenkosten" class="section-title">Nebenkosten-Abrechnungen</h2>
          <ul class="plain">
            {#each r.statements as s (s.id)}
              {@const chip = resultChip({ balance: s.balance, billedIn: s.billedIn, deliveredLate: false, fields: {} })}
              <li class="row-line">
                <a href="/nebenkosten/{encodeURIComponent(s.id)}">{s.title}</a>
                <span class="num">{euro(s.total)}</span>
                <span class="no-print-chip chip-cell"><StatusChip tone={chip.tone}>{chip.text}</StatusChip></span>
              </li>
            {/each}
          </ul>
        </section>
      {/if}

      {#if r.meters.length > 0}
        <section class="card stack" aria-labelledby="verbrauch">
          <h2 id="verbrauch" class="section-title">Verbrauch</h2>
          <ul class="plain">
            {#each r.meters as m (m.id)}
              <li class="meter">
                <span class="meter-head">
                  <a href="/zaehler/{encodeURIComponent(m.id)}">{meterTitle(m)}</a>
                  {#if m.change !== null}<span class="num {m.change > 0 ? 'up' : 'down'}">{changeText(m.change)} zum Vorjahr</span>{/if}
                </span>
                <span class="num">{spanText(m.year, m.unit)}</span>
                {#if m.cost !== null}<span class="text-muted text-small">etwa {euro(m.cost)} nach heutigem Tarif</span>{/if}
                {#if m.previous.coveredDays > 0}<span class="text-muted text-small">Vorjahr: {spanText(m.previous, m.unit)}</span>{/if}
              </li>
            {/each}
          </ul>
        </section>
      {/if}
    </div>

    <div class="stack">
      <section class="card stack" aria-labelledby="steuer">
        <h2 id="steuer" class="section-title">Für die Steuer (§ 35a)</h2>
        {#if r.tax.items.length === 0}
          <p class="text-muted">Keine Beträge in {r.year}. Sie kommen aus Nebenkostenabrechnungen (im Jahr, in dem sie ankamen) und aus Kleinreparaturen, die du selbst bezahlt hast.</p>
        {:else}
          {#each ['haushaltsnah', 'handwerker'] as const as kind (kind)}
            {@const total = kindTotal(r, kind)}
            {#if total}
              <h3 class="group-title">{TAX_SECTIONS[kind].title}</h3>
              <ul class="plain">
                {#each r.tax.items.filter((i) => i.taxKind === kind) as item, i (item.date + item.title + i)}
                  <li class="row-line">
                    <span>
                      {item.title}
                      <span class="text-muted text-small">· <span class="num">{formatDay(item.date)}</span> · {item.source.title}</span>
                      {#if item.check}<span class="check"><CircleAlert size={13} strokeWidth={2} aria-hidden="true" /> Arbeitsanteil prüfen</span>{/if}
                    </span>
                    <span class="num">{euro(item.amount)}</span>
                  </li>
                {/each}
                <li class="row-line sum"><span>Arbeitskosten zusammen</span><span class="num">{euro(total.amount)}</span></li>
                <li class="row-line"><span>Davon 20 % Ermäßigung <span class="text-muted text-small">({TAX_SECTIONS[kind].cap})</span></span><span class="num">{euro(total.reduction)}</span></li>
              </ul>
            {/if}
          {/each}
          <p class="reduction">Steuerermäßigung {r.year} zusammen <span class="num">{euro(r.tax.reduction)}</span></p>
          <p class="text-muted text-small">
            Trag die Arbeitskosten in der Steuererklärung bei den haushaltsnahen Aufwendungen ein. Bei „Arbeitsanteil prüfen“ steht der ganze Betrag; nimm den Lohnanteil von der Rechnung. Keine Steuerberatung.
          </p>
          <a class="btn btn-secondary on-surface start no-print" href="/api/reports/{r.year}/steuer.csv" download>
            <Download size={16} strokeWidth={1.75} aria-hidden="true" />
            Als CSV herunterladen
          </a>
        {/if}
      </section>

      <section class="card stack" aria-labelledby="haus">
        <h2 id="haus" class="section-title">Am Haus erledigt</h2>
        {#if r.maintenance.length === 0 && r.defects.length === 0}
          <p class="text-muted">Nichts eingetragen.</p>
        {/if}
        {#if r.maintenance.length > 0}
          <h3 class="group-title">Wartung</h3>
          <ul class="plain">
            {#each r.maintenance as m, i (m.id + m.date + i)}
              <li class="row-line">
                <span><a href="/wartung/{encodeURIComponent(m.id)}">{m.title}</a>{#if m.note}{' '}<span class="text-muted text-small">· {m.note}</span>{/if}</span>
                <span class="num">{formatDay(m.date)}</span>
              </li>
            {/each}
          </ul>
        {/if}
        {#if r.defects.length > 0}
          <h3 class="group-title">Behobene Mängel</h3>
          <ul class="plain">
            {#each r.defects as d (d.id)}
              <li class="row-line">
                <span><a href="/maengel/{encodeURIComponent(d.id)}">{d.title}</a>{#if d.cost !== null}{' '}<span class="text-muted text-small">· <span class="num">{euro(d.cost)}</span></span>{/if}</span>
                <span class="num">{formatDay(d.fixedOn)}</span>
              </li>
            {/each}
          </ul>
        {/if}
        {#if r.minorRepairs.cases.length > 0}
          <p class="text-small">
            Kleinreparaturen: <span class="num">{euro(r.minorRepairs.total)}</span>{#if r.minorRepairs.perYear !== null}{' '}von höchstens <span class="num">{euro(r.minorRepairs.perYear)}</span> im Jahr{/if}.
          </p>
        {/if}
      </section>
    </div>
  </div>
  <p class="text-muted text-small print-only">Wohnpilot · Jahresbericht {r.year} · erstellt am {formatDate(new Date().toISOString().slice(0, 10))}</p>
{/if}

<style>
  .plain {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .plain > li + li {
    border-top: 1px solid var(--line);
  }

  .row-line {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: baseline;
    gap: var(--space-1) var(--space-3);
    padding: var(--space-2) 0;
  }

  .row-line > :last-child {
    text-align: right;
  }

  .meter {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-2) 0;
  }

  .meter-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: var(--space-1) var(--space-3);
  }

  .sum {
    font-weight: 700;
  }

  .chip-cell {
    grid-column: 1 / -1;
  }

  .up {
    color: var(--soon-text);
  }

  .down {
    color: var(--ok);
  }

  .check {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    margin-left: 4px;
    font-size: 12px;
    font-weight: 600;
    color: var(--soon-text);
  }

  .reduction {
    display: flex;
    justify-content: space-between;
    padding-top: var(--space-2);
    border-top: 1px solid var(--line-strong);
    font-weight: 700;
  }

  .start {
    align-self: flex-start;
  }

  .print-only {
    display: none;
  }

  @media print {
    .print-only {
      display: block;
    }

    .no-print-chip {
      display: none;
    }

    .card {
      break-inside: avoid;
    }
  }
</style>
