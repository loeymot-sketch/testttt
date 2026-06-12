// DISPUTE round-1 Vague C — C1-c : commande complexe FINALE in-SPA avec retry anti-429.
// Tacos(26, composé MENU COMPLET wizard) ×2 + Galette(23, composé) + Coca(52)×2, suppr Eau(58),
// promo BORNEAUDIT5 + fidélité 0612345678 sur la MÊME commande.
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_c1c-log.txt', m);
const { browser, page, sink } = await boot();

await kioskBoot(page);
await startFlow(page);

const r1 = await addProduct(page, 5, 26);  L(`add Tacos(26): ${JSON.stringify(r1).slice(0, 600)}`);
if (!r1.ok) { await snap(page, sink, 'c1c-00-tacos-wizard-stuck'); }
const r2 = await addProduct(page, 2, 23);  L(`add Galette(23): ${JSON.stringify(r2).slice(0, 400)}`);
const r3 = await addProduct(page, 10, 52); L(`add Coca(52): ${JSON.stringify(r3)}`);
const r4 = await addProduct(page, 10, 58); L(`add Eau(58): ${JSON.stringify(r4)}`);
L(`cart: ${JSON.stringify(await cartState(page))}`);

await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); // reload UNIQUE avant toute remise (items persistés)
await page.waitForTimeout(1800);
await snap(page, sink, 'c1c-01-cart-4-lines');
const names = [];
const nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
for (let i = 0; i < nLines; i++) names.push((await page.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).innerText({ timeout: 1500 })).trim());
L(`lignes: ${JSON.stringify(names)}`);
L(`T0 initial: ${JSON.stringify(await readCartTotals(page))}`);

const idxOf = (frag) => names.findIndex(n => n.toLowerCase().includes(frag));
const iTacos = idxOf('tacos'), iCoca = idxOf('coca'), iEau = idxOf('eau');
if (iTacos >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iTacos}"]`).click(); await page.waitForTimeout(900); }
if (iCoca >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iCoca}"]`).click(); await page.waitForTimeout(900); }
L(`T1 après qty+ (composé Tacos→2, Coca→2): ${JSON.stringify(await readCartTotals(page))}`);
await snap(page, sink, 'c1c-02-cart-qty');

if (iEau >= 0) {
  await page.locator(`[data-testid="kiosk-cart-item-remove-${iEau}"]`).click();
  await page.waitForTimeout(700);
  for (const sel of ['button:has-text("Supprimer")', 'button:has-text("Oui")']) {
    const b = page.locator(sel).first();
    if (await b.isVisible().catch(() => false)) { await b.click().catch(() => {}); break; }
  }
  await page.waitForTimeout(900);
}
L(`T2 après suppr Eau: ${JSON.stringify(await readCartTotals(page))} state=${JSON.stringify(await cartState(page))}`);
await snap(page, sink, 'c1c-03-cart-removed');

// --- promo avec retry anti-429 (throttle:30,1 partagé par le user borne)
let promoOk = false;
for (let a = 1; a <= 4 && !promoOk; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2500);
  const t = await readCartTotals(page);
  if (t.promo) { promoOk = true; L(`T3 promo appliquée (essai ${a}): ${JSON.stringify(t)}`); break; }
  const err = await page.locator('#kiosk-cart-promo-error, [data-testid="kiosk-cart-promo-error"]').innerText({ timeout: 1200 }).catch(() => null);
  L(`promo essai ${a} échec — erreur inline: "${err}" — attente 35s`);
  if (a === 1) await snap(page, sink, 'c1c-04a-promo-fail-inline');
  await page.waitForTimeout(35000);
}
await snap(page, sink, 'c1c-04-cart-promo');

// --- fidélité in-SPA avec retry anti-429 (throttle:10,1)
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1600);
let loyaltyDone = false;
for (let a = 1; a <= 4 && !loyaltyDone; a++) {
  const input = page.locator('.kiosk-loyalty-input').first();
  if (await input.isVisible().catch(() => false)) { try { await input.fill('0612345678'); } catch {} }
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(2800);
  const bal = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || null);
  if (bal) { loyaltyDone = true; L(`loyalty balance (essai ${a}): ${bal}`); break; }
  const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`loyalty essai ${a} échec — erreur: "${err}" — attente 35s`);
  await page.waitForTimeout(35000);
}
await snap(page, sink, 'c1c-05-loyalty-balance');
if (loyaltyDone) {
  const opt = page.locator('.kiosk-loyalty-option').first();
  if (await opt.isVisible().catch(() => false)) {
    L(`option redeem: ${(await opt.innerText({ timeout: 1500 }).catch(() => '?')).replace(/\n/g, ' ')}`);
    await opt.click();
    await page.waitForTimeout(2200);
  } else L('AUCUNE option redeem visible (canRedeem=false ?)');
}
L(`après loyalty → ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c1c-06-after-redeem');
if (!page.url().includes('/cart')) {
  const back = page.locator('.kiosk-back-btn').first();
  if (await back.isVisible().catch(() => false)) { await back.click(); await page.waitForTimeout(1200); }
}
const t4 = await readCartTotals(page);
L(`T4 panier promo+fidélité: ${JSON.stringify(t4)}`);
await snap(page, sink, 'c1c-07-cart-final');

// checkout → upsell → payment → confirm
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
L(`checkout → ${page.url().replace(BASE, '')}`);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1500); }
}
if (page.url().includes('/upsell')) {
  await snap(page, sink, 'c1c-08-upsell');
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
await page.waitForTimeout(900);
await snap(page, sink, 'c1c-09-payment');
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`T5 PAYMENT: ${payTotal}`);
const payLines = await page.evaluate(() => document.body.innerText.match(/(Sous-total|Remise|Fidélit|Promo|Total|articles)[^\n]*/gi)?.slice(0, 12) || []);
L(`T5 PAYMENT lignes: ${JSON.stringify(payLines)}`);

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
L(`confirm → ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c1c-10-cash-instruction');
const cashNum = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
const cashAmt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
L(`T6 CASH: numero="${cashNum}" montant="${cashAmt}"`);
L(`ORDERS POST: ${JSON.stringify(sink.orders)}`);
fs.writeFileSync(OUT + '_c1c-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C1c done');
