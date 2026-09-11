import assert from 'node:assert/strict'
import test from 'node:test'

import { createWorker, loadWorkerSource } from './helpers/worker-harness.mjs'

const source = await loadWorkerSource('service-worker.js')

test('API requests always bypass caches', async () => {
  for (const pathname of ['/api', '/api/v1/invoices']) {
    const worker = createWorker(source)
    const result = worker.dispatchFetch({
      url: `https://invoice.test${pathname}`,
      method: 'GET',
      destination: '',
    })

    assert.ok(result)
    await result
    assert.equal(worker.cacheOpenCount, 0)
    assert.equal(worker.fetchCalls.length, 1)
    assert.equal(worker.fetchCalls[0][1].cache, 'no-store')
  }
})

/**
 * Statiku drží prohlížeč podle hlaviček, ne worker. Cache-first vrstva byla
 * jediné místo, které umělo vrátit starý soubor i po nasazení — proto tu není.
 */
test('static assets are left to the browser', () => {
  for (const [url, destination] of [
    ['https://invoice.test/assets/app-deadbeef.js', 'script'],
    ['https://invoice.test/assets/app-deadbeef.css', 'style'],
    ['https://invoice.test/fonts/plus-jakarta-sans-latin.woff2', 'font'],
    ['https://invoice.test/styles/invoice.css', 'style'],
    ['https://invoice.test/pwa/icon-192.png', 'image'],
  ]) {
    const worker = createWorker(source)
    const result = worker.dispatchFetch({ url, method: 'GET', destination })

    assert.equal(result, undefined, url)
    assert.equal(worker.cacheOpenCount, 0, url)
    assert.equal(worker.fetchCalls.length, 0, url)
  }
})

test('HTML navigation stays on the network', () => {
  const worker = createWorker(source)
  const result = worker.dispatchFetch({
    url: 'https://invoice.test/invoices',
    method: 'GET',
    destination: 'document',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
  assert.equal(worker.fetchCalls.length, 0)
})

test('non-GET requests are never intercepted', () => {
  const worker = createWorker(source)
  const result = worker.dispatchFetch({
    url: 'https://invoice.test/assets/app-deadbeef.js',
    method: 'POST',
    destination: 'script',
  })

  assert.equal(result, undefined)
  assert.equal(worker.cacheOpenCount, 0)
})

/**
 * Úklid po předchozích verzích: worker cachoval statiku pod `myinvoice-static-*`
 * a uživatel v té cache mohl uvíznout. Aktivace ji smaže celou, cizí cache
 * nechá být.
 */
test('activation drops every MyInvoice cache and keeps foreign ones', async () => {
  const worker = createWorker(source, {
    existingCaches: ['myinvoice-static-v1', 'myinvoice-static-v2', 'other-app-cache'],
  })

  await worker.dispatchActivate()

  assert.deepEqual(worker.deletedCaches, ['myinvoice-static-v1', 'myinvoice-static-v2'])
})
