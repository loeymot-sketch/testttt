// D1 VAGUE E — étape 2b : encaissement espèces 4531 avec résilience 401 + extraction receipt #print
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d1-E-lib.mjs';

const ORDER_ID = Number(process.env.E_ORDER_ID || 4531);
const QUEUE = process.env.E_QUEUE || 'A0006';
const TAG = process.env.E_TAG || 'E21';
const L = makeLogger(`${TAG}-collect`);
const { browser, page, consoleBuf, netBuf } = await boot();

let confirmStatus = null;
let confirmResp = null;
page.on('response', async (r) => {
  if (r.url().includes(`counter-collect/${ORDER_ID}/confirm`)) {
    confirmStatus = r.status();
    try { confirmResp = await r.json(); } catch {}
    L(`CONFIRM-POST status=${r.status()}`);
  }
});

await login(page);
L('login OK');

let collected = false;
for (let attempt = 1; attempt <= 3 && !collected; attempt++) {
  L(`tentative encaissement #${attempt}`);
  await gotoAdmin(page, '/admin/encaissement');
  await page.waitForTimeout(3000);
  const cardSel = `.enc-ticket:has(.enc-queue:has-text("${QUEUE}"))`;
  const present = await page.locator(cardSel).first().isVisible().catch(() => false);
  if (!present) { L(`carte ${QUEUE} absente de la file (déjà encaissée ?) → stop`); break; }
  await page.locator(`${cardSel} .enc-collect-btn`).first().click();
  await page.waitForTimeout(2000);
  const hero = await page.locator('[data-testid="pos-counter-collect-total"]').innerText().catch(() => null);
  L(`modal hero="${hero}"`);
  if (attempt === 1) await quartet(page, consoleBuf, netBuf, `${TAG}-01-modal`);
  await page.locator('[data-testid="pos-counter-collect-mode-CASH"]').click().catch(() => {});
  await page.waitForTimeout(700);
  const heroNum = (hero || '').match(/(\d+[\d\s.,]*)/)?.[1]?.replace(/\s/g, '').replace(',', '.') || '10.00';
  await page.locator('[data-testid="pos-counter-collect-received-input"]').fill(String(parseFloat(heroNum)));
  await page.waitForTimeout(500);
  confirmStatus = null;
  await page.locator('[data-testid="pos-counter-collect-confirm"]').click();
  for (let w = 0; w < 16 && confirmStatus === null; w++) await page.waitForTimeout(500);
  L(`résultat confirm: ${confirmStatus} resp=${JSON.stringify(confirmResp)?.slice(0, 300)}`);
  if (confirmStatus === 200 || confirmStatus === 201) { collected = true; break; }
  if (confirmStatus === 401) { L('401 → re-login + retry'); await login(page); continue; }
  if (confirmStatus === null) { L('aucune réponse confirm captée → re-check la file'); }
}
await page.waitForTimeout(2500);
await quartet(page, consoleBuf, netBuf, `${TAG}-02-apres-confirm`);
L(`collected=${collected}`);

// --- show + receipt #print ---
await gotoAdmin(page, `/admin/pos-orders/show/${ORDER_ID}`);
await page.waitForTimeout(3000);
const showSummary = await page.evaluate(() => {
  const txt = document.body.innerText;
  const lines = txt.split('\n').map((s) => s.trim()).filter(Boolean);
  return {
    header: lines.filter((l) => /Commande|Encaisser|Payé|Accepter|paiement|commande:/i.test(l)).slice(0, 10),
    montants: lines.filter((l) => /€/.test(l)).slice(0, 12),
  };
});
L(`SHOW post-encaissement: ${JSON.stringify(showSummary)}`);
await quartet(page, consoleBuf, netBuf, `${TAG}-03-show-apres`);

const receipt = await page.evaluate(() => {
  const el = document.getElementById('print');
  return el ? el.innerText.split('\n').map((s) => s.trim()).filter(Boolean) : null;
});
L(`RECEIPT #print (${receipt?.length ?? 0} lignes):\n  ${receipt ? receipt.join('\n  ') : 'ABSENT'}`);
fs.writeFileSync(`${OUT}_${TAG}-receipt.txt`, (receipt || ['ABSENT']).join('\n'));
// screenshot du seul bloc receipt
const printEl = page.locator('#print');
if (await printEl.count()) {
  await printEl.first().screenshot({ path: `${OUT}${TAG}-04-receipt-print.png` }).catch(async () => {
    // hidden element → force visible clone screenshot fallback
    await page.evaluate(() => {
      const el = document.getElementById('print');
      if (!el) return;
      const c = el.cloneNode(true);
      c.id = 'print-clone';
      c.style.cssText = 'position:fixed;top:0;left:0;z-index:99999;background:#fff;display:block;visibility:visible;width:380px;';
      document.body.appendChild(c);
    });
    await page.locator('#print-clone').screenshot({ path: `${OUT}${TAG}-04-receipt-print.png` }).catch(() => {});
  });
  L('receipt screenshot tenté');
}
L.flush();
await browser.close();
