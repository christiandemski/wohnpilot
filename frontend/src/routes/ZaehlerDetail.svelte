<script lang="ts">
  import { Gauge, Lightbulb, Pencil } from '@lucide/svelte'
  import { api } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { formatDate, formatEuro, formatNumber, monthName, previousDay } from '../lib/format'
  import { dueChip, groupMeterNumber, INTERVALS, mediumName, meterTitle, roomName } from '../lib/meters'
  import type { Estimate, MeterDetail } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import MonthChart from '../components/MonthChart.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import Segmentschalter from '../components/Segmentschalter.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  let { id }: { id: string } = $props()

  const meter = new Resource(() => api.get<MeterDetail>(`/meters/${encodeURIComponent(id)}`))
  let view = $state('monate')

  function amount(value: number | null, m: MeterDetail): string {
    return value === null ? '–' : `${formatNumber(value, value < 10 ? Math.min(m.decimals, 1) : 0)} ${m.unit}`
  }

  /** Consumption between this reading and the one before (readings are newest first). */
  function usage(m: MeterDetail, i: number): string {
    const older = m.readings[i + 1]
    if (!older) return ''
    if (m.readings[i].occasion === 'Zählerwechsel' && older.occasion === 'Zählerwechsel' && older.date === m.readings[i].date) return 'Wechsel'
    return formatNumber(m.readings[i].value - older.value, m.decimals)
  }

  /** "Ca. 49 € Nachzahlung erwartet" — rounded, because it is an estimate. */
  function hint(estimate: Estimate, m: MeterDetail) {
    const rounded = Math.round(Math.abs(estimate.balance))
    if (rounded < 5) return { tone: 'ok', title: 'Dein Abschlag passt', text: `Hochrechnung ≈ ${formatNumber(estimate.projectedAmount)} ${m.unit} im Jahr.` }
    const extra = estimate.balance > 0
    const instalment = m.tariff?.abschlag_eur
    const suggestion =
      estimate.suggestedInstalment !== null && instalment !== undefined && estimate.suggestedInstalment !== instalment
        ? ` Mit ${formatEuro(estimate.suggestedInstalment)} statt ${formatEuro(instalment)} Abschlag gehst du auf null.`
        : ''
    return {
      tone: extra ? 'soon' : 'ok',
      title: extra ? `Ca. ${formatEuro(rounded)} Nachzahlung erwartet` : `Ca. ${formatEuro(rounded)} Guthaben erwartet`,
      text: `Hochrechnung ≈ ${formatNumber(estimate.projectedAmount)} ${m.unit}.${suggestion}`,
    }
  }
</script>

<PageHeader back="/zaehler">
  {#snippet action()}
    <a class="btn btn-inverse" href="/ablesen/{encodeURIComponent(id)}">
      <Gauge size={18} strokeWidth={1.75} aria-hidden="true" />
      Ablesen
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={meter} />

{#if meter.data}
  {@const m = meter.data}
  <div class="detail">
    <div class="head">
      <span class="chips">
        <StatusChip tone="module">Zähler · {roomName(m.room)}</StatusChip>
        {#if m.latest?.date === todayIso()}
          <StatusChip tone="ok">Heute erfasst</StatusChip>
        {:else}
          <StatusChip tone={m.schedule.status === 'ok' ? 'neutral' : m.schedule.status}>{dueChip(m.schedule, todayIso())}</StatusChip>
        {/if}
      </span>
      <h1>{meterTitle(m)}</h1>
      {#if m.latest}
        <span class="stand">
          <span class="num stand-value">{formatNumber(m.latest.value, m.decimals)}</span>
          <span class="unit">{m.unit}</span>
        </span>
        <span class="muted small">Stand vom {formatDate(m.latest.date)}</span>
      {:else}
        <span class="muted">Noch kein Stand erfasst.</span>
      {/if}
    </div>

    <div class="columns">
      <div class="column">
        <Segmentschalter
          idPrefix="zeitraum"
          label="Zeitraum"
          bind:value={view}
          options={[
            { value: 'monate', label: 'Monate' },
            { value: 'jahre', label: 'Jahre' },
            { value: 'kosten', label: 'Kosten' },
          ]}
        />

        <div id="zeitraum-panel" role="tabpanel" aria-labelledby="zeitraum-tab-{view}">
          {#if view === 'monate'}
            {@const current = m.months[m.months.length - 1]}
            <figure class="card chart-card">
              <figcaption>
                <span class="caption-value">
                  <span class="muted small">{monthName(current.month)}{current.coveredDays > 0 && current.coveredDays < current.days ? ' bisher' : ''}</span>
                  <span class="num big">{amount(current.amount, m)}</span>
                </span>
                <span class="legend">
                  <span><span class="key bar-key"></span>{current.month.slice(0, 4)}</span>
                  <span><span class="key dot-key"></span>Vorjahr</span>
                </span>
              </figcaption>
              {#if m.months.some((mo) => mo.amount !== null)}
                <MonthChart months={m.months} unit={m.unit} decimals={m.decimals} />
              {:else}
                <p class="muted">Ab der zweiten Ablesung siehst du hier deinen Verbrauch.</p>
              {/if}
            </figure>
          {:else if view === 'jahre'}
            <div class="card">
              {#if m.years.length === 0}
                <p class="muted">Noch keine Jahreswerte.</p>
              {:else}
                <table class="table">
                  <thead><tr><th scope="col">Jahr</th><th scope="col">Verbrauch</th><th scope="col">erfasst</th></tr></thead>
                  <tbody>
                    {#each m.years.toReversed() as year (year.year)}
                      <tr>
                        <th scope="row" class="num">{year.year}</th>
                        <td class="num">{amount(year.amount, m)}</td>
                        <td class="num">{year.coveredDays} von {year.days} Tagen</td>
                      </tr>
                    {/each}
                  </tbody>
                </table>
              {/if}
            </div>
          {:else}
            <div class="card">
              {#if m.estimate}
                {@const e = m.estimate}
                <dl class="facts">
                  <div><dt>Zeitraum</dt><dd class="num">{formatDate(e.periodStart)} – {formatDate(previousDay(e.periodEnd))}</dd></div>
                  <div><dt>Hochrechnung</dt><dd class="num">{formatNumber(e.projectedAmount)} {m.unit}</dd></div>
                  <div><dt>Kosten erwartet</dt><dd class="num">{formatEuro(e.cost)}</dd></div>
                  <div><dt>Abschläge</dt><dd class="num">{formatEuro(e.paid)}</dd></div>
                  <div><dt>{e.balance >= 0 ? 'Nachzahlung' : 'Guthaben'}</dt><dd class="num strong">{formatEuro(Math.abs(e.balance))}</dd></div>
                </dl>
                <p class="muted small">Hochrechnung aus deinen Ablesungen; Tage ohne Ablesung zählen mit dem Vorjahr oder deinem Durchschnitt.</p>
              {:else}
                <p class="muted">Trag unter „Bearbeiten“ Arbeitspreis, Grundpreis und Abschlag ein, dann rechnet Wohnpilot die Kosten hoch.</p>
              {/if}
            </div>
          {/if}
        </div>

        {#if m.estimate}
          {@const h = hint(m.estimate, m)}
          <section class="hint {h.tone}" aria-label="Hinweis zum Abschlag">
            <span class="hint-icon"><Lightbulb size={20} strokeWidth={1.75} aria-hidden="true" /></span>
            <span class="hint-text">
              <strong>{h.title}</strong>
              <span>{h.text}</span>
            </span>
          </section>
        {/if}
      </div>

      <div class="column">
        <section class="card" aria-labelledby="verlauf">
          <h2 id="verlauf">Ablesungen</h2>
          {#if m.readings.length === 0}
            <p class="muted">Noch keine Ablesung.</p>
          {:else}
            <table class="table">
              <thead><tr><th scope="col">Datum</th><th scope="col">Stand</th><th scope="col">Verbrauch</th></tr></thead>
              <tbody>
                {#each m.readings as reading, i (reading.date + reading.value + i)}
                  <tr>
                    <th scope="row" class="num">
                      {formatDate(reading.date)}
                      {#if reading.occasion && reading.occasion !== 'regulär'}<span class="occasion">{reading.occasion}</span>{/if}
                    </th>
                    <td class="num">{formatNumber(reading.value, m.decimals)}</td>
                    <td class="num">{usage(m, i)}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          {/if}
        </section>

        <section class="card" aria-labelledby="stammdaten">
          <div class="section-head">
            <h2 id="stammdaten">Zähler</h2>
            <a class="btn btn-secondary on-surface" href="/zaehler/{encodeURIComponent(m.id)}/bearbeiten">
              <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
              Bearbeiten
            </a>
          </div>
          <dl class="facts">
            <div><dt>Art</dt><dd>{mediumName(m.medium)}</dd></div>
            <div><dt>Zählernummer</dt><dd class="num">{m.number ? groupMeterNumber(m.number) : '–'}</dd></div>
            <div><dt>Raum</dt><dd>{roomName(m.room) || '–'}</dd></div>
            <div><dt>Ablesen</dt><dd>{INTERVALS[m.interval] ?? m.interval}</dd></div>
            {#if m.tariff?.arbeitspreis_ct !== undefined}
              <div><dt>Arbeitspreis</dt><dd class="num">{formatNumber(m.tariff.arbeitspreis_ct, 2)} ct/{m.unit}</dd></div>
            {/if}
            {#if m.tariff?.grundpreis_eur_monat !== undefined}
              <div><dt>Grundpreis</dt><dd class="num">{formatEuro(m.tariff.grundpreis_eur_monat, true)} im Monat</dd></div>
            {/if}
            {#if m.tariff?.abschlag_eur !== undefined}
              <div><dt>Abschlag</dt><dd class="num">{formatEuro(m.tariff.abschlag_eur, true)} im Monat</dd></div>
            {/if}
          </dl>
          {#if m.notes}<p class="notes">{m.notes}</p>{/if}
          {#if m.modifiedBy}<p class="muted small">Zuletzt geändert von {m.modifiedBy}</p>{/if}
        </section>
      </div>
    </div>
  </div>
{/if}

<style>
  .detail {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .head {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    padding: var(--space-2) var(--space-1) 0;
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  h1 {
    margin-top: 6px;
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

  .stand {
    display: flex;
    align-items: baseline;
    gap: 6px;
  }

  .stand-value {
    font-size: 40px;
    line-height: 44px;
    font-weight: 600;
    letter-spacing: -0.03em;
  }

  .unit {
    font-size: 16px;
    color: var(--ink-muted);
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
    line-height: 18px;
  }

  .columns,
  .column {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  @media (min-width: 900px) {
    .columns {
      display: grid;
      grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
      align-items: start;
    }
  }

  .chart-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: var(--space-4) var(--space-3) 10px;
  }

  figcaption {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 var(--space-1);
  }

  .caption-value {
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .big {
    font-size: 20px;
    line-height: 26px;
    font-weight: 600;
  }

  .legend {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--space-1);
    font-size: 11px;
    color: var(--ink-muted);
  }

  .legend > span {
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .key {
    display: inline-block;
  }

  .bar-key {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    background: var(--accent);
  }

  .dot-key {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--ink);
  }

  .hint {
    display: flex;
    gap: var(--space-3);
    padding: var(--space-4);
    border-radius: var(--radius-lg);
  }

  .hint.soon {
    background: var(--soon-tint);
  }

  .hint.ok {
    background: var(--ok-tint);
  }

  .hint-icon {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-xs);
    background: var(--surface);
    color: var(--soon-text);
  }

  .hint.ok .hint-icon {
    color: var(--ok);
  }

  .hint-text {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    line-height: 18px;
  }

  .hint-text strong {
    font-size: 15px;
    line-height: 20px;
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
  }

  .section-head .btn {
    font-size: 14px;
  }

  .table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    line-height: 18px;
  }

  .table th,
  .table td {
    padding: 8px 4px;
    border-bottom: 1px solid var(--line);
    text-align: right;
    vertical-align: top;
  }

  .table th:first-child {
    text-align: left;
    font-weight: 500;
  }

  .table thead th {
    color: var(--ink-muted);
    font-family: var(--font-ui);
    font-weight: 600;
  }

  .occasion {
    display: block;
    font-family: var(--font-ui);
    font-size: 12px;
    color: var(--ink-muted);
  }

  .facts {
    display: flex;
    flex-direction: column;
    margin: 0;
  }

  .facts div {
    display: flex;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 8px 0;
    border-bottom: 1px solid var(--line);
    font-size: 14px;
  }

  .facts dt {
    color: var(--ink-muted);
  }

  .facts dd {
    margin: 0;
    text-align: right;
  }

  .strong {
    font-weight: 600;
  }

  .notes {
    white-space: pre-line;
  }
</style>
