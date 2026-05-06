/**
 * Pivot V1 critical-flow — axe-core a11y on the Ingredients page.
 *
 * Requires a live Laravel backend and @axe-core/playwright.
 * Enable with E2E_BACKEND_AVAILABLE=1.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('../../e2e/helpers/login');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const BACKEND_AVAILABLE = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');

let AxeBuilder = null;

try {
  AxeBuilder = require('@axe-core/playwright').default;
} catch (error) {
  // Resolved in beforeAll so CI fails unless the skip is explicitly allowed.
}

test.describe('Pivot V1 — Ingredients a11y', () => {
  test.skip(!BACKEND_AVAILABLE, 'Backend live required: set E2E_BACKEND_AVAILABLE=1');
  test.setTimeout(120_000);

  test.beforeAll(() => {
    if (!AxeBuilder) {
      if (process.env.ALLOW_AXE_SKIP === '1') {
        test.skip(true, '@axe-core/playwright absent (ALLOW_AXE_SKIP=1)');
      } else {
        throw new Error('@axe-core/playwright must be installed for the Ingredients a11y sentinel.');
      }
    }
  });

  async function openIngredientsPage(page) {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto('/admin/ingredients', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('ingredient-list')).toBeVisible({ timeout: 30_000 });
  }

  async function expectNoBlockingViolations(page) {
    test.skip(!AxeBuilder, '@axe-core/playwright not installed');

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    const blockingViolations = results.violations.filter((violation) =>
      ['critical', 'serious'].includes(violation.impact),
    );

    expect(blockingViolations).toHaveLength(0);
  }

  test('has no serious or critical WCAG violations on /admin/ingredients', async ({ page }) => {
    await openIngredientsPage(page);
    await expectNoBlockingViolations(page);
  });

  test('has no serious or critical WCAG violations with the usage drawer open', async ({ page }) => {
    await openIngredientsPage(page);

    await page.getByRole('button', { name: /voir les détails|view details/i }).first().click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await expectNoBlockingViolations(page);
  });

  test('toggles the first ingredient switch from the keyboard', async ({ page }) => {
    await openIngredientsPage(page);

    const toggle = page.getByTestId('ingredient-list').getByRole('switch').first();
    await expect(toggle).toBeVisible({ timeout: 25_000 });

    const before = await toggle.getAttribute('aria-checked');
    // `window.prompt` can fire on each unavailable toggle; handle every dialog (not `once`).
    const onDialog = (dialog) => {
      void dialog.accept('A11y keyboard test');
    };
    page.on('dialog', onDialog);
    try {
      await toggle.focus();
      await toggle.press('Space');
      await expect
        .poll(async () =>
          page.getByTestId('ingredient-list').getByRole('switch').first().getAttribute('aria-checked'),
          { timeout: 15_000 },
        )
        .not.toBe(before || '');
    } finally {
      page.off('dialog', onDialog);
    }
  });

  test('has no serious or critical WCAG violations on the empty state', async ({ page }) => {
    await page.route('**/admin/ingredients**', async (route) => {
      const request = route.request();
      if (['fetch', 'xhr'].includes(request.resourceType()) && request.method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [] }),
        });
        return;
      }

      await route.continue();
    });

    await openIngredientsPage(page);
    await expect(page.getByTestId('ingredient-empty')).toBeVisible();

    await expectNoBlockingViolations(page);
  });
});
