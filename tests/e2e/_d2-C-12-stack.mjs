// DISPUTE round-2 Vague C — C-12 : preuve d'isolement du root-cause C-RED-02-R2
// (users.status=5 vs gate where('status',1)) + STACK promo+fidélité même commande.
// MUTATION TEST DOCUMENTÉE (DB jetable): user44.status 5→1 pendant le test, restauré après.
import { boot, snap2, kioskBoot, startFlow, addProduct, readCartTotals, BASE, log2, OUT2, skipToPayment } from './_d2-C-lib.mjs';
import { execSync } from 'child_process';
import fs from 'fs';

const L = (m) => log2('_c12-log.txt', m);
const sql = (q) => execSync(`mysql -u root foodking_e2e -e "${q}"`).toString().trim();

sql("UPDATE users SET status=1 WHERE id=44");
L(`MUTATION TEST: user44.status 5→1 → ${sql("SELECT id,status,loyalty_points FROM users WHERE id=44").replace(/\n/g, ' | ')}`);
L(`AVANT: promo uses_count=${sql("SELECT uses_count FROM kiosk_promos WHERE id=1").split('\n').pop()}`);

const { browser, page, sink } = await boot();
const trace = [];
page.on('response', async (r) => {
  const u = r.url();
  if (!/api\/frontend\/(order|loyalty|promo)/.test(u)) return;
  let resp = null; try { resp = JSON.stringify(await r.json()).slice(0, 900); } catch {}
  trace.push({ t: new Date().toISOString().slice(11, 23), m: r.request().method(), u: u.replace(BASE, ''), status: r.status(), reqBody: (r.request().postData() || '').slice(0, 900) || null, resp });
});

try {
  await kioskBoot(page);
  await startFlow(page);
  let r1 = { ok: false };
  for (let a = 1; a <= 3 && !r1.ok; a++) {
    r1 = await addProduct(page, 5, 26); // Tacos 11,50 (wizard)
    L(`add Tacos(26) essai ${a}: ${JSON.stringify(r1).slice(0, 120)}`);
    if (!r1.ok) { await kioskBoot(page); await startFlow(page); }
  }
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400);
  L(`T0: ${JSON.stringify(await readCartTotals(page))}`);

  // PROMO d'abord
  await page.locator('[data-testid="kiosk-cart-promo-input"]').fill('BORNEAUDIT5');
  await page.waitForTimeout(1100);
  await page.locator('[data-testid="kiosk-cart-promo-apply"]').click();
  await page.waitForTimeout(2600);
  L(`T1 promo: ${JSON.stringify(await readCartTotals(page))}`);
  await snap2(page, sink, 'c12-01-cart-promo');

  // FIDÉLITÉ ensuite (token frais)
  await kioskBoot(page);
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1100);
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
    const err = await page.locator('.kiosk-loyalty-error').innerText({ timeout: 1200 }).catch(() => null);
    L(`fidélité essai ${a} échec err="${err}" — attente 65s`);
    await page.waitForTimeout(65000);
  }
  await page.locator('.kiosk-loyalty-option').first().click({ timeout: 15000 });
  await page.waitForTimeout(700);
  await page.locator('.kiosk-btn-primary.full').first().click();
  await page.waitForTimeout(1600);
  await page.locator('.kiosk-loyalty-confirm-card .kiosk-btn-primary.full').click();
  await page.waitForTimeout(2200);
  // retour panier pour lire les DEUX lignes de remise stackées
  await page.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const t2 = await readCartTotals(page);
  L(`T2 STACK panier (promo+fidélité): ${JSON.stringify(t2)}`);
  await snap2(page, sink, 'c12-02-cart-stacked');

  await page.locator('[data-testid="kiosk-cart-checkout"]').click();
  await page.waitForTimeout(2400);
  await skipToPayment(page, BASE);
  const payTotal = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).replace(/\n/g, ' | ');
  L(`T3 PAYMENT: "${payTotal}"`);
  await snap2(page, sink, 'c12-03-payment-stacked');
  await page.locator('[data-testid="kiosk-payment-counter-confirm"]').click();
  await page.waitForTimeout(4500);
  const cashNum = (await page.locator('[data-testid="kiosk-cash-order-number"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
  const cashAmt = (await page.locator('[data-testid="kiosk-cash-amount"]').innerText({ timeout: 2500 }).catch(() => 'ABSENT')).trim();
  L(`T4 CASH num="${cashNum}" amount="${cashAmt}"`);
  await snap2(page, sink, 'c12-04-cash-stacked');
  L(`ORDERS API: ${JSON.stringify(sink.orders)}`);
  const last = sink.orders.filter(o => o.id).pop();
  if (last?.id) {
    L(`DB ORDER: ${sql(`SELECT id,queue_number,subtotal,discount,total FROM orders WHERE id=${last.id}`).replace(/\n/g, ' | ')}`);
    L(`DB user44 points APRÈS: ${sql("SELECT loyalty_points FROM users WHERE id=44").split('\n').pop()}`);
    L(`DB ledger TOP2: ${sql(`SELECT id,type,points,balance_after,order_id,source_surface FROM loyalty_transactions WHERE user_id=44 ORDER BY id DESC LIMIT 2`).replace(/\n/g, ' || ')}`);
    L(`DB promo uses_count APRÈS: ${sql("SELECT uses_count FROM kiosk_promos WHERE id=1").split('\n').pop()}`);
  }
} catch (e) {
  L(`FLOW FAIL: ${String(e).split('\n')[0]}`);
  await snap2(page, sink, 'c12-99-flow-fail');
} finally {
  sql("UPDATE users SET status=5 WHERE id=44");
  L(`RESTORE: user44.status → ${sql("SELECT status FROM users WHERE id=44").split('\n').pop()} (points laissés à l'état réel post-flux)`);
  fs.writeFileSync(OUT2 + '_c12-trace.json', JSON.stringify(trace, null, 1));
  await browser.close();
}
L('C12 done');
