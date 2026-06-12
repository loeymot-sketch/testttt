// D2 VAGUE E — étape 4 : encaisser 4516 (A0005) ESPÈCES puis REFUND
// Vérifie E-ADV-3 (wallet admin PAS crédité) + E-ADV-5 (ledger libellé mode réel)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d2-E-lib.mjs';

const ORDER_ID = 4516, QUEUE = 'A0005', SERIAL = '1206264516';
const L = makeLogger('E40-refund');
const { browser, page, consoleBuf, netBuf } = await boot();
await login(page);

// --- 1) encaisser 4516 espèces ---
await gotoAdmin(page, '/admin/encaissement');
await page.waitForTimeout(3000);
const todayCard = page.locator('.enc-ticket')
  .filter({ has: page.locator('.enc-queue', { hasText: QUEUE }) })
  .filter({ hasNot: page.locator('[data-testid^="enc-day-badge"]') });
const n = await todayCard.count();
L(`cartes ${QUEUE} du jour: ${n}`);
await todayCard.first().locator('.enc-collect-btn').click();
await page.waitForTimeout(2000);
const hero = await page.locator('[data-testid="pos-counter-collect-total"]').innerText().catch(() => null);
L(`modal hero="${hero}"`);
await page.locator('[data-testid="pos-counter-collect-mode-CASH"]').click().catch(() => {});
await page.waitForTimeout(700);
const heroNum = (hero || '').match(/(\d+[\d\s.,]*)/)?.[1]?.replace(/\s/g, '').replace(',', '.') || '5.00';
await page.locator('[data-testid="pos-counter-collect-received-input"]').fill(String(parseFloat(heroNum)));
await page.waitForTimeout(600);
await page.locator('[data-testid="pos-counter-collect-confirm"]').click();
await page.waitForTimeout(4000);
L('4516 encaissé espèces');
await quartet(page, consoleBuf, netBuf, 'E40-01-encaisse-4516-especes');

// --- 2) show + refund ---
await gotoAdmin(page, `/admin/pos-orders/show/${ORDER_ID}`);
await page.waitForTimeout(3000);
await quartet(page, consoleBuf, netBuf, 'E40-02-show-4516-paye');
const refundBtn = page.locator('[data-testid="pos-order-refund-open"]');
if (!(await refundBtn.isVisible().catch(() => false))) { L('ANOMALIE: bouton Rembourser invisible'); }
await refundBtn.click();
await page.waitForTimeout(1500);
const modal = await page.evaluate(() => {
  const q = (s) => document.querySelector(s)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
  return {
    title: q('[data-testid="pos-refund-modal-title"]'),
    total: q('[data-testid="pos-refund-modal-total"]'),
    method: q('[data-testid="pos-refund-modal-payment-method"]'),
    warning: q('[data-testid="pos-refund-modal-warning"]'),
  };
});
L(`REFUND MODAL: ${JSON.stringify(modal)}`);
await quartet(page, consoleBuf, netBuf, 'E40-03-refund-modal');
await page.locator('[data-testid="pos-refund-modal-reason"]').fill('Trace intégrité round-2 vague E');
await page.waitForTimeout(400);
await page.locator('[data-testid="pos-refund-modal-confirm"]').click();
await page.waitForTimeout(4500);
const err = await page.locator('[data-testid="pos-refund-modal-error"]').innerText().catch(() => null);
if (err) L(`REFUND ERROR: ${err}`);
await quartet(page, consoleBuf, netBuf, 'E40-04-apres-refund');
const showAfter = await page.evaluate(() => document.body.innerText.split('\n').map((s) => s.trim()).filter((l) => /REMBOURS|Annulé|Retour|€|miroir/i.test(l)).slice(0, 15));
L(`SHOW après refund: ${JSON.stringify(showAfter)}`);

// --- 3) transactions ---
await gotoAdmin(page, '/admin/transactions');
await page.waitForTimeout(3000);
const txInfo = await page.evaluate((needles) => {
  const rows = Array.from(document.querySelectorAll('table tbody tr')).map((tr) => Array.from(tr.querySelectorAll('td')).map((td) => td.innerText.replace(/\s+/g, ' ').trim()));
  return { mine: rows.filter((r) => needles.some((n) => r.join('|').includes(n))).slice(0, 6), first5: rows.slice(0, 5), total: rows.length };
}, [SERIAL, '1206264530', '4516']);
L(`TRANSACTIONS: ${JSON.stringify(txInfo.mine)}`);
L(`TRANSACTIONS first5: ${JSON.stringify(txInfo.first5)}`);
await quartet(page, consoleBuf, netBuf, 'E40-05-transactions');

// --- 4) cash-overview après refund ---
await gotoAdmin(page, '/admin/cash-overview');
await page.waitForTimeout(2500);
const cash = await page.evaluate(() => document.body.innerText.split('\n').map((s) => s.trim()).filter((l) => /€/.test(l) || /GRAND|CAISSE|BORNE|Espèces|Carte|rembours/i.test(l)).slice(0, 50));
L(`CASH-OVERVIEW:\n  ${cash.join('\n  ')}`);
await quartet(page, consoleBuf, netBuf, 'E40-06-cash-overview');

fs.writeFileSync(`${OUT}_E40-refund.json`, JSON.stringify({ modal, showAfter, txInfo, cash }, null, 2));
L.flush();
await browser.close();
