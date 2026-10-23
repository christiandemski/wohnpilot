/**
 * Light / dark: follows prefers-color-scheme unless the person picks one.
 * The choice is a per-device convenience, so localStorage is fine.
 */

export type ThemeChoice = 'system' | 'light' | 'dark'

const KEY = 'wohnpilot-theme'

function read(): ThemeChoice {
  try {
    const value = localStorage.getItem(KEY)
    return value === 'light' || value === 'dark' ? value : 'system'
  } catch {
    return 'system'
  }
}

class Theme {
  choice = $state<ThemeChoice>(read())

  apply(): void {
    const root = document.documentElement
    if (this.choice === 'system') root.removeAttribute('data-theme')
    else root.setAttribute('data-theme', this.choice)
  }

  set(choice: ThemeChoice): void {
    this.choice = choice
    try {
      if (choice === 'system') localStorage.removeItem(KEY)
      else localStorage.setItem(KEY, choice)
    } catch {
      // private mode: the choice lasts for this visit
    }
    this.apply()
  }
}

export const theme = new Theme()
