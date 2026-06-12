// DISPUTE round-2 Vague C — C-11b : HEAL C-RED-02 — flux fidélité COMPLET
// (option → Confirmer → écran confirmé → Continuer vers le paiement → commande).
import { boot, snap2, kioskBoot, startFlow, addProduct, readCartTotals, BASE, log2, OUT2, skipToPayment } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c11b-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

L(`AVANT: user44 points=${sql("SELECT loyalty_points FROM users WHERE id=44").split('\n').pop()}`);

const { browser, page, sink } = await boot();
await kioskBoot(page);
await startFlow(page);

let r1 = { ok: false };
for (let a = 1; a <= 3 && !r1.ok; a++) {
  r1 = await addProduct(page, 2, 23); // Galette 6,50
  L(`add Galette(23) essai ${a}: ${JSON.stringify(r1).slice(0, 160)}`);
  if (!r1.ok) { await kioskBoot(page); await startFlow(page); }
}
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);
L(`T0 cart: ${JSON.stringify(await readCartTotals(page))}`);

await kioskBoot(page); // token frais
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1200);
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
  if (bal) { loyaltyOk = true; L(`balance (essai ${a}): ${bal}`); break; }
  L(`fidélité essai ${a} échec — attente 15s`);
  await page.waitForTimeout(15000);
}
// étape 2: choisir « Utiliser mes points » PUIS Confirmer
await page.locator('.kiosk-loyalty-option').first().click();
await page.waitForTimeout(800);
await snap2(page, sink, 'c11b-01-option-selected');
await page.locator('.kiosk-btn-primary.full').first().click(); // Confirmer (applyLoyalty)
await page.waitForTimeout(1800);
const confirmTxt = await page.evaluate(() => document.querySelector('.kiosk-loyalty-confirm-card')?.innerText?.replace(/\n/g, ' | ') || 'ABSENT');
L(`écran confirmé: ${confirmTxt}`);
await snap2(page, sink, 'c11b-02-loyalty-confirmed');
// Continuer vers le paiement
await page.locator('.kiosk-loyalty-confirm-card .kiosk-btn-primary.full').click();
await page.waitForTimeout(2200);
L(`après continue → ${page.url().replace(BASE, '')}`);
await skipToPayment(page, BASE);
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`T2 PAYMENT: "${payTotal}" url=${page.url().replace(BASE, '')}`);
await snap2(page, sink, 'c11b-03-payment-loyalty');
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
const cashNum = (await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
const cashAmt = (await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
L(`T3 CASH num="${cashNum}" amount="${cashAmt}"`);
await snap2(page, sink, 'c11b-04-cash-loyalty');
L(`ORDERS API: ${JSON.stringify(sink.orders)}`);
const last = sink.orders.filter(o => o.id).pop();
if (last?.id) {
  L(`DB ORDER: ${sql(`SELECT id,queue_number,subtotal,discount,total,status FROM orders WHERE id=${last.id}`)}`);
  L(`DB user44 APRÈS: ${sql("SELECT loyalty_points FROM users WHERE id=44").split('\n').pop()}`);
  L(`DB ledger TOP3: ${sql(`SELECT id,type,points,balance_after,order_id,source_surface FROM loyalty_transactions WHERE user_id=44 ORDER BY id DESC LIMIT 3`).replace(/\n/g, ' || ')}`);
}
fs.writeFileSync(OUT2 + '_c11b-orders.json', JSON.stringify(sink.orders, null, 2));
await browser.close();
L('C11b done');
