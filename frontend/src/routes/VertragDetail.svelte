<script lang="ts">
  import { Lightbulb, Pencil, Plus } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { days, formatDate, parseNumber } from '../lib/format'
  import { categoryName, contractChip, costText, countdownShare, euro, months, noticePeriodText, termText } from '../lib/contracts'
  import { meterTitle } from '../lib/meters'
  import { settings } from '../lib/settings.svelte'
  import { paperlessLink } from '../lib/house'
  import type { ContractDetail, MeterSummary } from '../lib/types'
  import FristLineal from '../components/FristLineal.svelte'
  import FristRing from '../components/FristRing.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  let { id }: { id: string } = $props()

  const path = $derived(`/contracts/${encodeURIComponent(id)}`)
  const contract = new Resource(() => api.get<ContractDetail>(path))
  const meters = new Resource(async () => (await api.get<{ meters: MeterSummary[] }>('/meters')).meters)
  const today = todayIso()

  let busy = $state(false)
  let actionError = $state<ApiError | null>(null)

  async function act(run: () => Promise<ContractDetail>) {
    busy = true
    actionError = null
    try {
      contract.data = await run()
    } catch (e) {
      actionError = e instanceof ApiError ? e : new ApiError(0, 'internal')
      // The note may have changed elsewhere: show what is there now.
      if (actionError.code === 'conflict') void contract.reload()
    } finally {
      busy = false
    }
  }

  function markCancelled(c: ContractDetail) {
    void act(() => api.put(path, { etag: c.etag, changes: { gekuendigt_am: today }, original: { gekuendigt_am: c.fields.gekuendigt_am ?? null } }))
  }

  // Price change form
  let priceOpen = $state(false)
  let priceDate = $state('')
  let priceValues = $state<Record<string, string>>({})

  const DEFAULT_COLUMNS = ['Kosten €', 'Hinweis']
  const priceColumns = (c: ContractDetail) => (c.prices?.columns.slice(1) ?? DEFAULT_COLUMNS)
  const isAmount = (column: string) => /€|ct/.test(column)

  const isoDate = (value: string) => (/^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : value)

  /** "33.9" in the note → "33,9" on screen */
  const cell = (column: string, value: string) => (isAmount(column) ? value.replace(/^(-?\d+)\.(\d+)$/, '$1,$2') : value)

  function addPrice(event: SubmitEvent, c: ContractDetail) {
    event.preventDefault()
    const values: Record<string, string> = {}
    for (const column of priceColumns(c)) {
      const text = (priceValues[column] ?? '').trim()
      const number = isAmount(column) ? parseNumber(text) : null
      // Amounts go into the note as 33.9, like the rows already there.
      values[column] = number !== null ? String(number) : text
    }
    void act(async () => {
      const updated = await api.post<ContractDetail>(`${path}/prices`, { gueltig_ab: priceDate, values })
      priceOpen = false
      priceDate = ''
      priceValues = {}
      return updated
    })
  }
</script>

<PageHeader back="/vertraege">
  {#snippet action()}
    <a class="btn btn-secondary" href="/vertraege/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={contract} />

{#if contract.data}
  {@const c = contract.data}
  {@const f = c.fields}
  {@const t = c.term}
  {@const chip = contractChip(c)}
  {@const explained = termText(t, f.beginn)}
  <div class="detail">
    <div class="head">
      <span class="chips">
        <StatusChip tone="module" module="vertraege">Verträge · {categoryName(f.kategorie)}</StatusChip>
        <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
      </span>
      <h1>{f.sparte ? `${f.sparte} · ` : ''}{c.name}</h1>
      {#if f.kosten_eur !== undefined}
        <span class="cost"><span class="num">{costText(f.kosten_eur, f.rhythmus)}</span></span>
      {/if}
    </div>

    <div class="columns">
      <div class="column">
        <section class="card term" aria-labelledby="laufzeit">
          {#if t.state === 'running'}
            <div class="term-head">
              <FristRing days={t.daysUntilNotice!} share={countdownShare(t.countdownFrom!, t.noticeBy!, today)} status={t.status!} />
              <div class="term-title">
                <h2 id="laufzeit">{explained.title}</h2>
                <span class="when {t.status}">
                  {#if t.daysUntilNotice! < 0}
                    Nächster Kündigungstag nach dem {formatDate(t.periodEnd!)}
                  {:else if t.daysUntilNotice === 0}
                    Heute ist der letzte Tag
                  {:else}
                    noch {days(t.daysUntilNotice!)}
                  {/if}
                </span>
              </div>
            </div>
            <FristLineal term={t} {today} />
          {:else}
            <h2 id="laufzeit">{explained.title}</h2>
          {/if}
          <p>{explained.text}</p>
          {#if t.state === 'running' || t.state === 'open' || t.state === 'expires'}
            <div class="actions">
              <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={() => markCancelled(c)}>Heute gekündigt</button>
            </div>
            <p class="muted small">Schick die Kündigung schriftlich ab und trag danach das bestätigte Datum unter „Bearbeiten“ ein.</p>
          {/if}
          {#if actionError}<p class="save-error" role="alert">{actionError.text}</p>{/if}
        </section>

        {#each c.priceChanges as change (change.effective)}
          <section class="hint {change.status === 'ok' ? 'calm' : change.status}" aria-label="Preisänderung">
            <span class="hint-icon"><Lightbulb size={20} strokeWidth={1.75} aria-hidden="true" /></span>
            <span class="hint-text">
              <strong>Preisänderung zum {formatDate(change.effective)}</strong>
              <span>
                Du kannst bis zum {formatDate(change.noticeBy)} kündigen ({change.daysUntil > 0 ? `noch ${days(change.daysUntil)}` : 'heute'}).
                Vergleich die neuen Preise in der Tabelle mit anderen Anbietern.
              </span>
            </span>
          </section>
        {/each}

        <section class="card" aria-labelledby="preise">
          <div class="section-head">
            <h2 id="preise">Preise</h2>
            {#if !priceOpen}
              <button type="button" class="btn btn-secondary on-surface" onclick={() => (priceOpen = true)}>
                <Plus size={16} strokeWidth={2} aria-hidden="true" />
                Preisänderung
              </button>
            {/if}
          </div>

          {#if priceOpen}
            <form class="price-form" onsubmit={(e) => addPrice(e, c)} novalidate>
              <div class="field">
                <label for="gueltig-ab">Gültig ab</label>
                <input id="gueltig-ab" class="input num" type="date" bind:value={priceDate} required />
              </div>
              {#each priceColumns(c) as column (column)}
                <div class="field">
                  <label for="preis-{column}">{column}</label>
                  <input id="preis-{column}" class="input" class:num={isAmount(column)} inputmode={isAmount(column) ? 'decimal' : 'text'} bind:value={priceValues[column]} />
                </div>
              {/each}
              <p class="muted small">Steht im Brief des Anbieters. Eine Preiserhöhung gibt dir ein Sonderkündigungsrecht bis zum Tag davor.</p>
              <div class="actions">
                <button type="submit" class="btn btn-primary" disabled={busy || priceDate === ''}>Eintragen</button>
                <button type="button" class="btn btn-secondary on-surface" onclick={() => (priceOpen = false)}>Abbrechen</button>
              </div>
            </form>
          {/if}

          {#if c.prices && c.prices.rows.length > 0}
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>{#each c.prices.columns as column (column)}<th scope="col">{column}</th>{/each}</tr>
                </thead>
                <tbody>
                  {#each c.prices.rows as row, i (i)}
                    <tr class:future={row[c.prices.columns[0]] > today}>
                      {#each c.prices.columns as column, j (column)}
                        {#if j === 0}
                          <th scope="row" class="num">{isoDate(row[column])}</th>
                        {:else}
                          <td class:num={isAmount(column)}>{cell(column, row[column] ?? '')}</td>
                        {/if}
                      {/each}
                    </tr>
                  {/each}
                </tbody>
              </table>
            </div>
          {:else}
            <p class="muted">Noch keine Preise. Trag eine Änderung ein, sobald dein Anbieter eine ankündigt.</p>
          {/if}
        </section>
      </div>

      <div class="column">
        <section class="card" aria-labelledby="daten">
          <h2 id="daten">Vertrag</h2>
          <dl class="facts">
            <div><dt>Anbieter</dt><dd>{f.anbieter}</dd></div>
            {#if f.vertragsnummer}<div><dt>{c.insurance ? 'Versicherungsnummer' : 'Vertragsnummer'}</dt><dd class="num">{f.vertragsnummer}</dd></div>{/if}
            <div><dt>Beginn</dt><dd class="num">{f.beginn ? formatDate(f.beginn) : '–'}</dd></div>
            {#if f.mindestlaufzeit_monate}<div><dt>Mindestlaufzeit</dt><dd class="num">{months(f.mindestlaufzeit_monate)}</dd></div>{/if}
            {#if f.verlaengerung_monate}<div><dt>Verlängerung</dt><dd class="num">je {months(f.verlaengerung_monate)}</dd></div>{/if}
            <div><dt>Kündigungsfrist</dt><dd class="num">{noticePeriodText(f) || '–'}</dd></div>
            {#if f.gekuendigt_am}<div><dt>Gekündigt am</dt><dd class="num">{formatDate(f.gekuendigt_am)}</dd></div>{/if}
            {#if f.gekuendigt_zum}<div><dt>Gekündigt zum</dt><dd class="num">{formatDate(f.gekuendigt_zum)}</dd></div>{/if}
            {#if c.monthlyCost !== null && f.rhythmus !== 'monatlich'}
              <div><dt>Pro Monat</dt><dd class="num">{euro(Math.round(c.monthlyCost * 100) / 100)}</dd></div>
            {/if}
            {#if f.versicherungssumme_eur !== undefined}<div><dt>Versicherungssumme</dt><dd class="num">{euro(f.versicherungssumme_eur)}</dd></div>{/if}
            {#if f.selbstbeteiligung_eur !== undefined}<div><dt>Selbstbeteiligung</dt><dd class="num">{euro(f.selbstbeteiligung_eur)}</dd></div>{/if}
            {#if f.bezieht_sich_auf?.length}
              <div>
                <dt>Zähler</dt>
                <dd>
                  {#each f.bezieht_sich_auf as meterId, i (meterId)}
                    {@const meter = meters.data?.find((m) => m.id === meterId)}
                    {#if i > 0}, {/if}<a href="/zaehler/{encodeURIComponent(meterId)}">{meter ? meterTitle(meter) : meterId}</a>
                  {/each}
                </dd>
              </div>
            {/if}
            {#if paperlessLink(settings.paperlessUrl, f.paperless)}
              <div><dt>Paperless</dt><dd class="num"><a href={paperlessLink(settings.paperlessUrl, f.paperless)} target="_blank" rel="noopener">Dokument {f.paperless}</a></dd></div>
            {/if}
          </dl>
          {#if c.notes}<p class="notes">{c.notes}</p>{/if}
          {#if c.modifiedBy}<p class="muted small">Zuletzt geändert von {c.modifiedBy}</p>{/if}
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

  .cost {
    font-size: 20px;
    line-height: 26px;
    font-weight: 600;
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

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .term {
    gap: var(--space-4);
  }

  .term-head {
    display: flex;
    align-items: center;
    gap: var(--space-4);
  }

  .term-title {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .when {
    font-size: 14px;
    font-weight: 600;
    color: var(--ok);
  }

  .when.soon {
    color: var(--soon-text);
  }

  .when.due {
    color: var(--due);
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
    font-size: 13px;
    line-height: 18px;
  }

  .hint {
    display: flex;
    gap: var(--space-3);
    padding: var(--space-4);
    border-radius: var(--radius-lg);
    background: var(--surface);
  }

  .hint.soon,
  .hint.due {
    background: var(--soon-tint);
  }

  .hint-icon {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-xs);
    background: var(--paper);
    color: var(--soon-text);
  }

  .hint.soon .hint-icon,
  .hint.due .hint-icon {
    background: var(--surface);
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

  .section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-2);
  }

  .section-head .btn {
    font-size: 14px;
  }

  .price-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-3);
    border-radius: var(--radius-sm);
    background: var(--paper);
  }

  .price-form .input {
    background: var(--surface);
  }

  .table-wrap {
    overflow-x: auto;
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
    white-space: nowrap;
  }

  .table thead th {
    color: var(--ink-muted);
    font-family: var(--font-ui);
    font-weight: 600;
  }

  .future th:first-child::after {
    content: ' · neu';
    font-family: var(--font-ui);
    color: var(--soon-text);
    font-weight: 600;
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

  .notes {
    white-space: pre-line;
  }
</style>
