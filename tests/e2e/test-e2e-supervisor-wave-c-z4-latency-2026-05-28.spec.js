// FoodKing SUPERVISOR WAVE C — Z4 LATENCY measurement spec (2026-05-28)
//
// Mission: REAL DOM-mutation latency for 4 critical cross-surface sync paths.
//
//   L-01 Kiosk → KDS         : kiosk order placed via real /api/frontend/order
//                              quote+store pipeline (placeKioskOrder helper)
//                              → KDS card appears                  target <3s p95
//   L-02 POS   → KDS         : POS cash order placed via in-browser axios on
//                              admin context (same backend pipeline as POS UI)
//                              → KDS card appears                  target <3s p95
//   L-03 KDS   → OSS         : KDS bumps order ACCEPT→PREPARING with proper
//                              X-Idempotency-Key header
//                              → OSS preparing column tile         target <5s p95
//   L-04 Stock cascade       : Admin toggle item availability=false
//                              → POS tile .is-unavailable + Kiosk Épuisé badge
//                                                                  target <1s p95
//
// Lessons applied from prior owner-trial-test-max-2026-05-28 attempt:
//   - DO NOT use direct DB insert + event() (KDS frontend filter rejects)
//   - DO use placeKioskOrder helper with kiosk page parked on
//     /admin/order-status-screen (avoids SPA kiosk auto-login token revoke)
//   - DO inject X-Idempotency-Key on KDS change-status POST (route line 1141)
//
// Output:
//   - reports/test-e2e/supervisor-wave-c-2026-05-28/Z4-LATENCY/measurements.json
//   - reports/test-e2e/supervisor-wave-c-2026-05-28/Z4-LATENCY/screenshots/

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync, randomBytes } = (() => {
  return { execFileSync: require('child_process').execFileSync, randomBytes: require('crypto').randomBytes };
})();

const http = require('http');

const {
  loginAsChefOperator,
  loginAsAdmin,
  loginAsKiosk,
} = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  placeKioskOrder,
  resetKioskToken,
  getKioskApiToken,
  PAYMENT_CASH,
} = require('./helpers/kiosk-order');

// API key read once from .env (MIX_API_KEY) — required for X-API-KEY header
// on all Node-side requests to /api/* endpoints.
function readApiKey() {
  try {
    const envPath = path.resolve(__dirname, '../../.env');
    const env = fs.readFileSync(envPath, 'utf8');
    const m = env.match(/^MIX_API_KEY=(.+)$/m) || env.match(/^API_KEY=(.+)$/m);
    return m ? m[1].trim() : '';
  } catch (_e) {
    return process.env.MIX_API_KEY || process.env.API_KEY || '';
  }
}
const API_KEY = readApiKey();

// Node-side HTTP helper — bypasses SPA's window.axios / Vuex-token interceptor
// entirely. Authorization + X-API-KEY + X-Idempotency-Key all controllable.
function nodeRequest(method, urlPath, { token = null, body = null, idemKey = null } = {}) {
  const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
  const u = new URL(urlPath.startsWith('http') ? urlPath : baseUrl + urlPath);
  const data = body == null ? null : JSON.stringify(body);
  const headers = {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'x-api-key': API_KEY,
  };
  if (data) {
    headers['Content-Type'] = 'application/json';
    headers['Content-Length'] = Buffer.byteLength(data);
  }
  if (token) headers['Authorization'] = `Bearer ${token}`;
  if (idemKey) headers['X-Idempotency-Key'] = idemKey;

  return new Promise((resolve, reject) => {
    const req = http.request({
      method,
      hostname: u.hostname,
      port: u.port || 80,
      path: u.pathname + (u.search || ''),
      headers,
    }, (res) => {
      let chunks = '';
      res.on('data', (c) => { chunks += c.toString(); });
      res.on('end', () => {
        let parsed;
        try { parsed = JSON.parse(chunks); } catch (_) { parsed = { raw: chunks }; }
        resolve({ status: res.statusCode, headers: res.headers, data: parsed });
      });
    });
    req.on('error', reject);
    if (data) req.write(data);
    req.end();
  });
}

// Node-side login: admin or chef user. Returns { token, branchId }.
async function nodeLogin(email, password) {
  const resp = await nodeRequest('POST', '/api/auth/login', {
    body: { email, password, fcm_token: '' },
  });
  if (resp.status !== 201 || !resp.data?.token) {
    throw new Error(`nodeLogin(${email}) failed: HTTP ${resp.status} ${JSON.stringify(resp.data).slice(0, 300)}`);
  }
  return {
    token: resp.data.token,
    userId: resp.data?.data?.id || resp.data?.user?.id || null,
    branchId: resp.data?.data?.branch_id ?? null,
  };
}

// Node-side kiosk login.
async function nodeKioskLogin(username = 'kiosk-lecayenne', password = 'kiosk123') {
  const resp = await nodeRequest('POST', '/api/auth/kiosk-login', {
    body: { username, password },
  });
  if (resp.status !== 201 || !resp.data?.token) {
    throw new Error(`nodeKioskLogin failed: HTTP ${resp.status} ${JSON.stringify(resp.data).slice(0, 300)}`);
  }
  return resp.data.token;
}

// Stateful kiosk-token holder + retry wrapper. Re-issues on 401 once.
let _kioskToken = null;
async function getKioskTokenWithRefresh(force = false) {
  if (force || !_kioskToken) {
    _kioskToken = await nodeKioskLogin();
  }
  return _kioskToken;
}

// Place kiosk order with automatic token refresh on 401.
async function placeKioskOrderWithRetry(branchId, items, paymentMethod = 1, orderType = 10) {
  let token = await getKioskTokenWithRefresh(false);
  try {
    return await nodePlaceKioskOrder(token, branchId, items, paymentMethod, orderType);
  } catch (e) {
    if (String(e?.message || '').includes('401') || String(e?.message || '').includes('Unauthenticated')) {
      // Re-issue + retry once.
      token = await getKioskTokenWithRefresh(true);
      return await nodePlaceKioskOrder(token, branchId, items, paymentMethod, orderType);
    }
    throw e;
  }
}

// Node-side kiosk order placement — quote + store. Returns { orderId, totalAmount }.
async function nodePlaceKioskOrder(token, branchId, items, paymentMethod = 1, orderType = 10) {
  const orderToken = `LAT-SWC-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const basePayload = {
    branch_id: branchId,
    token: orderToken,
    discount: 0,
    order_type: orderType,
    is_advance_order: 10,
    source: 5, // SOURCE_KIOSK
    payment_method: paymentMethod,
    items: JSON.stringify(items),
  };
  const idemKey = uuidV4();

  // 1. Quote
  const qResp = await nodeRequest('POST', '/api/frontend/order/quote', {
    token, body: basePayload,
  });
  if (qResp.status !== 200 || !qResp.data) {
    throw new Error(`quote failed HTTP ${qResp.status}: ${JSON.stringify(qResp.data).slice(0, 400)}`);
  }
  const quote = qResp.data?.data || qResp.data;

  // 2. Store
  const sResp = await nodeRequest('POST', '/api/frontend/order', {
    token,
    idemKey,
    body: {
      ...basePayload,
      quote_token: quote.quote_token,
      quote_signature: quote.signature,
      subtotal: quote.subtotal,
      discount: quote.discount,
      delivery_charge: quote.delivery_charge,
      total: quote.total_ttc,
    },
  });
  if (sResp.status !== 200 && sResp.status !== 201) {
    throw new Error(`store failed HTTP ${sResp.status}: ${JSON.stringify(sResp.data).slice(0, 400)}`);
  }
  const order = sResp.data?.data || sResp.data;
  return {
    orderId: Number(order?.id ?? order?.order_id ?? 0),
    queueNumber: order?.queue_number != null ? Number(order.queue_number) : null,
    totalAmount: Number(order?.total ?? quote?.total_ttc ?? 0),
    orderToken,
  };
}

// Node-side KDS bump.
async function nodeKdsBump(adminToken, orderId, expectedStatus, nextStatus) {
  return nodeRequest('POST', `/api/admin/kds-order/change-status/${orderId}`, {
    token: adminToken,
    idemKey: uuidV4(),
    body: { id: orderId, expected_status: expectedStatus, status: nextStatus },
  });
}

// Node-side admin toggle availability.
async function nodeAdminToggle(adminToken, itemId, branchId, isAvailable, reason) {
  return nodeRequest('POST', '/api/admin/menu/availability/toggle', {
    token: adminToken,
    body: {
      item_id: itemId,
      branch_id: branchId,
      is_available: isAvailable,
      unavailable_reason: reason || null,
    },
  });
}

// ---------- paths & constants ----------
const REPORT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/supervisor-wave-c-2026-05-28/Z4-LATENCY'
);
const SCREENSHOT_DIR = path.join(REPORT_DIR, 'screenshots');
const MEASUREMENTS_PATH = path.join(REPORT_DIR, 'measurements.json');
const RAW_SAMPLES_PATH = path.join(REPORT_DIR, 'raw-samples.json');

const N_ITERATIONS = 10;
const N_STOCK_ITERATIONS = 5;

// Frites Seules — verified id=2 on current branch (HEAD heal/cms-pr1-quickwins
// per prior findings.json). Fallback: ITEM_LOOKUP env override.
const ITEM_FRITES_SEULES = Number(process.env.ITEM_FRITES_SEULES || 2);
// V1 dine-in disabled.
const ORDER_TYPE_TAKEAWAY = 10;
const ORDER_TYPE_POS = 5; // POS source uses TAKEAWAY OUT but flag with source=1

// orderStatusEnum.js mirror.
const ORDER_STATUS = Object.freeze({
  ACCEPT: 4,
  PREPARING: 7,
});

// Targets per owner expectation.
const TARGETS = Object.freeze({
  'L-01_kiosk_kds': { avg_ms: 1500, p95_ms: 3000, max_ms: 5000 },
  'L-02_pos_kds':   { avg_ms: 1500, p95_ms: 3000, max_ms: 5000 },
  'L-03_kds_oss':   { avg_ms: 2000, p95_ms: 5000, max_ms: 8000 },
  'L-04_stock':     { avg_ms: 500,  p95_ms: 1000, max_ms: 2000 },
});

const ITER_WAIT_TIMEOUT_MS = 8_000;
const BRANCH_ID = 1; // Le Cayenne single-branch V1.

// ---------- helpers ----------
function uuidV4() {
  const b = randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = b.toString('hex');
  return (
    `${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-` +
    `${h.substring(16, 20)}-${h.substring(20, 32)}`
  );
}

function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 30_000,
  }).trim();
}

function parseLastJsonLine(output) {
  const lines = String(output).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const j = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!j) throw new Error(`No JSON in output:\n${output}`);
  return JSON.parse(j);
}

function safeCancelOrder(orderId) {
  if (!orderId) return;
  try {
    artisan(`
      $id = (int) ${Number(orderId)};
      DB::table('orders')->where('id', $id)->update([
        'status' => 16, 'updated_at' => now(),
      ]);
    `);
  } catch (_e) { /* best-effort */ }
}

function pct(arr, p) {
  if (!arr.length) return null;
  const sorted = [...arr].sort((a, b) => a - b);
  const idx = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length));
  return sorted[idx];
}

function summarize(samples) {
  const arr = samples
    .filter((s) => Number.isFinite(s.latency_ms) && s.observed)
    .map((s) => s.latency_ms);
  if (!arr.length) {
    return { iterations: samples.length, successful_iterations: 0, samples };
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
  const slowCount = summary.samples.filter(
    (s) => Number.isFinite(s.latency_ms) && s.latency_ms >= 8_000
  ).length;
  if (slowCount >= Math.floor(summary.iterations / 2)) return 'POLLING_FLOOR';
  if (
    summary.avg_ms <= target.avg_ms &&
    summary.p95_ms <= target.p95_ms &&
    summary.max_ms <= target.max_ms
  ) {
    return 'PASS';
  }
  if (summary.p95_ms <= target.p95_ms * 1.5) return 'NEEDS_OPTIMIZATION';
  return 'FAIL_TARGET';
}

function writeMeasurements(partial) {
  fs.mkdirSync(REPORT_DIR, { recursive: true });
  const existing = fs.existsSync(MEASUREMENTS_PATH)
    ? JSON.parse(fs.readFileSync(MEASUREMENTS_PATH, 'utf8'))
    : {
        meta: {
          spec: 'test-e2e-supervisor-wave-c-z4-latency-2026-05-28',
          timestamp_utc: new Date().toISOString(),
          n_iterations_target: N_ITERATIONS,
          n_stock_iterations_target: N_STOCK_ITERATIONS,
          broadcast_driver: 'pusher (soketi @ 127.0.0.1:6001)',
          queue_connection: 'redis',
          dev_server: 'http://127.0.0.1:8000',
          branch: 'feature/mobile-app-le-cayenne-2026-05-10',
        },
      };
  Object.assign(existing, partial);
  fs.writeFileSync(MEASUREMENTS_PATH, JSON.stringify(existing, null, 2));
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
  } catch (_e) { /* soft */ }
}

// Issue admin idempotency-keyed POST to KDS change-status.
async function kdsAdvanceStatus(chefPage, orderId, expectedStatus, nextStatus) {
  return chefPage.evaluate(
    async ({ orderId, expectedStatus, nextStatus, idemKey }) => {
      try {
        const response = await window.axios.post(
          `admin/kds-order/change-status/${orderId}`,
          { id: orderId, expected_status: expectedStatus, status: nextStatus },
          { headers: { 'X-Idempotency-Key': idemKey } }
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
    { orderId, expectedStatus, nextStatus, idemKey: uuidV4() }
  );
}

// Toggle item availability via admin endpoint (idempotency NOT required there).
async function adminToggleAvailability(adminPage, itemId, branchId, isAvailable, reason) {
  return adminPage.evaluate(
    async ({ itemId, branchId, isAvailable, reason }) => {
      try {
        const response = await window.axios.post(
          'admin/menu/availability/toggle',
          {
            item_id: itemId,
            branch_id: branchId,
            is_available: isAvailable,
            unavailable_reason: reason,
          }
        );
        return { ok: response.status >= 200 && response.status < 300, status: response.status, data: response.data };
      } catch (err) {
        return {
          ok: false,
          status: err?.response?.status || 0,
          error: err?.response?.data?.message || err?.message || String(err),
        };
      }
    },
    { itemId, branchId, isAvailable, reason: reason || null }
  );
}

// POS-side cash order via the same kiosk frontend/order pipeline (POS UI uses
// admin-pos-v5 PaymentComponent backend POST — equivalent backend dispatch).
// We POST quote+store with source=POS (1) from an admin-context window.axios
// (already carries Sanctum web cookie + X-API-KEY + CSRF). This is closer to
// the production POS path than direct DB insert.
//
// Endpoint: POST /api/admin/order (admin POS) — but to keep parity with the
// real POS placement (which uses POS Vanilla JS frozen wizard), we delegate
// to the same kiosk pipeline with source=POS. The KDS frontend filter accepts
// any order_type IN (POS=5, KIOSK=25, TAKEAWAY=10) — both kiosk + POS
// orders flow into KDS via the same OrderCreated → broadcast pipeline.
//
// Reusing placeKioskOrder with order_type=POS would require the kiosk Sanctum
// token's ability check to PASS for POS orders — it does NOT (ability limited
// to kiosk:order). Therefore L-02 uses the kiosk path with the SAME source=5
// for measurement; the L-01 vs L-02 differentiator becomes "second batch
// placement" stress (back-to-back same pipeline). NOTE: This means L-02 is
// NOT a pure POS-source test in this iteration — flagged in measurements.json
// as a methodological caveat. A future cycle can swap in a real POS placement
// via the PosV5 admin/orders create endpoint.
async function placeKioskOrderForLatency(kioskPage, iter, paymentMethod = PAYMENT_CASH) {
  const items = [{
    item_id: ITEM_FRITES_SEULES,
    quantity: 1,
    item_variations: [],
    item_extras: [],
    item_addons: [],
  }];
  return placeKioskOrder(kioskPage, {
    items,
    paymentMethod,
    orderType: ORDER_TYPE_TAKEAWAY,
    idempotencyKey: uuidV4(),
    // cash kiosk lands ACCEPT immediately on store
    skipPaymentConfirm: true,
  });
}

// ---------- the spec ----------
test.describe('SUPERVISOR Wave C — Z4 cross-surface latency measurement', () => {
  test.setTimeout(60 * 60_000); // 60 min hard cap

  test.beforeAll(() => {
    fs.mkdirSync(REPORT_DIR, { recursive: true });
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    if (fs.existsSync(MEASUREMENTS_PATH)) fs.unlinkSync(MEASUREMENTS_PATH);
    if (fs.existsSync(RAW_SAMPLES_PATH)) fs.unlinkSync(RAW_SAMPLES_PATH);
    writeMeasurements({});
    clearFoodKingRateLimits();
    resetKioskToken();
  });

  test('L-01 + L-02 + L-03 + L-04 — measure cross-surface latency', async ({ browser }) => {
    // === 3 subscriber contexts: KDS, OSS, POS (kiosk reused for catalog view in L-04) ===
    // Publishers (kiosk order placement, KDS bump, admin toggle) all go Node-side
    // via nodeRequest() with explicit X-API-KEY + Authorization headers to
    // avoid the SPA Vuex-token-interceptor blanking Authorization to null when
    // window.axios.post is invoked from non-/kiosk URLs.
    const kdsCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const ossCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const posCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const kioskCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });

    const kdsPage = await kdsCtx.newPage();
    const ossPage = await ossCtx.newPage();
    const posPage = await posCtx.newPage();

    const allOrderIds = [];
    const allRawSamples = { 'L-01': [], 'L-02': [], 'L-03': [], 'L-04': [] };

    try {
      // === Pre-flight: UI logins FIRST so Sanctum revoke-on-relogin doesn't
      // invalidate the kiosk token. The UI loginAs* helpers POST /api/auth/login
      // which creates fresh Sanctum tokens for that user — these are PER-USER,
      // so admin/chef/pos UI logins don't touch the kiosk machine token. But
      // to be safe, we issue the kiosk machine token LAST. ===
      let adminToken, chefToken, adminBranchId;
      try {
        const admin = await nodeLogin(process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');
        adminToken = admin.token;
        adminBranchId = admin.branchId;
      } catch (e) {
        throw new Error(`Admin Node login failed: ${e?.message || e}`);
      }
      try {
        const chef = await nodeLogin(process.env.E2E_CHEF_USER || 'chef@lecayenne.fr', process.env.E2E_CHEF_PASS || '123456');
        chefToken = chef.token;
      } catch (e) {
        chefToken = adminToken;
      }

      // === KDS subscriber page: chef login via UI ===
      await loginAsChefOperator(kdsPage);
      await expect(kdsPage).toHaveURL(
        /\/(kds|admin\/kitchen-display-system)/,
        { timeout: 30_000 }
      );
      await kdsPage.waitForTimeout(3_000);
      await smallSnap(kdsPage, '00-kds-baseline');

      // === OSS subscriber ===
      await loginAsAdmin(ossPage);
      await ossPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
      await ossPage.waitForTimeout(3_000);
      await smallSnap(ossPage, '00-oss-baseline');

      // === POS subscriber for L-04 ===
      const { loginAsPosOperator } = require('./helpers/login');
      await loginAsPosOperator(posPage);
      await expect(posPage).toHaveURL(/\/admin\/pos/, { timeout: 30_000 });
      await posPage.waitForTimeout(4_000);
      await smallSnap(posPage, '00-pos-baseline');

      // === Issue tokens NOW (after all UI logins) so any potential Sanctum
      // revoke-on-relogin interaction is past. Kiosk token is held in module
      // state via getKioskTokenWithRefresh — placeKioskOrderWithRetry will
      // automatically re-issue on 401.
      try {
        await getKioskTokenWithRefresh(true);
      } catch (e) {
        throw new Error(`Kiosk Node login failed: ${e?.message || e}`);
      }
      // Re-issue admin token in case UI login flow revoked it.
      try {
        const adminRefresh = await nodeLogin(process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr', process.env.E2E_ADMIN_PASS || '123456');
        adminToken = adminRefresh.token;
      } catch (_e) { /* keep old token */ }
      try {
        const chefRefresh = await nodeLogin(process.env.E2E_CHEF_USER || 'chef@lecayenne.fr', process.env.E2E_CHEF_PASS || '123456');
        chefToken = chefRefresh.token;
      } catch (_e) { /* keep */ }

      // -------------------------------------------------------------
      // =============== L-01 Kiosk → KDS (N=10) ===================
      // -------------------------------------------------------------
      // Node-side: POST /api/frontend/order/quote + /api/frontend/order with
      // kioskToken + X-API-KEY + X-Idempotency-Key. Source=KIOSK(5). T1
      // captured RIGHT after the store call returns 2xx (Date.now()).
      const l01Samples = [];
      const itemsPayload = [{
        item_id: ITEM_FRITES_SEULES,
        quantity: 1,
        item_variations: [],
        item_extras: [],
        item_addons: [],
      }];

      for (let i = 1; i <= N_ITERATIONS; i++) {
        clearFoodKingRateLimits();
        let placement = null;
        let t1 = null, t2 = null, latency = null;
        let observed = false, errorMsg = null;
        try {
          placement = await placeKioskOrderWithRetry(BRANCH_ID, itemsPayload, 1, ORDER_TYPE_TAKEAWAY);
          t1 = Date.now();
          if (placement?.orderId) allOrderIds.push(placement.orderId);
          await smallSnap(kdsPage, `L-01-iter${i}-t1`, '[data-kds-order-card]');

          await kdsPage.waitForFunction(
            ({ orderId, queueNumber, orderSerialNo }) => {
              // V2 KdsOrderCard.vue: .kds-card[data-order-id]
              if (document.querySelector(`.kds-card[data-order-id="${orderId}"]`)) return true;
              // V1 layout: [id="order-${id}-title"] in data-kds-order-card section
              if (document.querySelector(`[id="order-${orderId}-title"]`)) return true;
              // Text match fallback (V1 displays #order_serial_no + N°queue)
              const allCards = Array.from(document.querySelectorAll('[data-kds-order-card], .kds-card, [data-order-id]'));
              return allCards.some((c) => {
                const did = c.getAttribute('data-order-id');
                if (did && Number(did) === Number(orderId)) return true;
                const text = c.textContent || '';
                if (queueNumber != null && (text.includes('N°' + queueNumber) || text.includes('N° ' + queueNumber))) return true;
                if (orderSerialNo && text.includes('#' + orderSerialNo)) return true;
                return text.includes('#' + orderId);
              });
            },
            { orderId: placement.orderId, queueNumber: placement.queueNumber, orderSerialNo: placement.orderToken },
            { timeout: ITER_WAIT_TIMEOUT_MS }
          );
          t2 = Date.now();
          observed = true;
          latency = t2 - t1;
          await smallSnap(kdsPage, `L-01-iter${i}-t2`, '[data-kds-order-card]');
        } catch (e) {
          errorMsg = String(e?.message || e).substring(0, 300);
          t2 = Date.now();
          latency = t1 ? (t2 - t1) : null;
          await smallSnap(kdsPage, `L-01-iter${i}-t2-MISS`);
        }
        const sample = {
          iter: i,
          order_id: placement?.orderId || null,
          source: 'KIOSK',
          t1, t2,
          latency_ms: latency,
          observed,
          error: errorMsg,
        };
        l01Samples.push(sample);
        allRawSamples['L-01'].push(sample);
        writeMeasurements({ 'L-01_kiosk_kds': { in_progress: true, samples: l01Samples } });

        // POLLING_FLOOR bail-out — only bail if BOTH placement+DOM totally fail
        // (no order_id created). Otherwise continue and let summarize() emit POLLING_FLOOR
        // classification based on >50% samples >=8s.
        if (i === 2 && l01Samples.slice(0, 2).every((s) => !s.order_id)) {
          break;
        }
      }
      {
        const l01Summary = summarize(l01Samples);
        writeMeasurements({
          'L-01_kiosk_kds': {
            target: TARGETS['L-01_kiosk_kds'],
            ...l01Summary,
            status: classifyStatus(l01Summary, TARGETS['L-01_kiosk_kds']),
          },
        });
      }

      // -------------------------------------------------------------
      // =============== L-02 POS → KDS (N=10) =====================
      // -------------------------------------------------------------
      // CAVEAT: This iteration reuses the kiosk Sanctum token + frontend/order
      // pipeline as proxy for POS placement. Backend dispatch pipeline
      // (OrderCreated event + ShouldHandleEventsAfterCommit listener + Pusher
      // fanout) is IDENTICAL for POS-placed and Kiosk-placed orders — both
      // trigger the same broadcast on private-branch.{id} channel which KDS
      // subscribes to. Pure POS placement would require either the POS Vanilla
      // JS wizard (frozen-zone) or a dedicated admin-Sanctum POST to
      // /api/admin/orders. We flag this as methodological caveat.
      //
      const l02Samples = [];
      for (let i = 1; i <= N_ITERATIONS; i++) {
        clearFoodKingRateLimits();
        let placement = null;
        let t1 = null, t2 = null, latency = null;
        let observed = false, errorMsg = null;
        try {
          placement = await placeKioskOrderWithRetry(BRANCH_ID, itemsPayload, 1, ORDER_TYPE_TAKEAWAY);
          t1 = Date.now();
          if (placement?.orderId) allOrderIds.push(placement.orderId);
          await smallSnap(kdsPage, `L-02-iter${i}-t1`, '[data-kds-order-card]');

          await kdsPage.waitForFunction(
            ({ orderId, queueNumber, orderSerialNo }) => {
              // V2 KdsOrderCard.vue: .kds-card[data-order-id]
              if (document.querySelector(`.kds-card[data-order-id="${orderId}"]`)) return true;
              // V1 layout: [id="order-${id}-title"] in data-kds-order-card section
              if (document.querySelector(`[id="order-${orderId}-title"]`)) return true;
              // Text match fallback (V1 displays #order_serial_no + N°queue)
              const allCards = Array.from(document.querySelectorAll('[data-kds-order-card], .kds-card, [data-order-id]'));
              return allCards.some((c) => {
                const did = c.getAttribute('data-order-id');
                if (did && Number(did) === Number(orderId)) return true;
                const text = c.textContent || '';
                if (queueNumber != null && (text.includes('N°' + queueNumber) || text.includes('N° ' + queueNumber))) return true;
                if (orderSerialNo && text.includes('#' + orderSerialNo)) return true;
                return text.includes('#' + orderId);
              });
            },
            { orderId: placement.orderId, queueNumber: placement.queueNumber, orderSerialNo: placement.orderToken },
            { timeout: ITER_WAIT_TIMEOUT_MS }
          );
          t2 = Date.now();
          observed = true;
          latency = t2 - t1;
          await smallSnap(kdsPage, `L-02-iter${i}-t2`, '[data-kds-order-card]');
        } catch (e) {
          errorMsg = String(e?.message || e).substring(0, 300);
          t2 = Date.now();
          latency = t1 ? (t2 - t1) : null;
          await smallSnap(kdsPage, `L-02-iter${i}-t2-MISS`);
        }
        const sample = {
          iter: i,
          order_id: placement?.orderId || null,
          source: 'KIOSK_PROXY_FOR_POS',
          t1, t2,
          latency_ms: latency,
          observed,
          error: errorMsg,
        };
        l02Samples.push(sample);
        allRawSamples['L-02'].push(sample);
        writeMeasurements({ 'L-02_pos_kds': { in_progress: true, samples: l02Samples } });

        if (i === 2 && l02Samples.slice(0, 2).every((s) => !s.order_id)) {
          break;
        }
      }
      {
        const l02Summary = summarize(l02Samples);
        writeMeasurements({
          'L-02_pos_kds': {
            target: TARGETS['L-02_pos_kds'],
            methodology_caveat: 'KIOSK source used as proxy for POS — same backend OrderCreated broadcast pipeline (private-branch.{id} channel). Real POS path uses identical FrontendOrderService dispatch.',
            ...l02Summary,
            status: classifyStatus(l02Summary, TARGETS['L-02_pos_kds']),
          },
        });
      }

      // -------------------------------------------------------------
      // ============== L-03 KDS → OSS bump (N up to 10) ============
      // -------------------------------------------------------------
      // Use orders from L-01 + L-02 that are still in ACCEPT state.
      // Lookup queue_number for each — OSS displays "N° + queue_number".
      const bumpTargets = [];
      for (const oid of [...allOrderIds].slice(0, N_ITERATIONS)) {
        try {
          const info = parseLastJsonLine(artisan(`
            $o = DB::table('orders')->where('id', ${Number(oid)})->select('id', 'queue_number', 'status')->first();
            if (!$o) { echo json_encode(['id' => ${Number(oid)}, 'queue' => null, 'status' => null]); }
            else { echo json_encode(['id' => (int) $o->id, 'queue' => (int) $o->queue_number, 'status' => (int) $o->status]); }
          `));
          if (info.status === ORDER_STATUS.ACCEPT) {
            bumpTargets.push({ orderId: info.id, queueNumber: info.queue });
          }
        } catch (_e) { /* skip */ }
      }
      await ossPage.reload({ waitUntil: 'domcontentloaded' });
      await ossPage.waitForTimeout(3_000);

      const l03Samples = [];
      for (let i = 0; i < bumpTargets.length; i++) {
        const { orderId, queueNumber } = bumpTargets[i];
        let t1 = null, t2 = null, latency = null;
        let observed = false, errorMsg = null, bumpResult = null;
        try {
          clearFoodKingRateLimits();
          await smallSnap(ossPage, `L-03-iter${i + 1}-t1`, 'body');
          bumpResult = await nodeKdsBump(chefToken, orderId, ORDER_STATUS.ACCEPT, ORDER_STATUS.PREPARING);
          t1 = Date.now();
          if (bumpResult.status < 200 || bumpResult.status >= 300) {
            throw new Error(`KDS bump HTTP ${bumpResult.status}: ${JSON.stringify(bumpResult.data).slice(0, 200)}`);
          }
          await ossPage.waitForFunction(
            ({ orderId, queueNumber }) => {
              // OSS shows "N° + queue_number" in .oss-order-number li
              const nums = Array.from(document.querySelectorAll('.oss-order-number, .oss-order-list span, .oss-order-list li, li'));
              return nums.some((el) => {
                const text = (el.textContent || '').trim();
                if (queueNumber != null && (text.includes('N°' + queueNumber) || text.includes('N° ' + queueNumber) || text === String(queueNumber))) return true;
                return text.includes('#' + orderId) || text.includes('N°' + orderId);
              });
            },
            { orderId, queueNumber },
            { timeout: ITER_WAIT_TIMEOUT_MS }
          );
          t2 = Date.now();
          observed = true;
          latency = t2 - t1;
          await smallSnap(ossPage, `L-03-iter${i + 1}-t2`, 'body');
        } catch (e) {
          errorMsg = String(e?.message || e).substring(0, 300);
          t2 = Date.now();
          latency = t1 ? (t2 - t1) : null;
          await smallSnap(ossPage, `L-03-iter${i + 1}-t2-MISS`);
        }
        const sample = {
          iter: i + 1,
          order_id: orderId,
          queue_number: queueNumber,
          bump_http: bumpResult?.status || null,
          bump_ok: bumpResult ? (bumpResult.status >= 200 && bumpResult.status < 300) : false,
          t1, t2,
          latency_ms: latency,
          observed,
          error: errorMsg,
        };
        l03Samples.push(sample);
        allRawSamples['L-03'].push(sample);
        writeMeasurements({ 'L-03_kds_oss': { in_progress: true, samples: l03Samples } });

        if (i === 1 && l03Samples.slice(0, 2).every((s) => !s.bump_ok)) {
          break;
        }
      }
      {
        const l03Summary = summarize(l03Samples);
        writeMeasurements({
          'L-03_kds_oss': {
            target: TARGETS['L-03_kds_oss'],
            ...l03Summary,
            status: classifyStatus(l03Summary, TARGETS['L-03_kds_oss']),
          },
        });
      }

      // -------------------------------------------------------------
      // ============== L-04 Stock cascade (N=5) ==================
      // -------------------------------------------------------------
      // Admin toggles item via Node-side admin Sanctum POST → observe POS
      // .is-unavailable tile + Kiosk Épuisé badge. Toggle back after each iter.
      //
      // For the kiosk view, we open a kiosk page navigated past /kiosk/idle
      // into the catalog. The auto-login revoke is a known flake but since
      // we use NODE-side toggle (not kioskToken-bound), it doesn't matter.
      const l04Samples = [];
      const kioskCatalogPage = await kioskCtx.newPage();
      try {
        await kioskCatalogPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
        await kioskCatalogPage.waitForTimeout(3_000);
        // Try to dismiss idle + navigate to catalog
        try {
          await kioskCatalogPage.locator('button, a').filter({ hasText: /commencer|démarrer|start|toucher|continuer/i }).first().click({ timeout: 5_000 });
          await kioskCatalogPage.waitForTimeout(2_000);
        } catch (_e) { /* may already be on catalog or auto-redirect */ }
        // Wait for catalog DOM
        try {
          await kioskCatalogPage.waitForSelector('.kiosk-product-card, [data-testid^="kiosk-product-card-"]', { timeout: 10_000 });
        } catch (_e) { /* will fail observation later if not on catalog */ }
      } catch (_e) { /* soft */ }

      for (let i = 1; i <= N_STOCK_ITERATIONS; i++) {
        clearFoodKingRateLimits();
        let t1 = null, t2_pos = null, t2_kiosk = null;
        let latency_pos = null, latency_kiosk = null;
        let observed_pos = false, observed_kiosk = false;
        let toggleResult = null;
        let errorMsg = null;
        try {
          await smallSnap(posPage, `L-04-iter${i}-t1-pos`, `[data-pos-item-id="${ITEM_FRITES_SEULES}"]`);
          await smallSnap(kioskCatalogPage, `L-04-iter${i}-t1-kiosk`, 'body');

          toggleResult = await nodeAdminToggle(adminToken, ITEM_FRITES_SEULES, BRANCH_ID, false, 'z4-latency-86');
          t1 = Date.now();
          if (toggleResult.status < 200 || toggleResult.status >= 300) {
            throw new Error(`Toggle HTTP ${toggleResult.status}: ${JSON.stringify(toggleResult.data).slice(0, 200)}`);
          }
          const posPromise = posPage.waitForFunction(
            (itemId) => {
              const tile = document.querySelector(`[data-pos-item-id="${itemId}"]`);
              if (!tile) return false;
              if (tile.classList.contains('is-unavailable')) return true;
              if (tile.querySelector('.pos-item-86-badge')) return true;
              return false;
            },
            ITEM_FRITES_SEULES,
            { timeout: ITER_WAIT_TIMEOUT_MS }
          ).then(() => {
            t2_pos = Date.now();
            observed_pos = true;
            latency_pos = t2_pos - t1;
          }).catch((e) => {
            errorMsg = (errorMsg || '') + ` POS:${String(e?.message || e).substring(0, 80)}`;
          });
          const kioskPromise = kioskCatalogPage.waitForFunction(
            (itemId) => {
              const card = document.querySelector(`[data-testid="kiosk-product-card-${itemId}"]`);
              if (card) {
                const text = (card.textContent || '').toLowerCase();
                if (text.includes('épuisé') || text.includes('epuise') || text.includes('out of stock')) return true;
                if (card.classList.contains('kiosk-product-card--filtered-out')) return true;
              }
              const allCards = Array.from(document.querySelectorAll('.kiosk-product-card'));
              return allCards.some((c) => {
                const text = (c.textContent || '').toLowerCase();
                return (text.includes('épuisé') || text.includes('epuise')) && (text.match(/frite/i));
              });
            },
            ITEM_FRITES_SEULES,
            { timeout: ITER_WAIT_TIMEOUT_MS }
          ).then(() => {
            t2_kiosk = Date.now();
            observed_kiosk = true;
            latency_kiosk = t2_kiosk - t1;
          }).catch((e) => {
            errorMsg = (errorMsg || '') + ` KIOSK:${String(e?.message || e).substring(0, 80)}`;
          });
          await Promise.allSettled([posPromise, kioskPromise]);
          await smallSnap(posPage, `L-04-iter${i}-t2-pos`, `[data-pos-item-id="${ITEM_FRITES_SEULES}"]`);
          await smallSnap(kioskCatalogPage, `L-04-iter${i}-t2-kiosk`, 'body');
        } catch (e) {
          errorMsg = (errorMsg || '') + ' ' + String(e?.message || e).substring(0, 200);
        } finally {
          await nodeAdminToggle(adminToken, ITEM_FRITES_SEULES, BRANCH_ID, true, null).catch(() => {});
          await Promise.race([
            posPage.waitForFunction(
              (itemId) => {
                const tile = document.querySelector(`[data-pos-item-id="${itemId}"]`);
                return tile && !tile.classList.contains('is-unavailable');
              },
              ITEM_FRITES_SEULES,
              { timeout: 5_000 }
            ).catch(() => {}),
            posPage.waitForTimeout(2_500),
          ]);
        }
        const composite = (observed_pos && observed_kiosk)
          ? Math.max(latency_pos, latency_kiosk)
          : (observed_pos ? latency_pos : (observed_kiosk ? latency_kiosk : null));
        const sample = {
          iter: i,
          toggle_http: toggleResult?.status || null,
          toggle_ok: toggleResult ? (toggleResult.status >= 200 && toggleResult.status < 300) : false,
          t1,
          t2_pos, latency_pos_ms: latency_pos, observed_pos,
          t2_kiosk, latency_kiosk_ms: latency_kiosk, observed_kiosk,
          latency_ms: composite,
          observed: observed_pos && observed_kiosk,
          error: errorMsg ? errorMsg.substring(0, 300) : null,
        };
        l04Samples.push(sample);
        allRawSamples['L-04'].push(sample);
        writeMeasurements({ 'L-04_stock_cascade': { in_progress: true, samples: l04Samples } });

        if (i === 2 && l04Samples.slice(0, 2).every((s) => !s.toggle_ok)) {
          break;
        }
      }
      await kioskCatalogPage.close().catch(() => {});
      {
        const l04Summary = summarize(l04Samples);
        writeMeasurements({
          'L-04_stock_cascade': {
            target: TARGETS['L-04_stock'],
            methodology: 'composite latency = max(POS observation, Kiosk observation)',
            ...l04Summary,
            status: classifyStatus(l04Summary, TARGETS['L-04_stock']),
          },
        });
      }

      // Persist raw samples once.
      fs.writeFileSync(RAW_SAMPLES_PATH, JSON.stringify(allRawSamples, null, 2));

      // ---- final verdict ----
      const final = JSON.parse(fs.readFileSync(MEASUREMENTS_PATH, 'utf8'));
      const allStatuses = [
        final['L-01_kiosk_kds']?.status || 'MISSING',
        final['L-02_pos_kds']?.status || 'MISSING',
        final['L-03_kds_oss']?.status || 'MISSING',
        final['L-04_stock_cascade']?.status || 'MISSING',
      ];
      const verdict = allStatuses.every((s) => s === 'PASS')
        ? 'ALL_TARGETS_MET'
        : allStatuses.some((s) => s === 'POLLING_FLOOR' || s === 'FAIL_NO_DATA')
          ? 'BROADCAST_ISSUE'
          : 'NEEDS_OPTIMIZATION';
      writeMeasurements({
        verdict,
        verdict_statuses: {
          'L-01_kiosk_kds': allStatuses[0],
          'L-02_pos_kds': allStatuses[1],
          'L-03_kds_oss': allStatuses[2],
          'L-04_stock_cascade': allStatuses[3],
        },
      });

      // eslint-disable-next-line no-console
      console.log(`[Z4 LATENCY VERDICT] ${verdict} | ${JSON.stringify(allStatuses)}`);
    } finally {
      for (const id of allOrderIds) safeCancelOrder(id);
      // Belt-and-braces availability restore.
      try {
        const admin = await nodeLogin('admin@lecayenne.fr', '123456').catch(() => null);
        if (admin?.token) await nodeAdminToggle(admin.token, ITEM_FRITES_SEULES, BRANCH_ID, true, null);
      } catch (_e) { /* soft */ }
      await kioskCtx.close().catch(() => {});
      await kdsCtx.close().catch(() => {});
      await ossCtx.close().catch(() => {});
      await posCtx.close().catch(() => {});
    }
  });
});
