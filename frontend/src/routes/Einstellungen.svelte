<script lang="ts">
  import { people } from '../lib/people.svelte'
  import { api, ApiError } from '../lib/api'
  import { settings } from '../lib/settings.svelte'
  import type { Settings } from '../lib/types'
  import PageHeader from '../components/PageHeader.svelte'

  /** Settings of the household, for everyone in it (saved in _haushalt.md). */
  let paperless = $state('')
  let saving = $state(false)
  let message = $state('')
  let error = $state('')

  settings.load().then(() => (paperless = settings.paperlessUrl ?? ''))

  async function save(event: SubmitEvent) {
    event.preventDefault()
    saving = true
    message = ''
    error = ''
    try {
      settings.apply(await api.put<Settings>('/settings', { paperless_url: paperless.trim() }))
      paperless = settings.paperlessUrl ?? ''
      message = settings.paperless ? 'Paperless ist eingeschaltet.' : 'Paperless ist ausgeschaltet.'
    } catch (e) {
      error = e instanceof ApiError ? (e.fields.paperless_url ?? e.text) : 'Das hat nicht geklappt.'
    } finally {
      saving = false
    }
  }
</script>

<PageHeader back="/mehr" title="Einstellungen" />

<form class="form" onsubmit={save} novalidate>
  <fieldset class="card fields">
    <legend>Paperless</legend>
    <p class="muted">
      Läuft bei dir Paperless-ngx, trag hier die Adresse ein. Dann kannst du bei Verträgen, Mietvertrag und Geräten die Nummer des Dokuments angeben und kommst mit einem Tipp hin.
      Ohne Adresse blendet Wohnpilot alles zu Paperless aus.
    </p>
    <div class="field">
      <label for="paperless">Adresse <span class="optional">(optional)</span></label>
      <input id="paperless" class="input" type="url" inputmode="url" autocomplete="url" bind:value={paperless} placeholder="https://paperless.example.org" aria-invalid={error !== ''} />
      {#if error}<span class="error">{error}</span>{/if}
    </div>
  </fieldset>
  <p role="status" class="small">{message}</p>
  {#if !people.manages}<p class="muted small">Ändern kann, wer den Haushalt verwaltet.</p>{/if}
  <button type="submit" class="btn btn-primary btn-large" disabled={saving || !people.manages}>{saving ? 'Speichert …' : 'Speichern'}</button>
</form>

<style>
  .muted {
    color: var(--ink-muted);
    font-size: 14px;
  }

  .small {
    font-size: 13px;
  }

  .small:empty {
    display: none;
  }
</style>
