// FoodKing — W-B heal CAISSE-REMISE-01 — validation runtime du fix applyDiscount
// (PosComponent.vue : dispatch('posCart/discountReason') → commit — l'action
// n'existe pas, seul le couple getter/mutation [M4-02] existe dans posCart.js).
// Cible : serveur privé :8767 servant le bundle REBUILT du worktree healé,
// DB foodking_e2e (clone jetable).
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8767 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   npx playwright test tests/e2e/zz-caisse-heal-remise-2026-06-10.spec.js

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const OUT = path.join(__dirname, '../../reports/test-e2e/validation-profonde-2026-06-10/caisse/heal-remise');
fs.mkdirSync(OUT, { recursive: true });

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';

function parseMoney(text) {
  const m = String(text || '').match(/(\d+(?:[.,]\d{1,2})?)/);
  return m ? parseFloat(m[1].replace(',', '.')) : null;
}

test('remise manuelle 10% + raison → total réduit, zéro erreur console', async ({ page }) => {
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => pageErrors.push(String(e.message).slice(0, 200)));

  // login UI
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.locator('#formEmail').fill(ADMIN_EMAIL);
  await page.locator('#formPassword').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1500);

  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  // dialog session éventuel
  const close = page.locator('[data-testid="cash-session-close"]');
  if (await close.isVisible({ timeout: 2_500 }).catch(() => false)) await close.click().catch(() => {});

  // ajout item simple (Tarte Daim) via wizard vanilla
  const tile = page.locator('.pos-v5-tile, .pos-item-tile').filter({ hasText: /tarte daim/i }).first();
  await expect(tile).toBeVisible({ timeout: 20_000 });
  await tile.click();
  const modal = page.locator('#item-variation-modal');
  await expect(modal).toBeVisible({ timeout: 10_000 });
  await page.waitForTimeout(1500); // bindEvents du wizard vanilla
  for (let i = 0; i < 3; i++) {
    await modal.locator('.wizard-btn-cart, button[data-action="add-to-cart"]').first().click({ timeout: 5_000 }).catch(() => {});
    await page.waitForTimeout(1300);
    if (!(await modal.isVisible({ timeout: 600 }).catch(() => false))) break;
  }
  const cartItems = page.locator('.pos-v5-cart-item');
  await expect(cartItems.first()).toBeVisible({ timeout: 10_000 });

  const before = parseMoney(await page.locator('[data-testid="pos-grand-total"]').innerText());
  await page.screenshot({ path: path.join(OUT, '1-avant-remise.jpg'), type: 'jpeg', quality: 70 });

  // remise 10% + raison
  await page.locator('[data-testid="pos-discount-input"]').fill('10');
  await page.waitForTimeout(500);
  await page.locator('[data-testid="pos-discount-reason"]').fill('Remise heal W-B');
  await page.waitForTimeout(500);
  const apply = page.locator('[data-testid="pos-discount-apply"]');
  await expect(apply).toBeEnabled({ timeout: 5_000 });
  await apply.click();
  await page.waitForTimeout(1200);

  const after = parseMoney(await page.locator('[data-testid="pos-grand-total"]').innerText());
  await page.screenshot({ path: path.join(OUT, '2-apres-remise.jpg'), type: 'jpeg', quality: 70 });

  const expected = Math.round(before * 0.9 * 100) / 100;
  console.log(`[HEAL-REMISE] before=${before} after=${after} expected=${expected}`);
  fs.writeFileSync(path.join(OUT, 'result.json'), JSON.stringify({
    before, after, expected,
    console_errors: consoleErrors, page_errors: pageErrors,
  }, null, 2));

  expect(after, 'total réduit de 10%').toBeCloseTo(expected, 2);
  const vuexErrors = consoleErrors.filter((t) => /unknown action type/i.test(t));
  expect(vuexErrors, 'plus d erreur vuex unknown action').toHaveLength(0);
  expect(pageErrors.filter((t) => /reading 'then'/.test(t)), 'plus de TypeError .then').toHaveLength(0);
});
