<script lang="ts">
  import { CircleAlert } from '@lucide/svelte'

  /**
   * Shown when a field was changed both here and elsewhere (e.g. in the
   * Notes app). Nothing has been overwritten; the person decides.
   */
  let {
    fields,
    labels,
    format = (value: unknown) => (value === null || value === undefined || value === '' ? '–' : String(value)),
    onKeepMine,
    onTakeTheirs,
  }: {
    fields: Record<string, { mine: unknown; theirs: unknown }>
    labels: Record<string, string>
    format?: (value: unknown) => string
    onKeepMine: () => void
    onTakeTheirs: () => void
  } = $props()
</script>

<section class="conflict" role="alert" aria-labelledby="conflict-title">
  <h2 id="conflict-title">
    <CircleAlert size={20} strokeWidth={2} aria-hidden="true" />
    Inzwischen woanders geändert
  </h2>
  <p>Diese Felder wurden auch in der Nextcloud geändert. Es ist noch nichts überschrieben.</p>
  <table>
    <thead><tr><th scope="col">Feld</th><th scope="col">Deine Eingabe</th><th scope="col">In der Nextcloud</th></tr></thead>
    <tbody>
      {#each Object.entries(fields) as [field, values] (field)}
        <tr>
          <th scope="row">{labels[field] ?? field}</th>
          <td>{format(values.mine)}</td>
          <td>{format(values.theirs)}</td>
        </tr>
      {/each}
    </tbody>
  </table>
  <div class="actions">
    <button type="button" class="btn btn-inverse" onclick={onKeepMine}>Meine übernehmen</button>
    <button type="button" class="btn btn-secondary on-surface" onclick={onTakeTheirs}>Nextcloud-Stand laden</button>
  </div>
</section>

<style>
  .conflict {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border-radius: var(--radius-lg);
    background: var(--soon-tint);
  }

  h2 {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 15px;
    font-weight: 700;
    color: var(--soon-text);
  }

  table {
    border-collapse: collapse;
    font-size: 13px;
  }

  th,
  td {
    padding: 6px 4px;
    border-bottom: 1px solid var(--line-strong);
    text-align: left;
  }

  thead th {
    font-weight: 600;
  }

  tbody th {
    font-weight: 500;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }
</style>
