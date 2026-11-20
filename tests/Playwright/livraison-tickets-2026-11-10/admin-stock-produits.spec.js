// [GOAL LIVRAISON-TICKETS 2026-11-10 · W3 · porte visuelle] L'admin voit le tableau de bord (widget stock bas), la gestion
// « Produits & Stock » (/admin/stock/rupture) et le catalogue (/admin/items) sans clé brute ni erreur de console.
//
//   E2E_ADMIN_USER=… E2E_ADMIN_PASS=… CAPTURE_DIR=… PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-tickets-2026-11-10/admin-stock-produits.spec.js --reporter=line --workers=1
const { test, expect } = require('@playwright/test');
const path = require('path');

const DIR = process.env.CAPTURE_DIR || '/tmp';
const ADMIN = { email: process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', pass: process.env.E2E_ADMIN_PASS || '123456' };

test.use({ viewport: { width: 1440, height: 900 } });

test('admin : tableau de bord, stock, catalogue', async ({ page }) => {
  const erreurs = [];
  page.on('pageerror', (e) => erreurs.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|net::ERR_|pusher|soketi|websocket/i.test(m.text())) erreurs.push(m.text()); });

  await page.goto('/login');
  await page.locator('#formEmail').fill(ADMIN.email);
  await page.locator('#formPassword').fill(ADMIN.pass);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/auth/login'), { timeout: 30_000 }),
    page.locator('button[type="submit"]').first().click(),
  ]);
  await page.waitForURL(/\/admin\//, { timeout: 30_000 });

  for (const [chemin, nom, attendu] of [
    ['/admin/dashboard', 'tableau-de-bord', /Tableau de bord/i],
    ['/admin/stock/rupture', 'stock', /Stock|Rupture/i],
    ['/admin/items', 'catalogue', /Produits|Catalogue|Articles/i],
  ]) {
    await page.goto(chemin);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(attendu, { timeout: 30_000 });
    expect(await page.locator('text=/\\b(label|button|menu|message)\\.[a-z_]+/').count(), `${nom} : clé brute`).toBe(0);
    await page.screenshot({ path: path.join(DIR, `admin-${nom}.png`), fullPage: false });
  }
  expect(erreurs, 'erreurs de console').toEqual([]);
});
