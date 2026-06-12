// ADVERSARIAL R2 — Vague B caisse-gestion. Vérifs live prioritaires:
// (1) /admin/transactions BM: gateway 200 SANS secret, 0 console error, 0 >=400
// (2) badges date file encaissement (cartes 10/06) + VRAI scroll bas (comble b2-02≡b2-01)
// (3) vente POS directe NEUVE -> visible /admin/transactions ET cash-overview
// (4) historique: CLIENT d'une commande borne (B-R1-17 / E-ADV-3)
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, gotoPos, jsClick, bodyText } from './_d2-B-lib.mjs';

const L = mkLogger('red2-b1');
const { browser, page, state } = await boot();
const gateway = [];
page.on('response', async (r) => {
  if (/setting\/payment-gateway/.test(r.url()) && r.request().method() === 'GET') {
    let body = null; try { body = await r.text(); } catch {}
    gateway.push({ status: r.status(), url: r.url().replace(BASE, ''), body });
  }
});

try {
  // ── (1) transactions BM ──
  await login(page, L);
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  const secretRe = /(sk_live|sk_test|stripe_secret|secret_key|client_secret|whsec_|paypal_client)/i;
  for (const g of gateway) L(`GATEWAY ${g.status} ${g.url} bytes=${g.body?.length} secret=${secretRe.test(g.body || '') ? 'LEAK!!' : 'none'} body=${(g.body || '').slice(0, 200)}`);
  const errs = state.consoleBuf.filter((c) => /ERROR|PAGEERROR/.test(c) && !/6001|WebSocket/i.test(c));
  L(`console errors (hors WS): ${errs.length} ${JSON.stringify(errs.slice(0, 3))}`);
  L(`network >=400: ${state.netBuf.length} ${JSON.stringify(state.netBuf.slice(0, 3))}`);
  await snap(page, state, 'red2-b-01-transactions-bm');

  // ── (2) encaissement: badges + vrai scroll bas ──
  await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  const badges = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll('[data-testid^="enc-day-badge-"]'));
    return { count: els.length, texts: [...new Set(els.map((e) => e.textContent.trim()))], visible: els.filter((e) => e.getClientRects().length > 0).length };
  });
  L(`badges file: count=${badges.count} visible=${badges.visible} texts=${JSON.stringify(badges.texts)}`);
  const serials = await page.evaluate(() => Array.from(document.querySelectorAll('.enc-ticket')).map((t) => {
    const s = t.innerText.match(/N°(A\d+)/)?.[1] || '?';
    const b = t.querySelector('[data-testid^="enc-day-badge-"]')?.textContent.trim() || '';
    return s + (b ? `[${b}]` : '[today]');
  }));
  L(`ordre file (20 prem.): ${JSON.stringify(serials.slice(0, 20))}`);
  L(`ordre file (5 dern.): ${JSON.stringify(serials.slice(-5))}`);
  await snap(page, state, 'red2-b-02-encaissement-haut');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(1200);
  await snap(page, state, 'red2-b-03-encaissement-bas-REEL');

  // ── (3) vente POS directe neuve ──
  await gotoPos(page, L);
  await page.waitForTimeout(1500);
  await page.locator('button.pos-v5-category[aria-label="Boissons"]').first().click().catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('.pos-v5-tile:has-text("Coca-Cola 33cl")').first().click();
  await page.waitForTimeout(1500);
  await page.locator('button:has-text("Ajouter au panier")').first().click().catch(async () => jsClick(page, '.wizard-btn-cart'));
  await page.waitForTimeout(1200);
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2800);
  await page.fill('#cashInput', '2').catch(() => L('WARN cashInput introuvable'));
  await page.waitForTimeout(800);
  const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /admin\/pos\b/.test(r.url()) && !/quote/.test(r.url()), { timeout: 20000 }).catch(() => null);
  await page.locator('[data-testid="pos-payment-confirm"]').click();
  const resp = await respP;
  let saleId = null, saleSerial = null, saleTotal = null;
  if (resp) { try { const j = await resp.json(); saleId = j?.data?.id; saleSerial = j?.data?.order_serial_no; saleTotal = j?.data?.total; } catch {} L(`POST /admin/pos → ${resp.status()} id=${saleId} serial=${saleSerial} total=${saleTotal}`); }
  else L('FAIL: pas de réponse POST vente directe');
  await page.waitForTimeout(2500);
  await snap(page, state, 'red2-b-04-vente-directe-receipt');
  await page.keyboard.press('Escape').catch(() => {});
  await page.evaluate(() => { const m = document.querySelector('#receiptModal'); if (m) m.querySelectorAll('button').forEach((b) => { if (/fermer|close|nouvelle/i.test(b.innerText)) b.click(); }); });
  await page.waitForTimeout(1000);

  // ── (3b) la vente dans /admin/transactions ──
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const txTop = await page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).slice(0, 5).map((r) => r.innerText.replace(/\s+/g, ' ').trim()));
  L(`transactions top5: ${JSON.stringify(txTop)}`);
  const foundTx = txTop.some((r) => saleSerial && r.includes(String(saleSerial)));
  L(`VENTE ${saleSerial} DANS TRANSACTIONS: ${foundTx ? 'OUI' : 'NON'}`);
  await snap(page, state, 'red2-b-05-transactions-apres-vente');

  // ── (3c) la vente dans cash-overview ──
  await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  const ovText = await bodyText(page);
  fs.writeFileSync(OUT + '_red2-b-overview-text.txt', ovText);
  const caisseCard = ovText.match(/CAISSE\s*\n\s*([\d,]+\s?€)\s*\n\s*(\d+ tx)/);
  L(`carte CAISSE: ${caisseCard ? caisseCard[1] + ' / ' + caisseCard[2] : 'INTROUVABLE'}`);
  const rowFound = saleSerial ? ovText.includes('A' + String(saleSerial).slice(-4)) : false;
  const lines = ovText.split('\n').filter((l) => /1,50|Caisse/.test(l)).slice(0, 12);
  L(`overview lignes caisse échantillon: ${JSON.stringify(lines)}`);
  await snap(page, state, 'red2-b-06-cash-overview-apres-vente');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(900);
  await snap(page, state, 'red2-b-07-cash-overview-bas-REEL');

  // ── (4) historique: CLIENT d'une commande borne (4335 counter-collectée au state 3) ──
  await page.goto(BASE + '/admin/historique', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  await page.fill('input[placeholder*="echerch"], input[type="search"]', '1006264335').catch(async () => {
    const ok = await page.evaluate(() => { const i = Array.from(document.querySelectorAll('input')).find((x) => /commande|recherch/i.test(x.placeholder || '')); if (i) { i.value = '1006264335'; i.dispatchEvent(new Event('input', { bubbles: true })); return true; } return false; });
    L(`fallback search fill: ${ok}`);
  });
  await page.keyboard.press('Enter').catch(() => {});
  await page.locator('button:has-text("Rechercher")').first().click().catch(() => {});
  await page.waitForTimeout(2500);
  const histRows = await page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).slice(0, 4).map((r) => r.innerText.replace(/\s+/g, ' ').trim()));
  L(`historique recherche 4335: ${JSON.stringify(histRows)}`);
  await snap(page, state, 'red2-b-08-historique-borne-4335');

  L('DONE');
} catch (e) {
  L('FATAL ' + e.message);
  await snap(page, state, 'red2-b-99-fatal').catch(() => {});
} finally {
  L.flush();
  await browser.close();
}
