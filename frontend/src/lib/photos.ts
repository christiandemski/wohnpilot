import { api } from './api'

/** Photos of defects, devices and maintenance tasks (backend PhotoService). */

export type PhotoOwner = 'mangel' | 'geraet' | 'wartung' | 'wissen' | 'vertrag' | 'mietvertrag' | 'frist'

export interface Photo {
  path: string
  alt: string
  url: string
}

/** Long side in pixels after shrinking: sharp enough for a type plate, a few hundred KB. */
export const MAX_SIDE = 2000
const QUALITY = 0.85

/** Size after shrinking to at most $max on the long side; never enlarges. */
export function fitWithin(width: number, height: number, max = MAX_SIDE): { width: number; height: number } {
  const scale = Math.min(1, max / Math.max(width, height))
  return { width: Math.round(width * scale), height: Math.round(height * scale) }
}

/**
 * Turns any photo the browser can open (also HEIC on iPhones) into a JPEG of
 * at most MAX_SIDE pixels, upright as the camera held it.
 */
export async function shrink(file: File): Promise<Blob> {
  const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  const size = fitWithin(bitmap.width, bitmap.height)
  const canvas = document.createElement('canvas')
  canvas.width = size.width
  canvas.height = size.height
  const context = canvas.getContext('2d')!
  // JPEG has no transparency: white behind it instead of black.
  context.fillStyle = '#ffffff'
  context.fillRect(0, 0, size.width, size.height)
  context.drawImage(bitmap, 0, 0, size.width, size.height)
  bitmap.close()
  return new Promise((resolve, reject) =>
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('toBlob'))), 'image/jpeg', QUALITY),
  )
}

const base = (type: PhotoOwner, id: string) => `/photos/${type}/${encodeURIComponent(id)}`

export async function uploadPhoto(type: PhotoOwner, id: string, file: File, alt = ''): Promise<Photo[]> {
  const form = new FormData()
  form.append('foto', await shrink(file), 'foto.jpg')
  form.append('alt', alt)
  return (await api.upload<{ photos: Photo[] }>(base(type, id), form)).photos
}

export async function deletePhoto(type: PhotoOwner, id: string, path: string): Promise<Photo[]> {
  return (await api.delete<{ photos: Photo[] }>(`${base(type, id)}?path=${encodeURIComponent(path)}`)).photos
}
