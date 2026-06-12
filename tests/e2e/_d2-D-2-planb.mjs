// DISPUTE R2 vague D — vérif heals D-003 (panier vidé à cash-instruction) + ADV-F-P1-1 (CTA Retour à l'accueil) + re-validation impossible
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState, cartCount, gotoPayment } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const orders = [];
p.on('response', async (r) => {
  if (r.url().includes('frontend/order') && r.request().method() === 'POST' && !r.url().includes('quote') && !r.url().includes('change-status')) {
    try { const j = await r.json(); const d = j?.data || j; orders.push({ status: r.status(), id: d?.id, queue: d?.queue_number, total: d?.total ?? d?.order_amount }); L(`ORDER-POST ${r.status()} id=${d?.id} queue=${d?.queue_number} total=${d?.total ?? d?.order_amount}`); }
    catch { orders.push({ status: r.status() }); L(`ORDER-POST ${r.status()} (non-json)`); }
  }
});
const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(innerText KO)');

await kioskBoot(p);
await enterFromIdle(p);
const added = await addSimpleProduct(p);
L(`produit ajouté: ${added} cart=${JSON.stringify(await cartState(p))}`);

// commande 1 Plan B complète
await gotoPayment(p);
await rec.snap('d2D2-01-payment-before-confirm');
// échappatoire « Retour au panier » sur l'écran Plan B (fix 43c5f2d76) ?
const backToCart = await p.locator('[data-testid="kiosk-payment-counter-back"]').isVisible().catch(() => false);
L(`bouton Retour au panier sur écran Plan B: ${backToCart}`);
await p.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first().click().catch(() => {});
await p.waitForTimeout(3800);
L(`commande 1 → ${p.url().replace(BASE, '')}`);
const stateAtCash = await cartState(p);
L(`cart à cash-instruction (D-003 attendu VIDE + idemKey null): ${JSON.stringify(stateAtCash)}`);
const cashTxt = (await bodyText()).slice(0, 700).replace(/\n+/g, ' | ');
L(`TEXTE cash-instruction: "${cashTxt}"`);
await rec.snap('d2D2-02-cash-instruction-cart-cleared');

// CTA « Retour à l'accueil » (ADV-F-P1-1)
const ctaHome = p.locator('button:has-text("Retour à l\'accueil"), [data-testid*="back-home"], .kiosk-cash-cta');
const ctaCount = await ctaHome.count();
const ctaTxt = ctaCount ? await ctaHome.first().innerText().catch(() => '?') : '(absent)';
L(`CTA retour accueil: count=${ctaCount} text="${ctaTxt}"`);

// re-navigation SPA vers le panier (probe round-1 D-A5) — attendu : panier VIDE
const nav = await p.evaluate(() => {
  try { document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/kiosk/cart'); return 'PUSHED'; } catch (e) { return String(e); }
});
L(`router.push(/kiosk/cart) depuis cash-instruction → ${nav}`);
await p.waitForTimeout(2200);
const lines = await p.locator('[data-testid^="kiosk-cart-item-name-"]').count();
L(`url=${p.url().replace(BASE, '')} cart=${await cartCount(p)} lignes rendues=${lines}`);
await rec.snap('d2D2-03-cart-after-order-empty');

// re-validation impossible ? le bouton checkout doit être absent/désactivé sur panier vide
const checkoutVisible = await p.locator('[data-testid="kiosk-cart-checkout"]').isVisible().catch(() => false);
const checkoutDisabled = checkoutVisible ? await p.locator('[data-testid="kiosk-cart-checkout"]').isDisabled().catch(() => false) : null;
L(`bouton checkout: visible=${checkoutVisible} disabled=${checkoutDisabled}`);
if (checkoutVisible && !checkoutDisabled) {
  await p.locator('[data-testid="kiosk-cart-checkout"]').click().catch(() => {});
  await p.waitForTimeout(2500);
  L(`après clic checkout panier vide → ${p.url().replace(BASE, '')}`);
  L(`TEXTE: "${(await bodyText()).slice(0, 400).replace(/\n+/g, ' | ')}"`);
  await rec.snap('d2D2-04-revalidation-attempt');
}

// retour cash-instruction impossible (back) puis clic CTA accueil — flux nominal
await p.goBack().catch(() => {});
await p.waitForTimeout(1500);
L(`après back → ${p.url().replace(BASE, '')}`);
const ctaHome2 = p.locator('button:has-text("Retour à l\'accueil")').first();
if (await ctaHome2.isVisible().catch(() => false)) {
  await ctaHome2.click().catch(() => {});
  await p.waitForTimeout(2000);
  L(`après clic Retour à l'accueil → ${p.url().replace(BASE, '')}`);
  await rec.snap('d2D2-05-after-cta-home');
} else {
  L(`CTA Retour à l'accueil non visible sur ${p.url().replace(BASE, '')}`);
  await rec.snap('d2D2-05-no-cta-state');
}

L(`ORDERS totaux: ${JSON.stringify(orders)} (attendu: UN SEUL 201, aucun 409)`);
fs.writeFileSync(`${OUT}/_d2D-2-planb-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D2');
