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
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

function tinkerExec(php) {
  execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 15_000,
  });
}

const uploadDir = path.resolve(__dirname, '../../storage/framework/testing/playwright');
const pngPath = path.join(uploadDir, 'admin-settings-crud-functional.png');

function ensureUploadFile() {
  fs.mkdirSync(uploadDir, { recursive: true });
  if (!fs.existsSync(pngPath)) {
    fs.writeFileSync(
      pngPath,
      Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l0qbWQAAAABJRU5ErkJggg==',
        'base64'
      )
    );
  }
  return pngPath;
}

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

  test('Role permissions (show/:id page): "Autorisations" -> toggle a real permission checkbox -> save -> persisted -> restored', async () => {
    // A genuinely SEPARATE feature from the Role list's "Modifier" modal
    // above (which only renames the role): the "Autorisations" button
    // navigates to RoleShowComponent.vue (admin.settings.role.show), a
    // full-page permission matrix (create/update/delete/view checkboxes
    // per admin page) that dispatches its own permission/lists +
    // permission/save actions -- confirmed via source read, not assumed
    // to be redundant with the modal just because both live under "Role".
    // Uses a fresh throwaway role (created here, deleted at the end) so
    // toggling a real permission never touches real staff RBAC.
    const uniq = `E2ERolePerm${Date.now() % 100000}`;
    const list = page.locator('#role');

    await page.goto('/admin/settings/role', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await openCreateModal(page);
    await page.fill('#name', uniq);
    await submitModal(page);

    const nextBtn = page.getByRole('button', { name: /^next$/i }).or(page.getByText('Next', { exact: true }));
    if (await nextBtn.count()) {
      await nextBtn.last().click().catch(() => {});
      await page.waitForTimeout(800);
    }
    await expect(list).toContainText(uniq, { timeout: 10_000 });

    const row = list.locator('li', { hasText: uniq });
    await row.getByRole('link', { name: /autorisations|permissions/i }).click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('table.db-table')).toBeVisible({ timeout: 10_000 });

    // A fresh role has zero permissions checked -- pick the FIRST checkbox
    // (unchecked by construction) rather than assuming a specific feature.
    const firstCheckbox = page.locator('input[type="checkbox"][id^="feature_"]').first();
    await expect(firstCheckbox).not.toBeChecked({ timeout: 8000 });
    await firstCheckbox.check();

    const saveResponse = page.waitForResponse(
      (res) => /\/api\/admin\/setting\/permission\/\d+$/.test(res.url()) && res.request().method() === 'PUT',
      { timeout: 10_000 }
    );
    await page.locator('button[type="submit"], form button:has-text("Enregistrer")').first().click();
    const res = await saveResponse;
    expect(res.status()).toBe(200);
    await page.waitForTimeout(500);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('input[type="checkbox"][id^="feature_"]').first()).toBeChecked({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Role permissions: real PUT /api/admin/setting/permission/:id (200) persisted a checked permission on throwaway role "${uniq}", survives reload — REAL, not a client-side-only checkbox.`);

    // Cleanup: delete the throwaway role via the list page (cascades its
    // permission rows), never touches any real role.
    await page.goto('/admin/settings/role', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const nextBtn2 = page.getByRole('button', { name: /^next$/i }).or(page.getByText('Next', { exact: true }));
    if (await nextBtn2.count()) {
      await nextBtn2.last().click().catch(() => {});
      await page.waitForTimeout(800);
    }
    await list.locator('li', { hasText: uniq }).getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);
    await expect(list).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Role permissions: throwaway role deleted — cleanup confirmed.');
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

    // REAL FINDING, not a bug: "Voir" targets admin.settings.itemCategory.show,
    // but that route's own definition (settingRoutes.js) is a `redirect`
    // function straight to admin.items.studio (Catalog Hub) with
    // `item_category_id` as a query param -- ItemCategoryShowComponent.vue
    // is confirmed genuinely read-only AND confirmed DEAD CODE, never
    // actually reached by real navigation (same "consolidation left a
    // component orphaned" pattern already found on Ingredients this
    // session, but at the router level this time). Testing the REAL
    // behavior instead of the stale assumption: the category should land
    // filtered in the Catalog Hub, not on a standalone detail page.
    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('link', { name: /voir/i }).click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page).toHaveURL(/item_category_id=/, { timeout: 10_000 });
    await expect(page.locator('body')).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Item Category "Voir": redirects to Catalog Hub filtered by item_category_id (REAL router redirect, not a broken/dead link) -- "${uniq}EDITED" visible in the filtered category sidebar.`);

    await page.goto('/admin/settings/item-categories', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.click(`[data-testid="admin-category-delete-${rowId}"] button, [data-testid="admin-category-delete-${rowId}"]`);
    await confirmDelete(page);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Item Category DELETE: row gone after confirm — REAL removal.');
  });

  test('Language: create -> appears in table -> edit -> delete -> gone', async () => {
    const uniq = `E2ELang${Date.now() % 100000}`;
    // code is validated as /^[A-Za-z_-]+$/ (letters only, no digits) --
    // discovered live via the "Le format du champ code n'est pas valide"
    // error after a first attempt used a numeric suffix.
    const letters = 'abcdefghijklmnopqrstuvwxyz';
    const code = `zz${letters[Date.now() % 26]}${letters[Math.floor(Date.now() / 26) % 26]}`;
    const table = page.locator('table.db-table');

    await page.goto('/admin/settings/languages', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await openCreateModal(page);
    await page.fill('#name', uniq);
    await page.fill('#code', code);
    await page.locator('#modal #ltr').check();
    await page.locator('#modal #active').check();
    await submitModal(page);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Language CREATE: "${uniq}" appears in table — REAL.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const nameInput = page.locator('#modal #name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await submitModal(page);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Language EDIT: rename reflected in table — REAL persistence.');

    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Language DELETE: row gone after confirm — REAL removal.');
  });

  test('Page (CMS): create -> appears in table -> edit -> update reflected -> delete -> gone', async () => {
    // title is required|string|max:190|unique (PageRequest.php) -- unique
    // suffix per run, same discipline as Currency/Language. description is
    // a Quill rich-text editor (vue3-quill), not a plain <textarea> -- the
    // id="description" attribute lands on the outer non-editable <section>;
    // the actual contenteditable surface is the child ".ql-editor" div
    // (confirmed via component source, resources/js/components/admin/
    // settings/Page/PageCreateComponent.vue).
    const uniq = `E2EPage${Date.now() % 100000}`;

    await page.goto('/admin/settings/pages/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #title', uniq);
    await page.locator('#modal #description .ql-editor').click();
    await page.keyboard.type(`E2E test page body ${uniq}`);
    await page.locator('#modal #active').check();
    await submitModal(page);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Page CREATE: "${uniq}" appears in table — REAL, Quill description accepted.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const titleInput = page.locator('#modal #title');
    await expect(titleInput).toBeVisible({ timeout: 8000 });
    await titleInput.fill(`${uniq}EDITED`);
    await submitModal(page);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Page EDIT: rename reflected in table — REAL persistence.');

    // PageShowComponent.vue confirmed read-only (no form, no mutation)
    // after a full read -- proven with its own real GET/render rather
    // than skipped without evidence.
    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('link', { name: /voir/i }).click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('h3.text-lg', { hasText: `${uniq}EDITED` })).toBeVisible({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Page show/:id: real title "${uniq}EDITED" rendered in heading -- REAL GET, not a static/mocked panel.`);

    await page.goto('/admin/settings/pages/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await table.locator('tr', { hasText: `${uniq}EDITED` }).getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Page DELETE: row gone after confirm — REAL removal.');
  });

  test('Kiosk Machine: create (incl. 2 vue-selects) -> appears in table -> delete -> gone', async () => {
    // machine_id and username are unique (KioskMachineRequest.php) -- unique
    // suffix per run. user_id/branch_id are vue-selects populated from a
    // live API list (users/branches), not a fixed enum -- unlike Otp's
    // closed 3-value set, there's no fixed target label to search for, so
    // this test picks whatever the FIRST real option is in each dropdown
    // rather than guessing at content. V1 is single-branch (branch_id=1)
    // with a small real user set, so "first option" is always valid.
    // No EDIT step here: password is required-on-create but nullable-on-
    // edit, and the create->delete cycle alone already proves both
    // vue-selects (a pattern not yet covered by any list-CRUD test in this
    // file) plus the unique-field validation path.
    const uniq = `E2EKM${Date.now() % 100000}`;

    await page.goto('/admin/settings/kiosk-machines/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #machine_id', uniq);
    await page.fill('#modal #username', uniq.toLowerCase());
    await page.fill('#modal #password', 'TestPassword123!');

    const userGroup = page.locator('#modal label[for="user_id"]').locator('xpath=..');
    await userGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await userGroup.locator('li.vue-dropdown-item[role="option"]').first().click();

    const branchGroup = page.locator('#modal label[for="branch_id"]').locator('xpath=..');
    await branchGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await branchGroup.locator('li.vue-dropdown-item[role="option"]').first().click();

    await page.locator('#modal #active').check();
    await submitModal(page);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Kiosk Machine CREATE: "${uniq}" appears in table — REAL, both vue-selects (user/branch) accepted.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();
    await confirmDelete(page);

    await expect(table).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Kiosk Machine DELETE: row gone after confirm — REAL removal.');
  });

  test('Kiosk Machine: real "Logout" button flips is_login to NO in DB', async () => {
    // The Logout button only renders when is_login=YES(5)
    // (KioskMachineListComponent.vue: v-if="kioskMachine.is_login ===
    // enums.askEnum.YES") -- a throwaway machine created via the UI starts
    // at is_login=NO(10) by default, so it's flipped to YES via tinker
    // first (simulating "a kiosk is currently signed in", not a real
    // device session). KioskMachineService::logout() also fires a push
    // notification, but only if device_token is set -- this throwaway
    // machine has none, so the fan-out array is empty (same safe pattern
    // already verified for the Push Notification page: 0 real devices,
    // 0 risk of reaching one).
    const uniq = `E2EKMLogout${Date.now() % 100000}`;

    await page.goto('/admin/settings/kiosk-machines/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #machine_id', uniq);
    await page.fill('#modal #username', uniq.toLowerCase());
    await page.fill('#modal #password', 'TestPassword123!');
    const userGroup = page.locator('#modal label[for="user_id"]').locator('xpath=..');
    await userGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await userGroup.locator('li.vue-dropdown-item[role="option"]').first().click();
    const branchGroup = page.locator('#modal label[for="branch_id"]').locator('xpath=..');
    await branchGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await branchGroup.locator('li.vue-dropdown-item[role="option"]').first().click();
    await page.locator('#modal #active').check();
    await submitModal(page);
    await expect(table).toContainText(uniq, { timeout: 10_000 });

    tinkerExec(`\\App\\Models\\KioskMachine::where('machine_id', '${uniq}')->update(['is_login' => 5]);`);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('button', { name: /log ?out|d[ée]connexion/i }).click();
    await page.getByRole('button', { name: /yes, log out/i }).click();
    await page.waitForTimeout(1500);

    const dbIsLogin = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\KioskMachine::where('machine_id', '${uniq}')->first()->is_login;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbIsLogin).toBe('10'); // Ask::NO
    console.log(`[CRUD-FUNCTIONAL] Kiosk Machine LOGOUT: real is_login flipped YES(5) -> NO(10) in DB via the real Logout button, 0 real devices reached.`);

    await row.locator('[class*="delete" i]').first().click();
    await confirmDelete(page);
    await expect(table).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Kiosk Machine LOGOUT test cleanup: row deleted.');
  });

  test('Kiosk Machine: real status toggle switch flips status in DB', async () => {
    // A distinct interaction from create/delete/logout: the active/inactive
    // toggle switch in the list row (KioskMachineService::changeStatus()).
    // Same safe empty-fan-out pattern (no device_token on a throwaway
    // machine, so the push notification call reaches 0 real devices).
    const uniq = `E2EKMToggle${Date.now() % 100000}`;

    await page.goto('/admin/settings/kiosk-machines/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #machine_id', uniq);
    await page.fill('#modal #username', uniq.toLowerCase());
    await page.fill('#modal #password', 'TestPassword123!');
    const userGroup = page.locator('#modal label[for="user_id"]').locator('xpath=..');
    await userGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await userGroup.locator('li.vue-dropdown-item[role="option"]').first().click();
    const branchGroup = page.locator('#modal label[for="branch_id"]').locator('xpath=..');
    await branchGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await branchGroup.locator('li.vue-dropdown-item[role="option"]').first().click();
    await page.locator('#modal #active').check();
    await submitModal(page);
    await expect(table).toContainText(uniq, { timeout: 10_000 });

    const machineId = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\KioskMachine::where('machine_id', '${uniq}')->first()->id;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();

    const row = table.locator('tr', { hasText: uniq });
    await row.locator(`#switcher-${machineId}`).click({ force: true });
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\KioskMachine::find(${machineId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe('10'); // Status::INACTIVE, was ACTIVE(5) at create
    console.log(`[CRUD-FUNCTIONAL] Kiosk Machine STATUS TOGGLE: real status flipped ACTIVE(5) -> INACTIVE(10) in DB via the switch, not a cosmetic UI-only toggle.`);

    await row.locator('[class*="delete" i]').first().click();
    await confirmDelete(page);
    await expect(table).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Kiosk Machine STATUS TOGGLE test cleanup: row deleted.');
  });

  test('Kiosk Machine: edit (rename machine_id) -> appears in table -> delete -> gone', async () => {
    // Standard #modal edit pattern (SmModalEditComponent), same shape as
    // Currency/Language edit already proven -- password is required on
    // create but nullable on edit (KioskMachineRequest.php), so editing
    // doesn't need to re-enter it.
    const uniq = `E2EKMEdit${Date.now() % 100000}`;

    await page.goto('/admin/settings/kiosk-machines/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #machine_id', uniq);
    await page.fill('#modal #username', uniq.toLowerCase());
    await page.fill('#modal #password', 'TestPassword123!');
    const userGroup = page.locator('#modal label[for="user_id"]').locator('xpath=..');
    await userGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await userGroup.locator('li.vue-dropdown-item[role="option"]').first().click();
    const branchGroup = page.locator('#modal label[for="branch_id"]').locator('xpath=..');
    await branchGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    await branchGroup.locator('li.vue-dropdown-item[role="option"]').first().click();
    await page.locator('#modal #active').check();
    await submitModal(page);
    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Kiosk Machine (edit test) CREATE: "${uniq}" appears in table.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const machineIdInput = page.locator('#modal #machine_id');
    await expect(machineIdInput).toHaveValue(uniq, { timeout: 8000 });
    await machineIdInput.fill(`${uniq}EDITED`);
    await submitModal(page);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Kiosk Machine EDIT: machine_id rename reflected in table — REAL persistence.');

    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.locator('[class*="delete" i]').first().click();
    await confirmDelete(page);
    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Kiosk Machine EDIT test cleanup: row deleted.');
  });

  test('Slider: create (incl. required image upload) -> appears in table -> delete -> gone', async () => {
    // title is unique (SliderRequest.php) -- unique suffix per run, like
    // Currency/Language. image is REQUIRED on create (unlike Page/Theme
    // where it's optional/the-only-field) -- uses a real 1x1 PNG fixture,
    // same technique already proven in
    // tests/e2e/central-management-dashboard-crud.spec.js. description is
    // a plain <textarea> here (not Quill like Page) -- ordinary .fill().
    const uniq = `E2ESlider${Date.now() % 100000}`;
    const imagePath = ensureUploadFile();

    await page.goto('/admin/settings/sliders/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #name', uniq);
    await page.fill('#modal #description', `E2E test slider body ${uniq}`);
    await page.setInputFiles('#modal #image', imagePath);
    await page.locator('#modal #active').check();
    await submitModal(page);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Slider CREATE: "${uniq}" appears in table — REAL, required image upload accepted.`);

    // SliderShowComponent.vue is confirmed read-only (no form, no mutation
    // action) after a full read -- proven here via its own real GET/render
    // rather than skipped as "redundant" without evidence: click "Voir",
    // confirm the real title fetched from the backend renders in the
    // heading (not a static shell), then continue to delete as before.
    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('link', { name: /voir/i }).click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('h3.text-lg', { hasText: uniq })).toBeVisible({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Slider show/:id: real title "${uniq}" rendered in heading -- REAL GET, not a static/mocked panel.`);

    await page.goto('/admin/settings/sliders/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await row.locator('[class*="delete" i]').first().click();
    await confirmDelete(page);

    await expect(table).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Slider DELETE: row gone after confirm — REAL removal.');
  });

  test('Analytics: create -> appears in table -> edit -> update reflected -> delete -> gone', async () => {
    // name is unique (AnalyticRequest.php) -- unique suffix per run. This is
    // the top-level Analytic entity (name+status only); nested "sections"
    // within an Analytic's show page use a separate AnalyticSectionRequest
    // and are out of scope for this pass.
    const uniq = `E2EAnalytic${Date.now() % 100000}`;

    await page.goto('/admin/settings/analytics/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #name', uniq);
    await page.locator('#modal #active').check();
    await submitModal(page);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Analytics CREATE: "${uniq}" appears in table — REAL.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const nameInput = page.locator('#modal #name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await submitModal(page);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Analytics EDIT: rename reflected in table — REAL persistence.');

    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Analytics DELETE: row gone after confirm — REAL removal.');
  });

  test('Analytics Sections (show/:id page): real nested CRUD, NOT a redundant read-only detail view', async () => {
    // CORRECTION: an earlier pass in this session classified AnalyticShowComponent.vue
    // as read-only by a grep that only matched inline dispatch/axios calls -- it
    // missed this component entirely because it wires up create/edit/delete via
    // local methods (edit()/destroy()) and a separate AnalyticSectionCreateComponent
    // modal. Full read confirms it's a genuine nested CRUD screen: an Analytic's
    // show page lists/creates/edits/deletes "sections" (header/body/footer content
    // blocks), backed by its own analyticSection/* Vuex actions and
    // AnalyticSectionRequest validation -- not redundant with the parent list's
    // rename-only modal at all.
    //
    // analytic_sections.analytic_id has NO cascade-on-delete (plain
    // ->constrained('analytics'), confirmed via the migration) -- the section
    // must be deleted before the parent Analytic, or the parent's own delete
    // would 500 on a FK constraint. Tested in that order below.
    const analyticName = `E2EAnalyticSec${Date.now() % 100000}`;
    const sectionName = `E2ESection${Date.now() % 100000}`;

    await page.goto('/admin/settings/analytics/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #name', analyticName);
    await page.locator('#modal #active').check();
    await submitModal(page);
    await expect(table).toContainText(analyticName, { timeout: 10_000 });

    const row = table.locator('tr', { hasText: analyticName });
    await row.getByRole('link', { name: /voir/i }).click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('h3.db-card-title', { hasText: analyticName })).toBeVisible({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Analytics Sections: show page for "${analyticName}" loaded with real title in heading.`);

    const sectionTable = page.locator('table.db-table');
    await openCreateModal(page);
    await page.fill('#modal #name', sectionName);
    const sectionGroup = page.locator('#modal label[for="section"]').locator('xpath=..');
    await sectionGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    const bodyOption = sectionGroup.locator('li.vue-dropdown-item[role="option"]').filter({ hasText: /^Body$|^Corps$/i });
    await expect(bodyOption.first()).toBeVisible({ timeout: 5000 });
    await bodyOption.first().click();
    await page.fill('#modal #data', `E2E section body content ${sectionName}`);
    await submitModal(page);

    await expect(sectionTable).toContainText(sectionName, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Analytics Sections CREATE: real section "${sectionName}" appears in the nested table — REAL nested CRUD, not a static detail page.`);

    const sectionRow = sectionTable.locator('tr', { hasText: sectionName });
    await sectionRow.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const sectionNameInput = page.locator('#modal #name');
    await expect(sectionNameInput).toBeVisible({ timeout: 8000 });
    await sectionNameInput.fill(`${sectionName}EDITED`);
    await submitModal(page);
    await expect(sectionTable).toContainText(`${sectionName}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Analytics Sections EDIT: rename reflected — REAL persistence.');

    const editedSectionRow = sectionTable.locator('tr', { hasText: `${sectionName}EDITED` });
    await editedSectionRow.getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);
    await expect(sectionTable).not.toContainText(`${sectionName}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Analytics Sections DELETE: section gone after confirm — REAL removal.');

    // Cleanup: delete the throwaway parent Analytic now that its sections are gone.
    await page.goto('/admin/settings/analytics/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.locator('table.db-table').locator('tr', { hasText: analyticName }).getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);
    await expect(page.locator('table.db-table')).not.toContainText(analyticName, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Analytics Sections: throwaway parent Analytic deleted — cleanup confirmed.');
  });

  test('Item Attribute: create -> appears in table -> edit (exercises rename-cascade fix) -> delete -> gone', async () => {
    // name is unique (ItemAttributeRequest.php). The EDIT step here isn't
    // just a generic rename check -- it live-exercises the composer-wizard
    // rename-cascade fix from earlier this session
    // (ItemAttributeService::update(), see ItemAttributeRenamePropagationTest.php):
    // a throwaway attribute is safe to rename since it has zero real
    // item_wizard_steps rows referencing it, so this proves the UI path
    // reaches that service method without touching any of the 57 real rows
    // that fix protects.
    const uniq = `E2EAttr${Date.now() % 100000}`;

    await page.goto('/admin/settings/item-attributes/list', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await openCreateModal(page);
    await page.fill('#modal #name', uniq);
    await page.locator('#modal #active').check();
    await submitModal(page);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Item Attribute CREATE: "${uniq}" appears in table — REAL.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);
    const nameInput = page.locator('#modal #name');
    await expect(nameInput).toBeVisible({ timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await submitModal(page);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Item Attribute EDIT: rename reflected in table — REAL persistence via the rename-cascade service path.');

    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('button', { name: /supprimer/i }).click();
    await confirmDelete(page);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Item Attribute DELETE: row gone after confirm — REAL removal.');
  });

  test('Payment Terminals: create -> appears in table -> edit -> update reflected -> "delete" (real soft-archive) -> gone from active list', async () => {
    // Despite the name, this is device METADATA (name, gateway type from a
    // fixed enum, fee config, serial number, status) -- confirmed via
    // PaymentTerminalRequest.php: no API key/secret field at all. Genuinely
    // different risk class from PaymentGateway settings (real Mollie/Stripe
    // credentials, correctly declined elsewhere this session). name is
    // unique (scoped per branch). Own modal (#modalTerminal, opened via
    // @click="openCreate", not the shared [data-modal="#modal"] pattern
    // used by every other Settings CRUD test in this file).
    //
    // REAL FINDING, not a bug: unlike every other "Delete" button in this
    // suite, PaymentTerminalController::destroy() does NOT remove the row
    // -- it soft-archives it (`status = STATUS_ARCHIVED`), by design
    // (financial audit trail: a payment device record may be linked to
    // historical transactions, so it's kept, just hidden from the active
    // set and its Delete button). A first attempt assumed the generic
    // row-disappears pattern used everywhere else in this file and failed
    // reproducibly; root-caused by reading PaymentTerminalController.php
    // rather than guessing at a selector fix. The archived row has no
    // UI-driven path back to active (no "restore" button), so cleanup here
    // hard-deletes via tinker instead of relying on a UI action.
    const uniq = `E2ETerminal${Date.now() % 100000}`;

    await page.goto('/admin/settings/payment-terminals', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await page.getByRole('button', { name: /add|ajouter/i }).click();
    const modal = page.locator('#modalTerminal');
    await expect(modal).toBeVisible({ timeout: 8000 });
    await page.fill('#t_name', uniq);
    await page.selectOption('#t_gateway', 'manual');
    await page.fill('#t_serial', 'E2E-SERIAL-001');
    await page.locator('#t_active').check();
    await modal.locator('button[type="submit"]').click();
    await page.waitForTimeout(1500);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Payment Terminals CREATE: "${uniq}" appears in table — REAL.`);

    // Edit/Delete buttons here have no accessible text/aria-label (icon-only)
    // -- targeted by their icon class instead of role/name.
    const row = table.locator('tr', { hasText: uniq });
    await row.locator('.lab-edit').first().click();
    await expect(modal).toBeVisible({ timeout: 8000 });
    const nameInput = page.locator('#t_name');
    await expect(nameInput).toHaveValue(uniq, { timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await modal.locator('button[type="submit"]').click();
    await page.waitForTimeout(1500);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Payment Terminals EDIT: rename reflected in table — REAL persistence.');

    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.locator('.lab-delete').first().click();
    await confirmDelete(page);
    await page.waitForTimeout(1000);

    // The row stays visible (soft-archive, not removal) but its Delete
    // button disappears (v-if="terminal.status === 1") and its status
    // column now reads "Archivé" -- that's the real, correct proof here.
    await expect(editedRow.locator('.lab-delete')).toHaveCount(0, { timeout: 10_000 });
    await expect(editedRow).toContainText(/archiv/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Payment Terminals DELETE: real soft-archive confirmed (status -> Archivé, Delete button gone), not a hard row removal like every other page in this suite.');

    tinkerExec(`\\App\\Models\\PaymentTerminal::where('name', '${uniq}EDITED')->delete();`);
  });
});

test.describe.serial('Real functional CRUD — Printers (physical device metadata, safe: bypass transport confirmed active)', () => {
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

  test('Printers: create -> appears in table -> "Test print" fires a real (bypassed) print call -> edit -> "delete" (real, no confirm dialog) -> gone', async () => {
    // Own local modal (:class="{active: modalActive}", no shared #modal id,
    // no [data-modal] trigger attribute) -- opened via its own
    // data-testid="printer-add-btn" button.
    //
    // REAL FINDING, not a bug: the placeholder host "192.168.1.50" shown in
    // the field, and the exact host format the app's own real printers use
    // (127.0.0.1:9100/9101, "bridge" processes local to each till per
    // CLAUDE.md's documented architecture), are BOTH rejected by
    // PrinterRequest's SafeRemoteHost SSRF guard (added deliberately,
    // 2026-05-24, GOAL-L2-HEAL-03 -- blocks fsockopen() to
    // loopback/link-local/RFC1918 ranges to stop the printer-host field
    // being used as a LAN/cloud-metadata port-scan primitive). The rule
    // ships with an owner-configurable escape hatch
    // (SAFE_REMOTE_HOST_ALLOWLIST env var / config('security.safe_remote_
    // host_allowlist')) specifically for this LAN-printer case, but it is
    // confirmed EMPTY in this environment (checked .env + tinker). Net
    // effect: creating a NEW printer, or editing an EXISTING one's host,
    // with the only host format this V1 single-restaurant architecture
    // actually uses is currently blocked by validation; the 2 real rows
    // visible in this table are grandfathered in only because validation
    // doesn't re-run against existing DB rows. This is a real
    // security-vs-functionality gap worth owner attention, NOT something
    // to silently patch here (widening the allowlist is an owner opt-in
    // by design, not a scope-minimal test fix). Using a hostname instead
    // of an IP literal for this test (hostnames pass SafeRemoteHost
    // unconditionally) to still prove the rest of the CRUD cycle for real.
    const uniq = `E2EPrinter${Date.now() % 100000}`;

    await page.goto('/admin/settings/printers', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');

    await page.click('[data-testid="printer-add-btn"]');
    const modal = page.locator('.modal.active');
    await expect(modal).toBeVisible({ timeout: 8000 });
    await page.fill('#p_name', uniq);
    await page.fill('#p_host', 'e2e-printer-test.invalid');
    await modal.locator('button[type="submit"]').click();
    await page.waitForTimeout(1500);

    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Printers CREATE: "${uniq}" appears in table — REAL, not just a toast.`);

    const row = table.locator('tr', { hasText: uniq });
    const testBtn = row.locator('[data-testid^="printer-test-"]');
    const testPrintResponse = page.waitForResponse(
      (res) => /\/api\/admin\/printers\/\d+\/test-print$/.test(res.url()) && res.request().method() === 'POST',
      { timeout: 10_000 }
    );
    await testBtn.click();
    const testRes = await testPrintResponse;
    expect(testRes.status()).toBe(200);
    console.log('[CRUD-FUNCTIONAL] Printers TEST PRINT: real POST .../test-print (200), bypassed to NullPrinterTransport (confirmed via config) -- no real socket touched, no hang.');

    await row.locator('.lab-edit').first().click();
    await expect(modal).toBeVisible({ timeout: 8000 });
    const nameInput = page.locator('#p_name');
    await expect(nameInput).toHaveValue(uniq, { timeout: 8000 });
    await nameInput.fill(`${uniq}EDITED`);
    await modal.locator('button[type="submit"]').click();
    await page.waitForTimeout(1500);

    await expect(table).toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Printers EDIT: rename reflected in table — REAL persistence.');

    // destroy() calls axios.delete directly with no SweetAlert confirm
    // step (confirmed by reading the component) -- unlike every other
    // Delete button in this suite, no confirmDelete() step here.
    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.locator('.lab-delete').first().click();
    await page.waitForTimeout(1500);

    await expect(table).not.toContainText(`${uniq}EDITED`, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Printers DELETE: row gone from table immediately after click (no confirm dialog on this page, confirmed by reading destroy() -- unlike every other Delete flow in this suite) — REAL removal.');
  });
});

test.describe.serial('Real functional CRUD — Time Slots (per-day time-picker create, real delete)', () => {
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

  test('Time Slots: create (Monday, time-picker overlay) -> appears under Monday -> delete (SweetAlert confirm) -> gone', async () => {
    // REAL FINDING, cosmetic not functional: TimeSlotCreateComponent is
    // rendered once PER WEEKDAY (7 instances), and each one hard-codes
    // id="modal" -- confirmed via a live DOM probe that querying #modal
    // returns 7 duplicate ids. Despite the invalid markup, functionality
    // is unaffected: the form payload (including `day`) lives in a single
    // reactive object shared across all 7 instances (:props="props" on
    // the parent), set via @click on the day's own "Ajouter" button
    // BEFORE the modal opens -- so clicking any day's button still
    // submits the correct day, confirmed below by checking the created
    // slot lands under Monday specifically, not just "somewhere". Using
    // Monday (the first "Ajouter" button) to sidestep any ambiguity about
    // which DOM #modal a later day's click would visually resolve to.
    //
    // The time fields are @vuepic/vue-datepicker in time-picker mode, a
    // DIFFERENT interaction pattern than the date-mode calendar already
    // proven on Coupons: readonly text input -> click opens an overlay
    // with inc/dec buttons per column (data-test="time-inc-btn"/"time-dec
    // -btn", aria-label "Increment/Decrement hours|minutes") and a
    // data-test="select-button" to commit. Confirmed via tinker that this
    // environment has ZERO existing time_slots rows, so there's no
    // overlap-validation risk from TimeSlotService's overlap formula
    // (already reviewed as sound elsewhere this session) regardless of
    // which time is picked.
    await page.goto('/admin/settings/time-slots', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    const mondayRow = page.locator('li', { hasText: 'Monday' }).first();
    await mondayRow.getByText(/ajouter|add/i).first().click();
    const modal = page.locator('#modal').first();
    await expect(modal).toBeVisible({ timeout: 8000 });

    // Opening time: accept the default (current time) by opening the
    // overlay and clicking Select immediately.
    await modal.locator('#opening_time, label[for="opening_time"] + div input').first().click();
    await page.locator('[data-test="select-button"]').first().click();
    await page.waitForTimeout(300);

    // Closing time: open overlay, bump the hour forward once so
    // closing > opening (avoids a same-instant edge case), then Select.
    await modal.locator('#closing_time, label[for="closing_time"] + div input').first().click();
    await page.locator('[data-test="time-inc-btn"][aria-label="Increment hours"]').first().click();
    await page.locator('[data-test="select-button"]').first().click();
    await page.waitForTimeout(300);

    await modal.locator('button[type="submit"]').click();
    await page.waitForTimeout(1500);

    await expect(mondayRow).toContainText(/\d{2}:\d{2}/, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Time Slots CREATE: real slot appears under Monday specifically (not just "somewhere") — REAL, time-picker overlay interaction confirmed wired end-to-end.');

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await mondayRow.locator('.lab-close').first().click();
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1200);

    await expect(mondayRow).not.toContainText(/\d{2}:\d{2}/, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Time Slots DELETE: slot gone from Monday row after SweetAlert confirm — REAL removal.');
  });
});

test.describe.serial('Real functional interaction — Branches (real backend rejection, never risks the real branch record)', () => {
  test.setTimeout(120_000);
  let page;
  let baselineBefore;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // V1 is mono-tenant (branch_id=1 is the only real branch, underlying
    // BranchScope on 24+ models) -- a successful mutate-and-restore test
    // was explicitly considered and rejected this session (an adversarial
    // dispute agent argued city/zip_code have zero code consumers and
    // recommended it as "safe"; rejected anyway because "no grep hit in
    // the Laravel backend" doesn't rule out external consumers like a
    // real Google Business listing or delivery-platform integration for
    // this real, currently-operating restaurant). This test proves the
    // real thing worth proving -- the edit form is wired to a real
    // backend endpoint with real server-side validation -- via a path
    // that can NEVER succeed in writing to the real record: clear a
    // required field and submit, expect a real 422. Capture the exact
    // current real values first so a failed assertion can be diffed
    // against ground truth, not assumed unaffected.
    baselineBefore = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=echo json_encode(\\App\\Models\\Branch::find(1)->only(['name','email','phone','city','state','zip_code','address','status','latitude','longitude']));"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
  });

  test.afterAll(async () => {
    if (page) await page.context().close().catch(() => {});
  });

  test('Branches: clearing a required field and saving is rejected by the real backend, real record never touched', async () => {
    await page.goto('/admin/settings/branches', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    const row = page.locator('table.db-table tr', { hasText: 'Cayenne' });
    await row.getByRole('button', { name: /modifier/i }).click();
    await page.waitForTimeout(600);

    const cityInput = page.locator('#modal #city');
    await expect(cityInput).toBeVisible({ timeout: 8000 });
    const realCity = await cityInput.inputValue();
    expect(realCity.length).toBeGreaterThan(0);
    await cityInput.fill('');

    const saveResponse = page.waitForResponse(
      (res) => /\/api\/admin\/setting\/branch\/\d+$/.test(res.url()) && res.request().method() === 'PUT',
      { timeout: 10_000 }
    );
    await page.locator('#modal button[type="submit"]').click();
    const res = await saveResponse;
    expect(res.status()).toBe(422);
    await expect(page.locator('#modal small.db-field-alert')).toBeVisible({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Branches: real PUT /api/admin/setting/branch/1 (422) rejected an empty required "city" field with a real rendered error -- proves the edit form is wired to a real backend with real server-side validation, WITHOUT ever completing a write to the real branch record.`);

    // Close without ever submitting valid data -- no save ever succeeds
    // in this test, so there is nothing to "restore".
    await page.locator('#modal .modal-close').first().click();
    await page.waitForTimeout(500);

    const baselineAfter = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=echo json_encode(\\App\\Models\\Branch::find(1)->only(['name','email','phone','city','state','zip_code','address','status','latitude','longitude']));"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(baselineAfter).toBe(baselineBefore);
    console.log('[CRUD-FUNCTIONAL] Branches: real branch record confirmed byte-for-byte unchanged in DB after this test -- verified, not assumed.');
  });
});

test.describe.serial('Real functional interaction — Language file editor (read-only proof, save deliberately never touched)', () => {
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

  test('Language show/:id: "Récupérer le contenu du fichier" fetches real translation content -- save deliberately never clicked', async () => {
    // LanguageShowComponent.vue's file editor is a raw i18n-file editor
    // whose SAVE path (language/fileTextStore -> LanguageController::
    // fileTextStore -> LanguageService.php) does a global string-replace
    // keyed by the CURRENT translation VALUE, not the key -- confirmed
    // via an adversarial dispute this session: 176 distinct values repeat
    // across multiple unrelated keys in en.json alone (e.g. "Cancel" x9),
    // so editing any one occurrence would silently corrupt every sibling
    // key sharing that value. No safe single-key edit exists, and this
    // session's Branches negative-path pattern (submit-and-expect-422)
    // doesn't apply here: the file endpoint has no comparable required-
    // field validation to trigger a guaranteed-safe rejection.
    //
    // What IS safe and worth proving: the "load a file" READ path.
    // language/fileText -> LanguageController::fileText ->
    // LanguageService::fileText() is confirmed via source read to be a
    // pure `include($resolvedPath)` with zero writes -- a genuinely
    // different backend route (POST admin/setting/language/file-text)
    // than the dangerous save (POST .../file-text/store), never called
    // here.
    await page.goto('/admin/settings/languages', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const row = page.locator('table.db-table tr', { hasText: 'Français' });

    // language/fileList only dispatches AFTER language/show resolves
    // (chained in mounted()), so it's a real, separate, later network
    // call -- wait for it explicitly rather than racing it (a first
    // attempt without this wait hit a real 0-options/422 failure here,
    // confirmed via a live probe to be exactly this timing gap, not a
    // product bug).
    const fileListResponse = page.waitForResponse(
      (res) => /\/api\/admin\/setting\/language\/file-list\//.test(res.url()) && res.request().method() === 'GET',
      { timeout: 15_000 }
    );
    await row.getByRole('link', { name: /voir/i }).click();
    await fileListResponse;
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('h3.text-lg', { hasText: 'Français' })).toBeVisible({ timeout: 10_000 });

    const fileSelect = page.locator('select.db-field-control').first();
    await expect(fileSelect).toBeVisible({ timeout: 10_000 });
    const fileCount = await fileSelect.locator('option').count();
    expect(fileCount).toBeGreaterThan(0);
    const firstFileName = await fileSelect.locator('option').first().textContent();

    // The native <select> visually defaults to showing the first option,
    // but Vue's v-model="form.name" starts as an empty string and only
    // updates on an EXPLICIT selection -- a first attempt without this
    // call submitted name:"" and got a real 422, confirmed via a live
    // probe that the select's options were correctly populated all along
    // (a test bug, not a product bug).
    //
    // REAL FINDING fixed separately (LanguageService.php +
    // LanguageController.php): option index 0 is always "{code}.json" per
    // fileList()'s ordering -- selecting it used to hang this exact
    // request indefinitely (include() on a .json file echoes the raw
    // file as literal output with no `return`, confirmed via a live
    // probe showing the request fire but never receive a response).
    // Fixed to properly json_decode() .json files instead of include()-
    // ing them. This test exercises the fixed default/first-listed file
    // on purpose, not a safer alternative.
    await fileSelect.selectOption({ index: 0 });

    const fileTextResponse = page.waitForResponse(
      (res) => /\/api\/admin\/setting\/language\/file-text$/.test(res.url()) && res.request().method() === 'POST',
      { timeout: 10_000 }
    );
    await page.getByRole('button', { name: /récupérer le contenu/i }).click();
    const res = await fileTextResponse;
    expect(res.status()).toBe(200);
    await page.waitForTimeout(500);

    // The file-content card only renders once fileText has real keys
    // (v-if="Object.keys(fileText).length > 0") -- its appearance alone
    // proves real content came back, not an empty/mocked response.
    await expect(page.locator('input.db-field-control, .form-row input[type="text"]').first()).toBeVisible({ timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Language file editor: real POST /api/admin/setting/language/file-text (200) loaded real content for "${(firstFileName || '').trim()}" -- real fields rendered from the actual file, not a static shell. Save (the risky global-replace-by-value path) deliberately never clicked -- no mutation possible from this test.`);
  });
});
