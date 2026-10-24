<script lang="ts">
  import { Ellipsis, Gauge, House, LayoutGrid, Plus, Search } from '@lucide/svelte'
  import { router } from '../lib/router.svelte'
  import ErfassenSheet from './ErfassenSheet.svelte'

  let sheet: ReturnType<typeof ErfassenSheet> | undefined = $state()

  /**
   * Phone: floating dark TabBar (docs/design/komponenten/TabBar.md).
   * Desktop: list of modules on the left. Same targets in both.
   */
  const items = [
    { href: '/', label: 'Übersicht', icon: LayoutGrid, module: 'wissen', prefixes: ['uebersicht'] },
    { href: '/zaehler', label: 'Zähler', icon: Gauge, module: 'zaehler', prefixes: ['zaehler'] },
    {
      href: '/zuhause',
      label: 'Zuhause',
      icon: House,
      module: 'miete',
      // Everything about the house itself
      prefixes: ['zuhause', 'mietvertrag', 'wartung', 'termine', 'termin', 'muell', 'maengel', 'mangel', 'geraete', 'geraet', 'wissen', 'notfall', 'aufkleber', 'nebenkosten'],
    },
    { href: '/mehr', label: 'Mehr', icon: Ellipsis, module: 'wissen', prefixes: ['mehr', 'vertraege', 'vertrag', 'fristen', 'frist', 'einstellungen', 'kontakte', 'kontakt', 'eingang'] },
  ]

  /** Route names like "mangel-detail" belong to the target with prefix "mangel". */
  const isCurrent = (item: (typeof items)[number]) => item.prefixes.some((p) => router.current.name === p || router.current.name.startsWith(p + '-'))
</script>

<nav class="tabbar" aria-label="Hauptnavigation">
  {#each items.slice(0, 2) as item (item.href)}
    {@render tab(item)}
  {/each}
  <button type="button" class="tab add" aria-haspopup="dialog" onclick={() => sheet?.open()}>
    <span class="fab"><Plus size={22} strokeWidth={2.25} aria-hidden="true" /></span>
    <span class="label">Erfassen</span>
  </button>
  {#each items.slice(2) as item (item.href)}
    {@render tab(item)}
  {/each}
</nav>

{#snippet tab(item: (typeof items)[number])}
  {@const Icon = item.icon}
  <a class="tab" class:active={isCurrent(item)} href={item.href} aria-current={isCurrent(item) ? 'page' : undefined}>
    <span class="icon"><Icon size={20} strokeWidth={1.75} aria-hidden="true" /></span>
    <span class="label">{item.label}</span>
  </a>
{/snippet}

<nav class="sidenav" aria-label="Hauptnavigation">
  <a class="brand" href="/">Wohnpilot</a>
  <a class="side-search" href="/suche">
    <Search size={18} strokeWidth={1.75} aria-hidden="true" />
    Suchen
  </a>
  <ul>
    {#each items as item (item.href)}
      {@const Icon = item.icon}
      <li>
        <a href={item.href} class:active={isCurrent(item)} aria-current={isCurrent(item) ? 'page' : undefined}>
          <span class="side-tile" style:--tile-bg="var(--module-{item.module}-tint)" style:--tile-fg="var(--module-{item.module}-ink)">
            <Icon size={18} strokeWidth={1.75} aria-hidden="true" />
          </span>
          {item.label}
        </a>
      </li>
    {/each}
  </ul>
  <button type="button" class="btn btn-primary" aria-haspopup="dialog" onclick={() => sheet?.open()}>
    <Plus size={20} strokeWidth={2.25} aria-hidden="true" />
    Erfassen
  </button>
</nav>

<ErfassenSheet bind:this={sheet} />

<style>
  .tabbar {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: calc(var(--space-3) + env(safe-area-inset-bottom));
    z-index: 10;
    display: flex;
    align-items: center;
    height: var(--tabbar-height);
    max-width: 480px;
    margin: 0 auto;
    padding: 0 var(--space-2);
    border-radius: var(--radius-full);
    background: color-mix(in srgb, var(--inverse) 94%, transparent);
    box-shadow: var(--shadow-tabbar);
  }

  /* Icon over word, the whole column is the touch target (TabBar.md) */
  .tab {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-width: var(--touch-min);
    height: 100%;
    color: var(--on-inverse-muted);
    text-decoration: none;
  }

  .icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 28px;
    border-radius: var(--radius-full);
  }

  .label {
    font-size: 11px;
    line-height: 14px;
    font-weight: 600;
  }

  .tab.active {
    color: var(--on-inverse);
  }

  .tab.active .icon {
    background: color-mix(in srgb, var(--on-inverse) 16%, transparent);
  }

  .fab {
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--fab);
    height: var(--fab);
    border-radius: var(--radius-full);
    background: var(--accent);
    color: var(--on-accent);
  }

  .add {
    gap: 0;
    padding: 0;
    border: 0;
    background: none;
    color: var(--on-inverse);
    font: inherit;
    cursor: pointer;
  }

  .sidenav {
    display: none;
  }

  @media (min-width: 900px) {
    .tabbar {
      display: none;
    }

    .sidenav {
      position: sticky;
      top: 0;
      display: flex;
      flex-direction: column;
      gap: var(--space-4);
      height: 100dvh;
      padding: var(--space-6) var(--space-4);
    }

    .brand {
      padding: 0 var(--space-2);
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -0.01em;
      text-decoration: none;
    }

    .side-search {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      min-height: var(--touch-min);
      padding: 0 var(--space-3);
      border-radius: var(--radius-full);
      box-shadow: inset 0 0 0 1px var(--line-strong);
      color: var(--ink-muted);
      text-decoration: none;
      font-size: 14px;
    }

    ul {
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    ul a {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      min-height: 48px;
      padding: 0 var(--space-2);
      border-radius: var(--radius-sm);
      text-decoration: none;
      font-weight: 500;
    }

    ul a:hover {
      background: var(--surface);
    }

    ul a.active {
      background: var(--surface);
      font-weight: 600;
    }

    .side-tile {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: var(--radius-xs);
      background: var(--tile-bg);
      color: var(--tile-fg);
    }
  }
</style>
