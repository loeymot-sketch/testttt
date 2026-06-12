// DISPUTE round-2 Vague C — C-11c : fidélité avec TRACE COMPLÈTE des payloads
// (quote + order + loyalty/*) pour prouver où la remise se perd (ou confirmer le heal).
import { boot, snap2, kioskBoot, startFlow, addProduct, readCartTotals, BASE, log2, OUT2, skipToPayment } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c11c-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

L(`AVANT: user44 points=${sql("SELECT loyalty_points FROM users WHERE id=44").split('\n').pop()}`);

const { browser, page, sink } = await boot();
const trace = [];
page.on('response', async (r) => {
  const u = r.url();
  if (!/api\/frontend\/(order|loyalty)/.test(u)) return;
  const req = r.request();
  let body = req.postData() || null;
  let resp = null;
  try { resp = JSON.stringify(await r.json()).slice(0, 1500); } catch {}
  trace.push({ t: new Date().toISOString().slice(11, 23), m: req.method(), u: u.replace(BASE, ''), status: r.status(), reqBody: body ? body.slice(0, 1200) : null, resp });
});

await kioskBoot(page);
await startFlow(page);
let r1 = { ok: false };
for (let a = 1; a <= 3 && !r1.ok; a++) {
  r1 = await addProduct(page, 2, 23);
  L(`add Galette(23) essai ${a}: ${JSON.stringify(r1).slice(0, 120)}`);
  if (!r1.ok) { await kioskBoot(page); await startFlow(page); }
}
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1400);
await kioskBoot(page);
await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1100);
await page.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await page.waitForTimeout(1300);
let loyaltyOk = false;
for (let a = 1; a <= 4 && !loyaltyOk; a++) {
  const input = page.locator('.kiosk-loyalty-input').first();
  if (await input.isVisible().catch(() => false)) { try { await input.fill('VICT1234'); } catch {} }
  await page.waitForTimeout(1100);
  await page.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
  await page.waitForTimeout(2800);
  const bal = await page.evaluate(() => document.querySelector('.kiosk-loyalty-points-badge')?.innerText?.replace(/\n/g, ' ') || null);
  if (bal) { loyaltyOk = true; L(`balance (essai ${a}): ${bal}`); break; }
  const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
  L(`fidélité essai ${a} échec err="${err}" — attente 65s (throttle 10/min partagé)`);
  await page.waitForTimeout(65000);
}
try {
await page.locator('.kiosk-loyalty-option').first().click({ timeout: 15000 });
await page.waitForTimeout(700);
await page.locator('.kiosk-btn-primary.full').first().click();
await page.waitForTimeout(1600);
L(`confirmé: ${await page.evaluate(() => document.querySelector('.kiosk-loyalty-confirm-card')?.innerText?.replace(/\n/g, ' | ') || 'ABSENT')}`);
const stVuex = await page.evaluate(() => { try { const c = JSON.parse(localStorage.vuex).kioskCart; return { loyaltyDiscount: c.loyaltyDiscount, loyaltyCustomer: c.loyaltyCustomer?.id || null }; } catch { return null; } });
L(`store loyalty: ${JSON.stringify(stVuex)}`);
await page.locator('.kiosk-loyalty-confirm-card .kiosk-btn-primary.full').click();
await page.waitForTimeout(2200);
await skipToPayment(page, BASE);
const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
L(`PAYMENT: "${payTotal}"`);
await snap2(page, sink, 'c11c-01-payment-loyalty');
await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
await page.waitForTimeout(4500);
const cashAmt = (await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
const cashNum = (await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
L(`CASH num="${cashNum}" amount="${cashAmt}"`);
await snap2(page, sink, 'c11c-02-cash-loyalty');
L(`ORDERS API: ${JSON.stringify(sink.orders)}`);
const last = sink.orders.filter(o => o.id).pop();
if (last?.id) {
  L(`DB ORDER: ${sql(`SELECT id,queue_number,subtotal,discount,total FROM orders WHERE id=${last.id}`).replace(/\n/g, ' | ')}`);
  L(`DB user44 APRÈS: ${sql("SELECT loyalty_points FROM users WHERE id=44").split('\n').pop()}`);
  L(`DB ledger TOP2: ${sql(`SELECT id,type,points,balance_after,order_id FROM loyalty_transactions WHERE user_id=44 ORDER BY id DESC LIMIT 2`).replace(/\n/g, ' || ')}`);
}
} catch (e) { L(`FLOW FAIL: ${String(e).split('\n')[0]}`); await snap2(page, sink, 'c11c-99-flow-fail'); }
fs.writeFileSync(OUT2 + '_c11c-trace.json', JSON.stringify(trace, null, 1));
L(`trace écrite: ${trace.length} échanges API`);
await browser.close();
L('C11c done');
