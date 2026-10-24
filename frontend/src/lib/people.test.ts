import { describe, expect, it } from 'vitest'
import { signInWay } from './people.svelte'

describe('signInWay', () => {
  it('names how someone gets in', () => {
    expect(signInWay({ bearid: '42', email: null, emergency: false })).toBe('BearID')
    expect(signInWay({ bearid: null, email: 'a@example.org', emergency: true })).toBe('E-Mail mit Notzugang')
    expect(signInWay({ bearid: '42', email: 'a@example.org', emergency: false })).toBe('BearID oder E-Mail')
    expect(signInWay({ bearid: null, email: null, emergency: false })).toBe('Noch keine Anmeldung')
  })
})
