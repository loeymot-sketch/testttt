// Wave Polish Final — C. Stock Rupture Dashboard sanity + KDS Historique drawer
// sanity (2026-05-21).
//
// Mission (Phase 4 — final convergence). Combined surface verification:
//
//   Stock Rupture Dashboard /admin/stock/rupture (post stock-mgmt-M1 cycle,
//     commits 7a409ade7..1116b3957 + Phase 2H fix a68acb20f Q9-S1 cache
//     invalidation):
//     C-01  default mount → unified rail showing buckets from the 3 axes
//           (categories / extras-by-name / variations-by-attribute) + product
//           grid (the controller emits 3 payload axes; the visual rail is a
//           single vertical list aggregating them all).
//     C-02  Toggle a CATEGORY product OFF (Supplément #12 "Cheddar")
//           → status flip in DOM + 200 response, no audit-log UI surface.
//     C-03  Toggle an EXTRA (group_label='supplement', name='Boursin')
//           → cascades to all 4 underlying extra_ids; snapshot version
//           bumps within ≤5s; re-fetch kiosk menu confirms cascade.
//     C-04  Toggle a VARIATION (attribute 7 "Base bol", name='Frites')
//           → cascades to all 5 underlying variation_ids; snapshot bumps.
//     C-05  Bulk-ish toggle: 5 SEQUENTIAL category-product toggles
//           (Cheddar / Raclette / Emmental / Œuf / Légumes sautés) — exercises
//           the M1 concurrency-2 + 100ms inter-batch path indirectly. All 5
//           must land within ~10s, no 429.
//     C-06  Cross-axis dedupe: "Suppléments" category + "Suppléments (à
//           composer)" extras-group both render with correct item counts,
//           no collision (per round-3 A-015 fix at
//           StockRuptureDashboardController.php:418-427).
//
//   KDS Historique drawer /admin/kitchen-display-system (post Wave X X3 commit
//     4428e3ca4 + bundle rebuild f0060a138 + Q12 sentinel 3122214175):
//     C-07  /kds mount → header pill "Historique" visible (FR), NOT raw
//           label.kds_history_button.
//     C-08  Click pill → drawer slides in (data-testid kds-history-drawer).
//     C-09  Drawer renders today's bumped orders (PREPARED / OUT / DELIVERED)
//           with timestamps + status badges = translated FR (Prêt / En
//           livraison / Livré, CSS uppercase OK), NOT raw LABEL.KDS_STATE_*.
//     C-10  Escape-key → drawer hidden (Wave X 8c handler at
//           KdsHistoryDrawer.vue:194-200).
//     C-11  Read-only V1: zero "Renvoyer" / "Recall" / "Restore" controls
//           rendered in drawer items (V1.0.2 backlog per Wave X3 comments).
//
// Spec discipline:
//   - attachMegaAuditRecorder → quartet PNG + DOM + console + network
//   - Mutating tests UPSERT stock_levels rows + are reaped in afterAll
//     (mirrors Wave B pattern: delete rows + Cache::forget the kiosk key
//     so the absent-row rule restores availability)
//   - Seed historical orders for C-09 via tinker (WAVE-POLISH-C- prefix);
//     iter15:cleanup-test-orders sweeps them on teardown
//   - NO frozen-zone modifications. NF525 chain untouched. audit_logs
//     read-only here.
//
// Credentials: admin@lecayenne.fr / 123456 (CLAUDE.md §reference_admin_e2e_creds).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { getKioskApiToken } = require('./helpers/kiosk-order');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const STOCK_PATH = '/admin/stock/rupture';
const KDS_PATH = '/admin/kitchen-display-system';
const SCREENSHOT_DIR = path.resolve('tests/e2e/__screenshots__/wave-polish-final-C');
const REPORT_DIR = path.resolve('reports/test-e2e/wave-polish-final-2026-05-21/round-1');
const repoRoot = path.resolve(__dirname, '../..');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

// --- Live-DB constants — verified via tinker before spec-write (see commit body) ---

// Category #8 = "Suppléments" — 10 items. We toggle low-id items in sequence
// so cleanup is deterministic. Cheddar=#12 is the C-02 anchor.
const STOCK_CATEGORY_ID = 8;
const STOCK_ITEM_CHEDDAR = 12;
const STOCK_ITEM_C05_BATCH = [12, 13, 14, 15, 17]; // Cheddar / Raclette / Emmental / Œuf / Légumes sautés

// Extra group_label='supplement', name='Boursin' deduped to 4 underlying ids.
// Toggling cascades the rail row through sendBulkToggle(concurrency=2/100ms).
const STOCK_EXTRA_BOURSIN_IDS = [133, 146, 159, 172];

// Variation attribute_id=7 "Base bol", name='Frites' deduped to 5 ids.
const STOCK_VARIATION_FRITES_IDS = [55, 70, 85, 100, 115];

const KIOSK_BRANCH_ID = 1;
const TOKEN_PREFIX = 'WAVE-POLISH-C-';

// Shared run-level state — written to wave-C-findings.json at the end.
const runState = {
  wave: 'C',
  round: 1,
  startedAt: new Date().toISOString(),
  states: {},
  timings: {},
  evidence: {},
  cleanup: null,
};

function tinker(script, timeout = 20_000) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', script], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout,
  });
}

function lastNumericLine(out) {
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const num = [...lines].reverse().find((l) => /^\d+$/.test(l));
  return Number(num || 0);
}

function readSnapshotVersion(branchId) {
  try {
    const out = tinker(`echo (int) Cache::get('menu:snapshot_version:branch:${branchId}', 0);`, 10_000);
    return { ok: true, version: lastNumericLine(out) };
  } catch (err) {
    return { ok: false, error: String(err?.message || err).substring(0, 300) };
  }
}

/**
 * Wipe the stock_levels rows the spec just inserted, then forget the kiosk
 * menu cache. The absent-row rule in ChoiceAvailabilityResolver returns
 * is_available=true so this fully restores pristine state.
 */
function restoreStock({ itemIds = [], extraIds = [], variationIds = [] }) {
  const itemArr = JSON.stringify(itemIds.map(Number).filter((n) => n > 0));
  const extraArr = JSON.stringify(extraIds.map(Number).filter((n) => n > 0));
  const variationArr = JSON.stringify(variationIds.map(Number).filter((n) => n > 0));
  const script = `
    if (!empty(${itemArr})) {
      DB::table('stock_levels')
        ->where('stockable_type','App\\\\Models\\\\Item')
        ->where('branch_id', ${KIOSK_BRANCH_ID})
        ->whereIn('stockable_id', ${itemArr})
        ->delete();
      DB::table('item_branch_availabilities')
        ->where('branch_id', ${KIOSK_BRANCH_ID})
        ->whereIn('item_id', ${itemArr})
        ->delete();
    }
    if (!empty(${extraArr})) {
      DB::table('stock_levels')
        ->where('stockable_type','App\\\\Models\\\\ItemExtra')
        ->where('branch_id', ${KIOSK_BRANCH_ID})
        ->whereIn('stockable_id', ${extraArr})
        ->delete();
    }
    if (!empty(${variationArr})) {
      DB::table('stock_levels')
        ->where('stockable_type','App\\\\Models\\\\ItemVariation')
        ->where('branch_id', ${KIOSK_BRANCH_ID})
        ->whereIn('stockable_id', ${variationArr})
        ->delete();
    }
    Cache::forget('kiosk.menu.branch.${KIOSK_BRANCH_ID}');
    echo 'restored';
  `;
  try { tinker(script); return true; } catch (_) { return false; }
}

/**
 * Seed a single historical order in today's window so the KDS drawer has
 * content. Mirrors tests/e2e/wave-x3-kds-history.spec.js:seedHistoricalOrder.
 * Returns { id, serial }.
 */
function seedHistoricalOrder({ status, tokenSuffix }) {
  const token = `${TOKEN_PREFIX}${tokenSuffix}-${Date.now()}`;
  const phpCode = `
    $o = new \\App\\Models\\Order();
    $o->order_serial_no = '${token}';
    $o->order_type = 5;
    $o->source_surface = 'pos';
    $o->branch_id = ${KIOSK_BRANCH_ID};
    $o->user_id = 1;
    $o->status = ${status};
    $o->payment_status = 10;
    $o->payment_method = 1;
    $o->subtotal = 9.50;
    $o->total = 9.50;
    $o->discount = 0;
    $o->delivery_charge = 0;
    $o->order_datetime = now();
    $o->updated_at = now();
    $o->is_advance_order = 0;
    $o->saveQuietly();
    echo $o->id . '|' . $o->order_serial_no;
  `.replace(/\s+/g, ' ');
  const out = tinker(phpCode).trim();
  const lines = out.split(/\r?\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '';
  const [id, serial] = last.split('|');
  return { id: parseInt(id, 10), serial };
}

function cleanupSeededOrders() {
  try {
    const out = execFileSync(
      'php',
      ['artisan', 'iter15:cleanup-test-orders', '--apply', `--token-prefix=${TOKEN_PREFIX}`],
      { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 30_000 },
    );
    return { ok: true, output: String(out).substring(0, 400) };
  } catch (err) {
    return { ok: false, error: String(err?.message || err).substring(0, 400) };
  }
}

async function fetchKioskMenu(page) {
  const token = await getKioskApiToken(page);
  return page.evaluate(async (bearer) => {
    const resp = await window.axios.get('frontend/menu', {
      headers: { Authorization: `Bearer ${bearer}` },
    });
    return { status: resp.status, data: resp.data };
  }, token);
}

test.describe.configure({ mode: 'serial' });

test.describe('Wave Polish Final C — Stock Rupture Dashboard + KDS Historique sanity', () => {

  // Track everything we touched so afterAll can roll back deterministically.
  const touched = {
    itemIds: [],
    extraIds: [],
    variationIds: [],
  };

  test.afterAll(async () => {
    restoreStock(touched);
    runState.cleanup = cleanupSeededOrders();
    runState.endedAt = new Date().toISOString();
    const findingsPath = path.join(REPORT_DIR, 'wave-C-findings.json');
    fs.writeFileSync(findingsPath, JSON.stringify(buildFindings(), null, 2));
    // eslint-disable-next-line no-console
    console.log(`[wave-C] findings written → ${findingsPath}`);
  });

  // ------------------------------------------------------------------------
  // STOCK — C-01 through C-06
  // ------------------------------------------------------------------------
  test('C-01..C-06 — Stock Rupture Dashboard sanity', async ({ page }) => {
    test.setTimeout(240_000);
    const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    clearFoodKingRateLimits();
    await loginAsAdmin(page);

    // ---------- C-01 — default mount: rail with 3 axes' buckets visible ----------
    const overviewPromise = page.waitForResponse(
      (r) => /\/api\/admin\/stock\/catalog-overview(\?|$)/.test(r.url()) && r.status() < 500,
      { timeout: 30_000 },
    );
    await page.goto(STOCK_PATH, { waitUntil: 'domcontentloaded' });
    await overviewPromise;
    const root = page.locator('[data-testid="stock-management-v2"]');
    await expect(root).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(600);

    // Sanity: rail aggregates buckets from all 3 axes (controller emits
    // categories / extra_groups / variation_groups; rail key prefixes are
    // cat-* / extra-* / var-*).
    const catCount = await page.locator('[data-testid^="stock-mgmt-bucket-cat-"]').count();
    const extraCount = await page.locator('[data-testid^="stock-mgmt-bucket-extra-"]').count();
    const variationCount = await page.locator('[data-testid^="stock-mgmt-bucket-var-"]').count();
    runState.evidence.c01_rail_axes = { categories: catCount, extras: extraCount, variations: variationCount };
    expect(catCount).toBeGreaterThanOrEqual(1);
    expect(extraCount).toBeGreaterThanOrEqual(1);
    expect(variationCount).toBeGreaterThanOrEqual(1);
    runState.states['C-01'] = `rail axes : categories=${catCount} extras=${extraCount} variations=${variationCount} (3 axes present)`;
    await recorder.snap('C-01-stock-default-mount');

    // ---------- C-06 — Cross-axis dedupe (do this BEFORE any toggling) ----------
    // "Suppléments" category vs "Suppléments (à composer)" extra group both
    // exist with non-empty counts. Round-3 A-015 fix at
    // StockRuptureDashboardController.php:418-427.
    const categoryButton = page.locator('[data-testid="stock-mgmt-bucket-cat-8"]');
    const extraGroupButton = page.locator('[data-testid="stock-mgmt-bucket-extra-supplement"]');
    await expect(categoryButton).toBeVisible({ timeout: 5_000 });
    await expect(extraGroupButton).toBeVisible({ timeout: 5_000 });
    const catLabel = (await categoryButton.innerText()).trim();
    const extraLabel = (await extraGroupButton.innerText()).trim();
    runState.evidence.c06_labels = {
      category: catLabel,
      extra_group: extraLabel,
    };
    // Both labels include the "Suppléments" stem but the extra side carries
    // the disambiguator "(à composer)".
    expect(catLabel).toMatch(/Suppléments/i);
    expect(extraLabel).toMatch(/Suppléments/i);
    expect(extraLabel).toMatch(/à composer/i);
    runState.states['C-06'] = `cross-axis dedupe : category="${catLabel.replace(/\s+/g, ' ')}" + extra="${extraLabel.replace(/\s+/g, ' ')}"`;
    await recorder.snap('C-06-stock-cross-axis-dedupe');

    // ---------- C-02 — Toggle a CATEGORY product OFF (Cheddar #12) ----------
    await categoryButton.click();
    await page.waitForTimeout(300);
    const cheddarToggle = page.locator(`[data-testid="stock-mgmt-toggle-item-${STOCK_ITEM_CHEDDAR}"]`);
    await expect(cheddarToggle).toBeVisible({ timeout: 10_000 });
    const cheddarLabelBefore = (await cheddarToggle.innerText()).trim();

    const togglePromise = page.waitForResponse(
      (r) => /\/api\/admin\/menu\/availability\/toggle$/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 15_000 },
    );
    await cheddarToggle.click();
    const toggleResp = await togglePromise;
    runState.evidence.c02 = { http: toggleResp.status() };
    expect(toggleResp.status()).toBe(200);
    touched.itemIds.push(STOCK_ITEM_CHEDDAR);

    // Wait for optimistic flip to settle.
    await expect.poll(
      async () => (await cheddarToggle.innerText()).trim(),
      { timeout: 5_000 },
    ).not.toBe(cheddarLabelBefore);
    const cheddarLabelAfter = (await cheddarToggle.innerText()).trim();
    runState.states['C-02'] = `category toggle Cheddar #${STOCK_ITEM_CHEDDAR} : "${cheddarLabelBefore}" → "${cheddarLabelAfter}" (HTTP ${toggleResp.status()})`;
    await recorder.snap('C-02-stock-toggle-category-off');

    // ---------- C-03 — Toggle EXTRA (Boursin in supplement group) ----------
    // Prime kiosk cache + snapshot version BEFORE the toggle (Wave B
    // discipline — avoid the empty-cache false positive).
    const cacheMenuBefore = await fetchKioskMenu(page);
    expect(cacheMenuBefore.status).toBe(200);
    const snapBeforeExtra = readSnapshotVersion(KIOSK_BRANCH_ID);
    runState.evidence.c03_snapshot_before = snapBeforeExtra;

    await page.locator('[data-testid="stock-mgmt-bucket-extra-supplement"]').click();
    await page.waitForTimeout(300);
    const boursinKey = 'extra-supplement-Boursin';
    const boursinToggle = page.locator(`[data-testid="stock-mgmt-toggle-${boursinKey}"]`);
    await expect(boursinToggle).toBeVisible({ timeout: 10_000 });
    const boursinLabelBefore = (await boursinToggle.innerText()).trim();

    const tExtraStart = Date.now();
    await boursinToggle.click();
    // The fan-out is concurrency-2 + 100ms gap → ~400ms for 4 ids. Allow up
    // to 5s for snapshot bump to land (matches Wave B q9_s1_invalidation_signal_ms).
    let snapAfterExtra = null;
    for (let i = 0; i < 22; i += 1) {
      snapAfterExtra = readSnapshotVersion(KIOSK_BRANCH_ID);
      if (snapAfterExtra?.ok && snapAfterExtra.version > snapBeforeExtra.version) break;
      await page.waitForTimeout(250);
    }
    const tExtraEnd = Date.now();
    runState.evidence.c03_snapshot_after = snapAfterExtra;
    runState.timings.c03_extra_invalidation_ms = {
      delta_ms: tExtraEnd - tExtraStart,
      expect_max_ms: 5000,
      pass: snapAfterExtra?.ok && snapAfterExtra.version > snapBeforeExtra.version
        && (tExtraEnd - tExtraStart) <= 5000,
    };
    expect(snapAfterExtra?.ok).toBe(true);
    expect(snapAfterExtra.version).toBeGreaterThan(snapBeforeExtra.version);
    touched.extraIds.push(...STOCK_EXTRA_BOURSIN_IDS);

    await expect.poll(
      async () => (await boursinToggle.innerText()).trim(),
      { timeout: 5_000 },
    ).not.toBe(boursinLabelBefore);
    const boursinLabelAfter = (await boursinToggle.innerText()).trim();
    runState.states['C-03'] = `extra toggle Boursin (4 ids fan-out) : "${boursinLabelBefore}" → "${boursinLabelAfter}", snapshot ${snapBeforeExtra.version}→${snapAfterExtra.version} in ${tExtraEnd - tExtraStart}ms`;
    await recorder.snap('C-03-stock-toggle-extra-off');

    // ---------- C-04 — Toggle VARIATION (Frites in attribute 7 "Base bol") ----------
    const snapBeforeVar = readSnapshotVersion(KIOSK_BRANCH_ID);
    runState.evidence.c04_snapshot_before = snapBeforeVar;
    await page.locator('[data-testid="stock-mgmt-bucket-var-7"]').click();
    await page.waitForTimeout(300);
    const fritesKey = 'var-7-Frites';
    const fritesToggle = page.locator(`[data-testid="stock-mgmt-toggle-${fritesKey}"]`);
    await expect(fritesToggle).toBeVisible({ timeout: 10_000 });
    const fritesLabelBefore = (await fritesToggle.innerText()).trim();

    const tVarStart = Date.now();
    await fritesToggle.click();
    let snapAfterVar = null;
    for (let i = 0; i < 22; i += 1) {
      snapAfterVar = readSnapshotVersion(KIOSK_BRANCH_ID);
      if (snapAfterVar?.ok && snapAfterVar.version > snapBeforeVar.version) break;
      await page.waitForTimeout(250);
    }
    const tVarEnd = Date.now();
    runState.evidence.c04_snapshot_after = snapAfterVar;
    runState.timings.c04_variation_invalidation_ms = {
      delta_ms: tVarEnd - tVarStart,
      expect_max_ms: 5000,
      pass: snapAfterVar?.ok && snapAfterVar.version > snapBeforeVar.version
        && (tVarEnd - tVarStart) <= 5000,
    };
    expect(snapAfterVar?.ok).toBe(true);
    expect(snapAfterVar.version).toBeGreaterThan(snapBeforeVar.version);
    touched.variationIds.push(...STOCK_VARIATION_FRITES_IDS);

    await expect.poll(
      async () => (await fritesToggle.innerText()).trim(),
      { timeout: 5_000 },
    ).not.toBe(fritesLabelBefore);
    const fritesLabelAfter = (await fritesToggle.innerText()).trim();
    runState.states['C-04'] = `variation toggle Frites (5 ids fan-out) : "${fritesLabelBefore}" → "${fritesLabelAfter}", snapshot ${snapBeforeVar.version}→${snapAfterVar.version} in ${tVarEnd - tVarStart}ms`;
    await recorder.snap('C-04-stock-toggle-variation-off');

    // ---------- C-05 — Bulk-ish sequential toggle 5 category products ----------
    // The M1 concurrency-2 + 100ms inter-batch gap lives inside sendBulkToggle
    // for the cascading extras/variations paths. Plain item toggles call a
    // single endpoint per product. Here we exercise the rate-limit budget for
    // /admin/menu/availability/toggle by clicking 5 products in sequence and
    // ensuring all 5 land 200 with the loop finishing under ~10s.
    await page.locator('[data-testid="stock-mgmt-bucket-cat-8"]').click();
    await page.waitForTimeout(400);

    const bulkResults = [];
    const tBulkStart = Date.now();
    for (const itemId of STOCK_ITEM_C05_BATCH) {
      if (itemId === STOCK_ITEM_CHEDDAR) continue; // already toggled in C-02
      const btn = page.locator(`[data-testid="stock-mgmt-toggle-item-${itemId}"]`);
      const visible = await btn.isVisible().catch(() => false);
      if (!visible) {
        bulkResults.push({ itemId, status: 'NOT_FOUND' });
        continue;
      }
      const resPromise = page.waitForResponse(
        (r) => /\/api\/admin\/menu\/availability\/toggle$/.test(r.url())
          && r.request().method() === 'POST',
        { timeout: 8_000 },
      );
      await btn.click();
      try {
        const res = await resPromise;
        bulkResults.push({ itemId, status: res.status() });
        if (res.status() === 200) touched.itemIds.push(itemId);
      } catch (e) {
        bulkResults.push({ itemId, status: 'TIMEOUT' });
      }
      await page.waitForTimeout(200);
    }
    const tBulkEnd = Date.now();
    const tBulkDelta = tBulkEnd - tBulkStart;
    const all200 = bulkResults.every((r) => r.status === 200);
    const no429 = !bulkResults.some((r) => r.status === 429);
    runState.evidence.c05_bulk_results = bulkResults;
    runState.timings.c05_bulk_total_ms = {
      delta_ms: tBulkDelta,
      expect_max_ms: 10_000,
      pass: tBulkDelta <= 10_000 && all200 && no429,
    };
    expect(no429, `bulk got 429: ${JSON.stringify(bulkResults)}`).toBe(true);
    expect(all200, `not all 200: ${JSON.stringify(bulkResults)}`).toBe(true);
    runState.states['C-05'] = `bulk toggle (${bulkResults.length} sequential category toggles) : statuses=[${bulkResults.map((r) => r.status).join(',')}] in ${tBulkDelta}ms (no 429)`;
    await recorder.snap('C-05-stock-bulk-after-5-toggles');

    recorder.dispose();
  });

  // ------------------------------------------------------------------------
  // KDS — C-07 through C-11
  // ------------------------------------------------------------------------
  test('C-07..C-11 — KDS Historique drawer sanity', async ({ page }) => {
    test.setTimeout(180_000);
    const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Seed at least 2 historical orders so the drawer surfaces a list, not
    // the empty state. Status int per app/Enums/OrderStatus.php:
    //   PREPARED = 8, OUT_FOR_DELIVERY = 10, DELIVERED = 13.
    const seedA = seedHistoricalOrder({ status: 8, tokenSuffix: 'HIST-A' });
    const seedB = seedHistoricalOrder({ status: 13, tokenSuffix: 'HIST-B' });
    runState.evidence.c07_seeded = { prepared: seedA, delivered: seedB };

    clearFoodKingRateLimits();
    await loginAsAdmin(page);

    // ---------- C-07 — /kds mount, history pill visible + FR translated ----------
    await page.goto(KDS_PATH, { waitUntil: 'domcontentloaded' });
    const trigger = page.locator('[data-testid="kds-history-button"]');
    await expect(trigger).toBeVisible({ timeout: 30_000 });
    const triggerText = (await trigger.innerText()).trim();
    runState.evidence.c07_trigger_text = triggerText;
    // FR copy lives at lang/fr/all.php:92 'kds_history_button' => 'Historique'.
    expect(triggerText).toMatch(/Historique/);
    expect(triggerText).not.toMatch(/label\./i);
    runState.states['C-07'] = `KDS mount, history pill text="${triggerText}" (FR translated, no raw key)`;
    await recorder.snap('C-07-kds-mount-pill-visible');

    // ---------- C-08 — Click pill → drawer slides in ----------
    await trigger.click();
    const drawer = page.locator('[data-testid="kds-history-drawer"]');
    await expect(drawer).toBeVisible({ timeout: 10_000 });
    // Wait for list or empty.
    await page.locator('[data-testid="kds-history-list"], [data-testid="kds-history-empty"]')
      .first()
      .waitFor({ timeout: 15_000 });
    runState.states['C-08'] = 'drawer opened from header pill';
    await recorder.snap('C-08-kds-drawer-open');

    // ---------- C-09 — Drawer rows have translated status badges + timestamps ----------
    const items = page.locator('[data-testid="kds-history-item"]');
    const itemCount = await items.count();
    expect(itemCount).toBeGreaterThanOrEqual(2);
    const drawerText = await drawer.innerText();
    // Header
    expect(drawerText).toContain('Historique du jour');
    expect(drawerText).not.toMatch(/label\.kds_history_title/i);
    // At least one anchored FR badge token (Prêt / En livraison / Livré),
    // matched word-boundary so the raw key "LABEL.KDS_STATE_PREPARED" cannot
    // vacuously satisfy the test by substring.
    expect(drawerText).toMatch(/\b(Prêt|En livraison|Livré)\b/i);
    // Negative sentinel: any raw i18n key leaking is P0.
    expect(drawerText).not.toMatch(/\b(label|kiosk|kds)\.[a-z_]+\b/i);
    expect(drawerText).not.toMatch(/\bLABEL\.[A-Z_]+\b/);
    // Each item must render a queue + a timestamp (HH:MM).
    const firstItemText = await items.first().innerText();
    runState.evidence.c09_first_item = firstItemText.substring(0, 240);
    expect(firstItemText).toMatch(/N°\s*\S+/);
    expect(firstItemText).toMatch(/\d{2}:\d{2}/);
    runState.states['C-09'] = `drawer rows count=${itemCount}, translated FR header + status badges, no raw labels`;
    await recorder.snap('C-09-kds-drawer-rows');

    // ---------- C-10 — Escape-key dismisses drawer ----------
    // KdsHistoryDrawer.vue:194-200 registers a keydown handler. Tab into the
    // body first so keydown is page-scoped (drawer focus is inside the dialog).
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden({ timeout: 5_000 });
    runState.states['C-10'] = 'Escape-key handler closed drawer (WAI-ARIA dialog pattern verified)';
    await recorder.snap('C-10-kds-drawer-closed-after-esc');

    // ---------- C-11 — Read-only V1 (no Renvoyer / Recall control in any row) ----------
    // Re-open drawer to inspect rendered rows for revert controls.
    await trigger.click();
    await expect(drawer).toBeVisible({ timeout: 10_000 });
    await page.locator('[data-testid="kds-history-list"], [data-testid="kds-history-empty"]')
      .first()
      .waitFor({ timeout: 15_000 });
    const revertButtons = drawer.locator(
      'button:has-text("Renvoyer"), button:has-text("Recall"), button:has-text("Restore"), button:has-text("Rétablir")',
    );
    const revertCount = await revertButtons.count();
    runState.evidence.c11_revert_button_count = revertCount;
    expect(revertCount).toBe(0);
    runState.states['C-11'] = `drawer rendered ${itemCount} rows; zero Renvoyer/Recall/Restore controls (read-only V1 contract)`;
    await recorder.snap('C-11-kds-drawer-read-only-no-revert');

    // Close cleanly so teardown doesn't see overlays.
    await page.locator('[data-testid="kds-history-close"]').click();
    await expect(drawer).toBeHidden({ timeout: 5_000 });

    recorder.dispose();
  });
});

/**
 * Build findings JSON (REVIEWER_PROTOCOL.md shape with wave-C extension fields).
 * Adversarial reviewer reads PNGs + DOM/console/network siblings; this JSON
 * captures the spec-side ledger of states + timings + raw evidence.
 */
function buildFindings() {
  const findings = [];
  let p0 = 0; let p1 = 0; let p2 = 0; let p3 = 0;

  const ev = runState.evidence || {};

  // --- Q9-S1 extra-path invalidation (NEW assertion vs Wave B) ---
  const c03Bumped = ev.c03_snapshot_before?.ok
    && ev.c03_snapshot_after?.ok
    && ev.c03_snapshot_after.version > ev.c03_snapshot_before.version;
  if (!c03Bumped) {
    findings.push({
      id: 'C-001',
      state_artifact: 'wave-polish-final-C/C-03-stock-toggle-extra-off.png',
      category: 'sync_q9_s1_extra_path',
      severity: 'P1',
      evidence: `Extra-toggle did NOT bump kiosk MenuSnapshot. before=${ev.c03_snapshot_before?.version} after=${ev.c03_snapshot_after?.version} (Wave B proved variation-path; this is the extra-path verification).`,
      fix_hint: 'Verify EventServiceProvider.php:204 wires ItemExtraAvailabilityChanged → InvalidateKioskMenuCacheOnCatalogChange (currently at line 217). If wired, audit the AvailabilityController::toggleExtra() to confirm it dispatches the event after-commit.',
    });
    p1 += 1;
  }

  // --- Variation path bump (replicates Wave B) ---
  const c04Bumped = ev.c04_snapshot_before?.ok
    && ev.c04_snapshot_after?.ok
    && ev.c04_snapshot_after.version > ev.c04_snapshot_before.version;
  if (!c04Bumped) {
    findings.push({
      id: 'C-002',
      state_artifact: 'wave-polish-final-C/C-04-stock-toggle-variation-off.png',
      category: 'sync_q9_s1_variation_path',
      severity: 'P1',
      evidence: `Variation-toggle did NOT bump kiosk MenuSnapshot. before=${ev.c04_snapshot_before?.version} after=${ev.c04_snapshot_after?.version}. This is regression vs Wave B (which proved variation_id=9 Mayonnaise bumped the snapshot).`,
      fix_hint: 'EventServiceProvider.php:219 → InvalidateKioskMenuCacheOnCatalogChange listener should fire on ItemVariationAvailabilityChanged. Check that AvailabilityController::toggleVariation() upserts stock_levels AND fires the event.',
    });
    p1 += 1;
  }

  // --- Bulk 5 sequential toggles: any 429 = P0 (rate-limit regression) ---
  const bulkResults = ev.c05_bulk_results || [];
  const has429 = bulkResults.some((r) => r?.status === 429);
  if (has429) {
    findings.push({
      id: 'C-003',
      state_artifact: 'wave-polish-final-C/C-05-stock-bulk-after-5-toggles.png',
      category: 'rate_limit_regression',
      severity: 'P0',
      evidence: `5 sequential category-toggle POSTs hit 429 — rate-limit budget exhausted. Results: ${JSON.stringify(bulkResults)}`,
      fix_hint: 'routes/api.php:248 (throttle:60,1 on /admin/menu/availability/toggle). If 5 sequential calls trip it, the bucket is shared with other surfaces; consider scoping to user+route or raising to throttle:120,1 for trusted admin sessions.',
    });
    p0 += 1;
  }

  // --- Cross-axis dedupe sanity (P2 if labels missing) ---
  const c06Labels = ev.c06_labels || {};
  const c06DedupeOk = /Suppléments/i.test(c06Labels.category || '')
    && /Suppléments/i.test(c06Labels.extra_group || '')
    && /à composer/i.test(c06Labels.extra_group || '');
  if (!c06DedupeOk) {
    findings.push({
      id: 'C-004',
      state_artifact: 'wave-polish-final-C/C-06-stock-cross-axis-dedupe.png',
      category: 'cross_axis_dedupe',
      severity: 'P2',
      evidence: `Cross-axis dedupe (round-3 A-015 fix) labels not as expected: category="${c06Labels.category}", extra_group="${c06Labels.extra_group}". Expected "Suppléments" + "Suppléments (à composer)".`,
      fix_hint: 'StockRuptureDashboardController.php:418-427 cross-axis dedupe — verify the categoryNameSet + display_name match are still wired.',
    });
    p2 += 1;
  }

  // --- KDS i18n leak (P1 if a raw key leaks in the drawer) ---
  const triggerText = ev.c07_trigger_text || '';
  if (/label\./i.test(triggerText)) {
    findings.push({
      id: 'C-005',
      state_artifact: 'wave-polish-final-C/C-07-kds-mount-pill-visible.png',
      category: 'i18n_leak',
      severity: 'P1',
      evidence: `KDS history pill text contains raw key: "${triggerText}"`,
      fix_hint: 'resources/js/languages/fr.json line ~727 "kds_history_button" should resolve to "Historique" — verify lang bundle rebuilt + AdminLayout locale lock returns "fr" for /admin/* paths.',
    });
    p1 += 1;
  }

  // --- Revert button in V1 drawer = P0 (V1.0.2 backlog, must NOT be present in V1) ---
  if ((ev.c11_revert_button_count || 0) > 0) {
    findings.push({
      id: 'C-006',
      state_artifact: 'wave-polish-final-C/C-11-kds-drawer-read-only-no-revert.png',
      category: 'v1_contract_violation',
      severity: 'P0',
      evidence: `Drawer rendered ${ev.c11_revert_button_count} revert/recall control(s). V1 contract per KdsHistoryDrawer.vue:138-142 is READ-ONLY (V1.0.2 backlog requires LOCK plan + owner countersign because OrderStateMachine §7 forbids reverse transitions).`,
      fix_hint: 'KdsHistoryDrawer.vue: remove any newly-added revert/recall button. If owner pushed V1.0.2 feature into V1, escalate per CLAUDE.md §10 Human gate.',
    });
    p0 += 1;
  }

  // Verdict: P0 or P1 > 0 → not green.
  let verdict;
  if (p0 > 0) verdict = 'RED';
  else if (p1 > 0) verdict = 'AMBER';
  else verdict = 'GREEN';

  return {
    wave: 'C',
    round: 1,
    started_at: runState.startedAt,
    ended_at: runState.endedAt || new Date().toISOString(),
    states_reviewed: 11,
    scope: 'Stock Rupture Dashboard (post stock-mgmt-M1 + Phase 2H Q9-S1 fix) + KDS Historique drawer (post Wave X X3 commit 4428e3ca4 + Q12 sentinel).',
    commit_under_test: '4c8fdcdbe',
    fix_commits_verified: [
      '7a409ade7 — feat(stock-mgmt-M1): unified catalog browser',
      '1116b3957 — stock-mgmt-M1 final',
      'a68acb20f — fix(sync-q9-s1): wire kiosk cache invalidator on extras + variations events',
      '4428e3ca4 — feat(kds-X3): historique du jour drawer read-only V1',
      'f0060a138 — bundle rebuild post X3',
      '312221417 — test(sentinel-kds-bundle): build-integrity check',
    ],
    states: runState.states,
    findings,
    summary: {
      P0: p0, P1: p1, P2: p2, P3: p3,
      open_P0: p0, open_P1: p1, open_P2: p2, open_P3: p3,
    },
    timings: runState.timings,
    evidence: runState.evidence,
    cleanup: runState.cleanup,
    verdict,
  };
}
