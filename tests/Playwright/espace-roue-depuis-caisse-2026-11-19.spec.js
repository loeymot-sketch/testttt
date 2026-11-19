/**
 * [ESPACE ROUE 2026-11-19] Depuis la CAISSE, sans retaper le code : trois accès séparés.
 *
 * Parcours réel : connexion caissier → page Encaissement (barre de navigation caisse) →
 *   · « Espace roue »   ouvre un NOUVEL onglet directement dans l'espace commerçant (tableau de bord) ;
 *   · « Avis & réseaux » ouvre l'espace sur la rubrique Avis & réseaux ;
 *   · « Hygiène »       mène à l'accueil hygiène, dans l'application.
 * La passe est signée, à usage unique : aucun code de la maison n'est demandé au caissier.
 *
 * Lancement : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *   npx playwright test tests/Playwright/espace-roue-depuis-caisse-2026-11-19.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const SORTIE = process.env.ESPACE_CAPTURES || path.join(__dirname, '..', 'captures', 'espace-roue');

test.describe.configure({ mode: 'serial', retries: 0 });
test.use({ actionTimeout: 15_000, navigationTimeout: 30_000 });
test.setTimeout(150_000);

test('la caisse ouvre l\'espace roue, les avis et l\'hygiène — sans code', async ({ page, context }) => {
    await loginAsPosOperator(page);
    await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
    const nav = page.locator('[data-testid=caisse-secondary-nav]');
    await expect(nav).toBeVisible({ timeout: 30_000 });
    await expect(nav).toContainText('Espace roue');
    await expect(nav).toContainText('Avis & réseaux');
    await expect(nav).toContainText('Hygiène');
    await expect(nav).not.toContainText('menu.');
    await page.waitForTimeout(800);
    await nav.screenshot({ path: path.join(SORTIE, 'caisse-barre-navigation.png') });

    // 1. Espace roue → nouvel onglet, directement dans l'espace (pas d'écran de code).
    const [roue] = await Promise.all([context.waitForEvent('page'), page.click('[data-testid=csn-roue]')]);
    await roue.waitForURL(/\/admin\/roue\/espace/, { timeout: 30_000 });
    await expect(roue.locator('#chargement')).toBeHidden({ timeout: 30_000 });
    await expect(roue.locator('section[data-view=tableau]')).toBeVisible();
    await expect(roue.locator('input[name=pin]')).toHaveCount(0);
    await roue.close();

    // 2. Avis & réseaux → l'espace, rubrique avis.
    const [avis] = await Promise.all([context.waitForEvent('page'), page.click('[data-testid=csn-avis]')]);
    await avis.waitForURL(/\/admin\/roue\/espace#avis/, { timeout: 30_000 });
    await expect(avis.locator('#chargement')).toBeHidden({ timeout: 30_000 });
    await expect(avis.locator('section[data-view=avis]')).toBeVisible();
    await expect(avis.locator('#titre')).toHaveText('Avis & réseaux');
    await avis.screenshot({ path: path.join(SORTIE, 'caisse-vers-avis.png'), fullPage: true });
    await avis.close();

    // 3. Hygiène → l'accueil hygiène de l'application.
    await page.click('[data-testid=csn-hygiene]');
    await expect(page).toHaveURL(/\/admin\/hygiene\/home/, { timeout: 30_000 });
});
