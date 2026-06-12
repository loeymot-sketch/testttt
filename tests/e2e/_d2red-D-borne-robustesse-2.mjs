// ADVERSARIAL R2 vague D — probe 2 : D-004 (indicateur offline catalogue, NON healé → vérif SURVIVANT)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, cartState } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };
const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);
await p.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
await ctx.setOffline(true);
await p.waitForTimeout(800);
// navigation SPA catégorie + ajout produit OFFLINE
await p.locator('[data-testid^="kiosk-category-"], .kiosk-cat-item').first().click().catch(() => {});
await p.waitForTimeout(1200);
const add = p.locator('[data-testid^="kiosk-product-add-"]').first();
if (await add.isVisible().catch(() => false)) { await add.click().catch(() => {}); await p.waitForTimeout(1200); }
const indicator = await p.evaluate(() => {
  const el = document.querySelector('.kiosk-offline-indicator, [data-testid*="offline"], [class*="offline"]');
  return el ? { cls: el.className, txt: (el.innerText || '').slice(0, 120), visible: !!(el.offsetWidth || el.offsetHeight) } : null;
});
const toastTxt = await p.evaluate(() => document.querySelector('.kiosk-toast-container')?.innerText || '');
L(`D-004 probe: OFFLINE browse+add → indicateur=${JSON.stringify(indicator)} toast="${toastTxt}" cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2red-D004-offline-catalogue-no-signal');
await ctx.setOffline(false);
fs.writeFileSync(`${OUT}/_d2red-D-probe2-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2RED-2');
