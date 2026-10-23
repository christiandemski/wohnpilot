<script lang="ts">
  import { CircleAlert, LogIn } from '@lucide/svelte'
  import { ApiError, login } from '../lib/api'

  let { error, failed }: { error: ApiError | null; failed: boolean } = $props()

  const message = $derived(
    failed
      ? 'Die Anmeldung hat nicht geklappt. Versuch es bitte noch einmal.'
      : error && error.code !== 'unauthorized'
        ? error.text
        : '',
  )
</script>

<main class="login">
  <div class="card box">
    <h1>Wohnpilot</h1>
    <p>Dein Zuhause im Blick – Zähler, Fristen, Termine.</p>
    {#if message}
      <p class="problem" role="alert">
        <CircleAlert size={18} strokeWidth={2} aria-hidden="true" />
        {message}
      </p>
    {/if}
    {#if error?.code === 'forbidden'}
      <p class="hint">Bitte jemanden aus deinem Haushalt, dich in Wohnpilot einzutragen.</p>
    {:else}
      <button type="button" class="btn btn-primary" onclick={login}>
        <LogIn size={20} strokeWidth={2} aria-hidden="true" />
        Mit BearID anmelden
      </button>
    {/if}
  </div>
</main>

<style>
  .login {
    display: grid;
    place-items: center;
    min-height: 100dvh;
    padding: var(--space-4);
  }

  .box {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 380px;
    padding: var(--space-6);
    border-radius: var(--radius-xl);
  }

  h1 {
    font-size: 32px;
    line-height: 35px;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  p {
    color: var(--ink-muted);
  }

  .problem {
    display: flex;
    gap: var(--space-2);
    align-items: flex-start;
    color: var(--due);
    font-weight: 600;
  }

  .hint {
    color: var(--ink);
  }
</style>
