// DISPUTE round-1 Vague C — C1-bis : commande complexe 100% in-SPA (pas de reload entre états).
// Tacos(26 composé) + Galette Normale(23 composé) + Coca(52) + Eau(58) →
// qty+ Tacos (composé→2) + qty+ Coca (simple→2) → suppr ligne Eau →
// promo BORNEAUDIT5 → fidélité 0612345678 (même commande) → checkout → upsell → payment → confirm.
// Intégrité: relever sous-total/remises/total à CHAQUE écran.
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import fs from 'fs';

const L = (m) => logLine('_c1b-log.txt', m);
const { browser, page, sink } = await boot();

await kioskBoot(page);
await startFlow(page);
L(`start → ${page.url().replace(BASE, '')}`);

const r1 = await addProduct(page, 5, 26);  L(`add Tacos(26): ${JSON.stringify(r1)}`);
const r2 = await addProduct(page, 2, 23);  L(`add Galette(23): ${JSON.stringify(r2)}`);
const r3 = await addProduct(page, 10, 52); L(`add Coca(52): ${JSON.stringify(r3)}`);
const r4 = await addProduct(page, 10, 58); L(`add Eau(58): ${JSON.stringify(r4)}`);
L(`cart: ${JSON.stringify(await cartState(page))}`);

// → panier via le bouton bottom-bar (in-SPA), fallback goto si introuvable
const cartBtn = page.locator('[data-testid="kiosk-bottom-cart"], .kiosk-bottom-bar button:has-text("Panier"), [data-testid="kiosk-cart-open"]').first();
if (await cartBtn.isVisible().catch(() => false)) { await cartBtn.click(); }
else { await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); }
await page.waitForTimeout(1800);
L(`panier → ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c1b-01-cart-4-lines');

const names = [];
const nLines = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
for (let i = 0; i < nLines; i++) names.push((await page.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).innerText({ timeout: 1500 })).trim());
L(`lignes: ${JSON.stringify(names)}`);
const t0 = await readCartTotals(page);
L(`T0 panier initial: ${JSON.stringify(t0)}`);

const idxOf = (frag) => names.findIndex(n => n.toLowerCase().includes(frag));
const iTacos = idxOf('tacos'), iCoca = idxOf('coca'), iEau = idxOf('eau');
L(`idx tacos=${iTacos} coca=${iCoca} eau=${iEau}`);

// qty>1 sur composé + simple
if (iTacos >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iTacos}"]`).click(); await page.waitForTimeout(900); }
if (iCoca >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iCoca}"]`).click(); await page.waitForTimeout(900); }
const t1 = await readCartTotals(page);
L(`T1 après qty+ (Tacos×2, Coca×2): ${JSON.stringify(t1)}`);
await snap(page, sink, 'c1b-02-cart-qty');

// suppression ligne Eau
if (iEau >= 0) {
  await page.locator(`[data-testid="kiosk-cart-item-remove-${iEau}"]`).click();
  await page.waitForTimeout(700);
  for (const sel of ['button:has-text("Supprimer")', 'button:has-text("Oui")', '.kiosk-modal button.danger']) {
    const b = page.locator(sel).first();
    if (await b.isVisible().catch(() => false)) { await b.click().catch(() => {}); break; }
  }
  await page.waitForTimeout(900);
}
const t2 = await readCartTotals(page);
L(`T2 après suppr Eau: ${JSON.stringify(t2)} — state: ${JSON.stringify(await cartState(page))}`);
await snap(page, sink, 'c1b-03-cart-removed');

// promo
await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
await page.waitForTimeout(2200);
const t3 = await readCartTotals(page);
L(`T3 après promo BORNEAUDIT5: ${JSON.stringify(t3)}`);
await snap(page, sink, 'c1b-04-cart-promo');

// fidélité in-SPA
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1600);
L(`loyalty → ${page.url().replace(BASE, '')}`);
let typed = false;
const input = page.locator('.kiosk-loyalty-input').first();
if (await input.isVisible().catch(() => false)) { try { await input.fill('0612345678'); typed = true; } catch {} }
if (!typed) for (const d of '0612345678') { await page.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click().catch(() => {}); await page.waitForTimeout(110); }
await snap(page, sink, 'c1b-05-loyalty-input');
await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
await page.waitForTimeout(2500);
await snap(page, sink, 'c1b-06-loyalty-balance');
const balTxt = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || 'ABSENT');
L(`loyalty balance: ${balTxt}`);
// redeem = 1re option
const opt = page.locator('.kiosk-loyalty-option').first();
if (await opt.isVisible().catch(() => false)) {
  const optTxt = (await opt.innerText({ timeout: 1500 }).catch(() => '?')).replace(/\n/g, ' ');
  L(`option choisie: ${optTxt}`);
  await opt.click();
  await page.waitForTimeout(2000);
}
L(`après redeem → ${page.url().replace(BASE, '')}`);
// retour panier in-SPA si nécessaire
if (!page.url().includes('/cart')) {
  const back = page.locator('.kiosk-back-btn').first();
  if (await back.isVisible().catch(() => false)) { await back.click(); await page.waitForTimeout(1200); }
}
L(`retour → ${page.url().replace(BASE, '')}`);
const t4 = await readCartTotals(page);
L(`T4 panier promo+fidélité: ${JSON.stringify(t4)}`);
await snap(page, sink, 'c1b-07-cart-promo-loyalty');

// checkout → upsell → payment
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
L(`checkout → ${page.url().replace(BASE, '')}`);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
  await snap(page, sink, 'c1b-08-loyalty-interstitial');
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1500); }
}
if (page.url().includes('/upsell')) {
  await snap(page, sink, 'c1b-09-upsell');
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1800);
}
L(`payment → ${page.url().replace(BASE, '')}`);
await page.waitForTimeout(900);
await snap(page, sink, 'c1b-10-payment');
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
const payLines = await page.evaluate(() => document.body.innerText.match(/(Sous-total|Remise|Fidélit|Promo|Total)[^\n]*/gi)?.slice(0, 12) || []);
L(`T5 PAYMENT total: ${payTotal}`);
L(`T5 PAYMENT lignes: ${JSON.stringify(payLines)}`);

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
L(`confirm → ${page.url().replace(BASE, '')}`);
await snap(page, sink, 'c1b-11-cash-instruction');
const cashNum = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
const cashAmt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
L(`T6 CASH-INSTRUCTION: numero="${cashNum}" montant="${cashAmt}"`);
L(`ORDERS POST: ${JSON.stringify(sink.orders)}`);
fs.writeFileSync(OUT + '_c1b-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C1b done');
