<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { publicWorkReportApi, type WrPublicState, type WrPreview } from '@/api/workReportTracking'
import { useTurnstile } from '@/composables/useTurnstile'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const token = computed(() => String(route.params.token || ''))
const { t, locale } = useI18n()

// Veřejný náhled vždy ve světlém režimu — sjednoceno s e-mailem, ze kterého se
// sem klient prokliká: logo i akcentní barva dodavatele jsou laděné na světlé
// pozadí (tmavé logo na tmavém pozadí by bylo nečitelné). Děláme to v setupu
// (před prvním paintem této routy), režim uživatele obnovíme při odchodu.
const { isDark } = useTheme()
document.documentElement.classList.remove('dark')
onBeforeUnmount(() => {
  document.documentElement.classList.toggle('dark', isDark.value)
})

const loading = ref(true)
const loadError = ref('')
const state = ref<WrPublicState | null>(null)
const preview = ref<WrPreview | null>(null)

// auth flow
type AuthStep = 'email' | 'code'
const authStep = ref<AuthStep>('email')
const email = ref('')
const code = ref('')
const busy = ref(false)
const authError = ref('')
const cooldown = ref(0)
let cooldownTimer: number | null = null

// captcha
const turnstile = useTurnstile()
const turnstileEl = ref<HTMLElement | null>(null)
const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js'

const lang = computed(() => preview.value?.language || state.value?.language || 'cs')

// Logo dodavatele (data: URI) místo MyInvoice loga — k dispozici v náhledu i na
// ověřovací obrazovce. Prázdné → fallback na MyInvoice branding v hlavičce.
const logoSrc = computed(() => preview.value?.logo_src || state.value?.logo_src || '')
const supplierName = computed(() => preview.value?.supplier_name || state.value?.supplier_name || '')

function fmtMoney(n: number, currency: string): string {
  const decimals = currency === 'JPY' ? 0 : 2
  const loc = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return n.toLocaleString(loc, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + currency
}
function fmtHours(n: number): string {
  const loc = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return n.toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtDate(d: string | null): string {
  if (!d) return ''
  const parts = d.slice(0, 10).split('-')
  if (parts.length !== 3) return d
  return lang.value === 'en' ? `${parts[2]}.${parts[1]}.${parts[0]}` : `${Number(parts[2])}. ${Number(parts[1])}. ${parts[0]}`
}
function reportHasDates(items: { work_date: string | null }[]): boolean {
  return items.some(i => !!i.work_date)
}

const supplier = computed(() => preview.value?.supplier || null)
const supplierAddress = computed(() => {
  const s = supplier.value
  if (!s) return ''
  const line2 = [s.zip, s.city].filter(Boolean).join(' ')
  return [s.street, line2, s.country].filter(Boolean).join(', ')
})
function webDisplay(url: string): string {
  return url.replace(/^https?:\/\//i, '').replace(/\/+$/, '')
}
function webHref(url: string): string {
  return /^https?:\/\//i.test(url) ? url : `https://${url}`
}

function applyLocale() {
  const l = lang.value
  locale.value = l
  localStorage.setItem('locale', l)
}

async function maybeRenderCaptcha() {
  if (state.value?.captcha_provider === 'turnstile' && state.value.captcha_site_key) {
    await nextTick()
    if (turnstileEl.value) {
      turnstile.containerRef.value = turnstileEl.value
      await turnstile.render(state.value.captcha_site_key, TURNSTILE_SCRIPT, 'work_report')
    }
  }
}

onMounted(async () => {
  try {
    const s = await publicWorkReportApi.get(token.value)
    state.value = s
    if (!s.requires_auth && s.preview) {
      preview.value = s.preview
    }
    applyLocale()
    document.title = t('workReportTracking.public.title') + ' — MyInvoice.cz'
  } catch (e: any) {
    loadError.value = e?.response?.data?.error?.message || t('workReportTracking.public.link_invalid_hint')
  } finally {
    loading.value = false
  }
  if (state.value?.requires_auth) {
    await maybeRenderCaptcha()
  }
})

function startCooldown(seconds: number) {
  cooldown.value = seconds
  if (cooldownTimer) window.clearInterval(cooldownTimer)
  cooldownTimer = window.setInterval(() => {
    cooldown.value = Math.max(0, cooldown.value - 1)
    if (cooldown.value === 0 && cooldownTimer) { window.clearInterval(cooldownTimer); cooldownTimer = null }
  }, 1000)
}

async function requestCode(resend = false) {
  authError.value = ''
  if (!email.value.trim()) {
    return
  }
  if (state.value?.captcha_provider === 'turnstile' && !turnstile.token.value) {
    authError.value = t('workReportTracking.public.sending_code')
    return
  }
  busy.value = true
  try {
    const r = await publicWorkReportApi.requestCode(token.value, {
      email: email.value.trim(),
      cf_turnstile_response: turnstile.token.value || null,
      resend,
    })
    authStep.value = 'code'
    startCooldown(r.cooldown_remaining || 60)
    turnstile.reset()
  } catch (e: any) {
    authError.value = e?.response?.data?.error?.message || t('workReportTracking.public.invalid_code')
    turnstile.reset()
  } finally {
    busy.value = false
  }
}

async function verify() {
  authError.value = ''
  if (!code.value.trim()) return
  busy.value = true
  try {
    const r = await publicWorkReportApi.verify(token.value, { email: email.value.trim(), code: code.value.trim() })
    preview.value = r.preview
    if (state.value) state.value.requires_auth = false
  } catch (e: any) {
    authError.value = e?.response?.data?.error?.message || t('workReportTracking.public.invalid_code')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-3xl mx-auto flex items-center gap-3">
        <!-- Logo dodavatele (pokud má zapnutý branding + nahrané logo) -->
        <template v-if="logoSrc">
          <img :src="logoSrc" :alt="supplierName" class="h-9 max-w-[220px] object-contain" />
          <div class="text-sm">
            <div v-if="supplierName" class="font-semibold text-neutral-900">{{ supplierName }}</div>
            <div class="text-xs text-neutral-500">{{ t('workReportTracking.public.title') }}</div>
          </div>
        </template>
        <!-- Fallback: MyInvoice branding -->
        <template v-else>
          <div class="w-8 h-8 rounded-md flex items-center justify-center text-white font-bold"
            :style="{ background: preview?.accent_color || '#16357A' }">M</div>
          <div class="text-sm">
            <div class="font-semibold">My<span class="text-primary-700">Invoice</span><span class="text-neutral-500">.cz</span></div>
            <div class="text-xs text-neutral-500">{{ t('workReportTracking.public.title') }}</div>
          </div>
        </template>
      </div>
    </header>

    <main class="flex-1 px-4 py-8">
      <div class="max-w-3xl mx-auto">

        <div v-if="loading" class="text-center text-neutral-500 py-16">{{ t('workReportTracking.public.loading') }}</div>

        <!-- Error -->
        <div v-else-if="loadError" class="bg-surface border border-danger-500/40 rounded-xl p-8 text-center shadow-sm">
          <div class="text-4xl mb-3">⚠</div>
          <h1 class="text-xl font-semibold mb-2">{{ t('workReportTracking.public.link_invalid') }}</h1>
          <p class="text-neutral-600 text-sm">{{ loadError }}</p>
        </div>

        <!-- Auth -->
        <div v-else-if="state?.requires_auth" class="max-w-md mx-auto bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
          <h1 class="text-xl font-semibold mb-1">{{ t('workReportTracking.public.auth_title') }}</h1>
          <p v-if="state.supplier_name" class="text-sm text-neutral-500 mb-3">{{ t('workReportTracking.public.from') }}: <strong class="text-neutral-800">{{ state.supplier_name }}</strong></p>
          <p class="text-sm text-neutral-600 mb-4">{{ t('workReportTracking.public.auth_intro') }}</p>

          <!-- Step: email -->
          <div v-if="authStep === 'email'" class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('workReportTracking.public.email') }}</label>
              <input v-model="email" type="email" :placeholder="t('workReportTracking.public.email_placeholder')"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" @keyup.enter="requestCode()" />
            </div>
            <p v-if="state.masked_emails?.length" class="text-xs text-neutral-500">
              {{ t('workReportTracking.public.allowed_hint', { emails: state.masked_emails.join(', ') }) }}
            </p>
            <div v-if="state.captcha_provider === 'turnstile'" class="flex justify-center">
              <div ref="turnstileEl"></div>
            </div>
            <p v-if="authError" class="text-sm text-danger-500">{{ authError }}</p>
            <button @click="requestCode()" :disabled="busy"
              class="cursor-pointer w-full h-11 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? t('workReportTracking.public.sending_code') : t('workReportTracking.public.send_code') }}
            </button>
          </div>

          <!-- Step: code -->
          <div v-else class="space-y-3">
            <p class="text-sm text-success-600">{{ t('workReportTracking.public.code_sent') }}</p>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('workReportTracking.public.code') }}</label>
              <input v-model="code" inputmode="numeric" maxlength="6" :placeholder="t('workReportTracking.public.code_placeholder')"
                class="w-full h-11 px-3 border border-neutral-300 rounded-md text-center text-lg tracking-widest font-mono" @keyup.enter="verify()" />
            </div>
            <p v-if="authError" class="text-sm text-danger-500">{{ authError }}</p>
            <button @click="verify" :disabled="busy"
              class="cursor-pointer w-full h-11 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? t('workReportTracking.public.verifying') : t('workReportTracking.public.verify') }}
            </button>
            <button @click="requestCode(true)" :disabled="busy || cooldown > 0"
              class="cursor-pointer w-full h-9 text-sm text-neutral-600 hover:text-neutral-900 disabled:opacity-50">
              {{ cooldown > 0 ? t('workReportTracking.public.resend_in', { n: cooldown }) : t('workReportTracking.public.resend') }}
            </button>
          </div>
        </div>

        <!-- Preview -->
        <div v-else-if="preview" class="space-y-4 wr-scope" :style="{ '--wr-accent': preview.accent_color || '#16357A' }">
          <div class="wr-hero rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 pt-5 pb-5">
              <div class="wr-eyebrow text-xs font-semibold uppercase tracking-wider mb-3">{{ t('workReportTracking.public.title') }}</div>
              <div class="flex flex-wrap items-start justify-between gap-x-10 gap-y-5">

                <!-- Dodavatel -->
                <div class="min-w-0">
                  <div class="wr-eyebrow text-[11px] font-semibold uppercase tracking-wider mb-1">{{ t('workReportTracking.public.from') }}</div>
                  <div class="wr-hero-name text-lg font-bold leading-tight">{{ supplier?.name || preview.supplier_name }}</div>
                  <div v-if="supplier?.tagline" class="text-sm text-neutral-500 mt-0.5">{{ supplier.tagline }}</div>

                  <div v-if="supplierAddress" class="text-sm text-neutral-600 mt-3">{{ supplierAddress }}</div>

                  <div v-if="supplier?.ic || supplier?.dic || supplier && !supplier.is_vat_payer"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-neutral-700 mt-1.5">
                    <span v-if="supplier?.ic"><span class="text-neutral-400">{{ t('workReportTracking.public.ic') }}</span> {{ supplier.ic }}</span>
                    <span v-if="supplier?.dic"><span class="text-neutral-400">{{ t('workReportTracking.public.dic') }}</span> {{ supplier.dic }}</span>
                    <span v-if="supplier && !supplier.is_vat_payer" class="wr-chip">{{ t('workReportTracking.public.non_vat_payer') }}</span>
                  </div>

                  <div v-if="supplier?.email || supplier?.phone || supplier?.web"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm mt-3">
                    <a v-if="supplier?.email" :href="`mailto:${supplier.email}`" class="wr-link">{{ supplier.email }}</a>
                    <a v-if="supplier?.phone" :href="`tel:${supplier.phone.replace(/\s+/g, '')}`" class="wr-link">{{ supplier.phone }}</a>
                    <a v-if="supplier?.web" :href="webHref(supplier.web)" target="_blank" rel="noopener noreferrer" class="wr-link">{{ webDisplay(supplier.web) }}</a>
                  </div>
                </div>

                <!-- Pro / zakázka -->
                <div class="text-sm sm:text-right shrink-0">
                  <div class="wr-eyebrow text-[11px] font-semibold uppercase tracking-wider mb-1">{{ t('workReportTracking.public.for') }}</div>
                  <div class="font-semibold text-neutral-900">{{ preview.client_company_name }}</div>
                  <div v-if="preview.project_name" class="text-neutral-600 mt-2">
                    <span class="text-neutral-400">{{ t('workReportTracking.public.project') }}:</span> {{ preview.project_name }}
                  </div>
                </div>

              </div>
            </div>
          </div>

          <div v-if="!preview.reports.length" class="bg-surface border border-neutral-200 rounded-xl p-8 text-center text-neutral-500 shadow-sm">
            {{ t('workReportTracking.public.no_open') }}
          </div>

          <div v-for="rep in preview.reports" :key="rep.invoice_id" class="wr-card bg-surface border border-neutral-200 rounded-xl shadow-sm overflow-hidden">
            <header class="wr-card-head px-6 py-3 border-b flex items-baseline justify-between gap-3 flex-wrap">
              <h2 class="wr-card-title text-sm font-semibold">{{ rep.title }}</h2>
              <span class="text-xs text-neutral-500">
                <span v-if="rep.project_name">{{ rep.project_name }} · </span>{{ fmtDate(rep.date) }}
              </span>
            </header>
            <div class="overflow-x-auto">
              <table class="wr-table w-full text-sm table-fixed">
                <thead class="wr-thead text-xs uppercase tracking-wide">
                  <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ t('workReportTracking.public.description') }}</th>
                    <th v-if="reportHasDates(rep.items)" class="px-3 py-2 text-left font-medium w-28">{{ t('workReportTracking.public.date') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-20 whitespace-nowrap">{{ t('workReportTracking.public.hours') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-32 whitespace-nowrap">{{ t('workReportTracking.public.rate') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-36 whitespace-nowrap">{{ t('workReportTracking.public.amount') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="(it, idx) in rep.items" :key="idx">
                    <td class="px-4 py-2 whitespace-pre-wrap break-words text-neutral-800">{{ it.description }}</td>
                    <td v-if="reportHasDates(rep.items)" class="px-3 py-2 whitespace-nowrap text-neutral-600">{{ fmtDate(it.work_date) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtHours(it.hours) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(it.rate, rep.currency) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(it.total_amount, rep.currency) }}</td>
                  </tr>
                  <tr class="wr-sum font-semibold">
                    <td class="px-4 py-2 text-right" :colspan="reportHasDates(rep.items) ? 2 : 1">{{ t('workReportTracking.public.report') }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtHours(rep.total_hours) }} h</td>
                    <td></td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(rep.total_amount, rep.currency) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Výkaz materiálu -->
            <div v-if="rep.material_total > 0 && rep.materials.length" class="overflow-x-auto border-t border-neutral-200">
              <div class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                {{ rep.material_title || t('workReportTracking.public.material') }}
              </div>
              <table class="wr-table w-full text-sm table-fixed">
                <thead class="wr-thead text-xs uppercase tracking-wide">
                  <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ t('workReportTracking.public.description') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-20 whitespace-nowrap">{{ t('workReportTracking.public.quantity') }}</th>
                    <th class="px-3 py-2 text-left font-medium w-16">{{ t('workReportTracking.public.unit') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-28 whitespace-nowrap">{{ t('workReportTracking.public.unit_price') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-28 whitespace-nowrap">{{ t('workReportTracking.public.amount') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="(m, idx) in rep.materials" :key="`mat-${idx}`">
                    <td class="px-4 py-2 whitespace-pre-wrap break-words text-neutral-800">{{ m.description }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ Number(m.quantity).toLocaleString('cs', { maximumFractionDigits: 3 }) }}</td>
                    <td class="px-3 py-2 text-neutral-600">{{ m.unit }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(m.unit_price, rep.currency) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(m.total_amount, rep.currency) }}</td>
                  </tr>
                  <tr class="wr-sum font-semibold">
                    <td class="px-4 py-2 text-right" colspan="4">{{ t('workReportTracking.public.report') }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(rep.material_total, rep.currency) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Grand total -->
          <div v-if="preview.reports.length" class="wr-grand rounded-xl p-5 shadow-sm">
            <div class="flex items-baseline justify-between gap-3 flex-wrap">
              <span class="wr-grand-label text-sm font-semibold uppercase tracking-wide">{{ t('workReportTracking.public.total_open') }}</span>
              <div class="text-right">
                <div class="wr-grand-amount text-xl font-bold font-mono">{{ fmtHours(preview.total_hours) }} h</div>
                <div v-for="tc in preview.totals_by_currency" :key="tc.currency" class="wr-grand-amount text-xl font-bold font-mono">
                  {{ fmtMoney(tc.total_amount, tc.currency) }}
                </div>
              </div>
            </div>
          </div>

          <p class="text-xs text-neutral-500 text-center pt-2">{{ t('workReportTracking.public.footer_note') }}</p>
        </div>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      MyInvoice.cz
    </footer>
  </div>
</template>

<style scoped>
/* Akcentní motiv náhledu — světlá verze, laděná na barvu dodavatele (--wr-accent).
   color-mix dělá jemné tóny do bílé; v prohlížečích bez podpory se utility-fallback
   (bg-neutral-50 apod.) zachová. */
.wr-scope {
  --wr-tint-head: color-mix(in srgb, var(--wr-accent) 6%, white);
  --wr-tint-sum: color-mix(in srgb, var(--wr-accent) 11%, white);
  --wr-tint-hover: color-mix(in srgb, var(--wr-accent) 5%, white);
  --wr-line: color-mix(in srgb, var(--wr-accent) 22%, white);
  --wr-ink: color-mix(in srgb, var(--wr-accent) 72%, #1e293b);
}

/* Hero hlavička — dodavatel + příjemce */
.wr-hero {
  background:
    linear-gradient(135deg,
      color-mix(in srgb, var(--wr-accent) 8%, white),
      white 62%);
  border: 1px solid var(--wr-line);
  border-top: 3px solid var(--wr-accent);
}
.wr-eyebrow {
  color: color-mix(in srgb, var(--wr-accent) 55%, #64748b);
}
.wr-hero-name {
  color: var(--wr-ink);
}
.wr-link {
  color: var(--wr-accent);
  text-decoration: none;
  font-weight: 500;
}
.wr-link:hover {
  text-decoration: underline;
}
.wr-chip {
  display: inline-block;
  padding: 1px 8px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 600;
  background: color-mix(in srgb, var(--wr-accent) 12%, white);
  color: var(--wr-ink);
  border: 1px solid var(--wr-line);
}

/* Karta výkazu: akcentní proužek nahoře + tónované záhlaví */
.wr-card {
  border-top: 3px solid var(--wr-accent);
}
.wr-card-head {
  background: var(--wr-tint-head);
  border-bottom-color: var(--wr-line);
}
.wr-card-title {
  color: var(--wr-ink);
  letter-spacing: 0.01em;
}

/* Záhlaví tabulky */
.wr-thead th {
  background: var(--wr-tint-head);
  color: var(--wr-ink);
  border-bottom: 2px solid var(--wr-line);
}

/* Řádky: jemný hover přes celý řádek */
.wr-table tbody tr:not(.wr-sum):hover td {
  background: var(--wr-tint-hover);
}

/* Součtový řádek výkazu */
.wr-sum td {
  background: var(--wr-tint-sum);
  color: var(--wr-ink);
  border-top: 1px solid var(--wr-line);
}

/* Spodní souhrn — výrazný akcentní box s gradientem */
.wr-grand {
  background:
    linear-gradient(135deg,
      color-mix(in srgb, var(--wr-accent) 12%, white),
      color-mix(in srgb, var(--wr-accent) 4%, white));
  border: 1px solid var(--wr-line);
  border-left: 4px solid var(--wr-accent);
}
.wr-grand-label {
  color: var(--wr-ink);
}
.wr-grand-amount {
  color: var(--wr-accent);
}
</style>
