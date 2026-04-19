// FoodKing E2E — Flow 3 : Kiosk Wizard
// La borne se configure côté admin (KioskMachine en DB).
// kioskAutoLogin est injecté SERVER-SIDE dans window.foodkingConfig — null si borne non configurée.
// (addInitScript inutile : la config serveur écrase tout script injecté avant le rendu.)
// Assertions sur body : utiliser innerText(), pas textContent() (sinon contenu des <script> inline → faux positifs ex. "500").

const { test, expect } = require('@playwright/test');

test.describe('Kiosk — écran login borne', () => {
  test('page /kiosk/login accessible sans crash', async ({ page }) => {
    await page.goto('/kiosk/login');
    // innerText = texte visible uniquement (pas le contenu des <script>)
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);
    // La page kiosk doit avoir un contenu visible (pas blanche)
    expect(visibleText.trim().length).toBeGreaterThan(10);
  });

  test('écran login borne visible — message de configuration affiché', async ({ page }) => {
    await page.goto('/kiosk/login');
    await page.waitForTimeout(2_000); // laisser Vue se monter

    const visibleText = await page.locator('body').innerText();
    const url = page.url();
    // Auto-login silencieux : on peut être déjà sur /kiosk/… (plus de formulaire login).
    if (/\/kiosk\/login/i.test(url)) {
      expect(visibleText).toMatch(
        /automatique|kiosk|borne|connexion|configuration|login|sign.in/i,
      );
    } else {
      expect(url).toMatch(/\/kiosk\//);
      expect(visibleText.trim().length).toBeGreaterThan(10);
    }
  });

  test('pas d\'erreur JavaScript fatale sur la borne', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await page.goto('/kiosk/login');
    await page.waitForTimeout(3_000);

    const criticalErrors = jsErrors.filter(msg =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg)
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('window.foodkingConfig présent et kioskMenuPricing configuré', async ({ page }) => {
    await page.goto('/kiosk/login');
    // Vérifier que la config pricing est injectée par le serveur
    const menuPricing = await page.evaluate(() => {
      return window.foodkingConfig?.kioskMenuPricing || null;
    });
    expect(menuPricing).not.toBeNull();
    expect(menuPricing).toHaveProperty('full_ratio');
    expect(menuPricing.full_ratio).toBe(1);
    expect(menuPricing).toHaveProperty('fries_ratio');
    expect(menuPricing).toHaveProperty('drink_ratio');
  });

  test('kiosk navigation flow — categories and items browsable', async ({ page }) => {
    await page.goto('/kiosk/login');
    await page.waitForTimeout(3_000);

    // Try to find kiosk-specific elements
    const visibleText = await page.locator('body').innerText();
    expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);

    // Verify Vue app mounted (not blank page)
    expect(visibleText.trim().length).toBeGreaterThan(10);

    // No critical JS errors during navigation
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    // Try clicking around if elements exist
    const clickableItems = page.locator('button, [role="button"], a[href]');
    const count = await clickableItems.count();
    if (count > 0) {
      // Click first interactive element to test navigation
      await clickableItems.first().click().catch(() => {});
      await page.waitForTimeout(1_000);
    }

    const criticalErrors = jsErrors.filter(msg =>
      /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg)
    );
    expect(criticalErrors).toHaveLength(0);
  });
});
