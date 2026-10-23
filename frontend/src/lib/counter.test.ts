import { describe, expect, it } from 'vitest'
import { check, fromWheels, placesFor, toWheels, turn } from './counter'

describe('wheels', () => {
  it('splits a value into wheels and back', () => {
    const wheels = toWheels(23702, 5, 1)
    expect(wheels.digits).toEqual([2, 3, 7, 0, 2, 0])
    expect(fromWheels(wheels)).toBe(23702)
    expect(fromWheels(toWheels(1234.567, 5, 3))).toBe(1234.567)
    expect(toWheels(42.5, 5, 1).digits).toEqual([0, 0, 0, 4, 2, 5])
  })

  it('turns one wheel round without carrying', () => {
    let wheels = toWheels(23709, 5, 1)
    wheels = turn(wheels, 4, 1)
    expect(fromWheels(wheels)).toBe(23700)
    wheels = turn(wheels, 0, -1)
    expect(fromWheels(wheels)).toBe(13700)
    expect(fromWheels(turn(toWheels(0, 5, 1), 0, -1))).toBe(90000)
  })

  it('shows more places when the value needs them', () => {
    expect(placesFor(123456, 5)).toBe(6)
    expect(placesFor(42, 5)).toBe(5)
  })
})

describe('check, same cases as the backend', () => {
  const last = { value: 23702, days: 8 }
  const rate = 710 / 84

  it('accepts a usual reading', () => {
    const result = check(23770, last, rate)
    expect(result.result).toBe('ok')
    expect(result.delta).toBe(68)
    expect(result.perDay).toBe(8.5)
  })

  it('warns when lower than the last reading', () => {
    expect(check(23700, last, rate).result).toBe('lower')
  })

  it('warns when unusually high', () => {
    expect(check(23950, last, rate).result).toBe('high')
  })

  it('has nothing to compare for the first reading or without history', () => {
    expect(check(5, null, null).result).toBe('first')
    expect(check(9999, { value: 1, days: 23 }, null).result).toBe('ok')
  })

  it('treats float noise as equal', () => {
    expect(check(0.1 + 0.2, { value: 0.3, days: 1 }, 1).result).toBe('ok')
  })
})
