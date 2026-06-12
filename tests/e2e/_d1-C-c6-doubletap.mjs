// DISPUTE round-1 Vague C — C6 : double-tap « Commander » avec POST /frontend/order ralenti 2s.
// Question : 2 commandes créées ? (guards attendus : submitting flag + v-if + X-Idempotency-Key réutilisée)
import { boot, snap, kioskBoot, startFlow, addProduct, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_c6-log.txt', m);
const { browser, context, page, sink } = await boot();

// ralentit le POST order de 2s (les autres requêtes passent)
const postLog = [];
await context.route('**/api/frontend/order', async (route) => {
  const req = route.request();
  if (req.method() === 'POST') {
    postLog.push({ ts: Date.now(), idemKey: req.headers()['x-idempotency-key'] || null });
    L(`POST /frontend/order intercepté (#${postLog.length}) idem=${postLog[postLog.length - 1].idemKey} → délai 2s`);
    await new Promise(r => setTimeout(r, 2000));
  }
  await route.continue();
});

await kioskBoot(page);
await startFlow(page);
await addProduct(page, 10, 56); // Oasis simple
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
}
if (page.url().includes('/upsell')) {
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1600);
}
L(`payment url=${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c6-01-payment-before');

// double-tap natif dans le MÊME tick (avant tout re-render Vue) + double click Playwright
const btn = page.locator('[data-testid="kiosk-payment-counter-confirm"]');
await page.evaluate(() => {
  const b = document.querySelector('[data-testid="kiosk-payment-counter-confirm"]');
  if (b) { b.click(); b.click(); } // 2 clicks synchrones même tick
});
// + 1 click Playwright 150ms après (pendant le délai réseau 2s)
await page.waitForTimeout(150);
await btn.click({ timeout: 1000 }).catch((e) => L(`3e tap (150ms) refusé: ${String(e).split('\n')[0]}`));
await page.waitForTimeout(600);
await snap(page, sink, 'c6-02-payment-submitting'); // état loading pendant le délai 2s
await page.waitForTimeout(4500);
L(`après confirm → ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c6-03-after');
L(`POSTs interceptés: ${postLog.length} — détail ${JSON.stringify(postLog)}`);
L(`réponses order: ${JSON.stringify(sink.orders)}`);
fs.writeFileSync(OUT + '_c6-posts.json', JSON.stringify({ postLog, orders: sink.orders }, null, 2));
await browser.close();
L('C6 done');
