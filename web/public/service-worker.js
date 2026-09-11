// Service worker aplikace.
//
// **Statiku schválně necachuje.** Soubory buildu mají v názvu otisk obsahu
// a servírují se s `immutable`, takže je prohlížeč drží sám a nová verze má
// vždy novou adresu; `index.html` a tenhle soubor jdou s `no-cache`, takže se
// ověřují při každé návštěvě. Cache-first vrstva v service workeru k tomu
// nepřidávala nic než riziko: byla jediným místem, které umělo vrátit starý
// soubor i po nasazení, a ladění „proč nevidím změny" stálo víc než offline
// režim, který aplikace stejně nikdy needitovala.
//
// Worker zůstává kvůli instalovatelnosti (PWA vyžaduje fetch handler) a kvůli
// úklidu: při aktivaci smaže všechny cache, které si předchozí verze udělaly.

const STATIC_CACHE_PREFIX = 'myinvoice-static-'

function isApiRequest(url) {
  return url.pathname === '/api' || url.pathname.startsWith('/api/')
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting())
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys()
    await Promise.all(
      cacheNames
        .filter((name) => name.startsWith(STATIC_CACHE_PREFIX))
        .map((name) => caches.delete(name)),
    )
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (url.origin !== self.location.origin) return

  // Odpovědi API se nesmí cachovat ani omylem; zbytek si řídí prohlížeč
  // podle hlaviček.
  if (isApiRequest(url)) {
    event.respondWith(fetch(request, { cache: 'no-store' }))
  }
})
