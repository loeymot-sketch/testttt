/**
 * [FINITION 2026-11-04] Banc écran cuisine : le sélecteur « 4 · 6 · 8 » ne recouvre plus rien.
 * Avant : flottant à 8 px sous le haut de `.kds-v2` (y 108→160), il masquait « SYNC · LOCAL » du bandeau
 * d'état et le coin de la carte la plus à droite (cartes à partir de y 142). Après : dans la rangée
 * d'outils, entre l'en-tête et le bandeau. Le sélecteur n'existe que s'il y a au moins une commande
 * active : sans commande, le banc est ignoré (il le dit) plutôt que vert pour rien.
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/finition-kds-2026-11-04.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { loginAsChefOperator } = require('../e2e/helpers/login');

for (const [w, h] of [[1024, 768], [1366, 768], [1920, 1080]]) {
  test(`cuisine ${w}×${h} : sélecteur de colonnes hors du bandeau et des cartes`, async ({ page }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: w, height: h });
    await loginAsChefOperator(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.locator('.kds-v2').first().waitFor({ timeout: 20_000 });
    await page.waitForTimeout(4000);
    const picker = page.locator('.kds-cols-picker');
    test.skip(await picker.count() === 0, 'aucune commande active : le sélecteur n’est pas rendu, rien à mesurer');
    const m = await page.evaluate(() => {
      const r = (e) => e.getBoundingClientRect();
      const p = r(document.querySelector('.kds-cols-picker'));
      const zone = r(document.querySelector('.kds-v2'));
      const outils = [...document.querySelectorAll('button')].filter((b) => /Rupture|Historique|Afficher les noms/.test(b.innerText)).map(r);
      const cartes = [...document.querySelectorAll('.kds-v2__grid > *')].map(r);
      const chevauche = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
      return {
        pickerBas: Math.round(p.bottom), zoneHaut: Math.round(zone.top), pickerHaut: Math.round(p.top),
        surOutil: outils.some((o) => chevauche(p, o)), surCarte: cartes.some((c) => chevauche(p, c)),
      };
    });
    console.log(`${w}×${h}`, JSON.stringify(m));
    expect(m.pickerBas, 'le sélecteur reste au-dessus du bandeau d’état').toBeLessThanOrEqual(m.zoneHaut);
    expect(m.pickerHaut, 'le sélecteur reste sous l’en-tête').toBeGreaterThanOrEqual(56);
    expect(m.surOutil).toBe(false);
    expect(m.surCarte).toBe(false);
  });
}
