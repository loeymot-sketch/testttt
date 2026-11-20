// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-2.2.2 · porte visuelle] Fiche produit : un enregistrement refusé (nom vidé → 422)
// laisse un message écrit près du bouton « Enregistrer » — plus jamais d'échec muet (mémoire formulaire-admin-echec-muet).
//
//   CAPTURE_DIR=… PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-tickets-2026-11-10/fiche-produit-erreur-visible.spec.js --reporter=line --workers=1
const { test, expect } = require('@playwright/test');
const path = require('path');

const DIR = process.env.CAPTURE_DIR || '/tmp';
const ADMIN = { email: process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', pass: process.env.E2E_ADMIN_PASS || '123456' };

test.use({ viewport: { width: 1440, height: 900 } });

test('fiche produit : refus visible près du bouton', async ({ page }) => {
  await page.goto('/login');
  await page.locator('#formEmail').fill(ADMIN.email);
  await page.locator('#formPassword').fill(ADMIN.pass);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/auth/login'), { timeout: 30_000 }),
    page.locator('button[type="submit"]').first().click(),
  ]);
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });
  await page.goto('/admin/items');
  await page.waitForLoadState('networkidle');

  // Premier bouton « modifier » du tableau (crayon vert) : ouvre le tiroir d'édition.
  const editer = page.locator('table tbody tr').first().locator('button.db-table-action.edit').first();
  await editer.click();
  const nom = page.locator('[data-testid="admin-item-form-name"]');
  await expect(nom).toBeVisible({ timeout: 30_000 });
  await nom.fill('');

  const bouton = page.locator('[data-testid="admin-item-form-save"]');
  const refus = page.waitForResponse((r) => /\/admin\/item(\/\d+)?$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 });
  await bouton.click();
  expect((await refus).status()).toBe(422);

  const message = page.locator('[data-testid="item-save-error"]');
  await expect(message).toBeVisible({ timeout: 10_000 });
  await expect(message).toContainText('pas enregistré');
  await message.scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(DIR, 'fiche-produit-erreur-visible.png') });
});
