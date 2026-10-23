<script lang="ts">
  import { Droplet, Droplets, Flame, Fuel, Gauge, Sun, Thermometer, Zap } from '@lucide/svelte'

  let { medium, size = 20 }: { medium: string; size?: number } = $props()

  const icons = {
    strom: Zap,
    wasser: Droplet,
    gartenwasser: Droplets,
    gas: Flame,
    waerme: Thermometer,
    pv: Sun,
    oel: Fuel,
  } as const

  const Icon = $derived(icons[medium as keyof typeof icons] ?? Gauge)
</script>

<Icon {size} strokeWidth={1.75} aria-hidden="true" />
