<script lang="ts">
  import { Check, CircleAlert, Pencil } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { dueChip, euro } from '../lib/contracts'
  import { days, formatDate, formatNumber } from '../lib/format'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { CONSUMPTION, KINDS, TAX_KINDS, changeText, checkTone, periodText, resultChip } from '../lib/utilities'
  import type { UtilityDetail } from '../lib/types'
  import ContactLine from '../components/ContactLine.svelte'
  import DeleteEntry from '../components/DeleteEntry.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'
  import Unterlagen from '../components/Unterlagen.svelte'

  let { id }: { id: string } = $props()

  const path = $derived(`/utilities/${encodeURIComponent(id)}`)
  const statement = new Resource(() => api.get<UtilityDetail>(path))
  const today = todayIso()
  let busy = $state(false)
  let error = $state('')

  async function act(run: () => Promise<UtilityDetail>) {
    busy = true
    error = ''
    try {
      statement.data = await run()
    } catch (e) {
      error = e instanceof ApiError ? (Object.values(e.fields)[0] ?? e.text) : 'Das hat nicht geklappt.'
    } finally {
      busy = false
    }
  }

  const setPaid = (date: string | null) => act(() => api.put<UtilityDetail>(`${path}/paid`, { datum: date }))
  const adopt = () => act(() => api.post<UtilityDetail>(`${path}/prepayment`))
  const taxTotal = (s: UtilityDetail) => s.taxShares.reduce((sum, t) => sum + t.amount, 0)
  /** A short previous period (the move-in month) scaled to a year misleads, above all for heating */
  const COMPARABLE_DAYS = 300
  const comparable = (s: UtilityDetail) => s.previous !== null && s.previous.days >= COMPARABLE_DAYS
</script>

<PageHeader back="/nebenkosten">
  {#snippet action()}
    <a class="btn btn-secondary" href="/nebenkosten/{encodeURIComponent(id)}/bearbeiten">
      <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
      Bearbeiten
    </a>
  {/snippet}
</PageHeader>

<LoadState resource={statement} />

{#if statement.data}
  {@const s = statement.data}
  {@const chip = resultChip(s)}
  <header class="page-head">
    <span class="chip-row">
      <StatusChip tone="module" module="miete">{KINDS[s.kind]}</StatusChip>
      <StatusChip tone={chip.tone}>{chip.text}</StatusChip>
    </span>
    <h1 class="page-title">{s.title}</h1>
    <p class="text-muted">
      <span class="num">{periodText(s.from, s.to)}</span> ({days(s.days)}){#if s.received}, erhalten am <span class="num">{formatDate(s.received)}</span>{/if}
    </p>
  </header>

  <div class="detail-columns">
    <div class="stack">
      <section class="card stack" aria-labelledby="ergebnis">
        <h2 id="ergebnis" class="section-title">Ergebnis</h2>
        <dl class="fact-list">
          <div><dt>Kosten</dt><dd class="num">{euro(s.total)}</dd></div>
          {#if s.fields.vorauszahlungen_eur !== undefined}<div><dt>Deine Vorauszahlungen</dt><dd class="num">− {euro(s.fields.vorauszahlungen_eur)}</dd></div>{/if}
          {#if s.balance !== null}
            <div class="result"><dt>{s.balance > 0 ? 'Nachzahlung' : 'Guthaben'}</dt><dd class="num">{euro(Math.abs(s.balance))}</dd></div>
          {/if}
        </dl>

        {#if s.deliveredLate && s.balance !== null && s.balance > 0}
          <p class="notice ok"><Check size={16} strokeWidth={2} aria-hidden="true" /> Die Abrechnung kam nach dem {formatDate(s.deliveryDeadline!)}. Eine Nachzahlung darf der Vermieter nicht mehr verlangen (§ 556 Abs. 3 BGB); ein Guthaben bekommst du trotzdem.</p>
        {:else if s.balance !== null && s.balance > 0}
          {#if s.fields.bezahlt}
            <p class="text-muted">Überwiesen am <span class="num">{formatDate(s.fields.bezahlt)}</span>.</p>
            <button type="button" class="btn btn-secondary on-surface start" disabled={busy} onclick={() => setPaid(null)}>Doch nicht bezahlt</button>
          {:else}
            {#if s.payment}
              {@const due = dueChip({ date: s.payment.date, daysUntil: s.payment.daysUntil, status: s.payment.daysUntil <= 14 ? 'soon' : 'ok' })}
              <p class="pay-line">Überweisen bis <span class="num">{formatDate(s.payment.date)}</span> <StatusChip tone={due.tone}>{due.text}</StatusChip></p>
            {/if}
            <p class="text-muted text-small">Prüf die Abrechnung vorher. Stimmt etwas nicht, kannst du Einwände erheben und Belege einsehen.</p>
            <button type="button" class="btn btn-primary start" disabled={busy} onclick={() => setPaid(today)}>
              <Check size={18} strokeWidth={2.25} aria-hidden="true" />
              Heute bezahlt
            </button>
          {/if}
        {:else if s.balance !== null && s.balance < 0}
          <p class="text-muted text-small">Das Guthaben zahlt der Vermieter zurück oder verrechnet es mit der nächsten Miete.</p>
        {/if}

        {#if s.billedIn}
          <p>Diese Heizkosten rechnet der Vermieter ab in <a href="/nebenkosten/{encodeURIComponent(s.billedIn)}">{s.billedIn.startsWith('betriebskosten-') ? `Betriebskosten ${s.billedIn.slice(15)}` : s.billedIn}</a>.</p>
        {/if}
        {#each s.heating as h (h.id)}
          <p>Darin enthalten: <a href="/nebenkosten/{encodeURIComponent(h.id)}">{h.title}</a> vom Messdienst, <span class="num">{euro(h.total)}</span>.</p>
        {/each}
        {#if error}<p class="save-error" role="alert">{error}</p>{/if}
      </section>

      {#if s.fields.neue_vorauszahlung_eur !== undefined}
        <section class="card stack" aria-labelledby="vorauszahlung">
          <h2 id="vorauszahlung" class="section-title">Neue Vorauszahlung</h2>
          <p>
            <span class="num big">{euro(s.fields.neue_vorauszahlung_eur)}</span> im Monat{#if s.fields.neue_vorauszahlung_ab}, ab <span class="num">{formatDate(s.fields.neue_vorauszahlung_ab)}</span>{/if}
          </p>
          {#if s.prepaymentAdopted === true}
            <p class="notice ok"><Check size={16} strokeWidth={2} aria-hidden="true" /> Steht im Mietvertrag.</p>
          {:else if s.prepaymentAdopted === false && s.fields.neue_vorauszahlung_ab}
            <p class="text-muted text-small">Im Mietvertrag steht noch der alte Betrag. Übernimmst du ihn, rechnet Wohnpilot ab dann mit der neuen Warmmiete.</p>
            <button type="button" class="btn btn-secondary on-surface start" disabled={busy} onclick={adopt}>In den Mietvertrag übernehmen</button>
          {/if}
        </section>
      {/if}

      <section class="card stack" aria-labelledby="positionen">
        <h2 id="positionen" class="section-title">Positionen</h2>
        {#if s.positions.length === 0}
          <p class="text-muted">Noch keine Positionen. Über „Bearbeiten“ trägst du sie ein.</p>
        {:else}
          <div class="table-wrap">
            <table class="history-table">
              <thead>
                <tr>
                  <th scope="col">Kostenart</th>
                  <th scope="col" class="right">Betrag</th>
                  {#if comparable(s)}<th scope="col" class="right">Zum Vorjahr</th>{/if}
                </tr>
              </thead>
              <tbody>
                {#each s.comparison as row, i (row.kind + i)}
                  {@const position = s.positions[i]}
                  <tr>
                    <th scope="row">
                      {row.kind}
                      {#if position?.taxKind}<span class="tax">§ 35a</span>{/if}
                    </th>
                    <td class="num right">{euro(row.amount)}</td>
                    {#if comparable(s)}
                      <td class="num right {row.change !== null && row.change > 10 ? 'up' : ''}">
                        {row.previousPerYear === null ? 'neu' : changeText(row.change)}
                      </td>
                    {/if}
                  </tr>
                {/each}
              </tbody>
              <tfoot>
                <tr><th scope="row">Summe</th><td class="num right">{euro(s.total)}</td>{#if comparable(s)}<td></td>{/if}</tr>
              </tfoot>
            </table>
          </div>
          {#if s.previous && comparable(s)}
            <p class="text-muted text-small">
              Verglichen mit <a href="/nebenkosten/{encodeURIComponent(s.previous.id)}">{s.previous.title}</a>{s.previous.days !== s.days ? ', beide auf ein Jahr gerechnet' : ''}.
            </p>
          {:else if s.previous}
            <p class="text-muted text-small">
              <a href="/nebenkosten/{encodeURIComponent(s.previous.id)}">{s.previous.title}</a> umfasst nur {days(s.previous.days)}. Hochgerechnet wäre das kein fairer Vergleich – er kommt mit der nächsten Abrechnung.
            </p>
          {/if}
        {/if}
      </section>
    </div>

    <div class="stack">
      <section class="card stack" aria-labelledby="fristen">
        <h2 id="fristen" class="section-title">Fristen</h2>
        <dl class="fact-list">
          {#if s.deliveryDeadline}
            <div>
              <dt>Frist des Vermieters zum Abrechnen</dt>
              <dd class="num">{formatDate(s.deliveryDeadline)}{#if s.deliveredLate}<span class="late"> · zu spät</span>{/if}</dd>
            </div>
          {/if}
          {#if s.objection}
            <div><dt>Einwände möglich bis</dt><dd class="num">{formatDate(s.objection.date)}</dd></div>
          {/if}
        </dl>
        {#if s.objection && s.objection.daysUntil >= 0}
          <p class="text-muted text-small">Bis dahin kannst du Fehler schriftlich beanstanden und beim Vermieter die Belege einsehen. Danach gilt die Abrechnung.</p>
        {:else if s.billedIn}
          <p class="text-muted text-small">Einwände richtest du gegen die Abrechnung des Vermieters.</p>
        {/if}
      </section>

      {#if s.checks.length > 0}
        <section class="card stack" aria-labelledby="abgleich">
          <h2 id="abgleich" class="section-title">Verbrauch im Abgleich</h2>
          <ul class="checks">
            {#each s.checks as c (c.key)}
              {@const label = CONSUMPTION[c.key]}
              <li>
                <span class="check-head">
                  <strong>{label?.label ?? c.key}</strong>
                  {#if c.difference !== null}<StatusChip tone={checkTone(c.difference)}>{changeText(c.difference)}</StatusChip>{/if}
                </span>
                <span class="text-small">
                  Abgerechnet <span class="num">{formatNumber(c.billed, 1)} {c.unit}</span>{#if c.measured !== null}, abgelesen <span class="num">{formatNumber(c.measured, 1)} {c.unit}</span>{/if}
                </span>
                {#if c.meterId === null}
                  <span class="text-muted text-small">Kein passender Zähler in Wohnpilot.</span>
                {:else if c.measured === null}
                  <span class="text-muted text-small">Deine Ablesungen decken {c.coveredDays} von {c.days} Tagen ab – zu wenig für einen Vergleich. <a href="/zaehler/{encodeURIComponent(c.meterId)}">Zähler ansehen</a></span>
                {:else if c.difference !== null && Math.abs(c.difference) > 5}
                  <span class="notice-text"><CircleAlert size={14} strokeWidth={2} aria-hidden="true" /> Weicht deutlich ab. Frag nach, welcher Zählerstand abgerechnet wurde.</span>
                {/if}
              </li>
            {/each}
          </ul>
        </section>
      {/if}

      {#if s.taxShares.length > 0}
        <section class="card stack" aria-labelledby="steuer">
          <h2 id="steuer" class="section-title">Für die Steuer (§ 35a)</h2>
          <dl class="fact-list">
            {#each s.taxShares as t, i (t.kind + i)}
              <div><dt>{t.kind} <span class="text-muted">· {TAX_KINDS[t.taxKind]}</span></dt><dd class="num">{euro(t.amount)}</dd></div>
            {/each}
            <div class="result"><dt>Zusammen</dt><dd class="num">{euro(taxTotal(s))}</dd></div>
          </dl>
          <p class="text-muted text-small">Nur der Arbeitsanteil zählt. 20 % davon kannst du von der Steuer abziehen.</p>
        </section>
      {/if}

      <section class="card stack" aria-labelledby="angaben">
        <h2 id="angaben" class="section-title">Angaben</h2>
        {#if typeof s.fields.kontakt === 'string'}<ContactLine id={s.fields.kontakt} />{/if}
        <dl class="fact-list">
          {#if s.fields.abrechner}<div><dt>Abgerechnet von</dt><dd>{s.fields.abrechner}</dd></div>{/if}
        </dl>
        {#if s.notes}<p class="notes-text">{s.notes}</p>{/if}
        {#if s.modifiedBy}<p class="text-muted text-small">Zuletzt geändert von {s.modifiedBy}</p>{/if}
        <DeleteEntry type="nebenkosten" id={s.id} etag={s.etag} what="Abrechnung" title={s.title} back="/nebenkosten" hint="Mit Positionen und Unterlagen." onChanged={() => statement.reload()} />
      </section>
    </div>
  </div>

  <Unterlagen type="nebenkosten" {id} photos />
{/if}

<style>
  .start {
    align-self: flex-start;
  }

  .result {
    font-weight: 700;
  }

  .big {
    font-size: 24px;
    font-weight: 700;
  }

  .pay-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
  }

  .notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    font-size: 14px;
  }

  .notice.ok {
    color: var(--ok);
  }

  .table-wrap {
    overflow-x: auto;
  }

  .right {
    text-align: right;
  }

  .up {
    color: var(--soon-text);
  }

  .tax {
    margin-left: 4px;
    padding: 1px 6px;
    border-radius: var(--radius-full);
    background: var(--paper);
    font-size: 11px;
    font-weight: 600;
    color: var(--ink-muted);
    white-space: nowrap;
  }

  tfoot th,
  tfoot td {
    font-weight: 700;
    border-top: 1px solid var(--line-strong);
  }

  .late {
    color: var(--ok);
    font-family: var(--font-ui);
  }

  .checks {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .checks li {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .check-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .notice-text {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: var(--soon-text);
  }
</style>
