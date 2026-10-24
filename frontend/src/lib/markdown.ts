import DOMPurify from 'dompurify'
import { marked, type Tokens } from 'marked'

/**
 * Markdown of a note as safe HTML. Images and links relative to the note
 * (`../../../anhaenge/…`, `.attachments.12/…`) point to /api/files, so
 * photos and PDFs show as in the Notes app; scripts and handlers are removed.
 */

/** "objekte/x/wissen" + "../../../anhaenge/a.jpg" → "anhaenge/a.jpg"; null for URLs, anchors and paths outside */
export function resolveNotePath(noteFolder: string, src: string): string | null {
  if (!src || /^[a-z][a-z0-9+.-]*:|^\/|^#/i.test(src)) return null
  const parts = noteFolder ? noteFolder.split('/') : []
  for (const segment of decodeURIComponent(src).split('/')) {
    if (segment === '' || segment === '.') continue
    if (segment === '..') {
      if (parts.length === 0) return null
      parts.pop()
    } else {
      parts.push(segment)
    }
  }
  return parts.length ? parts.join('/') : null
}

export function fileUrl(path: string): string {
  return '/api/files/' + path.split('/').map(encodeURIComponent).join('/')
}

/** Rewrites a relative image or link target in place. */
export function rewriteTargets(token: { type: string; href?: string }, noteFolder: string): void {
  if ((token.type === 'image' || token.type === 'link') && token.href) {
    const path = resolveNotePath(noteFolder, token.href)
    if (path) token.href = fileUrl(path)
  }
}

let hooked = false

export function renderNote(text: string, noteFolder: string): string {
  if (!hooked) {
    // External links open outside the app.
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
      if (node.tagName === 'A' && /^https?:/i.test(node.getAttribute('href') ?? '')) {
        node.setAttribute('target', '_blank')
        node.setAttribute('rel', 'noopener noreferrer')
      }
    })
    hooked = true
  }
  const html = marked.parse(text, {
    async: false,
    gfm: true,
    breaks: true,
    walkTokens: (token) => rewriteTargets(token as Tokens.Generic, noteFolder),
  }) as string
  return DOMPurify.sanitize(html)
}

/** "objekte/x/wissen/haupthahn.md" → "objekte/x/wissen" */
export const noteFolder = (path: string) => path.split('/').slice(0, -1).join('/')
