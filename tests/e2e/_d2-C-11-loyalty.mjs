// DISPUTE round-2 Vague C — C-11 : HEAL C-RED-02 + wire-up — rachat fidélité borne :
// remise AFFICHÉE + FACTURÉE + points DÉBITÉS + ledger loyalty_transactions.
import { boot, snap2, kioskBoot, startFlow, addProduct, readCartTotals, BASE, log2, OUT2, skipToPayment } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c11-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

L(`AVANT: user44 = ${sql("SELECT id,loyalty_code,loyalty_points FROM users WHERE id=44").replace(/\n/g, ' | ')}`);
L(`AVANT: ledger user44 = ${sql("SELECT COUNT(*) FROM loyalty_transactions WHERE user_id=44").split('\n').pop()} rows`);

const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

const r1 = await addProduct(page, 2, 23); // Galette
L(`add Galette(23): ${JSON.stringify(r1).slice(0, 200)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1600);
L(`T0 cart: ${JSON.stringify(await readCartTotals(page))}`);
await snap2(page, sink, 'c11-01-cart-before-loyalty');

// token frais juste avant la fidélité (guerre de révocation agents parallèles)
await kioskBoot(page);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1200);

// FIDÉLITÉ — code carte VICT1234
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1300);
let loyaltyOk = false;
for (let a = 1; a <= 3 && !loyaltyOk; a++) {
  const input = page.locator('.kiosk-loyalty-input').first();
  if (await input.isVisible().catch(() => false)) { try { await input.fill('VICT1234'); } catch {} }
  await page.waitForTimeout(1100);
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(2800);
  const bal = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || null);
  if (bal) { loyaltyOk = true; L(`balance affichée (essai ${a}): ${bal}`); break; }
  const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`fidélité essai ${a} échec err="${err}" — attente 15s`);
  await page.waitForTimeout(15000);
}
await snap2(page, sink, 'c11-02-loyalty-balance');

// options de rachat
const opts = await page.locator('.kiosk-loyalty-option').allInnerTexts().catch(() => []);
L(`options rachat: ${JSON.stringify(opts.map(o => o.replace(/\n/g, ' ')))}`);
if (loyaltyOk && opts.length) {
  await page.locator('.kiosk-loyalty-option').first().click();
  await page.waitForTimeout(2400);
}
L(`après rachat → ${page.url().replace(BASE, '')}`);
if (!page.url().includes('/cart')) {
  const back = page.locator('.kiosk-back-btn').first();
  if (await back.isVisible().catch(() => false)) { await back.click(); await page.waitForTimeout(1200); }
  if (!page.url().includes('/cart')) { await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1400); }
}
const t1 = await readCartTotals(page);
L(`T1 cart après rachat fidélité: ${JSON.stringify(t1)}`);
await snap2(page, sink, 'c11-03-cart-loyalty-applied');

// checkout → payment → confirm
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2400);
await skipToPayment(page, BASE);
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`T2 PAYMENT: "${payTotal}" url=${page.url().replace(BASE, '')}`);
await snap2(page, sink, 'c11-04-payment-loyalty');
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
const cashNum = (await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
const cashAmt = (await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
L(`T3 CASH num="${cashNum}" amount="${cashAmt}"`);
await snap2(page, sink, 'c11-05-cash-loyalty');
L(`ORDERS API: ${JSON.stringify(sink.orders)}`);

const last = sink.orders.filter(o => o.id).pop();
if (last?.id) {
  L(`DB ORDER: ${sql(`SELECT id,queue_number,subtotal,discount,total,status FROM orders WHERE id=${last.id}`)}`);
  L(`DB user44 APRÈS: ${sql("SELECT loyalty_points FROM users WHERE id=44").split('\n').pop()}`);
  L(`DB ledger: ${sql(`SELECT id,type,points,balance_after,order_id,source_surface FROM loyalty_transactions WHERE user_id=44 ORDER BY id DESC LIMIT 3`)}`);
}
fs.writeFileSync(OUT2 + '_c11-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C11 done');
