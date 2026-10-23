// Removes the previous build from backend/public, keeping index.php and .htaccess.
import { readdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'

const dir = new URL('../../backend/public/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const keep = new Set(['index.php', '.htaccess'])
for (const entry of readdirSync(dir)) {
  if (!keep.has(entry)) rmSync(join(dir, entry), { recursive: true, force: true })
}
