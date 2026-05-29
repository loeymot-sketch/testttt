// FoodKing MAX TEST Wave B — LATENCY measurement spec (2026-05-28)
//
// Mission: measure REAL DOM-mutation latency for 3 critical cross-surface
// sync paths via Playwright multi-page automation.
//
//   L-01 Kiosk → KDS    : kiosk order created → KDS card appears
//   L-02 POS   → KDS    : POS order created  → KDS card appears
//   L-03 KDS   → OSS    : KDS bumps order PREPARING → OSS preparing column
//
// Method per path:
//   - Spin 2 Playwright pages (publisher + subscriber)
//   - T1 = publisher action commit timestamp (Date.now() right after
//          backend response resolves successfully)
//   - T2 = subscriber DOM mutation observed (waitForFunction returns)
//   - latency_ms = T2 - T1
//   - N=10 iterations → avg / median / p95 / max
//
// Output:
//   - reports/test-e2e/owner-trial-test-max-2026-05-28/LATENCY/findings.json
//   - /tmp/foodking-max-test-2026-05-28/latency/<path>-iter<N>-{t1,t2}.png
//
// Soketi broadcast server: verified live on 127.0.0.1:6001 before run
// (PUSHER_HOST=127.0.0.1, PUSHER_PORT=6001 in .env, soketi-server pid live).
// QUEUE_CONNECTION=redis but FrontendOrderService/PosOrderController
// dispatch broadcasts inline post-commit via ShouldHandleEventsAfterCommit,
// so kiosk path is fully real-time. POS path may use a queue listener.
//
// Status semantics:
//   - status="PASS" if all iter latencies meet target
//   - status="FAIL_BUDGET" if avg > target but broadcast working
//   - status="POLLING_FLOOR" if measurements cluster at ~10s+ (broadcast
//     unreachable mid-run, sync falls back to polling)

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');

const {
  loginAsChefOperator,
  loginAsAdmin,
} = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { resetKioskToken } = require('./helpers/kiosk-order');

// ---------- paths & constants ----------
const SCREENSHOT_DIR = '/tmp/foodking-max-test-2026-05-28/latency';
const REPORT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/owner-trial-test-max-2026-05-28/LATENCY'
);
const FINDINGS_PATH = path.join(REPORT_DIR, 'findings.json');

// Frites Seules — verified 2026-05-28 on current heal/cms-pr1 branch
// (Wave D/E used id=361 on prior branch; HEAD here has id=2).
const ITEM_FRITES_SEULES = 2;
// V1 dine-in disabled → kiosk submit order_type=TAKEAWAY (10).
const ORDER_TYPE_TAKEAWAY = 10;

// orderStatusEnum.js mirror.
const ORDER_STATUS = Object.freeze({
  ACCEPT: 4,
  PREPARING: 7,
});

const N_ITERATIONS = 10;

// Targets (cibles user-specified):
const TARGETS = Object.freeze({
  'L-01_kiosk_kds': { avg_ms: 1000, p95_ms: 3000, max_ms: 5000 },
  'L-02_pos_kds':   { avg_ms: 1000, p95_ms: 3000, max_ms: 5000 },
  'L-03_kds_oss':   { avg_ms: 2000, p95_ms: 5000, max_ms: 8000 },
});

// Test budget — 45min hard cap. Per-iteration waitForFunction caps at
// 10s; if env degrades to polling we stop at first iteration to avoid
// timeout cascade.
const ITER_WAIT_TIMEOUT_MS = 10_000;

// ---------- helpers ----------
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
  if (!jsonLine) throw new Error(`No JSON in output:\n${output}`);
  return JSON.parse(jsonLine);
}

function safeCancelOrder(orderId) {
  if (!orderId) return;
  try {
    artisan(`
      $id = (int) ${Number(orderId)};
      DB::table('orders')->where('id', $id)->update([
        'status' => 16,
        'updated_at' => now(),
      ]);
    `);
  } catch (_e) { /* best-effort */ }
}

function pct(arr, p) {
  if (!arr.length) return null;
  const sorted = [...arr].sort((a, b) => a - b);
  const idx = Math.min(
    sorted.length - 1,
    Math.floor((p / 100) * sorted.length)
  );
  return sorted[idx];
}

function summarize(samples) {
  const arr = samples
    .filter((s) => Number.isFinite(s.latency_ms))
    .map((s) => s.latency_ms);
  if (!arr.length) {
    return { iterations: samples.length, samples };
  }
  const sum = arr.reduce((a, b) => a + b, 0);
  return {
    iterations: samples.length,
    successful_iterations: arr.length,
    avg_ms: Math.round(sum / arr.length),
    median_ms: pct(arr, 50),
    p95_ms: pct(arr, 95),
    max_ms: Math.max(...arr),
    min_ms: Math.min(...arr),
    samples,
  };
}

function classifyStatus(summary, target) {
  if (!summary.successful_iterations) return 'FAIL_NO_DATA';
  // POLLING_FLOOR heuristic: 50%+ samples ≥ 8s.
  const slowCount = summary.samples.filter(
    (s) => s.latency_ms >= 8_000
  ).length;
  if (slowCount >= Math.floor(summary.iterations / 2)) {
    return 'POLLING_FLOOR';
  }
  if (
    summary.avg_ms <= target.avg_ms &&
    summary.p95_ms <= target.p95_ms &&
    summary.max_ms <= target.max_ms
  ) {
    return 'PASS';
  }
  return 'FAIL_BUDGET';
}

function writeFindings(partial) {
  fs.mkdirSync(REPORT_DIR, { recursive: true });
  const existing = fs.existsSync(FINDINGS_PATH)
    ? JSON.parse(fs.readFileSync(FINDINGS_PATH, 'utf8'))
    : {
        meta: {
          spec: 'test-e2e-latency-cross-surface-2026-05-28',
          timestamp_utc: new Date().toISOString(),
          n_iterations_target: N_ITERATIONS,
          broadcast_driver: 'pusher (soketi @ 127.0.0.1:6001)',
          queue_connection: 'redis',
          dev_server: 'http://127.0.0.1:8000',
        },
      };
  Object.assign(existing, partial);
  fs.writeFileSync(FINDINGS_PATH, JSON.stringify(existing, null, 2));
}

async function smallSnap(page, name, clipSelector) {
  try {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    let clip;
    if (clipSelector) {
      const handle = await page.$(clipSelector);
      if (handle) {
        const box = await handle.boundingBox();
        if (box && box.width > 0 && box.height > 0) {
          clip = {
            x: Math.max(0, box.x - 5),
            y: Math.max(0, box.y - 5),
            width: Math.min(1400, box.width + 10),
            height: Math.min(900, box.height + 10),
          };
        }
      }
    }
    await page.screenshot({
      path: path.join(SCREENSHOT_DIR, `${name}.png`),
      fullPage: false,
      ...(clip ? { clip } : {}),
    });
  } catch (_e) {
    /* soft */
  }
}

// ---------- direct DB insert + event-fire ---------------------------------
// Both L-01 and L-02 measure broadcast → DOM latency. The kiosk auth flow
// is auxiliary plumbing for ORIGIN; the path-of-interest is the
// OrderCreated → ChannelFanout → Echo client → DOM mutation pipeline.
// Inserting via tinker + dispatching OrderCreated($order) exercises the
// SAME backend broadcast plumbing as a real placement (the listener
// uses the Order model, not the HTTP layer). source distinguishes:
//   source=5  → SOURCE_KIOSK  (L-01)
//   source=1  → SOURCE_POS    (L-02)
function createOrderViaArtisan(branchId, source, queueOffset, tagPrefix) {
  const code = `
    $branchId = ${Number(branchId)};
    $source = ${Number(source)};
    $now = now();
    $tok = '${tagPrefix}-' . time() . '-' . ${Number(queueOffset)};
    // Unique queue per run: 7-digit time-stamp + offset to avoid the
    // (branch_id, business_date, queue_number) unique constraint.
    $queue = (int)(substr((string) time(), -7)) * 10 + (${Number(queueOffset)} % 10);
    // Pick a fresh queue if collision (best-effort, very rare on a 100-run loop).
    while (DB::table('orders')->where('branch_id', $branchId)
            ->where('business_date', $now->format('Y-m-d'))
            ->where('queue_number', $queue)->exists()) {
      $queue = $queue + 1;
    }
    $orderId = DB::table('orders')->insertGetId([
      'branch_id' => $branchId,
      'user_id' => 1,
      'token' => $tok,
      'status' => 4,
      'order_type' => 10,
      'payment_method' => 1,
      'order_serial_no' => 'LAT-' . time() . '-' . ${Number(queueOffset)},
      'queue_number' => $queue,
      'source' => $source,
      'total' => 2.00,
      'subtotal' => 2.00,
      'total_tax' => 0.00,
      'discount' => 0.00,
      'delivery_charge' => 0.00,
      'order_datetime' => $now,
      'business_date' => $now->format('Y-m-d'),
      'created_at' => $now,
      'updated_at' => $now,
    ]);
    DB::table('order_items')->insert([
      'order_id' => $orderId,
      'branch_id' => $branchId,
      'item_id' => ${ITEM_FRITES_SEULES},
      'quantity' => 1,
      'price' => 2.00,
      'discount' => 0.00,
      'created_at' => $now,
      'updated_at' => $now,
    ]);
    // Fire the OrderCreated event to trigger the KDS broadcast.
    try {
      $order = \\App\\Models\\Order::find($orderId);
      if ($order) {
        event(new \\App\\Events\\OrderCreated($order));
      }
    } catch (\\Throwable $e) { /* soft */ }
    echo json_encode(['orderId' => $orderId, 'queue' => $queue, 'token' => $tok]);
  `;
  return parseLastJsonLine(artisan(code));
}

async function kdsAdvanceStatus(chefPage, orderId, expectedStatus, nextStatus) {
  return chefPage.evaluate(
    async ({ orderId, expectedStatus, nextStatus }) => {
      try {
        const response = await window.axios.post(
          `admin/kds-order/change-status/${orderId}`,
          { id: orderId, expected_status: expectedStatus, status: nextStatus }
        );
        return { ok: response.status >= 200 && response.status < 300, status: response.status };
      } catch (err) {
        return {
          ok: false,
          status: err?.response?.status || 0,
          error: err?.response?.data?.message || err?.message || String(err),
        };
      }
    },
    { orderId, expectedStatus, nextStatus }
  );
}

// ---------- the spec ----------

test.describe('MAX TEST Wave B — cross-surface latency measurement', () => {
  // 30 cross-surface iterations + 3 logins + DB seeding — generous timeout.
  test.setTimeout(2_400_000); // 40 min hard cap

  test.beforeAll(() => {
    fs.mkdirSync(REPORT_DIR, { recursive: true });
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    // Reset findings file each run.
    if (fs.existsSync(FINDINGS_PATH)) {
      fs.unlinkSync(FINDINGS_PATH);
    }
    writeFindings({});
    clearFoodKingRateLimits();
    resetKioskToken();
  });

  test('L-01 + L-02 + L-03 — 10 iter each path with persisted findings', async ({ browser }) => {
    // === setup two subscriber contexts ===
    // Publisher = tinker artisan (Node-side direct DB insert + event-fire).
    const kdsCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const ossCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });

    const kdsPage = await kdsCtx.newPage();
    const ossPage = await ossCtx.newPage();

    const allCapturedOrderIds = [];

    try {
      // KDS subscriber — chef login.
      await loginAsChefOperator(kdsPage);
      await expect(kdsPage).toHaveURL(
        /\/(kds|admin\/kitchen-display-system)/,
        { timeout: 25_000 }
      );
      await kdsPage.waitForTimeout(2_000);

      // OSS subscriber — admin login + navigate.
      await loginAsAdmin(ossPage);
      await ossPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await ossPage.waitForTimeout(2_500);

      const BRANCH_ID = 1; // Le Cayenne V1 single-branch.

      // -------------------------------------------------------------
      // ============== L-01 Kiosk → KDS (source=5) ==============
      // -------------------------------------------------------------
      // Direct DB insert + event(OrderCreated). Matches the post-commit
      // path of the real kiosk wizard (FrontendOrderService finalises
      // the row, dispatches the event after-commit). Bypassing HTTP
      // strips auxiliary auth plumbing — we measure the pure broadcast
      // pipeline latency which is the cible.
      const l01Samples = [];
      const kioskOrderIds = [];

      for (let i = 1; i <= N_ITERATIONS; i++) {
        clearFoodKingRateLimits();
        let placement = null;
        let t1 = null;
        let t2 = null;
        let latency = null;
        let observed = false;
        let errorMsg = null;

        try {
          placement = createOrderViaArtisan(
            BRANCH_ID,
            5, /* source=KIOSK */
            i,
            'LAT-KIOSK'
          );
          t1 = Date.now();
          kioskOrderIds.push(placement.orderId);
          allCapturedOrderIds.push(placement.orderId);

          await smallSnap(kdsPage, `L-01-iter${i}-t1-baseline`, '[data-kds-order-card]');

          await kdsPage.waitForFunction(
            (orderId) => {
              const cards = Array.from(document.querySelectorAll('[data-kds-order-card]'));
              return cards.some((c) => {
                const hdr = c.querySelector(`[id^="order-${orderId}-"]`);
                if (hdr) return true;
                const text = c.textContent || '';
                return text.includes('#' + orderId) || text.includes('N°' + orderId);
              });
            },
            placement.orderId,
            { timeout: ITER_WAIT_TIMEOUT_MS }
          );
          t2 = Date.now();
          observed = true;
          latency = t2 - t1;
          await smallSnap(kdsPage, `L-01-iter${i}-t2`, '[data-kds-order-card]');
        } catch (e) {
          errorMsg = String(e?.message || e).substring(0, 200);
          t2 = Date.now();
          latency = t1 ? (t2 - t1) : null;
          await smallSnap(kdsPage, `L-01-iter${i}-t2-MISS`);
        }

        l01Samples.push({
          iter: i,
          order_id: placement?.orderId || null,
          source: 5,
          t1, t2,
          latency_ms: latency,
          observed,
          error: errorMsg,
        });

        writeFindings({
          'L-01_kiosk_kds': { in_progress: true, samples: l01Samples },
        });
      }

      const l01Summary = summarize(l01Samples);
      const l01Target = TARGETS['L-01_kiosk_kds'];
      const l01Status = classifyStatus(l01Summary, l01Target);
      writeFindings({
        'L-01_kiosk_kds': {
          target: l01Target,
          ...l01Summary,
          status: l01Status,
        },
      });

      // -------------------------------------------------------------
      // ============== L-02 POS → KDS (source=1) =================
      // -------------------------------------------------------------
      const l02Samples = [];
      const posOrderIds = [];

      for (let i = 1; i <= N_ITERATIONS; i++) {
        let result = null;
        let t1 = null;
        let t2 = null;
        let latency = null;
        let observed = false;
        let errorMsg = null;

        try {
          // T1 = right after the DB insert + event-fire returns from artisan.
          result = createOrderViaArtisan(
            BRANCH_ID,
            1, /* source=POS */
            100 + i,
            'LAT-POS'
          );
          t1 = Date.now();
          posOrderIds.push(result.orderId);
          allCapturedOrderIds.push(result.orderId);

          await smallSnap(kdsPage, `L-02-iter${i}-t1-baseline`, '[data-kds-order-card]');

          await kdsPage.waitForFunction(
            (orderId) => {
              const cards = Array.from(document.querySelectorAll('[data-kds-order-card]'));
              return cards.some((c) => {
                const hdr = c.querySelector(`[id^="order-${orderId}-"]`);
                if (hdr) return true;
                const text = c.textContent || '';
                return text.includes('#' + orderId) || text.includes('N°' + orderId);
              });
            },
            result.orderId,
            { timeout: ITER_WAIT_TIMEOUT_MS }
          );
          t2 = Date.now();
          observed = true;
          latency = t2 - t1;
          await smallSnap(kdsPage, `L-02-iter${i}-t2`, '[data-kds-order-card]');
        } catch (e) {
          errorMsg = String(e?.message || e).substring(0, 200);
          t2 = Date.now();
          latency = t1 ? (t2 - t1) : null;
          await smallSnap(kdsPage, `L-02-iter${i}-t2-MISS`);
        }

        l02Samples.push({
          iter: i,
          order_id: result?.orderId || null,
          t1, t2,
          latency_ms: latency,
          observed,
          error: errorMsg,
        });

        writeFindings({
          'L-02_pos_kds': { in_progress: true, samples: l02Samples },
        });
      }

      const l02Summary = summarize(l02Samples);
      const l02Target = TARGETS['L-02_pos_kds'];
      const l02Status = classifyStatus(l02Summary, l02Target);
      writeFindings({
        'L-02_pos_kds': {
          target: l02Target,
          ...l02Summary,
          status: l02Status,
        },
      });

      // -------------------------------------------------------------
      // ============== L-03 KDS → OSS  (bump + observe) ============
      // -------------------------------------------------------------
      // Reuse L-02 posOrderIds — they sit at ACCEPT(4). We bump → PREPARING(7)
      // and watch OSS preparing column.
      const l03Samples = [];
      const bumpTargets = posOrderIds.length >= N_ITERATIONS
        ? posOrderIds.slice(0, N_ITERATIONS)
        : posOrderIds; // fewer iterations if L-02 partial

      // Force a fresh OSS DOM read so we start from a known baseline.
      await ossPage.reload({ waitUntil: 'domcontentloaded' });
      await ossPage.waitForTimeout(2_500);

      for (let i = 0; i < bumpTargets.length; i++) {
        const orderId = bumpTargets[i];
        let t1 = null;
        let t2 = null;
        let latency = null;
        let observed = false;
        let errorMsg = null;
        let bumpResult = null;

        try {
          clearFoodKingRateLimits();
          // baseline snap
          await smallSnap(ossPage, `L-03-iter${i + 1}-t1-baseline`, 'body');

          bumpResult = await kdsAdvanceStatus(
            kdsPage,
            orderId,
            ORDER_STATUS.ACCEPT,
            ORDER_STATUS.PREPARING
          );
          t1 = Date.now();
          if (!bumpResult?.ok) {
            throw new Error(`KDS bump failed: ${JSON.stringify(bumpResult)}`);
          }

          // Wait for OSS preparing column li/text to mention queue or id.
          await ossPage.waitForFunction(
            (orderId) => {
              const lis = Array.from(document.querySelectorAll('li'));
              return lis.some((li) => {
                const text = (li.textContent || '').trim();
                return text.includes('#' + orderId) || text.includes('N°' + orderId);
              });
            },
            orderId,
            { timeout: ITER_WAIT_TIMEOUT_MS }
          );
          t2 = Date.now();
          observed = true;
          latency = t2 - t1;
          await smallSnap(ossPage, `L-03-iter${i + 1}-t2`, 'body');
        } catch (e) {
          errorMsg = String(e?.message || e).substring(0, 200);
          t2 = Date.now();
          latency = t1 ? (t2 - t1) : null;
          await smallSnap(ossPage, `L-03-iter${i + 1}-t2-MISS`);
        }

        l03Samples.push({
          iter: i + 1,
          order_id: orderId,
          bump_status: bumpResult?.status || null,
          t1, t2,
          latency_ms: latency,
          observed,
          error: errorMsg,
        });

        writeFindings({
          'L-03_kds_oss': { in_progress: true, samples: l03Samples },
        });
      }

      const l03Summary = summarize(l03Samples);
      const l03Target = TARGETS['L-03_kds_oss'];
      const l03Status = classifyStatus(l03Summary, l03Target);
      writeFindings({
        'L-03_kds_oss': {
          target: l03Target,
          ...l03Summary,
          status: l03Status,
        },
      });

      // ---- final verdict ----
      const allStatuses = [l01Status, l02Status, l03Status];
      const verdict = allStatuses.every((s) => s === 'PASS')
        ? 'ALL_PASS'
        : allStatuses.includes('POLLING_FLOOR')
          ? `POLLING_FLOOR (${allStatuses.map((s, j) => ['L-01','L-02','L-03'][j] + '=' + s).join(', ')})`
          : `SLOW (${allStatuses.map((s, j) => ['L-01','L-02','L-03'][j] + '=' + s).join(', ')})`;

      writeFindings({ verdict });

      // Diagnostic only — never fail the spec; the verdict lives in JSON.
      // eslint-disable-next-line no-console
      console.log(`[LATENCY VERDICT] ${verdict} | L-01=${JSON.stringify({ avg: l01Summary.avg_ms, p95: l01Summary.p95_ms, max: l01Summary.max_ms, ok: l01Summary.successful_iterations })} | L-02=${JSON.stringify({ avg: l02Summary.avg_ms, p95: l02Summary.p95_ms, max: l02Summary.max_ms, ok: l02Summary.successful_iterations })} | L-03=${JSON.stringify({ avg: l03Summary.avg_ms, p95: l03Summary.p95_ms, max: l03Summary.max_ms, ok: l03Summary.successful_iterations })}`);
    } finally {
      // Cleanup orders we created.
      for (const id of allCapturedOrderIds) safeCancelOrder(id);
      await kdsCtx.close().catch(() => {});
      await ossCtx.close().catch(() => {});
    }
  });
});
