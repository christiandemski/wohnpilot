import { describe, expect, it } from 'vitest'
import { CHOICES, ordered } from './capture'

describe('ordered', () => {
  it('puts the last used choice on top', () => {
    expect(ordered('termin').map((c) => c.id)).toEqual(['termin', 'ablesen', 'mangel', 'wartung', 'frist', 'geraet'])
  })

  it('keeps the usual order without or with an unknown last choice', () => {
    expect(ordered(null)).toEqual(CHOICES)
    expect(ordered('gibt-es-nicht')).toEqual(CHOICES)
  })
})
