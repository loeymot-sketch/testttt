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

  test('Employee: create via sidebar drawer (incl. vue-select role) -> appears in table -> delete -> gone', async () => {
    // role_id is a vue3-select-component, not a plain <select> -- an id
    // attribute does NOT survive to a queryable element (confirmed by a
    // prior session's own live-DOM probe, documented in
    // historique-unified.spec.js: the real structure is
    // label[for=X] -> parent group -> .vue-select-header (click to open)
    // -> li.vue-dropdown-item[role="option"] (click to pick). A first
    // attempt in THIS spec guessed at #role_id directly and hung/failed
    // twice (scripted + a live manual browser session) before this proven
    // pattern was found and reused instead of guessing further.
    const uniq = `E2EEmployee${Date.now() % 100000}`;

    await page.goto('/admin/employees', { waitUntil: 'domcontentloaded' });
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

    const roleGroup = page.locator('#sidebar label[for="role_id"]').locator('xpath=..');
    await expect(roleGroup.locator('.vue-select')).toBeVisible({ timeout: 8000 });
    await roleGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    const roleOption = roleGroup.locator('li.vue-dropdown-item[role="option"]', { hasText: 'POS Operator' });
    await expect(roleOption.first()).toBeVisible({ timeout: 5000 });
    await roleOption.first().click();

    await page.click('#sidebar button[type="submit"]');
    await page.waitForTimeout(2000);

    await expect(page.locator('table.db-table')).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Employee CREATE: "${uniq}" appears in table — REAL, vue-select role assignment worked.`);

    const row = page.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(page.locator('table.db-table')).not.toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Employee DELETE: row gone from table after confirm — REAL removal.`);
  });

  test('Customer: create via sidebar drawer -> appears in table -> delete -> gone', async () => {
    // Customer is V1-hidden from the sidebar nav (v1-hidden-modules.js) but
    // code/routes remain intact by design -- reachable directly by URL,
    // same as every other V1-hidden page proven in the breadth sweep.
    const uniq = `E2ECustomer${Date.now() % 100000}`;

    await page.goto('/admin/customers', { waitUntil: 'domcontentloaded' });
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

    await page.click('#sidebar button[type="submit"]');
    await page.waitForTimeout(2000);

    await expect(page.locator('table.db-table')).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Customer CREATE: "${uniq}" appears in table — REAL, not just a toast.`);

    const row = page.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(page.locator('table.db-table')).not.toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Customer DELETE: row gone from table after confirm — REAL removal.`);
  });

  test('Delivery Boy: create via sidebar drawer -> appears in table -> delete -> gone', async () => {
    const uniq = `E2EDeliveryBoy${Date.now() % 100000}`;

    await page.goto('/admin/delivery-boys', { waitUntil: 'domcontentloaded' });
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
    console.log(`[CRUD-FUNCTIONAL] Delivery Boy CREATE: "${uniq}" appears in table — REAL, not just a toast.`);

    const row = page.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(page.locator('table.db-table')).not.toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Delivery Boy DELETE: row gone from table after confirm — REAL removal.`);
  });

  test('Chef: create via sidebar drawer -> appears in table -> delete -> gone', async () => {
    const uniq = `E2EChef${Date.now() % 100000}`;

    await page.goto('/admin/chefs', { waitUntil: 'domcontentloaded' });
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
    console.log(`[CRUD-FUNCTIONAL] Chef CREATE: "${uniq}" appears in table — REAL, not just a toast.`);

    const row = page.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(page.locator('table.db-table')).not.toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Chef DELETE: row gone from table after confirm — REAL removal.`);
  });
});
