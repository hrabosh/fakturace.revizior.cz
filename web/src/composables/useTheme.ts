import { computed, watchEffect } from 'vue'

/**
 * Barevný režim aplikace.
 *
 * **Fakturace jede jen ve světlém režimu.** Je to jeden produkt s reviziORem
 * a ten tmavý režim nemá; nabízet ho tady znamenalo, že uživatel s tmavým OS
 * viděl jinou aplikaci než ve zbytku systému.
 *
 * Tokeny `.dark` v `styles/main.css` zůstávají — jsou v barvách reviziORu
 * a čekají na chvíli, kdy tmavý režim dostane i hlavní aplikace. Do té doby
 * se třída `.dark` nikdy nenasazuje a přepínač se nezobrazuje.
 *
 * `preference` a `isDark` zůstávají v API kvůli grafům, které si barvy zrcadlí
 * ručně (chart.js nečte CSS proměnné).
 */
export type ThemePreference = 'auto' | 'light' | 'dark'

export const THEME_STORAGE_KEY = 'myinvoice-color-scheme'

const preference = computed<ThemePreference>(() => 'light')
const isDark = computed(() => false)

watchEffect(() => {
  // Uklidí třídu i uživatelům, kteří si dřív tmavý režim zapnuli.
  document.documentElement.classList.remove('dark')
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
const CHART_PALETTE_LIGHT = ['#0C2A52', '#123D75', '#1B5089', '#4E74A3', '#8CA5C6', '#B9C9DF', '#DCE5F0', '#E2A91A', '#D9B24B', '#4CAF7A']
const CHART_PALETTE_DARK = ['#7FAEE0', '#3D7ABF', '#A6C8EC', '#5B93CE', '#CBE0F6', '#1B5089', '#E2A91A', '#F4A261', '#E8CB7C', '#5FBF8E']

const chartColors = computed(() =>
  isDark.value
    ? { border: '#0E2138', tick: '#A8BDD2', grid: '#16304C', tooltipBg: '#16304C', primary: '#3D7ABF', primarySoft: '#7FAEE0', palette: CHART_PALETTE_DARK }
    : { border: '#FFFFFF', tick: '#475569', grid: '#E3DACB', tooltipBg: '#07162B', primary: '#123D75', primarySoft: '#8CA5C6', palette: CHART_PALETTE_LIGHT },
)

export function useChartColors() {
  return chartColors
}
