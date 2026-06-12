// DISPUTE R1 vague D — scénario 1 : offline/online à chaque étape + N1 prefetch
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, openWizard, cartCount, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(innerText KO)');

await kioskBoot(p);

// ---------- 1a. IDLE : vérifier link rel=prefetch kiosk-errors dans le DOM ----------
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(3000);
const prefetchLinks = await p.evaluate(() =>
  [...document.querySelectorAll('link[rel="prefetch"], link[rel="preload"]')].map(l => `${l.rel} ${l.href}`)
);
L(`PREFETCH-LINKS idle (${prefetchLinks.length}): ${JSON.stringify(prefetchLinks)}`);
const errChunk = prefetchLinks.filter(h => /kiosk-errors/.test(h));
L(`PREFETCH kiosk-errors présent: ${errChunk.length > 0} → ${JSON.stringify(errChunk)}`);
await rec.snap('d1-01-idle-prefetch');

// ---------- 1b. OFFLINE depuis idle → navigation SPA vers /kiosk/error/network ----------
await ctx.setOffline(true);
L('OFFLINE=true (depuis idle)');
const nav = await p.evaluate(() => {
  try {
    const app = document.querySelector('#app')?.__vue_app__;
    const r = app?.config?.globalProperties?.$router;
    if (!r) return 'NO-ROUTER';
    r.push({ name: 'kiosk.error.network' });
    return 'PUSHED';
  } catch (e) { return 'ERR ' + String(e); }
});
L(`router.push(kiosk.error.network) offline → ${nav}`);
await p.waitForTimeout(2500);
L(`URL après push offline: ${p.url().replace(BASE, '')}`);
const errTxt = (await bodyText()).slice(0, 600).replace(/\n+/g, ' | ');
L(`TEXTE écran erreur offline: "${errTxt}"`);
await rec.snap('d1-02-error-network-offline');
await ctx.setOffline(false);
L('OFFLINE=false — retour idle');
await p.waitForTimeout(1000);

// ---------- 1c. CATALOGUE offline ----------
await enterFromIdle(p);
await p.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
await ctx.setOffline(true);
L('OFFLINE=true (catalogue) — clic catégorie + tentative ajout produit');
// navigation catégorie (client-side)
await p.locator('[data-testid^="kiosk-cat-"]').nth(2).click().catch(() => {});
await p.waitForTimeout(1500);
const addBtn = p.locator('[data-testid^="kiosk-product-add-"]').first();
await addBtn.click().catch(() => {});
await p.waitForTimeout(2000);
L(`URL catalogue offline: ${p.url().replace(BASE, '')} cart=${await cartCount(p)}`);
await rec.snap('d1-03-catalogue-offline');
await ctx.setOffline(false);
L('OFFLINE=false (catalogue) — récupération');
await p.waitForTimeout(2500);
await rec.snap('d1-04-catalogue-recovery');
L(`cart après recovery: ${JSON.stringify(await cartState(p))}`);

// ---------- 1d. WIZARD ouvert offline ----------
if (!p.url().includes('/categories')) await p.goto(BASE + '/kiosk/categories?cat=1', { waitUntil: 'domcontentloaded' });
const wiz = await openWizard(p);
if (wiz) {
  await ctx.setOffline(true);
  L('OFFLINE=true (wizard ouvert) — clic option + next');
  await p.locator('.kiosk-option-card:not(.disabled), .kiosk-viande-card:not(.disabled)').first().click().catch(() => {});
  await p.waitForTimeout(600);
  await p.locator('.kiosk-btn-next').first().click().catch(() => {});
  await p.waitForTimeout(1500);
  L(`wizard offline: overlay=${await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)} url=${p.url().replace(BASE, '')}`);
  await rec.snap('d1-05-wizard-offline');
  await ctx.setOffline(false);
  await p.waitForTimeout(2000);
  L('OFFLINE=false (wizard) — état post-retour');
  await rec.snap('d1-06-wizard-recovery');
  // abandon wizard pour continuer le flux proprement
  await p.locator('.kiosk-btn-abandon').first().click().catch(() => {});
  await p.waitForTimeout(400);
  await p.locator('.kiosk-wizard-abandon-yes').click().catch(() => {});
  await p.waitForTimeout(600);
} else {
  L('WIZARD: aucun produit composé trouvé (ANOMALIE à vérifier)');
}

// ---------- 1e. PANIER offline ----------
const added = await addSimpleProduct(p);
L(`produit simple ajouté: ${added} cart=${JSON.stringify(await cartState(p))}`);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1500);
const cartBefore = await cartState(p);
L(`CART avant offline: ${JSON.stringify(cartBefore)}`);
await ctx.setOffline(true);
L('OFFLINE=true (panier) — tentative checkout');
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(3000);
L(`après checkout offline → ${p.url().replace(BASE, '')}`);
L(`TEXTE: "${(await bodyText()).slice(0, 500).replace(/\n+/g, ' | ')}"`);
await rec.snap('d1-07-cart-checkout-offline');
await ctx.setOffline(false);
await p.waitForTimeout(2500);
L(`OFFLINE=false (panier) — url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d1-08-cart-recovery');

// ---------- 1f. PAYMENT offline ----------
if (!p.url().includes('/cart')) { await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(1200); }
await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
await p.waitForTimeout(2000);
if (p.url().includes('/upsell')) {
  await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
  await p.waitForTimeout(1500);
}
if (p.url().includes('/loyalty')) {
  await p.locator('.kiosk-loyalty-skip').first().click().catch(() => {});
  await p.waitForTimeout(1500);
  if (p.url().includes('/upsell')) {
    await p.locator('[data-testid="kiosk-upsell-skip"], [data-testid="kiosk-upsell-add-continue"]').first().click().catch(() => {});
    await p.waitForTimeout(1500);
  }
}
L(`étape payment atteinte: ${p.url().replace(BASE, '')}`);
await rec.snap('d1-09-payment-online');
await ctx.setOffline(true);
L('OFFLINE=true (payment) — clic confirmer');
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(4000);
L(`après confirm offline → ${p.url().replace(BASE, '')}`);
L(`TEXTE: "${(await bodyText()).slice(0, 600).replace(/\n+/g, ' | ')}"`);
await rec.snap('d1-10-payment-confirm-offline');
// l'écran erreur réseau rend-il OFFLINE (chunk préfetché) ?
await ctx.setOffline(false);
await p.waitForTimeout(2500);
L(`OFFLINE=false (payment) — url=${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d1-11-payment-recovery');
// si bouton réessayer présent → clic
const retry = p.locator('button:has-text("Réessayer"), [data-testid*="retry"]').first();
if (await retry.isVisible().catch(() => false)) {
  await retry.click().catch(() => {});
  await p.waitForTimeout(3000);
  L(`après Réessayer → ${p.url().replace(BASE, '')}`);
  await rec.snap('d1-12-payment-retry-online');
}

fs.writeFileSync(`${OUT}/_d1-1-offline-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D1');
