// ============================================================================
// LOT 50 COMMANDES RÉELLES — interface MOBILE (Pixel 7 = chromium), funnel web complet, backend local.
// Mandat owner : « minimum 50 commandes réelles, pas en simulation, utilisation réelle client web,
// interface mobile ». Rotation FIABLE (Coca simple pour la vitesse + Cayenne wizard+supplément 1/3
// pour couvrir la personnalisation) — la variété par catégorie est déjà prouvée ailleurs (specs
// supplément 3 catégories + multi-option). Chaque commande : auth dev-OTP (1re seulement, token
// persiste), confirmation vérifiée (n° réel + total>0 + = total paiement), collectée au manifest
// pour la VÉRIF BACKEND adverse (total scellé au centime + composition) puis le nettoyage.
// Toutes les attentes sont BORNÉES → un pas bloqué ÉCHOUE proprement + resync (jamais de hang).
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 ORDERS=50 \
//         npx playwright test tests/e2e/web-50-real-orders-mobile-2026-07-20.spec.js --project=chromium
// ============================================================================
const { test, expect, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-50-orders-mobile-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (n) => path.join(SHOT_DIR, n);
const MANIFEST = path.join(SHOT_DIR, 'orders-manifest.json');

const N_ORDERS = Math.max(1, parseInt(process.env.ORDERS || '50', 10));
const LOCAL_BASE = 'http://127.0.0.1:8000';
const LOCAL_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const PHONE = '0699000333';
const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };

test.use({ ...devices['Pixel 7'] });            // MOBILE réel sur moteur chromium installé
test.describe.configure({ retries: 0 });
test.setTimeout(50 * 60_000);

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
async function assertNoHScroll(page, label) {
  const m = await page.evaluate(() => ({ iw: window.innerWidth, sw: document.documentElement.scrollWidth }));
  expect(m.sw, `${label} : débordement horizontal mobile (${m.sw} > ${m.iw})`).toBeLessThanOrEqual(m.iw + 1);
}
// État propre avant chaque commande : aucun overlay ouvert, on est bien sur le menu.
async function openMenuClean(page) {
  await page.keyboard.press('Escape').catch(() => {});
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible({ timeout: 3_000 }).catch(() => false)) await direct.click();
  else {
    await page.locator('.lc-nav-burger').click({ timeout: 8_000 });
    await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click({ timeout: 8_000 });
  }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}
async function addSimpleItem(page, ariaName) {
  const card = page.getByRole('button', { name: ariaName, exact: true });
  await card.scrollIntoViewIfNeeded({ timeout: 8_000 });
  await card.click({ timeout: 8_000 });
  const detail = page.locator('.lc-detail');
  await expect(detail).toBeVisible({ timeout: 8_000 });
  await detail.getByRole('button', { name: /Ajouter au panier/ }).click({ timeout: 8_000 });
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 8_000 });
  return ariaName.replace(/^Voir /, '');
}
async function addCayenneWizard(page) {
  const card = page.getByRole('button', { name: 'Voir Cayenne', exact: true });
  await card.scrollIntoViewIfNeeded({ timeout: 8_000 });
  await card.click({ timeout: 8_000 });
  const detail = page.locator('.lc-detail');
  await expect(detail).toBeVisible({ timeout: 8_000 });
  await detail.getByRole('button', { name: /Personnaliser/ }).click({ timeout: 8_000 });
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 8_000 });
  for (let s = 0; s < 14; s++) {
    const next = page.locator('.lc-wiz-foot-next');
    if (/Ajouter au panier/i.test((await next.innerText({ timeout: 5_000 }).catch(() => '')).trim())) break;
    const choices = page.locator('.lc-wiz-choice'); const cn = await choices.count();
    for (let c = 0; c < cn; c++) { if (await choices.nth(c).locator('.lc-wiz-choice-price').count() > 0) { await choices.nth(c).click({ timeout: 5_000 }).catch(() => {}); break; } }
    if (await page.locator('.lc-wiz-choice.is-on').count() === 0 && cn > 0) await choices.first().click({ timeout: 5_000 }).catch(() => {});
    await next.click({ timeout: 6_000 }).catch(() => {});
    await page.waitForTimeout(250);
  }
  await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }); // Ajouter au panier
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 8_000 });
  return 'Cayenne (wizard+supplément)';
}
async function throughUpsellToCheckout(page) {
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click({ timeout: 8_000 });
  const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 10; i++) {
    if (await cta.isVisible({ timeout: 1_500 }).catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible({ timeout: 800 }).catch(() => false)) await skip.click().catch(() => {});
    await page.waitForTimeout(300);
  }
  await cta.click({ timeout: 8_000 });
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 10_000 });
}

test('LOT — 50 commandes web réelles en mobile, chacune confirmée', async ({ page }) => {
  let devCode = null;
  page.on('response', async (r) => {
    if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) devCode = String(j.dev_code); }
  });

  await gotoDevLocal(page);
  await assertNoHScroll(page, 'home mobile');

  const placed = [];
  const failures = [];

  for (let k = 0; k < N_ORDERS; k++) {
    try {
      await openMenuClean(page);
      const useWizard = (k % 3 === 2);
      const prod = useWizard ? await addCayenneWizard(page) : await addSimpleItem(page, 'Voir Coca-Cola 33cl');
      await throughUpsellToCheckout(page);
      if (k === 0) await assertNoHScroll(page, 'paiement mobile');
      const payTotal = euro(await page.locator('.lcf-cta-bar-next').innerText());

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
      const serial = (await page.locator('.lcf-ticket-code').innerText()).trim();
      const confTotal = euro(await page.locator('.lcf-ticket-cell-val').last().innerText());
      expect(serial, `commande ${k + 1} : n° réel`).toMatch(/[A-Z0-9]/);
      expect(confTotal, `commande ${k + 1} : total > 0`).toBeGreaterThan(0);
      expect(confTotal, `commande ${k + 1} : confirmation === paiement`).toBeCloseTo(payTotal, 2);
      placed.push({ n: k + 1, serial: serial.replace(/^#/, ''), product: prod, payTotal, confTotal });
      if (k === 0) await page.screenshot({ path: shot('order-001-confirm-mobile.png'), fullPage: true });
      if (k === 24) await page.screenshot({ path: shot('order-025-confirm-mobile.png'), fullPage: true });
      if (k === N_ORDERS - 1) await page.screenshot({ path: shot(`order-${String(N_ORDERS).padStart(3, '0')}-confirm-mobile.png`), fullPage: true });
      console.log(`[order ${k + 1}/${N_ORDERS}] ${serial} · ${prod} · ${confTotal.toFixed(2)}€`);

      await page.getByRole('button', { name: /Retour à l'accueil/ }).click({ timeout: 8_000 });
      await expect(page.locator('.lc-cart-drawer.is-open')).toHaveCount(0, { timeout: 5_000 }).catch(() => {});
      await page.waitForTimeout(300);
    } catch (e) {
      failures.push({ n: k + 1, error: String(e.message).split('\n')[0].slice(0, 120) });
      console.log(`[order ${k + 1}/${N_ORDERS}] ÉCHEC: ${String(e.message).split('\n')[0].slice(0, 100)}`);
      await gotoDevLocal(page).catch(() => {}); // resync app pour la suivante
    }
  }

  fs.writeFileSync(MANIFEST, JSON.stringify({ phone: PHONE, placed, failures }, null, 2));
  console.log(`[LOT] placées=${placed.length}/${N_ORDERS} échecs=${failures.length}`);
  expect(placed.length, `au moins ${N_ORDERS} commandes réelles`).toBeGreaterThanOrEqual(N_ORDERS);
});
