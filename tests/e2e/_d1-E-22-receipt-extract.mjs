// D1 VAGUE E — étape 2c : extraction receipt #print (textContent + capture forcée visible)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d1-E-lib.mjs';

const ORDER_ID = Number(process.env.E_ORDER_ID || 4531);
const TAG = process.env.E_TAG || 'E22';
const L = makeLogger(`${TAG}-receipt`);
const { browser, page, consoleBuf, netBuf } = await boot();

await login(page);
await gotoAdmin(page, `/admin/pos-orders/show/${ORDER_ID}`);
await page.waitForTimeout(3500);

const receipt = await page.evaluate(() => {
  const el = document.getElementById('print');
  if (!el) return null;
  // textContent (innerText vide car modal display:none)
  return el.textContent.split('\n').map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
});
L(`RECEIPT textContent (${receipt?.length ?? 0} lignes):\n  ${receipt ? receipt.join('\n  ') : 'ABSENT'}`);
fs.writeFileSync(`${OUT}_${TAG}-receipt.txt`, (receipt || ['ABSENT']).join('\n'));

// forcer le modal visible pour la capture visuelle
await page.evaluate(() => {
  const modal = document.getElementById('receiptModal');
  if (modal) {
    modal.style.cssText = 'display:block;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.4);overflow:auto;';
    const dlg = document.getElementById('print');
    if (dlg) dlg.style.cssText += ';background:#fff;margin:20px auto;display:block;visibility:visible;';
  }
});
await page.waitForTimeout(800);
const printEl = page.locator('#print');
await printEl.screenshot({ path: `${OUT}${TAG}-01-receipt-visible.png` }).catch(async (e) => {
  L(`screenshot #print fail: ${e.message?.slice(0, 100)} → fullpage`);
  await page.screenshot({ path: `${OUT}${TAG}-01-receipt-visible.png` });
});
await quartet(page, consoleBuf, netBuf, `${TAG}-02-receipt-modal-force`);
L.flush();
await browser.close();
