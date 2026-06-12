// DISPUTE R2 vague D — vérif heal C-ADV-02 : promo appliquée SURVIT au reload (re-validation serveur)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const promoCalls = [];
p.on('response', r => {
  if (/promo/i.test(r.url()) && r.request().method() === 'POST') promoCalls.push(`${r.status()} ${r.url().slice(0, 140)}`);
});
const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(KO)');

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);

// appliquer le code réel seedé BORNEAUDIT5 (amount 5,00 €)
await p.locator('input[placeholder*="CODE PROMO" i], input[placeholder*="promo" i]').first().fill('BORNEAUDIT5').catch(() => {});
await p.waitForTimeout(400);
await p.locator('button:has-text("Appliquer")').first().click().catch(() => {});
await p.waitForTimeout(2500);
const txt1 = await bodyText();
L(`après Appliquer: promoCalls=${JSON.stringify(promoCalls)}`);
L(`cart store: ${JSON.stringify(await cartState(p))}`);
L(`ls promo: ${await p.evaluate(() => localStorage.getItem('foodking:kiosk-promo-code'))}`);
L(`TEXTE: "${txt1.slice(0, 600).replace(/\n+/g, ' | ')}"`);
await rec.snap('d2D5-01-promo-applied');

// F5 — la promo doit survivre (re-validation serveur au mount)
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(3500);
const txt2 = await bodyText();
L(`après F5: promoCalls=${JSON.stringify(promoCalls)}`);
L(`cart store après F5: ${JSON.stringify(await cartState(p))}`);
L(`ls promo après F5: ${await p.evaluate(() => localStorage.getItem('foodking:kiosk-promo-code'))}`);
L(`TEXTE après F5: "${txt2.slice(0, 600).replace(/\n+/g, ' | ')}"`);
const promoVisible = /BORNEAUDIT5|promo/i.test(txt2) && /-\s*5,00|−\s*5,00|5,00\s*€/.test(txt2);
L(`C-ADV-02 promo visible après F5: ${promoVisible}`);
await rec.snap('d2D5-02-promo-after-f5');

fs.writeFileSync(`${OUT}/_d2D-5-promo-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D5');
