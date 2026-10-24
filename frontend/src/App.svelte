<script lang="ts">
  import { api, ApiError } from './lib/api'
  import { router } from './lib/router.svelte'
  import { theme } from './lib/theme.svelte'
  import type { User } from './lib/types'
  import Navigation from './components/Navigation.svelte'
  import Login from './routes/Login.svelte'
  import Uebersicht from './routes/Uebersicht.svelte'
  import AbleseRunde from './routes/AbleseRunde.svelte'
  import ZaehlerListe from './routes/ZaehlerListe.svelte'
  import ZaehlerDetail from './routes/ZaehlerDetail.svelte'
  import ZaehlerForm from './routes/ZaehlerForm.svelte'
  import Zuhause from './routes/Zuhause.svelte'
  import ObjektForm from './routes/ObjektForm.svelte'
  import Mehr from './routes/Mehr.svelte'
  import NichtGefunden from './routes/NichtGefunden.svelte'

  theme.apply()

  let user = $state<User | null>(null)
  let authError = $state<ApiError | null>(null)
  let checked = $state(false)

  api
    .get<{ user: User }>('/me')
    .then((data) => (user = data.user))
    .catch((e) => (authError = e instanceof ApiError ? e : new ApiError(0, 'internal')))
    .finally(() => (checked = true))

  /** Full-screen flows without navigation */
  const focused = $derived(['ablesen', 'ablesen-einzeln'].includes(router.current.name))
</script>

{#if !checked}
  <p class="splash" aria-live="polite">Wohnpilot lädt …</p>
{:else if user === null}
  <Login error={authError} reason={router.query.get('login')} onSignedIn={(u) => { user = u; router.go('/', true) }} />
{:else}
  <div class="layout" class:focused>
    {#if !focused}<Navigation />{/if}
    <main class="content">
      {#key router.path}
        {#if router.current.name === 'uebersicht'}
          <Uebersicht {user} />
        {:else if router.current.name === 'ablesen'}
          <AbleseRunde />
        {:else if router.current.name === 'ablesen-einzeln'}
          <AbleseRunde only={router.current.params.id} />
        {:else if router.current.name === 'zaehler'}
          <ZaehlerListe />
        {:else if router.current.name === 'zaehler-neu'}
          <ZaehlerForm />
        {:else if router.current.name === 'zaehler-detail'}
          <ZaehlerDetail id={router.current.params.id} />
        {:else if router.current.name === 'zaehler-bearbeiten'}
          <ZaehlerForm id={router.current.params.id} />
        {:else if router.current.name === 'zuhause'}
          <Zuhause />
        {:else if router.current.name === 'zuhause-neu'}
          <ObjektForm />
        {:else if router.current.name === 'zuhause-bearbeiten'}
          <ObjektForm edit />
        {:else if router.current.name === 'mehr'}
          <Mehr {user} />
        {:else}
          <NichtGefunden />
        {/if}
      {/key}
    </main>
  </div>
{/if}

<style>
  .splash {
    display: grid;
    place-items: center;
    min-height: 100dvh;
    color: var(--ink-muted);
  }

  .content {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    max-width: 560px;
    margin: 0 auto;
    padding: var(--space-6) var(--space-4) calc(var(--tabbar-height) + var(--space-3) + var(--space-6) + env(safe-area-inset-bottom));
  }

  .focused .content {
    padding-bottom: var(--space-6);
  }

  @media (min-width: 900px) {
    .layout {
      display: grid;
      grid-template-columns: 260px minmax(0, 1fr);
      align-items: start;
    }

    .layout.focused {
      grid-template-columns: minmax(0, 1fr);
    }

    .content {
      width: 100%;
      max-width: 1120px;
      margin: 0;
      padding: var(--space-8) var(--space-8) var(--space-8);
    }

    .focused .content {
      max-width: 560px;
      margin: 0 auto;
    }
  }
</style>
