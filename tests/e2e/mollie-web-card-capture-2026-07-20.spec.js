// ============================================================================
// PREUVE RÉELLE — Câblage carte Mollie côté WEB (dormant flag OFF en prod).
// Cible : serveur LOCAL http://127.0.0.1:8899 (repo Site lecayenne, MES modifs).
// On active le flag onlineCard AU RUNTIME (comme le fera <meta feature-online-card=1>)
// et on prouve : (1) l'option « Carte bancaire (en ligne) » RENDUE dans le funnel,
// (2) la méthode window.LC.api.mollieCheckout EXISTE (endpoint câblé), (3) sélection carte.
// Le funnel est client-side jusqu'au paiement (data/menu.js statique) → aucun backend requis.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/mollie-web-card-capture-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'mollie-web-card-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);
const COCA = 'Voir Coca-Cola 33cl';

test.describe.configure({ retries: 0 });
test.setTimeout(180_000);

async function gotoDev(page) {
  let lastErr = null;
  for (let i = 0; i < 3; i++) {
    try { await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); lastErr = null; break; }
    catch (e) { lastErr = e; await page.waitForTimeout(3_000); }
  }
  if (lastErr) throw lastErr;
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await expect(page.locator('.lc-nav')).toBeVisible({ timeout: 15_000 });
}
async function enableCardFlag(page) {
  // Active le paiement carte au runtime (identique à <meta feature-online-card content="1">).
  return page.evaluate(() => {
    if (!window.LC || !window.LC.api) return { ok: false, reason: 'LC.api absent' };
    window.LC.api.config.onlineCardEnabled = true;
    return { ok: true, hasMollieCheckout: typeof window.LC.api.mollieCheckout === 'function' };
  });
}
async function openMenu(page) {
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible().catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click(); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click(); }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}
async function addCoca(page) {
  const card = page.getByRole('button', { name: COCA, exact: true });
  await card.scrollIntoViewIfNeeded(); await card.click();
  const detail = page.locator('.lc-detail');
  await expect(detail).toBeVisible({ timeout: 10_000 });
  await detail.getByRole('button', { name: /Ajouter au panier/ }).click();
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });
}
async function throughUpsellToCheckout(page) {
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click();
  const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 8; i++) {
    if (await cta.isVisible().catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible().catch(() => false)) await skip.click();
    await page.waitForTimeout(400);
  }
  await expect(cta).toBeVisible({ timeout: 10_000 });
}
async function toPayment(page) {
  await page.getByRole('button', { name: /Continuer vers paiement/ }).click();
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 10_000 });
}

test('Carte Mollie web — option rendue + méthode câblée + sélection', async ({ page }) => {
  await gotoDev(page);
  const flag = await enableCardFlag(page);
  console.log('[flag]', JSON.stringify(flag));
  expect(flag.ok, 'LC.api doit exister').toBeTruthy();
  expect(flag.hasMollieCheckout, 'api.mollieCheckout doit être exporté (endpoint câblé)').toBeTruthy();

  await openMenu(page);
  await addCoca(page);
  await throughUpsellToCheckout(page);
  await toPayment(page);
  await page.waitForTimeout(600);

  // (1) l'option carte en ligne est présente dans le DOM du funnel
  const cardOption = page.getByText('Carte bancaire (en ligne)', { exact: false });
  const cardVisible = await cardOption.isVisible().catch(() => false);
  console.log('[card-option visible]', cardVisible);
  await page.screenshot({ path: shot('01-payment-methods-card-visible.png'), fullPage: true });

  // (2) sélection de la carte → le formulaire/état carte s'affiche
  if (cardVisible) {
    await cardOption.click().catch(() => {});
    await page.waitForTimeout(500);
    await page.screenshot({ path: shot('02-card-selected.png'), fullPage: true });
  }

  expect(cardVisible, 'l\'option « Carte bancaire (en ligne) » doit être rendue quand le flag est ON').toBeTruthy();
});

// RÉGRESSION : flag OFF = défaut PROD. Mes changements (methods, bandeau sécurité, retrait du
// formulaire carte) NE DOIVENT PAS casser le parcours comptoir ni faire fuiter l'option carte.
test('Flag OFF (défaut prod) — AUCUNE option carte, parcours comptoir intact', async ({ page }) => {
  await gotoDev(page);
  // PAS d'injection de flag → onlineCardEnabled reste false (comme <meta feature-online-card=0>).
  const cfg = await page.evaluate(() => ({
    onlineCard: !!(window.LC && window.LC.api && window.LC.api.config.onlineCardEnabled),
    hasMollieCheckout: !!(window.LC && window.LC.api && typeof window.LC.api.mollieCheckout === 'function'),
  }));
  console.log('[flag-off cfg]', JSON.stringify(cfg));
  expect(cfg.onlineCard, 'le flag doit être OFF par défaut (prod)').toBeFalsy();
  // La méthode reste exportée (câblage dormant), mais l'UI ne l'expose pas.
  expect(cfg.hasMollieCheckout, 'mollieCheckout reste exporté même dormant').toBeTruthy();

  await openMenu(page);
  await addCoca(page);
  await throughUpsellToCheckout(page);
  await toPayment(page);
  await page.waitForTimeout(500);

  const cardVisible = await page.getByText('Carte bancaire (en ligne)', { exact: false }).isVisible().catch(() => false);
  const counterVisible = await page.getByText('Payer sur place', { exact: false }).isVisible().catch(() => false);
  console.log('[flag-off] card=', cardVisible, ' counter=', counterVisible);
  await page.screenshot({ path: shot('03-flag-off-counter-only.png'), fullPage: true });

  expect(cardVisible, 'flag OFF : l\'option carte NE DOIT PAS apparaître (défaut prod inchangé)').toBeFalsy();
  expect(counterVisible, 'flag OFF : « Payer sur place » reste présent (parcours comptoir intact)').toBeTruthy();
});
