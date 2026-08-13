/**
 * GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 — real functional CRUD proof,
 * Users/RBAC category (companion to admin-settings-crud-functional.spec.js
 * which covers Settings). Drives a real create -> appears in table -> delete
 * -> gone cycle for a Waiter record via the live UI's sidebar-drawer form —
 * not just page-load reachability.
 *
 * Self-contained throwaway record, deleted by the test itself.
 */
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

test.describe.serial('Real functional CRUD — Waiter (Users/RBAC category)', () => {
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

  test('Waiter: create via sidebar drawer -> appears in table -> delete -> gone', async () => {
    const uniq = `E2EWaiter${Date.now() % 100000}`;

    await page.goto('/admin/waiters', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.click('[data-drawer="#sidebar"]');
    await page.waitForTimeout(500);

    await page.fill('#sidebar #name', uniq);
    await page.fill('#sidebar #email', `${uniq.toLowerCase()}@e2e-test.local`);
    await page.fill('#sidebar #phone', `06${String(Date.now()).slice(-8)}`);
    await page.fill('#sidebar #password', 'TestPassword123!');
    await page.fill('#sidebar #password_confirmation', 'TestPassword123!');
    const activeRadio = page.locator('#sidebar #active');
    if (await activeRadio.count()) await activeRadio.check().catch(() => {});
    const branchRadio = page.locator('#sidebar #all_branch');
    if (await branchRadio.count()) await branchRadio.check().catch(() => {});

    await page.click('#sidebar button[type="submit"]');
    await page.waitForTimeout(2000);

    await expect(page.locator('table.db-table')).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Waiter CREATE: "${uniq}" appears in table — REAL, not just a toast.`);

    const row = page.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(page.locator('table.db-table')).not.toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Waiter DELETE: row gone from table after confirm — REAL removal.`);
  });

  // Employee CRUD was attempted and dropped: EmployeeCreateComponent's
  // required role_id field is a <vue-select> custom component, not a plain
  // <select>. Two independent attempts (a Playwright script with explicit
  // waits, then a live manual browser session via accessibility-role
  // lookup) both failed to reliably open/select from it -- the page's
  // "Rôle" combobox also collides with an identically-labelled filter-form
  // field elsewhere on the same page, and the create-drawer's dropdown may
  // render via a teleport/portal outside its apparent DOM container. This
  // needs dedicated live-DOM investigation to resolve correctly, not
  // further blind selector guessing -- noted as a gap, not silently
  // dropped or faked with a weakened assertion.
});
