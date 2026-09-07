import { computed, watchEffect } from 'vue'
import { useStorage, usePreferredDark } from '@vueuse/core'

/**
 * Barevný režim aplikace: System / Light / Dark.
 *
 * Why: `auto` respektuje OS (prefers-color-scheme), `light`/`dark` ho přebijí.
 * Volba se ukládá do localStorage (klíč musí sedět s anti-FOUC scriptem v index.html).
 * Reaktivně přepíná třídu `.dark` na <html>, na kterou je navázán dark scope v main.css.
 *
 * Stav je modul-level singleton, takže všechny komponenty sdílejí jednu instanci
 * a watchEffect běží jen jednou.
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myinvoice-color-scheme'

const preference = useStorage<ThemePreference>(THEME_STORAGE_KEY, 'auto')
const prefersDark = usePreferredDark()

/** Co reálně svítí (auto → podle systému). */
const isDark = computed(
  () => preference.value === 'dark' || (preference.value === 'auto' && prefersDark.value),
)

watchEffect(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

export function useTheme() {
  return { preference, isDark }
}

/**
 * Barvy pro chart.js — ten nečte CSS proměnné, takže je tu zrcadlíme ručně podle režimu.
 * POZOR: hodnoty musí odpovídat tokenům v styles/main.css (.dark scope) — při změně palety
 * srovnej i tady. Sdílený singleton; v komponentě: const colors = useChartColors() + watch(colors, build).
 */
// Kategorická paleta pro grafy (rozlišení kategorií, ne sémantika). V dark posunutá do
// světlejších indigo tónů, aby nejtmavší segmenty nesplývaly s tmavým pozadím.
const CHART_PALETTE_LIGHT = ['#123056', '#1A4173', '#24548F', '#4E7BB0', '#8AA9CC', '#C0D2E4', '#E1EAF3', '#F4A261', '#E8A547', '#4CAF7A']
const CHART_PALETTE_DARK = ['#8AA9CC', '#7C68C4', '#C0D2E4', '#8B79C8', '#E1EAF3', '#24548F', '#D8CEF0', '#F4A261', '#E8A547', '#5FBF8E']

const chartColors = computed(() =>
  isDark.value
    ? { border: '#1E1B2B', tick: '#A8A1BE', grid: '#2C2840', tooltipBg: '#322C4A', primary: '#7C68C4', primarySoft: '#8AA9CC', palette: CHART_PALETTE_DARK }
    : { border: '#FFFFFF', tick: '#5A5470', grid: '#E7E3EE', tooltipBg: '#0F1722', primary: '#1A4173', primarySoft: '#8AA9CC', palette: CHART_PALETTE_LIGHT },
)

export function useChartColors() {
  return chartColors
}
