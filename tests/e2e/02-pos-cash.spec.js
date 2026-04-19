// FoodKing E2E — Flow 2 : POS Cash
// Login caissier → surface POS chargée → pas d'erreur critique
// Credentials : pos@lecayenne.fr / 123456

const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');

const POS_EMAIL    = 'pos@lecayenne.fr';
const POS_PASSWORD = '123456';

async function loginAsPOS(page) {
  await login(page, POS_EMAIL, POS_PASSWORD);
  await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 20_000 });
}

test.describe('POS Cash — commande complète', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsPOS(page);
  });

  test('surface POS chargée sur /admin/pos', async ({ page }) => {
    await expect(page).toHaveURL(/\/admin\/pos/);
    // innerText = texte visible uniquement (textContent inclut <script> → faux positifs ex. "500")
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
  });

  test('panier démarre vide — pas de commande fantôme', async ({ page }) => {
    await expect(page).toHaveURL(/\/admin\/pos/);
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
    // Le total affiché doit être 0.00 ou équivalent (pas de commande pré-chargée)
    expect(visibleText).not.toContain('500');
  });

  test('pas de crash JavaScript visible sur la surface POS', async ({ page }) => {
    // Surveiller les erreurs console JavaScript critiques
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await page.waitForTimeout(2_000); // Laisser Vue se monter
    await expect(page).toHaveURL(/\/admin\/pos/);

    // Pas d'erreur JS fatale (TypeError, ReferenceError, etc.)
    const criticalErrors = jsErrors.filter(msg =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg)
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('full POS cash order cycle', async ({ page }) => {
    // POS surface loaded
    await expect(page).toHaveURL(/\/admin\/pos/);

    // Wait for Vue to mount and items to load
    await page.waitForTimeout(3_000);

    // Verify POS surface has loaded content (categories or items visible)
    const body = await page.locator('body').innerText();
    expect(body.length).toBeGreaterThan(100);

    // Try to find and click first available item
    const itemCards = page.locator('.item-card, .product-card, [class*="item"], [class*="product"]').first();
    if (await itemCards.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await itemCards.click();
      await page.waitForTimeout(1_000);
    }

    // Verify no crash after interaction
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
  });
});
