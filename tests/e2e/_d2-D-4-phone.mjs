// DISPUTE R2 vague D — vérif heal D-007 : téléphone numpad pré-rempli dans l'inscription fidélité
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(KO)');

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p); // fidélité accessible via panier
await p.goto(BASE + '/kiosk/loyalty', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1800);
L(`écran fidélité: ${p.url().replace(BASE, '')}`);

// numéro INCONNU (pas un seed) — espacer les frappes pour le rate-limit
const phone = '0788123456';
for (const d of phone) {
  await p.locator(`[data-testid="kiosk-numpad-${d}"], button:has-text("${d}")`).first().click().catch(() => {});
  await p.waitForTimeout(180);
}
await p.waitForTimeout(600);
const typed = await p.evaluate(() => document.querySelector('input')?.value || document.querySelector('.kiosk-loyalty-code-display, [class*="loyalty-input"]')?.innerText || '(?)');
L(`saisie numpad: "${typed}"`);
await rec.snap('d2D4-01-loyalty-typed');

// valider → « Non trouvé » → S'inscrire
await p.locator('button:has-text("Valider"), [data-testid="kiosk-loyalty-validate"]').first().click().catch(() => {});
await p.waitForTimeout(2500);
L(`après validation: "${(await bodyText()).slice(0, 400).replace(/\n+/g, ' | ')}"`);
await rec.snap('d2D4-02-not-found');
await p.locator('.kiosk-loyalty-register-btn, button:has-text("inscrire")').first().click().catch(() => {});
await p.waitForTimeout(1500);
const prefill = await p.evaluate(() => {
  const inputs = [...document.querySelectorAll('input')].map(i => ({ ph: i.placeholder, val: i.value }));
  return inputs;
});
L(`D-007 inscription inputs: ${JSON.stringify(prefill)}`);
const phoneField = prefill.find(i => /t[ée]l[ée]phone/i.test(i.ph || ''));
L(`D-007 TÉLÉPHONE pré-rempli = "${phoneField?.val}" (attendu "${phone}")`);
await rec.snap('d2D4-03-register-phone-prefilled');

fs.writeFileSync(`${OUT}/_d2D-4-phone-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D4');
