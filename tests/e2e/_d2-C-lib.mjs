// DISPUTE round-2 — Vague C (borne edge, post-heal). Lib jetable, READ-ONLY code source.
// Quartet par état: PNG + DOM CORRIGÉ (#app outerHTML, slice(-120KB)) + console + network(>=400).
// Réutilise les helpers de flux round-1 (_d1-C-lib.mjs) — seuls snap/logLine/OUT changent.
import fs from 'fs';
import path from 'path';
export { boot, kioskBoot, startFlow, addProduct, cartState, completeWizard, readCartTotals, BASE } from './_d1-C-lib.mjs';

export const OUT2 = new URL('../../reports/test-e2e/dispute-2026-06-12/round-2/C-borne-edge/', import.meta.url).pathname;
fs.mkdirSync(OUT2, { recursive: true });

export function log2(file, msg) {
  const line = `[${new Date().toISOString().slice(11, 19)}] ${msg}`;
  console.log(line);
  fs.appendFileSync(path.join(OUT2, file), line + '\n');
}

let lastSnapTs = 0;
// DOM CORRIGÉ round-2: #app outerHTML (fallback body), tronqué par la FIN à 120KB.
export async function snap2(page, sink, name) {
  const png = path.join(OUT2, name + '.png');
  await page.screenshot({ path: png }).catch((e) => console.log('SHOT FAIL', name, e.message));
  let dom = '';
  try {
    dom = await page.evaluate(() => document.querySelector('#app')?.outerHTML || document.body.outerHTML);
  } catch (e) { dom = 'DOM CAPTURE FAIL: ' + e.message; }
  if (dom.length > 120 * 1024) dom = dom.slice(-120 * 1024);
  fs.writeFileSync(path.join(OUT2, name + '.dom.html'), dom);
  const since = lastSnapTs;
  fs.writeFileSync(path.join(OUT2, name + '.console.json'), JSON.stringify(sink.console.filter(c => c.ts > since), null, 1));
  fs.writeFileSync(path.join(OUT2, name + '.network.json'), JSON.stringify(sink.network.filter(c => c.ts > since), null, 1));
  lastSnapTs = Date.now();
  console.log('SNAP2', name);
  return png;
}

// Skip les écrans intermédiaires (loyalty identify / upsell) jusqu'au payment.
export async function skipToPayment(page, BASEURL) {
  for (let hop = 0; hop < 4 && !page.url().includes('/payment'); hop++) {
    if (page.url().includes('/loyalty')) {
      const skip = page.locator('[data-testid="kiosk-loyalty-skip"], .kiosk-loyalty-skip').first();
      if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(1400); }
      else break;
    } else if (page.url().includes('/upsell')) {
      const skip = page.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]');
      await skip.first().click().catch(() => {});
      await page.waitForTimeout(1700);
    } else { await page.waitForTimeout(900); }
  }
  return page.url().includes('/payment');
}
