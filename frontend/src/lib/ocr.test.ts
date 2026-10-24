import { describe, expect, it } from 'vitest'
import { candidates, pickReading } from './ocr'

const strom = { places: 5, decimals: 1, last: 23702.0, expected: 23760.0, number: '1ESY1160123456' }

describe('reading suggestion from OCR text', () => {
  it('reads the wheels in a row with the red decimal last', () => {
    expect(pickReading('237581', strom)).toBe(23758.1)
    expect(pickReading('23758 1', strom)).toBe(23758.1)
  })

  it('takes a printed decimal mark, German or English', () => {
    expect(pickReading('23.758,1', strom)).toBe(23758.1)
    expect(pickReading('23758,1', strom)).toBe(23758.1)
    expect(pickReading('23758.1', strom)).toBe(23758.1)
  })

  it('ignores the meter number and other print on the meter', () => {
    expect(candidates('1160123456', strom)).toEqual([])
    expect(pickReading('230V 50Hz\n1160123456\n237581\n10(60)A 2021', strom)).toBe(23758.1)
  })

  it('never suggests less than the last reading', () => {
    expect(pickReading('237001', strom)).toBeNull()
  })

  it('gives no suggestion when nothing is near', () => {
    expect(pickReading('375', strom)).toBeNull()
    expect(pickReading('', strom)).toBeNull()
  })

  it('without consumption history, stays close to the last reading', () => {
    expect(pickReading('237050 12', { ...strom, expected: null })).toBe(23705.0)
  })

  it('first reading: the number that fills the counter', () => {
    expect(pickReading('12 004521 7', { places: 5, decimals: 0, last: null, expected: null })).toBe(4521)
    expect(pickReading('8 01234', { places: 5, decimals: 0, last: null, expected: null })).toBe(1234)
  })
})
