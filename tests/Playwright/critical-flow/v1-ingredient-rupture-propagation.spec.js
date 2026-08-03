/**
 * Pivot V1 critical-flow — ingredient rupture propagation.
 *
 * Requires a live Laravel backend and seeded admin/catalog data.
 * Enable with E2E_BACKEND_AVAILABLE=1.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('../../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const BACKEND_AVAILABLE = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');

const E2E_INGREDIENT_NAMES = {
  extra: 'E2E_PLAYWRIGHT_EXTRA_TOGGLE',
  attribute: 'E2E_PLAYWRIGHT_ATTRIBUTE_TOGGLE',
};

async function openIngredientListWithRows(page) {
  for (const type of ['extra', 'attribute']) {
    const listResponse = page.waitForResponse(
      (resp) =>
        resp.url().includes('/admin/ingredients') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
      { timeout: 30_000 },
    );
    await page.goto(`/admin/ingredients/${type}`, { waitUntil: 'domcontentloaded' });
    await listResponse;
    await expect(page.getByTestId('ingredient-list')).toBeVisible({ timeout: 30_000 });

    const seeded = E2E_INGREDIENT_NAMES[type];
    const seededRow = page.locator('[data-testid="ingredient-list"] tbody tr').filter({
      has: page.locator('th p.text-sm.font-semibold').getByText(seeded, { exact: true }),
    });
    const seededSwitch = seededRow.getByRole('switch').first();
    // Ne pas retomber sur « première ligne avec switch » : ça casse la persistance (mauvais global_id).
    // Si ce type n’a pas la ligne seedée, essayer l’autre onglet.
    try {
      await expect(seededSwitch).toBeVisible({ timeout: 25_000 });
      return { type, row: seededRow.first() };
    } catch {
      continue;
    }
  }

  throw new Error(
    'Missing E2E seeded ingredient rows (E2EPlaywrightIngredientRowsSeeder + global-setup).',
  );
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

  // IngredientAvailabilityToggleComponent updates aria-checked optimistically before axios completes;
  // reload/navigation must wait for PUT/PATCH …/availability 200 or we flake (DB still "available").
  const availabilitySaved = page.waitForResponse(
    (resp) => {
      const u = resp.url();
      return (
        u.includes('/admin/ingredients/') &&
        u.includes('/availability') &&
        ['PUT', 'PATCH'].includes(resp.request().method())
      );
    },
    { timeout: 45_000 },
  );

  await toggle.click();
  const putResp = await availabilitySaved;
  if (putResp.status() !== 200) {
    const body = await putResp.text().catch(() => '');
    throw new Error(`ingredient availability save failed: HTTP ${putResp.status()} ${body.slice(0, 400)}`);
  }
  await expect(toggle).toHaveAttribute('aria-checked', shouldBeAvailable ? 'true' : 'false', { timeout: 15_000 });
}

test.describe('Pivot V1 — ingredient rupture propagation', () => {
  test.skip(!BACKEND_AVAILABLE, 'Backend live required: set E2E_BACKEND_AVAILABLE=1');

  test('admin toggles an ingredient out of stock and the state persists after refresh', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    clearFoodKingRateLimits();

    const { type, row } = await openIngredientListWithRows(page);
    await setIngredientAvailability(page, row, true);

    // Name lives in the row header cell (`<th scope="row">`), not the type `<td>`.
    const ingredientName = (await row.locator('th').first().innerText()).split('\n')[0].trim();
    const ingredientGlobalId = await row.getAttribute('data-global-id');
    if (!ingredientGlobalId) {
      throw new Error('ingredient row missing data-global-id (IngredientListComponent)');
    }

    await setIngredientAvailability(page, row, false);
    await expect(row.getByText(/En rupture|Out of stock|Rupture/i)).toBeVisible({ timeout: 15_000 });
    await expect(row.getByText(/test e2e/i)).toBeVisible({ timeout: 15_000 });

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ingredient-list')).toBeVisible({ timeout: 30_000 });
    const listAfterNav = page.waitForResponse(
      (resp) =>
        resp.url().includes('/admin/ingredients') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
      { timeout: 30_000 },
    );
    await page.goto(`/admin/ingredients/${type}`, { waitUntil: 'domcontentloaded' });
    await listAfterNav;

    // hasText(ingredientName) matches substrings — another row could win .first() and still show available.
    const persistedRow = page.locator(
      `[data-testid="ingredient-list"] tbody tr[data-global-id="${ingredientGlobalId}"]`,
    );
    await expect(persistedRow).toBeVisible({ timeout: 20_000 });
    await expect(persistedRow.getByRole('switch').first()).toHaveAttribute('aria-checked', 'false', {
      timeout: 20_000,
    });

    await setIngredientAvailability(page, persistedRow, true);
    await expect(persistedRow.getByText(/En rupture|Out of stock|Rupture/i)).toHaveCount(0);
  });
});
