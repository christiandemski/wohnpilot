import { describe, expect, it } from 'vitest'
import { ApiError } from './api'
import { isTransient, jobOf, newJob, runJob, type QueuedRequest } from './queue'

const defectWithPhoto = () =>
  newJob('Mangel: Fenster zieht', [
    { method: 'POST', path: '/defects', json: { titel: 'Fenster zieht' } },
    { method: 'POST', path: '/photos/mangel/{id}', form: { foto: 'bytes' } },
  ])

describe('waiting writes', () => {
  it('runs the steps and hands the new id to the photo', async () => {
    const sent: [string, string][] = []
    const { job, answer } = await runJob(defectWithPhoto(), async (request, key) => {
      sent.push([request.path, key])
      return request.path === '/defects' ? { id: 'fenster-zieht' } : { photos: [] }
    })
    expect(sent.map(([path]) => path)).toEqual(['/defects', '/photos/mangel/fenster-zieht'])
    expect(job.done).toBe(2)
    expect(job.createdId).toBe('fenster-zieht')
    expect(answer).toEqual({ photos: [] })
  })

  it('goes on where the network broke off, with the same keys', async () => {
    const keys: string[] = []
    let online = true
    const send = async (request: QueuedRequest, key: string) => {
      keys.push(key)
      if (!online) throw new ApiError(0, 'offline')
      online = false // the network drops after the defect went through
      return { id: 'fenster-zieht' }
    }

    const first = defectWithPhoto()
    const error = await runJob(first, send).catch((e: unknown) => e)
    expect(isTransient(error)).toBe(true)
    const stuck = jobOf(error)!
    expect([stuck.done, stuck.createdId]).toEqual([1, 'fenster-zieht'])

    online = true
    const { job } = await runJob(stuck, send)
    expect(job.done).toBe(2)
    expect(keys).toEqual([`${first.id}:0`, `${first.id}:1`, `${first.id}:1`])
  })

  it('tells refusals from network trouble', () => {
    expect(isTransient(new ApiError(0, 'offline'))).toBe(true)
    expect(isTransient(new ApiError(503, 'storage_unavailable'))).toBe(true)
    expect(isTransient(new ApiError(401, 'unauthorized'))).toBe(true)
    expect(isTransient(new ApiError(422, 'validation'))).toBe(false)
    expect(isTransient(new Error('bug'))).toBe(false)
  })
})
