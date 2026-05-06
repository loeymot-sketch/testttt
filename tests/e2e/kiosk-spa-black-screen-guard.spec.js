/**
 * Garde-fou E2E — écran noir SPA kiosk (idle ↔ categories sans F5).
 * Reproduit la navigation client-side et vérifie que la racine routée reste
 * réellement visible (opacity + surface), pas seulement présente dans le DOM.
 *
 * Prérequis : serveur Laravel sur PLAYWRIGHT_BASE_URL (défaut http://localhost:8000)
 * avec borne auto-login configurée (kiosk idle joignable).
 */
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');

const ROUNDS = Number(process.env.KIOSK_SPA_GUARD_ROUNDS || 3);

/**
 * @param {import('@playwright/test').Page} page
 */
async function waitBranchBoot(page) {
  const overlay = page.locator('.kiosk-init-overlay').first();
  await overlay.waitFor({ state: 'hidden', timeout: 90_000 }).catch(() => {});
}

/**
 * Le shell applique des transitions sur la vue routée : si opacity reste à 0,
 * l’utilisateur voit un écran noir jusqu’à F5.
 * @param {import('@playwright/test').Page} page
 * @param {string} rootTestId data-testid du composant racine (idle ou categories)
 */
async function expectRoutedRootActuallyVisible(page, rootTestId) {
  const root = page.getByTestId(rootTestId);
  await expect(root).toBeVisible({ timeout: 25_000 });

  await page.waitForFunction(
    (id) => {
      const el = document.querySelector(`[data-testid="${id}"]`);
      if (!el) return false;
      const cs = window.getComputedStyle(el);
      const r = el.getBoundingClientRect();
      const op = parseFloat(cs.opacity);
      if (cs.visibility === 'hidden' || cs.display === 'none') return false;
      if (!(op >= 0.92)) return false;
      if (r.width < 80 || r.height < 80) return false;

      const app = document.querySelector('.kiosk-app');
      if (app) {
        const ap = parseFloat(window.getComputedStyle(app).opacity);
        if (!(ap >= 0.92)) return false;
      }
      return true;
    },
    rootTestId,
    { timeout: 20_000 },
  );

  const txt = await root.innerText().catch(() => '');
  expect(txt.trim().length, `surface ${rootTestId} doit avoir du texte ou contrôles visibles`).toBeGreaterThan(8);
}

test.describe('Kiosk SPA — pas d’écran noir après navigation client', () => {
  test.describe.configure({ retries: 2 });
  test.setTimeout(180_000);

  test(`idle → categories → abandon (×${ROUNDS} tours) — racines opaques et lisibles`, async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });

    await page.waitForURL(/\/kiosk\/(idle|login)/, { timeout: 60_000 }).catch(async () => {
      await expect(page).toHaveURL(/\/kiosk\//, { timeout: 5000 });
    });

    if (/\/kiosk\/login/i.test(page.url())) {
      // Auto-login serveur absent sur certaines DB : connexion borne explicite (même creds que les autres E2E).
      await loginAsKiosk(page);
      await page.waitForURL((u) => !/kiosk\/login/i.test(u.pathname), { timeout: 30_000 }).catch(() => {});
    }

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await waitBranchBoot(page);

    for (let i = 0; i < ROUNDS; i++) {
      await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 30_000 });
      await expectRoutedRootActuallyVisible(page, 'kiosk-idle-root');

      const takeaway = page.getByTestId('kiosk-order-type-takeaway');
      const dineIn = page.getByTestId('kiosk-order-type-dine-in');
      const orderBtn = (await takeaway.isVisible().catch(() => false)) ? takeaway : dineIn;
      await expect(orderBtn).toBeVisible({ timeout: 15_000 });
      await orderBtn.click();

      await expect(page).toHaveURL(/\/kiosk\/categories/, { timeout: 25_000 });
      await page.waitForSelector('[data-testid="kiosk-categories-root"]', {
        state: 'attached',
        timeout: 45_000,
      });
      await waitBranchBoot(page);

      await expectRoutedRootActuallyVisible(page, 'kiosk-categories-root');

      const abandon = page.getByTestId('kiosk-categories-abandon');
      await expect(abandon).toBeVisible({ timeout: 15_000 });
      await abandon.click();

      await expect(page).toHaveURL(/\/kiosk\/idle/, { timeout: 25_000 });
      await waitBranchBoot(page);
    }

    const critical = jsErrors.filter((msg) =>
      /TypeError|ReferenceError|Cannot read properties|is not a function|is not defined/i.test(msg),
    );
    expect(critical, `erreurs JS critiques: ${critical.join('\n')}`).toHaveLength(0);
  });

  test('deep-link /categories sans session commande → idle OU catalogue visible', async ({ page }) => {
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    if (/\/kiosk\/login/i.test(page.url())) {
      await loginAsKiosk(page);
    }
    await waitBranchBoot(page);

    const idle = page.getByTestId('kiosk-idle-root');
    const cat = page.getByTestId('kiosk-categories-root');
    await idle.or(cat).first().waitFor({ state: 'visible', timeout: 45_000 });

    if (await idle.isVisible().catch(() => false)) {
      await expectRoutedRootActuallyVisible(page, 'kiosk-idle-root');
      return;
    }
    await expectRoutedRootActuallyVisible(page, 'kiosk-categories-root');
  });
});
