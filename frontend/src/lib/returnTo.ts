/**
 * The page someone wanted before signing in, e.g. a scanned QR sticker.
 * Sign-in can leave the app (BearID, redirect login), so it waits in
 * sessionStorage of this tab and is taken once after signing in.
 */

const KEY = 'wohnpilot-return-to'

/** Only paths inside the app, never "//other.host" or the API. */
export function isAppPath(path: string): boolean {
  return /^\/(?![/\\])/.test(path) && !path.startsWith('/api/')
}

export function rememberReturn(path: string): void {
  if (!isAppPath(path) || path === '/' || path.startsWith('/?')) return
  try {
    sessionStorage.setItem(KEY, path)
  } catch {
    // no storage: after signing in you land on the overview
  }
}

export function takeReturn(): string | null {
  try {
    const path = sessionStorage.getItem(KEY)
    sessionStorage.removeItem(KEY)
    return path !== null && isAppPath(path) ? path : null
  } catch {
    return null
  }
}
