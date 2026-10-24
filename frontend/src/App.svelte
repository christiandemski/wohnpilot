<script lang="ts">
  import { api, ApiError } from './lib/api'
  import { router } from './lib/router.svelte'
  import { theme } from './lib/theme.svelte'
  import { settings } from './lib/settings.svelte'
  import { rememberReturn, takeReturn } from './lib/returnTo'
  import { qrTarget } from './lib/stickers'
  import { outbox } from './lib/outbox.svelte'
  import type { User } from './lib/types'
  import Navigation from './components/Navigation.svelte'
  import OutboxBanner from './components/OutboxBanner.svelte'
  import Login from './routes/Login.svelte'
  import Uebersicht from './routes/Uebersicht.svelte'
  import AbleseRunde from './routes/AbleseRunde.svelte'
  import ZaehlerListe from './routes/ZaehlerListe.svelte'
  import ZaehlerDetail from './routes/ZaehlerDetail.svelte'
  import ZaehlerForm from './routes/ZaehlerForm.svelte'
  import Zuhause from './routes/Zuhause.svelte'
  import ObjektForm from './routes/ObjektForm.svelte'
  import Mehr from './routes/Mehr.svelte'
  import Vertraege from './routes/Vertraege.svelte'
  import VertragDetail from './routes/VertragDetail.svelte'
  import VertragForm from './routes/VertragForm.svelte'
  import Mietvertrag from './routes/Mietvertrag.svelte'
  import MietvertragForm from './routes/MietvertragForm.svelte'
  import Fristen from './routes/Fristen.svelte'
  import FristDetail from './routes/FristDetail.svelte'
  import FristForm from './routes/FristForm.svelte'
  import NichtGefunden from './routes/NichtGefunden.svelte'
  import Wartung from './routes/Wartung.svelte'
  import WartungDetail from './routes/WartungDetail.svelte'
  import WartungForm from './routes/WartungForm.svelte'
  import Termine from './routes/Termine.svelte'
  import TerminDetail from './routes/TerminDetail.svelte'
  import TerminForm from './routes/TerminForm.svelte'
  import Muell from './routes/Muell.svelte'
  import Maengel from './routes/Maengel.svelte'
  import MangelDetail from './routes/MangelDetail.svelte'
  import MangelForm from './routes/MangelForm.svelte'
  import Geraete from './routes/Geraete.svelte'
  import GeraetDetail from './routes/GeraetDetail.svelte'
  import GeraetForm from './routes/GeraetForm.svelte'
  import Einstellungen from './routes/Einstellungen.svelte'
  import Kontakte from './routes/Kontakte.svelte'
  import KontaktDetail from './routes/KontaktDetail.svelte'
  import KontaktForm from './routes/KontaktForm.svelte'
  import Wissen from './routes/Wissen.svelte'
  import WissenDetail from './routes/WissenDetail.svelte'
  import WissenForm from './routes/WissenForm.svelte'
  import Notfall from './routes/Notfall.svelte'
  import Suche from './routes/Suche.svelte'
  import Aufkleber from './routes/Aufkleber.svelte'
  import Eingang from './routes/Eingang.svelte'
  import Jahresbericht from './routes/Jahresbericht.svelte'
  import Nebenkosten from './routes/Nebenkosten.svelte'
  import NebenkostenDetail from './routes/NebenkostenDetail.svelte'
  import NebenkostenForm from './routes/NebenkostenForm.svelte'

  theme.apply()

  let user = $state<User | null>(null)
  let authError = $state<ApiError | null>(null)
  let checked = $state(false)

  api
    .get<{ user: User }>('/me')
    .then((data) => {
      user = data.user
      void settings.load()
      void outbox.start()
      // Back from BearID or the redirect login, which always land on "/"
      const back = router.path === '/' ? takeReturn() : null
      if (back) router.go(back, true)
    })
    .catch((e) => {
      authError = e instanceof ApiError ? e : new ApiError(0, 'internal')
      rememberReturn(window.location.pathname + window.location.search)
    })
    .finally(() => (checked = true))

  /** Full-screen flows without navigation */
  const focused = $derived(['ablesen', 'ablesen-einzeln'].includes(router.current.name))

  /** A scanned QR sticker: on to where it leads, without the sticker address in the history. */
  $effect(() => {
    if (user && router.current.name === 'qr') router.go(qrTarget(router.current.params.kind, router.current.params.id), true)
  })
</script>

{#if !checked}
  <p class="splash" aria-live="polite">Wohnpilot lädt …</p>
{:else if user === null}
  <Login error={authError} reason={router.query.get('login')} onSignedIn={(u) => { user = u; void settings.load(); void outbox.start(); router.go(takeReturn() ?? "/", true) }} />
{:else}
  <div class="layout" class:focused>
    {#if !focused}<Navigation />{/if}
    <main class="content">
      <OutboxBanner />
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
        {:else if router.current.name === 'vertraege'}
          <Vertraege />
        {:else if router.current.name === 'vertrag-neu'}
          <VertragForm />
        {:else if router.current.name === 'vertrag-detail'}
          <VertragDetail id={router.current.params.id} />
        {:else if router.current.name === 'vertrag-bearbeiten'}
          <VertragForm id={router.current.params.id} />
        {:else if router.current.name === 'mietvertrag'}
          <Mietvertrag />
        {:else if router.current.name === 'mietvertrag-bearbeiten'}
          <MietvertragForm />
        {:else if router.current.name === 'fristen'}
          <Fristen />
        {:else if router.current.name === 'frist-neu'}
          <FristForm />
        {:else if router.current.name === 'frist-detail'}
          <FristDetail id={router.current.params.id} />
        {:else if router.current.name === 'frist-bearbeiten'}
          <FristForm id={router.current.params.id} />
        {:else if router.current.name === 'wartung'}
          <Wartung />
        {:else if router.current.name === 'wartung-neu'}
          <WartungForm />
        {:else if router.current.name === 'wartung-detail'}
          <WartungDetail id={router.current.params.id} />
        {:else if router.current.name === 'wartung-bearbeiten'}
          <WartungForm id={router.current.params.id} />
        {:else if router.current.name === 'termine'}
          <Termine />
        {:else if router.current.name === 'termin-neu'}
          <TerminForm />
        {:else if router.current.name === 'muell'}
          <Muell />
        {:else if router.current.name === 'termin-detail'}
          <TerminDetail id={router.current.params.id} />
        {:else if router.current.name === 'termin-bearbeiten'}
          <TerminForm id={router.current.params.id} />
        {:else if router.current.name === 'maengel'}
          <Maengel />
        {:else if router.current.name === 'mangel-neu'}
          <MangelForm />
        {:else if router.current.name === 'mangel-detail'}
          <MangelDetail id={router.current.params.id} />
        {:else if router.current.name === 'mangel-bearbeiten'}
          <MangelForm id={router.current.params.id} />
        {:else if router.current.name === 'geraete'}
          <Geraete />
        {:else if router.current.name === 'geraet-neu'}
          <GeraetForm />
        {:else if router.current.name === 'geraet-detail'}
          <GeraetDetail id={router.current.params.id} />
        {:else if router.current.name === 'geraet-bearbeiten'}
          <GeraetForm id={router.current.params.id} />
        {:else if router.current.name === 'einstellungen'}
          <Einstellungen />
        {:else if router.current.name === 'kontakte'}
          <Kontakte />
        {:else if router.current.name === 'kontakt-neu'}
          <KontaktForm />
        {:else if router.current.name === 'kontakt-detail'}
          <KontaktDetail id={router.current.params.id} />
        {:else if router.current.name === 'kontakt-bearbeiten'}
          <KontaktForm id={router.current.params.id} />
        {:else if router.current.name === 'wissen'}
          <Wissen />
        {:else if router.current.name === 'wissen-neu'}
          <WissenForm />
        {:else if router.current.name === 'wissen-detail'}
          <WissenDetail id={router.current.params.id} />
        {:else if router.current.name === 'wissen-bearbeiten'}
          <WissenForm id={router.current.params.id} />
        {:else if router.current.name === 'notfall'}
          <Notfall />
        {:else if router.current.name === 'suche'}
          <Suche />
        {:else if router.current.name === 'nebenkosten'}
          <Nebenkosten />
        {:else if router.current.name === 'nebenkosten-neu'}
          <NebenkostenForm />
        {:else if router.current.name === 'nebenkosten-detail'}
          <NebenkostenDetail id={router.current.params.id} />
        {:else if router.current.name === 'nebenkosten-bearbeiten'}
          <NebenkostenForm id={router.current.params.id} />
        {:else if router.current.name === 'bericht'}
          <Jahresbericht />
        {:else if router.current.name === 'eingang'}
          <Eingang />
        {:else if router.current.name === 'aufkleber'}
          <Aufkleber />
        {:else if router.current.name === 'qr'}
          <p class="text-muted" aria-live="polite">Einen Moment …</p>
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

  @media print {
    .layout {
      display: block;
    }

    .content {
      max-width: none;
      padding: 0;
    }
  }
</style>
