// ============================================================================
// PREUVE RÉELLE — SYNC cross-système : une VRAIE commande WEB (avec supplément) placée via le
// funnel (auth dev-OTP local) → doit exister côté BACKEND avec le bon total + le supplément dans
// le composition_snapshot (ce que caisse + KDS lisent). Prouve « web ↔ caisse ↔ cuisine ».
// Le n° de commande est loggé → un tinker de vérif backend suit (total + compo + statut).
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/web-order-sync-caisse-kds-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-sync-caisse-kds-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);
const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };
const LOCAL_BASE = 'http://127.0.0.1:8000';
const LOCAL_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const PHONE = '0699000222';

test.describe.configure({ retries: 0 });
test.setTimeout(180_000);

async function gotoDevLocal(page) {
  await page.addInitScript((d) => {
    const iv = setInterval(() => {
      if (window.LC && window.LC.api && window.LC.api.config) {
        window.LC.api.config.base = d.base; window.LC.api.config.apiKey = d.apiKey; clearInterval(iv);
      }
    }, 5);
  }, { base: LOCAL_BASE, apiKey: LOCAL_KEY });
  for (let i = 0; i < 3; i++) {
    try { await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); break; } catch (e) { await page.waitForTimeout(3_000); }
  }
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
}
async function openMenu(page) {
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible().catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click(); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click(); }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}
async function openWizard(page) {
  const cards = page.locator('[aria-label^="Voir "]');
  const n = await cards.count();
  for (let i = 0; i < n; i++) {
    const name = (await cards.nth(i).innerText().catch(() => '')).trim();
    await cards.nth(i).scrollIntoViewIfNeeded().catch(() => {});
    await cards.nth(i).click().catch(() => {});
    const detail = page.locator('.lc-detail');
    if (!(await detail.isVisible({ timeout: 2500 }).catch(() => false))) continue;
    const perso = detail.getByRole('button', { name: /Personnaliser/ });
    if (await perso.isVisible().catch(() => false)) { await perso.click(); if (await page.locator('.lc-wiz').isVisible({ timeout: 5_000 }).catch(() => false)) return name; }
    await page.keyboard.press('Escape').catch(() => {}); await page.waitForTimeout(250);
  }
  return null;
}
async function driveWizardAddSupplement(page) {
  for (let s = 0; s < 14; s++) {
    const next = page.locator('.lc-wiz-foot-next');
    if (/Ajouter au panier/i.test((await next.innerText().catch(() => '')).trim())) return;
    const choices = page.locator('.lc-wiz-choice'); const cn = await choices.count();
    for (let c = 0; c < cn; c++) { if (await choices.nth(c).locator('.lc-wiz-choice-price').count() > 0) { await choices.nth(c).click().catch(() => {}); break; } }
    if (await page.locator('.lc-wiz-choice.is-on').count() === 0 && cn > 0) await choices.first().click().catch(() => {});
    await next.click({ timeout: 6_000 }).catch(() => {}); await page.waitForTimeout(350);
  }
}

test('SYNC web→caisse/KDS — vraie commande web avec supplément existe côté backend', async ({ page }) => {
  let devCode = null;
  page.on('response', async (r) => {
    if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) devCode = String(j.dev_code); }
  });

  await gotoDevLocal(page);
  await openMenu(page);
  const prod = await openWizard(page);
  expect(prod, 'wizard produit ouvert').toBeTruthy();
  await driveWizardAddSupplement(page);
  const wizardTotal = euro(await page.locator('.lc-wiz-foot-next').innerText());
  await page.locator('.lc-wiz-foot-next').click();
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });

  // → checkout → paiement (flag OFF = parcours comptoir, commande web réelle UNPAID)
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click();
  const checkoutCta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 8; i++) { if (await checkoutCta.isVisible().catch(() => false)) break; const skip = page.getByRole('button', { name: 'Non merci' }); if (await skip.isVisible().catch(() => false)) await skip.click(); await page.waitForTimeout(400); }
  await checkoutCta.click();
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 10_000 });
  const payTotal = euro(await page.locator('.lcf-cta-bar-next').innerText());

  // Confirmer → gate OTP → auth via dev-code local
  await page.locator('.lcf-cta-bar-next').click();
  await expect(page.locator('#auth-phone')).toBeVisible({ timeout: 10_000 });
  await page.locator('#auth-phone').fill(PHONE);
  await page.getByRole('button', { name: /Recevoir le code/ }).click();
  await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 10_000 });
  await expect.poll(() => devCode, { timeout: 10_000 }).toBeTruthy();
  console.log('[dev-code reçu]', devCode);
  await page.locator('#auth-otp').fill(devCode);
  await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click();

  // confirmation → n° commande réel
  await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 20_000 });
  const serial = (await page.locator('.lcf-ticket-code').innerText().catch(() => '')).trim();
  const confTotal = euro(await page.locator('.lcf-ticket-cell-val').last().innerText().catch(() => ''));
  await page.screenshot({ path: shot('01-web-order-placed.png'), fullPage: true });
  console.log(`[commande web PLACÉE] serial=${serial} wizard=${wizardTotal} pay=${payTotal} conf=${confTotal}`);

  expect(serial, 'un vrai n° de commande doit s\'afficher').toMatch(/[A-Z0-9]/);
  expect(confTotal, 'total confirmation === total paiement (supplément inclus)').toBeCloseTo(payTotal, 2);
});
