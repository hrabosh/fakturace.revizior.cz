<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'
import { useChartColors, useTheme } from '@/composables/useTheme'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps<{
  buckets: Array<{ key: string; label: string; count: number; total_czk: number }>
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null
const colors = useChartColors()
const { isDark } = useTheme()

// Gradient velikosti faktur; v dark posunutý do viditelného rozsahu (nejtmavší indigo splývá).
const palette = computed(() => isDark.value
  ? ['#24548F', '#4E7BB0', '#8AA9CC', '#C0D2E4']
  : ['#8AA9CC', '#24548F', '#123056', '#0F1722'])

function formatCzk(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M Kč'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'k Kč'
  return n.toFixed(0) + ' Kč'
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.buckets.map(b => b.label),
      datasets: [{
        data: props.buckets.map(b => b.count),
        backgroundColor: props.buckets.map((_, i) => palette.value[i] ?? '#1A4173'),
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: colors.value.tooltipBg,
          callbacks: {
            label: (ctx) => {
              const b = props.buckets[ctx.dataIndex]
              return ` ${b.count} faktur · ${formatCzk(b.total_czk)}`
            },
          },
        },
      },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0, color: colors.value.tick, font: { size: 11 } }, grid: { color: colors.value.grid } },
        x: { ticks: { color: colors.value.tick, font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.buckets, build, { deep: true })
watch(colors, build)
</script>

<template>
  <div class="relative h-56"><canvas ref="canvas"></canvas></div>
</template>
