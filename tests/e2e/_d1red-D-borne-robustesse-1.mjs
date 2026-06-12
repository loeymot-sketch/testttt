// DISPUTE R1 vague D — ADVERSAIRE probes :
//  P1 = D-003b panier MODIFIÉ re-confirmé après commande Plan B (409 dead-end vs 201 doublon ?)
//  P2 = D-005b « Animations réduites » live : attr <html> après toggle, puis après F5
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const orders = [];
p.on('response', async (r) => {
  if (r.url().includes('frontend/order') && r.request().method() === 'POST' && !r.url().includes('quote') && !r.url().includes('change-status')) {
    try { const j = await r.json(); const d = j?.data || j; orders.push({ status: r.status(), id: d?.id, queue: d?.queue_number, total: d?.total ?? d?.order_amount }); L(`ORDER-POST ${r.status()} id=${d?.id} queue=${d?.queue_number} total=${d?.total ?? d?.order_amount}`); }
    catch { orders.push({ status: r.status() }); L(`ORDER-POST ${r.status()} (non-json)`); }
  }
});

// ---------- PROBE 1 : panier MODIFIÉ après commande 1 ----------
await kioskBoot(p);
await enterFromIdle(p);
const tidA = await addSimpleProduct(p);
L(`produit A ajouté: ${tidA} cart=${JSON.stringify(await cartState(p))}`);
L(`idemKey avant cmd1: ${await p.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.idempotencyKey; } catch { return '?'; } })}`);

await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1200);
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(2000);
if (p.url().includes('/upsell')) { await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {}); await p.waitForTimeout(1500); }
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(3500);
L(`commande 1 → ${p.url().replace(BASE, '')}`);
L(`idemKey après cmd1: ${await p.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.idempotencyKey; } catch { return '?'; } })}`);
await rec.snap('d1red-01-cash-instruction-cmd1');

// retour panier SPA (sans F5), AJOUT d'un 2e produit DIFFÉRENT → payload change
const nav = await p.evaluate(() => { try { document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/kiosk/categories?cat=8'); return 'PUSHED'; } catch (e) { return String(e); } });
L(`router.push(categories cat=8) → ${nav}`);
await p.waitForTimeout(1500);
const adds = p.locator('[data-testid^="kiosk-product-add-"]');
const n = await adds.count();
for (let i = 0; i < n; i++) {
  const el = adds.nth(i);
  const tid = await el.getAttribute('data-testid');
  if (tid === tidA) continue; // produit DIFFÉRENT
  if (!(await el.isVisible().catch(() => false))) continue;
  await el.click().catch(() => {});
  await p.waitForTimeout(1200);
  const overlay = await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false);
  if (overlay) { await p.locator('.kiosk-btn-abandon').first().click().catch(() => {}); await p.waitForTimeout(300); await p.locator('.kiosk-wizard-abandon-yes').click().catch(() => {}); await p.waitForTimeout(300); continue; }
  const st = await cartState(p);
  if ((st?.items?.length || 0) >= 2) { L(`produit B ajouté: ${tid}`); break; }
}
L(`cart modifié: ${JSON.stringify(await cartState(p))}`);
L(`idemKey après modif: ${await p.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.idempotencyKey; } catch { return '?'; } })}`);
await rec.snap('d1red-02-cart-modified-post-cmd1');

// re-checkout + re-confirm avec panier DIFFÉRENT
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1200);
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(2000);
if (p.url().includes('/upsell')) { await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {}); await p.waitForTimeout(1500); }
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(3500);
L(`commande 2 (panier modifié) → ${p.url().replace(BASE, '')}`);
const errTxt = await p.locator('.kiosk-payment-error, [role="alert"]').allTextContents().catch(() => []);
L(`messages visibles: ${JSON.stringify(errTxt)}`);
await rec.snap('d1red-03-second-confirm-modified');
L(`ORDERS: ${JSON.stringify(orders)}`);

// ---------- PROBE 2 : Animations réduites live ----------
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2200);
await p.locator('[data-testid="kiosk-a11y-open"], .ks-a11y-trigger, button[aria-label*="ccessibilit"]').first().click({ force: true }).catch(() => {});
await p.waitForTimeout(900);
const sw = p.locator('[role="switch"]').filter({ hasText: '' });
// le switch Animations réduites = dernier switch du drawer (ordre: PMR, Audio, Audio-desc, Animations)
const switches = p.locator('.ks-a11y-drawer [role="switch"]');
const nb = await switches.count();
L(`drawer switches: ${nb}`);
if (nb > 0) {
  await switches.nth(nb - 1).click().catch(() => {});
  await p.waitForTimeout(600);
}
const live = await p.evaluate(() => ({
  attr: document.documentElement.getAttribute('data-kiosk-reduced-motion'),
  store: (() => { try { return JSON.parse(localStorage.vuex)?.kioskSettings?.reducedMotion; } catch { return '?'; } })(),
}));
L(`APRÈS TOGGLE live: attr=${live.attr} store=${live.store}`);
await rec.snap('d1red-04-reduced-motion-toggled-live');
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
const afterF5 = await p.evaluate(() => ({
  attr: document.documentElement.getAttribute('data-kiosk-reduced-motion'),
  store: (() => { try { return JSON.parse(localStorage.vuex)?.kioskSettings?.reducedMotion; } catch { return '?'; } })(),
}));
L(`APRÈS F5: attr=${afterF5.attr} store=${afterF5.store}`);
await rec.snap('d1red-05-reduced-motion-after-f5');

fs.writeFileSync(`${OUT}/_d1red-probe-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D1RED');
