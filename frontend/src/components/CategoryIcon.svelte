<script lang="ts">
  import { FileText, Radio, ShieldCheck, Smartphone, Tv, Users, Wifi, Zap } from '@lucide/svelte'

  let { category, size = 20 }: { category: string; size?: number } = $props()

  const icons = {
    energie: Zap,
    internet: Wifi,
    mobilfunk: Smartphone,
    rundfunk: Radio,
    streaming: Tv,
    versicherung: ShieldCheck,
    mitgliedschaft: Users,
  } as const

  const Icon = $derived(icons[category as keyof typeof icons] ?? FileText)
</script>

<Icon {size} strokeWidth={1.75} aria-hidden="true" />
