<script lang="ts">
  import { Lightbulb, Pencil, Plus } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import { days, formatDate, parseNumber } from '../lib/format'
  import { DEPOSIT_TYPES, RENT_TYPES, euro, months } from '../lib/contracts'
  import { settings } from '../lib/settings.svelte'
  import { paperlessLink } from '../lib/house'
  import type { Lease } from '../lib/types'
  import LoadState from '../components/LoadState.svelte'
  import NextCard from '../components/NextCard.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import StatusChip from '../components/StatusChip.svelte'

  const lease = new Resource(async () => (await api.get<{ lease: Lease | null }>('/lease')).lease)
  const today = todayIso()

  let busy = $state(false)
  let actionError = $state<ApiError | null>(null)

  async function act(run: () => Promise<{ lease: Lease }>) {
    busy = true
    actionError = null
    try {
      lease.data = (await run()).lease
    } catch (e) {
      actionError = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      busy = false
    }
  }

  const agree = (validFrom: string, date: string | null) =>
    act(() => api.put(`/lease/rent/${encodeURIComponent(validFrom)}/agreed`, { datum: date }))

  // Rent change form
  let rentOpen = $state(false)
  let rent = $state({ gueltig_ab: '', kaltmiete: '', nebenkosten: '', grund: '', eingang: '' })
  let rentErrors = $state<Record<string, string>>({})

  async function addRent(event: SubmitEvent) {
    event.preventDefault()
    rentErrors = {}
    await act(async () => {
      try {
        const result = await api.post<{ lease: Lease }>('/lease/rent', {
          gueltig_ab: rent.gueltig_ab,
          kaltmiete_eur: parseNumber(rent.kaltmiete) ?? (rent.kaltmiete.trim() || null),
          nebenkosten_eur: parseNumber(rent.nebenkosten) ?? (rent.nebenkosten.trim() || null),
          grund: rent.grund.trim() || null,
          eingang: rent.eingang || null,
        })
        rentOpen = false
        rent = { gueltig_ab: '', kaltmiete: '', nebenkosten: '', grund: '', eingang: '' }
        return result
      } catch (e) {
        if (e instanceof ApiError) rentErrors = e.fields
        throw e
      }
    })
  }

  const warm = (l: Lease) => (l.rent.cold ?? 0) + (l.rent.utilities ?? 0)
</script>

<PageHeader back="/zuhause">
  {#snippet action()}
    {#if lease.data}
      <a class="btn btn-secondary" href="/mietvertrag/bearbeiten">
        <Pencil size={16} strokeWidth={1.75} aria-hidden="true" />
        Bearbeiten
      </a>
    {/if}
  {/snippet}
</PageHeader>

<LoadState resource={lease} />

{#if !lease.loading && !lease.error && lease.data === null}
  <NextCard
    title="Trag deinen Mietvertrag ein"
    meta="Miete, Kaution und Kündigungsfrist – dann rechnet Wohnpilot die Fristen bei einer Mieterhöhung für dich aus."
    action={{ href: '/mietvertrag/bearbeiten', text: 'Mietvertrag eintragen' }}
  />
{:else if lease.data}
  {@const l = lease.data}
  {@const f = l.fields}
  <div class="detail">
    <div class="head">
      <span class="chips">
        <StatusChip tone="module" module="miete">Mietvertrag · {RENT_TYPES[f.mietart ?? 'standard'] ?? f.mietart}</StatusChip>
      </span>
      <h1>Miete</h1>
      <span class="stand">
        <span class="num stand-value">{euro(warm(l))}</span>
        <span class="unit">warm im Monat</span>
      </span>
      <span class="muted small">
        <span class="num">{euro(l.rent.cold ?? 0)}</span> kalt + <span class="num">{euro(l.rent.utilities ?? 0)}</span> Nebenkosten{#if l.rent.validFrom} · seit <span class="num">{formatDate(l.rent.validFrom)}</span>{/if}
      </span>
    </div>

    <div class="columns">
      <div class="column">
        {#each l.consentDeadlines as consent (consent.validFrom)}
          <section class="hint {consent.status === 'ok' ? 'calm' : consent.status}" aria-label="Mieterhöhung">
            <span class="hint-icon"><Lightbulb size={20} strokeWidth={1.75} aria-hidden="true" /></span>
            <span class="hint-text">
              <strong>
                Mieterhöhung{consent.coldRent !== null ? ` auf ${euro(consent.coldRent)} kalt` : ''} ab {formatDate(consent.validFrom)}
              </strong>
              <span>
                Eingegangen am {formatDate(consent.received)}. Zustimmen musst du bis zum {formatDate(consent.consentBy)}
                ({consent.daysUntil > 0 ? `noch ${days(consent.daysUntil)}` : consent.daysUntil === 0 ? 'heute' : 'abgelaufen'}).
                Prüf vorher Mietspiegel und Begründung; ohne Zustimmung kann der Vermieter klagen.
              </span>
              <span class="actions">
                <button type="button" class="btn btn-secondary on-surface" disabled={busy} onclick={() => agree(consent.validFrom, today)}>Heute zugestimmt</button>
              </span>
            </span>
          </section>
        {/each}

        <section class="card" aria-labelledby="kuendigen">
          <h2 id="kuendigen">Wenn du kündigst</h2>
          <p>
            Ist deine Kündigung bis zum <strong class="num">{formatDate(l.tenantNotice.noticeBy)}</strong> beim Vermieter,
            endet das Mietverhältnis am <strong class="num">{formatDate(l.tenantNotice.endsOn)}</strong>.
          </p>
          <p class="muted small">
            Kündigungsfrist {months(f.kuendigungsfrist_monate ?? 3)}, bis zum dritten Werktag eines Monats; Samstag zählt mit. Wohnpilot nimmt nur bundesweite Feiertage heraus und liegt damit eher zu früh als zu spät. Schriftlich, mit Unterschrift.
          </p>
        </section>

        <section class="card" aria-labelledby="verlauf">
          <div class="section-head">
            <h2 id="verlauf">Miete im Verlauf</h2>
            {#if !rentOpen}
              <button type="button" class="btn btn-secondary on-surface" onclick={() => (rentOpen = true)}>
                <Plus size={16} strokeWidth={2} aria-hidden="true" />
                Mieterhöhung
              </button>
            {/if}
          </div>

          {#if rentOpen}
            <form class="inline-form" onsubmit={addRent} novalidate>
              <div class="pair">
                <div class="field">
                  <label for="gueltig-ab">Gültig ab</label>
                  <input id="gueltig-ab" class="input num" type="date" bind:value={rent.gueltig_ab} aria-invalid={rentErrors.gueltig_ab !== undefined} />
                </div>
                <div class="field">
                  <label for="eingang">Brief erhalten am</label>
                  <input id="eingang" class="input num" type="date" bind:value={rent.eingang} />
                </div>
              </div>
              <div class="pair">
                <div class="field">
                  <label for="kalt">Kaltmiete €</label>
                  <input id="kalt" class="input num" inputmode="decimal" bind:value={rent.kaltmiete} aria-invalid={rentErrors.kaltmiete_eur !== undefined} />
                </div>
                <div class="field">
                  <label for="nk">Nebenkosten €</label>
                  <input id="nk" class="input num" inputmode="decimal" bind:value={rent.nebenkosten} aria-invalid={rentErrors.nebenkosten_eur !== undefined} />
                </div>
              </div>
              <div class="field">
                <label for="grund">Grund <span class="optional">(optional)</span></label>
                <input id="grund" class="input" bind:value={rent.grund} placeholder="z. B. Mietspiegel, Nebenkostenabrechnung" />
              </div>
              <p class="muted small">Mit dem Eingangsdatum rechnet Wohnpilot aus, bis wann du zustimmen musst. Nur Nebenkosten geändert? Dann lass die Kaltmiete leer.</p>
              {#each Object.values(rentErrors).slice(0, 1) as problem (problem)}<p class="save-error">{problem}</p>{/each}
              <div class="actions">
                <button type="submit" class="btn btn-primary" disabled={busy || rent.gueltig_ab === ''}>Eintragen</button>
                <button type="button" class="btn btn-secondary on-surface" onclick={() => (rentOpen = false)}>Abbrechen</button>
              </div>
            </form>
          {/if}

          {#if l.history.length > 0}
            <table class="table">
              <thead><tr><th scope="col">Gültig ab</th><th scope="col">Kalt</th><th scope="col">Nebenkosten</th></tr></thead>
              <tbody>
                {#each l.history as change (change.validFrom)}
                  <tr>
                    <th scope="row">
                      <span class="num">{formatDate(change.validFrom)}</span>
                      {#if change.reason}<span class="reason">{change.reason}</span>{/if}
                      {#if change.agreed}
                        <span class="reason">Zugestimmt am {formatDate(change.agreed)}
                          <button type="button" class="link" disabled={busy} onclick={() => agree(change.validFrom, null)}>zurücknehmen</button>
                        </span>
                      {/if}
                    </th>
                    <td class="num">{change.coldRent !== null ? euro(change.coldRent) : '–'}</td>
                    <td class="num">{change.utilities !== null ? euro(change.utilities) : '–'}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          {:else}
            <p class="muted">Noch kein Verlauf.</p>
          {/if}
          {#if actionError}<p class="save-error" role="alert">{actionError.text}</p>{/if}
        </section>
      </div>

      <div class="column">
        <section class="card" aria-labelledby="daten">
          <h2 id="daten">Vertrag</h2>
          <dl class="facts">
            <div><dt>Vermieter</dt><dd>{f.vermieter ?? '–'}</dd></div>
            <div><dt>Beginn</dt><dd class="num">{f.beginn ? formatDate(f.beginn) : '–'}</dd></div>
            <div><dt>Mietart</dt><dd>{RENT_TYPES[f.mietart ?? 'standard'] ?? f.mietart}</dd></div>
            <div><dt>Kündigungsfrist</dt><dd class="num">{months(f.kuendigungsfrist_monate ?? 3)}</dd></div>
            {#if paperlessLink(settings.paperlessUrl, f.paperless)}
              <div><dt>Paperless</dt><dd class="num"><a href={paperlessLink(settings.paperlessUrl, f.paperless)} target="_blank" rel="noopener">Dokument {f.paperless}</a></dd></div>
            {/if}
          </dl>
        </section>

        <section class="card" aria-labelledby="kaution">
          <h2 id="kaution">Kaution</h2>
          {#if f.kaution}
            <dl class="facts">
              {#if f.kaution.betrag_eur !== undefined}<div><dt>Betrag</dt><dd class="num">{euro(f.kaution.betrag_eur)}</dd></div>{/if}
              {#if f.kaution.art}<div><dt>Art</dt><dd>{DEPOSIT_TYPES[f.kaution.art] ?? f.kaution.art}</dd></div>{/if}
              {#if f.kaution.hinterlegt}<div><dt>Hinterlegt</dt><dd>{f.kaution.hinterlegt}</dd></div>{/if}
            </dl>
          {:else}
            <p class="muted">Keine Kaution eingetragen.</p>
          {/if}
          {#if l.notes}<p class="notes">{l.notes}</p>{/if}
          {#if l.modifiedBy}<p class="muted small">Zuletzt geändert von {l.modifiedBy}</p>{/if}
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

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
    line-height: 18px;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
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

  .hint-text .actions {
    padding-top: var(--space-1);
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

  .inline-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-3);
    border-radius: var(--radius-sm);
    background: var(--paper);
  }

  .inline-form .input {
    background: var(--surface);
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
    font-weight: 600;
  }

  .reason {
    display: block;
    font-size: 12px;
    color: var(--ink-muted);
  }

  .link {
    display: inline-flex;
    align-items: center;
    min-height: var(--touch-min);
    padding: 0 var(--space-1);
    border: 0;
    background: none;
    color: var(--ink-muted);
    font-size: 12px;
    text-decoration: underline;
    cursor: pointer;
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
