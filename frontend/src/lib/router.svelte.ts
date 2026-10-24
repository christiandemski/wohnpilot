/**
 * A small history router. Routes are path patterns like "/zaehler/:id";
 * links are plain <a href> and get intercepted here.
 */

export interface Match {
  name: string
  params: Record<string, string>
}

const routes: [string, string][] = [
  ['uebersicht', '/'],
  ['ablesen', '/ablesen'],
  ['ablesen-einzeln', '/ablesen/:id'],
  ['zaehler', '/zaehler'],
  ['zaehler-neu', '/zaehler/neu'],
  ['zaehler-detail', '/zaehler/:id'],
  ['zaehler-bearbeiten', '/zaehler/:id/bearbeiten'],
  ['zuhause', '/zuhause'],
  ['zuhause-neu', '/zuhause/neu'],
  ['zuhause-bearbeiten', '/zuhause/bearbeiten'],
  ['vertraege', '/vertraege'],
  ['vertrag-neu', '/vertraege/neu'],
  ['vertrag-detail', '/vertraege/:id'],
  ['vertrag-bearbeiten', '/vertraege/:id/bearbeiten'],
  ['mietvertrag', '/mietvertrag'],
  ['mietvertrag-bearbeiten', '/mietvertrag/bearbeiten'],
  ['fristen', '/fristen'],
  ['frist-neu', '/fristen/neu'],
  ['frist-detail', '/fristen/:id'],
  ['frist-bearbeiten', '/fristen/:id/bearbeiten'],
  ['wartung', '/wartung'],
  ['wartung-neu', '/wartung/neu'],
  ['wartung-detail', '/wartung/:id'],
  ['wartung-bearbeiten', '/wartung/:id/bearbeiten'],
  ['termine', '/termine'],
  ['termin-neu', '/termine/neu'],
  ['muell', '/termine/muell'],
  ['termin-detail', '/termine/:id'],
  ['termin-bearbeiten', '/termine/:id/bearbeiten'],
  ['maengel', '/maengel'],
  ['mangel-neu', '/maengel/neu'],
  ['mangel-detail', '/maengel/:id'],
  ['mangel-bearbeiten', '/maengel/:id/bearbeiten'],
  ['geraete', '/geraete'],
  ['geraet-neu', '/geraete/neu'],
  ['geraet-detail', '/geraete/:id'],
  ['geraet-bearbeiten', '/geraete/:id/bearbeiten'],
  ['einstellungen', '/einstellungen'],
  ['kontakte', '/kontakte'],
  ['kontakt-neu', '/kontakte/neu'],
  ['kontakt-detail', '/kontakte/:id'],
  ['kontakt-bearbeiten', '/kontakte/:id/bearbeiten'],
  ['wissen', '/wissen'],
  ['wissen-neu', '/wissen/neu'],
  ['wissen-detail', '/wissen/:id'],
  ['wissen-bearbeiten', '/wissen/:id/bearbeiten'],
  ['notfall', '/notfall'],
  ['suche', '/suche'],
  ['aufkleber', '/aufkleber'],
  ['eingang', '/eingang'],
  ['bericht', '/bericht'],
  ['kiosk', '/kiosk'],
  ['nebenkosten', '/nebenkosten'],
  ['nebenkosten-neu', '/nebenkosten/neu'],
  ['nebenkosten-detail', '/nebenkosten/:id'],
  ['nebenkosten-bearbeiten', '/nebenkosten/:id/bearbeiten'],
  ['qr', '/q/:kind/:id'],
  ['mehr', '/mehr'],
]

export function match(path: string): Match {
  const parts = path.replace(/\/+$/, '').split('/').filter(Boolean)
  for (const [name, pattern] of routes) {
    const patternParts = pattern.split('/').filter(Boolean)
    if (patternParts.length !== parts.length) continue
    const params: Record<string, string> = {}
    const ok = patternParts.every((p, i) => {
      if (p.startsWith(':')) {
        params[p.slice(1)] = decodeURIComponent(parts[i])
        return true
      }
      return p === parts[i]
    })
    if (ok) return { name, params }
  }
  return { name: 'nicht-gefunden', params: {} }
}

class Router {
  path = $state(window.location.pathname)
  query = $state(new URLSearchParams(window.location.search))
  current = $derived(match(this.path))

  constructor() {
    window.addEventListener('popstate', () => this.sync())
    document.addEventListener('click', (event) => {
      const link = (event.target as Element | null)?.closest?.('a')
      if (
        !link ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        link.target ||
        link.hasAttribute('download') ||
        link.origin !== window.location.origin ||
        link.pathname.startsWith('/api/')
      ) {
        return
      }
      event.preventDefault()
      this.go(link.pathname + link.search)
    })
  }

  go(to: string, replace = false): void {
    if (to === window.location.pathname + window.location.search) return
    history[replace ? 'replaceState' : 'pushState'](null, '', to)
    this.sync()
    window.scrollTo(0, 0)
  }

  back(fallback = '/'): void {
    if (history.length > 1 && document.referrer.startsWith(window.location.origin)) history.back()
    else this.go(fallback)
  }

  private sync(): void {
    this.path = window.location.pathname
    this.query = new URLSearchParams(window.location.search)
  }
}

export const router = new Router()
