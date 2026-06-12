// DISPUTE R1 vague D — focus : écran après validation tel + prefill inscription (numéro réellement inconnu)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); fs.mkdirSync(OUT, { recursive: true }); fs.writeFileSync(`${OUT}/_d4c-log.txt`, log.join('\n')); };
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

// cas A : 0612345678 (soupçon: existe en seed)
for (const d of '0612345678') { await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click(); await p.waitForTimeout(50); }
await p.locator('.kiosk-btn-primary.full').first().click();
await p.waitForTimeout(2500);
L(`cas A (0612345678) → texte: "${(await p.evaluate(() => document.body.innerText)).slice(0, 350).replace(/\n+/g, ' | ')}"`);
await rec.snap('d4c-01-after-validate-0612345678');

// retour saisie si on a changé d'écran
const back = p.locator('button:has-text("Retour"), .kiosk-loyalty-skip');
if (await back.first().isVisible().catch(() => false)) { await back.first().click().catch(() => {}); await p.waitForTimeout(1000); }
if (!p.url().includes('/loyalty')) { await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(800); await p.locator('[data-testid="kiosk-cart-loyalty-btn"]').click(); await p.waitForTimeout(1200); }

// cas B : 0633221144 (inconnu) → Non trouvé → inscription → prefill ?
const inp2 = p.locator('.kiosk-loyalty-input').first();
await inp2.click().catch(() => {});
// vider via del
const del = p.locator('button[aria-label="Effacer le dernier chiffre"]');
for (let i = 0; i < 20; i++) { if (!(await inp2.inputValue().catch(() => ''))) break; await del.click().catch(() => {}); await p.waitForTimeout(40); }
for (const d of '0633221144') { await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click(); await p.waitForTimeout(50); }
L(`cas B saisie: "${await inp2.inputValue()}"`);
await p.locator('.kiosk-btn-primary.full').first().click();
await p.waitForTimeout(2500);
L(`cas B → message="${await p.locator('.kiosk-loyalty-error').innerText().catch(() => '(aucun)')}"`);
await rec.snap('d4c-02-unknown-number');
const reg = p.locator('.kiosk-loyalty-register-btn');
if (await reg.isVisible().catch(() => false)) {
  await reg.click();
  await p.waitForTimeout(1300);
  const phoneVal = await p.locator('[data-testid="kiosk-loyalty-register-phone"]').inputValue().catch(() => '(champ introuvable)');
  L(`inscription TÉLÉPHONE prérempli="${phoneVal}" (attendu utile: 0633221144)`);
  await rec.snap('d4c-03-register-prefill');
} else L('CTA inscription non visible (cas B)');

await browser.close();
console.log('DONE-D4C');
