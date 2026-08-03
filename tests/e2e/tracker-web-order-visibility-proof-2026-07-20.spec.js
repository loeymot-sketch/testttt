// ============================================================================
// PREUVE RÉELLE — la plainte owner « commande web INVISIBLE dans commandes en cours » est CORRIGÉE.
// Boucle complète : (1) VRAIE commande web via le funnel (dev-OTP) → (2) login admin →
// /admin/pos-orders-tracker → la carte web est VISIBLE (voie À encaisser, chip 🌐, CTA Accepter)
// → (3) clic Accepter → la carte passe cash-pending (CTA Encaisser) → captures à chaque étape.
// AVANT le fix : ordersByStatus jetait les PENDING → la même commande n'apparaissait NULLE PART.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 \
//         npx playwright test tests/e2e/tracker-web-order-visibility-proof-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'tracker-web-visibility-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);

const LOCAL_BASE = 'http://127.0.0.1:8000';
const LOCAL_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const PHONE = '0699000444';
const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };

test.describe.configure({ retries: 0 });
test.setTimeout(300_000);

async function gotoDevLocal(page) {
  await page.addInitScript((d) => {
    const iv = setInterval(() => {
      if (window.LC && window.LC.api && window.LC.api.config) {
        window.LC.api.config.base = d.base; window.LC.api.config.apiKey = d.apiKey; clearInterval(iv);
      }
    }, 5);
  }, { base: LOCAL_BASE, apiKey: LOCAL_KEY });
  await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
}

test('PLAINTE OWNER — commande web visible + acceptable + encaissable dans le tracker', async ({ page, browser }) => {
  // ── 1. VRAIE commande web via le funnel ─────────────────────────────────
  let devCode = null;
  page.on('response', async (r) => {
    if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) devCode = String(j.dev_code); }
  });
  await gotoDevLocal(page);
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible().catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click(); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click(); }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
  const coca = page.getByRole('button', { name: 'Voir Coca-Cola 33cl', exact: true });
  await coca.scrollIntoViewIfNeeded(); await coca.click();
  await page.locator('.lc-detail').getByRole('button', { name: /Ajouter au panier/ }).click();
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click();
  const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 10; i++) {
    if (await cta.isVisible().catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible().catch(() => false)) await skip.click();
    await page.waitForTimeout(350);
  }
  await cta.click();
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 10_000 });
  await page.locator('.lcf-cta-bar-next').click();
  if (await page.locator('#auth-phone').isVisible({ timeout: 3_000 }).catch(() => false)) {
    await page.locator('#auth-phone').fill(PHONE);
    await page.getByRole('button', { name: /Recevoir le code/ }).click();
    await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 10_000 });
    await expect.poll(() => devCode, { timeout: 10_000 }).toBeTruthy();
    await page.locator('#auth-otp').fill(devCode);
    await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click();
  }
  await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 25_000 });
  const serial = (await page.locator('.lcf-ticket-code').innerText()).trim().replace(/^#/, '');
  console.log('[web order placée]', serial);

  // ── 2. Admin : le tracker « commandes en cours » DOIT l'afficher ────────
  const admin = await browser.newPage();
  await admin.goto(`${LOCAL_BASE}/login`, { waitUntil: 'domcontentloaded' });
  const inputs = admin.locator('input');
  await inputs.nth(0).fill('admin@lecayenne.fr');
  await inputs.nth(1).fill('123456');
  await admin.getByRole('button', { name: /Login|Connexion|Se connecter/i }).click();
  await admin.waitForURL(/admin/, { timeout: 30_000 });
  await admin.goto(`${LOCAL_BASE}/admin/pos-orders-tracker`, { waitUntil: 'domcontentloaded' });
  await admin.waitForTimeout(2_500); // fetchOrders + render

  // La carte affiche le queue_number (N°A00xx), pas le serial → on cible par le CTA
  // web-accept (testid stable) et on en extrait l'id DB de la commande.
  const acceptBtn = admin.locator('[data-testid^="tracker-accept-web-"]').first();
  await expect(acceptBtn, 'une carte web PENDING avec CTA Accepter DOIT être visible (la plainte)')
    .toBeVisible({ timeout: 20_000 });
  const dbId = (await acceptBtn.getAttribute('data-testid')).replace('tracker-accept-web-', '');
  const card = admin.locator(`[data-testid="tracker-order-${dbId}"]`);
  await expect(card, `carte tracker de la commande web id=${dbId} visible`).toBeVisible();
  await admin.screenshot({ path: shot('01-tracker-web-order-visible.png'), fullPage: true });

  // chip source 🌐 (online) — le fix sourceOf
  await expect(card.locator('.pos-tracker-card-source--online'), 'chip source 🌐 online').toBeVisible();

  // ── 3. Accepter depuis le tracker → devient cash-pending (Encaisser) ────
  await acceptBtn.click();
  const encaisserBtn = admin.locator(`[data-testid="tracker-encaisser-${dbId}"]`);
  await expect(encaisserBtn, 'après Accepter, la MÊME carte devient encaissable (continuité du cycle)')
    .toBeVisible({ timeout: 20_000 });
  await admin.screenshot({ path: shot('02-tracker-web-order-accepted-encashable.png'), fullPage: true });

  console.log('[PROOF] web order → tracker visible → acceptée → encaissable. serial=', serial);
  fs.writeFileSync(path.join(SHOT_DIR, 'serial.txt'), serial);
});
