<script lang="ts">
  import { formatDate, formatDay } from '../lib/format'
  import { ruler } from '../lib/contracts'
  import type { ContractTerm } from '../lib/types'

  /** Fristen-Lineal on the contract page: period start (or a cut), today, notice day, end of term. */
  let { term, today }: { term: ContractTerm; today: string } = $props()

  const r = $derived(ruler(term, today))
  const pct = (share: number) => `${(share * 100).toFixed(1)}%`
  // Keep the notice label clear of the end labels.
  const labelAt = (share: number) => pct(Math.min(0.78, Math.max(0.22, share)))
</script>

{#if r}
  <figure class="lineal" aria-label="Kündigen bis {formatDate(r.noticeBy)}, Laufzeit bis {formatDate(r.to)}, heute ist der {formatDate(today)}">
    <div class="today-label" aria-hidden="true"><span style:left={pct(Math.min(0.94, Math.max(0.06, r.today)))}>heute</span></div>
    <div class="line" aria-hidden="true">
      <span class="done" style:width={pct(r.today)}></span>
      <span class="window {term.status}" style:left={pct(r.notice)}></span>
      <span class="tick today" style:left={pct(r.today)}></span>
      <span class="tick notice {term.status}" style:left={pct(r.notice)}></span>
    </div>
    <div class="labels" aria-hidden="true">
      <span class="num start">{r.fromIsStart ? formatDay(r.from) : '…'}</span>
      <span class="num notice-label {term.status}" style:left={labelAt(r.notice)}>{formatDay(r.noticeBy)}</span>
      <span class="num end">{formatDay(r.to)}</span>
    </div>
  </figure>
{/if}

<style>
  .lineal {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0 6px;
  }

  .today-label {
    position: relative;
    height: 16px;
  }

  .today-label span {
    position: absolute;
    transform: translateX(-50%);
    font-size: 11px;
    line-height: 16px;
    font-weight: 600;
    color: var(--ink);
  }

  .line {
    position: relative;
    height: 6px;
    border-radius: var(--radius-full);
    background: var(--paper);
  }

  .done {
    position: absolute;
    inset: 0 auto 0 0;
    border-radius: var(--radius-full);
    background: var(--line-strong);
  }

  /* From the notice day the term can no longer be ended this time */
  .window {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    border-radius: 0 var(--radius-full) var(--radius-full) 0;
    background: var(--ok-tint);
  }

  .window.soon {
    background: var(--soon-tint);
  }

  .window.due {
    background: var(--due-tint);
  }

  .tick {
    position: absolute;
    top: 50%;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    transform: translate(-50%, -50%);
  }

  .tick.today {
    width: 4px;
    height: 18px;
    border-radius: var(--radius-full);
    background: var(--ink);
  }

  .tick.notice {
    border: 3px solid var(--surface);
    background: var(--ok);
  }

  .tick.notice.soon {
    background: var(--soon);
  }

  .tick.notice.due {
    background: var(--due);
  }

  .labels {
    position: relative;
    height: 18px;
    font-size: 12px;
    line-height: 18px;
    color: var(--ink-muted);
  }

  .labels span {
    position: absolute;
  }

  .start {
    left: 0;
    transform: translateX(-6px);
  }

  .end {
    right: 0;
    transform: translateX(6px);
  }

  .notice-label {
    transform: translateX(-50%);
    font-weight: 600;
    color: var(--ok);
  }

  .notice-label.soon {
    color: var(--soon-text);
  }

  .notice-label.due {
    color: var(--due);
  }
</style>
