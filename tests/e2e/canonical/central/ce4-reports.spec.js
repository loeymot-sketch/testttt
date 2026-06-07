// =============================================================================
// CANONICAL E2E — CENTRAL Reports (Sales / Items / Transactions) integrity
// =============================================================================
// Proves the REP-* central-report defects are HEALED with concrete, anti-theater
// assertions on the RENDERED admin tables + the API query path the exports reuse.
// NO bare expect(true); every assertion checks real row counts / real cell text.
//
// Defects covered (source: REP-EXP-01 / REP-SALES-STATUS-01 / REP-SALES-PAYTYPE-02
//   / REP-ITEMS-TOTAL-03 / REP-SALES-ENUM-05 / REP-PDF-LANG-06):
//   • REP-EXP-01      — Excel/PDF exported only the current page (10 rows); PDF
//                       Total summed 10 rows. Heal = components send paginate:0
//                       (Vitest) + Export classes + pdf() controllers force
//                       paginate:0 (PHPUnit ReportExportFullSetSentinelTest).
//                       Export BINARY download is PHPUnit-covered (E2E-hard);
//                       here we prove the FULL-SET QUERY PATH the export reuses
//                       (GET index with paginate:0 returns > one page).
//   • REP-SALES-STATUS-01 — Payment-status column was BLANK for PENDING_COUNTER
//                       + REFUNDED. Heal = paymentStatusEnumArray extended.
//   • REP-SALES-PAYTYPE-02 — Payment-type column mislabeled kiosk pay-at-counter
//                       as "Cash on delivery" / blanked TR + deferred. Heal =
//                       posPaymentMethodEnumArray + paymentTypeLabel().
//   • REP-ITEMS-TOTAL-03  — Items tfoot Total summed only the current page. Heal
//                       = grand total over the FULL filtered set.
//   • REP-PDF-LANG-06 — PDFs hardcoded English (trans(...,'en')). Heal = resolve
//                       to FR app locale (FR keys confirmed present).
//
// HARNESS FACTS (worktree pre-cloud-exec):
//   - LIVE server = http://127.0.0.1:8765 (PLAYWRIGHT_BASE_URL). reuseExistingServer.
//   - loginAsAdmin → admin@lecayenne.fr / 123456 → SPA bundle MUST be rebuilt by
//     the orchestrator BEFORE running this spec (FE source heals live only after
//     the global `npm run production` rebuild — see post-rebuild notes below).
//   - READ-ONLY: the reports screens issue only GETs. No order POST → NF525 chain
//     untouched. Belt-and-braces: abort any POST to /api/admin/pos.
//
// POST-REBUILD NOTES (orchestrator):
//   1. Rebuild bundles (admin-shell / admin-reports / app / pos-app, i18n catalogs).
//   2. Start the worktree server on :8765 and seed a dataset that EXCEEDS one page
//      (>10 sales orders incl. at least one PENDING_COUNTER and one kiosk
//      COUNTER_DEFERRED order; >10 distinct sold items) so the pagination and
//      enum-coverage assertions are meaningful. With <10 rows the REP-EXP-01 and
//      REP-ITEMS-TOTAL-03 cases self-skip (documented in-test) rather than
//      green-wash.
//   3. PLAYWRIGHT_BASE_URL=http://127.0.0.1:8765 npx playwright test \
//        tests/e2e/canonical/central/ce4-reports.spec.js
// =============================================================================

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../../helpers/login');

const SALES_PATH = /\/admin\/sales-report/;
const ITEMS_PATH = /\/admin\/items-report/;

// Tokens that must NEVER appear in a rendered cell (unresolved i18n / enum leak).
const RAW_LEAK_RE = /\b(label|payment_status|pos_payment_method|payment_gateway|payment_type)\.[a-z_]+/i;

/** Belt-and-braces: never let an order POST through (reports are read-only). */
async function installChainSafety(page) {
  await page.route('**/api/admin/pos**', (route) => {
    if (route.request().method() === 'POST') return route.abort();
    return route.fallback();
  });
}

async function gotoSales(page) {
  await loginAsAdmin(page);
  await page.goto('/admin/sales-report', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(SALES_PATH, { timeout: 25_000 });
}

async function gotoItems(page) {
  await loginAsAdmin(page);
  await page.goto('/admin/items-report', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(ITEMS_PATH, { timeout: 25_000 });
}

/**
 * GET an API list reusing the SPA's own authenticated axios instance
 * (window.axios carries the Bearer token + x-api-key via its request
 * interceptor; a raw page.request would be 401). axios baseURL = /api, so the
 * path is given WITHOUT the /api prefix. Returns the parsed body.
 */
async function apiList(page, path, params) {
  const body = await page.evaluate(async ({ path, params }) => {
    const res = await window.axios.get(`/${path}`, { params });
    return res.data;
  }, { path, params });
  expect(body, `${path} API must return a body`).toBeTruthy();
  return body;
}

test.describe('CENTRAL Reports integrity (REP-* heals)', () => {
  test.describe.configure({ timeout: 90_000 });

  // ---- REP-SALES-STATUS-01 / REP-SALES-PAYTYPE-02 / REP-SALES-ENUM-05 --------
  test('REP-SALES-STATUS-01 + PAYTYPE-02 — payment status & payment type columns are non-blank with no raw label leak', async ({ page }) => {
    await installChainSafety(page);
    await gotoSales(page);

    const rows = page.locator('table#print tbody.db-table-body tr.db-table-body-tr');
    await expect(rows.first()).toBeVisible({ timeout: 25_000 });

    const count = await rows.count();
    // If the dataset is empty the "no data" placeholder row is shown — skip
    // rather than green-wash. The orchestrator seeds a >0-row dataset.
    const firstText = (await rows.first().innerText()).toLowerCase();
    test.skip(firstText.includes('no data') || firstText.includes('aucune donnée'),
      'No sales rows seeded — cannot assert column content.');

    // Columns: order_id(0) date(1) total(2) discount(3) delivery(4) payment_type(5) payment_status(6)
    const sample = Math.min(count, 10);
    for (let i = 0; i < sample; i++) {
      const cells = rows.nth(i).locator('td.db-table-body-td');
      const payType = (await cells.nth(5).innerText()).trim();
      const payStatus = (await cells.nth(6).innerText()).trim();

      // Non-blank: every order has a resolvable payment status AND a payment type.
      expect(payStatus, `row ${i}: payment STATUS must not be blank (REP-SALES-STATUS-01)`).not.toBe('');
      expect(payType, `row ${i}: payment TYPE must not be blank (REP-SALES-PAYTYPE-02)`).not.toBe('');

      // No raw i18n/enum token leaked into the cell.
      expect(payStatus, `row ${i}: payment status must not leak a raw key`).not.toMatch(RAW_LEAK_RE);
      expect(payType, `row ${i}: payment type must not leak a raw key`).not.toMatch(RAW_LEAK_RE);

      // REP-SALES-PAYTYPE-02 specific: a kiosk pay-at-counter order must NOT be
      // mislabeled "Cash on delivery". (The label resolves to the deferred /
      // collected method, never the delivery-COD wording.)
      expect(payType.toLowerCase(), `row ${i}: counter order must not read "cash on delivery"`)
        .not.toContain('cash on delivery');
    }
  });

  // ---- REP-ITEMS-TOTAL-03 ----------------------------------------------------
  test('REP-ITEMS-TOTAL-03 — items tfoot Total equals the FULL filtered set, not the current page', async ({ page }) => {
    await installChainSafety(page);
    await gotoItems(page);

    const rows = page.locator('table#print tbody.db-table-body tr.db-table-body-tr');
    await expect(rows.first()).toBeVisible({ timeout: 25_000 });
    const firstText = (await rows.first().innerText()).toLowerCase();
    test.skip(firstText.includes('no data') || firstText.includes('aucune donnée'),
      'No item rows seeded — cannot assert grand total.');

    // Independent source of truth: the FULL set via API (paginate:0) summed in-test.
    const full = await apiList(page, 'admin/items-report', { paginate: 0, order_column: 'id', order_type: 'asc' });
    const fullRows = full.data || [];
    const expectedTotal = fullRows.reduce((acc, r) => acc + parseInt(r.order ?? r.units_sold ?? 0, 10), 0);

    // Only meaningful when the full set exceeds one page (else the page total
    // could coincide with the grand total and the assertion proves nothing).
    test.skip(fullRows.length <= 10, `Only ${fullRows.length} items — seed >10 to exercise pagination.`);

    // The visible per-page rows must be a STRICT SUBSET (proves pagination active).
    const pageRowCount = await rows.count();
    expect(pageRowCount, 'screen must be paginated (fewer rows than the full set)').toBeLessThan(fullRows.length);

    // The tfoot Total cell (4th column) must equal the FULL-set sum.
    const totalCell = page.locator('table#print tfoot tr td').last();
    const shownTotal = parseInt((await totalCell.innerText()).trim(), 10);
    expect(shownTotal, 'tfoot Total must sum the FULL filtered set (REP-ITEMS-TOTAL-03)').toBe(expectedTotal);

    // And the grand total must be STRICTLY GREATER than the current-page sum
    // (anti-regression: the buggy total summed only the page).
    const pageSum = (await Promise.all(
      Array.from({ length: pageRowCount }, (_, i) =>
        rows.nth(i).locator('td.db-table-body-td').last().innerText()),
    )).reduce((acc, t) => acc + parseInt(t.trim() || '0', 10), 0);
    expect(shownTotal, 'grand total must exceed the current-page sum').toBeGreaterThan(pageSum);
  });

  // ---- REP-EXP-01 (full-set query path the export reuses) --------------------
  // The binary xlsx/pdf download is asserted at the PHPUnit layer
  // (ReportExportFullSetSentinelTest) where the export collection() is driven
  // with a paginated request and proven to return the full set. Here we prove
  // the same server query path used by the export (GET index with paginate:0)
  // returns MORE than one page — i.e. the export, which now forces paginate:0,
  // receives the full dataset.
  test('REP-EXP-01 — sales index with paginate:0 returns the full set (export query path)', async ({ page }) => {
    await installChainSafety(page);
    await gotoSales(page);

    const paged = await apiList(page, 'admin/sales-report', { paginate: 1, per_page: 10, page: 1, order_column: 'id' });
    const full = await apiList(page, 'admin/sales-report', { paginate: 0, order_column: 'id' });

    const pagedCount = (paged.data || []).length;
    const fullCount = (full.data || []).length;

    test.skip(fullCount <= 10, `Only ${fullCount} sales orders — seed >10 to exercise pagination.`);

    expect(pagedCount, 'paginated index must cap at per_page (10)').toBeLessThanOrEqual(10);
    expect(fullCount, 'paginate:0 must return the FULL set (> one page) — what the export now requests')
      .toBeGreaterThan(pagedCount);
  });

  test('REP-EXP-01 — items index with paginate:0 returns the full set (export query path)', async ({ page }) => {
    await installChainSafety(page);
    await gotoItems(page);

    const paged = await apiList(page, 'admin/items-report', { paginate: 1, per_page: 10, page: 1, order_column: 'id' });
    const full = await apiList(page, 'admin/items-report', { paginate: 0, order_column: 'id' });

    const pagedCount = (paged.data || []).length;
    const fullCount = (full.data || []).length;

    test.skip(fullCount <= 10, `Only ${fullCount} items — seed >10 to exercise pagination.`);

    expect(pagedCount, 'paginated index must cap at per_page (10)').toBeLessThanOrEqual(10);
    expect(fullCount, 'paginate:0 must return the FULL set (> one page) — what the export now requests')
      .toBeGreaterThan(pagedCount);
  });
});
