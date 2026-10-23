<script lang="ts">
  import { Ellipsis, Gauge, House, LayoutGrid, Plus } from '@lucide/svelte'
  import { router } from '../lib/router.svelte'

  /**
   * Phone: floating dark TabBar (docs/design/komponenten/TabBar.md).
   * Desktop: list of modules on the left. Same targets in both.
   */
  const items = [
    { href: '/', label: 'Übersicht', icon: LayoutGrid, module: 'wissen', match: ['uebersicht'] },
    { href: '/zaehler', label: 'Zähler', icon: Gauge, module: 'zaehler', match: ['zaehler', 'zaehler-detail', 'zaehler-neu', 'zaehler-bearbeiten'] },
    { href: '/zuhause', label: 'Zuhause', icon: House, module: 'miete', match: ['zuhause', 'zuhause-neu', 'zuhause-bearbeiten'] },
    { href: '/mehr', label: 'Mehr', icon: Ellipsis, module: 'wissen', match: ['mehr'] },
  ]

  const isCurrent = (item: (typeof items)[number]) => item.match.includes(router.current.name)
</script>

<nav class="tabbar" aria-label="Hauptnavigation">
  {#each items.slice(0, 2) as item (item.href)}
    {@render tab(item)}
  {/each}
  <a class="fab" href="/ablesen" aria-label="Zählerstände erfassen">
    <Plus size={24} strokeWidth={2.25} aria-hidden="true" />
  </a>
  {#each items.slice(2) as item (item.href)}
    {@render tab(item)}
  {/each}
</nav>

{#snippet tab(item: (typeof items)[number])}
  {@const Icon = item.icon}
  <a class="tab" class:active={isCurrent(item)} href={item.href} aria-label={item.label} aria-current={isCurrent(item) ? 'page' : undefined}>
    <Icon size={20} strokeWidth={1.75} aria-hidden="true" />
    {#if isCurrent(item)}<span>{item.label}</span>{/if}
  </a>
{/snippet}

<nav class="sidenav" aria-label="Hauptnavigation">
  <a class="brand" href="/">Wohnpilot</a>
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
  <a class="btn btn-primary" href="/ablesen">
    <Plus size={20} strokeWidth={2.25} aria-hidden="true" />
    Ablesen
  </a>
</nav>

<style>
  .tabbar {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: calc(var(--space-6) + env(safe-area-inset-bottom));
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: var(--tabbar-height);
    max-width: 480px;
    margin: 0 auto;
    padding: 0 10px;
    border-radius: var(--radius-full);
    background: color-mix(in srgb, var(--inverse) 94%, transparent);
    box-shadow: var(--shadow-tabbar);
  }

  .tab {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 48px;
    height: 48px;
    border-radius: var(--radius-full);
    color: var(--on-inverse-muted);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
  }

  /* Light pill in both themes (TabBar.md: surface or on-inverse as ground) */
  .tab.active {
    padding: 0 14px;
    background: var(--on-inverse);
    color: var(--inverse);
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
