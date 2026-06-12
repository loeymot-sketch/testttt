// DISPUTE R2 vague D — idle 3 min panier abandonné → overlay « Toujours là ? » → reset propre
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState, cartCount } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
L(`T0 panier abandonné: ${JSON.stringify(await cartState(p))} url=${p.url().replace(BASE, '')}`);
await rec.snap('d2D8-01-cart-T0');

// idleMs=180000, confirmMs=30000 → overlay attendu vers T+150 s
await p.waitForTimeout(155000);
const overlayAt155 = await p.locator('.kiosk-inactivity-overlay, [class*="inactivity"]').first().isVisible().catch(() => false);
const txt155 = await p.evaluate(() => document.body.innerText).catch(() => '');
L(`T+155s: overlay visible=${overlayAt155} url=${p.url().replace(BASE, '')} txt~="${txt155.slice(0, 250).replace(/\n+/g, ' | ')}"`);
await rec.snap('d2D8-02-T155-overlay');

// laisser expirer (confirmMs 30 s)
await p.waitForTimeout(40000);
L(`T+195s: url=${p.url().replace(BASE, '')} cart=${await cartCount(p)}`);
await rec.snap('d2D8-03-T195-after-timeout');

await p.waitForTimeout(15000);
const final = await cartState(p);
L(`T+210s FINAL: url=${p.url().replace(BASE, '')} cart=${JSON.stringify(final)}`);
await rec.snap('d2D8-04-final-reset');

// la borne est-elle réutilisable proprement ?
await enterFromIdle(p);
L(`réutilisable: url=${p.url().replace(BASE, '')} cart=${await cartCount(p)}`);
await rec.snap('d2D8-05-reusable');

fs.writeFileSync(`${OUT}/_d2D-8-idle-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D8');
