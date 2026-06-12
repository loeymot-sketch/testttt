// ADVERSARIAL re-verify R2 — Vague C borne edge (dispute-2026-06-12 round-2 post-heal)
// Missions: A promo BORNEAUDIT5 facturée (DB obligatoire), B fidélité client RÉEL status=5
// (recrédité 165 pts par mutation SQL documentée AVANT le run), C inactivité payment Plan B
// (heal C-RED-03), D 429 promo inline FR (heal C-ADV-01), E upsell API pool (nouveau P1 GStack).
import { boot, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE } from './_d1-C-lib.mjs';
import { OUT2, log2, snap2, skipToPayment } from './_d2-C-lib.mjs';
import fs from 'fs';

const L = (m) => log2('_d2red-log.txt', m);
const { browser, context, page, sink } = await boot();
await kioskBoot(page);

async function applyPromo(code, tries = 3, waitMs = 22000) {
  for (let a = 1; a <= tries; a++) {
    await page.locator('[data-testid="kiosk-cart-promo-input"]').fill(code);
    await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
    await page.waitForTimeout(2600);
    const t = await readCartTotals(page);
    if (t.promo) return { ok: true, totals: t, attempt: a };
    const err = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText({ timeout: 1200 }).catch(() => null);
    L(`promo essai ${a} échec: "${err}"`);
    if (a < tries) await page.waitForTimeout(waitMs);
  }
  return { ok: false };
}

async function confirmAndCash(tag) {
  const payTxt = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 3000 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
  L(`${tag} PAYMENT affiche: ${payTxt}`);
  await snap2(page, sink, `d2r-${tag}-payment`);
  await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
  await page.waitForTimeout(4500);
  const num = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 3000 }).catch(() => 'ABSENT');
  const amt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 3000 }).catch(() => 'ABSENT');
  const toast = await page.evaluate(() => document.querySelector('[role="alert"]')?.innerText?.replace(/\n/g, ' ') || null);
  L(`${tag} CASH: numero="${num}" montant="${amt}" toast="${toast}"`);
  await snap2(page, sink, `d2r-${tag}-cash`);
  return { payTxt, num, amt, toast };
}

// ============ MISSION A — promo BORNEAUDIT5 sur Galette 6,50 → DB ============
await startFlow(page);
let r = await addProduct(page, 2, 23); L(`A add Galette(23): ${JSON.stringify(r).slice(0, 160)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1600);
L(`A T0: ${JSON.stringify(await readCartTotals(page))}`);
const pA = await applyPromo('BORNEAUDIT5');
L(`A promo: ${JSON.stringify(pA)}`);
await snap2(page, sink, 'd2r-a-cart-promo');
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
await skipToPayment(page, BASE);
const A = await confirmAndCash('a');

// ============ MISSION B — fidélité client RÉEL (status=5, 165 pts re-crédités) ============
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
await page.waitForTimeout(1200);
await startFlow(page);
r = await addProduct(page, 2, 23); L(`B add Galette(23): ${JSON.stringify(r).slice(0, 160)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1600);
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1600);
let loyaltyDone = false;
for (let a = 1; a <= 3 && !loyaltyDone; a++) {
  const input = page.locator('.kiosk-loyalty-input').first();
  if (await input.isVisible().catch(() => false)) { try { await input.fill('0612345678'); } catch {} }
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(3000);
  const bal = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || null);
  if (bal) { loyaltyDone = true; L(`B loyalty balance (essai ${a}): ${bal}`); break; }
  const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`B loyalty essai ${a} échec: "${err}" — attente 25s`);
  await page.waitForTimeout(25000);
}
if (loyaltyDone) {
  const opt = page.locator('.kiosk-loyalty-option').first();
  if (await opt.isVisible().catch(() => false)) { await opt.click(); await page.waitForTimeout(800); }
  const conf = page.locator('button:has-text("Confirmer")').first();
  if (await conf.isVisible().catch(() => false)) { await conf.click(); await page.waitForTimeout(2200); }
}
if (!page.url().includes('/cart')) { await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }).catch(() => {}); await page.waitForTimeout(1500); }
const tB = await readCartTotals(page);
L(`B panier post-fidélité: ${JSON.stringify(tB)}`);
await snap2(page, sink, 'd2r-b-cart-loyalty');
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
await skipToPayment(page, BASE);
const B = await confirmAndCash('b');

// ============ MISSION C — heal C-RED-03: inactivité payment Plan B (idleMs=12s) ============
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
await page.waitForTimeout(1000);
await page.evaluate(() => {
  const v = JSON.parse(localStorage.vuex || '{}');
  v.kioskSettings = { ...(v.kioskSettings || {}), idleMs: 12000, confirmMs: 4000 };
  localStorage.vuex = JSON.stringify(v);
});
await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
await startFlow(page);
r = await addProduct(page, 10, 52); L(`C add Coca(52): ${JSON.stringify(r)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1000);
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
await skipToPayment(page, BASE);
L(`C arrivée payment: ${page.url().replace(BASE, '')}`);
let tOverlay = null, tLeave = null;
for (let i = 1; i <= 30; i++) {
  await page.waitForTimeout(1000);
  if (tOverlay === null) {
    const seen = await page.evaluate(() => /toujours là|continuer ma commande/i.test(document.body.innerText));
    if (seen) { tOverlay = i; L(`C overlay payment vu à ~${i}s`); await snap2(page, sink, 'd2r-c-payment-overlay'); }
  }
  if (!page.url().includes('/payment')) { tLeave = i; L(`C quitte payment à ~${i}s → ${page.url().replace(BASE, '')}`); break; }
}
L(`C RÉSULTAT: overlay=${tOverlay}s leave=${tLeave}s url=${page.url().replace(BASE, '')}`);
await snap2(page, sink, 'd2r-c-payment-after-idle');
const cartAfterIdle = await page.evaluate(() => { try { return JSON.parse(localStorage.vuex || '{}')?.kioskCart?.items?.length ?? 'n/a'; } catch { return 'err'; } });
L(`C panier après leave (lignes persistées): ${cartAfterIdle}`);

// ============ MISSION D — heal C-ADV-01: burst promo → 429 inline FR ============
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
await page.waitForTimeout(1000);
await page.evaluate(() => { const v = JSON.parse(localStorage.vuex || '{}'); v.kioskSettings = { ...(v.kioskSettings || {}), idleMs: 180000, confirmMs: 30000 }; localStorage.vuex = JSON.stringify(v); });
await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1200);
await startFlow(page);
r = await addProduct(page, 10, 52); L(`D add Coca(52): ${JSON.stringify(r)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1200);
let inline429 = null;
for (let a = 1; a <= 12; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('FAUXCODE' + a);
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(650);
  const err = await page.locator('[data-testid="kiosk-cart-promo-error"]').innerText({ timeout: 800 }).catch(() => null);
  if (err && /trop de|patientez|too many|attempts/i.test(err)) { inline429 = { attempt: a, err }; break; }
}
L(`D inline après burst: ${JSON.stringify(inline429)}`);
const sawNet429 = sink.network.some(e => e.status === 429 && String(e.url).includes('promo/validate'));
L(`D network 429 promo/validate observé: ${sawNet429}`);
await snap2(page, sink, 'd2r-d-promo-429-inline');

// ============ MISSION E — upsell API pool (nouveau P1 GStack C20) ============
const upsell = await page.evaluate(async () => {
  try {
    const v = JSON.parse(localStorage.vuex || '{}');
    const tok = v?.kioskCart?.kioskToken || v?.kioskToken || null;
    const res = await fetch('/api/frontend/item/kiosk-upsell', { headers: { Accept: 'application/json', ...(tok ? { Authorization: 'Bearer ' + tok } : {}) } });
    return { status: res.status, body: (await res.text()).slice(0, 400) };
  } catch (e) { return { error: e.message }; }
});
L(`E kiosk-upsell API: ${JSON.stringify(upsell)}`);

fs.writeFileSync(OUT2 + '_d2red-orders.json', JSON.stringify(sink.orders, null, 2));
L(`ORDERS: ${JSON.stringify(sink.orders)}`);
await browser.close();
