// DISPUTE round-2 Vague C — C-10 : HEAL C-RED-01 — promo BORNEAUDIT5 affichée ET FACTURÉE
// + uses_count incrémenté + copy payment/cash-instruction (C-ADV-06) + numéro de file #1.
import { boot, snap2, kioskBoot, startFlow, addProduct, readCartTotals, BASE, log2, OUT2 } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c10-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

L(`AVANT: kiosk_promos#1 uses_count=${sql("SELECT uses_count FROM kiosk_promos WHERE id=1").split('\n').pop()} | max order=${sql("SELECT MAX(id) FROM orders").split('\n').pop()}`);

const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

const r1 = await addProduct(page, 5, 26); // Tacos (wizard)
L(`add Tacos(26): ${JSON.stringify(r1).slice(0, 200)}`);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1600);
L(`T0 cart: ${JSON.stringify(await readCartTotals(page))}`);
await snap2(page, sink, 'c10-01-cart-before-promo');

// PROMO BORNEAUDIT5 (espacement bursts >=1s respecté: 1 seule tentative attendue valide)
let promoOk = false;
for (let a = 1; a <= 3 && !promoOk; a++) {
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.waitForTimeout(1100);
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2600);
  const t = await readCartTotals(page);
  if (t.promo) { promoOk = true; L(`promo OK (essai ${a}): ${JSON.stringify(t)}`); break; }
  const err = await page.locator('#kiosk-cart-promo-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`promo essai ${a} échec err="${err}" — attente 15s`);
  await page.waitForTimeout(15000);
}
const t1 = await readCartTotals(page);
await snap2(page, sink, 'c10-02-cart-promo-applied');
L(`T1 cart avec promo: ${JSON.stringify(t1)}`);

// checkout → loyalty(skip) → upsell(skip) → payment
await page.locator('[data-testid="kiosk-cart-checkout"]').click();
await page.waitForTimeout(2400);
for (let hop = 0; hop < 4 && !page.url().includes('/payment'); hop++) {
  if (page.url().includes('/loyalty')) {
    const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
    if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
  } else if (page.url().includes('/upsell')) {
    const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
    await skip.first().click().catch(() => {});
    await page.waitForTimeout(1700);
  } else await page.waitForTimeout(900);
}
L(`payment url=${page.url().replace(BASE, '')}`);
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
const payCopy = await page.evaluate(() => document.body.innerText.match(/[^\n]*(espèces|carte|ticket|caisse)[^\n]*/gi)?.slice(0, 8) || []);
L(`T2 PAYMENT total="${payTotal}" copy=${JSON.stringify(payCopy)}`);
await snap2(page, sink, 'c10-03-payment-promo');

await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
await snap2(page, sink, 'c10-04-cash-instruction');
const cashNum = (await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
const cashAmt = (await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
const cashCopy = await page.evaluate(() => document.body.innerText.match(/[^\n]*(espèces|carte|ticket|uniquement|caisse)[^\n]*/gi)?.slice(0, 8) || []);
L(`T3 CASH num="${cashNum}" amount="${cashAmt}" copy(C-ADV-06)=${JSON.stringify(cashCopy)}`);
L(`ORDERS API: ${JSON.stringify(sink.orders)}`);

const last = sink.orders.filter(o => o.id).pop();
if (last?.id) {
  L(`DB ORDER: ${sql(`SELECT id,queue_number,subtotal,discount,total,status FROM orders WHERE id=${last.id}`)}`);
  L(`DB uses_count APRÈS: ${sql("SELECT uses_count FROM kiosk_promos WHERE id=1").split('\n').pop()}`);
}
fs.writeFileSync(OUT2 + '_c10-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C10 done');
