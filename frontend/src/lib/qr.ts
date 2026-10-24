import { encode } from 'uqr'

/** The QR code as one SVG path in module units, plus its size; dark modules only. */
export function qrPathData(text: string): { size: number; d: string } {
  const qr = encode(text, { ecc: 'M', border: 0 })
  let d = ''
  qr.data.forEach((row, y) => {
    let x = 0
    while (x < row.length) {
      if (!row[x]) {
        x++
        continue
      }
      const start = x
      while (x < row.length && row[x]) x++
      d += `M${start} ${y}h${x - start}v1h-${x - start}z`
    }
  })
  return { size: qr.size, d }
}
