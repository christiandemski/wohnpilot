<script lang="ts">
  import { Printer } from '@lucide/svelte'
  import { api, ApiError } from '../lib/api'
  import { formatDate } from '../lib/format'
  import { people } from '../lib/people.svelte'
  import { Resource } from '../lib/resource.svelte'
  import type { ChecklistDetail } from '../lib/types'
  import DeleteEntry from '../components/DeleteEntry.svelte'
  import LoadState from '../components/LoadState.svelte'
  import PageHeader from '../components/PageHeader.svelte'

  /** A move-out or move-in checklist to tick off; also on paper. */
  let { id }: { id: string } = $props()

  const list = new Resource(() => api.get<ChecklistDetail>(`/checklists/${encodeURIComponent(id)}`))
  let busy = $state<number | null>(null)
  let message = $state('')

  const title = (c: ChecklistDetail) => (c.art === 'auszug' ? 'Auszug' : c.art === 'einzug' ? 'Einzug' : 'Checkliste')

  async function tick(index: number, text: string, done: boolean) {
    busy = index
    message = ''
    try {
      list.data = await api.put<ChecklistDetail>(`/checklists/${encodeURIComponent(id)}/items/${index}`, { text, done })
    } catch (e) {
      if (e instanceof ApiError && e.status === 409 && e.details.checklist) {
        list.data = e.details.checklist as ChecklistDetail
        message = 'Die Liste wurde inzwischen woanders geändert. Hier ist der neue Stand, hak bitte noch einmal ab.'
      } else {
        message = e instanceof ApiError ? e.text : 'Das hat nicht geklappt.'
        await list.reload()
      }
    } finally {
      busy = null
    }
  }
</script>

<PageHeader back="/umzug">
  {#snippet action()}
    {#if list.data}
      <button type="button" class="btn btn-secondary" onclick={() => window.print()}>
        <Printer size={18} strokeWidth={1.75} aria-hidden="true" />
        Drucken
      </button>
    {/if}
  {/snippet}
</PageHeader>

<LoadState resource={list} />

{#if list.data}
  {@const c = list.data}
  <header class="page-head">
    <h1 class="page-title">{title(c)}</h1>
    <p class="text-muted">
      {c.home?.adresse ?? (c.home?.art === 'haus' ? 'Haus' : 'Wohnung')}{c.datum ? ` · ${formatDate(c.datum)}` : ''}
    </p>
    <p class="progress num" role="status">{c.done} von {c.total} erledigt</p>
  </header>

  {#if message}<p class="save-error" role="alert">{message}</p>{/if}

  {#each c.sections as section, s (s)}
    <section class="card" aria-label={section.title ?? title(c)}>
      {#if section.title}<h2 class="group-title">{section.title}</h2>{/if}
      <ul class="tasks">
        {#each section.items as item (item.index)}
          <li>
            <label class:done={item.done}>
              <input
                type="checkbox"
                checked={item.done}
                disabled={!people.writes || busy !== null}
                onchange={(e) => tick(item.index, item.text, e.currentTarget.checked)}
              />
              <span>{item.text}</span>
            </label>
          </li>
        {/each}
      </ul>
    </section>
  {/each}

  {#if c.total === 0}
    <div class="card"><p>Die Liste hat keine Aufgaben. In der Notes-App schreibst du sie als <span class="num">- [ ] Aufgabe</span>.</p></div>
  {/if}

  <p class="muted small no-print">Die Liste ist eine Notiz in deiner Nextcloud. Dort kannst du Punkte ergänzen oder streichen.</p>
  <DeleteEntry type="checkliste" id={c.id} etag={c.etag} what="Checkliste" title="{title(c)}-Checkliste" back="/umzug" onChanged={() => list.reload()} />
{/if}

<style>
  .progress {
    margin-top: var(--space-1);
    font-family: var(--font-num);
    font-variant-numeric: tabular-nums;
    font-weight: 600;
  }

  .tasks {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .tasks li + li {
    border-top: 1px solid var(--line);
  }

  .tasks label {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    min-height: 44px;
    padding: var(--space-3) 0;
    cursor: pointer;
  }

  .tasks input {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    margin: 0;
    accent-color: var(--accent);
  }

  .done span {
    color: var(--ink-muted);
    text-decoration: line-through;
  }

  .muted {
    color: var(--ink-muted);
  }

  .small {
    font-size: 14px;
  }

  @media print {
    .done span {
      text-decoration: none;
    }
  }
</style>
