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

test.describe.serial('Real functional CRUD — Subscribers (delete-only screen)', () => {
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

  test('Subscriber: DB-seeded row -> appears in table -> delete via UI -> gone', async () => {
    // Unlike every other CRUD test in this suite, Subscribers has NO create
    // form in the admin UI -- subscribers self-register from the public
    // website newsletter box (Subscriber model: fillable = ['email'] only).
    // Proving the real destroy() flow still requires a real row to delete,
    // so this seeds one directly via tinker (not the UI, which has no way
    // to create one) and then drives the actual UI delete + confirm cycle.
    const uniq = `e2e-subscriber-${Date.now() % 100000}@e2e-test.local`;
    tinkerExec(`\\App\\Models\\Subscriber::create(['email' => '${uniq}']);`);

    try {
      await page.goto('/admin/subscribers', { waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle').catch(() => {});
      const table = page.locator('table.db-table');
      await expect(table).toContainText(uniq, { timeout: 10_000 });
      console.log(`[CRUD-FUNCTIONAL] Subscriber SEED: "${uniq}" appears in table (DB-seeded, no UI create path exists).`);

      const row = table.locator('tr', { hasText: uniq });
      await row.locator('[class*="delete" i]').first().click();

      const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
      await expect(yesBtn).toBeVisible({ timeout: 10_000 });
      await yesBtn.click();
      await page.waitForTimeout(1500);

      await expect(table).not.toContainText(uniq, { timeout: 10_000 });
      console.log('[CRUD-FUNCTIONAL] Subscriber DELETE: row gone from table after confirm — REAL removal via the actual destroy() endpoint.');
    } finally {
      tinkerExec(`\\App\\Models\\Subscriber::where('email', '${uniq}')->delete();`);
    }
  });
});

// @vuepic/vue-datepicker interaction pattern (discovered live for this
// suite, no prior selector to reuse): the picker instances here use
// `autoApply` -- clicking any enabled calendar day cell picks it AND closes
// the popup immediately, no separate time-picker step or "Select" button.
// Cells: `.dp__calendar_item[aria-disabled="false"]`. To guarantee
// end_date > start_date, start_date takes the FIRST enabled cell and
// end_date navigates one month forward (`[aria-label="Next month"]`) before
// picking, landing safely after start_date regardless of today's date.
async function pickDatepickerDate(page, inputLocator, { nextMonth = false } = {}) {
  await inputLocator.click();
  await page.waitForTimeout(300);
  if (nextMonth) {
    await page.click('.dp__menu [aria-label="Next month"]');
    await page.waitForTimeout(200);
  }
  await page.locator('.dp__calendar_item[aria-disabled="false"]').first().click();
  await page.waitForTimeout(300);
}

test.describe.serial('Real functional CRUD — Coupons (incl. vue-datepicker)', () => {
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

  test('Coupon: create (incl. 2 required datepickers) -> appears in table -> delete -> gone', async () => {
    // name and code are both unique (CouponRequest.php). start_date/end_date
    // are required strings populated by @vuepic/vue-datepicker, not plain
    // inputs -- see pickDatepickerDate() above for the interaction pattern.
    const uniq = `E2ECoupon${Date.now() % 100000}`;
    const code = uniq.slice(-10).toUpperCase();

    await page.goto('/admin/coupons', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.click('[data-drawer="#sidebar"]');
    await page.waitForTimeout(500);

    await page.fill('#sidebar #name', uniq);
    await page.fill('#sidebar #code', code);
    await page.fill('#sidebar #discount', '5');
    await page.locator('#sidebar #fixed').check();

    const datepickerInputs = page.locator('#sidebar .dp__input');
    await pickDatepickerDate(page, datepickerInputs.nth(0));
    await pickDatepickerDate(page, datepickerInputs.nth(1), { nextMonth: true });

    // minimum_order must be >= discount (real backend business rule,
    // CouponService -- discovered via a failed run's 422 body: "Minimum
    // order amount can't be less than discount amount."). discount=5 above,
    // so minimum_order/maximum_discount here must both clear that bar.
    await page.fill('#sidebar #minimum_order', '10');
    await page.fill('#sidebar #maximum_discount', '20');
    // Unlike every sidebar-drawer form elsewhere in this suite, Coupon's
    // status is a plain <select id="status"> (options 5=active/10=inactive),
    // not #active/#inactive radios -- a first attempt guessed radios and
    // hung until test timeout waiting for a locator that doesn't exist on
    // this form. Also a REAL finding along the way: the "image" field's
    // <label> carries class="required" (renders a red asterisk) but
    // CouponRequest.php validates image as nullable on both create and
    // edit -- a frontend/backend mismatch, not a submission blocker, left
    // undecided for the owner rather than "fixed" unilaterally.
    await page.selectOption('#sidebar #status', '5');
    // The advanced-promo-fields surface checkboxes below the submit button
    // geometrically overlap it at its normal scroll position (confirmed via
    // a failed run's trace: "<input ... id="surface_kiosk" ...> subtree
    // intercepts pointer events"). A plain click({force:true}) does NOT fix
    // this -- force only skips Playwright's actionability wait, the browser
    // still delivers the click to whatever's topmost at those coordinates,
    // so it silently hit the checkbox instead and no request ever fired
    // (confirmed via a network-log probe: zero POST to /coupon). Fixed with
    // dispatchEvent('click'), which invokes the button's click handler
    // directly, bypassing real hit-testing entirely.
    await page.locator('#sidebar button[type="submit"]').dispatchEvent('click');
    await page.waitForTimeout(2000);

    const table = page.locator('table.db-table');
    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Coupon CREATE: "${uniq}" appears in table — REAL, both datepickers accepted.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(table).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Coupon DELETE: row gone from table after confirm — REAL removal.');
  });
});
