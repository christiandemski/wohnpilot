<script lang="ts">
  import { noteFolder, renderNote } from '../lib/markdown'

  /** The text of a note as formatted page: lists, tables, photos and links like in the Notes app. */
  let { text, path }: { text: string; path: string } = $props()

  const html = $derived(renderNote(text, noteFolder(path)))
</script>

<!-- Sanitized by DOMPurify in renderNote -->
<div class="markdown">{@html html}</div>

<style>
  .markdown {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    font-size: 15px;
    line-height: 22px;
    overflow-wrap: anywhere;
  }

  .markdown :global(:is(p, ul, ol, table, h1, h2, h3, blockquote)) {
    margin: 0;
  }

  .markdown :global(:is(h1, h2, h3)) {
    font-size: 17px;
    line-height: 22px;
    font-weight: 700;
  }

  .markdown :global(:is(ul, ol)) {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    padding-left: 1.4em;
  }

  .markdown :global(table) {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }

  .markdown :global(:is(th, td)) {
    padding: 6px 4px;
    border-bottom: 1px solid var(--line);
    text-align: left;
    vertical-align: top;
  }

  .markdown :global(th) {
    color: var(--ink-muted);
    font-weight: 600;
  }

  .markdown :global(img) {
    max-width: 100%;
    border-radius: var(--radius-sm);
  }

  .markdown :global(blockquote) {
    padding-left: var(--space-3);
    border-left: 3px solid var(--line-strong);
    color: var(--ink-muted);
  }

  .markdown :global(code) {
    font-family: var(--font-num);
  }
</style>
