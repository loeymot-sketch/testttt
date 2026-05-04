/**
 * Sentinel a11y — Catalog Studio (/admin/items/studio).
 * Prérequis : serveur Laravel (PLAYWRIGHT_BASE_URL) + données seed (login admin).
 * @axe-core/playwright : déclarée en prod du test ; sinon les tests axe sont ignorés (voir rapport α6).
 */
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers/login');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

let AxeBuilder = null;
try {
  AxeBuilder = require('@axe-core/playwright').default;
} catch {
  /* package optionnel → tests axe skippés */
}

/**
 * @param {import('playwright').Page} page
 */
async function openCatalogStudio(page) {
  await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  // Même périmètre que PLAYWRIGHT_BASE_URL + '/admin/items/studio' (baseURL Playwright).
  await page.goto('/admin/items/studio', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('catalog-studio-page')).toBeVisible({ timeout: 30_000 });
}

test.describe('Catalog Studio a11y (axe-core)', () => {
  test.setTimeout(120_000);

  test.describe('violations axe (WCAG 2 A / AA tags)', () => {
    test('should have no critical violations on default state', async ({ page }) => {
      test.skip(!AxeBuilder, '@axe-core/playwright not installed');

      await openCatalogStudio(page);
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations.filter((v) => v.impact === 'critical').length).toBe(0);
    });

    test('should have no serious violations on category-selected state', async ({ page }) => {
      test.skip(!AxeBuilder, '@axe-core/playwright not installed');

      await openCatalogStudio(page);
      const catRow = page.locator('[data-testid^="catalog-studio-category-row-"]').first();
      await expect(catRow).toBeVisible({ timeout: 25_000 });
      await catRow.locator('button.catalog-studio__category').first().click();

      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious').length).toBe(0);
    });
  });

  test('should annotate focusable elements with visible focus ring', async ({ page }) => {
    await openCatalogStudio(page);

    await expect(page.getByTestId('catalog-studio-page')).toBeVisible();

    let inspectedControls = 0;
    /** @type {string[]} */
    const missingIndicator = [];

    for (let i = 0; i < 30; i++) {
      await page.keyboard.press('Tab');
      /** @typedef {{ skipped: boolean, reason?: string, tag?: string, hasRing?: boolean }} FocusSnap */
      /** @type {FocusSnap} */
      const snap = await page.evaluate(() => {
        const el = document.activeElement;
        if (!(el instanceof HTMLElement)) return { skipped: true, reason: 'no-element' };
        const root = document.querySelector('[data-testid="catalog-studio-page"]');
        if (!root || !root.contains(el)) return { skipped: true, reason: 'outside-studio' };
        if (el === document.body || el.tagName.toLowerCase() === 'html') return { skipped: true, reason: 'chrome' };

        const tag = el.tagName.toLowerCase();
        // Skip anchors / controls recouverts par un style global — on exige un signal sur les boutons & champs.
        const isInteractive =
          ['button', 'a', 'input', 'textarea', 'select'].includes(tag) ||
          el.getAttribute('tabindex') === '0' ||
          (el.getAttribute('role') &&
            ['button', 'link', 'tab', 'menuitem', 'textbox'].includes(String(el.getAttribute('role'))));
        if (!isInteractive) return { skipped: true, reason: 'non-interactive' };

        const cs = window.getComputedStyle(el);
        const outlineW = parseFloat(cs.outlineWidth) || 0;
        const outlineInvisible =
          cs.outlineStyle === 'none' || outlineW < 1 || !opaqueOutline(cs.outlineColor);
        const boxShadowOk =
          !!(cs.boxShadow &&
            cs.boxShadow !== 'none' &&
            !/^none\b/i.test(String(cs.boxShadow).trim()));

        /** @param {string} colorStr */
        function opaqueOutline(colorStr) {
          if (!colorStr || colorStr === 'transparent') return false;
          const m = String(colorStr).match(/rgba?\([^)]*\)/i);
          if (m && /rgba?\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i.test(m[0])) return false;
          return true;
        }

        const hasRing = !outlineInvisible || boxShadowOk;

        return { skipped: false, tag: el.tagName, hasRing };
      });

      if (snap.skipped || snap.hasRing === undefined) continue;

      inspectedControls += 1;
      if (!snap.hasRing) {
        missingIndicator.push(`${snap.tag} (tab #${i + 1})`);
      }
    }

    expect(
      inspectedControls,
      'attendu ≥1 focus sur un contrôle dans catalog-studio après Tab répété',
    ).toBeGreaterThan(0);
    expect(missingIndicator, `focus sans contour/ombre perceptible: ${missingIndicator.join('; ')}`).toHaveLength(
      0,
    );
  });
});
