// FoodKing E2E — Flow 1 : Auth Refresh (F5)
// Vérifie que F5 sur /admin/pos relance authcheck et revient sur la bonne surface (pas frontend.home)
// Credentials : pos@lecayenne.fr / 123456

const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');

const POS_EMAIL    = 'pos@lecayenne.fr';
const POS_PASSWORD = '123456';

test.describe('Auth Refresh — F5 sur POS', () => {
  test('login POS → F5 → reste sur /admin/pos', async ({ page }) => {
    // 1. Login
    await login(page, POS_EMAIL, POS_PASSWORD);

    // 2. Attendre la redirection vers POS
    await page.waitForURL(/\/admin\/pos/, { timeout: 15_000 });
    await expect(page).toHaveURL(/\/admin\/pos/);

    // 3. F5 — reload
    await page.reload();

    // 4. Après reload, doit rester sur /admin/pos (pas redirigé sur / ou /home)
    await page.waitForURL(/\/admin\/pos/, { timeout: 15_000 });
    await expect(page).toHaveURL(/\/admin\/pos/);

    // 5. Vérifier qu'on n'est PAS redirigé vers frontend.home
    await expect(page).not.toHaveURL('/');
    await expect(page).not.toHaveURL('/home');
  });

  test('user info preserved after F5', async ({ page }) => {
    await login(page, POS_EMAIL, POS_PASSWORD);
    await page.waitForURL(/\/admin\/pos/, { timeout: 15_000 });

    // Store some page state before reload
    const urlBefore = page.url();

    await page.reload();
    await page.waitForURL(/\/admin\/pos/, { timeout: 15_000 });

    // URL preserved
    expect(page.url()).toBe(urlBefore);

    // Not redirected to login
    await expect(page).not.toHaveURL(/\/login/);
  });
});
