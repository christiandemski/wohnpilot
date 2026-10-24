<script lang="ts">
  import { CalendarClock, CalendarDays, Gauge, Inbox, TriangleAlert, WashingMachine, Wrench, X } from '@lucide/svelte'
  import { ordered, readLastUsed, rememberLastUsed, type CaptureChoice } from '../lib/capture'
  import IconTile from './IconTile.svelte'

  /**
   * "Erfassen": what to enter, behind the plus button. A native dialog, so
   * focus stays inside, Escape closes it and the page behind is inert.
   * Phone: sheet from the bottom; desktop: centred.
   */
  let dialog: HTMLDialogElement | undefined = $state()
  let choices = $state<CaptureChoice[]>(ordered(null))

  const icons = {
    ablesen: Gauge,
    mangel: TriangleAlert,
    wartung: Wrench,
    termin: CalendarDays,
    frist: CalendarClock,
    geraet: WashingMachine,
    eingang: Inbox,
  } as const

  export function open() {
    choices = ordered(readLastUsed())
    dialog?.showModal()
  }

  function close() {
    dialog?.close()
  }

  function choose(choice: CaptureChoice) {
    rememberLastUsed(choice.id)
    // The link itself navigates (router); the sheet just goes away.
    close()
  }

  /** A click on the backdrop lands on the dialog element itself. */
  function backdrop(event: MouseEvent) {
    if (event.target === dialog) close()
  }
</script>

<dialog bind:this={dialog} class="sheet" aria-labelledby="erfassen-title" onclick={backdrop}>
  <div class="panel">
    <header class="head">
      <h2 id="erfassen-title">Erfassen</h2>
      <button type="button" class="btn btn-icon" aria-label="Schließen" onclick={close}>
        <X size={20} strokeWidth={2} aria-hidden="true" />
      </button>
    </header>
    <ul>
      {#each choices as choice, i (choice.id)}
        {@const Icon = icons[choice.id as keyof typeof icons]}
        <li>
          <a class="row" href={choice.href} onclick={() => choose(choice)}>
            <IconTile module={choice.module}><Icon size={20} strokeWidth={1.75} aria-hidden="true" /></IconTile>
            <span class="text">
              <span class="label">{choice.label}</span>
              <span class="meta">{i === 0 && readLastUsed() === choice.id ? 'Zuletzt genutzt' : choice.meta}</span>
            </span>
          </a>
        </li>
      {/each}
    </ul>
  </div>
</dialog>

<style>
  .sheet {
    width: 100%;
    max-width: 480px;
    max-height: 100dvh;
    margin: auto auto 0;
    padding: 0;
    border: 0;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    background: var(--surface);
    color: var(--ink);
  }

  .sheet::backdrop {
    background: color-mix(in srgb, var(--inverse) 45%, transparent);
  }

  .panel {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4) var(--space-4) calc(var(--space-6) + env(safe-area-inset-bottom));
  }

  .head {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  h2 {
    font-size: 20px;
    line-height: 26px;
    font-weight: 700;
  }

  ul {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  li + li {
    border-top: 1px solid var(--line);
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 60px;
    text-decoration: none;
  }

  .row:focus-visible {
    outline-offset: -2px;
  }

  .text {
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .label {
    font-weight: 600;
  }

  .meta {
    font-size: 13px;
    line-height: 18px;
    color: var(--ink-muted);
  }

  @media (min-width: 900px) {
    .sheet {
      margin: auto;
      border-radius: var(--radius-lg);
    }
  }
</style>
