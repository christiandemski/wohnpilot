import { describe, expect, it } from 'vitest'
import { base64UrlToBytes } from './push'

describe('base64UrlToBytes', () => {
  it('reads base64url without padding', () => {
    expect([...base64UrlToBytes('AQID_-8')]).toEqual([1, 2, 3, 255, 239])
    expect([...base64UrlToBytes('')]).toEqual([])
  })
})
