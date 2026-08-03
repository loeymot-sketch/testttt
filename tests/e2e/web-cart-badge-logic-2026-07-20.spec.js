// ============================================================================
// TEST LOGIQUE — le panier se vide-t-il correctement entre commandes ? (badge Panier = cart.length)
// Instrumente le compteur RÉEL à chaque étape sur 4 commandes enchaînées (mobile), pour trouver la
// RACINE d'une éventuelle accumulation (badge dérivant sous multi-commandes). systematic-debugging :
// récolter des preuves à chaque frontière, PAS deviner.
// Étapes loggées par commande : [menu avant add] → [après add] → [confirmation] → [après Retour accueil].
// Attendu si le panier se vide bien : après-add=1, confirmation=0, après-retour=0, menu-suivant=0.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/web-cart-badge-logic-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-cart-badge-logic-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);
const LOCAL_BASE = 'http://127.0.0.1:8000';
const LOCAL_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const PHONE = '0699000555';

test.use({ ...devices['Pixel 7'] });
test.describe.configure({ retries: 0 });
test.setTimeout(300_000);

// compteur RÉEL du badge (0 si le badge n'est pas rendu, car cartCount>0 conditionne son affichage)
async function badge(page) {
  const el = page.locator('.lc-nav-btn-cart-dot');
  if (await el.count() === 0) return 0;
  const t = (await el.first().innerText().catch(() => '0')).trim();
  const n = parseInt(t, 10);
  return Number.isFinite(n) ? n : 0;
}

async function gotoDevLocal(page) {
  await page.addInitScript((d) => {
    const iv = setInterval(() => { if (window.LC && window.LC.api && window.LC.api.config) { window.LC.api.config.base = d.base; window.LC.api.config.apiKey = d.apiKey; clearInterval(iv); } }, 5);
  }, { base: LOCAL_BASE, apiKey: LOCAL_KEY });
  await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
}
async function openMenuClean(page) {
  await page.keyboard.press('Escape').catch(() => {});
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible({ timeout: 3_000 }).catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click({ timeout: 8_000 }); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click({ timeout: 8_000 }); }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}

test('LOGIQUE panier — le badge reflète le panier réel et se vide entre commandes', async ({ page }) => {
  let devCode = null;
  page.on('response', async (r) => { if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) devCode = String(j.dev_code); } });
  await gotoDevLocal(page);

  const trace = [];
  for (let k = 0; k < 4; k++) {
    await openMenuClean(page);
    const bMenu = await badge(page);
    const coca = page.getByRole('button', { name: 'Voir Coca-Cola 33cl', exact: true });
    await coca.scrollIntoViewIfNeeded(); await coca.click({ timeout: 8_000 });
    await page.locator('.lc-detail').getByRole('button', { name: /Ajouter au panier/ }).click({ timeout: 8_000 });
    await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 8_000 });
    const bAfterAdd = await badge(page);

    // panier → checkout → paiement → confirmer
    await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click({ timeout: 8_000 });
    const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
    for (let i = 0; i < 10; i++) { if (await cta.isVisible({ timeout: 1_200 }).catch(() => false)) break; const skip = page.getByRole('button', { name: 'Non merci' }); if (await skip.isVisible({ timeout: 600 }).catch(() => false)) await skip.click().catch(() => {}); await page.waitForTimeout(250); }
    await cta.click({ timeout: 8_000 });
    await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 10_000 });
    await page.locator('.lcf-cta-bar-next').click({ timeout: 8_000 });
    if (await page.locator('#auth-phone').isVisible({ timeout: 3_000 }).catch(() => false)) {
      await page.locator('#auth-phone').fill(PHONE);
      await page.getByRole('button', { name: /Recevoir le code/ }).click({ timeout: 8_000 });
      await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 10_000 });
      await expect.poll(() => devCode, { timeout: 10_000 }).toBeTruthy();
      await page.locator('#auth-otp').fill(devCode);
      await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click({ timeout: 8_000 });
    }
    await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 25_000 });
    await page.waitForTimeout(400);
    const bConfirm = await badge(page);        // ATTENDU 0 (onNext a fait setCart([]))
    await page.getByRole('button', { name: /Retour à l'accueil/ }).click({ timeout: 8_000 });
    await page.waitForTimeout(400);
    const bAfterHome = await badge(page);      // ATTENDU 0

    trace.push({ order: k + 1, badgeMenuAvant: bMenu, badgeApresAjout: bAfterAdd, badgeConfirmation: bConfirm, badgeApresRetour: bAfterHome });
    console.log(`[cmd ${k + 1}] menu-avant=${bMenu} après-ajout=${bAfterAdd} CONFIRMATION=${bConfirm} après-retour=${bAfterHome}`);
  }

  fs.writeFileSync(path.join(SHOT_DIR, 'trace.json'), JSON.stringify(trace, null, 2));
  // ASSERTIONS LOGIQUES : le panier commandé doit être 1 à l'ajout, 0 à la confirmation, 0 au retour,
  // et NE DOIT PAS s'accumuler d'une commande à l'autre (badge-menu-avant reste 0).
  for (const t of trace) {
    expect(t.badgeApresAjout, `cmd ${t.order} : 1 article après ajout`).toBe(1);
    expect(t.badgeConfirmation, `cmd ${t.order} : panier VIDÉ à la confirmation (onNext setCart[])`).toBe(0);
    expect(t.badgeApresRetour, `cmd ${t.order} : panier vide après Retour accueil`).toBe(0);
    expect(t.badgeMenuAvant, `cmd ${t.order} : pas d'accumulation entre commandes`).toBe(0);
  }
});
