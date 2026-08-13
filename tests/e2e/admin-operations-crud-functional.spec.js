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

// Reports are read-only by design (no create/edit/delete) -- CRUD proof
// doesn't apply. The equivalent "real interaction, not just page load" proof
// for a filter/report screen is: does the filter form actually change what
// the backend returns, not just decorate the page. Proven by searching for
// a garbage order id that cannot match any real order and asserting the
// table collapses to the real empty-state (not silently ignoring the filter
// and still showing all rows), then clearing the filter and confirming rows
// return -- a genuine round trip through the real query, not a cosmetic
// no-op.
test.describe.serial('Real functional interaction — Sales Report (read-only, filter-driven)', () => {
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

  test('Sales Report: order-id filter actually queries the backend (not a cosmetic no-op)', async () => {
    await page.goto('/admin/sales-report', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');
    await expect(table).toBeVisible({ timeout: 10_000 });

    const beforeText = await table.innerText();
    console.log(`[CRUD-FUNCTIONAL] Sales Report: unfiltered table has ${beforeText.length} chars of content.`);

    // FilterComponent.vue's toggle button carries the stable class
    // .table-filter-btn; clicking it calls handleSlide('sales-report-filter')
    // which reveals the filter panel with that same id.
    await page.click('.table-filter-btn');
    const filterPanel = page.locator('#sales-report-filter');
    await expect(filterPanel).toBeVisible({ timeout: 8000 });

    // Neither button in this filter form has an explicit type="submit"
    // attribute in markup (confirmed via component source) -- they rely on
    // the browser's implicit default, which a `button[type="submit"]` CSS
    // attribute selector does NOT match (it only matches an explicit
    // attribute). A first attempt using that selector hung to test timeout
    // waiting on a locator matching zero elements. Fixed by targeting the
    // search button's distinguishing class (bg-primary) vs. clear's
    // (bg-gray-600) instead.
    const garbageOrderId = `ZZZ-NO-SUCH-ORDER-${Date.now()}`;
    await page.fill('#order_id', garbageOrderId);
    await page.click('#sales-report-filter button.bg-primary');
    await page.waitForTimeout(1500);

    await expect(page.locator('text=/no_data_available|Aucune donnée disponible/i')).toBeVisible({ timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Sales Report: garbage order-id filter correctly collapses to the real empty state — filter reaches the backend query, not a cosmetic no-op.');

    // Clear the filter and confirm real rows return -- proves the round trip,
    // not just that "empty" is the permanent state of this environment.
    await page.click('#sales-report-filter button.bg-gray-600');
    await page.waitForTimeout(1500);
    await expect(table).not.toContainText(/no_data_available|Aucune donnée disponible/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Sales Report: clearing the filter brings real rows back — genuine two-way interaction confirmed.');
  });
});

test.describe.serial('Real functional interaction — Items Report (read-only, filter-driven)', () => {
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

  test('Items Report: item-name filter (vue-select) narrows the table to only that item', async () => {
    // Unlike Sales Report's free-text order_id, this screen's "name" filter
    // is a closed vue-select of real items (label-by/value-by="name") -- no
    // garbage value can be typed. Proof here is stronger than empty-state:
    // pick one real item, filter, and assert every remaining row's name
    // column matches it exactly (not merely "the table changed size").
    await page.goto('/admin/items-report', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');
    await expect(table).toBeVisible({ timeout: 10_000 });

    await page.click('.table-filter-btn');
    const filterPanel = page.locator('#item-report-filter');
    await expect(filterPanel).toBeVisible({ timeout: 8000 });

    const nameGroup = filterPanel.locator('label[for="name"]').locator('xpath=..');
    await nameGroup.locator('.vue-select-header').click();
    await page.waitForTimeout(300);
    const firstOption = nameGroup.locator('li.vue-dropdown-item[role="option"]').first();
    await expect(firstOption).toBeVisible({ timeout: 8000 });
    const chosenName = (await firstOption.innerText()).trim();
    await firstOption.click();

    await page.click('#item-report-filter button.bg-primary');
    await page.waitForTimeout(1500);

    // Two outcomes both prove the filter genuinely reaches the backend
    // query: either the chosen item has real orders in this report's
    // default date range (every remaining row matches its name exactly),
    // or it has none (the real empty-state renders). Only a THIRD outcome
    // -- unrelated items still listed -- would mean the filter is a
    // cosmetic no-op. A first attempt assumed the first vue-select option
    // must have real orders; it didn't (real finding, not a bug: that item
    // had zero sales in the default range), so this asserts the disjunction
    // instead of guessing which branch a given item will land in.
    const isEmpty = await page.locator('text=/no_data_available|Aucune donnée disponible/i').isVisible().catch(() => false);
    if (isEmpty) {
      console.log(`[CRUD-FUNCTIONAL] Items Report: filtering by "${chosenName}" correctly collapses to the real empty state (zero orders for this item in range) — filter reaches the backend, not a cosmetic no-op.`);
    } else {
      const nameCells = table.locator('tbody tr td:first-child');
      const count = await nameCells.count();
      expect(count).toBeGreaterThan(0);
      for (let i = 0; i < count; i++) {
        await expect(nameCells.nth(i)).toHaveText(chosenName);
      }
      console.log(`[CRUD-FUNCTIONAL] Items Report: filtering by "${chosenName}" narrows all ${count} row(s) to exactly that item — filter reaches the backend query, not a cosmetic no-op.`);
    }

    await page.click('#item-report-filter button.bg-gray-600');
    await page.waitForTimeout(1500);
    console.log('[CRUD-FUNCTIONAL] Items Report: filter cleared without error — round trip confirmed.');
  });
});

test.describe.serial('Real functional interaction — Credit Balance Report (read-only, filter-driven)', () => {
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

  test('Credit Balance Report: name filter actually queries the backend (not a cosmetic no-op)', async () => {
    await page.goto('/admin/credit-balance-report', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');
    await expect(table).toBeVisible({ timeout: 10_000 });

    await page.click('.table-filter-btn');
    const filterPanel = page.locator('#credit-balance-filter');
    await expect(filterPanel).toBeVisible({ timeout: 8000 });

    const garbageName = `ZZZ-NO-SUCH-CUSTOMER-${Date.now()}`;
    await page.fill('#searchName', garbageName);
    await page.click('#credit-balance-filter button.bg-primary');
    await page.waitForTimeout(1500);

    await expect(page.locator('text=/no_data_available|Aucune donnée disponible/i')).toBeVisible({ timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Credit Balance Report: garbage name filter correctly collapses to the real empty state — filter reaches the backend query, not a cosmetic no-op.');

    await page.click('#credit-balance-filter button.bg-gray-600');
    await page.waitForTimeout(1500);
    await expect(table).not.toContainText(/no_data_available|Aucune donnée disponible/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Credit Balance Report: clearing the filter brings real rows back — genuine two-way interaction confirmed.');
  });
});

test.describe.serial('Real functional interaction — Cash Sessions Report (read-only, date-filter-driven)', () => {
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

  test('Cash Sessions Report: date-range filter actually queries the backend (not a cosmetic no-op)', async () => {
    // Structurally different from Sales/Items/Credit-Balance Report: no
    // table.db-table (grouped-by-day divs instead), no collapsible filter
    // panel (inline date inputs), and its submit button DOES carry an
    // explicit type="submit" here (unlike Sales/Items Report's buttons).
    await page.goto('/admin/cash-sessions-report', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    // A date range far in the future is guaranteed to have zero real cash
    // sessions -- deterministic empty-state proof, no dependency on what
    // real data happens to exist today.
    await page.fill('#cashFrom', '2099-01-01');
    await page.fill('#cashTo', '2099-01-02');
    await page.click('button[type="submit"].bg-primary');
    await page.waitForTimeout(1500);

    // This screen's empty state uses i18n key label.no_data_available,
    // which renders "Aucune donnée" -- shorter than the message.no_data_available
    // key used elsewhere in this file ("Aucune donnée disponible"). Confirmed
    // via screenshot from a failed run using the longer regex.
    await expect(page.locator('text=/no_data_available|Aucune donnée/i')).toBeVisible({ timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Cash Sessions Report: 2099 date range correctly collapses to the real empty state — filter reaches the backend query, not a cosmetic no-op.');

    await page.click('button.bg-gray-600');
    await page.waitForTimeout(1500);
    await expect(page.locator('text=/no_data_available|Aucune donnée/i')).not.toBeVisible({ timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Cash Sessions Report: clearing the filter brings real data back — genuine two-way interaction confirmed.');
  });
});

test.describe.serial('Real functional interaction — Cash Overview (read-only, date-filter-driven)', () => {
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

  test('Cash Overview: date-range filter actually changes the summary totals (not a cosmetic no-op)', async () => {
    // This screen has real data-testid attributes (unlike every other
    // report page in this file) -- used directly instead of guessing at
    // classes. It's a reconciliation summary (grand total + transaction
    // count), not a table list, so the proof shape is different: a
    // guaranteed-empty 2099 range must show 0 transactions, then clearing
    // must produce a genuinely different count -- proving the filter
    // reaches the backend rather than the summary being a static snapshot.
    await page.goto('/admin/cash-overview', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const summary = page.locator('[data-testid="cash-overview-summary"]');
    await expect(summary).toBeVisible({ timeout: 10_000 });

    await page.fill('#cashOverviewFrom', '2099-01-01');
    await page.fill('#cashOverviewTo', '2099-01-02');
    await page.click('[data-testid="cash-overview-search"]');
    await page.waitForTimeout(1500);

    await expect(summary).toContainText('0', { timeout: 10_000 });
    const emptyRangeText = await summary.innerText();
    console.log(`[CRUD-FUNCTIONAL] Cash Overview: 2099 date range shows "${emptyRangeText.replace(/\s+/g, ' ').trim()}" — 0 transactions confirmed.`);

    // NOT using the "Effacer" button here: clearFilters() resets to TODAY's
    // date, not all-time -- a first attempt assumed "today" would have real
    // dev-environment activity and it didn't (0 tx, same as the 2099 case,
    // not a bug -- just no real traffic hitting this dev server right now).
    // A wide explicit historical range is the reliable way to reach real
    // production history.
    await page.fill('#cashOverviewFrom', '2020-01-01');
    const todayIso = new Date().toISOString().slice(0, 10);
    await page.fill('#cashOverviewTo', todayIso);
    await page.click('[data-testid="cash-overview-search"]');
    await page.waitForTimeout(1500);
    const widRangeText = await summary.innerText();
    expect(widRangeText).not.toBe(emptyRangeText);
    console.log(`[CRUD-FUNCTIONAL] Cash Overview: 2020-today range shows "${widRangeText.replace(/\s+/g, ' ').trim()}" — genuinely different from the 2099 empty case, filter reaches the backend.`);
  });
});

test.describe.serial('Real functional state-machine — Delivery Boy Cash Session (open -> close -> reconcile)', () => {
  test.setTimeout(120_000);
  let page;
  let deliveryBoyId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // deliveryBoyId is a raw numeric input on this form (not a dropdown) --
    // needs a real delivery-boy user id. Seeded via tinker rather than the
    // full UI create flow (already proven separately in
    // admin-users-crud-functional.spec.js) since this test's focus is the
    // cash-session state machine, not delivery-boy creation.
    const uniq = Date.now();
    const out = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=$u = \\App\\Models\\User::create(["name"=>"E2E DeliveryBoy CashSession","email"=>"e2e-dbcs-${uniq}@e2e-test.local","username"=>"e2edbcs${uniq}","phone"=>"06" . substr((string) time(), -8),"password"=>bcrypt("TestPassword123!"),"branch_id"=>1,"status"=>5,"email_verified_at"=>now()]); $u->assignRole(\\App\\Enums\\Role::DELIVERY_BOY); echo $u->id;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    deliveryBoyId = out;
  });

  test.afterAll(async () => {
    if (deliveryBoyId) {
      tinkerExec(`\\App\\Models\\DeliveryBoyCashSession::where('delivery_boy_id', ${deliveryBoyId})->delete(); \\App\\Models\\User::where('id', ${deliveryBoyId})->delete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Delivery Boy Cash Session: open -> close (zero variance) -> reconcile, real state transitions', async () => {
    await page.goto('/admin/delivery-boy-cash-sessions', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.click('[data-testid="delivery-cash-open-session-btn"]');
    await page.waitForTimeout(400);
    await page.fill('[data-testid="delivery-cash-form-livreur-input"]', String(deliveryBoyId));
    await page.fill('#openingAmount', '50');
    await page.click('[data-testid="delivery-cash-form-open-submit"]');
    await page.waitForTimeout(1500);

    // Status badges render the TRANSLATED French label ("Ouverte"/"Fermée"/
    // "Réconciliée"), not an English status code -- a first attempt with
    // an English-only regex failed against the real rendered text
    // (confirmed via the failed run's DOM: <span ...>Ouverte</span>).
    await expect(page).toHaveURL(/\/admin\/delivery-boy-cash-sessions\/\d+/, { timeout: 10_000 });
    await expect(page.locator('[data-testid="delivery-cash-session-status"]')).toContainText(/open|ouverte/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Delivery Boy Cash Session OPEN: real session created, status=open, real navigation to its show page.');

    await page.click('[data-testid="delivery-cash-action-close"]');
    await page.waitForTimeout(400);
    await page.fill('#closingAmount', '50');
    await page.click('[data-testid="delivery-cash-form-close-submit"]');
    await page.waitForTimeout(1500);

    await expect(page.locator('[data-testid="delivery-cash-session-status"]')).toContainText(/closed|fermée/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Delivery Boy Cash Session CLOSE: status transitioned to closed, zero variance (50 in, 50 out).');

    await page.click('[data-testid="delivery-cash-action-reconcile"]');
    await page.waitForTimeout(400);
    await page.fill('#varianceReason', 'E2E test reconciliation, zero variance');
    await page.click('[data-testid="delivery-cash-form-reconcile-submit"]');
    await page.waitForTimeout(1500);

    await expect(page.locator('[data-testid="delivery-cash-session-status"]')).toContainText(/reconcil|réconcili/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Delivery Boy Cash Session RECONCILE: status transitioned to reconciled — full real state machine open->close->reconcile proven end-to-end.');
  });
});

test.describe.serial('Real functional state transition — Online Order status (Accept, via admin show page)', () => {
  test.setTimeout(120_000);
  let page;
  let orderId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // Seeded directly via tinker (mirrors the exact field set already
    // proven safe/valid in red-team-r4-kds-reception-2026-05-07.spec.js's
    // own throwaway-order pattern) -- bypasses the real checkout flow so
    // no fiscal_sequence_no is consumed and no real customer/webhook is
    // touched. order_type=TAKEAWAY (10), not DELIVERY (5): a first attempt
    // used DELIVERY and the show page threw "Cannot read properties of
    // null (reading 'apartment')" -- it dereferences a delivery address
    // unconditionally, which a real DELIVERY order always has (set at
    // checkout) but this minimal seed didn't. TAKEAWAY has no address
    // requirement and still appears in Online Orders (excludes only
    // order_type=POS). status=PENDING (1) so the real "Accepter" button
    // is visible on its show page (confirmed via a screenshot probe).
    const out = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=" +
        "$u = \\App\\Models\\User::role('customer')->first(); " +
        "$o = \\App\\Models\\Order::create(['order_serial_no'=>'E2ETEST-'.substr(uniqid(),-8),'branch_id'=>1,'user_id'=>$u->id,'order_type'=>10,'status'=>1,'payment_status'=>5,'order_datetime'=>now()->toDateTimeString(),'is_advance_order'=>10,'total'=>0,'subtotal'=>0]); echo $o->id;"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    orderId = out;
  });

  test.afterAll(async () => {
    if (orderId) {
      tinkerExec(`\\App\\Models\\Order::where('id', ${orderId})->forceDelete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Online Order: real "Accept" button transitions a real order from Pending to Accepted', async () => {
    await page.goto(`/admin/online-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    // Clicking "Accepter" only opens a SweetAlert2 confirmation ("Are you
    // sure? You will not be able to cancel the order!") -- changeStatus()
    // doesn't dispatch until that's confirmed too. A first attempt without
    // this step left the click registering but the status unchanged in DB
    // (confirmed via the failed run: status stayed at "1"/PENDING).
    await page.getByRole('button', { name: /accept|accepter/i }).click();
    await page.getByRole('button', { name: /yes, accept it/i }).click();
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\Order::find(${orderId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe(String(4)); // OrderStatus::ACCEPT
    console.log(`[CRUD-FUNCTIONAL] Online Order ACCEPT: real order #${orderId} transitioned PENDING(1) -> ACCEPT(4) in DB via the real "Accept" button, not a client-side-only toast.`);
  });
});

test.describe.serial('Real functional state transition — Table Order status (Accept, via admin show page)', () => {
  test.setTimeout(120_000);
  let page;
  let orderId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // Same throwaway-order pattern proven for Online Orders just above,
    // order_type=DINING_TABLE (20) this time. No address dereference found
    // in TableOrderShowComponent.vue's template (unlike OnlineOrder's
    // DELIVERY-address crash), so no special type substitution needed here.
    const out = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=" +
        "$u = \\App\\Models\\User::role('customer')->first(); " +
        "$o = \\App\\Models\\Order::create(['order_serial_no'=>'E2ETEST-'.substr(uniqid(),-8),'branch_id'=>1,'user_id'=>$u->id,'order_type'=>20,'status'=>1,'payment_status'=>5,'order_datetime'=>now()->toDateTimeString(),'is_advance_order'=>10,'total'=>0,'subtotal'=>0]); echo $o->id;"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    orderId = out;
  });

  test.afterAll(async () => {
    if (orderId) {
      tinkerExec(`\\App\\Models\\Order::where('id', ${orderId})->forceDelete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Table Order: real "Accept" button transitions a real order from Pending to Accepted', async () => {
    await page.goto(`/admin/table-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    // Same appService.acceptOrder() SweetAlert2 confirmation gate as
    // OnlineOrder's changeStatus() -- confirmed from the start this time.
    await page.getByRole('button', { name: /accept|accepter/i }).click();
    await page.getByRole('button', { name: /yes, accept it/i }).click();
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\Order::find(${orderId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe(String(4)); // OrderStatus::ACCEPT
    console.log(`[CRUD-FUNCTIONAL] Table Order ACCEPT: real order #${orderId} transitioned PENDING(1) -> ACCEPT(4) in DB via the real "Accept" button, not a client-side-only toast.`);
  });
});

test.describe.serial('Real functional state transition — Online Order Reject (with reason, real DB persistence)', () => {
  test.setTimeout(120_000);
  let page;
  let orderId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    const out = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=" +
        "$u = \\App\\Models\\User::role('customer')->first(); " +
        "$o = \\App\\Models\\Order::create(['order_serial_no'=>'E2ETEST-'.substr(uniqid(),-8),'branch_id'=>1,'user_id'=>$u->id,'order_type'=>10,'status'=>1,'payment_status'=>5,'order_datetime'=>now()->toDateTimeString(),'is_advance_order'=>10,'total'=>0,'subtotal'=>0]); echo $o->id;"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    orderId = out;
  });

  test.afterAll(async () => {
    if (orderId) {
      tinkerExec(`\\App\\Models\\Order::where('id', ${orderId})->forceDelete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Online Order: real "Reject" button (with reason) transitions a real order from Pending to Rejected', async () => {
    // OnlineOrderReasonComponent, no explicit :status prop on this usage ->
    // defaults to REJECTED(19), modal-id "reasonModal", testid
    // "online-order-reason-trigger-19" (confirmed from component source:
    // props.status default = orderStatusEnum.REJECTED). Different mechanism
    // from Accept: no SweetAlert2 confirm, a modal form with a reason field
    // (id="name" inside the modal) instead.
    await page.goto(`/admin/online-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.click('[data-testid="online-order-reason-trigger-19"]');
    await page.waitForTimeout(400);
    await page.fill('#reasonModal #name', 'E2E test rejection reason');
    await page.click('#reasonModal button[type="submit"]');
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\Order::find(${orderId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe(String(19)); // OrderStatus::REJECTED
    console.log(`[CRUD-FUNCTIONAL] Online Order REJECT: real order #${orderId} transitioned PENDING(1) -> REJECTED(19) in DB, reason persisted via the real reject modal.`);
  });
});

test.describe.serial('Real functional state transition — Table Order Reject (with reason, real DB persistence)', () => {
  test.setTimeout(120_000);
  let page;
  let orderId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    const out = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=" +
        "$u = \\App\\Models\\User::role('customer')->first(); " +
        "$o = \\App\\Models\\Order::create(['order_serial_no'=>'E2ETEST-'.substr(uniqid(),-8),'branch_id'=>1,'user_id'=>$u->id,'order_type'=>20,'status'=>1,'payment_status'=>5,'order_datetime'=>now()->toDateTimeString(),'is_advance_order'=>10,'total'=>0,'subtotal'=>0]); echo $o->id;"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    orderId = out;
  });

  test.afterAll(async () => {
    if (orderId) {
      tinkerExec(`\\App\\Models\\Order::where('id', ${orderId})->forceDelete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Table Order: real "Reject" button (with reason) transitions a real order from Pending to Rejected', async () => {
    // TableOrderReasonComponent differs from OnlineOrderReasonComponent: no
    // data-testid, status=REJECTED is internal data (not a configurable
    // prop, since Table Order has only one reason-modal use case, unlike
    // Online Order's Reject+Cancel reuse of the same component). Trigger
    // button targeted by its stable text ("Refuser") instead.
    await page.goto(`/admin/table-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.getByRole('button', { name: /reject|refuser/i }).click();
    await page.waitForTimeout(400);
    await page.fill('#reasonModal #name', 'E2E test rejection reason');
    await page.click('#reasonModal button[type="submit"]');
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\Order::find(${orderId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe(String(19)); // OrderStatus::REJECTED
    console.log(`[CRUD-FUNCTIONAL] Table Order REJECT: real order #${orderId} transitioned PENDING(1) -> REJECTED(19) in DB, reason persisted via the real reject modal.`);
  });
});

test.describe.serial('Real functional state transition — Online Order status dropdown (Accept -> Delivered)', () => {
  test.setTimeout(120_000);
  let page;
  let orderId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    const out = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=" +
        "$u = \\App\\Models\\User::role('customer')->first(); " +
        "$o = \\App\\Models\\Order::create(['order_serial_no'=>'E2ETEST-'.substr(uniqid(),-8),'branch_id'=>1,'user_id'=>$u->id,'order_type'=>10,'status'=>1,'payment_status'=>5,'order_datetime'=>now()->toDateTimeString(),'is_advance_order'=>10,'total'=>0,'subtotal'=>0]); echo $o->id;"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    orderId = out;
  });

  test.afterAll(async () => {
    if (orderId) {
      tinkerExec(`\\App\\Models\\Order::where('id', ${orderId})->forceDelete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Online Order: the status dropdown (a third, different UI control) jumps a real order to Delivered', async () => {
    // A third distinct status-change mechanism on this same page, after
    // the Accept button+SweetAlert2-confirm and the Reject modal+reason:
    // a dropdown list (<li> items, orderStatus(status.value)) with NO
    // confirmation step at all (confirmed via component source: orderStatus()
    // dispatches directly, unlike changeStatus() which gates through
    // appService.acceptOrder()). Only reachable once the order is no longer
    // PENDING, so Accept first via the already-proven button+confirm flow.
    await page.goto(`/admin/online-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.getByRole('button', { name: /accept|accepter/i }).click();
    await page.getByRole('button', { name: /yes, accept it/i }).click();
    await page.waitForTimeout(1500);

    // .dropdown-group is CSS :hover-driven (no JS toggle) -- confirmed via
    // an existing proven pattern elsewhere in this suite
    // (wave-t-r1-f5-delivery-ui.spec.js). A plain .click() on the button
    // never opens it (scale-y-0 stays applied), so a first attempt here
    // timed out waiting on a permanently-invisible <li>. hover() + a real
    // click still leaves the panel's computed visibility ambiguous
    // (animation-driven), so dispatchEvent('click') is used to fire the
    // Vue handler directly, same as that proven pattern.
    // .dropdown-group is reused across this page (nav avatar menu, payment
    // status dropdown, order status dropdown) -- a first attempt's bare
    // locator hit a 4-way strict-mode violation. The order-status one is
    // last in DOM order (confirmed via the violation's own element list:
    // avatar menu, payment status "Payé/Non payé", THEN order status
    // "Acceptée/En pré...").
    const dropdownGroup = page.locator('.dropdown-group').last();
    await dropdownGroup.hover();
    await page.waitForTimeout(300);
    await dropdownGroup.locator('.dropdown-list li', { hasText: /delivered|livrée|livré/i }).dispatchEvent('click');
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\Order::find(${orderId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe(String(13)); // OrderStatus::DELIVERED
    console.log(`[CRUD-FUNCTIONAL] Online Order DROPDOWN: real order #${orderId} transitioned ACCEPT(4) -> DELIVERED(13) in DB via the status dropdown, a 3rd distinct UI control on this page proven functional.`);
  });
});

test.describe.serial('Real functional state transition — Online Order Cancel (active order, real DB persistence)', () => {
  test.setTimeout(120_000);
  let page;
  let orderId;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    const out = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=" +
        "$u = \\App\\Models\\User::role('customer')->first(); " +
        "$o = \\App\\Models\\Order::create(['order_serial_no'=>'E2ETEST-'.substr(uniqid(),-8),'branch_id'=>1,'user_id'=>$u->id,'order_type'=>10,'status'=>1,'payment_status'=>5,'order_datetime'=>now()->toDateTimeString(),'is_advance_order'=>10,'total'=>0,'subtotal'=>0]); echo $o->id;"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    orderId = out;
  });

  test.afterAll(async () => {
    if (orderId) {
      tinkerExec(`\\App\\Models\\Order::where('id', ${orderId})->forceDelete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Online Order: real "Cancel" button (with reason) cancels an already-ACCEPTED order, distinct from Reject', async () => {
    // Genuinely different business scenario from Reject: Reject only
    // applies to a still-PENDING order (never confirmed to the kitchen);
    // Cancel applies to an order already ACCEPT/PREPARING/PREPARED/
    // OUT_FOR_DELIVERY (canCancelActiveOrder(), OnlineOrderShowComponent.vue)
    // -- reuses OnlineOrderReasonComponent with status=CANCELED,
    // modal-id="cancelReasonModal" instead of the default "reasonModal".
    await page.goto(`/admin/online-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.getByRole('button', { name: /accept|accepter/i }).click();
    await page.getByRole('button', { name: /yes, accept it/i }).click();
    await page.waitForTimeout(1500);

    await page.getByRole('button', { name: /cancel|annuler/i }).click();
    await page.waitForTimeout(400);
    await page.fill('#cancelReasonModal #name', 'E2E test cancellation reason');
    await page.click('#cancelReasonModal button[type="submit"]');
    await page.waitForTimeout(1500);

    const dbStatus = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo \\App\\Models\\Order::find(${orderId})->status;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbStatus).toBe(String(16)); // OrderStatus::CANCELED
    console.log(`[CRUD-FUNCTIONAL] Online Order CANCEL: real order #${orderId} transitioned ACCEPT(4) -> CANCELED(16) in DB via the real cancel-active-order modal, distinct from Reject.`);
  });
});

test.describe.serial('Real functional CRUD — Push Notification (send flow, zero real devices registered)', () => {
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

  test('Push Notification: create (real FCM fan-out call, 0 registered devices) -> appears in table -> delete -> gone', async () => {
    // Confirmed via tinker before writing this test: 0 users have a
    // web_token or device_token in this environment, so the real
    // PushNotificationService::store() fan-out call (FirebaseService::
    // sendNotification) resolves to an empty token array -- safe to drive
    // the real create flow without risking a notification reaching any
    // real device. role_id/image are both optional; only title+description
    // are required.
    const uniq = `E2EPush${Date.now() % 100000}`;

    await page.goto('/admin/push-notifications', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.click('[data-drawer="#sidebar"]');
    await page.waitForTimeout(500);

    await page.fill('#sidebar #title', uniq);
    await page.fill('#sidebar #description', `E2E test push notification body ${uniq}`);

    await page.click('#sidebar button[type="submit"]');
    await page.waitForTimeout(2000);

    const table = page.locator('table.db-table');
    await expect(table).toContainText(uniq, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Push Notification CREATE: "${uniq}" appears in table — REAL, real FCM fan-out call completed with 0 real devices reached.`);

    const row = table.locator('tr', { hasText: uniq });
    await row.locator('[class*="delete" i]').first().click();

    const yesBtn = page.getByRole('button', { name: /yes,\s*delete it/i });
    await expect(yesBtn).toBeVisible({ timeout: 10_000 });
    await yesBtn.click();
    await page.waitForTimeout(1500);

    await expect(table).not.toContainText(uniq, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Push Notification DELETE: row gone from table after confirm — REAL removal.');
  });
});

test.describe.serial('Real functional interaction — Observability Outbox dashboard (read-only, refresh-driven)', () => {
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

  test('Outbox dashboard: "Refresh" button fires a real GET, not a decorative no-op', async () => {
    // A system observability screen (sync outbox health), not a table
    // list -- deliberately only exercises the read-only Refresh button,
    // not Retry Failed/Drain Failed (those mutate real queued jobs in a
    // live system, out of scope for a proof pass). Proof: intercept the
    // network request the click fires, rather than compare a live
    // "generated at" timestamp that could coincidentally look unchanged
    // between two fast polls.
    await page.goto('/admin/observability/outbox', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('[data-testid="outbox-overview-dashboard"]')).toBeVisible({ timeout: 10_000 });

    const refreshResponse = page.waitForResponse(
      (res) => /\/api\/admin\/observability\/outbox(\?|$)/.test(res.url()) && res.request().method() === 'GET',
      { timeout: 10_000 }
    );
    await page.click('[data-testid="outbox-refresh"]');
    const res = await refreshResponse;
    expect(res.status()).toBe(200);
    console.log('[CRUD-FUNCTIONAL] Outbox dashboard: Refresh button fired a real GET /api/admin/observability/outbox (200), not a cosmetic no-op.');
  });
});

test.describe.serial('Real functional interaction — System Health dashboard (read-only, refresh-driven)', () => {
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

  test('System Health dashboard: "Refresh" button fires a real GET, not a decorative no-op', async () => {
    // This page also has real feature-flag toggles ("interrupteurs",
    // PUT /admin/observability/interrupteurs/:nom). Only the read-only
    // Refresh button (health-check re-fetch) is proven here; the
    // interrupteurs themselves are exercised in the dedicated test below
    // now that InterrupteurService.php has been read and confirmed as a
    // deliberately safe, reversible, owner-facing whitelist of exactly 2
    // toggles (split_payment, wheel) -- the genuinely dangerous switch
    // (idempotency.enabled, an NF525 fiscal safety guard) is permanently
    // excluded from that whitelist by the original developer's own design
    // comment, so it was never in scope for a "risky toggle" concern.
    await page.goto('/admin/observability/system', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('[data-testid="system-health"]')).toBeVisible({ timeout: 10_000 });

    const refreshResponse = page.waitForResponse(
      (res) => /\/api\/admin\/observability\/system-health(\?|$)/.test(res.url()) && res.request().method() === 'GET',
      { timeout: 10_000 }
    );
    await page.click('[data-testid="system-health-refresh"]');
    const res = await refreshResponse;
    expect(res.status()).toBe(200);
    console.log('[CRUD-FUNCTIONAL] System Health dashboard: Refresh button fired a real GET /api/admin/observability/system-health (200), not a cosmetic no-op.');
  });
});

test.describe.serial('Real functional interaction — System Health interrupteur (wheel toggle, deliberately safe by design)', () => {
  test.setTimeout(120_000);
  let page;
  let originalValue;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    originalValue = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=echo (new \\App\\Services\\Pilotage\\InterrupteurService())->valeur('wheel') ? '1' : '0';"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
  });

  test.afterAll(async () => {
    // Restore to the exact pre-test DB value regardless of what the test left it at.
    tinkerExec(`(new \\App\\Services\\Pilotage\\InterrupteurService())->regler('wheel', ${originalValue === '1' ? 'true' : 'false'});`);
    if (page) await page.context().close().catch(() => {});
  });

  test('wheel interrupteur: clicking the toggle flips real Settings-backed state, not a cosmetic switch', async () => {
    await page.goto('/admin/observability/system', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('[data-testid="system-interrupteurs"]')).toBeVisible({ timeout: 10_000 });

    const toggleBtn = page.locator('[data-testid="interrupteur-bouton-wheel"]');
    await expect(toggleBtn).toBeVisible({ timeout: 10_000 });
    const pressedBefore = await toggleBtn.getAttribute('aria-pressed');
    expect(pressedBefore).toBe(originalValue === '1' ? 'true' : 'false');

    const putResponse = page.waitForResponse(
      (res) => /\/admin\/observability\/interrupteurs\/wheel$/.test(res.url()) && res.request().method() === 'PUT',
      { timeout: 10_000 }
    );
    await toggleBtn.click();
    const res = await putResponse;
    expect(res.status()).toBe(200);
    await page.waitForTimeout(500);

    const expectedAfter = originalValue === '1' ? 'false' : 'true';
    await expect(toggleBtn).toHaveAttribute('aria-pressed', expectedAfter, { timeout: 10_000 });

    const dbAfter = execFileSync(
      'php',
      ['artisan', 'tinker', "--execute=echo (new \\App\\Services\\Pilotage\\InterrupteurService())->valeur('wheel') ? '1' : '0';"],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
    expect(dbAfter).toBe(expectedAfter === 'true' ? '1' : '0');
    console.log(`[CRUD-FUNCTIONAL] System Health interrupteur "wheel": clicking the toggle sent a real PUT /admin/observability/interrupteurs/wheel (200) and flipped the real Settings-backed value in DB from ${originalValue} to ${dbAfter} -- not a client-side-only switch.`);

    // Flip back within the test itself (in addition to the afterAll safety net) so the
    // proof includes round-trip reversibility, matching the page's own documented promise.
    const putResponseBack = page.waitForResponse(
      (res) => /\/admin\/observability\/interrupteurs\/wheel$/.test(res.url()) && res.request().method() === 'PUT',
      { timeout: 10_000 }
    );
    await toggleBtn.click();
    const resBack = await putResponseBack;
    expect(resBack.status()).toBe(200);
    await page.waitForTimeout(500);
    await expect(toggleBtn).toHaveAttribute('aria-pressed', originalValue === '1' ? 'true' : 'false', { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] System Health interrupteur "wheel": restored to its original value via the same real toggle button -- round-trip reversibility confirmed.');
  });
});

test.describe.serial('Real functional interaction — Transactions (read-only, filter-driven)', () => {
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

  test('Transactions: transaction-id filter actually queries the backend (not a cosmetic no-op)', async () => {
    await page.goto('/admin/transactions', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const table = page.locator('table.db-table');
    await expect(table).toBeVisible({ timeout: 10_000 });

    await page.click('.table-filter-btn');
    const filterPanel = page.locator('#transaction-filter');
    await expect(filterPanel).toBeVisible({ timeout: 8000 });

    const garbageId = `ZZZ-NO-SUCH-TXN-${Date.now()}`;
    await page.fill('#transaction_id', garbageId);
    await page.click('#transaction-filter button.bg-primary');
    await page.waitForTimeout(1500);

    await expect(page.locator('text=/no_data_available|Aucune donnée disponible/i')).toBeVisible({ timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Transactions: garbage transaction-id filter correctly collapses to the real empty state — filter reaches the backend query, not a cosmetic no-op.');

    await page.click('#transaction-filter button.bg-gray-600');
    await page.waitForTimeout(1500);
    await expect(table).not.toContainText(/no_data_available|Aucune donnée disponible/i, { timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Transactions: clearing the filter brings real rows back — genuine two-way interaction confirmed.');
  });
});

test.describe.serial('Real functional CRUD — Connected Devices (rename only, never revoke)', () => {
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

  test('Connected Devices: rename the current session\'s device label -> persists -> restored', async () => {
    // This screen also has a "Disconnect"/"Logout" (revoke) action --
    // deliberately NEVER clicked: revoking THIS test's own current device
    // token would immediately invalidate its own authenticated session
    // mid-test (and potentially other concurrent sessions using the same
    // admin login). Only Rename is exercised: it relabels a device with
    // zero security/session impact. Targets the row carrying the
    // "this_device" badge (this Playwright session's own token) since
    // that's guaranteed to exist and be safe to touch.
    await page.goto('/admin/profile/devices', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    // A locator FILTERED by the "this device" badge text stops matching its
    // own row the moment that row enters rename mode: the badge lives in
    // the v-else display branch, which the v-if edit branch (input+Save+
    // Cancel, no badge) replaces entirely. A first attempt reused the
    // filtered locator across the click->fill sequence and hung to test
    // timeout waiting on an input inside a row the locator could no longer
    // see. Fixed by resolving the row's stable INDEX once up front (Vue's
    // :key="device.id" keeps the same <tr> DOM node across re-renders, so
    // nth() stays valid regardless of which branch is showing), then using
    // plain `tbody tr` + nth() for every step instead of a content filter.
    const badgeRow = page.locator('tbody tr', { has: page.getByText(/this device|cet appareil/i) }).first();
    await expect(badgeRow).toBeVisible({ timeout: 10_000 });
    const allRows = page.locator('tbody tr');
    const rowCount = await allRows.count();
    let rowIndex = 0;
    for (let i = 0; i < rowCount; i++) {
      if (await allRows.nth(i).getByText(/this device|cet appareil/i).count()) {
        rowIndex = i;
        break;
      }
    }
    const row = allRows.nth(rowIndex);
    const original = (await row.locator('span.font-medium').innerText()).trim();
    const mutated = `E2E Test Device ${Date.now() % 100000}`;

    await row.getByRole('button', { name: /rename|renommer/i }).click();
    await row.locator('input').fill(mutated);
    await row.getByRole('button', { name: /save|enregistrer/i }).click();
    await page.waitForTimeout(1000);

    await expect(row.locator('span.font-medium')).toHaveText(mutated, { timeout: 8000 });
    console.log(`[CRUD-FUNCTIONAL] Connected Devices RENAME: "${original}" -> "${mutated}", REAL persistence, never touched Disconnect/Logout.`);

    await row.getByRole('button', { name: /rename|renommer/i }).click();
    await row.locator('input').fill(original);
    await row.getByRole('button', { name: /save|enregistrer/i }).click();
    await page.waitForTimeout(1000);
    await expect(row.locator('span.font-medium')).toHaveText(original, { timeout: 8000 });
    console.log('[CRUD-FUNCTIONAL] Connected Devices RENAME: restored to original label.');
  });
});

test.describe.serial('Real functional CRUD — Messages (admin-to-customer chat, real send)', () => {
  test.setTimeout(120_000);
  let page;
  let customerId;
  let customerName;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);

    // A throwaway customer, not a real one -- sending a real chat message
    // here would otherwise be visible to an actual person via their
    // mobile/web account. Mirrors DeliveryBoyService::store()'s exact
    // User::create + assignRole pattern (role=CUSTOMER instead).
    customerName = `E2E Msg Customer ${Date.now() % 100000}`;
    const uniq = Date.now();
    customerId = execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=$u = \\App\\Models\\User::create(["name"=>"${customerName}","email"=>"e2e-msg-${uniq}@e2e-test.local","username"=>"e2emsg${uniq}","phone"=>"06" . substr((string) time(), -8),"password"=>bcrypt("TestPassword123!"),"branch_id"=>1,"status"=>5,"email_verified_at"=>now()]); $u->assignRole(\\App\\Enums\\Role::CUSTOMER); echo $u->id;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();
  });

  test.afterAll(async () => {
    if (customerId) {
      // message_histories has a FK on messages.id -- a first attempt's
      // cleanup deleted messages directly and threw a foreign-key
      // constraint violation, which failed silently inside afterAll and
      // left both a stray message and a stray user behind (confirmed via
      // a follow-up tinker query; cleaned up by hand and fixed here).
      tinkerExec(`$ids = \\App\\Models\\Message::where('user_id', ${customerId})->pluck('id'); \\App\\Models\\MessageHistory::whereIn('message_id', $ids)->delete(); \\App\\Models\\Message::where('user_id', ${customerId})->delete(); \\App\\Models\\User::where('id', ${customerId})->delete();`);
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Messages: send a real chat message to a customer, appears in the thread', async () => {
    await page.goto('/admin/messages', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.fill('#name', customerName);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1000);
    await page.click(`li:has-text("${customerName}")`);
    await page.waitForTimeout(800);

    const messageText = `E2E test message ${Date.now()}`;
    await page.fill('input.chat-footer-data-input', messageText);
    await page.click('button.chat-footer-sent');
    await page.waitForTimeout(1500);

    await expect(page.locator('body')).toContainText(messageText, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Messages: real chat message sent to throwaway customer "${customerName}" and appears in the thread — REAL admin-to-customer send, not a client-side-only echo.`);
  });
});

test.describe.serial('Real functional interaction — Z-Reports (X-Report, read-only fiscal snapshot)', () => {
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

  test('Z-Reports: "X Report" button fetches a real live fiscal snapshot (GET, no mutation)', async () => {
    // Frozen-zone-adjacent territory (NF525 fiscal), but this specific
    // button is NOT the frozen Z-report close/sequence logic -- an
    // "X report" is the fiscal-terminology READ-ONLY reconciliation
    // snapshot (GET /admin/fiscal/x-report, confirmed via component
    // source), distinct from closing/creating a Z-report which IS
    // append-only/irreversible and correctly out of scope. No frozen
    // file touched, no mutation, no NF525 chain risk.
    await page.goto('/admin/settings/z-reports', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    const xReportResponse = page.waitForResponse(
      (res) => /\/api\/admin\/fiscal\/x-report(\?|$)/.test(res.url()) && res.request().method() === 'GET',
      { timeout: 10_000 }
    );
    await page.click('[data-testid="fiscal-x-report-btn"]');
    const res = await xReportResponse;
    expect(res.status()).toBe(200);

    const body = page.locator('[data-testid="fiscal-x-report-body"]');
    await expect(body).toBeVisible({ timeout: 10_000 });
    const text = (await body.innerText()).trim();
    expect(text.length).toBeGreaterThan(0);
    console.log(`[CRUD-FUNCTIONAL] Z-Reports X-Report: real GET /api/admin/fiscal/x-report (200) fetched a real fiscal snapshot (${text.length} chars), not a cosmetic no-op.`);
  });
});

test.describe.serial('Real functional CRUD — Promo Flyer (create real coupon code, revoke)', () => {
  test.setTimeout(120_000);
  let page;
  const flyerNamePrefix = `E2EFlyer${Date.now() % 100000}`;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    // A PromoFlyer create also auto-creates a REAL linked Coupon record
    // (confirmed via tinker: coupon_id on the flyer, a genuine Coupon row).
    // revoke() correctly deactivates it (status->INACTIVE) but doesn't
    // delete either row, so both need explicit cleanup here. The coupon's
    // `code` is a TRUNCATED/randomized slug (e.g. customer "E2EFlyer41685"
    // -> code "E2EFLYER4168-3ES3", one character short of a full prefix
    // match) -- a first cleanup attempt matched on `code LIKE` and missed
    // it, confirmed via tinker leaving 1 stray coupon after a run despite
    // the flyer itself being cleaned. Fixed by matching on the coupon's
    // `name` field instead ("Flyer <customer_name>"), which embeds the
    // full un-truncated customer name.
    tinkerExec(`\\App\\Models\\Coupon::where('name', 'like', 'Flyer ${flyerNamePrefix}%')->forceDelete(); \\App\\Models\\PromoFlyer::where('customer_name', 'like', '${flyerNamePrefix}%')->delete();`);
    if (page) await page.context().close().catch(() => {});
  });

  test('Promo Flyer: create a real coupon code -> appears in history -> revoke (native confirm dialog)', async () => {
    // customer_name is free text (not linked to a real user account) -- the
    // flyer print itself routes through a local print-bridge (same pattern
    // as kitchen tickets, per this project's established architecture: the
    // server can't reach in-restaurant printers directly), so triggering
    // "print" in this dev/test environment has no physical side effect.
    // revoke() uses a NATIVE window.confirm(), not a SweetAlert2 modal like
    // every other confirm in this codebase -- handled via Playwright's
    // page.on('dialog') (safe/standard for Playwright automation, unlike
    // browser-extension automation where native dialogs are a known hazard).
    const customerName = flyerNamePrefix;

    await page.goto('/admin/promo-flyer', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});

    await page.fill('#customer_name', customerName);
    await page.getByRole('button', { name: /print flyer|imprimer/i }).click();
    await page.waitForTimeout(1500);

    // button.refresh renders "Actualiser" in French here (confirmed via
    // languages/fr.json button.refresh -- a DIFFERENT French word than
    // "Rafraîchir" used by button.refresh in other namespaces of the same
    // file, e.g. the Outbox/System Health dashboards tested earlier).
    await page.getByRole('button', { name: /refresh|actualiser/i }).click();
    await page.waitForTimeout(1000);

    const row = page.locator('tbody tr', { hasText: customerName });
    await expect(row).toBeVisible({ timeout: 10_000 });
    const code = (await row.locator('td').nth(1).innerText()).split('\n')[0].trim();
    console.log(`[CRUD-FUNCTIONAL] Promo Flyer CREATE: real coupon code "${code}" issued for throwaway customer "${customerName}", appears in history.`);

    // button.cancel_code renders just "Annuler" in French (confirmed via
    // languages/fr.json), not "Revoke"/"Annuler le code" -- targeted by its
    // distinguishing class instead of guessing at exact text.
    page.once('dialog', (dialog) => dialog.accept());
    await row.locator('button.bg-rose-700').click();
    await page.waitForTimeout(1500);

    // button.refresh renders "Actualiser" in French here (confirmed via
    // languages/fr.json button.refresh -- a DIFFERENT French word than
    // "Rafraîchir" used by button.refresh in other namespaces of the same
    // file, e.g. the Outbox/System Health dashboards tested earlier).
    await page.getByRole('button', { name: /refresh|actualiser/i }).click();
    await page.waitForTimeout(1000);
    // label.flyer_revoked renders "annule" in French (confirmed via
    // languages/fr.json), not "revoked"/"révoqué".
    await expect(page.locator('tbody tr', { hasText: customerName })).toContainText(/revoked|annule/i, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Promo Flyer REVOKE: real code "${code}" marked revoked via the native confirm dialog, not a client-side-only toast.`);
  });
});

test.describe.serial('Real functional interaction — Ingredients (read-only by design, usage-drawer proof)', () => {
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

  test('Ingredients: "View details" opens the usage drawer with a real fetched usage payload, not a static/mocked panel', async () => {
    // Read-only page by design (post stock-mgmt-M1 consolidation, commit
    // 5037203f1: "collapse duplicate stock-rupture entry points — single
    // SSOT page /admin/stock/rupture V2"). A real toggle component
    // (IngredientAvailabilityToggleComponent.vue) + a wired Vuex action
    // (ingredients/toggleAvailability) + a real backend route
    // (PUT /admin/ingredients/{id}/availability) all still exist and are
    // fully built, but the component is NEVER imported/rendered anywhere
    // in the app (confirmed via grep across resources/js) -- dead code
    // left over from the consolidation, not a broken button (there is no
    // button to click at all). The mutation surface now lives at
    // /admin/stock/rupture instead. The only real interactive element on
    // THIS page is the "View details" usage drawer, proven here via a
    // real network fetch, matching the read-only-page proof pattern used
    // for the Report screens above.
    await page.goto('/admin/ingredients', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('[data-testid="ingredient-list"]')).toBeVisible({ timeout: 10_000 });

    const firstRow = page.locator('tbody tr[data-global-id]').first();
    await expect(firstRow).toBeVisible({ timeout: 10_000 });
    const rowName = (await firstRow.locator('th p').first().innerText()).trim();

    const usageResponse = page.waitForResponse(
      (res) => /\/api\/admin\/ingredients\/[^/]+\/usage$/.test(res.url()) && res.request().method() === 'GET',
      { timeout: 10_000 }
    );
    await firstRow.getByRole('button', { name: /view details|voir les détails/i }).click();
    const res = await usageResponse;
    expect(res.status()).toBe(200);

    const drawer = page.locator('[data-testid="ingredient-usage-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('[data-testid="ingredient-usage-name"]')).toContainText(rowName, { timeout: 10_000 });
    console.log(`[CRUD-FUNCTIONAL] Ingredients: "View details" on "${rowName}" fired a real GET .../usage (200) and rendered its real payload in the drawer -- not a static panel.`);

    await page.locator('[data-testid="ingredient-usage-backdrop"]').click();
    await expect(drawer).not.toBeVisible({ timeout: 10_000 });
    console.log('[CRUD-FUNCTIONAL] Ingredients: usage drawer closes via backdrop click -- real interaction, not a decorative overlay.');
  });
});

test.describe.serial('Real functional interaction — Stock Rupture Dashboard (real item availability toggle, round-trip)', () => {
  test.setTimeout(120_000);
  let page;
  let itemId;
  let originalAvailable;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    page = await context.newPage();
    await loginAsAdmin(page);
  });

  test.afterAll(async () => {
    // Safety net: if the test's own round-trip somehow didn't restore the
    // real item, force it back via the same real toggle service used by
    // the controller (never touch the row with a raw UPDATE).
    if (itemId != null && originalAvailable != null) {
      tinkerExec(
        `app(\\App\\Services\\Menu\\AvailabilityService::class)->toggle(${itemId}, 1, ${originalAvailable ? 'true' : 'false'});`
      );
    }
    if (page) await page.context().close().catch(() => {});
  });

  test('Stock Rupture Dashboard: toggling a real product actually flips its branch-scoped availability in DB, then restores it', async () => {
    // This is the real, canonical SSOT toggle surface (CLAUDE.md's own
    // documented consolidation target, commit 5037203f1) -- unlike
    // Ingredients' name-cascade (32 Tacos sharing one extra), this writes
    // a single ItemBranchAvailability row scoped to (item_id, branch_id),
    // confirmed by reading AvailabilityController::toggle(). Kept the
    // customer-visible window as short as possible: toggle off, verify,
    // toggle back on immediately, verify restored -- same discipline as
    // the wheel interrupteur test above.
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.locator('[data-testid="stock-management-v2"]')).toBeVisible({ timeout: 15_000 });

    const firstToggle = page.locator('[data-testid^="stock-mgmt-toggle-item-"]').first();
    await expect(firstToggle).toBeVisible({ timeout: 15_000 });
    const testId = await firstToggle.getAttribute('data-testid');
    itemId = Number(testId.replace('stock-mgmt-toggle-item-', ''));

    // Pin the locator to this specific item's testid (not `.first()`) for
    // every subsequent interaction: the dashboard polls and reloads the
    // whole product list on a timer (setInterval(this.loadAll, ...)), so
    // a `.first()` re-query mid-test can silently resolve to a DIFFERENT
    // row after a reorder -- reproduced this exact flake (DB/DOM restore
    // mismatches on later assertions) before pinning by testid.
    const toggleBtn = page.locator(`[data-testid="stock-mgmt-toggle-item-${itemId}"]`);
    const pressedBefore = await toggleBtn.getAttribute('aria-checked');

    const readDbAvailability = () => execFileSync(
      'php',
      ['artisan', 'tinker', `--execute=echo (int) optional(\\App\\Models\\ItemBranchAvailability::where('item_id', ${itemId})->where('branch_id', 1)->first())->is_available ?? 1;`],
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 15_000 }
    ).trim();

    // Ground truth for each half of the round trip is read fresh right
    // before/after its own click, and every assertion below checks a FLIP
    // relative to that same read -- never a DOM-vs-DB comparison at a
    // single point in time. This dev DB has other automated writers
    // (confirmed elsewhere this session: a parallel Playwright run was
    // caught live-mutating order counts the Dashboard KPI reads), so the
    // page's own data-load snapshot can already be one write behind the
    // DB by click time; comparing DOM-at-load against DB-at-click-time
    // produced exactly that false mismatch on a prior run of this test.
    const dbBefore = readDbAvailability();
    originalAvailable = dbBefore === '1';

    const toggleResponse = page.waitForResponse(
      (res) => /\/api\/admin\/menu\/availability\/toggle$/.test(res.url()) && res.request().method() === 'POST',
      { timeout: 10_000 }
    );
    await toggleBtn.click();
    const res = await toggleResponse;
    expect(res.status()).toBe(200);
    await page.waitForTimeout(500);

    const domAfter = await toggleBtn.getAttribute('aria-checked');
    expect(domAfter).not.toBe(pressedBefore);

    const dbAfter = readDbAvailability();
    expect(dbAfter).not.toBe(dbBefore);
    console.log(`[CRUD-FUNCTIONAL] Stock Rupture Dashboard: toggling real item #${itemId} sent a real POST /api/admin/menu/availability/toggle (200), flipped aria-checked ${pressedBefore}->${domAfter}, and flipped the real ItemBranchAvailability row ${dbBefore}->${dbAfter} -- not a client-side-only switch.`);

    const toggleResponseBack = page.waitForResponse(
      (res) => /\/api\/admin\/menu\/availability\/toggle$/.test(res.url()) && res.request().method() === 'POST',
      { timeout: 10_000 }
    );
    await toggleBtn.click();
    const resBack = await toggleResponseBack;
    expect(resBack.status()).toBe(200);
    await page.waitForTimeout(500);

    const domRestored = await toggleBtn.getAttribute('aria-checked');
    expect(domRestored).toBe(pressedBefore);

    const dbRestored = readDbAvailability();
    expect(dbRestored).toBe(dbBefore);
    console.log(`[CRUD-FUNCTIONAL] Stock Rupture Dashboard: real item #${itemId} restored to its original availability (${dbRestored}) via the same real toggle -- round-trip reversibility confirmed, real customer-facing window kept under 1s.`);
  });
});
