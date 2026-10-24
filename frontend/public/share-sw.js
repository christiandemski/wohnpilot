/*
 * Share target (Android): the share menu POSTs files and text to
 * /eingang/teilen. They go into the device's inbox ("eingang" in the
 * IndexedDB "wohnpilot", same layout as src/lib/idb.ts) and the app opens
 * /eingang to assign them. Nothing leaves the phone before that.
 */

function openInbox() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open('wohnpilot', 2)
    open.onupgradeneeded = () => {
      for (const name of ['outbox', 'eingang']) {
        if (!open.result.objectStoreNames.contains(name)) open.result.createObjectStore(name, { keyPath: 'id' })
      }
    }
    open.onsuccess = () => resolve(open.result)
    open.onerror = () => reject(open.error)
  })
}

async function keepShared(form) {
  const now = new Date().toISOString()
  const items = []
  for (const file of form.getAll('dateien')) {
    if (!(file instanceof File) || file.size === 0) continue
    const kind = file.type === 'application/pdf' ? 'pdf' : file.type.startsWith('image/') ? 'image' : null
    if (kind) items.push({ id: crypto.randomUUID(), kind, name: file.name, blob: file, createdAt: now })
  }
  const text = [form.get('title'), form.get('text'), form.get('url')].filter((v) => typeof v === 'string' && v.trim() !== '').join('\n')
  if (text) items.push({ id: crypto.randomUUID(), kind: 'text', name: '', text, createdAt: now })

  const db = await openInbox()
  await new Promise((resolve, reject) => {
    const tx = db.transaction('eingang', 'readwrite')
    for (const item of items) tx.objectStore('eingang').put(item)
    tx.oncomplete = resolve
    tx.onerror = () => reject(tx.error)
  })
  db.close()
  return items.length
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)
  if (event.request.method !== 'POST' || url.origin !== self.location.origin || url.pathname !== '/eingang/teilen') return
  event.respondWith(
    (async () => {
      try {
        const count = await keepShared(await event.request.formData())
        return Response.redirect(`/eingang?geteilt=${count}`, 303)
      } catch {
        return Response.redirect('/eingang?geteilt=fehler', 303)
      }
    })(),
  )
})
