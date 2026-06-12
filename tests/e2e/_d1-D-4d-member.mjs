// DISPUTE R1 vague D — recapture écran membre fidélité (artefact écrasé par run rate-limit)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct } from './_d1-D-helper.mjs';
const log = [];
const L = (m) => { log.push(m); console.log(m); fs.writeFileSync(`${OUT}/_d4d-member-log.txt`, log.join('\n')); };
const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1200);
await p.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await p.waitForTimeout(1500);
for (const d of '0612345678') { await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click(); await p.waitForTimeout(60); }
await p.locator('.kiosk-btn-primary.full').first().click();
await p.waitForTimeout(3000);
L(`écran après check: "${(await p.evaluate(() => document.body.innerText)).slice(0, 400).replace(/\n+/g, ' | ')}"`);
await rec.snap('d4d-01-member-screen');
await browser.close();
console.log('DONE-D4D');
