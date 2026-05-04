/**
 * Pivot V1 critical-flow — ingredient rupture propagation.
 *
 * Requires a live Laravel backend and seeded admin/catalog data.
 * Enable with E2E_BACKEND_AVAILABLE=1.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('../../e2e/helpers/login');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const BACKEND_AVAILABLE = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');

async function openIngredientListWithRows(page) {
  for (const type of ['extra', 'attribute']) {
    await page.goto(`/admin/ingredients/${type}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ingredient-list')).toBeVisible({ timeout: 30_000 });

    const row = page.locator('[data-testid="ingredient-list"] tbody tr').filter({
      has: page.getByRole('switch'),
    }).first();

    if (await row.isVisible({ timeout: 10_000 }).catch(() => false)) {
      return { type, row };
    }
  }

  throw new Error('No toggleable extra or attribute ingredient row found');
}

async function setIngredientAvailability(page, row, shouldBeAvailable) {
  const toggle = row.getByRole('switch').first();
  const isAvailable = (await toggle.getAttribute('aria-checked')) === 'true';

  if (isAvailable === shouldBeAvailable) {
    return;
  }

  if (!shouldBeAvailable) {
    page.once('dialog', (dialog) => dialog.accept('test e2e'));
  }

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-checked', shouldBeAvailable ? 'true' : 'false', { timeout: 15_000 });
}

test.describe('Pivot V1 — ingredient rupture propagation', () => {
  test.skip(!BACKEND_AVAILABLE, 'Backend live required: set E2E_BACKEND_AVAILABLE=1');
  test.setTimeout(120_000);

  test('admin toggles an ingredient out of stock and the state persists after refresh', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);

    const { type, row } = await openIngredientListWithRows(page);
    await setIngredientAvailability(page, row, true);

    const ingredientName = (await row.locator('td').first().innerText()).split('\n')[0].trim();

    await setIngredientAvailability(page, row, false);
    await expect(row.getByText(/En rupture|Out of stock|Rupture/i)).toBeVisible({ timeout: 15_000 });
    await expect(row.getByText(/test e2e/i)).toBeVisible({ timeout: 15_000 });

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ingredient-list')).toBeVisible({ timeout: 30_000 });
    await page.goto(`/admin/ingredients/${type}`, { waitUntil: 'domcontentloaded' });

    const persistedRow = page.locator('[data-testid="ingredient-list"] tbody tr')
      .filter({ hasText: ingredientName })
      .first();
    await expect(persistedRow).toBeVisible({ timeout: 20_000 });
    await expect(persistedRow.getByRole('switch').first()).toHaveAttribute('aria-checked', 'false');

    await setIngredientAvailability(page, persistedRow, true);
    await expect(persistedRow.getByText(/En rupture|Out of stock|Rupture/i)).toHaveCount(0);
  });
});
