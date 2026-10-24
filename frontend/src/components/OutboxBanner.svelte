<script lang="ts">
  import { ChevronDown, CloudOff, CloudUpload, Trash2 } from '@lucide/svelte'
  import { outbox } from '../lib/outbox.svelte'

  /** Offline notice and "n Einträge warten", above every page. */
  let open = $state(false)
  const count = $derived(outbox.jobs.length)
  const when = (iso: string) => new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
  const failed = $derived(outbox.jobs.filter((j) => j.error !== undefined).length)
</script>

{#if !outbox.online || count > 0}
  <section class="outbox no-print" aria-live="polite" aria-label="Verbindung">
    {#if !outbox.online}
      <p class="line">
        <CloudOff size={18} strokeWidth={1.75} aria-hidden="true" />
        <span>Offline – du siehst den zuletzt geladenen Stand.</span>
      </p>
    {/if}
    {#if count > 0}
      <div class="line">
        <CloudUpload size={18} strokeWidth={1.75} aria-hidden="true" />
        <span class="grow">
          <strong class="num">{count}</strong>
          {count === 1 ? 'Eintrag wartet' : 'Einträge warten'}{failed > 0 ? `, ${failed} abgelehnt` : outbox.online ? '' : ' auf Netz'}
        </span>
        {#if outbox.online}
          <button type="button" class="btn btn-secondary on-surface" disabled={outbox.sending} onclick={() => void outbox.flush()}>
            {outbox.sending ? 'Sendet …' : 'Jetzt senden'}
          </button>
        {/if}
        <button type="button" class="btn btn-icon" aria-expanded={open} aria-label="Wartende Einträge zeigen" onclick={() => (open = !open)}>
          <span class="chevron" class:open><ChevronDown size={18} strokeWidth={1.75} aria-hidden="true" /></span>
        </button>
      </div>
      {#if open}
        <ul class="jobs">
          {#each outbox.jobs as job (job.id)}
            <li>
              <span class="grow">
                <span>{job.label}</span>
                <span class="text-muted text-small">
                  {when(job.createdAt)} Uhr{job.error ? ` · ${job.error}` : ''}
                </span>
              </span>
              <button type="button" class="btn btn-icon" aria-label="{job.label} verwerfen" onclick={() => void outbox.discard(job.id)}>
                <Trash2 size={18} strokeWidth={1.75} aria-hidden="true" />
              </button>
            </li>
          {/each}
        </ul>
        <p class="text-muted text-small">Die Einträge liegen auf diesem Gerät und gehen von selbst raus, sobald du Netz hast.</p>
      {/if}
    {/if}
  </section>
{/if}

<style>
  .outbox {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    background: var(--soon-tint);
    color: var(--soon-text);
    font-size: 14px;
  }

  .line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .grow {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
  }

  .line .grow {
    display: inline;
  }

  .chevron {
    display: inline-grid;
    transition: transform 0.15s;
  }

  .chevron.open {
    transform: rotate(180deg);
  }

  .jobs {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
    color: var(--ink);
  }

  .jobs li {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-1) 0;
    border-top: 1px solid var(--line);
  }
</style>
