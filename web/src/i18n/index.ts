import { createI18n } from 'vue-i18n'
import cs from './cs.json'
import en from './en.json'

/**
 * Rozhraní jede jen česky.
 *
 * Fakturace je součást reviziORu a ten anglickou mutaci nemá; přepínač jazyka
 * by nabízel poloviční produkt. Anglické překlady **zůstávají** v repu i v
 * balíku — až bude mít anglickou verzi hlavní aplikace, stačí vrátit přepínač
 * a čtení `localStorage`.
 */
export const i18n = createI18n({
  legacy: false,
  locale: 'cs',
  fallbackLocale: 'cs',
  messages: { cs, en },
})
