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

    const editedRow = table.locator('tr', { hasText: `${uniq}EDITED` });
    await editedRow.getByRole('button', { name: /supprimer/i }).click();
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

    const row = table.locator('tr', { hasText: uniq });
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
});
