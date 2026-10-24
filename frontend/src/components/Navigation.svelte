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
  <a class="tab add" href="/ablesen">
    <span class="fab"><Plus size={22} strokeWidth={2.25} aria-hidden="true" /></span>
    <span class="label">Ablesen</span>
  </a>
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
    color: var(--on-inverse);
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
