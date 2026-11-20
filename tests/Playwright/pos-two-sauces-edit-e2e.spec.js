// [ORDER-INTEGRITY-KDS-LOYALTY-20260914]
// Real cashier journey for the reported regression: two selected product sauces
// must survive opening the cart line again and confirming the edit unchanged.
// It stops before payment and empties the local POS cart on exit.
const { test, expect } = require('@playwright/test');

// Keep this direct-navigation spec on the same target as Playwright's web
// server. A stale hard-coded port silently turned every regression run into an
// ECONNREFUSED false negative when the standard local server used port 8000.
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

async function loginAsPos(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.locator('button:has-text("Connexion")').click();
  await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 15_000 });
}

async function clearCart(page) {
  const cancelLast = page.getByTestId('pos-cancel-last-line');
  for (let attempt = 0; attempt < 8; attempt += 1) {
    if (!await cancelLast.isVisible({ timeout: 600 }).catch(() => false)) break;
    await cancelLast.click();
    await page.waitForTimeout(250);
  }
}

test.describe('caisse : deux sauces survivent à la modification', () => {
  test.describe.configure({ timeout: 90_000, retries: 0 });

  test('Cayenne garde Andalouse et Algérienne après reopen + confirmation', async ({ page }) => {
    await loginAsPos(page);
    await clearCart(page);

    const grid = page.getByTestId('pos-category-grid');
    await expect(grid).toBeVisible({ timeout: 20_000 });
    await grid.getByTestId('pos-category-tile').filter({ hasText: /sandwich/i }).first().click();

    const cayenne = page.locator('[data-pos-item-id="22"]').first();
    await expect(cayenne).toBeVisible({ timeout: 15_000 });
    await cayenne.click();

    const modal = page.locator('#item-variation-modal');
    const sauceSection = modal.locator('.sauce-section');
    await expect(modal).toBeVisible({ timeout: 10_000 });
    // Le support est obligatoire pour un sandwich : remplir explicitement le
    // fixture, plutôt que de dépendre d'un ancien défaut implicite du wizard.
    const painButton = modal.locator('.pain-section button').filter({ hasText: /Pain/i }).first();
    await expect(painButton).toBeVisible();
    await painButton.click();
    await modal.locator('.viande-section .wizard-viande-tile').filter({ hasText: /poulet marin/i }).first()
      .locator('.viande-tile-add').click();
    // Cayenne exige deux viandes. Le correctif POS récent supprime à juste titre
    // la viande fantôme par défaut : le fixture doit donc sélectionner les deux
    // choix explicitement avant de vérifier la conservation des deux sauces.
    await modal.locator('.viande-section .wizard-viande-tile').filter({ hasText: /viande hachée/i }).first()
      .locator('.viande-tile-add').click();

    const andalouse = sauceSection.locator('.sauce-chip').filter({ hasText: /andalouse/i }).first();
    const algerienne = sauceSection.locator('.sauce-chip').filter({ hasText: /algérienne/i }).first();
    await andalouse.click();
    await algerienne.click();
    await expect(sauceSection.locator('.sauce-chip.selected')).toHaveCount(2);
    await expect(andalouse).toHaveClass(/selected/);
    await expect(algerienne).toHaveClass(/selected/);

    await modal.locator('button[data-action="add-to-cart"]').first().click();
    await expect(modal).toBeHidden({ timeout: 10_000 });

    const line = page.locator('article.pos-v5-cart-item').filter({ hasText: /cayenne/i }).first();
    await expect(line).toBeVisible({ timeout: 10_000 });
    await expect(line).toContainText(/andalouse/i);
    await expect(line).toContainText(/algérienne/i);
    const priceBefore = await line.locator('.pos-v5-cart-item__price').innerText();
    const detailsBefore = await line.locator('.pos-v5-cart-item__detail').innerText();

    await line.getByTestId('pos-cart-edit').click();
    await expect(modal).toBeVisible({ timeout: 10_000 });
    await expect(sauceSection.locator('.sauce-chip.selected')).toHaveCount(2);
    await expect(andalouse).toHaveClass(/selected/);
    await expect(algerienne).toHaveClass(/selected/);

    await modal.locator('button[data-action="add-to-cart"]').first().click();
    await expect(modal).toBeHidden({ timeout: 10_000 });
    await expect(line.locator('.pos-v5-cart-item__price')).toHaveText(priceBefore.trim());
    await expect(line.locator('.pos-v5-cart-item__detail')).toHaveText(detailsBefore.trim());

    await clearCart(page);
    await expect(page.locator('article.pos-v5-cart-item')).toHaveCount(0);
  });
});
