<script lang="ts">
  import { Camera, ScanLine, X } from '@lucide/svelte'
  import { formatNumber } from '../lib/format'
  import { readMeter, type MeterHint } from '../lib/ocr'
  import { shrink } from '../lib/photos'

  /**
   * Photo of the meter in the reading round: proof in the note's "Foto"
   * column, and OCR on the phone suggests the value. Only a suggestion:
   * it goes onto the wheels when you tap "Übernehmen".
   */
  let {
    photo = $bindable(null),
    hint,
    unit,
    onSuggest,
  }: { photo?: Blob | null; hint: MeterHint; unit: string; onSuggest: (value: number) => void } = $props()

  type OcrState = { step: 'idle' } | { step: 'loading'; share: number } | { step: 'reading' } | { step: 'found'; value: number } | { step: 'none' } | { step: 'failed' }
  let ocr = $state<OcrState>({ step: 'idle' })
  let input: HTMLInputElement | undefined = $state()
  let preview = $state<string | null>(null)
  let run = 0

  $effect(() => {
    const url = photo ? URL.createObjectURL(photo) : null
    preview = url
    return () => {
      if (url) URL.revokeObjectURL(url)
    }
  })

  async function choose(event: Event) {
    const file = (event.currentTarget as HTMLInputElement).files?.[0]
    ;(event.currentTarget as HTMLInputElement).value = ''
    if (!file) return
    const current = ++run
    try {
      photo = await shrink(file)
    } catch {
      photo = file
    }
    ocr = { step: 'reading' }
    try {
      const value = await readMeter(photo, hint, (share) => {
        if (current === run && share < 1) ocr = { step: 'loading', share }
        else if (current === run) ocr = { step: 'reading' }
      })
      if (current !== run) return
      ocr = value === null ? { step: 'none' } : { step: 'found', value }
    } catch {
      if (current === run) ocr = { step: 'failed' }
    }
  }

  function remove() {
    run++
    photo = null
    ocr = { step: 'idle' }
  }
</script>

<div class="photo">
  <input bind:this={input} class="visually-hidden" type="file" accept="image/*" capture="environment" tabindex="-1" aria-hidden="true" onchange={choose} />
  {#if photo && preview}
    <div class="taken">
      <img src={preview} alt="Foto vom Zählwerk" />
      <div class="result" role="status">
        {#if ocr.step === 'loading'}
          <span class="muted">Texterkennung lädt … <span class="num">{Math.round(ocr.share * 100)} %</span></span>
          <span class="muted small">Nur beim ersten Mal, danach liegt sie auf dem Handy.</span>
        {:else if ocr.step === 'reading'}
          <span class="muted"><ScanLine size={16} strokeWidth={1.75} aria-hidden="true" /> Liest Ziffern …</span>
        {:else if ocr.step === 'found'}
          <span>Vorschlag: <strong class="num">{formatNumber(ocr.value, hint.decimals)} {unit}</strong></span>
          <button type="button" class="btn btn-secondary on-surface" onclick={() => ocr.step === 'found' && onSuggest(ocr.value)}>Übernehmen</button>
        {:else if ocr.step === 'none'}
          <span class="muted small">Keine passenden Ziffern erkannt. Stell die Walzen von Hand ein, das Foto bleibt als Beleg.</span>
        {:else if ocr.step === 'failed'}
          <span class="muted small">Texterkennung gerade nicht verfügbar. Das Foto bleibt als Beleg.</span>
        {/if}
      </div>
      <button type="button" class="btn btn-icon" aria-label="Foto entfernen" onclick={remove}>
        <X size={18} strokeWidth={1.75} aria-hidden="true" />
      </button>
    </div>
  {:else}
    <button type="button" class="btn btn-secondary on-surface take" onclick={() => input?.click()}>
      <Camera size={18} strokeWidth={1.75} aria-hidden="true" />
      Foto vom Zählwerk
    </button>
    <p class="muted small">Wohnpilot liest die Ziffern auf dem Handy und schlägt sie vor. Das Foto wird als Beleg gespeichert.</p>
  {/if}
</div>

<style>
  .photo {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    width: 100%;
  }

  .take {
    align-self: center;
  }

  .photo > .muted {
    text-align: center;
  }

  .taken {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  img {
    flex-shrink: 0;
    width: 64px;
    height: 64px;
    border-radius: var(--radius-xs);
    object-fit: cover;
  }

  .result {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
    min-width: 0;
    font-size: 14px;
  }

  .result span {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 13px;
    line-height: 18px;
  }
</style>
