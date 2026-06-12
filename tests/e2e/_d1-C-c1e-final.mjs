// DISPUTE round-1 Vague C — C1d : promo + fidélité sur la MÊME commande (intégrité bout-en-bout).
// Fidélité D'ABORD (code VICT1234), puis promo BORNEAUDIT5, puis checkout complet.
import { boot, snap, kioskBoot, startFlow, addProduct, cartState, readCartTotals, BASE, logLine, OUT } from './_d1-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => logLine('_c1e-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();
L(`AVANT: user44 points = ${sql("SELECT loyalty_points FROM users WHERE id=44")} | kiosk_promos uses = ${sql("SELECT uses_count FROM kiosk_promos WHERE id=1")}`);

const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

const r1 = await addProduct(page, 5, 26);  L(`add Tacos(26): ok=${r1.ok}`);
const r2 = await addProduct(page, 2, 23);  L(`add Galette(23): ok=${r2.ok}`);
const r3 = await addProduct(page, 10, 52); L(`add Coca(52): ok=${r3.ok}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1600);
const names = [];
const n = await page.locator('[data-testid^="kiosk-cart-item-name-"]').count();
for (let i = 0; i < n; i++) names.push((await page.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).innerText({ timeout: 1500 })).trim());
const iT = names.findIndex(x => x.toLowerCase().includes('tacos'));
if (iT >= 0) { await page.locator(`[data-testid="kiosk-cart-item-qty-plus-${iT}"]`).click(); await page.waitForTimeout(900); }
L(`lignes=${JSON.stringify(names)} (Tacos→2)`);
L(`T0: ${JSON.stringify(await readCartTotals(page))}`);
await snap(page, sink, 'c1e-01-cart');

// TOKEN FRAIS juste avant la fidélité (guerre de révocation avec les agents parallèles
// — chaque login du compte machine révoque les tokens précédents). Les items du panier
// sont persistés (store/index.js:287) et survivent au re-login.
await kioskBoot(page);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1200);
L(`re-boot token → cart: ${JSON.stringify(await readCartTotals(page))}`);

// FIDÉLITÉ immédiatement — code carte (évite l'ambiguïté téléphone)
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1200);
let loyaltyOk = false;
for (let a = 1; a <= 3 && !loyaltyOk; a++) {
  const input = page.locator('.kiosk-loyalty-input').first();
  if (await input.isVisible().catch(() => false)) { try { await input.fill('VICT1234'); } catch {} }
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(2800);
  const bal = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || null);
  if (bal) { loyaltyOk = true; L(`balance (essai ${a}): ${bal}`); break; }
  const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`fidélité essai ${a} échec err="${err}" — tap + attente 20s`);
  await page.locator('.kiosk-loyalty-input').first().click().catch(() => {});
  await page.waitForTimeout(20000);
}
await snap(page, sink, 'c1e-02-loyalty-balance');
if (loyaltyOk) {
  const opt = page.locator('.kiosk-loyalty-option').first();
  if (await opt.isVisible().catch(() => false)) {
    L(`redeem option: ${(await opt.innerText({ timeout: 1500 }).catch(() => '?')).replace(/\n/g, ' ')}`);
    await opt.click();
    await page.waitForTimeout(2200);
  } else L('PAS d option redeem (canRedeem false?)');
}
L(`après fidélité → ${page.url().replace(BASE, '')}`);
if (!page.url().includes('/cart')) {
  const back = page.locator('.kiosk-back-btn').first();
  if (await back.isVisible().catch(() => false)) { await back.click(); await page.waitForTimeout(1200); }
}
const t1 = await readCartTotals(page);
L(`T1 après fidélité: ${JSON.stringify(t1)}`);
await snap(page, sink, 'c1e-03-cart-loyalty');

// PROMO ensuite — même commande
let promoOk = false;
for (let a = 1; a <= 3 && !promoOk; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2500);
  const t = await readCartTotals(page);
  if (t.promo) { promoOk = true; L(`T2 promo OK (essai ${a}): ${JSON.stringify(t)}`); break; }
  const err = await page.locator('#kiosk-cart-promo-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`promo essai ${a} échec err="${err}" — tap + attente 20s`);
  await page.locator('[data-testid="kiosk-cart-promo-input"]').click().catch(() => {});
  await page.waitForTimeout(20000);
}
const t2 = await readCartTotals(page);
await snap(page, sink, 'c1e-04-cart-both');
L(`T2 final panier (promo+fidélité): ${JSON.stringify(t2)}`);

// checkout
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2200);
if (page.url().includes('/loyalty')) {
  const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
  if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
}
if (page.url().includes('/upsell')) {
  await snap(page, sink, 'c1e-05-upsell');
  const upsellTotals = await page.evaluate(() => document.body.innerText.match(/(Total|€)[^\n]*/gi)?.slice(0, 6) || []);
  L(`upsell montants visibles: ${JSON.stringify(upsellTotals)}`);
  const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
  await skip.first().click().catch(() => {});
  await page.waitForTimeout(1700);
}
await page.waitForTimeout(800);
await snap(page, sink, 'c1e-06-payment');
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`T3 PAYMENT: ${payTotal}`);
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
await snap(page, sink, 'c1e-07-cash');
const cashNum = await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
const cashAmt = await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT');
L(`T4 CASH: ${cashNum} ${cashAmt}`);
L(`ORDERS: ${JSON.stringify(sink.orders)}`);
const last = sink.orders[sink.orders.length - 1];
if (last?.id) {
  L(`DB ORDER: ${sql(`SELECT id,queue_number,total,discount FROM orders WHERE id=${last.id}`)}`);
  L(`DB coupons consommés: ${sql(`SELECT uses_count FROM kiosk_promos WHERE id=1`)} | user44 points APRÈS: ${sql("SELECT loyalty_points FROM users WHERE id=44")}`);
}
fs.writeFileSync(OUT + '_c1e-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C1e done');
