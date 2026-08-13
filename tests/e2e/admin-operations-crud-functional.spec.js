/**
 * GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 — real functional CRUD proof,
 * operational/business-feature category (companion to
 * admin-settings-crud-functional.spec.js and admin-users-crud-functional.spec.js
 * which cover Settings and Users/RBAC). Drives real create -> appears in
 * table -> edit -> delete cycles against operational screens outside those
 * two categories.
 *
 * Self-contained: creates its own throwaway records and deletes them.
 * READ-ONLY on everything else. No frozen-zone touch.
 */
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

test.describe.serial('Real functional CRUD — Dining Tables (operational category)', () => {
  test.setTimeout(120_000);
  let page;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (page) await page.context().close().catch(() => {});
  });

  test('Dining Table: create via sidebar drawer -> appears in table -> edit -> update reflected -> delete -> gone', async () => {
    const uniq = `E2ETable${Date.now() % 100000}`;

    await page.goto('/admin/dining-tables', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.click('[data-drawer="#sidebar"]');
    await page.waitForTimeout(500);

    await page.fill('#sidebar #name', uniq);
    await page.fill('#sidebar #size', '4');
    const activeRadio = page.locator('#sidebar #active');
    if (await activeRadio.count()) await activeRadio.check().catch(() => {});

    await page.click('#sidebar button[type="submit"]');
    await page.waitForTimeout(2000);

    const table = page.locator('table.db-table');
    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Dining Table CREATE: "${uniq}" appears in table — REAL, not just a toast.`);

    // EDIT
    const row = table.locator('tr', { hasText: uniq });
    await row.locator('[class*="edit" i]').first().click();
    await page.waitForTimeout(600);
    const nameInput = page.locator('#sidebar #name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await page.click('#sidebar button[type="submit"]');
    await page.waitForTimeout(2000);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Dining Table EDIT: rename reflected in table — REAL persistence.');

    // DELETE
    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Dining Table DELETE: row gone from table after confirm — REAL removal.');
  });
});
