// ADVERSARIAL R2 — scroll du VRAI conteneur interne (layout 100vh overflow:auto) → bas de page réel
import { BASE, OUT, boot, mkLogger, login } from './_d2-B-lib.mjs';

const L = mkLogger('red2-b3');
const { browser, page } = await boot();
const scrollInner = async () => page.evaluate(() => {
  const els = Array.from(document.querySelectorAll('*')).filter((e) => e.scrollHeight > e.clientHeight + 50 && e.clientHeight > 300);
  const tgt = els.sort((a, b) => b.scrollHeight - a.scrollHeight)[0];
  if (tgt) { tgt.scrollTop = tgt.scrollHeight; return { tag: tgt.tagName, cls: String(tgt.className).slice(0, 60), sh: tgt.scrollHeight, ch: tgt.clientHeight }; }
  return null;
});
try {
  await login(page, L);
  await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  L('scroller encaissement: ' + JSON.stringify(await scrollInner()));
  await page.waitForTimeout(1000);
  await page.screenshot({ path: OUT + 'red2-b-12-encaissement-bas-INNER.png' });
  await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  L('scroller overview: ' + JSON.stringify(await scrollInner()));
  await page.waitForTimeout(1000);
  await page.screenshot({ path: OUT + 'red2-b-13-cash-overview-bas-INNER.png' });
  L('DONE');
} catch (e) { L('FATAL ' + e.message); } finally { L.flush(); await browser.close(); }
