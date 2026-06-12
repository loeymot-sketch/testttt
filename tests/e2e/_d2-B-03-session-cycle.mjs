// VAGUE B ROUND 2 — session caisse complète (partie 1):
// ouverture fond 50€ → encaissement 4334 (6,90 exact) + 4335 (8,90, reçu 10,00) →
// vente POS directe fraîche (ADV-B-07) → no-sale → mouvements.
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, gotoPos, bodyText, jsClick } from './_d2-B-lib.mjs';

const L = mkLogger('b3-session');
const { browser, page, state } = await boot();
const api = [];
page.on('response', async (r) => {
  if (/cash-drawer|counter|collect|no-sale|admin\/pos\b|admin\/pos\?/i.test(r.url()) && ['POST', 'PUT'].includes(r.request().method())) {
    let body = null; try { body = await r.json(); } catch {}
    api.push({ method: r.request().method(), status: r.status(), url: r.url().replace(BASE, ''), body });
    L(`API ${r.request().method()} ${r.status()} ${r.url().replace(BASE, '').slice(0, 110)} :: ${JSON.stringify(body)?.slice(0, 220)}`);
  }
});
const dlg = (tid) => page.locator(`[data-testid="${tid}"]`);

async function openSessionDialog() {
  const already = await dlg('cash-session-overlay').isVisible().catch(() => false);
  if (already) { L('dialog session déjà ouvert (auto-open POS)'); return; }
  await dlg('pos-cash-session-open').click();
  await page.waitForTimeout(1200);
}
async function closeSessionDialog() {
  await dlg('cash-session-close').click().catch(() => {});
  await page.waitForTimeout(600);
}

try {
  await login(page, L);
  await gotoPos(page, L);
  await page.waitForTimeout(1500);

  // ── 1. OUVERTURE fond 50,00 € ──
  await openSessionDialog();
  let isActive = await dlg('cash-session-active-view').isVisible().catch(() => false);
  let isOpenForm = await dlg('cash-session-open-form').isVisible().catch(() => false);
  L(`dialog initial: active=${isActive} openForm=${isOpenForm}`);
  await snap(page, state, 'b3-01-session-dialog-initial');
  if (isActive) {
    // session résiduelle de CE compte → clôture propre au montant attendu
    await dlg('cash-session-go-close').click();
    await page.waitForTimeout(900);
    const exp = await dlg('cash-session-close-expected').innerText();
    const expNum = parseFloat(exp.replace(/[^\d,.-]/g, '').replace(',', '.'));
    L(`session résiduelle → clôture à l'attendu ${expNum}`);
    await dlg('cash-session-closing-input').fill(expNum.toFixed(2));
    await page.waitForTimeout(500);
    await dlg('cash-session-close-submit').click();
    await page.waitForTimeout(2500);
  }
  isOpenForm = await dlg('cash-session-open-form').isVisible().catch(() => false);
  if (!isOpenForm) { await closeSessionDialog(); await openSessionDialog(); }
  await dlg('cash-session-opening-input').fill('50');
  await page.waitForTimeout(400);
  const openDisplay = await dlg('cash-session-opening-display').innerText().catch(() => '?');
  L(`fond saisi 50 → display="${openDisplay.replace(/\s+/g, ' ')}"`);
  await snap(page, state, 'b3-02-session-open-form-50');
  await dlg('cash-session-open-submit').click();
  await page.waitForTimeout(2500);
  const statOpening = await dlg('cash-session-stat-opening').innerText().catch(() => '?');
  const statExpected = await dlg('cash-session-stat-expected').innerText().catch(() => '?');
  L(`session ouverte: opening="${statOpening.replace(/\s+/g, ' ')}" expected="${statExpected.replace(/\s+/g, ' ')}"`);
  await snap(page, state, 'b3-03-session-active-50');
  await closeSessionDialog();

  // ── 2. ENCAISSEMENTS ×2 via /admin/encaissement (ciblage par id) ──
  async function encaisser(orderId, received, snapPrefix) {
    await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const clicked = await page.evaluate((oid) => {
      const badge = document.querySelector(`[data-testid="enc-day-badge-${oid}"]`);
      let root = badge;
      for (let i = 0; i < 8 && root; i++) {
        const btn = root.querySelector?.('.enc-collect-btn');
        if (btn) { btn.click(); return 'OK'; }
        root = root.parentElement;
      }
      return 'NO-CARD-' + oid;
    }, orderId);
    L(`encaisse ${orderId}: clic carte=${clicked}`);
    if (clicked !== 'OK') return false;
    await page.waitForTimeout(2200);
    const total = await dlg('pos-counter-collect-total').innerText().catch(() => '?');
    const chip = await dlg('pos-counter-collect-day-badge').innerText().catch(() => '(pas de chip)');
    const cashMode = page.locator('[data-testid="pos-counter-collect-mode-cash"]');
    if (await cashMode.count()) { await cashMode.click(); await page.waitForTimeout(500); }
    L(`modal ${orderId}: total="${total.replace(/\s+/g, ' ')}" chip="${chip.replace(/\s+/g, ' ')}"`);
    if (received !== null) {
      const inp = dlg('pos-counter-collect-received-input');
      if (await inp.count()) {
        await inp.fill(String(received));
        await page.waitForTimeout(500);
        const change = await dlg('pos-counter-collect-change').innerText().catch(() => '(pas de rendu affiché)');
        L(`reçu=${received} → rendu="${change.replace(/\s+/g, ' ')}"`);
      }
    }
    await snap(page, state, `${snapPrefix}-modal`);
    await dlg('pos-counter-collect-confirm').click();
    await page.waitForTimeout(3200);
    await snap(page, state, `${snapPrefix}-after`);
    return true;
  }
  await encaisser(4334, null, 'b3-04-encaisse-4334');   // 6,90 € compte exact (input prérempli)
  await encaisser(4335, 10.0, 'b3-05-encaisse-4335');   // 8,90 € reçu 10,00 → rendu 1,10

  // ── 3. VENTE POS DIRECTE fraîche (ADV-B-07) : Coca-Cola 33cl 1,50 € espèces reçu 2,00 ──
  await gotoPos(page, L);
  await page.waitForTimeout(1500);
  await page.locator('button.pos-v5-category[aria-label="Boissons"]').first().click().catch(() => L('WARN cat Boissons introuvable'));
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Coca-Cola 33cl")').first().click();
  await page.waitForTimeout(1200);
  const cart = await page.evaluate(() => Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 120)));
  L(`panier: ${JSON.stringify(cart)}`);
  await snap(page, state, 'b3-06-pos-cart-coca');
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2800);
  await page.fill('#cashInput', '2').catch(() => L('WARN cashInput introuvable'));
  await page.waitForTimeout(800);
  const change = await page.locator('.pos-v5-payment-change-value').textContent().catch(() => 'ABSENT');
  L(`vente directe: reçu 2,00 → rendu="${change?.trim()}"`);
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
  L(`receipt (extrait): ${receiptText.replace(/\n+/g, ' | ').slice(0, 400)}`);
  await snap(page, state, 'b3-08-pos-receipt');
  fs.writeFileSync(OUT + '_b3-sale-meta.json', JSON.stringify({ saleId, saleSerial, saleTotal }, null, 2));
  // fermer le modal receipt
  await page.keyboard.press('Escape').catch(() => {});
  await jsClick(page, '#receiptModal .close, #receiptModal button');
  await page.waitForTimeout(1000);

  // ── 4. NO-SALE (tiroir hors-vente) ──
  await gotoPos(page, L);
  await page.waitForTimeout(1200);
  const noSale = dlg('pos-no-sale');
  L(`pos-no-sale présent=${await noSale.count()}`);
  if (await noSale.count()) {
    await noSale.click();
    await page.waitForTimeout(2200);
    const dialogTxt = await page.evaluate(() => {
      const cand = document.querySelector('[data-testid="no-sale-dialog"], .no-sale-dialog, .swal2-popup, [class*="dialog"]:not([style*="display: none"])');
      return cand ? cand.innerText.replace(/\s+/g, ' ').slice(0, 300) : '(pas de dialog visible)';
    });
    L(`no-sale dialog: ${dialogTxt}`);
    await snap(page, state, 'b3-09-no-sale');
    // confirmer si un dialog avec raison existe
    const reasonInp = page.locator('[data-testid="no-sale-reason"], textarea, input[placeholder*="aison"]').first();
    if (await page.locator('[data-testid="no-sale-confirm"]').count()) {
      await reasonInp.fill('Ouverture tiroir test round-2 vague B').catch(() => {});
      await page.locator('[data-testid="no-sale-confirm"]').click();
      await page.waitForTimeout(2000);
      await snap(page, state, 'b3-10-no-sale-confirmed');
    }
  }

  // ── 5. MOUVEMENTS + stats session ──
  await openSessionDialog();
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
  L(`stats session après mutations: expected="${exp2.replace(/\s+/g, ' ')}" mvts="${mvtCount.replace(/\s+/g, ' ')}"`);
  await snap(page, state, 'b3-12-session-stats');
  await closeSessionDialog();
} finally {
  fs.writeFileSync(OUT + '_b3-api-responses.json', JSON.stringify(api, null, 2));
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
