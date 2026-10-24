import { describe, expect, it } from 'vitest'
import { kindOf, splitText, titleFrom } from './inbox.svelte'

describe('inbox', () => {
  it('makes a title from the file name', () => {
    expect(titleFrom('Brief_Vermieter-2026.pdf', 'pdf', '24.10.')).toBe('Brief Vermieter 2026')
    expect(titleFrom('IMG_20261024_101500.jpg', 'image', '24.10.')).toBe('Foto vom 24.10.')
    expect(titleFrom('PXL_20261024.jpg', 'image', '24.10.')).toBe('Foto vom 24.10.')
    expect(titleFrom('', 'pdf', '24.10.')).toBe('Dokument vom 24.10.')
  })

  it('takes photos and PDFs only', () => {
    expect(kindOf('image/heic')).toBe('image')
    expect(kindOf('application/pdf')).toBe('pdf')
    expect(kindOf('application/zip')).toBeNull()
  })

  it('splits a shared text into title and text', () => {
    expect(splitText('  Heizung im Bad bleibt kalt\nSeit gestern, Thermostat auf 5.')).toEqual({
      titel: 'Heizung im Bad bleibt kalt',
      text: 'Heizung im Bad bleibt kalt\nSeit gestern, Thermostat auf 5.',
      rest: 'Seit gestern, Thermostat auf 5.',
    })
    expect(splitText('x'.repeat(100)).titel).toHaveLength(79)
  })
})
