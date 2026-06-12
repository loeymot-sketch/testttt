// VAGUE B ROUND 2 — suite session: vente POS directe (popup « Ajouter au panier ») + no-sale + mouvements
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, gotoPos, jsClick } from './_d2-B-lib.mjs';

const L = mkLogger('b3b-sale');
const { browser, page, state } = await boot();
const api = [];
page.on('response', async (r) => {
  if (/cash-drawer|no-sale|admin\/pos\b/i.test(r.url()) && ['POST', 'PUT'].includes(r.request().method())) {
    let body = null; try { body = await r.json(); } catch {}
    api.push({ method: r.request().method(), status: r.status(), url: r.url().replace(BASE, ''), body });
    L(`API ${r.request().method()} ${r.status()} ${r.url().replace(BASE, '').slice(0, 110)} :: ${JSON.stringify(body)?.slice(0, 200)}`);
  }
});
const dlg = (tid) => page.locator(`[data-testid="${tid}"]`);

try {
  await login(page, L);
  await gotoPos(page, L);
  await page.waitForTimeout(1500);

  // ── vente directe: Coca-Cola 33cl, popup → Ajouter au panier ──
  await page.locator('button.pos-v5-category[aria-label="Boissons"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Coca-Cola 33cl")').first().click();
  await page.waitForTimeout(1200);
  // popup item → bouton Ajouter au panier
  await page.locator('button:has-text("Ajouter au panier")').first().click().catch(async () => jsClick(page, '.wizard-btn-cart'));
  await page.waitForTimeout(1200);
  const cart = await page.evaluate(() => Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 120)));
  L(`panier: ${JSON.stringify(cart)}`);
  await snap(page, state, 'b3-06b-pos-cart-coca');

  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2800);
  await page.fill('#cashInput', '2').catch(() => L('WARN cashInput introuvable'));
  await page.waitForTimeout(800);
  const change = await page.locator('.pos-v5-payment-change-value').textContent().catch(() => 'ABSENT');
  L(`reçu 2,00 → rendu="${change?.trim()}"`);
  await snap(page, state, 'b3-07-pos-payment-cash-2');
  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /admin\/pos\b/.test(r.url()) && !/quote/.test(r.url()), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  let saleId = null, saleSerial = null, saleTotal = null;
  if (resp) {
    try { const j = await resp.json(); saleId = j?.data?.id; saleSerial = j?.data?.order_serial_no; saleTotal = j?.data?.total; } catch {}
    L(`POST /admin/pos → ${resp.status()} order id=${saleId} serial=${saleSerial} total=${saleTotal}`);
  } else L('FAIL: pas de réponse POST vente directe');
  await page.waitForTimeout(3000);
  const receiptText = await page.evaluate(() => document.querySelector('#print-receipt-client')?.innerText || 'ABSENT');
  L(`receipt extrait: ${receiptText.replace(/\n+/g, ' | ').slice(0, 500)}`);
  await snap(page, state, 'b3-08-pos-receipt');
  fs.writeFileSync(OUT + '_b3-sale-meta.json', JSON.stringify({ saleId, saleSerial, saleTotal }, null, 2));
  await page.keyboard.press('Escape').catch(() => {});
  await page.evaluate(() => { const m = document.querySelector('#receiptModal'); if (m) { m.querySelectorAll('button').forEach((b) => { if (/fermer|close|nouvelle/i.test(b.innerText)) b.click(); }); } });
  await page.waitForTimeout(1200);

  // ── no-sale ──
  await gotoPos(page, L);
  await page.waitForTimeout(1500);
  const noSale = dlg('pos-no-sale');
  L(`pos-no-sale présent=${await noSale.count()}`);
  if (await noSale.count()) {
    await noSale.click();
    await page.waitForTimeout(2200);
    const dialogTxt = await page.evaluate(() => {
      const overlays = Array.from(document.querySelectorAll('div')).filter((d) => {
        const s = getComputedStyle(d); return s.position === 'fixed' && s.zIndex > 10 && d.offsetHeight > 100;
      });
      return overlays.map((o) => o.innerText.replace(/\s+/g, ' ').slice(0, 250)).slice(-1)[0] || '(pas de dialog visible)';
    });
    L(`après clic no-sale: ${dialogTxt}`);
    await snap(page, state, 'b3-09-no-sale');
    const conf = page.locator('[data-testid="no-sale-confirm"], button:has-text("Confirmer")').first();
    if (await conf.count()) {
      await page.locator('[data-testid="no-sale-reason"], textarea').first().fill('Ouverture tiroir test round-2 vague B').catch(() => {});
      await conf.click().catch(() => {});
      await page.waitForTimeout(2000);
      await snap(page, state, 'b3-10-no-sale-confirmed');
    }
  }

  // ── mouvements + stats ──
  const already = await dlg('cash-session-overlay').isVisible().catch(() => false);
  if (!already) { await dlg('pos-cash-session-open').click(); await page.waitForTimeout(1200); }
  await dlg('cash-session-view-movements').click().catch(() => L('WARN bouton mouvements introuvable'));
  await page.waitForTimeout(1500);
  const rows = await page.locator('[data-testid="cash-session-movement-row"]').allTextContents();
  L(`mouvements (${rows.length}):`);
  rows.forEach((r, i) => L(`  mvt#${i + 1}: ${r.replace(/\s+/g, ' | ').trim().slice(0, 170)}`));
  await snap(page, state, 'b3-11-movements');
  await page.locator('.cash-session-dialog__btn--ghost:has-text("Retour"), .cash-session-dialog__actions button').first().click().catch(() => {});
  await page.waitForTimeout(900);
  const exp2 = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
  const mvtCount = await dlg('cash-session-stat-mvt-count').innerText().catch(() => '?');
  L(`stats session: expected="${exp2.replace(/\s+/g, ' ')}" mvts="${mvtCount.replace(/\s+/g, ' ')}"`);
  await snap(page, state, 'b3-12-session-stats');
} finally {
  fs.writeFileSync(OUT + '_b3b-api-responses.json', JSON.stringify(api, null, 2));
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
