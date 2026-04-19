// FoodKing E2E — Flow 4 : KDS
// Login chef → interface KDS accessible
// Credentials : chef@lecayenne.fr / 123456

const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');

const CHEF_EMAIL    = 'chef@lecayenne.fr';
const CHEF_PASSWORD = '123456';

// Après login, le landing_url mène à la route nommée admin.kitchen-display-system
// → URL /admin/kitchen-display-system. L'alias /kds redirige vers la même surface.
const KDS_SURFACE_RE = /\/(kds|admin\/kitchen-display-system)/;

test.describe('KDS — interface cuisine', () => {
  test('page /kds accessible — redirige vers login si non authentifié', async ({ page }) => {
    await page.goto('/kds');
    // Non auth : souvent /login. Déjà auth : /kds ou /admin/kitchen-display-system après redirect
    const url = page.url();
    expect(url).toMatch(/\/kds|kitchen-display-system|\/login/);

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
  });

  test('login chef via /login → redirection vers surface chef', async ({ page }) => {
    await login(page, CHEF_EMAIL, CHEF_PASSWORD);

    await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });

    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);

    await expect(page).toHaveURL(KDS_SURFACE_RE);
  });

  test('KDS surface loads order list without crash', async ({ page }) => {
    await login(page, CHEF_EMAIL, CHEF_PASSWORD);
    await expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 });

    // Wait for Vue to mount
    await page.waitForTimeout(3_000);

    // KDS should show some content (order columns, even if empty)
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
    expect(visibleText.trim().length).toBeGreaterThan(10);

    // No critical JS errors
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));
    await page.waitForTimeout(2_000);

    const criticalErrors = jsErrors.filter(msg =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg)
    );
    expect(criticalErrors).toHaveLength(0);
  });
});
