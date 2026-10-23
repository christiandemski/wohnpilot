<script lang="ts">
  import { CircleAlert, CircleCheck, SkipForward } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { check, HIGH_FACTOR } from '../lib/counter'
  import { daysBetween, formatDate, formatDay, formatDelta, formatNumber } from '../lib/format'
  import { groupMeterNumber, meterTitle, OCCASIONS, roomName } from '../lib/meters'
  import { Resource, todayIso } from '../lib/resource.svelte'
  import type { MeterDetail, MeterSummary } from '../lib/types'
  import IconTile from '../components/IconTile.svelte'
  import LoadState from '../components/LoadState.svelte'
  import MediumIcon from '../components/MediumIcon.svelte'
  import PageHeader from '../components/PageHeader.svelte'
  import Rollenzaehler from '../components/Rollenzaehler.svelte'

  /**
   * Reading round: all meters of the home one after another, sorted by room.
   * With `only` it reads a single meter (from its detail page or a QR code).
   */
  let { only = null }: { only?: string | null } = $props()

  const meters = new Resource(async () => {
    const all = (await api.get<{ meters: MeterSummary[] }>('/meters')).meters
    return only ? all.filter((m) => m.id === only) : all
  })

  let index = $state(0)
  let value = $state(0)
  let date = $state(todayIso())
  let occasion = $state<string>('regulär')
  let askOccasion = $state(false)
  let saving = $state(false)
  let error = $state<ApiError | null>(null)
  let done = $state<{ meter: MeterSummary; value: number; skipped: boolean }[]>([])

  const list = $derived(meters.data ?? [])
  const meter = $derived(list[index] as MeterSummary | undefined)
  const finished = $derived(meters.data !== null && index >= list.length)

  // Start each meter at its last reading, like the real counter shows.
  $effect(() => {
    if (meter) {
      value = meter.latest?.value ?? 0
      occasion = 'regulär'
      askOccasion = false
      error = null
    }
  })

  const result = $derived(
    meter
      ? check(
          value,
          meter.latest ? { value: meter.latest.value, days: daysBetween(meter.latest.date, date) } : null,
          meter.check.typicalDailyRate,
          meter.check.highFactor ?? HIGH_FACTOR,
        )
      : null,
  )
  const warning = $derived(result?.result === 'lower' || result?.result === 'high')
  const unchanged = $derived(meter?.latest !== null && meter?.latest !== undefined && result?.delta === 0)

  async function save() {
    if (!meter) return
    if (warning && !askOccasion) {
      askOccasion = true
      return
    }
    saving = true
    error = null
    try {
      await api.post<MeterDetail>(`/meters/${encodeURIComponent(meter.id)}/readings`, { date, value, occasion })
      done = [...done, { meter, value, skipped: false }]
      index++
    } catch (e) {
      error = e instanceof ApiError ? e : new ApiError(0, 'internal')
    } finally {
      saving = false
    }
  }

  function skip() {
    if (!meter) return
    done = [...done, { meter, value: meter.latest?.value ?? 0, skipped: true }]
    index++
  }

  const backTarget = $derived(only ? `/zaehler/${only}` : '/')
</script>

<div class="round">
  <PageHeader back={backTarget} close title={only ? 'Ablesen' : 'Ablese-Runde'}>
    {#snippet action()}
      {#if list.length > 1 && !finished}
        <span class="count num" aria-label="Zähler {index + 1} von {list.length}">{index + 1}/{list.length}</span>
      {/if}
    {/snippet}
  </PageHeader>

  {#if list.length > 1}
    <div class="progress" aria-hidden="true" style:--count={list.length}>
      {#each list as m, i (m.id)}
        <span class:filled={i <= index}></span>
      {/each}
    </div>
  {/if}

  <LoadState resource={meters} />

  {#if meters.data && list.length === 0}
    <div class="card empty">
      <p>Hier gibt es noch keinen Zähler zum Ablesen.</p>
      <a class="btn btn-primary" href="/zaehler/neu">Zähler anlegen</a>
    </div>
  {:else if finished}
    <section class="card finished" aria-labelledby="finished-title">
      <CircleCheck size={40} strokeWidth={1.75} aria-hidden="true" class="ok-icon" />
      <h1 id="finished-title">{done.some((d) => !d.skipped) ? 'Alles erfasst' : 'Nichts erfasst'}</h1>
      <ul>
        {#each done as entry (entry.meter.id)}
          <li>
            <span>{meterTitle(entry.meter)}</span>
            {#if entry.skipped}
              <span class="muted">übersprungen</span>
            {:else}
              <span class="num">{formatNumber(entry.value, entry.meter.decimals)} {entry.meter.unit}</span>
            {/if}
          </li>
        {/each}
      </ul>
      <a class="btn btn-primary btn-large" href={only ? `/zaehler/${only}` : '/'}>{only ? 'Zum Zähler' : 'Zur Übersicht'}</a>
    </section>
  {:else if meter}
    <div class="meter">
      <IconTile size={52}><MediumIcon medium={meter.medium} size={24} /></IconTile>
      <div>
        <h1>{meterTitle(meter)}</h1>
        <p class="meta">
          {roomName(meter.room)}{#if meter.number}{' · '}<span class="num">{groupMeterNumber(meter.number)}</span>{/if}
        </p>
      </div>
    </div>

    <div class="card counter-card">
      <Rollenzaehler bind:value places={meter.digits} decimals={meter.decimals} unit={meter.unit} />

      {#if result && result.delta !== null && meter.latest}
        <span class="delta-line">
          <span class="delta num">{formatDelta(result.delta, meter.decimals)} {meter.unit}</span>
          <span class="muted">seit {formatDay(meter.latest.date)}</span>
        </span>
      {/if}

      <p role="status" class="check {unchanged ? 'same' : result?.result}">
        {#if result?.result === 'lower'}
          <CircleAlert size={16} strokeWidth={2} aria-hidden="true" />Kleiner als der letzte Stand
        {:else if result?.result === 'high'}
          <CircleAlert size={16} strokeWidth={2} aria-hidden="true" />Ungewöhnlich hoch
        {:else if result?.result === 'first'}
          Erster Stand für diesen Zähler
        {:else if unchanged}
          Gleich wie beim letzten Mal – dreh die Walzen auf den aktuellen Stand
        {:else if meter.check.typicalDailyRate === null}
          <CircleCheck size={16} strokeWidth={2} aria-hidden="true" />Noch kein Vergleich – ab der nächsten Ablesung
        {:else}
          <CircleCheck size={16} strokeWidth={2} aria-hidden="true" />Passt zu deinem Verbrauch
        {/if}
      </p>
    </div>

    {#if meter.latest}
      <div class="facts">
        <div class="card fact">
          <span class="muted small">Letzter Stand</span>
          <span class="num fact-value">{formatNumber(meter.latest.value, meter.decimals)}</span>
          <span class="muted small">{formatDate(meter.latest.date)}</span>
        </div>
        <div class="card fact">
          <span class="muted small">Pro Tag</span>
          <span class="num fact-value">{result?.perDay != null && result.perDay >= 0 ? formatNumber(result.perDay, 1) : '–'}</span>
          <span class="muted small">{result?.days ? `${meter.unit} über ${result.days} ${result.days === 1 ? 'Tag' : 'Tage'}` : 'heute schon abgelesen'}</span>
        </div>
      </div>
    {/if}

    {#if askOccasion}
      <fieldset class="card occasion">
        <legend>Was ist passiert?</legend>
        <p class="muted small">Du kannst trotzdem speichern. Der Anlass steht dann in der Notiz.</p>
        <div class="pills">
          {#each OCCASIONS as option (option)}
            <label class="pill">
              <input type="radio" name="occasion" value={option} bind:group={occasion} />
              <span>{option === 'regulär' ? 'Stimmt so' : option}</span>
            </label>
          {/each}
        </div>
      </fieldset>
    {/if}

    <details class="date">
      <summary>Datum: {date === todayIso() ? 'heute' : formatDate(date)}</summary>
      <div class="field">
        <label for="reading-date">Abgelesen am</label>
        <input id="reading-date" class="input" type="date" bind:value={date} max={todayIso()} />
      </div>
    </details>

    {#if error}
      <p class="save-error" role="alert">
        <CircleAlert size={18} strokeWidth={2} aria-hidden="true" />
        {error.code === 'validation' ? Object.values(error.fields).join(' ') : error.text}
      </p>
    {/if}

    <footer class="actions">
      {#if list.length > 1}
        <button type="button" class="btn btn-icon btn-icon-l" aria-label="Zähler überspringen" onclick={skip}>
          <SkipForward size={22} strokeWidth={1.75} aria-hidden="true" />
        </button>
      {/if}
      <button type="button" class="btn btn-primary btn-large grow" disabled={saving} onclick={save}>
        {#if saving}
          Speichert …
        {:else if askOccasion}
          Mit Anlass speichern
        {:else}
          {index < list.length - 1 ? 'Speichern & weiter' : 'Speichern'}
        {/if}
      </button>
    </footer>
  {/if}
</div>

<style>
  .round {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .count {
    padding: 8px 12px;
    border-radius: var(--radius-full);
    background: var(--surface);
    font-size: 13px;
    font-weight: 600;
  }

  .progress {
    display: grid;
    grid-template-columns: repeat(var(--count), minmax(0, 1fr));
    gap: 6px;
  }

  .progress span {
    height: 6px;
    border-radius: 3px;
    background: var(--line-strong);
  }

  .progress span.filled {
    background: var(--ink);
  }

  .meter {
    display: flex;
    align-items: center;
    gap: 14px;
  }

  h1 {
    font-size: 24px;
    line-height: 29px;
    font-weight: 700;
    letter-spacing: -0.01em;
  }

  .meta,
  .muted {
    color: var(--ink-muted);
  }

  .meta,
  .small {
    font-size: 13px;
    line-height: 18px;
  }

  .counter-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: var(--space-4) 10px var(--space-5);
    border-radius: var(--radius-xl);
  }

  .delta-line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 13px;
  }

  .delta {
    padding: 7px 12px;
    border-radius: var(--radius-full);
    background: var(--inverse);
    color: var(--on-inverse);
    font-size: 17px;
    line-height: 22px;
    font-weight: 600;
  }

  .check {
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: 18px;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
    color: var(--ok);
  }

  .check.lower {
    color: var(--due);
  }

  .check.high {
    color: var(--soon-text);
  }

  .check.first,
  .check.same {
    color: var(--ink-muted);
    font-weight: 500;
  }

  .facts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .fact {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 14px var(--space-4);
    border-radius: var(--radius-md);
  }

  .fact-value {
    font-size: 17px;
    line-height: 22px;
    font-weight: 600;
  }

  .occasion {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin: 0;
    border: 0;
  }

  .occasion legend {
    float: left;
    padding: 0;
    font-size: 15px;
    font-weight: 600;
  }

  .pills {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .pill {
    position: relative;
    display: inline-flex;
  }

  .pill input {
    position: absolute;
    opacity: 0;
    inset: 0;
  }

  .pill span {
    display: inline-flex;
    align-items: center;
    min-height: var(--touch-min);
    padding: 0 var(--space-4);
    border-radius: var(--radius-full);
    box-shadow: inset 0 0 0 1px var(--line-strong);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
  }

  .pill input:checked + span {
    background: var(--inverse);
    color: var(--on-inverse);
    box-shadow: none;
    font-weight: 600;
  }

  .pill input:focus-visible + span {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
  }

  .date summary {
    display: flex;
    align-items: center;
    min-height: var(--touch-min);
    padding: 0 var(--space-1);
    font-size: 14px;
    font-weight: 600;
    color: var(--ink-muted);
    cursor: pointer;
  }

  .save-error {
    display: flex;
    gap: var(--space-2);
    color: var(--due);
    font-weight: 600;
  }

  .actions {
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .grow {
    flex: 1;
  }

  .empty,
  .finished {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    border-radius: var(--radius-xl);
  }

  .finished {
    align-items: stretch;
    padding: var(--space-6) var(--space-5);
  }

  .finished :global(.ok-icon) {
    color: var(--ok);
  }

  .finished ul {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .finished li {
    display: flex;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px 0;
    border-bottom: 1px solid var(--line);
  }
</style>
