import { describe, expect, it } from 'vitest'
import { fileUrl, noteFolder, resolveNotePath, rewriteTargets } from './markdown'

const folder = 'objekte/efh-musterstrasse/wissen'

describe('note paths', () => {
  it('resolves images and documents relative to the note', () => {
    expect(resolveNotePath(folder, '../../../anhaenge/wissen/haupthahn/2026-10-24-1.jpg')).toBe('anhaenge/wissen/haupthahn/2026-10-24-1.jpg')
    expect(resolveNotePath(folder, '.attachments.12/Foto%20Bad.png')).toBe('objekte/efh-musterstrasse/wissen/.attachments.12/Foto Bad.png')
    expect(resolveNotePath(folder, 'https://example.org/a.jpg')).toBeNull()
    expect(resolveNotePath(folder, '#abschnitt')).toBeNull()
    expect(resolveNotePath(folder, '../../../../aussen.jpg')).toBeNull()
    expect(noteFolder('objekte/efh-musterstrasse/wissen/haupthahn.md')).toBe(folder)
  })

  it('points relative targets to the file API', () => {
    const image = { type: 'image', href: '../../../anhaenge/wissen/a b.jpg' }
    rewriteTargets(image, folder)
    expect(image.href).toBe('/api/files/anhaenge/wissen/a%20b.jpg')

    const external = { type: 'link', href: 'https://stadtwerke.example.org' }
    rewriteTargets(external, folder)
    expect(external.href).toBe('https://stadtwerke.example.org')
    expect(fileUrl('anhaenge/x/ä.pdf')).toBe('/api/files/anhaenge/x/%C3%A4.pdf')
  })
})
