/**
 * Pivot V1 critical-flow — ultra-clean admin sidebar.
 *
 * Requires a live Laravel backend and seeded admin credentials.
 * Enable with E2E_BACKEND_AVAILABLE=1.
 */
const { test, expect } = require('@playwright/test');
const { login } = require('../../e2e/helpers/login');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
const BACKEND_AVAILABLE = /^(1|true|yes)$/i.test(process.env.E2E_BACKEND_AVAILABLE || '');

test.describe('Pivot V1 — admin sidebar cleanup', () => {
  test.skip(!BACKEND_AVAILABLE, 'Backend live required: set E2E_BACKEND_AVAILABLE=1');
  test.setTimeout(90_000);

  test('shows only V1 core navigation and hides legacy back-office links', async ({ page }) => {
    await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });

    const sidebar = page.locator('.db-sidebar');
    await expect(sidebar).toBeVisible({ timeout: 30_000 });

    for (const expected of [/Stock/i, /Catalogue/i, /Ingrédients/i, /Commandes/i]) {
      await expect(sidebar.getByText(expected).first()).toBeVisible();
    }

    for (const hidden of [
      /Permissions/i,
      /Rôles|Roles/i,
      /Translations|Traductions/i,
      /Activity Log|Journal d'activité/i,
      /Pages/i,
      /Sliders/i,
      /Theme|Thème/i,
      /Demo Wizard avancé/i,
    ]) {
      await expect(sidebar.getByText(hidden)).toHaveCount(0);
    }
  });
});
