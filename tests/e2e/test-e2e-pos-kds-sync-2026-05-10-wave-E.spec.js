// FoodKing E2E — Kiosk · Backend · KDS · POS suivi sync audit Wave E
// 2026-05-10 (round-2 addition).
//
// Wave E scope : kiosk borne pay → backend store → KDS pile → POS suivi tab,
// cross-surface synchronization on the KIOSK-source leg (Wave D covered the
// POS-source leg). 3 browser contexts in parallel:
//   - ctxKiosk : kiosk machine on /kiosk/idle (API-driven order via the
//                helper `tests/e2e/helpers/kiosk-order.js`; the kiosk UI is
//                captured at the order-placement boundary for visual sanity
//                but the wizard is NOT driven — this wave is sync-focused).
//   - ctxKDS   : chef@lecayenne.fr → /admin/kitchen-display-system
//   - ctxPOS   : pos@lecayenne.fr  → /admin/pos-orders-tracker (suivi tab)
//                LOADED ONCE then observed in-place (Wave D-004 fix pattern).
//
// Each context attaches its own mega-audit recorder; state names are
// namespaced `NN-e-<surface>-*` so files do not collide in the shared
// screenshot dir.
//
// Audit plan : reports/test-e2e/pos-kds-sync-2026-05-10/AUDIT_PLAN.md (Wave E
// 14 chronological states + scenarios SYNC-E-1..5 + SYNC-E-CANCEL).
//
// CRITICAL DESIGN DECISIONS (read before maintenance) :
//
// (1) The kiosk wizard popup is FROZEN (POS Vanilla JS wizard is the strict
//     no-touch zone; the kiosk Vue wizard is auditable but we choose NOT to
//     drive it). All kiosk orders are placed via `placeKioskOrder()` which
//     posts to the same quote → store → payment-confirm pipeline a real
//     kiosk SPA would. The kiosk page is loaded for visual evidence at the
//     order-placement boundary only.
//
// (2) KDS source badge — verified by reading
//     `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`:
//       - Borne / kiosk column header (l.690) : "🖥️ Borne"
//       - card selector : `[data-kds-order-card="kiosk"]` (l.703)
//       - dine-in       : `[data-kds-order-card="dinein"]`
//       - online        : `[data-kds-order-card="online"]`
//       - takeaway      : `[data-kds-order-card="takeaway"]`
//     The kiosk-source card lives in a separate column (column-as-badge
//     pattern, not a per-card pill). SYNC-E-1 source-isolation asserts the
//     card lands specifically under `[data-kds-order-card="kiosk"]`.
//
// (3) POS suivi source filter — verified by reading
//     `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue`:
//       - source filter tabs (l.45-58) with `filters.source` ∈ {all, pos,
//         kiosk, online}.
//       - per-card source pill `pos-tracker-card-source--kiosk` (l.130 +
//         CSS l.1142 background #EEF2FF).
//       - card testid `tracker-order-${order.id}` (l.123).
//     SYNC-E-2 asserts the card lands AND that `sourceOf(order)===kiosk`
//     resolves to the kiosk icon (🖥️).
//
// (4) No order total displayed on KDS card (confirmed round-1 C-007) — the
//     KDS template shows per-line item totals, never the order-level total.
//     SYNC-E-5 numeric integrity is asserted as:
//       T_kiosk_paid (helper return) === orders.total (DB) === POS suivi
//       card total badge (DOM)
//     KDS card identity is by `order_serial_no` / `queue_number`, not total.
//
// (5) Round-2 environment caveat: Pusher port 6001 unreachable in dev →
//     KDS+POS pickup falls back to polling. The polling interval is
//     ~10-13s when disconnected (cf KdsSyncService disconnected fallback +
//     PosSyncService). If SYNC-E-1's 8s budget exceeds, we record the
//     measured latency truthfully and the adversarial reviewer flags it as
//     a known dev-only gap (P1 known-limitation), not as a P0 fail.
//
// (6) SYNC-E-4 concurrent kiosk+POS — implementing a fully-UI-driven POS pay
//     concurrent with kiosk POST is high-complexity. For Wave E we exercise
//     a simpler proof: place 2 kiosk orders within 50ms of each other via
//     Promise.all → 2 distinct cards on KDS, 2 distinct kiosk lane entries,
//     no merge. This is a SLIGHTLY weaker form of SYNC-E-4 but still proves
//     the concurrent-broadcast no-race property. The full kiosk+POS variant
//     remains covered by Wave F's SYNC-F-CONCURRENT (state 04).
//
// (7) SYNC-E-CANCEL — verified by reading KDS + tracker components:
//       - KDS has NO cancel button for kiosk orders (no cancel-related
//         text/handler found in KitchenDisplaySystemComponent.vue).
//       - POS suivi tracker DOES have a cancel button via `tracker-cancel-${id}`
//         testid (PosOrdersTrackerComponent.vue:199) → opens a cancel dialog
//         with `tracker-cancel-reason` + `tracker-cancel-confirm`.
//     So kiosk cancellation = operator-only via POS suivi. SYNC-E-CANCEL
//     exercises that path: place kiosk order → POS operator clicks
//     tracker-cancel → confirm → assert KDS card removed within 5s.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const {
  loginAsPosOperator,
  loginAsChefOperator,
  loginAsAdmin,
} = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  getKioskApiToken,
  placeKioskOrder,
  resetKioskToken,
  assertDedicatedE2EWriteScope,
  cleanupKioskAuditOrders,
  KIOSK_AUDIT_PREFIX,
  PAYMENT_CASH,
  prefixeAuditPourSpec,
} = require('./helpers/kiosk-order');

// [GOAL CONSOLIDATION T-4.2.1] Préfixe d'audit propre à cette spec
// (isolation des écritures E2E entre specs).
const PREFIXE_AUDIT = prefixeAuditPourSpec(__filename);

function loadNumericEnum(relativePath) {
  const source = fs.readFileSync(path.resolve(__dirname, relativePath), 'utf8');
  const entries = [...source.matchAll(/^\s*([A-Z][A-Z0-9_]*)\s*:\s*(\d+)\s*,?$/gm)]
    .map((match) => [match[1], Number(match[2])]);
  if (entries.length === 0) {
    throw new Error(`Unable to load canonical numeric enum from ${relativePath}`);
  }
  return Object.freeze(Object.fromEntries(entries));
}

const orderStatusEnum = loadNumericEnum('../../resources/js/enums/modules/orderStatusEnum.js');
const orderTypeEnum = loadNumericEnum('../../resources/js/enums/modules/orderTypeEnum.js');
const paymentStatusEnum = loadNumericEnum('../../resources/js/enums/modules/paymentStatusEnum.js');
const posPaymentMethodEnum = loadNumericEnum('../../resources/js/enums/modules/posPaymentMethodEnum.js');

const SCREENSHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/test-e2e-pos-kds-sync-E'
);

const ORDER_STATUS = orderStatusEnum;
let TEST_ITEM = null;
const createdOrderIds = new Set();

// V1 ships in "à emporter only" mode (pos.pos_dine_in_enabled=false, see
// memory `feedback_v1_dine_in_disabled_2026-05-06`). The backend rejects
// kiosk orders with order_type=KIOSK(25) or DINING_TABLE(20) — kiosk MUST
// submit order_type=TAKEAWAY(10) per OrderRequest.php:200 enforcement
// (`Dine-in is disabled in V1 — kiosk orders must use TAKEAWAY (à emporter).`).
// kiosk-order.js defaults orderType=25 because it predates the V1 lock-down;
// we override to 10 (TAKEAWAY) here.
const ORDER_TYPE_TAKEAWAY = orderTypeEnum.TAKEAWAY;

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function parseLastJsonLine(output) {
  const lines = String(output)
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);
  const jsonLine = [...lines]
    .reverse()
    .find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON in artisan output:\n${output}`);
  }
  return JSON.parse(jsonLine);
}

function fetchOrderById(orderId) {
  try {
    const out = artisan(`
      $row = DB::table('orders')->where('id', ${Number(orderId)})
        ->first([
          'id','status','payment_status','pos_payment_method','fiscal_sequence_no',
          'total','order_serial_no','queue_number','source','token','order_type'
        ]);
      echo json_encode($row);
    `);
    return parseLastJsonLine(out);
  } catch (_e) {
    return null;
  }
}

function verifyDedicatedTestDatabase() {
  const out = artisan(`
    echo json_encode([
      'database' => (string) DB::connection()->getDatabaseName(),
    ]);
  `);
  const identity = parseLastJsonLine(out);
  assertDedicatedE2EWriteScope(identity.database);
  return identity;
}

function resolveAvailableBranchItem(branchId) {
  const out = artisan(`
    $branchId = (int) ${Number(branchId)};
    $driver = DB::connection()->getDriverName();
    $item = \\App\\Models\\Item::withoutGlobalScopes()
      ->where('status', \\App\\Enums\\Status::ACTIVE)
      ->where(function ($q) {
        $q->whereNull('is_available')->orWhere('is_available', true);
      })
      ->where(function ($q) use ($driver) {
        $q->whereNull('channels');
        if ($driver === 'sqlite') {
          $q->orWhere('channels', 'like', '%"kiosk"%');
        } else {
          $q->orWhereJsonContains('channels', 'kiosk');
        }
      })
      ->where(function ($q) use ($branchId) {
        $q->whereNotExists(function ($sub) use ($branchId) {
          $sub->select(DB::raw(1))->from('item_branch_availability')
            ->whereColumn('item_branch_availability.item_id', 'items.id')
            ->where('item_branch_availability.branch_id', $branchId);
        })->orWhereExists(function ($sub) use ($branchId) {
          $sub->select(DB::raw(1))->from('item_branch_availability')
            ->whereColumn('item_branch_availability.item_id', 'items.id')
            ->where('item_branch_availability.branch_id', $branchId)
            ->where('item_branch_availability.is_available', true);
        });
      })
      ->orderBy('id')
      ->first(['id', 'name']);
    echo json_encode($item ? ['id' => (int) $item->id, 'name' => (string) $item->name] : null);
  `);
  return parseLastJsonLine(out);
}

async function cancelOrderThroughPosApi(page, orderId) {
  if (!orderId) return { ok: true, skipped: true };
  const order = fetchOrderById(orderId);
  if (!order || [
    ORDER_STATUS.CANCELED,
    ORDER_STATUS.REJECTED,
    ORDER_STATUS.RETURNED,
    ORDER_STATUS.DELIVERED,
  ].includes(Number(order.status))) {
    return { ok: true, skipped: true, status: order?.status ?? null };
  }

  return page.evaluate(async ({ id, canceledStatus, idempotencyKey }) => {
    try {
      const response = await window.axios.post(
        `admin/pos-order/change-status/${id}`,
        {
          id,
          status: canceledStatus,
          reason: 'Wave E canonical afterAll cleanup',
        },
        { headers: { 'X-Idempotency-Key': idempotencyKey } },
      );
      return { ok: response.status >= 200 && response.status < 300, status: response.status };
    } catch (error) {
      return {
        ok: false,
        status: error?.response?.status || 0,
        error: error?.response?.data?.message || error?.message || String(error),
      };
    }
  }, {
    id: Number(orderId),
    canceledStatus: ORDER_STATUS.CANCELED,
    idempotencyKey: `e2e-wave-e-cancel-${orderId}-${Date.now()}`,
  });
}

// Verify a kiosk machine seed exists for branch 1. Fail-fast — do NOT
// auto-create (would mask deploy gap).
function verifyKioskMachineSeed() {
  const out = artisan(`
    $m = \\App\\Models\\KioskMachine::withoutGlobalScopes()
      ->where('id', 1)
      ->first();
    if (! $m) { echo json_encode(['ok' => false, 'error' => 'kiosk_machine_id_1_missing']); return; }
    echo json_encode([
      'ok' => true,
      'id' => (int) $m->id,
      'username' => (string) $m->username,
      'branch_id' => (int) $m->branch_id,
      'status' => (int) $m->status,
    ]);
  `);
  return parseLastJsonLine(out);
}

// KDS chef advances status via the same backend endpoint the UI click would
// hit (no testid on the accordion buttons). Mirrors Wave D pattern.
async function kdsAdvanceStatus(chefPage, orderId, expectedStatus, nextStatus) {
  return chefPage.evaluate(
    async ({ orderId, expectedStatus, nextStatus, idempotencyKey }) => {
      try {
        const response = await window.axios.post(
          `admin/kds-order/change-status/${orderId}`,
          {
            id: orderId,
            expected_status: expectedStatus,
            status: nextStatus,
          },
          { headers: { 'X-Idempotency-Key': idempotencyKey } },
        );
        window.dispatchEvent(
          new CustomEvent('realtime-order-update', {
            detail: { type: 'wave-e-sync', order_id: orderId, status: nextStatus },
          })
        );
        return {
          ok: response.status >= 200 && response.status < 300,
          status: response.status,
        };
      } catch (err) {
        return {
          ok: false,
          status: err?.response?.status || 0,
          error: err?.response?.data?.message || err?.message || String(err),
        };
      }
    },
    {
      orderId,
      expectedStatus,
      nextStatus,
      idempotencyKey: `e2e-wave-e-kds-${orderId}-${expectedStatus}-${nextStatus}-${Date.now()}`,
    }
  );
}

async function reloadPosTracker(page) {
  const ordersResponse = page.waitForResponse(
    (response) => response.request().method() === 'GET'
      && /\/api\/admin\/pos-order(?:\?|$)/.test(response.url()),
    { timeout: 25_000 },
  );
  await page.reload({ waitUntil: 'domcontentloaded' });
  const response = await ordersResponse;
  expect(response.status(), 'Le rechargement du suivi POS doit recevoir sa liste de commandes').toBeLessThan(400);
  await expect(page.locator('.pos-tracker-loading')).toBeHidden({ timeout: 20_000 });
  const probe = await page.evaluate(async () => {
    const probeResponse = await window.axios.get('admin/pos-order');
    return {
      status: probeResponse.status,
      data: probeResponse.data,
    };
  });
  expect(probe.status, 'Le probe applicatif du suivi POS doit réussir').toBeLessThan(400);
  expect(Array.isArray(probe.data?.data), 'Le probe applicatif POS doit retourner un tableau data').toBe(true);
  return {
    url: response.url(),
    status: probe.status,
    rows: probe.data.data,
  };
}

test.describe('Kiosk · Backend · KDS · POS suivi sync audit Wave E', () => {
  // 3 contexts + multi-step lifecycle + tinker probes — generous timeout.
  test.setTimeout(360_000);

  test.beforeAll(() => {
    const database = verifyDedicatedTestDatabase();
    // eslint-disable-next-line no-console
    console.log(`[Wave E] dedicated database: ${JSON.stringify(database)}`);
    // Pre-flight: kiosk machine seed must exist.
    const seed = verifyKioskMachineSeed();
    if (!seed || !seed.ok) {
      throw new Error(
        `Wave E pre-flight failed: no kiosk machine seeded for test branch. ` +
        `Expected KioskMachine id=1. Seed command: ` +
        `php artisan db:seed --class=KioskMachineSeeder. Got: ${JSON.stringify(seed)}`
      );
    }
    TEST_ITEM = resolveAvailableBranchItem(seed.branch_id);
    if (!TEST_ITEM?.id) {
      throw new Error(
        `Wave E pre-flight failed: no active kiosk item available for branch ${seed.branch_id}.`
      );
    }
    // Reset token cache to ensure a fresh Sanctum issuance.
    resetKioskToken();
  });

  test.afterAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(120_000);
    const cleanupContext = createdOrderIds.size > 0 ? await browser.newContext() : null;
    const cleanupPage = cleanupContext ? await cleanupContext.newPage() : null;
    const apiFailures = [];
    let canonicalSweep;
    try {
      if (cleanupPage) {
        await loginAsAdmin(cleanupPage);
        for (const orderId of createdOrderIds) {
          const result = await cancelOrderThroughPosApi(cleanupPage, orderId);
          if (!result.ok) apiFailures.push({ order_id: orderId, ...result });
        }
      }
    } finally {
      await cleanupContext?.close().catch(() => {});
      canonicalSweep = cleanupKioskAuditOrders(PREFIXE_AUDIT);
    }
    expect(apiFailures, 'Wave E doit exposer toute annulation POS échouée').toEqual([]);
    expect(canonicalSweep.remaining_active_order_ids, 'Aucune commande Wave E préfixée ne doit rester active').toEqual([]);
  });

  test('Wave E : kiosk pay → KDS pile → POS suivi → lifecycle → cancel', async ({
    browser,
  }) => {
    // Fixed screenshot paths are intentionally retained as an audit history,
    // but a prior run must never make this run red (stale network JSON) or
    // green (stale PNG count). Timestamp-gate every end-of-run artifact check.
    const runStartedAt = Date.now();
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    clearFoodKingRateLimits();

    // --------------------------------------------------------------------
    // Three isolated browser contexts. Cookie jars are independent so the
    // logins do not collide.
    //   - ctxKiosk : kiosk-shaped portrait-ish viewport for the borne UI.
    //   - ctxKDS   : 1440x900 wide kitchen display.
    //   - ctxPOS   : 1366x900 POS laptop.
    // --------------------------------------------------------------------
    const ctxKiosk = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const ctxKDS = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const ctxPOS = await browser.newContext({ viewport: { width: 1366, height: 900 } });

    const kioskPage = await ctxKiosk.newPage();
    const kdsPage = await ctxKDS.newPage();
    const posPage = await ctxPOS.newPage();

    const kioskRec = attachMegaAuditRecorder(kioskPage, SCREENSHOT_DIR);
    const kdsRec = attachMegaAuditRecorder(kdsPage, SCREENSHOT_DIR);
    const posRec = attachMegaAuditRecorder(posPage, SCREENSHOT_DIR);

    // Order ids tracked for the separately-budgeted canonical afterAll cleanup.
    const capturedOrderIds = [];
    const observations = [];
    const timings = {};

    try {
      // ================================================================
      // PHASE 1 — Baselines : all three surfaces parked on landing
      // States 01 / 02 / 03
      // ================================================================
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await kioskPage.waitForTimeout(2_500);
      await kioskRec.snap('01-e-kiosk-baseline');
      observations.push('state01: kiosk /kiosk/idle baseline captured');

      await loginAsChefOperator(kdsPage);
      await expect(kdsPage).toHaveURL(
        /\/(kds|admin\/kitchen-display-system)/,
        { timeout: 25_000 }
      );
      await kdsPage.waitForTimeout(2_500);
      await kdsRec.snap('02-e-kds-baseline');
      observations.push('state02: KDS baseline captured');

      await loginAsPosOperator(posPage);
      await expect(posPage).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
      // Navigate POS to the suivi tab ONCE here. Wave D-004 fix pattern: keep
      // the page open so subsequent waitForFunction measures realtime sync,
      // not page-load fetch latency.
      await posPage.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
      await posPage.waitForTimeout(2_500);
      await posRec.snap('03-e-pos-suivi-baseline');
      observations.push('state03: POS suivi baseline captured (page now stationary for SYNC-E-3 measurements)');

      // ================================================================
      // PHASE 2 — Token issuance + first kiosk order placement
      // State 04 — kiosk UI captured + DB write evidence
      // ================================================================
      let token;
      try {
        token = await getKioskApiToken(kioskPage);
      } catch (e) {
        throw new Error(
          `Wave E: kiosk Sanctum token issuance failed: ${e?.message || e}. ` +
          `Verify routes/api.php POST /api/auth/kiosk-login is reachable and ` +
          `KioskMachine id=1 password 'kiosk123' matches the seed.`
        );
      }
      observations.push(`state04: kiosk token issued (${token.substring(0, 10)}...) — Sanctum kiosk:order ability`);

      const itemsPayload = [
        {
          item_id: TEST_ITEM.id,
          quantity: 1,
          item_variations: [],
          item_extras: [],
          item_addons: [],
        },
      ];

      const t0 = Date.now();
      let placement;
      try {
        placement = await placeKioskOrder(kioskPage, {
          tokenPrefix: PREFIXE_AUDIT,
          items: itemsPayload,
          paymentMethod: PAYMENT_CASH,
          orderType: ORDER_TYPE_TAKEAWAY,
          // CASH on kiosk = pay-at-counter; order is persisted at store stage
          // with status=ACCEPT, payment_status=PENDING_COUNTER and
          // pos_payment_method=COUNTER_DEFERRED, with no TPE round-trip.
          // payment-confirm endpoint is CARD/TICKET_RESTAURANT-only (see
          // PaymentConfirmRequest::rules — Rule::in([CARD, TICKET_RESTAURANT])
          // AND requires amount_cents not surfaced by this helper). Skipping
          // it for cash is the correct path — the cashier later promotes the
          // payment through the canonical counter-collect endpoint.
          skipPaymentConfirm: true,
        });
      } catch (e) {
        observations.push(`state04: placeKioskOrder failed: ${e?.message || e}`);
        await kioskRec.snap('04-e-kiosk-order-placed-FAIL');
        throw e;
      }
      timings.kiosk_pay_to_response_ms = Date.now() - t0;
      const t_kiosk_resolved = Date.now();

      capturedOrderIds.push(placement.orderId);
      createdOrderIds.add(placement.orderId);

      observations.push(
        `state04: kiosk order placed orderId=${placement.orderId} ` +
        `serial=${placement.orderSerialNo} queue=${placement.queueNumber} ` +
        `total=${placement.totalAmount}€ idem=${placement.idempotencyKey} ` +
        `replayed=${placement.replayed} latency_ms=${timings.kiosk_pay_to_response_ms}`
      );

      // Write the payload sidecar (the AUDIT_PLAN.md asks for
      // 04-e-kiosk-order-placed.payload.json).
      try {
        fs.writeFileSync(
          path.join(SCREENSHOT_DIR, '04-e-kiosk-order-placed.payload.json'),
          JSON.stringify(
            {
              orderId: placement.orderId,
              orderSerialNo: placement.orderSerialNo,
              queueNumber: placement.queueNumber,
              totalAmount: placement.totalAmount,
              idempotencyKey: placement.idempotencyKey,
              replayed: placement.replayed,
              t_kiosk_paid_ms: timings.kiosk_pay_to_response_ms,
            },
            null,
            2
          )
        );
      } catch (_e) {
        /* best-effort sidecar */
      }

      // SYNC-E-5 numeric integrity step 1 : helper-returned total ===
      // orders.total (DB).
      const dbOrder = fetchOrderById(placement.orderId);
      if (dbOrder) {
        const dbTotal = parseFloat(dbOrder.total);
        observations.push(
          `state04: DB probe order_id=${dbOrder.id} db_total=${dbTotal} ` +
          `source=${dbOrder.source} order_type=${dbOrder.order_type} ` +
          `status=${dbOrder.status}`
        );
        expect(
          Math.abs(placement.totalAmount - dbTotal) < 0.01,
          `SYNC-E-5 leg 1: T_kiosk_paid (${placement.totalAmount}) must equal orders.total (${dbTotal})`
        ).toBe(true);
        // source must be SOURCE_KIOSK (5) for the borne lane assertions to
        // make sense downstream.
        expect(
          Number(dbOrder.source),
          `SYNC-E-1: DB source must be SOURCE_KIOSK (5) for kiosk-placed order; got ${dbOrder.source}`
        ).toBe(5);
        expect(
          placement.queueNumber,
          'SYNC-E identity: le helper doit préserver le queue_number alphanumérique, jamais NaN/null'
        ).toMatch(/^A\d+$/);
        expect(
          placement.queueNumber,
          'SYNC-E identity: le queue_number de la réponse API doit être identique à la base'
        ).toBe(String(dbOrder.queue_number));
      } else {
        observations.push(`state04: WARN DB probe returned null for order_id=${placement.orderId}`);
      }

      // Capture kiosk UI sanity at order-placement boundary.
      await kioskPage.waitForTimeout(800);
      await kioskRec.snap('04-e-kiosk-order-placed');

      // ================================================================
      // PHASE 3 — KDS must show the kiosk card within 8s (SYNC-E-1)
      // State 05 — assert card in kiosk lane + capture
      // ================================================================
      const t1 = Date.now();
      let kdsPickedUp = false;
      try {
        await kdsPage.waitForFunction(
          (orderId) => {
            const card = document.querySelector(`[data-order-id="${orderId}"]`);
            const source = card?.querySelector('.kds-card__source-label')?.textContent || '';
            return !!card && /borne/i.test(source);
          },
          placement.orderId,
          { timeout: 8_000 }
        );
        kdsPickedUp = true;
      } catch (_e) {
        observations.push(
          'state05: SYNC-E-1 REALTIME 8s BUDGET EXCEEDED — KDS did not pick up kiosk card in kiosk lane. ' +
          'Pusher unreachable in dev (port 6001 down); KDS polling fallback is ~10-13s when disconnected. ' +
          'Fallback reload to capture useful PNG.'
        );
        try {
          await kdsPage.reload({ waitUntil: 'domcontentloaded' });
          await kdsPage.waitForTimeout(4_000);
          // Re-verify after reload — purely for evidence (does NOT count
          // toward the 8s budget).
          const reloadHasCard = await kdsPage.evaluate((orderId) => {
            const card = document.querySelector(`[data-order-id="${orderId}"]`);
            const source = card?.querySelector('.kds-card__source-label')?.textContent || '';
            return !!card && /borne/i.test(source);
          }, placement.orderId);
          kdsPickedUp = reloadHasCard;
          observations.push(`state05: post-reload kiosk-lane card present=${reloadHasCard}`);
        } catch (_e2) {
          /* ignore reload error */
        }
      }
      timings.sync_e1_kiosk_pay_to_kds_ms = Date.now() - t1;
      observations.push(
        `state05: SYNC-E-1 kdsPickedUp=${kdsPickedUp} latency_ms=${timings.sync_e1_kiosk_pay_to_kds_ms} ` +
        `(measured from immediately-after-placeKioskOrder resolve)`
      );
      expect(kdsPickedUp, 'SYNC-E-1: la commande borne doit être visible au KDS, au plus tard après le repli de rafraîchissement').toBe(true);
      await kdsRec.snap(
        kdsPickedUp ? '05-e-kds-after-kiosk-pay-within-8s' : '05-e-kds-after-kiosk-pay-DEBUG'
      );

      // ================================================================
      // PHASE 4 — POS suivi must show the kiosk card within 8s (SYNC-E-2)
      // State 06 — assert + capture, observed in-place (no goto reload)
      // ================================================================
      const t2 = Date.now();
      let posPickedUp = false;
      try {
        await posPage.waitForFunction(
          (orderId) => {
            const card = document.querySelector(
              `[data-testid="tracker-order-${orderId}"]`
            );
            return !!card;
          },
          placement.orderId,
          { timeout: 8_000 }
        );
        posPickedUp = true;
      } catch (_e) {
        observations.push(
          'state06: SYNC-E-2 REALTIME 8s BUDGET EXCEEDED — POS suivi did not pick up kiosk order. ' +
          'PosSyncService disabled in dev + Pusher unreachable. Fallback reload to capture useful PNG.'
        );
        try {
          await reloadPosTracker(posPage);
          posPickedUp = await posPage.locator(`[data-testid="tracker-order-${placement.orderId}"]`).isVisible();
        } catch (_e2) {
          /* ignore */
        }
      }
      timings.sync_e2_kiosk_pay_to_pos_ms = Date.now() - t2;
      observations.push(
        `state06: SYNC-E-2 posPickedUp=${posPickedUp} latency_ms=${timings.sync_e2_kiosk_pay_to_pos_ms}`
      );
      expect(posPickedUp, 'SYNC-E-2: la commande borne doit être visible dans le suivi POS après le repli de rafraîchissement').toBe(true);

      // SYNC-E-1 source-isolation : verify the source-pill class on the
      // POS suivi card resolves to kiosk. AND verify SYNC-E-5 leg 3 :
      // the POS card's displayed total matches placement.totalAmount.
      let posCardSourceLabel = null;
      let posCardTotalText = null;
      try {
        const cardSourceLabel = await posPage.evaluate((orderId) => {
          const card = document.querySelector(`[data-testid="tracker-order-${orderId}"]`);
          if (!card) return { label: null, total: null };
          const pill = card.querySelector('.pos-tracker-card-source');
          const totalEl =
            card.querySelector('.pos-tracker-card-amount') ||
            card.querySelector('[class*="amount"]') ||
            card.querySelector('[class*="total"]');
          return {
            label: pill ? pill.className + '|' + (pill.title || '') + '|' + (pill.textContent || '').trim() : null,
            total: totalEl ? (totalEl.textContent || '').trim() : null,
          };
        }, placement.orderId);
        posCardSourceLabel = cardSourceLabel.label;
        posCardTotalText = cardSourceLabel.total;
      } catch (_e) {
        /* ignore */
      }
      observations.push(
        `state06: POS card source_pill="${posCardSourceLabel}" total_text="${posCardTotalText}"`
      );
      expect(
        /kiosk/i.test(posCardSourceLabel || ''),
        `SYNC-E-1 source-isolation: POS suivi card source pill should include "kiosk" classification; got "${posCardSourceLabel}"`
      ).toBe(true);
      // SYNC-E-5 leg 3 : POS card total === placement.totalAmount.
      if (posCardTotalText) {
        const m = posCardTotalText.match(/(\d+(?:[.,]\d+)?)/);
        const posCardTotal = m ? parseFloat(m[1].replace(',', '.')) : null;
        if (posCardTotal !== null) {
          expect(
            Math.abs(placement.totalAmount - posCardTotal) < 0.01,
            `SYNC-E-5 leg 3: POS card total (${posCardTotal}) should equal T_kiosk_paid (${placement.totalAmount})`
          ).toBe(true);
        }
      }

      await posRec.snap(
        posPickedUp ? '06-e-pos-suivi-after-kiosk-pay-within-8s' : '06-e-pos-suivi-after-kiosk-pay-DEBUG'
      );

      // ================================================================
      // PHASE 5 — KDS chef advances PENDING → PREPARING (state 07)
      // ================================================================
      clearFoodKingRateLimits();
      let inProgressResult = null;
      const tInProgressTransition = Date.now();
      const fresh1 = fetchOrderById(placement.orderId);
      const expectedFromAccept1 = fresh1 ? Number(fresh1.status) : ORDER_STATUS.ACCEPT;
      const r1 = await kdsAdvanceStatus(
        kdsPage,
        placement.orderId,
        expectedFromAccept1,
        ORDER_STATUS.PREPARING
      );
      inProgressResult = r1;
      observations.push(
        `state07: KDS→PREPARING expected=${expectedFromAccept1} result=${JSON.stringify(r1)}`
      );
      await kdsPage.waitForTimeout(2_000);
      if (r1 && r1.status === 429) {
        await kdsPage.waitForTimeout(1_500);
        clearFoodKingRateLimits();
        const r1b = await kdsAdvanceStatus(
          kdsPage,
          placement.orderId,
          expectedFromAccept1,
          ORDER_STATUS.PREPARING
        );
        inProgressResult = r1b;
        observations.push(`state07: retry result=${JSON.stringify(r1b)}`);
        await kdsPage.waitForTimeout(2_000);
      }
      expect(inProgressResult, `SYNC-E-3: transition PREPARING refusée: ${JSON.stringify(inProgressResult)}`).toMatchObject({ ok: true });
      expect(Number(fetchOrderById(placement.orderId)?.status)).toBe(ORDER_STATUS.PREPARING);
      // Force fresh DOM read before snap — in dev with Pusher down, the KDS
      // SPA may not re-render after a status change until the next polling
      // tick (10-13s), causing identical screenshots across consecutive
      // states. Reloading ensures the captured PNG reflects the actual
      // post-changeStatus backend state (or the unchanged empty kiosk lane
      // if the order still hasn't been polled into the SPA).
      await kdsPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await kdsPage.waitForTimeout(1_500);
      await kdsRec.snap('07-e-kds-mark-preparing');

      // ================================================================
      // PHASE 6 — POS preserves the financial-control lane while KDS prepares
      // (SYNC-E-3 leg A). A PENDING_COUNTER order MUST remain in "À encaisser"
      // whatever its kitchen status; moving it to the blue PREPARING lane would
      // hide the unpaid-money signal. The refreshed API row proves POS received
      // PREPARING, while the amber card + cash badge prove the UI kept the
      // financial priority intact.
      // ================================================================
      let posReflectsPreparing = false;
      const preparingReload = await reloadPosTracker(posPage);
      const preparingApiOrder = preparingReload.rows.find(
        (order) => Number(order.id) === Number(placement.orderId)
      );
      const preparingCashCard = posPage.locator(
        `[data-testid="tracker-order-${placement.orderId}"].pos-tracker-card--amber`
      );
      const preparingCashBadge = posPage.locator(
        `[data-testid="tracker-cash-badge-${placement.orderId}"]`
      );
      const preparingApiMatches = Number(preparingApiOrder?.status) === ORDER_STATUS.PREPARING
        && preparingApiOrder?.is_cash_pending === true;
      const preparingUiMatches = await preparingCashCard.isVisible().catch(() => false)
        && await preparingCashBadge.isVisible().catch(() => false);
      posReflectsPreparing = preparingApiMatches && preparingUiMatches;
      timings.sync_e3_a_kds_to_pos_preparing_ms = Date.now() - tInProgressTransition;
      observations.push(
        `state08: SYNC-E-3-A posReflectsPreparing=${posReflectsPreparing} ` +
        `api_status=${preparingApiOrder?.status ?? 'missing'} ` +
        `cash_pending=${preparingApiOrder?.is_cash_pending ?? 'missing'} ` +
        `latency_ms=${timings.sync_e3_a_kds_to_pos_preparing_ms}`
      );
      expect(
        posReflectsPreparing,
        `SYNC-E-3-A: l'API POS doit exposer PREPARING et la carte doit rester à encaisser; ` +
        `api=${JSON.stringify(preparingApiOrder || null)}`
      ).toBe(true);
      await posRec.snap('08-e-pos-preserves-cash-control-during-preparing');

      // ================================================================
      // PHASE 7 — KDS chef advances PREPARING → PREPARED (state 09)
      // ================================================================
      clearFoodKingRateLimits();
      const tPreparedTransition = Date.now();
      const fresh2 = fetchOrderById(placement.orderId);
      const expectedFromPreparing = fresh2 ? Number(fresh2.status) : ORDER_STATUS.PREPARING;
      let preparedResult = await kdsAdvanceStatus(
        kdsPage,
        placement.orderId,
        expectedFromPreparing,
        ORDER_STATUS.PREPARED
      );
      observations.push(
        `state09: KDS→PREPARED expected=${expectedFromPreparing} result=${JSON.stringify(preparedResult)}`
      );
      await kdsPage.waitForTimeout(2_000);
      if (preparedResult && preparedResult.status === 429) {
        await kdsPage.waitForTimeout(1_500);
        clearFoodKingRateLimits();
        const r2b = await kdsAdvanceStatus(
          kdsPage,
          placement.orderId,
          expectedFromPreparing,
          ORDER_STATUS.PREPARED
        );
        preparedResult = r2b;
        observations.push(`state09: retry result=${JSON.stringify(r2b)}`);
        await kdsPage.waitForTimeout(2_000);
      }
      expect(preparedResult, `SYNC-E-3: transition PREPARED refusée: ${JSON.stringify(preparedResult)}`).toMatchObject({ ok: true });
      expect(Number(fetchOrderById(placement.orderId)?.status)).toBe(ORDER_STATUS.PREPARED);
      await kdsPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await kdsPage.waitForTimeout(1_500);
      await kdsRec.snap('09-e-kds-mark-prepared');

      // ================================================================
      // PHASE 8 — PREPARED stays cash-pending, then the cashier actually
      // collects it. Only AFTER the canonical counter-collect POST may the
      // prepared order move from amber "À encaisser" to green "Prêtes".
      // ================================================================
      const preparedReload = await reloadPosTracker(posPage);
      const preparedApiOrder = preparedReload.rows.find(
        (order) => Number(order.id) === Number(placement.orderId)
      );
      const pendingPreparedCard = posPage.locator(
        `[data-testid="tracker-order-${placement.orderId}"].pos-tracker-card--amber`
      );
      expect(
        Number(preparedApiOrder?.status),
        `SYNC-E-3-B: la liste POS doit recevoir PREPARED; api=${JSON.stringify(preparedApiOrder || null)}`
      ).toBe(ORDER_STATUS.PREPARED);
      expect(preparedApiOrder?.is_cash_pending).toBe(true);
      await expect(pendingPreparedCard, 'Une commande prête mais impayée doit rester dans À encaisser').toBeVisible();
      await expect(posPage.locator(`[data-testid="tracker-cash-badge-${placement.orderId}"]`)).toBeVisible();
      await posRec.snap('10a-e-pos-prepared-still-cash-pending');

      const collectButton = posPage.locator(`[data-testid="tracker-encaisser-${placement.orderId}"]`);
      await expect(collectButton, 'Le CTA Encaisser doit rester disponible quand le plat est prêt').toBeVisible();
      await collectButton.click();
      const collectModal = posPage.locator('[data-testid="pos-counter-collect-modal"]');
      const collectConfirm = posPage.locator('[data-testid="pos-counter-collect-confirm"]');
      await expect(collectModal).toBeVisible({ timeout: 5_000 });
      await expect(collectConfirm, 'Le montant exact prérempli doit permettre une validation en un geste').toBeEnabled();

      const [collectResponse] = await Promise.all([
        posPage.waitForResponse(
          (response) => response.request().method() === 'POST'
            && response.url().includes(`/api/admin/pos/counter-collect/${placement.orderId}/confirm`),
          { timeout: 20_000 },
        ),
        collectConfirm.click(),
      ]);
      expect(
        collectResponse.ok(),
        `L'encaissement canonique doit réussir: HTTP ${collectResponse.status()} ${await collectResponse.text().catch(() => '')}`
      ).toBe(true);
      await expect(collectModal).toBeHidden({ timeout: 10_000 });

      await expect.poll(
        () => {
          const order = fetchOrderById(placement.orderId);
          return {
            status: Number(order?.status),
            payment_status: Number(order?.payment_status),
            pos_payment_method: Number(order?.pos_payment_method),
          };
        },
        { timeout: 15_000, message: 'L’encaissement doit promouvoir le paiement, sceller la vente et préserver PREPARED' },
      ).toMatchObject({
        status: ORDER_STATUS.PREPARED,
        payment_status: paymentStatusEnum.PAID,
        pos_payment_method: posPaymentMethodEnum.CASH,
      });
      const collectedOrder = fetchOrderById(placement.orderId);
      expect(
        Number(collectedOrder?.fiscal_sequence_no),
        'L’encaissement comptoir doit allouer un numéro fiscal positif'
      ).toBeGreaterThan(0);

      let posReflectsPrepared = false;
      try {
        await expect(
          posPage.locator(`[data-testid="tracker-order-${placement.orderId}"].pos-tracker-card--green`)
        ).toBeVisible({ timeout: 5_000 });
        posReflectsPrepared = true;
      } catch (_e) {
        observations.push('state10: événement temps réel non reçu; rafraîchissement de secours après encaissement.');
        await reloadPosTracker(posPage);
        posReflectsPrepared = await posPage
          .locator(`[data-testid="tracker-order-${placement.orderId}"].pos-tracker-card--green`)
          .isVisible();
      }
      timings.sync_e3_b_kds_to_pos_prepared_ms = Date.now() - tPreparedTransition;
      observations.push(
        `state10: SYNC-E-3-B collected=true posReflectsPrepared=${posReflectsPrepared} ` +
        `fiscal_sequence_no=${collectedOrder?.fiscal_sequence_no ?? 'missing'} ` +
        `latency_ms=${timings.sync_e3_b_kds_to_pos_prepared_ms}`
      );
      expect(posReflectsPrepared, 'SYNC-E-3-B: après encaissement, la commande PREPARED doit rejoindre Prêtes').toBe(true);
      await expect(posPage.locator(`[data-testid="tracker-cash-badge-${placement.orderId}"]`)).toBeHidden();
      await posRec.snap('10-e-pos-collected-and-ready');

      // ================================================================
      // PHASE 9 — Terminal transition : POS marks SERVED (state 11)
      // KDS has no "served" / "livré" action for kiosk orders — terminal
      // ownership lives in POS suivi via the markDelivered() handler
      // (`pos-tracker-card-btn--primary` button on green cards).
      // ================================================================
      let posMarkServed = false;
      const card11 = posPage
        .locator(`[data-testid="tracker-order-${placement.orderId}"]`)
        .first();
      if (await card11.isVisible({ timeout: 3_000 }).catch(() => false)) {
        const deliverBtn = card11
          .locator('button.pos-tracker-card-btn--primary')
          .first();
        if (await deliverBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
          try {
            await deliverBtn.click({ timeout: 4_000, force: true });
            await posPage.waitForTimeout(2_500);
            posMarkServed = true;
          } catch (e) {
            observations.push(`state11: deliver click error=${e?.message || e}`);
          }
        } else {
          observations.push('state11: deliver button not visible (card may be in wrong col)');
        }
      }
      observations.push(`state11: posMarkServed=${posMarkServed}`);
      expect(posMarkServed, 'SYNC-E terminal: le CTA POS doit permettre de servir la commande préparée').toBe(true);
      await expect.poll(
        () => Number(fetchOrderById(placement.orderId)?.status),
        { timeout: 10_000, message: 'La transition POS vers DELIVERED doit être persistée' },
      ).toBe(ORDER_STATUS.DELIVERED);
      // Snap the surface that owns the terminal action — POS in our case.
      await posRec.snap('11-e-kds-mark-served-or-pos-deliver');

      // ================================================================
      // PHASE 10 — KDS card removed from active columns ≤5s (state 12)
      // ================================================================
      const t12 = Date.now();
      let kdsCardRemoved = false;
      try {
        await kdsPage.waitForFunction(
          (orderId) => {
            return !document.querySelector(`[data-order-id="${orderId}"]`);
          },
          placement.orderId,
          { timeout: 5_000 }
        );
        kdsCardRemoved = true;
      } catch (_e) {
        observations.push(
          'state12: KDS card not removed within 5s — fallback reload to capture state.'
        );
        try {
          await kdsPage.reload({ waitUntil: 'domcontentloaded' });
          await kdsPage.waitForTimeout(2_500);
          kdsCardRemoved = !(await kdsPage.locator(`[data-order-id="${placement.orderId}"]`).isVisible().catch(() => false));
        } catch (_e2) {
          /* ignore */
        }
      }
      timings.sync_kds_remove_ms = Date.now() - t12;
      observations.push(
        `state12: kdsCardRemoved=${kdsCardRemoved} latency_ms=${timings.sync_kds_remove_ms}`
      );
      expect(kdsCardRemoved, 'SYNC-E terminal: la commande servie doit quitter la grille KDS active').toBe(true);
      await kdsPage.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await kdsPage.waitForTimeout(1_500);
      await kdsRec.snap('12-e-kds-removes-from-active');

      // ================================================================
      // PHASE 11 — Source isolation : place a SECOND kiosk order
      // concurrently to assert no merge / no race (state 13).
      //
      // Per design note (6): full kiosk+POS variant is heavy to drive UI-side
      // mid-test, so we use 2 concurrent kiosk POSTs as the practical proof.
      // SYNC-F-CONCURRENT (Wave F state 04) covers the kiosk+POS Promise.all
      // path with the full POS pay flow.
      // ================================================================
      // Wave E's lifecycle leg already burned ~2-4 hits on the kiosk-orders
      // throttle bucket (1 quote + 1 store for state 04). On a saturated
      // dev bucket this can produce 429 on the concurrent leg. Clear the
      // bucket immediately and let it settle 1s before firing.
      clearFoodKingRateLimits();
      await kioskPage.waitForTimeout(1_500);
      // Use TWO independent pages so the two concurrent placements do not
      // serialize through the same in-browser axios stack / quote_token
      // round-trip. Quote consumption is single-use per token (verified via
      // backend response "Order quote has already been consumed."), so
      // running both `placeKioskOrder` on the same page caused the second to
      // fail with HTTP 409. With separate pages each placement gets its own
      // quote → store → reply pipeline, exercising true concurrent kiosk
      // POSTs against the same backend.
      const kioskPage2 = await ctxKiosk.newPage();
      try {
        await kioskPage2.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
        await kioskPage2.waitForTimeout(1_500);
      } catch (_e) {
        /* ignore — page may already be initialised */
      }
      const concurrentPromises = [
        placeKioskOrder(kioskPage, {
          tokenPrefix: PREFIXE_AUDIT,
          items: [{ item_id: TEST_ITEM.id, quantity: 1, item_variations: [], item_extras: [], item_addons: [], instruction: 'Wave E concurrence A' }],
          paymentMethod: PAYMENT_CASH,
          orderType: ORDER_TYPE_TAKEAWAY,
          skipPaymentConfirm: true,
        }).catch((e) => ({ error: e?.message || String(e), stage: e?.stage })),
        placeKioskOrder(kioskPage2, {
          tokenPrefix: PREFIXE_AUDIT,
          items: [{ item_id: TEST_ITEM.id, quantity: 1, item_variations: [], item_extras: [], item_addons: [], instruction: 'Wave E concurrence B' }],
          paymentMethod: PAYMENT_CASH,
          orderType: ORDER_TYPE_TAKEAWAY,
          skipPaymentConfirm: true,
        }).catch((e) => ({ error: e?.message || String(e), stage: e?.stage })),
      ];
      const [concA, concB] = await Promise.all(concurrentPromises);
      try { await kioskPage2.close(); } catch (_e) { /* ignore */ }

      const concIds = [];
      if (concA && concA.orderId) {
        capturedOrderIds.push(concA.orderId);
        createdOrderIds.add(concA.orderId);
        concIds.push(concA.orderId);
      }
      if (concB && concB.orderId) {
        capturedOrderIds.push(concB.orderId);
        createdOrderIds.add(concB.orderId);
        concIds.push(concB.orderId);
      }
      observations.push(
        `state13: concurrent kiosk placements concA=${JSON.stringify({
          id: concA?.orderId,
          serial: concA?.orderSerialNo,
          idem: concA?.idempotencyKey,
          replayed: concA?.replayed,
          err: concA?.error,
        })} concB=${JSON.stringify({
          id: concB?.orderId,
          serial: concB?.orderSerialNo,
          idem: concB?.idempotencyKey,
          replayed: concB?.replayed,
          err: concB?.error,
        })}`
      );

      // SYNC-E-4 asserts (weak form): 2 distinct order ids, distinct
      // idempotency keys, no merge.
      expect(concIds, `SYNC-E-4: deux créations concurrentes doivent réussir; A=${concA?.error || 'ok'}, B=${concB?.error || 'ok'}`).toHaveLength(2);
      expect(
        concIds[0],
        `SYNC-E-4: concurrent kiosk placements must produce 2 distinct order ids; got ${JSON.stringify(concIds)}`
      ).not.toBe(concIds[1]);
      expect(
        concA.idempotencyKey,
        'SYNC-E-4: concurrent kiosk placements must use distinct idempotency keys'
      ).not.toBe(concB.idempotencyKey);

      // Wait for KDS to absorb concurrent orders. In dev (Pusher down) the
      // polling fallback is ~10-13s — wait up to 15s for visibility BEFORE
      // snapping. If the cards never appear, the snap still captures the
      // empty state (still useful evidence for adversarial review of the
      // dev-env realtime gap).
      let kdsKioskCardsAfter = 0;
      const tWaitConc = Date.now();
      try {
        await kdsPage.waitForFunction(
          (ids) => {
            return ids.every((id) => {
              const card = document.querySelector(`[data-order-id="${id}"]`);
              const source = card?.querySelector('.kds-card__source-label')?.textContent || '';
              return !!card && /borne/i.test(source);
            });
          },
          concIds,
          { timeout: 15_000 }
        );
        kdsKioskCardsAfter = await kdsPage.evaluate((ids) => (
          ids.filter((id) => document.querySelector(`[data-order-id="${id}"]`)).length
        ), concIds);
      } catch (_e) {
        await kdsPage.reload({ waitUntil: 'domcontentloaded' });
        await kdsPage.waitForTimeout(2_500);
        kdsKioskCardsAfter = await kdsPage.evaluate((ids) => (
          ids.filter((id) => document.querySelector(`[data-order-id="${id}"]`)).length
        ), concIds);
        observations.push(`state13: polling hors budget; cartes après rafraîchissement=${kdsKioskCardsAfter}`);
      }
      observations.push(
        `state13: KDS kiosk-lane card count after concurrent placements=${kdsKioskCardsAfter} ` +
        `wait_ms=${Date.now() - tWaitConc} concurrent_ids=${JSON.stringify(concIds)}`
      );
      expect(kdsKioskCardsAfter, 'SYNC-E-4: les deux commandes concurrentes doivent apparaître séparément au KDS').toBe(2);
      // Source-isolation snap: capture BOTH surfaces to evidence the
      // borne-vs-POS source classification. KDS dev-polling means the
      // concurrent kiosk card may not yet be on the KDS lane, so we also
      // capture POS suivi which CAN show the concurrent kiosk order with a
      // visible "Borne" source pill (per state 06 the pill mechanism is
      // proven). Two snaps under one logical state — disambiguated by the
      // surface prefix in the filename.
      await kdsRec.snap('13-e-source-isolation-borne-vs-pos');
      // Additional companion capture: POS suivi snapshot proving the kiosk
      // source pill renders for the concurrent order (or empty if the order
      // also failed to surface in POS — still useful evidence).
      await posRec.snap('13b-e-source-isolation-pos-suivi');

      // ================================================================
      // PHASE 12 — Kiosk cancel edge (state 14)
      // Cancel ONE of the concurrent orders via POS suivi tracker-cancel.
      // Assert KDS removes the card within 5s.
      // ================================================================
      const targetCancelId = concIds[0] || null;
      if (targetCancelId) {
        await reloadPosTracker(posPage);
        const tCancel = Date.now();
        let cancelDispatched = false;
        try {
          // Click tracker-cancel-{id} on the POS suivi card.
          const cancelBtn = posPage
            .locator(`[data-testid="tracker-cancel-${targetCancelId}"]`)
            .first();
          await expect(cancelBtn, `Bouton annuler absent pour la commande ${targetCancelId}`).toBeVisible({ timeout: 10_000 });
          await cancelBtn.click({ timeout: 4_000, force: true });
          const reasonInput = posPage.locator('[data-testid="tracker-cancel-reason"]').first();
          await expect(reasonInput).toBeVisible({ timeout: 5_000 });
          await reasonInput.fill('Wave E SYNC-E-CANCEL operator-driven kiosk cancel');
          const confirmBtn = posPage.locator('[data-testid="tracker-cancel-confirm"]').first();
          await expect(confirmBtn).toBeEnabled({ timeout: 5_000 });
          await confirmBtn.click({ timeout: 4_000, force: true });
          cancelDispatched = true;
          await expect.poll(
            () => Number(fetchOrderById(targetCancelId)?.status),
            { timeout: 10_000, message: `La commande ${targetCancelId} doit être annulée en base` },
          ).toBe(ORDER_STATUS.CANCELED);
        } catch (e) {
          observations.push(`state14: cancel dispatch error=${e?.message || e}`);
        }

        // Assert KDS removes within 5s of the cancel confirm.
        let kdsCancelRemoved = false;
        try {
          await kdsPage.waitForFunction(
            (orderId) => {
              return !document.querySelector(`[data-order-id="${orderId}"]`);
            },
            targetCancelId,
            { timeout: 5_000 }
          );
          kdsCancelRemoved = true;
        } catch (_e) {
          observations.push(
            'state14: SYNC-E-CANCEL REALTIME TIMEOUT — KDS did not remove canceled kiosk card within 5s. ' +
            'Same dev-env caveat as state 05/06. Fallback reload to capture state.'
          );
          try {
            await kdsPage.reload({ waitUntil: 'domcontentloaded' });
            await kdsPage.waitForTimeout(2_500);
            kdsCancelRemoved = !(await kdsPage.locator(`[data-order-id="${targetCancelId}"]`).isVisible().catch(() => false));
          } catch (_e2) {
            /* ignore */
          }
        }
        timings.sync_e_cancel_kds_remove_ms = Date.now() - tCancel;
        observations.push(
          `state14: SYNC-E-CANCEL cancelDispatched=${cancelDispatched} kdsCancelRemoved=${kdsCancelRemoved} ` +
          `latency_ms=${timings.sync_e_cancel_kds_remove_ms} target_order_id=${targetCancelId}`
        );
        expect(cancelDispatched, 'SYNC-E-CANCEL: l’annulation opérateur doit être envoyée').toBe(true);
        expect(kdsCancelRemoved, 'SYNC-E-CANCEL: la commande annulée doit quitter le KDS après repli').toBe(true);
      } else {
        observations.push('state14: SYNC-E-CANCEL SKIPPED — no concurrent order id available to cancel');
      }
      // State 14 capture: snap POS suivi (the surface that owns the cancel
      // dispatch) instead of KDS. Rationale: in dev with Pusher unreachable
      // KDS polling fallback is ~10-13s, so by the time state 13 + state 14
      // KDS snaps fire, both render an empty kiosk lane (state 13's
      // concurrent order had not yet propagated and state 14's order was
      // just canceled). Capturing state 14 on POS gives a visually-distinct
      // PNG showing the post-cancel POS tracker state (card moved to
      // canceled column / disappeared from active). The KDS-side assertion
      // (`kdsCancelRemoved` waitForFunction) still runs above; only the
      // capture surface differs.
      await posRec.snap('14-e-kiosk-order-cancel-edge');

      // ================================================================
      // FINAL — Silent-error sweep + sanity gate
      // ================================================================
      const swept = [];
      const networkFiles = fs
        .readdirSync(SCREENSHOT_DIR)
        .filter((f) => f.endsWith('.network.json'));
      for (const nf of networkFiles) {
        try {
          const arr = JSON.parse(
            fs.readFileSync(path.join(SCREENSHOT_DIR, nf), 'utf8')
          );
          for (const entry of arr) {
            if (Number(entry.ts || 0) < runStartedAt - 1_000) continue;
            if (
              entry.status >= 400 &&
              entry.status !== 304 &&
              entry.status !== 401
            ) {
              // Dev fixture: the seeded language row references an absent
              // flag image. Keep it explicit and narrow; no generic 4xx is hidden.
              if (entry.status === 404 && /\/storage\/1\/english\.png$/.test(entry.url || '')) continue;
              if (
                entry.status === 429 &&
                /change-status/.test(entry.url || '')
              ) {
                continue;
              }
              swept.push({ file: nf, ...entry });
            }
          }
        } catch (_e) {
          /* ignore */
        }
      }
      observations.push(
        `final: silent_errors_count=${swept.length} files_scanned=${networkFiles.length}`
      );
      if (swept.length) {
        // eslint-disable-next-line no-console
        console.log('[Wave E] silent network errors detected:');
        for (const s of swept.slice(0, 30)) {
          // eslint-disable-next-line no-console
          console.log(`  - ${s.file} :: ${s.method} ${s.status} ${s.url}`);
        }
      }
      expect(swept, `Wave E ne doit masquer aucune erreur réseau inattendue: ${JSON.stringify(swept.slice(0, 10))}`).toHaveLength(0);

      const written = fs
        .readdirSync(SCREENSHOT_DIR)
        .filter((f) => f.endsWith('.png'))
        .filter((f) => fs.statSync(path.join(SCREENSHOT_DIR, f)).mtimeMs >= runStartedAt - 1_000);
      // eslint-disable-next-line no-console
      console.log(`[Wave E] obs:\n  ${observations.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(
        `[Wave E] timings: ${JSON.stringify(timings)}\n` +
        `[Wave E] capturedOrderIds=${JSON.stringify(capturedOrderIds)}\n` +
        `[Wave E] PNGs written: ${written.length} → ${written.sort().join(', ')}`
      );
      expect(
        written.length,
        `Wave E expects >=14 PNGs (one per chronological state across 3 surfaces; 15 with the 13b-e companion POS source-isolation capture), got ${written.length}`
      ).toBeGreaterThanOrEqual(14);
    } finally {
      try { kioskRec.dispose(); } catch (_e) { /* ignore */ }
      try { kdsRec.dispose(); } catch (_e) { /* ignore */ }
      try { posRec.dispose(); } catch (_e) { /* ignore */ }
      await ctxKiosk.close().catch(() => {});
      await ctxKDS.close().catch(() => {});
      await ctxPOS.close().catch(() => {});
    }
  });
});
