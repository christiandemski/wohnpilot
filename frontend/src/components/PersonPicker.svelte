<script lang="ts">
  import { people } from '../lib/people.svelte'

  /**
   * Who of the household takes care of it (`person`). Everyone still sees
   * everything; the name shows up in lists, push and calendar. Hidden while
   * the household has one person only.
   */
  let { value = $bindable(''), id = 'person' }: { value: string; id?: string } = $props()

  void people.load()
</script>

{#if people.several || value}
  <div class="field">
    <label for={id}>Wer kümmert sich? <span class="optional">(optional)</span></label>
    <select {id} class="input" bind:value>
      <option value="">Niemand bestimmtes</option>
      {#each people.list as person (person.id)}
        <option value={person.id}>{person.name}</option>
      {/each}
      {#if value && !people.list.some((p) => p.id === value)}<option {value}>{value}</option>{/if}
    </select>
    <span class="hint">Alle sehen es weiterhin; der Name steht in Übersicht, Mitteilung und Kalender.</span>
  </div>
{/if}
