// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.4.2 / T-1.4.4 · porte visuelle]
//  1. Un livreur qui se connecte sur /login arrive sur SON écran /livreur (plus sur une liste admin interdite).
//  2. L'admin voit la gestion des livreurs (/admin/delivery-boys) et des employés (/admin/employees) sans clé brute.
//
//   E2E_COURIER_USER=… E2E_COURIER_PASS=… E2E_ADMIN_USER=… E2E_ADMIN_PASS=… CAPTURE_DIR=… \
//     PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-tickets-2026-11-10/acces-livreur-admin.spec.js --reporter=line --workers=1
const { test, expect } = require('@playwright/test');
const path = require('path');

const DIR = process.env.CAPTURE_DIR || '/tmp';
const COURIER = { email: process.env.E2E_COURIER_USER, pass: process.env.E2E_COURIER_PASS || '123456' };
const ADMIN = { email: process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', pass: process.env.E2E_ADMIN_PASS || '123456' };

test.use({ viewport: { width: 1280, height: 800 } });

async function seConnecter(page, email, pass) {
  await page.goto('/login');
  await page.locator('#formEmail').fill(email);
  await page.locator('#formPassword').fill(pass);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/auth/login'), { timeout: 30_000 }),
    page.locator('button[type="submit"]').first().click(),
  ]);
}

test('livreur : /login → /livreur', async ({ page }) => {
  test.skip(!COURIER.email, 'E2E_COURIER_USER requis');
  await seConnecter(page, COURIER.email, COURIER.pass);
  await page.waitForURL(/\/livreur(\?|#|$)/, { timeout: 30_000 });
  expect(new URL(page.url()).pathname).toBe('/livreur');
  await expect(page.locator('#login, #app').first()).toBeVisible();
  await page.screenshot({ path: path.join(DIR, 'acces-livreur-atterrissage.png') });
});

test('admin : gestion des livreurs et des employés', async ({ page }) => {
  await seConnecter(page, ADMIN.email, ADMIN.pass);
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });
  for (const [chemin, nom] of [['/admin/delivery-boys', 'livreurs'], ['/admin/employees', 'employes']]) {
    await page.goto(chemin);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('table, [role="table"], .db-table').first()).toBeVisible({ timeout: 30_000 });
    expect(await page.locator('text=/\\b(label|button|menu|message)\\.[a-z_]+/').count()).toBe(0);
    await page.screenshot({ path: path.join(DIR, `acces-admin-${nom}.png`) });
  }
});
