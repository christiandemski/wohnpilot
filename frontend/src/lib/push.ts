import { api } from './api'

/**
 * Web Push on this device: the browser keeps the subscription, the backend
 * sends the morning summary to it (backend/bin/notify.php).
 */

export type PushState =
  | 'unsupported' // no service worker or Push API, e.g. iPhone outside the home screen app
  | 'off' // server has no VAPID keys yet
  | 'denied' // notifications blocked in the browser
  | 'inactive'
  | 'active'

export function pushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/** True on iPhone and iPad, where push only works in the app added to the home screen. */
export function isAppleMobile(): boolean {
  return /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
  if (!pushSupported()) return null
  // Without a service worker (dev server) ready would wait forever.
  if (!(await navigator.serviceWorker.getRegistration())) return null
  return navigator.serviceWorker.ready
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  return (await registration())?.pushManager.getSubscription() ?? null
}

export async function pushState(): Promise<{ state: PushState; publicKey: string | null }> {
  const { publicKey } = await api.get<{ publicKey: string | null }>('/push')
  if (!pushSupported() || !(await registration())) return { state: 'unsupported', publicKey }
  if (!publicKey) return { state: 'off', publicKey }
  if (Notification.permission === 'denied') return { state: 'denied', publicKey }
  const subscription = await currentSubscription()
  if (subscription) {
    // Keep the server in step, e.g. after the keys of the browser changed.
    await save(subscription)
    return { state: 'active', publicKey }
  }
  return { state: 'inactive', publicKey }
}

/** Asks for permission and subscribes this device. Returns the new state. */
export async function enablePush(publicKey: string): Promise<PushState> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'inactive'
  const reg = await registration()
  if (!reg) return 'unsupported'
  const subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(publicKey) })
  await save(subscription)
  return 'active'
}

export async function disablePush(): Promise<void> {
  const subscription = await currentSubscription()
  if (!subscription) return
  await api.post('/push/unsubscribe', { endpoint: subscription.endpoint })
  await subscription.unsubscribe()
}

export async function testPush(): Promise<'sent' | 'gone' | 'failed'> {
  const subscription = await currentSubscription()
  if (!subscription) return 'gone'
  return (await api.post<{ result: 'sent' | 'gone' | 'failed' }>('/push/test', { endpoint: subscription.endpoint })).result
}

function save(subscription: PushSubscription): Promise<void> {
  const json = subscription.toJSON()
  const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? ['aes128gcm']
  return api.post('/push/subscriptions', {
    endpoint: json.endpoint,
    keys: json.keys,
    contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
  })
}

/** VAPID keys come base64url-encoded; the Push API wants the bytes. */
export function base64UrlToBytes(text: string): Uint8Array<ArrayBuffer> {
  const base64 = (text + '='.repeat((4 - (text.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(base64)
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return bytes
}
