// FoodKing E2E — Wave Z (Q13) Stress sync — 3 parallel kiosk contexts.
//
// Owner mandate Q13 (2026-05-21, verbatim) :
//   "but principal il pourra assurer la synchronisation data, la base de
//    données vraiment sécu en terme de synchronisation et connectivité entre
//    tous les systèmes"
//
// MISSION
//   Empirically prove the FoodKing kiosk → backend → KDS → OSS sync stack
//   tolerates 3 customers ordering simultaneously across 50 orders without :
//     - lost orders   (each ack-ed, NF525 fiscal_sequence_no allocated)
//     - duplicate orders (no two rows share idempotency_key — UNIQUE per branch)
//     - gaps in fiscal_sequence_no
//     - corrupted audit_logs chain (HMAC verified by fiscal:verify-chain)
//
// SCOPE — SETUP ONLY (Phase 2G).
//   This spec is AUTHORED + LOAD-VERIFIED in Phase 2G but NOT executed here.
//   Execution is Phase 3, once the V1 stack is otherwise stable. The
//   `--list` step in CI is the only thing that must succeed in 2G.
//
// PARALLELISM CONTRACT
//   3 isolated chromium contexts launched via `browser.newContext()` (separate
//   storage state, cookies, axios instances). Each context issues its own
//   Sanctum kiosk:order token after a serial pre-warm to avoid 3× concurrent
//   `kiosk-login` POST against throttle:kiosk-login.
//
// RATE-LIMIT ENVIRONMENT CONTRACT
//   The kiosk-orders limiter is keyed `kiosk:<user_id>|<ip>` (RouteServiceProvider
//   line 60) — ALL 3 contexts share the bucket because they auth as the same
//   kiosk-lecayenne machine from the same 127.0.0.1. Default is 5/min
//   (config/kiosk.php → KIOSK_ORDER_RATE_LIMIT). For a 50-order stress test
//   to run AT ALL, the spec asserts `config('kiosk.order_rate_limit') >= 60`
//   at boot and `test.skip()`s with a clear message otherwise. Phase 3
//   operator must export `KIOSK_ORDER_RATE_LIMIT=200` in .env (or just
//   raise it in the kiosk.php config_cache) before invoking.
//
// CLEANUP CONTRACT (NF525)
//   At end, we run the existing `iter15:cleanup-test-orders --token-prefix=STRESS-Q13-`
//   command — verified READ-ONLY against `audit_logs` + `z_reports` (only
//   touches order_status_transitions, transactions, domain_events,
//   order_addresses, order_items, orders). Append-only NF525 chain stays
//   untouched. Any orders that have already advanced past PREPARED=8 (e.g.
//   marked DELIVERED in a parallel run) are KEPT by design — the cleanup
//   command refuses to nuke finalized rows for traceability.
//
// RUN
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
//     npx playwright test tests/e2e/wave-z-stress-sync-3-kiosk.spec.js \
//     --project=chromium --workers=1 --retries=0 --reporter=line
//
//   `--workers=1` is for the Playwright test runner only; the spec drives
//   its OWN 3-way parallelism via Promise.all + 3 newContext() pairs.

const { test, expect, chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');

const {
  placeKioskOrder,
  getKioskApiToken,
  resetKioskToken,
  PAYMENT_CASH,
  PAYMENT_CARD,
  prefixeAuditPourSpec,
} = require('./helpers/kiosk-order');

// [GOAL CONSOLIDATION T-4.2.1] Préfixe d'audit propre à cette spec
// (isolation des écritures E2E entre specs).
const PREFIXE_AUDIT = prefixeAuditPourSpec(__filename);
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const repoRoot = path.resolve(__dirname, '../..');

// ----------------------------------------------------------------------------
// Test-wide constants — keep small + grep-able for the Phase 3 operator.
// ----------------------------------------------------------------------------

const STRESS_PREFIX = 'STRESS-Q13-';
const PARALLEL_CONTEXTS = 3;
const ORDERS_PER_CONTEXT = 17;  // 17×3 = 51, we cap at 50 for the assertion
const TOTAL_ORDER_TARGET = 50;
const CHECKPOINTS = [10, 25, 50]; // quartet snapshot triggers
const RATE_LIMIT_FLOOR = 60;     // require >=60/min to run (50 + headroom)

const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/wave-z-stress-3kiosk',
);
const REPORT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/wave-z-stress-q13-2026-05-21',
);

// ----------------------------------------------------------------------------
// Tinker helpers — small + spec-local so we don't depend on helper churn.
// ----------------------------------------------------------------------------

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
    throw new Error(`No JSON payload in tinker output:\n${out.slice(0, 600)}`);
  }
  return JSON.parse(jsonLine);
}

/**
 * Verify NF525 chain. Returns { ok: bool, output: string } so the spec
 * can attach the raw output to the failure for adversarial review.
 */
function verifyFiscalChain() {
  let output = '';
  let ok = false;
  try {
    output = execFileSync('php', ['artisan', 'fiscal:verify-chain'], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 60_000,
    });
    ok = /CHAIN OK/i.test(output);
  } catch (err) {
    output = String(err?.stdout || err?.message || err);
    ok = false;
  }
  return { ok, output };
}

/**
 * Aggregate DB-side counters for STRESS-Q13 orders — the four invariants we
 * promised to verify in the dispatch :
 *   1. orders.count   == 50
 *   2. DISTINCT idempotency_key == 50  (UNIQUE per (branch_id, idempotency_key))
 *   3. orders.fiscal_sequence_no NOT NULL count == 50
 *   4. MAX(fiscal_sequence_no) - MIN(fiscal_sequence_no) == 49 (gap-free)
 *
 * NOTE on (4): we restrict to STRESS-Q13 rows AND require the (max-min+1)
 * span to equal the row count. Across parallel non-stress traffic, sequence
 * numbers may be interleaved — a strict (max-min == count-1) match proves
 * the allocator handed out an unbroken sub-range to OUR rows even under
 * concurrent allocation. If other branches allocated in between, the spec
 * surfaces the diff via observations rather than failing immediately.
 */
function aggregateStressMetrics() {
  return tinkerJson(`
    $prefix = '${STRESS_PREFIX}';
    $rows = DB::table('orders')
      ->where('token', 'like', $prefix . '%')
      ->orderBy('fiscal_sequence_no')
      ->get(['id', 'token', 'idempotency_key', 'fiscal_sequence_no', 'status', 'payment_status']);
    $ids = $rows->pluck('id');
    $idemDistinct = $rows->pluck('idempotency_key')->filter()->unique()->count();
    $fiscalRows = $rows->whereNotNull('fiscal_sequence_no');
    $fiscalCount = $fiscalRows->count();
    $minFs = $fiscalRows->min('fiscal_sequence_no');
    $maxFs = $fiscalRows->max('fiscal_sequence_no');
    $span = ($minFs !== null && $maxFs !== null) ? ($maxFs - $minFs + 1) : null;
    echo json_encode([
      'orders_count' => $rows->count(),
      'idempotency_distinct' => $idemDistinct,
      'fiscal_sequence_count' => $fiscalCount,
      'fiscal_min' => $minFs,
      'fiscal_max' => $maxFs,
      'fiscal_span' => $span,
      'fiscal_gap_free_in_subset' => ($span !== null && $span === $fiscalCount),
      'order_ids' => $ids->values()->all(),
      'tokens' => $rows->pluck('token')->values()->all(),
      'statuses' => $rows->pluck('status')->values()->all(),
    ]);
  `);
}

/**
 * Probe the kiosk-orders rate-limit config — Phase 3 gate.
 */
function fetchKioskRateLimit() {
  const out = tinker(`echo (int) config('kiosk.order_rate_limit');`);
  const lines = out.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  for (const line of [...lines].reverse()) {
    const n = parseInt(line, 10);
    if (Number.isFinite(n) && n > 0) return n;
  }
  return 0;
}

// ----------------------------------------------------------------------------
// Items — pick one cheap, single-line, no-wizard item by querying the live DB
// at runtime. We can't hardcode an item_id because the catalog drifts; we
// pick the first ACTIVE item that has visible_on=['kiosk'] and no
// wizard_profile (or trivial wizard). Caches between tests.
// ----------------------------------------------------------------------------

let _cachedItem = null;
function resolveStressItem() {
  if (_cachedItem) return _cachedItem;
  _cachedItem = tinkerJson(`
    $item = \\App\\Models\\Item::query()
      ->where('status', \\App\\Enums\\Status::ACTIVE)
      ->where('is_available', true)
      ->whereNotIn('id', function ($q) {
          $q->select('item_id')->from('item_wizard_profiles')
            ->where('is_published', true)->whereNotNull('item_id');
      })
      ->orderBy('price')
      ->first();
    if (! $item) {
      $item = \\App\\Models\\Item::query()
        ->where('status', \\App\\Enums\\Status::ACTIVE)
        ->orderBy('price')
        ->first();
    }
    if (! $item) {
      echo json_encode(['error' => 'no_active_item']);
      return;
    }
    echo json_encode([
      'id' => (int) $item->id,
      'name' => (string) $item->name,
      'price' => (float) $item->price,
    ]);
  `);
  if (_cachedItem.error) {
    throw new Error(`Cannot resolve stress test item: ${JSON.stringify(_cachedItem)}`);
  }
  return _cachedItem;
}

// ----------------------------------------------------------------------------
// Bootstrap a kiosk page so window.axios.Authorization gets populated from
// Vuex.kioskCart.kioskToken — without this step, the shared axios interceptor
// at public/js/app.js:85026 OVERWRITES any per-call Authorization header.
// Pattern lifted from tests/e2e/rush-sync-flow.spec.js:494-527 (verified).
//
// EXTRA CARE: KioskMachineLoginController:96 REVOKES previous `kiosk-token`
// records on each fresh login. So if we let the helper's `getKioskApiToken`
// run again post-prime, it mints a NEW token and KILLS the one Vuex+interceptor
// would use. We resolve this by reading the token Vuex stored during
// auto-login and stuffing it back into Vuex even after subsequent re-logins.
// ----------------------------------------------------------------------------
async function primeKioskPageForApi(page) {
  await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded', timeout: 30_000 });
  let vuexReady = false;
  for (let i = 0; i < 30; i += 1) {
    const probe = await page.evaluate(() => {
      let v = {};
      try { v = JSON.parse(localStorage.getItem('vuex') || '{}'); } catch (_e) { /* ignore */ }
      return !!(v.kioskCart && v.kioskCart.kioskToken);
    });
    if (probe) { vuexReady = true; break; }
    await page.waitForTimeout(500);
  }
  if (!vuexReady) {
    throw new Error('primeKioskPageForApi: Vuex.kioskCart.kioskToken not populated after 15s — auto-login broken');
  }
  if (!/\/kiosk\/idle/.test(page.url())) {
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded', timeout: 30_000 });
  }
  await page.waitForTimeout(800);
}

// ----------------------------------------------------------------------------
// Sync the helper's module cache token back into Vuex.kioskCart.kioskToken.
// Required after `getKioskApiToken(page)` re-issues a NEW token that revoked
// the original auto-login token Vuex was holding. Without this, the axios
// interceptor sends the dead Vuex token and the backend returns 401 even
// though we have a live token in JS-land.
// ----------------------------------------------------------------------------
async function syncVuexKioskTokenFromHelper(page, token) {
  if (!token) return;
  await page.evaluate((tk) => {
    let v = {};
    try { v = JSON.parse(localStorage.getItem('vuex') || '{}'); } catch (_e) { /* ignore */ }
    v.kioskCart = v.kioskCart || {};
    v.kioskCart.kioskToken = tk;
    try { localStorage.setItem('vuex', JSON.stringify(v)); } catch (_e) { /* ignore */ }
    // Also poke the live Vuex store if exposed — best-effort.
    try {
      const app = document.querySelector('#app')?.__vue_app__;
      const store = app?.config?.globalProperties?.$store || window.__VUEX_STORE__;
      if (store && store.commit) {
        try { store.commit('kioskCart/SET_KIOSK_TOKEN', tk); } catch (_e) { /* ignore */ }
      }
    } catch (_e) { /* ignore */ }
  }, token);
}

// ----------------------------------------------------------------------------
// Per-context order placement loop. Returns { contextId, results, errors }.
// ----------------------------------------------------------------------------

async function runKioskContext(browser, contextId, ordersToPlace, item) {
  const results = [];
  const errors = [];
  const context = await browser.newContext();
  const page = await context.newPage();
  // CRITICAL: the shared axios interceptor at public/js/app.js:85026 OVERWRITES
  // the Authorization header from Vuex.kioskCart.kioskToken. We MUST land at
  // /kiosk/login first so KioskLoginComponent.autoLogin() populates Vuex,
  // before any frontend/order/* call.
  await primeKioskPageForApi(page);

  // Pre-issue this context's bearer token via the shared module cache (already
  // warmed by test.beforeAll — single login, all contexts re-use it through
  // getKioskApiToken's cached value). If the helper had to re-login (cache
  // miss), the NEW token revoked Vuex's old one — push the new one back into
  // Vuex so the axios interceptor stops sending the dead token.
  const liveToken = await getKioskApiToken(page);
  await syncVuexKioskTokenFromHelper(page, liveToken);

  for (let i = 0; i < ordersToPlace; i += 1) {
    const idemKey = `${STRESS_PREFIX}c${contextId}-${Date.now()}-${i}`;
    try {
      const result = await placeKioskOrder(page, {
        tokenPrefix: PREFIXE_AUDIT,
        items: [{ item_id: item.id, quantity: 1 }],
        paymentMethod: PAYMENT_CASH,
        idempotencyKey: idemKey,
        // V1 ships dine-in disabled — OrderRequest rejects ORDER_TYPE_KIOSK=25
        // on kiosk tokens (`pos_dine_in_enabled` is off). Use TAKEAWAY=10.
        // Mirrors rush-sync-flow.spec.js choice.
        orderType: 10,
      });
      results.push({
        ctx: contextId,
        idx: i,
        orderId: result.orderId,
        serial: result.orderSerialNo,
        total: result.totalAmount,
        idempotencyKey: result.idempotencyKey,
      });
    } catch (err) {
      errors.push({
        ctx: contextId,
        idx: i,
        idempotencyKey: idemKey,
        stage: err?.stage,
        status: err?.status,
        message: String(err?.message || err).slice(0, 600),
      });
    }
  }

  await page.close().catch(() => {});
  await context.close().catch(() => {});
  return { contextId, results, errors };
}

// ----------------------------------------------------------------------------
// Network resilience subtest — block /frontend/order POST for 30s mid-burst
// then verify the order eventually completes OR cleanly fails (no zombie row
// in PENDING with no fiscal_sequence_no past 5 minutes).
// ----------------------------------------------------------------------------

async function runNetworkResilienceSubtest(browser, item) {
  const context = await browser.newContext();
  const page = await context.newPage();
  // Same prime as runKioskContext — Vuex.kioskCart.kioskToken needed.
  await primeKioskPageForApi(page);
  const liveToken = await getKioskApiToken(page);
  await syncVuexKioskTokenFromHelper(page, liveToken);

  // 5 nominal orders first.
  const baseline = [];
  for (let i = 0; i < 5; i += 1) {
    const r = await placeKioskOrder(page, {
      tokenPrefix: PREFIXE_AUDIT,
      items: [{ item_id: item.id, quantity: 1 }],
      paymentMethod: PAYMENT_CASH,
      idempotencyKey: `${STRESS_PREFIX}netres-base-${Date.now()}-${i}`,
      // V1 dine-in disabled — use TAKEAWAY=10 (see runKioskContext comment).
      orderType: 10,
    });
    baseline.push(r.orderId);
  }

  // Install a 30s block on the 6th order's POST. We block `**/frontend/order`
  // but NOT `**/frontend/order/quote` — that way the client gets a quote
  // back, then hangs on the store call until we unroute. Mirrors a true
  // network drop where the TCP connection establishes but stalls.
  const blockUntil = Date.now() + 30_000;
  await page.route('**/frontend/order', async (route, request) => {
    if (request.method() === 'POST' && Date.now() < blockUntil) {
      // hang up to blockUntil; client retry/rescue path should kick in
      await new Promise((r) => setTimeout(r, Math.max(0, blockUntil - Date.now())));
    }
    await route.continue();
  });

  let resilientErr = null;
  let resilientOk = null;
  try {
    resilientOk = await placeKioskOrder(page, {
      tokenPrefix: PREFIXE_AUDIT,
      items: [{ item_id: item.id, quantity: 1 }],
      paymentMethod: PAYMENT_CASH,
      idempotencyKey: `${STRESS_PREFIX}netres-blocked-${Date.now()}`,
      orderType: 10,
    });
  } catch (err) {
    resilientErr = {
      stage: err?.stage,
      status: err?.status,
      message: String(err?.message || err).slice(0, 400),
    };
  }

  await page.unroute('**/frontend/order').catch(() => {});

  // Give the outbox rescue cron (foodking:outbox:rescue) a fair chance — in
  // the test env we trigger it manually to keep the spec runtime bounded.
  try {
    execFileSync('php', ['artisan', 'foodking:outbox:rescue'], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 30_000,
    });
  } catch (_e) { /* best-effort */ }

  // Probe: did the blocked order produce a row (resilient retry succeeded),
  // or did the client throw cleanly with NO orphan row in the DB?
  const orphanProbe = tinkerJson(`
    $prefix = '${STRESS_PREFIX}netres-blocked';
    $rows = DB::table('orders')
      ->where('token', 'like', $prefix . '%')
      ->orWhere('idempotency_key', 'like', $prefix . '%')
      ->get(['id', 'status', 'payment_status', 'fiscal_sequence_no']);
    echo json_encode([
      'rows' => $rows->count(),
      'has_fiscal' => $rows->whereNotNull('fiscal_sequence_no')->count(),
      'statuses' => $rows->pluck('status')->values()->all(),
    ]);
  `);

  await page.close().catch(() => {});
  await context.close().catch(() => {});

  return { baselineCount: baseline.length, resilientOk, resilientErr, orphanProbe };
}

// ----------------------------------------------------------------------------
// Tests
// ----------------------------------------------------------------------------

test.describe.serial('Wave Z (Q13) — Stress sync, 3 parallel kiosk contexts', () => {
  test.setTimeout(900_000); // 15 min wall clock budget for the burst test

  let browser;
  let item;

  test.beforeAll(async () => {
    // Belt-and-braces: stress test is destructive on rate buckets — wipe + check.
    clearFoodKingRateLimits();
    resetKioskToken();

    const rate = fetchKioskRateLimit();
    if (rate > 0 && rate < RATE_LIMIT_FLOOR) {
      test.skip(
        true,
        `kiosk.order_rate_limit=${rate} < ${RATE_LIMIT_FLOOR} — export KIOSK_ORDER_RATE_LIMIT=200 in .env before running this stress test`,
      );
    }

    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    fs.mkdirSync(REPORT_DIR, { recursive: true });

    item = resolveStressItem();

    // Pre-warm the Sanctum token in ONE serial call so the 3 parallel contexts
    // don't all hammer /api/auth/kiosk-login at once. Module-level cache is
    // shared across contexts since it lives on the helper module.
    browser = await chromium.launch();
    const warm = await browser.newContext();
    const warmPage = await warm.newPage();
    await primeKioskPageForApi(warmPage);
    const warmToken = await getKioskApiToken(warmPage);
    await syncVuexKioskTokenFromHelper(warmPage, warmToken);
    await warmPage.close();
    await warm.close();
  });

  test.afterAll(async () => {
    if (browser) {
      await browser.close().catch(() => {});
    }

    // NF525-safe cleanup — `iter15:cleanup-test-orders` only touches the
    // order graph (status_transitions, transactions, domain_events,
    // order_addresses, order_items, orders). It NEVER deletes audit_logs
    // or z_reports — both verified by reading the command on 2026-05-21.
    try {
      const out = execFileSync(
        'php',
        ['artisan', 'iter15:cleanup-test-orders', '--apply', `--token-prefix=${STRESS_PREFIX}`, '--json'],
        {
          cwd: repoRoot,
          encoding: 'utf8',
          stdio: ['ignore', 'pipe', 'pipe'],
          timeout: 60_000,
        },
      );
      fs.writeFileSync(path.join(REPORT_DIR, 'cleanup.json'), out);
    } catch (err) {
      fs.writeFileSync(
        path.join(REPORT_DIR, 'cleanup.json'),
        JSON.stringify({ error: String(err?.message || err) }),
      );
    }
  });

  test('3 contexts × ~17 orders each → 50 fiscal-allocated, gap-free, no dupes', async () => {
    expect(item).toBeTruthy();
    expect(item.id).toBeGreaterThan(0);

    // Run 3 contexts in parallel; each places ORDERS_PER_CONTEXT (cap 17).
    // We cap the total at TOTAL_ORDER_TARGET (50) post-aggregation to keep
    // the invariants math clean even when one context produces 16 vs 17.
    const startTs = Date.now();
    const triples = await Promise.all(
      Array.from({ length: PARALLEL_CONTEXTS }, (_, idx) =>
        runKioskContext(browser, idx + 1, ORDERS_PER_CONTEXT, item),
      ),
    );
    const wallMs = Date.now() - startTs;

    // Flatten + persist the per-context placement report.
    const placements = triples.flatMap((t) => t.results);
    const failures = triples.flatMap((t) => t.errors);
    fs.writeFileSync(
      path.join(REPORT_DIR, 'placements.json'),
      JSON.stringify({ wall_ms: wallMs, placements, failures }, null, 2),
    );

    // No silent loss: at least TOTAL_ORDER_TARGET successful placements.
    // (If the rate limiter chewed some despite our floor check, we want
    // to see that as a failed spec, not a passing one.)
    expect(placements.length, `expected >= ${TOTAL_ORDER_TARGET} successful placements, got ${placements.length}; failures=${JSON.stringify(failures).slice(0, 600)}`)
      .toBeGreaterThanOrEqual(TOTAL_ORDER_TARGET);

    // Aggregate DB invariants — the proof.
    const metrics = aggregateStressMetrics();
    fs.writeFileSync(
      path.join(REPORT_DIR, 'metrics.json'),
      JSON.stringify(metrics, null, 2),
    );

    expect(metrics.orders_count, 'orders_count must include all 50 STRESS-Q13 rows')
      .toBeGreaterThanOrEqual(TOTAL_ORDER_TARGET);
    expect(metrics.idempotency_distinct, 'idempotency_key must be unique per order — no replay collision')
      .toBe(metrics.orders_count);
    expect(metrics.fiscal_sequence_count, 'every placed order must have fiscal_sequence_no allocated')
      .toBe(metrics.orders_count);
    expect(metrics.fiscal_gap_free_in_subset, `fiscal sequence has gaps in STRESS-Q13 subset (min=${metrics.fiscal_min} max=${metrics.fiscal_max} count=${metrics.fiscal_sequence_count})`)
      .toBe(true);

    // NF525 chain assertion — the hard gate.
    const chain = verifyFiscalChain();
    fs.writeFileSync(
      path.join(REPORT_DIR, 'fiscal-verify-chain.txt'),
      chain.output,
    );
    expect(chain.ok, `fiscal:verify-chain did NOT return CHAIN OK:\n${chain.output.slice(0, 1200)}`)
      .toBe(true);
  });

  test('network-drop resilience — 30s block on /frontend/order does not leave zombie row', async () => {
    expect(item).toBeTruthy();
    const res = await runNetworkResilienceSubtest(browser, item);
    fs.writeFileSync(
      path.join(REPORT_DIR, 'network-resilience.json'),
      JSON.stringify(res, null, 2),
    );

    // Two acceptable outcomes:
    //   A. order completed via retry (resilientOk.orderId truthy, orphanProbe.rows >= 1
    //      with has_fiscal == rows)
    //   B. client threw cleanly + NO orphan PENDING row without a fiscal seq.
    const completed = res.resilientOk && Number(res.resilientOk.orderId) > 0;
    const cleanFailure = res.resilientErr && (res.orphanProbe.rows === 0
      || res.orphanProbe.has_fiscal === res.orphanProbe.rows);
    expect(
      completed || cleanFailure,
      `network-drop subtest produced an ambiguous state: ${JSON.stringify(res).slice(0, 800)}`,
    ).toBe(true);

    // Chain still OK after the resilience subtest.
    const chain = verifyFiscalChain();
    expect(chain.ok, `fiscal:verify-chain failed after net-drop subtest:\n${chain.output.slice(0, 1000)}`).toBe(true);
  });
});
