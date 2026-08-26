// FoodKing E2E — rush-100 Wave C : Cross-Surface (KDS + OSS + Admin) — 2026-05-13
//
// MISSION (from orchestrator user prompt) :
//   Capture KDS + OSS + Admin pages, VISUAL states, AND verify cross-surface
//   sync: orders from Wave A (kiosk) + Wave B (POS) are visible on KDS + OSS
//   and have a `composition_snapshot` rendered correctly.
//
// HARD CONSTRAINTS observed during orientation (advisor reconciled) :
//
// 1) Latency is post-hoc, NOT real-time. By the time Wave C boots, A+B have
//    finished. We CANNOT measure creation→propagation latency. We report
//    `(now - created_at)` as informational AGE only, plus presence in API
//    payloads. The sync-check JSON contains this reframe explicitly so
//    reviewers don't read the `age_ms` field as a true latency.
//
// 2) DB schema diverges from instruction prose :
//      • `kds_orders` table does NOT exist (KitchenDisplaySystemOrderService
//        reads from `orders`). All sync checks hit `orders` + `domain_events`.
//      • Instructions cite "37 active items" / "10 categories"; live DB shows
//        86 items (all status=5) and 18 item_categories. We capture the
//        rendered count to observations but use soft assertions to never fail
//        on a stale-prose count drift.
//      • `composition_snapshot` IS a column on `order_items`; we probe it.
//
// 3) Routes (admin SPA) — corrected from instructions :
//      • Items list      = /admin/items          (redirects to studio)
//      • Items canonical = /admin/items/studio   (per router)
//      • Categories      = /admin/settings/item-categories/list
//        (NOT /admin/item-category as the user-prompt cites)
//
// 4) NO SEEDING. Wave A/B already produced orders. This wave is a
//    verification-only wave: it logs in as admin, captures the surfaces,
//    cross-checks DB presence + API payloads, and bumps lifecycle on ONE
//    pre-existing Wave-A/B order via the direct axios change-status path
//    (same as kiosk-kds-sync Wave C — UI clicks add brittleness for zero
//    coverage gain).
//
// 5) Order discovery. We look for orders whose `token` or `order_serial_no`
//    starts with `AUDIT-RUSH-A-` (kiosk) or `AUDIT-RUSH-B-` (POS) — the
//    legacy rush-hour-50x50 token convention. If Wave-A/B used a different
//    prefix, we fall back to "all orders created today after a window
//    around Wave-A/B startup" — and log the discovery method to
//    observations so adversarial reviewers can audit.
//
// 6) Frozen-zone integrity — none touched. KDS/Admin/OSS Vue components are
//    NOT in CLAUDE.md frozen list. We only READ them and never patch.
//
// VOCAB GAP (kept identical to kiosk-kds-sync wave C) :
//   plan PENDING(1) — NOT visible on KDS (filtered out by service).
//   plan ACCEPT(4)   — entry-on-KDS state (= "PENDING" in user prose).
//   plan PREPARING(7) — first bump.
//   plan PREPARED(8) — terminal on KDS for kiosk+POS sources per FIX-54-5.
//
// REVIEWER PROTOCOL : per state we emit PNG + DOM + console.json + network.json
// via attachMegaAuditRecorder. Adversarial reviewer scores 12 defect categories.
//
// Audit plan : reports/test-e2e/rush-100-2026-05-13/REVIEWER_PROTOCOL.md
// Output dir  : tests/e2e/__screenshots__/rush-100/cross-surface/

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/rush-100/cross-surface');
const REPORT_DIR = path.resolve(__dirname, '../../reports/test-e2e/rush-100-2026-05-13/round-1');
const SYNC_CHECK_PATH = path.join(REPORT_DIR, 'wave-C-sync-check.json');

const repoRoot = path.resolve(__dirname, '../..');

const STATUS = Object.freeze({
  PENDING: 1,
  ACCEPT: 4,
  PREPARING: 7,
  PREPARED: 8,
});

function tinker(code) {
  try {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 30_000,
    }).trim();
  } catch (err) {
    return `__tinker_error__:${err?.message || err}`;
  }
}

function tinkerJson(code) {
  const out = tinker(code);
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON payload in tinker output: ${out.slice(0, 400)}`);
  }
  return JSON.parse(jsonLine);
}

/**
 * Robust kdsChangeStatus replicating helpers/sync-journey-trace::kdsChangeStatus,
 * but with full error-body propagation for diagnostics. We do NOT silently
 * swallow non-2xx.
 */
async function safeKdsChangeStatus(page, orderId, expected, next, label) {
  const probe = await page.evaluate(async ({ orderId, expected, next }) => {
    try {
      const r = await window.axios.post(`admin/kds-order/change-status/${orderId}`, {
        expected_status: expected,
        status: next,
      },
          // [GOAL CONSOLIDATION 2026-08-25] En-tête OBLIGATOIRE : `config/idempotency.php`
          // liste la route `kds-order/change-status/{id}` dans required_routes (un double
          // bump enverrait deux notifications client). Sans l'en-tête → 422, et l'échec
          // ressemble trompeusement à un défaut de synchro cuisine.
          { headers: { 'X-Idempotency-Key': `idem-kds-${Date.now()}-${Math.random().toString(16).slice(2)}` } }
        );
      return { ok: r.status >= 200 && r.status < 300, status: r.status };
    } catch (e) {
      return {
        ok: false,
        status: e?.response?.status || null,
        body: typeof e?.response?.data === 'string'
          ? e.response.data.slice(0, 400)
          : (e?.response?.data ? JSON.stringify(e.response.data).slice(0, 400) : String(e?.message || e)),
      };
    }
  }, { orderId, expected, next });
  if (!probe.ok) {
    throw new Error(`[${label}] change-status ${orderId} (${expected}->${next}): ${JSON.stringify(probe)}`);
  }
  await page.evaluate((id) => {
    window.dispatchEvent(new CustomEvent('realtime-order-update', {
      detail: { type: 'rush-100-wave-c-bump', order_id: id },
    }));
  }, orderId);
  return probe;
}

/**
 * Discover Wave-A + Wave-B orders. Strategy :
 *   1) Primary : token or order_serial_no LIKE 'AUDIT-RUSH-A-%' / 'AUDIT-RUSH-B-%'.
 *   2) Fallback : if primary yields 0 rows, take the most-recent 20 orders
 *      created today regardless of token — and log the discovery method.
 *   3) Always : annotate with source_surface, composition_snapshot presence per
 *      order_item, domain_events count.
 *
 * Returns { method, orders: [{id, serial, token, source_surface, status,
 *           created_at, age_ms, total, kiosk_or_pos,
 *           composition_snapshot_present, domain_events_count}] }
 */
function discoverWaveOrders() {
  return tinkerJson(
    "$now = now(); "
    + "$primary = \\App\\Models\\Order::withoutGlobalScopes()"
    + "  ->where(function($q){ $q->where('token','like','AUDIT-RUSH-A-%')->orWhere('order_serial_no','like','AUDIT-RUSH-A-%')"
    + "  ->orWhere('token','like','AUDIT-RUSH-B-%')->orWhere('order_serial_no','like','AUDIT-RUSH-B-%'); })"
    + "  ->orderByDesc('id')->take(50)->get(['id','order_serial_no','token','source_surface','status','order_type','total','created_at']); "
    + "$method = 'token-prefix'; "
    + "if ($primary->isEmpty()) { "
    + "  $primary = \\App\\Models\\Order::withoutGlobalScopes()"
    + "    ->whereDate('created_at',$now->toDateString())"
    + "    ->orderByDesc('id')->take(20)->get(['id','order_serial_no','token','source_surface','status','order_type','total','created_at']); "
    + "  $method = 'today-recent-fallback'; "
    + "} "
    + "$rows = []; "
    + "foreach ($primary as $o) { "
    + "  $createdMs = $o->created_at ? \\Carbon\\Carbon::parse($o->created_at)->valueOf() : null; "
    + "  $ageMs = $createdMs ? ($now->valueOf() - $createdMs) : null; "
    + "  $items = \\DB::table('order_items')->where('order_id',$o->id)->get(['id','composition_snapshot']); "
    + "  $itemsWithSnap = 0; foreach ($items as $it) { if (!empty($it->composition_snapshot) && $it->composition_snapshot !== 'null') $itemsWithSnap++; } "
    + "  $domainEv = \\DB::table('domain_events')->where('aggregate_id',(string)$o->id)->count(); "
    + "  $kindGuess = null; "
    + "  if ($o->source_surface) $kindGuess = $o->source_surface; "
    + "  elseif ($o->token && str_starts_with($o->token,'AUDIT-RUSH-A-')) $kindGuess = 'kiosk'; "
    + "  elseif ($o->token && str_starts_with($o->token,'AUDIT-RUSH-B-')) $kindGuess = 'pos'; "
    + "  elseif ($o->order_serial_no && str_starts_with($o->order_serial_no,'AUDIT-RUSH-A-')) $kindGuess = 'kiosk'; "
    + "  elseif ($o->order_serial_no && str_starts_with($o->order_serial_no,'AUDIT-RUSH-B-')) $kindGuess = 'pos'; "
    + "  $rows[] = ['id'=>$o->id,'serial'=>$o->order_serial_no,'token'=>$o->token,"
    + "    'source_surface'=>$o->source_surface,'status'=>$o->status,'order_type'=>$o->order_type,"
    + "    'total'=>(float)$o->total,'created_at'=>(string)$o->created_at,"
    + "    'age_ms'=>$ageMs,'kind_guess'=>$kindGuess,"
    + "    'items_count'=>$items->count(),'items_with_composition_snapshot'=>$itemsWithSnap,"
    + "    'domain_events_count'=>$domainEv]; "
    + "} "
    + "echo json_encode(['method'=>$method,'count'=>count($rows),'orders'=>$rows]);"
  );
}

test.describe('rush-100 Wave C — Cross-Surface (KDS + OSS + Admin)', () => {
  test.setTimeout(420_000);
  test.describe.configure({ mode: 'serial' });

  const observations = [];
  let waveDiscovery = null;

  test.afterAll(() => {
    // eslint-disable-next-line no-console
    console.log('[rush-100 wave-C observations]\n' + observations.join('\n'));
  });

  test('Wave C : 10 sequential states across KDS/OSS/Admin + cross-surface sync', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    try {
      // Pre-flight: clear rate limits in case Wave A or B left throttles primed.
      try { clearFoodKingRateLimits(); } catch (_) {}

      // STATE 00 — login admin → /admin/dashboard (default post-login landing).
      await loginAsAdmin(page);
      await page.waitForTimeout(1500);
      await snap('00-login-ok');
      observations.push(`state00: post-login URL=${page.url()}`);

      // ---------------------------------------------------------------
      // STATE 01 — /admin/dashboard
      // ---------------------------------------------------------------
      await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2000);
      await snap('01-dashboard');
      observations.push(`state01: dashboard URL=${page.url()}`);

      // ---------------------------------------------------------------
      // DISCOVER Wave-A + Wave-B orders BEFORE the KDS capture so we can
      // log expected presence + composition_snapshot presence per order.
      // ---------------------------------------------------------------
      waveDiscovery = discoverWaveOrders();
      observations.push(`discovery: method=${waveDiscovery.method} count=${waveDiscovery.count}`);
      if (waveDiscovery.count === 0) {
        observations.push('discovery: ZERO orders found — Wave A+B may not have produced any. Visual capture continues but cross-surface assertions will be soft-skipped.');
      } else {
        const sample = waveDiscovery.orders.slice(0, 5).map((o) => ({ id: o.id, serial: o.serial, status: o.status, kind: o.kind_guess, snap: o.items_with_composition_snapshot, dev: o.domain_events_count }));
        observations.push(`discovery: sample=${JSON.stringify(sample)}`);
      }

      // ---------------------------------------------------------------
      // STATE 02 — /admin/kitchen-display-system
      // Expect: KDS shows pending orders from waves A+B (~10 if both green).
      // We capture the visual, count lane cards, and probe the admin/kds-order
      // API to record what KDS actually receives.
      // ---------------------------------------------------------------
      await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3500);
      // KDS surfaces a fallback banner if WS disconnected (dev path is always
      // WS-off). We don't fail on its presence; only log.
      const wsBanner = await page.locator('[data-testid="kds-sync-mode-banner"]').count();
      const errBanner = await page.locator('[data-testid="kds-error-banner"]').count();
      observations.push(`state02: ws-fallback-banner=${wsBanner} error-banner=${errBanner}`);

      // Count cards per lane.
      const kioskLane = await page.locator('[data-kds-order-card="kiosk"]').count();
      const takeawayLane = await page.locator('[data-kds-order-card="takeaway"]').count();
      const onlineLane = await page.locator('[data-kds-order-card="online"]').count();
      const dineinLane = await page.locator('[data-kds-order-card="dinein"]').count();
      observations.push(`state02: lanes kiosk=${kioskLane} takeaway=${takeawayLane} online=${onlineLane} dinein=${dineinLane}`);

      // Probe the API directly — captures what the SPA *would* fetch on a poll.
      const kdsApiPayload = await page.evaluate(async () => {
        try {
          const r = await window.axios.get('admin/kds-order', {
            params: { paginate: 0, order_column: 'id', order_by: 'desc', status: '' },
          });
          const rows = Array.isArray(r.data?.data) ? r.data.data : [];
          return {
            status: r.status,
            count: rows.length,
            sample_serials: rows.slice(0, 10).map((row) => row.order_serial_no),
            sample_statuses: rows.slice(0, 10).map((row) => row.status),
            sample_sources: rows.slice(0, 10).map((row) => row.source_surface),
          };
        } catch (e) {
          return { error: String(e?.response?.status || e?.message || e) };
        }
      });
      observations.push(`state02: kds-api ${JSON.stringify(kdsApiPayload)}`);
      expect.soft(kdsApiPayload.status, 'KDS API must respond 200').toBe(200);

      await snap('02-kds-with-orders');

      // ---------------------------------------------------------------
      // STATE 03 — bump ONE Wave-A/B order ACCEPT(4) → PREPARING(7)
      // (only if discovery produced a candidate in ACCEPT status).
      // ---------------------------------------------------------------
      const inAccept = waveDiscovery && Array.isArray(waveDiscovery.orders)
        ? waveDiscovery.orders.filter((o) => Number(o.status) === STATUS.ACCEPT)
        : [];
      let bumpedOrder = null;
      if (inAccept.length > 0) {
        bumpedOrder = inAccept[0];
        try {
          await safeKdsChangeStatus(page, bumpedOrder.id, STATUS.ACCEPT, STATUS.PREPARING, 'state03');
          await page.waitForTimeout(2200);
          observations.push(`state03: bumped order ${bumpedOrder.id} (${bumpedOrder.serial}) ACCEPT→PREPARING OK`);
        } catch (err) {
          observations.push(`state03: bump FAILED ${err.message}`);
          bumpedOrder = null;
        }
      } else {
        observations.push(`state03: NO Wave-A/B orders in ACCEPT status — skipping lifecycle bump. discovery count by status=${JSON.stringify((waveDiscovery?.orders || []).reduce((acc, o) => { const k = `s${o.status}`; acc[k] = (acc[k] || 0) + 1; return acc; }, {}))}`);
      }
      await snap('03-kds-preparing');

      // ---------------------------------------------------------------
      // STATE 04 — bump the same order PREPARING(7) → PREPARED(8)
      // ---------------------------------------------------------------
      if (bumpedOrder) {
        try {
          await safeKdsChangeStatus(page, bumpedOrder.id, STATUS.PREPARING, STATUS.PREPARED, 'state04');
          await page.waitForTimeout(2200);
          observations.push(`state04: bumped order ${bumpedOrder.id} PREPARING→PREPARED OK`);
        } catch (err) {
          observations.push(`state04: bump FAILED ${err.message}`);
        }
      } else {
        observations.push('state04: skipped — no order bumped in state03.');
      }
      await snap('04-kds-ready');

      // ---------------------------------------------------------------
      // STATE 05 — /order-status-screen (OSS)
      // The OSS uses an admin-context GET /api/admin/oss-order. We capture
      // the rendered UI plus probe the API directly.
      // ---------------------------------------------------------------
      await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const ossApiPayload = await page.evaluate(async () => {
        try {
          const r = await window.axios.get('admin/oss-order', {
            params: { paginate: 0, order_column: 'id', order_by: 'desc' },
          });
          const rows = Array.isArray(r.data?.data) ? r.data.data : (Array.isArray(r.data) ? r.data : []);
          return {
            status: r.status,
            count: rows.length,
            sample_serials: rows.slice(0, 10).map((row) => row?.order_serial_no || row?.token),
            sample_statuses: rows.slice(0, 10).map((row) => row?.status),
          };
        } catch (e) {
          return { error: String(e?.response?.status || e?.message || e) };
        }
      });
      observations.push(`state05: oss-api ${JSON.stringify(ossApiPayload)}`);
      // OSS may render preparing/ready columns; count via the component selectors.
      const ossPrepCount = await page.locator('section[aria-label*="préparation" i] li, [aria-label*="preparing" i] li').count();
      const ossReadyCount = await page.locator('section[aria-label*="prêt" i] li, [aria-label*="ready" i] li').count();
      observations.push(`state05: oss-ui preparing-li=${ossPrepCount} ready-li=${ossReadyCount}`);
      await snap('05-oss-preparing-and-ready');

      // ---------------------------------------------------------------
      // STATE 06 — /admin/items
      // Instructions claim "37 active". DB has 86 items (all status=5).
      // We capture, count rows, and assert > 0 (soft).
      // ---------------------------------------------------------------
      await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3500);
      // /admin/items redirects to /admin/items/studio (per router). Log final URL.
      observations.push(`state06: items URL=${page.url()}`);
      // Try both list testids — studio shell may differ from legacy list.
      const itemRows = await page.locator('[data-testid="admin-items-list"] tr, [data-testid^="admin-item-row-"], .item-card, [data-testid^="catalog-studio-item-"]').count();
      observations.push(`state06: rendered item rows/cards=${itemRows}`);
      expect.soft(itemRows, 'Items page should render at least 1 row/card').toBeGreaterThan(0);
      await snap('06-admin-items-list');

      // ---------------------------------------------------------------
      // STATE 07 — search "Bol"
      // The search input id is "name" per ItemListComponent line 92, but
      // the legacy list may not be on the studio shell. We attempt several
      // selectors and log which fired.
      // ---------------------------------------------------------------
      const searchTried = [];
      const searchSelectors = [
        'input#name',
        'input[placeholder*="rech" i]',
        'input[type="search"]',
        'input[aria-label*="rech" i]',
        '[data-testid*="search"] input',
      ];
      let searchUsed = null;
      for (const sel of searchSelectors) {
        const el = page.locator(sel).first();
        const visible = await el.isVisible({ timeout: 800 }).catch(() => false);
        searchTried.push(`${sel}=${visible}`);
        if (visible) {
          await el.fill('Bol').catch(() => {});
          searchUsed = sel;
          break;
        }
      }
      if (searchUsed) {
        // Most search forms submit on Enter; try both Enter + clicking a Search button.
        await page.keyboard.press('Enter').catch(() => {});
        await page.waitForTimeout(1500);
        observations.push(`state07: search "Bol" submitted via ${searchUsed}`);
      } else {
        observations.push(`state07: no usable search input found — tried ${searchTried.join(' ; ')}`);
      }
      await snap('07-admin-items-search-bol');

      // ---------------------------------------------------------------
      // STATE 08 — /admin/settings/item-categories/list
      // (Instruction prose says /admin/item-category — wrong; corrected.)
      // ---------------------------------------------------------------
      await page.goto('/admin/settings/item-categories/list', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const catListVisible = await page.locator('[data-testid="admin-category-list"]').isVisible({ timeout: 4000 }).catch(() => false);
      const catRowCount = await page.locator('[data-testid^="admin-category-row-"]').count();
      observations.push(`state08: cat-list-visible=${catListVisible} cat-row-count=${catRowCount} URL=${page.url()}`);
      expect.soft(catRowCount, 'Item categories list should render at least one row').toBeGreaterThan(0);
      await snap('08-admin-categories-list');

      // ---------------------------------------------------------------
      // STATE 09 — toggle "show archived" if the UI exposes one.
      // ItemCategoryListComponent does NOT expose a "show archived" toggle
      // in the readable DOM (verified via grep) — we capture the same list
      // as state08 + log absence. This is informational, not a P0.
      // ---------------------------------------------------------------
      const archivedToggle = page.locator('button:has-text(/archiv/i), input[type="checkbox"]:near(:text("archiv"))').first();
      const toggleVisible = await archivedToggle.isVisible({ timeout: 1500 }).catch(() => false);
      if (toggleVisible) {
        await archivedToggle.click().catch(() => {});
        await page.waitForTimeout(1500);
        observations.push('state09: archived toggle clicked.');
      } else {
        observations.push('state09: no "show archived" toggle found on item-categories list. Captured as-is.');
      }
      await snap('09-admin-categories-with-archived');

      // ---------------------------------------------------------------
      // CROSS-SURFACE SYNC REPORT
      // Re-query DB after the captures so the JSON reflects final state
      // (post-bump statuses, etc.).
      // ---------------------------------------------------------------
      const finalDiscovery = discoverWaveOrders();
      // For each order, also probe KDS API + OSS API presence at this moment.
      let kdsSerialSet = new Set();
      let ossSerialSet = new Set();
      try {
        const kdsAgain = await page.evaluate(async () => {
          try {
            const r = await window.axios.get('admin/kds-order', {
              params: { paginate: 0, order_column: 'id', order_by: 'desc', status: '' },
            });
            const rows = Array.isArray(r.data?.data) ? r.data.data : [];
            return rows.map((row) => row.order_serial_no).filter(Boolean);
          } catch (_e) { return []; }
        });
        kdsSerialSet = new Set(kdsAgain);
      } catch (_e) { /* ignore */ }
      try {
        const ossAgain = await page.evaluate(async () => {
          try {
            const r = await window.axios.get('admin/oss-order', { params: { paginate: 0 } });
            const rows = Array.isArray(r.data?.data) ? r.data.data : (Array.isArray(r.data) ? r.data : []);
            return rows.map((row) => row?.order_serial_no || row?.token).filter(Boolean);
          } catch (_e) { return []; }
        });
        ossSerialSet = new Set(ossAgain);
      } catch (_e) { /* ignore */ }

      const perOrder = (finalDiscovery.orders || []).map((o) => ({
        id: o.id,
        serial: o.serial,
        token: o.token,
        kind_guess: o.kind_guess,
        source_surface: o.source_surface,
        status: o.status,
        total: o.total,
        created_at: o.created_at,
        age_ms_at_wave_c_runtime: o.age_ms,
        items_count: o.items_count,
        items_with_composition_snapshot: o.items_with_composition_snapshot,
        composition_snapshot_full_coverage: o.items_count > 0 && o.items_count === o.items_with_composition_snapshot,
        domain_events_count: o.domain_events_count,
        present_in_kds_api: kdsSerialSet.has(o.serial),
        present_in_oss_api: ossSerialSet.has(o.serial),
      }));

      const syncReport = {
        wave: 'C',
        run: 'rush-100-2026-05-13',
        round: 1,
        generated_at: new Date().toISOString(),
        reframe_note:
          'age_ms_at_wave_c_runtime is age-since-creation at Wave-C runtime, NOT a real-time creation→KDS propagation latency. Wave C runs AFTER Wave A+B finish, so true propagation latency is not observable here. Reviewers should read present_in_kds_api + present_in_oss_api + composition_snapshot_full_coverage as the verification signal.',
        discovery_method: finalDiscovery.method,
        order_count_total: finalDiscovery.count,
        order_count_kiosk_guess: perOrder.filter((o) => o.kind_guess === 'kiosk').length,
        order_count_pos_guess: perOrder.filter((o) => o.kind_guess === 'pos').length,
        order_count_present_in_kds_api: perOrder.filter((o) => o.present_in_kds_api).length,
        order_count_present_in_oss_api: perOrder.filter((o) => o.present_in_oss_api).length,
        order_count_composition_snapshot_full: perOrder.filter((o) => o.composition_snapshot_full_coverage).length,
        order_count_domain_events_present: perOrder.filter((o) => o.domain_events_count > 0).length,
        bumped_order: bumpedOrder ? { id: bumpedOrder.id, serial: bumpedOrder.serial } : null,
        kds_api_lane_counts: { kiosk: kioskLane, takeaway: takeawayLane, online: onlineLane, dinein: dineinLane },
        per_order: perOrder,
        observations,
      };

      fs.mkdirSync(REPORT_DIR, { recursive: true });
      fs.writeFileSync(SYNC_CHECK_PATH, JSON.stringify(syncReport, null, 2));
      observations.push(`sync-check JSON written → ${SYNC_CHECK_PATH}`);

      // Soft assertions on cross-surface presence.
      if (syncReport.order_count_total > 0) {
        expect.soft(syncReport.order_count_present_in_kds_api,
          `At least 1 Wave-A/B order should appear in KDS API. Got ${syncReport.order_count_present_in_kds_api}/${syncReport.order_count_total}`)
          .toBeGreaterThan(0);
        expect.soft(syncReport.order_count_composition_snapshot_full,
          `At least 1 Wave-A/B order should have full composition_snapshot coverage. Got ${syncReport.order_count_composition_snapshot_full}/${syncReport.order_count_total}`)
          .toBeGreaterThan(0);
      } else {
        observations.push('cross-surface: SKIPPED soft assertions — discovery returned 0 orders. Wave A+B may be empty.');
      }

    } finally {
      dispose();
      await page.close().catch(() => {});
      await ctx.close().catch(() => {});
    }
  });
});
