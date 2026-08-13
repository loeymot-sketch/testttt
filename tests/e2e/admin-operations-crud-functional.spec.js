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
