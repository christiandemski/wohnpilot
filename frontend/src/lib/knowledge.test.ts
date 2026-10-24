import { describe, expect, it } from 'vitest'
import { isPlaceholder, routeFor, telHref } from './knowledge'

describe('knowledge helpers', () => {
  it('dials numbers as written', () => {
    expect(telHref('0800 1234567')).toBe('tel:08001234567')
    expect(telHref('+49 (30) 123-456')).toBe('tel:+4930123456')
  })

  it('leaves out example placeholders', () => {
    expect(isPlaceholder('[Telefon]')).toBe(true)
    expect(isPlaceholder('0800 1234567')).toBe(false)
  })

  it('opens every kind of entry', () => {
    expect(routeFor('mangel', 'fenster-schlafzimmer')).toBe('/maengel/fenster-schlafzimmer')
    expect(routeFor('mietvertrag', 'x')).toBe('/mietvertrag')
    expect(routeFor('haushalt', 'haushalt')).toBeNull()
  })
})
