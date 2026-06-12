// D1 VAGUE E — étape 3 (retry durci) : commande CAISSE espèces avec récupération anti-401
// (compte admin partagé entre vagues parallèles → tokens révoqués à tout moment)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login } from './_d2-E-lib.mjs';

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

async function ensurePos() {
  for (let a = 1; a <= 4; a++) {
    if (!page.url().includes('/admin/pos')) await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(1500);
    if (page.url().includes('/login')) { await login(page); continue; }
    const ok = await page.waitForSelector('.pos-v5-tile', { timeout: 12000 }).then(() => true).catch(() => false);
    if (ok) {
      const openCaisse = page.locator('button:has-text("Ouvrir la caisse")');
      if (await openCaisse.isVisible().catch(() => false)) { await openCaisse.click(); await page.waitForTimeout(2500); L('session caisse ouverte'); }
      for (let i = 0; i < 3; i++) {
        const ov = page.locator('[data-testid="cash-session-overlay"]');
        if (!(await ov.isVisible().catch(() => false))) break;
        await page.locator('[data-testid="cash-session-close"]').click().catch(() => {});
        await page.waitForTimeout(1000);
      }
      return true;
    }
    await login(page);
    await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
  }
  return false;
}

async function addTile(category, tileName) {
  await page.locator(`button.pos-v5-category[aria-label="${category}"]`).first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator(`.pos-v5-tile:has-text("${tileName}")`).first().click().catch(() => L(`WARN tuile ${tileName}`));
  for (let w = 0; w < 10; w++) {
    await page.waitForTimeout(400);
    if (await page.evaluate(() => !!document.querySelector('.wizard-btn-cart'))) { await jsClick('.wizard-btn-cart'); break; }
  }
  await page.waitForTimeout(900);
}

async function cartCount() { return page.locator('.pos-v5-cart-item').count().catch(() => 0); }

await login(page);
let done = false;
for (let attempt = 1; attempt <= 4 && !done; attempt++) {
  L(`=== tentative POS #${attempt} ===`);
  if (!(await ensurePos())) { L('POS injoignable'); continue; }
  // (re)construire le panier si nécessaire
  if ((await cartCount()) < 2) {
    // vider si partiel
    if ((await cartCount()) === 1) { await page.locator('.pos-v5-cart-item .pos-v5-cart-item__remove, .pos-v5-cart-item button:has-text("🗑")').first().click().catch(() => {}); await page.waitForTimeout(600); }
    await addTile('Desserts', 'Tiramisu');
    await addTile('Boissons', 'Eau Plate 50cl');
  }
  const lines = await page.evaluate(() => Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 100)));
  const grandTotal = (await page.locator('[data-testid="pos-grand-total"]').textContent().catch(() => 'n/a') || '').replace(/\s+/g, ' ').trim();
  L(`CART: ${JSON.stringify(lines)} total="${grandTotal}"`);
  if (lines.length < 2) { L('panier incomplet → retry'); continue; }
  if (attempt === 1) await quartet(page, consoleBuf, netBuf, `${TAG}-01-pos-cart`);

  // payer
  await page.locator('[data-testid="pos-v5-pay"]').click().catch(() => {});
  const modalUp = await page.waitForSelector('#cashInput', { timeout: 12000 }).then(() => true).catch(() => false);
  if (!modalUp) { L(`modal paiement PAS ouvert (url=${page.url().replace(BASE, '')}) → retry`); continue; }
  const payTotal = await page.locator('.pos-v5-payment-total-value').first().textContent().catch(() => 'ABSENT');
  L(`PAYMENT modal total="${(payTotal || '').trim()}"`);
  await quartet(page, consoleBuf, netBuf, `${TAG}-02-payment-modal`);

  await page.fill('#cashInput', '5').catch(() => L('WARN fill cashInput'));
  await page.waitForTimeout(800);
  const change = await page.locator('.pos-v5-payment-change-value').textContent().catch(() => 'ABSENT');
  L(`reçu=5,00 rendu="${(change || '').trim()}"`);
  await quartet(page, consoleBuf, netBuf, `${TAG}-03-cash-5`);

  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /admin\/pos\b/.test(r.url()) && !/quote/.test(r.url()), { timeout: 20000 }).catch(() => null);
  const clicked = await page.locator('[data-testid="pos-payment-confirm"]').click({ timeout: 8000 }).then(() => true).catch(() => false);
  if (!clicked) { L('confirm introuvable → retry'); continue; }
  const resp = await respP;
  L(`confirm POST → ${resp ? resp.status() : 'AUCUNE RÉPONSE'}`);
  if (resp && resp.status() === 401) { L('401 → re-login + retry'); await login(page); continue; }
  if (resp && resp.status() < 300) done = true;
  await page.waitForTimeout(3000);
  await quartet(page, consoleBuf, netBuf, `${TAG}-04-apres-confirm`);
}
L(`done=${done}`);

if (done) {
  const receiptText = await page.evaluate(() => {
    const r = document.querySelector('#print-receipt-client') || document.getElementById('print');
    return r ? (r.innerText || r.textContent) : null;
  });
  const rcLines = (receiptText || '').split('\n').map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
  L(`RECEIPT POS (${rcLines.length} lignes):\n  ${rcLines.join('\n  ')}`);
  fs.writeFileSync(`${OUT}_${TAG}-receipt.txt`, rcLines.join('\n') || 'ABSENT');
  await quartet(page, consoleBuf, netBuf, `${TAG}-05-receipt`);
}
const order = created[created.length - 1];
fs.writeFileSync(`${OUT}_${TAG}-order.json`, JSON.stringify({ created }, null, 2));
L(`ORDER POS FINAL: ${JSON.stringify(order ? { id: order.id, serial: order.order_serial_no, queue: order.queue_number, subtotal: order.subtotal, discount: order.discount, total_tax: order.total_tax, total: order.total } : null)}`);
L.flush();
await browser.close();
