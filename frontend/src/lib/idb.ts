/**
 * The app's IndexedDB on this device: "outbox" (writes waiting for the
 * network) and "eingang" (files and texts shared to the app). The service
 * worker opens the same database in public/share-sw.js; keep both in step.
 */

const DB = 'wohnpilot'
const VERSION = 2
export type StoreName = 'outbox' | 'eingang'

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB, VERSION)
    open.onupgradeneeded = () => {
      for (const name of ['outbox', 'eingang']) {
        if (!open.result.objectStoreNames.contains(name)) open.result.createObjectStore(name, { keyPath: 'id' })
      }
    }
    open.onsuccess = () => resolve(open.result)
    open.onerror = () => reject(open.error)
  })
}

export async function inStore<T>(name: StoreName, mode: IDBTransactionMode, work: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb()
  try {
    return await new Promise<T>((resolve, reject) => {
      const tx = db.transaction(name, mode)
      const req = work(tx.objectStore(name))
      tx.oncomplete = () => resolve(req.result)
      tx.onerror = () => reject(tx.error)
    })
  } finally {
    db.close()
  }
}
