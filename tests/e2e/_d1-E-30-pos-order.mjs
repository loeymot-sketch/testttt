// D1 VAGUE E — étape 3 : commande CAISSE directe (Tiramisu 3,80 + Eau Plate 1,00 = 4,80 €, espèces 5,00)
// puis suivi cross-surface (mêmes surfaces que la borne)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d1-E-lib.mjs';

const TAG = 'E30';
const L = makeLogger(`${TAG}-pos`);
const { browser, page, consoleBuf, netBuf } = await boot();

const created = [];
page.on('response', async (r) => {
  if (r.request().method() === 'POST' && /admin\/pos\b/.test(r.url()) && !/quote|counter-collect/.test(r.url()) && r.status() < 300) {
    try { const j = await r.json(); if (j?.data?.id) { created.push(j.data); L(`ORDER-POST ${r.status()} id=${j.data.id} serial=${j.data.order_serial_no} total=${j.data.total}`); } } catch {}
  }
});

const jsClick = (sel) => page.evaluate((s) => { const el = document.querySelector(s); if (el) { el.click(); return true; } return false; }, sel);

await login(page);
L('login admin OK');

// --- POS ---
for (let attempt = 1; attempt <= 3; attempt++) {
  await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  if (page.url().includes('/login')) { await login(page); continue; }
  if (await page.waitForSelector('.pos-v5-tile', { timeout: 20000 }).then(() => true).catch(() => false)) break;
  await login(page);
}
const openCaisse = page.locator('button:has-text("Ouvrir la caisse")');
if (await openCaisse.isVisible().catch(() => false)) { await openCaisse.click(); await page.waitForTimeout(3000); L('session caisse ouverte'); }
for (let i = 0; i < 3; i++) {
  const ov = page.locator('[data-testid="cash-session-overlay"]');
  if (!(await ov.isVisible().catch(() => false))) break;
  await page.locator('[data-testid="cash-session-close"]').click().catch(() => {});
  await page.waitForTimeout(1200);
}

// --- baseline cash-overview (DB-side fait foi, mais on capture) ---
// (capture légère : on reste sur POS pour limiter la fenêtre de token-churn)

// --- ajouter Tiramisu (Desserts) + Eau Plate 50cl (Boissons) ---
async function addTile(category, tileName) {
  await page.locator(`button.pos-v5-category[aria-label="${category}"]`).first().click().catch(() => L(`WARN cat ${category} introuvable`));
  await page.waitForTimeout(1400);
  await page.locator(`.pos-v5-tile:has-text("${tileName}")`).first().click().catch(() => L(`WARN tuile ${tileName} introuvable`));
  for (let w = 0; w < 10; w++) {
    await page.waitForTimeout(450);
    if (await page.evaluate(() => !!document.querySelector('.wizard-btn-cart'))) { await jsClick('.wizard-btn-cart'); break; }
  }
  await page.waitForTimeout(1000);
}
await addTile('Desserts', 'Tiramisu');
await addTile('Boissons', 'Eau Plate 50cl');
const cartLines = await page.evaluate(() => Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 120)));
const grandTotal = (await page.locator('[data-testid="pos-grand-total"]').textContent().catch(() => 'n/a') || '').replace(/\s+/g, ' ').trim();
L(`CART POS: ${JSON.stringify(cartLines)} grandTotal="${grandTotal}"`);
await quartet(page, consoleBuf, netBuf, `${TAG}-01-pos-cart`);

// --- payer : Commander → PaymentComponent (FROZEN, observation seule) ---
await page.locator('[data-testid="pos-v5-pay"]').click();
await page.waitForTimeout(3000);
const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
L(`PAYMENT modal total="${(payTotal || '').trim()}"`);
await quartet(page, consoleBuf, netBuf, `${TAG}-02-payment-modal`);

await page.fill('#cashInput', '5').catch(() => L('WARN #cashInput introuvable'));
await page.waitForTimeout(900);
const change = await page.locator('.pos-v5-payment-change-value').textContent().catch(() => 'ABSENT');
L(`espèces reçu=5,00 rendu="${(change || '').trim()}"`);
await quartet(page, consoleBuf, netBuf, `${TAG}-03-cash-5`);

const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /admin\/pos\b/.test(r.url()) && !/quote/.test(r.url()), { timeout: 25000 }).catch(() => null);
await page.locator('[data-testid="pos-payment-confirm"]').click();
const resp = await respP;
L(`confirm POST → ${resp ? resp.status() : 'AUCUNE RÉPONSE'}`);
await page.waitForTimeout(3500);
await quartet(page, consoleBuf, netBuf, `${TAG}-04-apres-confirm`);

// --- receipt POS (client) ---
const receiptText = await page.evaluate(() => {
  const r = document.querySelector('#print-receipt-client') || document.getElementById('print');
  return r ? (r.innerText || r.textContent) : null;
});
const rcLines = (receiptText || '').split('\n').map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
L(`RECEIPT POS (${rcLines.length} lignes):\n  ${rcLines.join('\n  ')}`);
fs.writeFileSync(`${OUT}_${TAG}-receipt.txt`, rcLines.join('\n') || 'ABSENT');
await quartet(page, consoleBuf, netBuf, `${TAG}-05-receipt`);

const order = created[created.length - 1];
fs.writeFileSync(`${OUT}_${TAG}-order.json`, JSON.stringify({ cartLines, grandTotal, payTotal, change, created }, null, 2));
L(`ORDER POS FINAL: ${JSON.stringify(order ? { id: order.id, serial: order.order_serial_no, queue: order.queue_number, subtotal: order.subtotal, discount: order.discount, total_tax: order.total_tax, total: order.total } : null)}`);
L.flush();
await browser.close();
