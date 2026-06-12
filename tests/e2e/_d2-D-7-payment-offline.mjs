// DISPUTE R2 vague D — complément : confirm OFFLINE depuis /kiosk/payment (flux réel D-001) + état final après RÉESSAYER
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState, gotoPayment } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(KO)');

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
let url = await gotoPayment(p);
if (!url.includes('/payment')) {
  L('retry gotoPayment (1er essai resté sur cart)');
  await p.waitForTimeout(2500);
  url = await gotoPayment(p);
}
L(`payment atteint: ${url.replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d2D7-01-payment-online');

await ctx.setOffline(true);
L('OFFLINE=true — clic Confirmer ma commande');
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(5000);
const t = await bodyText();
L(`après confirm offline → ${p.url().replace(BASE, '')}`);
L(`écran erreur rendu: ${/Connexion perdue/.test(t)} · texte: "${t.slice(0, 600).replace(/\n+/g, ' | ')}"`);
await rec.snap('d2D7-02-payment-confirm-offline');

// retour online + RÉESSAYER → état FINAL post-reload (round précédent: capture mi-reload blanche)
await ctx.setOffline(false);
await p.waitForTimeout(2500);
const retry = p.locator('button:has-text("Réessayer"), [data-testid="kiosk-error-network-cta-retry"]').first();
if (await retry.isVisible().catch(() => false)) {
  await retry.click().catch(() => {});
  await p.waitForTimeout(9000); // reload complet + boot SPA
  L(`état FINAL après Réessayer (+9 s): ${p.url().replace(BASE, '')}`);
  L(`cart après reload: ${JSON.stringify(await cartState(p))}`);
  L(`TEXTE: "${(await bodyText()).slice(0, 500).replace(/\n+/g, ' | ')}"`);
  await rec.snap('d2D7-03-retry-final-state');
} else {
  L(`pas de CTA Réessayer — url=${p.url().replace(BASE, '')}`);
  await rec.snap('d2D7-03-no-retry');
}

fs.writeFileSync(`${OUT}/_d2D-7-payment-offline-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D7');
