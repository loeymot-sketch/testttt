// DISPUTE R1 vague D — focus : touche del numpad fidélité (aria-label FR) + prefill téléphone inscription
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct } from './_d1-D-helper.mjs';

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
await p.waitForTimeout(1200);
await p.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await p.waitForTimeout(1500);

const inp = p.locator('.kiosk-loyalty-input').first();
for (const d of '0612345678') { await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click(); await p.waitForTimeout(60); }
L(`saisie: "${await inp.inputValue()}"`);

const del = p.locator('button[aria-label="Effacer le dernier chiffre"]');
L(`del présent: ${await del.count()}`);
await del.click(); await p.waitForTimeout(120);
await del.click(); await p.waitForTimeout(120);
await del.click(); await p.waitForTimeout(120);
L(`après 3 del: "${await inp.inputValue()}" (attendu 0612345)`);
await rec.snap('d4b-01-del-works');
for (const d of '678') { await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click(); await p.waitForTimeout(60); }
L(`re-complété: "${await inp.inputValue()}" (attendu 0612345678)`);

// valider → Non trouvé → inscription : le téléphone est-il prérempli ?
await p.locator('.kiosk-btn-primary.full').first().click();
await p.waitForTimeout(2500);
L(`message: "${await p.locator('.kiosk-loyalty-error').innerText().catch(() => '?')}"`);
await p.locator('.kiosk-loyalty-register-btn').click();
await p.waitForTimeout(1500);
const phoneVal = await p.locator('[data-testid="kiosk-loyalty-register-phone"]').inputValue().catch(() => '(champ introuvable)');
L(`inscription TÉLÉPHONE prérempli="${phoneVal}" (saisi à l'étape précédente: 0612345678)`);
await rec.snap('d4b-02-register-phone-prefill');

fs.writeFileSync(`${OUT}/_d4b-del-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D4B');
