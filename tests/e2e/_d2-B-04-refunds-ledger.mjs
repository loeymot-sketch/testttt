// VAGUE B ROUND 2 — heal B-R1-15: refund espèces → grand livre « Espèces » (PAS « Carte bancaire »)
// Refund 1: vente POS directe 4531 (cash). Refund 2: commande borne counter-collectée 4334 (counter_cash).
// Puis /admin/transactions: rows cash_back en Espèces, montants négatifs.
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d2-B-lib.mjs';

const L = mkLogger('b4-refunds');
const { browser, page, state } = await boot();
const api = [];
page.on('response', async (r) => {
  if (/refund|cash-drawer/i.test(r.url()) && ['POST', 'PUT'].includes(r.request().method())) {
    let body = null; try { body = await r.json(); } catch {}
    api.push({ method: r.request().method(), status: r.status(), url: r.url().replace(BASE, ''), body });
    L(`API ${r.request().method()} ${r.status()} ${r.url().replace(BASE, '').slice(0, 110)} :: ${JSON.stringify(body)?.slice(0, 300)}`);
  }
});
const dlg = (tid) => page.locator(`[data-testid="${tid}"]`);

async function gotoShow(id) {
  await page.goto(`${BASE}/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2800);
  if (page.url().includes('/login')) {
    await login(page, L);
    await page.goto(`${BASE}/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2800);
  }
}

async function refund(orderId, snapPrefix, reason) {
  await gotoShow(orderId);
  const t0 = await bodyText(page);
  L(`${orderId} avant: paiement=${JSON.stringify((t0.match(/Payé|À Encaisser|Remboursé|Espèces|Carte bancaire/g) || []).slice(0, 8))}`);
  await snap(page, state, `${snapPrefix}-before`);
  const btn = dlg('pos-order-refund-open');
  if (!(await btn.count())) { L(`FAIL refund btn absent sur ${orderId}`); return false; }
  await btn.click();
  await page.waitForTimeout(1200);
  const mTotal = await dlg('pos-refund-modal-total').innerText().catch(() => '?');
  const mMethod = await dlg('pos-refund-modal-payment-method').innerText().catch(() => '?');
  const mWarn = await dlg('pos-refund-modal-warning').innerText().catch(() => '(absent)');
  L(`modal refund ${orderId}: total="${mTotal.replace(/\s+/g, ' ')}" méthode="${mMethod.replace(/\s+/g, ' ')}" warning="${mWarn.replace(/\s+/g, ' ').slice(0, 150)}"`);
  await dlg('pos-refund-modal-reason').fill(reason);
  await page.waitForTimeout(400);
  await snap(page, state, `${snapPrefix}-modal`);
  await dlg('pos-refund-modal-confirm').click();
  await page.waitForTimeout(3800);
  await snap(page, state, `${snapPrefix}-after`);
  return true;
}

try {
  await login(page, L);
  await refund(4531, 'b4-01-refund-4531', 'Test round-2 vague B — remboursement vente directe espèces');
  await refund(4334, 'b4-02-refund-4334', 'Test round-2 vague B — remboursement encaissement comptoir');

  // ── grand livre /admin/transactions : les refunds doivent afficher Espèces ──
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const rows = await page.locator('table tbody tr').allTextContents();
  L(`transactions top ${rows.length}:`);
  rows.forEach((r, i) => L(`  row${i + 1}: ${r.replace(/\s+/g, ' ').trim().slice(0, 170)}`));
  await snap(page, state, 'b4-03-transactions-after-refunds');

  const txt = await bodyText(page);
  const carteRows = (txt.match(/TXN-[A-Za-z0-9]+[^\n]*Carte bancaire[^\n]*/g) || []).slice(0, 6);
  L(`rows « Carte bancaire » fraîches éventuelles: ${JSON.stringify(carteRows)}`);
} finally {
  fs.writeFileSync(OUT + '_b4-api-responses.json', JSON.stringify(api, null, 2));
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
