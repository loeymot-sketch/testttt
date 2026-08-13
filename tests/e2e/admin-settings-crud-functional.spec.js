/**
 * GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13 — real functional CRUD proof.
 *
 * Reachability sweeps (DASH-T10 + admin-full-breadth-sweep) prove pages load.
 * They do NOT prove "the interactions and modifications work" — the owner's
 * explicit distinction ("juste la page ça s'ouvre, c'est pas mon but"). This
 * spec performs REAL create -> edit -> delete cycles against two Settings
 * screens (Currency, Tax) via the live UI, asserting the row actually
 * appears/updates/disappears in the table after each step — not just that
 * a success toast fired.
 *
 * Self-contained: creates its own throwaway records and deletes them.
 * READ-ONLY on everything else. No frozen-zone touch.
 */
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');

async function openCreateModal(page) {
  await page.click('[data-modal="#modal"]');
  await page.waitForSelector('#modal.show, #modal[style*="display: block"], #modal', { state: 'visible', timeout: 10_000 }).catch(() => {});
  await page.waitForTimeout(400);
}

async function submitModal(page) {
  await page.click('#modal button[type="submit"], #modal form button[type="submit"]');
  await page.waitForTimeout(1500);
}

async function confirmDelete(page) {
  // SweetAlert2-style "Are you sure?" confirmation used across this codebase's destroy() flows.
  const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
  await expect(yesBtn).toBeVisible({ timeout: 10_000 });
  await yesBtn.click();
  await page.waitForTimeout(1200);
}

test.describe.serial('Real functional CRUD — Currency and Tax settings (not just reachability)', () => {
  test.setTimeout(180_000);
  let page;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (page) await page.context().close().catch(() => {});
  });

  test('Currency: create -> appears in table -> edit -> update reflected -> delete -> gone', async () => {
    const uniq = `E2E${Date.now() % 100000}`;
    const code = uniq.slice(-3).toUpperCase();

    await page.goto('/admin/settings/currencies', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    // CREATE
    await openCreateModal(page);
    await page.fill('#name', `Test Currency ${uniq}`);
    await page.fill('#symbol', 'T$');
    await page.fill('#code', code);
    await page.fill('#exchange_rate', '1.5');
    await submitModal(page);

    await expect(page.locator('table.db-table')).toContainText(`Test Currency ${uniq}`, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Currency CREATE: "Test Currency ${uniq}" appears in table — REAL, not just a toast.`);

    // EDIT — open edit modal for the row we just created, change the name, verify the change lands.
    const row = page.locator('tr', { hasText: `Test Currency ${uniq}` });
    await row.locator('button, a').filter({ has: page.locator('i, svg') }).first().click().catch(async () => {
      // fallback: click any element with an edit icon inside the row
      await row.locator('[class*="edit" i]').first().click();
    });
    await page.waitForTimeout(600);
    const nameInput = page.locator('#modal #name, #modal input#name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`Test Currency ${uniq} EDITED`);
    await submitModal(page);

    await expect(page.locator('table.db-table')).toContainText(`Test Currency ${uniq} EDITED`, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Currency EDIT: rename reflected in table — REAL persistence, not a stale UI echo.`);

    // DELETE
    const editedRow = page.locator('tr', { hasText: `Test Currency ${uniq} EDITED` });
    await editedRow.locator('[class*="delete" i], [class*="trash" i]').first().click();
    await confirmDelete(page);

    await expect(page.locator('table.db-table')).not.toContainText(`Test Currency ${uniq} EDITED`, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Currency DELETE: row gone from table after confirm — REAL removal.`);
  });

  test('Tax: create -> appears in table -> delete -> gone', async () => {
    const uniq = `E2ETax${Date.now() % 100000}`;

    await page.goto('/admin/settings/taxes', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await openCreateModal(page);
    await page.fill('#name', uniq);
    await page.fill('#code', uniq.slice(-6).toUpperCase());
    await page.fill('#tax_rate', '5');
    const activeRadio = page.locator('#modal #active');
    if (await activeRadio.count()) await activeRadio.check().catch(() => {});
    await submitModal(page);

    await expect(page.locator('table.db-table')).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Tax CREATE: "${uniq}" appears in table — REAL.`);

    const row = page.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i], [class*="trash" i]').first().click();
    await confirmDelete(page);

    await expect(page.locator('table.db-table')).not.toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Tax DELETE: row gone after confirm — REAL removal.`);
  });

  test('Role: create -> appears in list -> edit -> delete -> gone', async () => {
    // Role & Permissions renders as <li> rows inside #role (RoleListComponent.vue),
    // NOT a <table> like Currency/Tax/Purchasing -- a genuinely different list
    // pattern in this codebase, confirmed by inspecting the source directly
    // rather than assuming the table.db-table selector would carry over.
    const uniq = `E2ERole${Date.now() % 100000}`;
    const list = page.locator('#role');

    await page.goto('/admin/settings/role', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await openCreateModal(page);
    await page.fill('#name', uniq);
    await submitModal(page);

    // The role list paginates at 10/page and a freshly-created role sorts to
    // the end -- go to the last page before asserting, rather than assuming
    // page 1 (discovered live: a first attempt at this test failed here
    // because the new role landed on page 2 of a 12-role list).
    const nextBtn = page.getByRole('button', { name: /^next$/i }).or(page.getByText('Next', { exact: true }));
    if (await nextBtn.count()) {
      await nextBtn.last().click().catch(() => {});
      await page.waitForTimeout(800);
    }

    await expect(list).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Role CREATE: "${uniq}" appears in list — REAL.`);

    const row = list.locator('li', { hasText: uniq });
    // .modal-btn is shared by BOTH the "Autorisations" (permissions) link
    // and the real edit button (SmModalEditComponent) -- discovered live via
    // source inspection after a class-based selector clicked the wrong one.
    // The visible French label ("Modifier") is the unambiguous target.
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const nameInput = page.locator('#modal #name, #modal input#name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await submitModal(page);

    await expect(list).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Role EDIT: rename reflected in list — REAL persistence.');

    const editedRow = list.locator('li', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);

    await expect(list).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Role DELETE: row gone after confirm — REAL removal.');
  });

  test('Item Category: create -> appears in table -> edit -> delete -> gone', async () => {
    // Uses its own modal id (#categoryModal, not the generic #modal) --
    // SmModalCreateComponent's data-modal attribute is actually IGNORED by
    // its click handler (appService.modalShow() takes no argument and
    // defaults to the '.modal' CLASS selector, confirmed by reading
    // appService.js directly) so the button still opens the right modal,
    // but the submit-button/field selectors below must scope to
    // #categoryModal specifically since a generic #modal id doesn't exist
    // on this page. This page also ships clean data-testid attributes
    // (admin-category-row-{id}, -edit-{id}, -delete-{id}) -- used here in
    // preference to text/class matching after earlier pages on this page
    // taught the lesson that shared CSS classes are not reliable targets.
    const uniq = `E2ECategory${Date.now() % 100000}`;

    await page.goto('/admin/settings/item-categories', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.getByRole('button', { name: /ajouter/i }).click();
    await page.waitForTimeout(400);
    await page.fill('#categoryModal #name', uniq);
    await page.click('#categoryModal button[type="submit"]');
    await page.waitForTimeout(1500);

    const table = page.locator('table.db-table');
    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Item Category CREATE: "${uniq}" appears in table — REAL.`);

    const row = table.locator('tr', { hasText: uniq });
    const rowId = await row.getAttribute('data-testid').then((v) => (v || '').replace('admin-category-row-', ''));
    await page.click(`[data-testid="admin-category-edit-${rowId}"] button, [data-testid="admin-category-edit-${rowId}"]`);
    await page.waitForTimeout(600);
    const nameInput = page.locator('#categoryModal #name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await page.click('#categoryModal button[type="submit"]');
    await page.waitForTimeout(1500);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Item Category EDIT: rename reflected in table — REAL persistence.');

    await page.click(`[data-testid="admin-category-delete-${rowId}"] button, [data-testid="admin-category-delete-${rowId}"]`);
    await confirmDelete(page);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Item Category DELETE: row gone after confirm — REAL removal.');
  });
});
