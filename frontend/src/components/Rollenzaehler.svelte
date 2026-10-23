<script lang="ts">
  import { ChevronDown, ChevronUp } from '@lucide/svelte'
  import { fromWheels, placesFor, toWheels, turn } from '../lib/counter'
  import { formatNumber, parseNumber } from '../lib/format'

  /**
   * Meter value as digit wheels (docs/design/komponenten/Rollenzaehler.md).
   * Arrows turn one wheel; tapping a wheel opens a keypad for the whole value.
   */
  let {
    value = $bindable(),
    places,
    decimals,
    unit,
  }: { value: number; places: number; decimals: number; unit: string } = $props()

  const shownPlaces = $derived(placesFor(value, places))
  const wheels = $derived(toWheels(value, shownPlaces, decimals))

  let typing = $state(false)
  let typed = $state('')
  let typedError = $state('')
  let input: HTMLInputElement | undefined = $state()

  function step(index: number, direction: 1 | -1) {
    value = fromWheels(turn(wheels, index, direction))
  }

  function openKeypad() {
    typed = formatNumber(value, decimals).replace(/ /g, '')
    typedError = ''
    typing = true
    queueMicrotask(() => input?.select())
  }

  function applyTyped(event: SubmitEvent) {
    event.preventDefault()
    const parsed = parseNumber(typed)
    if (parsed === null || parsed < 0) {
      typedError = 'Bitte einen Zählerstand eingeben, z. B. 23702,5.'
      return
    }
    value = Math.round(parsed * 10 ** decimals) / 10 ** decimals
    typing = false
  }

  function positionLabel(index: number): string {
    return index < shownPlaces ? `Stelle ${index + 1}` : `Nachkommastelle ${index - shownPlaces + 1}`
  }
</script>

<section class="counter" aria-label="Zählerstand einstellen">
  <div class="wheels" role="group" aria-label="Zählerstand">
    {#each wheels.digits as digit, i (i)}
      {#if i === shownPlaces}
        <span class="comma" aria-hidden="true">,</span>
      {/if}
      <span class="column">
        <button type="button" class="arrow" aria-label="{positionLabel(i)} erhöhen" onclick={() => step(i, 1)}>
          <ChevronUp size={18} strokeWidth={2} aria-hidden="true" />
        </button>
        <button
          type="button"
          class="wheel num"
          class:decimal={i >= shownPlaces}
          aria-label="{positionLabel(i)}: {digit}. Zum Eintippen antippen"
          onclick={openKeypad}
        >
          {digit}
        </button>
        <button type="button" class="arrow" aria-label="{positionLabel(i)} verringern" onclick={() => step(i, -1)}>
          <ChevronDown size={18} strokeWidth={2} aria-hidden="true" />
        </button>
      </span>
    {/each}
  </div>
  <p class="visually-hidden" aria-live="polite">Eingestellt: {formatNumber(value, decimals)} {unit}</p>

  {#if typing}
    <form class="keypad" onsubmit={applyTyped}>
      <div class="field">
        <label for="counter-typed">Zählerstand eintippen</label>
        <div class="row">
          <input
            bind:this={input}
            id="counter-typed"
            class="input num"
            inputmode="decimal"
            autocomplete="off"
            bind:value={typed}
            aria-invalid={typedError !== ''}
            aria-describedby={typedError ? 'counter-typed-error' : undefined}
          />
          <button type="submit" class="btn btn-inverse">Übernehmen</button>
        </div>
        {#if typedError}<span id="counter-typed-error" class="error">{typedError}</span>{/if}
      </div>
    </form>
  {/if}
</section>

<style>
  .counter {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    width: 100%;
    container-type: inline-size;
  }

  .wheels {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 4px;
    width: 100%;
  }

  /* Wheels shrink with the card so 8 of them still fit on a 390 px phone. */
  .column {
    display: flex;
    flex: 0 1 46px;
    flex-direction: column;
    align-items: center;
    min-width: 0;
  }

  .arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-width: 32px;
    height: 40px;
    border: 0;
    background: transparent;
    color: var(--ink-muted);
    cursor: pointer;
  }

  .wheel {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 72px;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: var(--counter-face);
    color: var(--counter-digit);
    font-size: min(40px, 8.5cqi);
    line-height: 44px;
    font-weight: 600;
    letter-spacing: -0.02em;
    cursor: text;
    /* Hint of a rolling wheel: darker top and bottom edge */
    box-shadow:
      inset 0 12px 12px -10px color-mix(in srgb, black 55%, transparent),
      inset 0 -12px 12px -10px color-mix(in srgb, black 55%, transparent);
  }

  .wheel.decimal {
    background: var(--counter-decimal);
  }

  .comma {
    flex-shrink: 0;
    align-self: center;
    padding: 0 1px;
    font-family: var(--font-num);
    font-size: min(36px, 8cqi);
    font-weight: 600;
    color: var(--ink-muted);
  }

  .keypad {
    width: 100%;
    padding: 0 var(--space-2);
  }

  .row {
    display: flex;
    gap: var(--space-2);
  }

  .row .input {
    font-size: 20px;
  }
</style>
