const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin, loginAsPosOperator } = require('../../e2e/helpers/login');

// [LIVRAISONS-PAGE 2026-10-23] Accès par la barre latérale : « Livraisons » (planche des départs + bascule nos livreurs ⇄ Uber)
// et « Écran livreur ». Preuve en navigateur réel, pour l'administrateur ET pour l'opérateur caisse.
const OUT = path.resolve(__dirname, '../../../reports/test-e2e/livraisons-page-2026-10-23/captures');
const cap = (page, name) => page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: false });

for (const [who, login] of [['admin', loginAsAdmin], ['caisse', loginAsPosOperator]]) {
  test(`${who} : la barre latérale ouvre Livraisons, la planche et la bascule Uber s'affichent`, async ({ page }) => {
    test.setTimeout(120_000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(1500);

    const side = page.locator('aside.db-sidebar');
    const livraisons = side.locator('a.db-sidebar-nav-menu', { hasText: 'Livraisons' });
    const livreur = side.locator('a.db-sidebar-nav-menu', { hasText: 'Écran livreur' });
    await expect(livraisons, 'entrée Livraisons absente de la barre latérale').toHaveCount(1);
    await expect(livreur, 'entrée Écran livreur absente').toHaveCount(1);
    await expect(livreur).toHaveAttribute('href', '/livreur');
    await expect(livreur).toHaveAttribute('target', '_blank');
    await cap(page, `${who}-01-barre-laterale`);

    // Accès rapides du tableau de bord : mêmes deux entrées.
    const rapide = page.locator('nav[aria-label] a', { hasText: 'Livraisons' });
    await expect(rapide, 'accès rapide Livraisons absent du tableau de bord').toHaveCount(1);
    const rapideLivreur = page.locator('nav[aria-label] a', { hasText: 'Écran livreur' });
    await expect(rapideLivreur).toHaveAttribute('href', '/livreur');
    await expect(rapideLivreur).toHaveAttribute('target', '_blank');

    await livraisons.click();
    await expect(page).toHaveURL(/\/admin\/livraisons/, { timeout: 20_000 });
    const board = page.getByTestId('pos-delivery-board');
    await expect(board).toBeVisible({ timeout: 20_000 });
    await expect(board).toContainText('Tournées de livraison');
    // Page, pas fenêtre : pas de dialogue modal, pas de « Fermer », la barre latérale reste visible et cliquable.
    await expect(page.locator('[role="dialog"][aria-label="Tournées de livraison"]')).toHaveCount(0);
    await expect(board.getByRole('button', { name: 'Fermer' })).toHaveCount(0);
    await expect(side).toBeVisible();
    await expect(page.getByTestId('csn-livraisons')).toHaveClass(/active/);
    await page.waitForTimeout(1500);
    await cap(page, `${who}-02-page-livraisons`);

    // La bascule : au moins un arrêt non livré propose « Confier à Uber » ou dit pourquoi il est grisé.
    const dispatch = page.locator('[data-testid^="pos-delivery-dispatch-"]');
    const n = await dispatch.count();
    if (n > 0) {
      await expect(dispatch.first()).toContainText(/Nos livreurs|Uber/);
      await expect(page.getByTestId('pos-delivery-use-uber').first()).toBeVisible();
    }

    // Retour : le lien « Écran livreur » de la nav caisse existe aussi dans la page.
    await expect(page.getByTestId('csn-livreur')).toHaveAttribute('href', '/livreur');
    expect(errors, `erreurs JS de page : ${errors.join(' | ')}`).toEqual([]);
  });
}

test('mobile 390 : la page Livraisons ne déborde pas', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 390, height: 844 });
  await loginAsAdmin(page);
  await page.goto('/admin/livraisons', { waitUntil: 'domcontentloaded' });
  const board = page.getByTestId('pos-delivery-board');
  await expect(board).toBeVisible({ timeout: 25_000 });
  await page.waitForTimeout(1500);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, 'défilement horizontal de la page').toBeLessThanOrEqual(1);
  await cap(page, 'mobile-03-page-livraisons');
});

test('la fenêtre « Tournées » de la caisse fonctionne toujours (non-régression)', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginAsPosOperator(page);
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  const ouvrir = page.getByTestId('pos-delivery-board-open');
  await expect(ouvrir).toBeVisible({ timeout: 30_000 });
  await ouvrir.click();
  const dlg = page.locator('[role="dialog"][aria-label="Tournées de livraison"]');
  await expect(dlg).toBeVisible();
  await cap(page, 'caisse-04-fenetre-tournees');
  await dlg.getByRole('button', { name: 'Fermer' }).click();
  await expect(dlg).toHaveCount(0);
});
