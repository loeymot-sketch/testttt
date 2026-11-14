/**
 * [FINITION 2026-11-04] Banc : la colonne produits de la caisse va jusqu'au ticket, à toutes les largeurs.
 *
 * Avant : sous le zoom 0.9 de la caisse, `.pos-v5-shell` plafonné à 100vw (= 90 % de l'écran) laissait
 * une bande vide de 94 à 158 px entre la grille et le ticket (mesuré à 1280 → 1920). Correctif :
 * resources/css/pos-v5.css (bloc « FINITION 2026-11-04 »). Ce banc a été joué AVANT le correctif : il
 * échouait aux 6 largeurs (écarts 37/94/102/110/119/158 px).
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/finition-caisse-largeur-2026-11-04.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('../e2e/helpers/login');

for (const [w, h] of [[1024, 768], [1280, 800], [1366, 768], [1440, 900], [1536, 864], [1920, 1080]]) {
  test(`caisse ${w}×${h} : grille produits collée au ticket, sans défilement horizontal`, async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: w, height: h });
    await loginAsPosOperator(page);
    await page.locator('.pos-v4-main').first().waitFor({ timeout: 20_000 });
    await page.locator('#pos-cart').first().waitFor({ timeout: 20_000 });
    await page.waitForTimeout(1500);
    const m = await page.evaluate(() => {
      const main = document.querySelector('.pos-v4-main').getBoundingClientRect();
      const cart = document.querySelector('#pos-cart').getBoundingClientRect();
      return {
        zoom: getComputedStyle(document.body).zoom,
        gap: Math.round(cart.left - main.right),
        hscroll: document.documentElement.scrollWidth > window.innerWidth,
      };
    });
    console.log(`${w}×${h}`, JSON.stringify(m));
    expect(m.zoom).toBe('0.9');
    expect(m.gap, 'espace entre la grille et le ticket').toBeGreaterThanOrEqual(4);
    expect(m.gap, 'espace entre la grille et le ticket').toBeLessThanOrEqual(24);
    expect(m.hscroll).toBe(false);
  });
}
