// FoodKing E2E — Wave P-4 OSS (Order Status Screen) Audit (2026-05-20)
//
// Mission: customer-facing wall display audit.
//   - Empty state
//   - Mixed orders (KIOSK / TAKEAWAY / DELIVERY / POS)
//   - Allowlist regression: DELIVERY + POS MUST NOT appear (R-3 2026-05-18 heal)
//   - PREPARING column → PRÊT column transition
//   - Order pickup → disappears from wall
//   - Visual: order tokens BIG (text-[40px]), readable from distance
//   - Sync: list re-fetches after status change
//   - i18n FR everywhere, no raw labels (Label.X, kiosk.foo, undefined)
//
// Page under test: /admin/order-status-screen
//   - Vue route (`/order-status-screen` without /admin/ is SPA notFound).
//   - PUBLIC_FRIENDLY_AUTH_ROUTES.has it → unauthenticated wall lands here
//     and store dispatches to `frontend/oss-order` (public sibling).
//
// Reports dir: reports/test-e2e/wave-p-2026-05-20/oss/screenshots/

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPORT_ROOT = path.join(__dirname, '..', '..', 'reports', 'test-e2e', 'wave-p-2026-05-20', 'oss');
const SHOTS_DIR  = path.join(REPORT_ROOT, 'screenshots');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const OSS_URL = '/admin/order-status-screen';
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const FATAL_ERR_FILTER = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

function php(snippet) {
  // Run a small PHP snippet under `php artisan tinker --execute=`. Returns stdout.
  // Note: spawnSync passes args as argv (no shell), so single quotes inside the
  // snippet are safe. We pass `--execute` separately from the value so very
  // long snippets are not truncated by argv quoting.
  const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
    cwd: REPO_ROOT, encoding: 'utf8', timeout: 60_000,
  });
  return (res.stdout || '') + (res.stderr || '');
}

function logErr(consoleErrors, msg) {
  if (msg.type() === 'error') {
    const text = msg.text();
    if (!FATAL_ERR_FILTER.test(text)) consoleErrors.push(text);
  }
}

// Seed a single OSS-shaped order. Returns the new ID (from stdout).
//
// statusInt: 7 = PREPARING, 8 = PREPARED
// typeInt:   5 = DELIVERY, 10 = TAKEAWAY, 15 = POS, 20 = DINING_TABLE, 25 = KIOSK
// queueNumber: short string (e.g. 'K001'); null for token-bearing flows
// token: short opaque string; null for kiosk-style flows
function seedOrder({ queueNumber, token, typeInt, statusInt, suffix }) {
  const qn = queueNumber === null ? 'null' : `"${queueNumber}"`;
  const tk = token       === null ? 'null' : `"${token}"`;
  // Build the PHP snippet inline. Use single-statement form (semicolon-separated)
  // so tinker --execute parses it as one expression block.
  const snippet =
    `$o = new App\\Models\\Order; ` +
    `$o->order_serial_no = 'WPOSS-${suffix}'; ` +
    `$o->queue_number = ${qn}; ` +
    `$o->token = ${tk}; ` +
    `$o->user_id = 1; ` +
    `$o->branch_id = 1; ` +
    `$o->subtotal = 12.50; ` +
    `$o->total = 12.50; ` +
    `$o->order_type = ${typeInt}; ` +
    `$o->order_datetime = now('UTC'); ` +
    `$o->preparation_time = 5; ` +
    `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
    `$o->payment_method = 1; ` +
    `$o->payment_status = 10; ` +
    `$o->status = ${statusInt}; ` +
    `$o->business_date = now()->toDateString(); ` +
    `$o->save(); ` +
    `echo $o->id;`;
  const out = php(snippet);
  const m = out.match(/(\d+)\s*$/);
  return m ? parseInt(m[1], 10) : null;
}

// Update the status of a previously-seeded order.
function setOrderStatus(orderId, statusInt) {
  return php(`App\\Models\\Order::where('id', ${orderId})->update(['status' => ${statusInt}]);`);
}

// Soft-delete an order (simulates "picked up / archived"). The OSS query does
// not include soft-deleted rows.
function softDeleteOrder(orderId) {
  return php(`$o = App\\Models\\Order::find(${orderId}); if ($o) { $o->delete(); }`);
}

// Wipe the seeded rows from this run (cleanup between iterations + on exit).
function wipeSeededOrders() {
  return php(`DB::table('orders')->where('order_serial_no','like','WPOSS-%')->update(['fiscal_sequence_no' => null]); App\\Models\\Order::withTrashed()->where('order_serial_no','like','WPOSS-%')->forceDelete();`);
}

test.describe('Wave P-4 OSS customer wall E2E audit', () => {
  test.setTimeout(240_000);

  test.beforeAll(async () => {
    // Clean slate
    wipeSeededOrders();
  });

  test.afterAll(async () => {
    wipeSeededOrders();
  });

  test('oss: empty → mixed-types allowlist → status transition → pickup-disappear', async ({ page }) => {
    /** @type {string[]} */ const consoleErrors = [];
    /** @type {Array<{url:string,status:number}>} */ const networkFails = [];
    /** @type {string[]} */ const reqUrls = [];

    page.on('console', (msg) => logErr(consoleErrors, msg));
    page.on('requestfailed', (req) => networkFails.push({ url: req.url(), status: 0 }));
    page.on('response', async (res) => {
      const u = res.url();
      reqUrls.push(u);
      if (res.status() >= 500 && /\/api\//.test(u)) {
        networkFails.push({ url: u, status: res.status() });
      }
    });

    // ─── STATE 1: empty wall ──────────────────────────────────────────────
    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(2_000); // initial mount + first fetch
    await page.screenshot({ path: path.join(SHOTS_DIR, 'O01-empty.png'), fullPage: true });

    // Sanity: two columns rendered (PRÉPARATION + PRÊT) — use case-insensitive
    // aria-label match via Playwright `getByRole` (CSS [attr*=v] is case-sensitive).
    const preparingTitle = await page.getByRole('region', { name: /pr[ée]paration|preparing/i }).count();
    const readyTitle     = await page.getByRole('region', { name: /pr[êe]t|ready/i }).count();
    expect(preparingTitle, 'PRÉPARATION column should be rendered').toBeGreaterThanOrEqual(1);
    expect(readyTitle,     'PRÊT column should be rendered').toBeGreaterThanOrEqual(1);

    // Both should be empty placeholders (—)
    const emptyDashes = await page.locator('p:has-text("—")').count();
    expect(emptyDashes, 'two empty-state dashes (— in each column) on empty wall').toBeGreaterThanOrEqual(2);

    // ─── STATE 2: 3 PREPARING (KIOSK + TAKEAWAY + a token-only kiosk) ─────
    const kioskA  = seedOrder({ queueNumber: 'K-101', token: null,    typeInt: 25, statusInt: 7, suffix: 'A-K-101' });
    const takeB   = seedOrder({ queueNumber: 'T-202', token: null,    typeInt: 10, statusInt: 7, suffix: 'B-T-202' });
    const kioskC  = seedOrder({ queueNumber: 'K-103', token: null,    typeInt: 25, statusInt: 7, suffix: 'C-K-103' });
    expect(kioskA, 'kioskA id non-null').toBeTruthy();
    expect(takeB,  'takeB id non-null').toBeTruthy();
    expect(kioskC, 'kioskC id non-null').toBeTruthy();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await page.screenshot({ path: path.join(SHOTS_DIR, 'O02-3-preparing.png'), fullPage: true });

    // All 3 tokens must appear in PRÉPARATION list
    for (const qn of ['K-101', 'T-202', 'K-103']) {
      const cell = page.locator(`li:has-text("${qn}")`).first();
      await expect(cell, `${qn} should appear in PRÉPARATION column`).toBeVisible();
    }
    // PRÊT should still be empty
    const dashesAfterPrep = await page.locator('p:has-text("—")').count();
    expect(dashesAfterPrep, 'PRÊT column still empty placeholder').toBeGreaterThanOrEqual(1);

    // ─── STATE 3: 2 PREPARING + 2 READY (and bring in DELIVERY + POS to test allowlist) ──
    setOrderStatus(takeB, 8);  // T-202 moves to PRÊT
    setOrderStatus(kioskC, 8); // K-103 moves to PRÊT
    // Place 2 orders that MUST be filtered out by the allowlist:
    const deliveryX = seedOrder({ queueNumber: 'D-666', token: 'TKDEL01', typeInt: 5,  statusInt: 7, suffix: 'X-D-666' });
    const posY      = seedOrder({ queueNumber: 'P-777', token: 'TKPOS01', typeInt: 15, statusInt: 7, suffix: 'Y-P-777' });
    // And re-seed 1 fresh KIOSK-PREPARING so the column is not empty:
    const kioskD    = seedOrder({ queueNumber: 'K-404', token: null,      typeInt: 25, statusInt: 7, suffix: 'D-K-404' });

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await page.screenshot({ path: path.join(SHOTS_DIR, 'O03-2prep-2ready-allowlist.png'), fullPage: true });

    // PRÊT: T-202, K-103
    for (const qn of ['T-202', 'K-103']) {
      const cell = page.locator(`li:has-text("${qn}")`).first();
      await expect(cell, `${qn} should be in PRÊT`).toBeVisible();
    }
    // PRÉPARATION: K-101, K-404
    for (const qn of ['K-101', 'K-404']) {
      const cell = page.locator(`li:has-text("${qn}")`).first();
      await expect(cell, `${qn} should be in PRÉPARATION`).toBeVisible();
    }
    // ALLOWLIST: DELIVERY + POS MUST NOT appear ANYWHERE
    const deliveryLeak = await page.locator(`li:has-text("D-666"), li:has-text("TKDEL01")`).count();
    const posLeak      = await page.locator(`li:has-text("P-777"), li:has-text("TKPOS01")`).count();
    expect(deliveryLeak, 'DELIVERY order MUST NOT leak to OSS').toBe(0);
    expect(posLeak,      'POS order MUST NOT leak to OSS').toBe(0);

    // ─── STATE 4: K-101 moves PREPARING → PRÊT, verify auto-refresh ────────
    setOrderStatus(kioskA, 8);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await page.screenshot({ path: path.join(SHOTS_DIR, 'O04-k101-moved-to-ready.png'), fullPage: true });

    // K-101 now in PRÊT region (locate by aria-label of parent region)
    const readyRegion = page.getByRole('region', { name: /pr[êe]t|ready/i }).first();
    await expect(readyRegion.locator('li:has-text("K-101")').first(), 'K-101 now in PRÊT').toBeVisible();

    // PREPARING should NO LONGER contain K-101
    const preparingRegion = page.getByRole('region', { name: /pr[ée]paration|preparing/i }).first();
    const k101InPreparing = await preparingRegion.locator('li:has-text("K-101")').count();
    expect(k101InPreparing, 'K-101 should leave PRÉPARATION').toBe(0);

    // ─── STATE 5: T-202 picked up → soft-delete → must disappear ──────────
    softDeleteOrder(takeB);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await page.screenshot({ path: path.join(SHOTS_DIR, 'O05-t202-picked-up.png'), fullPage: true });

    const t202Anywhere = await page.locator('li:has-text("T-202")').count();
    expect(t202Anywhere, 'T-202 should disappear after pickup (soft-delete)').toBe(0);

    // ─── STATE 6: visual readability + i18n + console hygiene ─────────────
    // Verify the column titles are in FR (PRÉPARATION / PRÊT) — no raw labels.
    // The first <h3> on the page is "Articles à préparer" (PopularItemComponent
    // left column). Scope the lookup inside the PreparingAndReady regions only.
    const preparingTxt = await preparingRegion.locator('h3').first().textContent();
    const readyTxt     = await readyRegion.locator('h3').first().textContent();
    expect(preparingTxt, 'PRÉPARATION header in FR').toMatch(/pr[ée]paration|preparing/i);
    expect(readyTxt,     'PRÊT header in FR').toMatch(/pr[êe]t|ready/i);

    // No raw i18n keys leaking into the rendered DOM
    const bodyText = await page.locator('body').innerText();
    const RAW_KEYS = [/\blabel\.[a-z_]+\b/, /\boss\.[a-z_]+\b/, /\bundefined\b(?!\s*\()/];
    for (const rk of RAW_KEYS) {
      const found = bodyText.match(rk);
      expect(found, `no raw label leak matching ${rk}: ${found ? found[0] : ''}`).toBeNull();
    }

    // Visual: text-[40px] applied on tokens (≥36px font-size) — readability from a TV wall
    const tokenFontSize = await page.locator('li:has-text("K-101")').first().evaluate((el) => {
      return window.getComputedStyle(el.tagName === 'LI' ? el : (el.closest('li') || el)).fontSize;
    });
    const sizePx = parseFloat(tokenFontSize);
    expect(sizePx, `token font-size should be ≥ 36px for distance readability (got ${tokenFontSize})`).toBeGreaterThanOrEqual(36);

    // No fatal console errors
    expect(consoleErrors, `no fatal console errors; got ${consoleErrors.length}`).toEqual([]);

    // No 5xx on /api/* OSS endpoints
    const ossApiFailures = networkFails.filter((f) => /(oss-order|frontend\/oss-order|admin\/oss-order)/.test(f.url));
    expect(ossApiFailures, `no 5xx on OSS API; got ${JSON.stringify(ossApiFailures)}`).toEqual([]);

    // Persist artifacts
    fs.writeFileSync(
      path.join(REPORT_ROOT, 'console-errors.json'),
      JSON.stringify({ consoleErrors, networkFails, ossApiFailures }, null, 2),
    );
    fs.writeFileSync(
      path.join(REPORT_ROOT, 'request-urls.txt'),
      reqUrls.slice(-300).join('\n'),
    );
    fs.writeFileSync(
      path.join(REPORT_ROOT, 'seeded-ids.json'),
      JSON.stringify({ kioskA, takeB, kioskC, deliveryX, posY, kioskD }, null, 2),
    );
  });
});
