<script lang="ts">
  /** Pill switch for 2–4 options (docs/design/komponenten/Segmentschalter.md). */
  let {
    options,
    value = $bindable(),
    label,
    idPrefix,
  }: { options: { value: string; label: string }[]; value: string; label: string; idPrefix: string } = $props()

  let buttons: HTMLButtonElement[] = $state([])

  function onKeydown(event: KeyboardEvent, index: number) {
    const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0
    if (step === 0) return
    event.preventDefault()
    const next = (index + step + options.length) % options.length
    value = options[next].value
    buttons[next]?.focus()
  }
</script>

<div class="track" role="tablist" aria-label={label} style:--count={options.length}>
  {#each options as option, i (option.value)}
    <button
      bind:this={buttons[i]}
      type="button"
      role="tab"
      id="{idPrefix}-tab-{option.value}"
      aria-controls="{idPrefix}-panel"
      aria-selected={value === option.value}
      tabindex={value === option.value ? 0 : -1}
      onclick={() => (value = option.value)}
      onkeydown={(e) => onKeydown(e, i)}
    >
      {option.label}
    </button>
  {/each}
</div>

<style>
  .track {
    display: grid;
    grid-template-columns: repeat(var(--count), minmax(0, 1fr));
    padding: 4px;
    border-radius: var(--radius-full);
    background: var(--line);
  }

  button {
    min-height: 40px;
    border: 0;
    border-radius: var(--radius-full);
    background: transparent;
    color: var(--ink-muted);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
  }

  button[aria-selected='true'] {
    background: var(--surface);
    color: var(--ink);
    font-weight: 600;
  }
</style>
