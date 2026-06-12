// VAGUE B dispute — refund UI bout-en-bout sur 4329 (encaissée espèces session 21, session CLOSE)
// + miroir (badge, montants négatifs, lien) + cash-overview après mutations
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d1-B-lib.mjs';

const L = mkLogger('b2-refund');
const { browser, page, state } = await boot();
const api = [];
page.on('response', async (r) => {
  if (/refund|cash-drawer/i.test(r.url()) && ['POST', 'PUT'].includes(r.request().method())) {
    let body = null; try { body = await r.json(); } catch {}
    api.push({ method: r.request().method(), status: r.status(), url: r.url().replace(BASE, ''), body });
    L(`API ${r.request().method()} ${r.status()} ${r.url().replace(BASE, '').slice(0, 110)} :: ${JSON.stringify(body)?.slice(0, 400)}`);
  }
});
const dlg = (tid) => page.locator(`[data-testid="${tid}"]`);

async function gotoShow(id) {
  await page.goto(`${BASE}/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  if (page.url().includes('/login')) {
    await login(page, L);
    await page.goto(`${BASE}/admin/pos-orders/show/${id}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
  }
}

await login(page, L);

// ── état avant refund ──
await gotoShow(4329);
const t0 = await bodyText(page);
L(`4329 avant: paiement=${JSON.stringify((t0.match(/Payé|À Encaisser|Remboursé|Espèces|Comptoir différé/g) || []).slice(0, 8))}`);
await snap(page, state, 'b2-01-order-4329-paid');
const refundBtn = dlg('pos-order-refund-open');
L(`refund btn present=${await refundBtn.count()}`);
if (await refundBtn.count()) {
  await refundBtn.click();
  await page.waitForTimeout(1200);
  const mTotal = await dlg('pos-refund-modal-total').innerText().catch(() => '?');
  const mMethod = await dlg('pos-refund-modal-payment-method').innerText().catch(() => '?');
  const mWarn = await dlg('pos-refund-modal-warning').innerText().catch(() => '(absent)');
  L(`modal: total="${mTotal.replace(/\s+/g, ' ')}" méthode="${mMethod.replace(/\s+/g, ' ')}" warning="${mWarn.replace(/\s+/g, ' ').slice(0, 160)}"`);
  const disabledBefore = await dlg('pos-refund-modal-confirm').isDisabled().catch(() => null);
  L(`confirm disabled avant raison: ${disabledBefore}`);
  await snap(page, state, 'b2-02-refund-modal-vide');
  // raison trop courte (4 chars) → bloqué ?
  await dlg('pos-refund-modal-reason').fill('test');
  await page.waitForTimeout(400);
  const disabledShort = await dlg('pos-refund-modal-confirm').isDisabled().catch(() => null);
  L(`confirm disabled raison 4 chars: ${disabledShort}`);
  await dlg('pos-refund-modal-reason').fill('Article rendu — test remboursement dispute round-1 vague B');
  await page.waitForTimeout(400);
  await snap(page, state, 'b2-03-refund-modal-rempli');
  await dlg('pos-refund-modal-confirm').click();
  await page.waitForTimeout(4000);
  await snap(page, state, 'b2-04-refund-confirmé');
  const t1 = await bodyText(page);
  L(`après confirm: marqueurs=${JSON.stringify((t1.match(/REMBOURS\S*|Remboursé|remboursement|miroir|avoir/gi) || []).slice(0, 10))}`);
} else {
  L('FAIL: bouton refund absent sur 4329 — conditions canRefund non remplies?');
  await snap(page, state, 'b2-02-refund-btn-absent');
}

// recharge le show père
await gotoShow(4329);
const t2 = await bodyText(page);
L(`4329 rechargé: badges=${JSON.stringify((t2.match(/Remboursé|REMBOURS\S*|Payé|À Encaisser/g) || []).slice(0, 8))}`);
const negs2 = (t2.match(/[-−]\s?\d+[.,]\d{2}\s?€/g) || []).slice(0, 8);
L(`montants négatifs père: ${JSON.stringify(negs2)}`);
await snap(page, state, 'b2-05-order-4329-apres-refund');

// trouver le miroir: lien sur la page ? sinon balayer les ids suivants
let mirrorId = null;
const link = page.locator('a[href*="pos-orders/show"]');
const hrefs = await link.evaluateAll((as) => as.map((a) => a.getAttribute('href')));
L(`liens show présents: ${JSON.stringify(hrefs.slice(0, 6))}`);
const mirrorHref = hrefs.find((h) => h && !h.includes('/4329'));
if (mirrorHref) {
  mirrorId = (mirrorHref.match(/show\/(\d+)/) || [])[1];
  L(`miroir via lien: ${mirrorHref} → id=${mirrorId}`);
}
if (!mirrorId) {
  // le refund API a renvoyé l'id du miroir ?
  const fromApi = api.find((a) => /refund/.test(a.url) && a.body);
  const d = fromApi?.body?.data || fromApi?.body;
  mirrorId = d?.refund_order_id || d?.mirror_order_id || d?.id;
  L(`miroir via API: ${mirrorId}`);
}
if (mirrorId && String(mirrorId) !== '4329') {
  await gotoShow(mirrorId);
  const t3 = await bodyText(page);
  L(`miroir ${mirrorId}: marqueurs=${JSON.stringify((t3.match(/REMBOURS\S*|Remboursé|Avoir|parent|origine/gi) || []).slice(0, 10))}`);
  const negs3 = (t3.match(/[-−]\s?\d+[.,]\d{2}\s?€/g) || []).slice(0, 10);
  L(`montants négatifs miroir: ${JSON.stringify(negs3)}`);
  await snap(page, state, 'b2-06-order-miroir');
}

// ── cash-overview après mutations ──
await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3000);
if (page.url().includes('/login')) { await login(page, L); await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(3000); }
const ov = await bodyText(page);
fs.writeFileSync(OUT + '_b2-cash-overview-text.txt', ov);
L(`cash-overview extrait: ${ov.replace(/\n+/g, ' | ').slice(0, 1200)}`);
await snap(page, state, 'b2-07-cash-overview');
// scroll bas
await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
await page.waitForTimeout(800);
await snap(page, state, 'b2-08-cash-overview-bas');

fs.writeFileSync(OUT + '_b2-api-responses.json', JSON.stringify(api, null, 2));
L.flush();
await browser.close();
console.log('DONE');
