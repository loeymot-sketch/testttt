/**
 * Kiosk order helper — Wave E (Kiosk↔Backend↔KDS sync audit).
 *
 * Wraps the kiosk order placement pipeline (machine login → quote → store →
 * payment confirm) behind a small set of programmatic helpers so audit specs
 * never have to drive the wizard UI to produce real, fiscally-valid orders.
 *
 * API contract (also restated in commit body for the Wave E GStack agent):
 *
 *   getKioskApiToken(page, machineId = null)
 *     → Promise<string>  (bearer token with kiosk:order ability)
 *
 *   placeKioskOrder(page, {
 *       items,            // [{ item_id, quantity, item_variations, item_extras, item_addons, instruction? }]
 *       paymentMethod,    // 1=cash_on_delivery, 4=card (TPE), 5=ticket_restaurant (per PaymentGateway interface)
 *       idempotencyKey,   // optional; auto-generated UUID v4 if absent
 *       branchId,         // optional; auto-resolved from seeded kiosk machine
 *       orderType = 25,   // kiosk
 *       source = 5,       // SOURCE_KIOSK
 *     })
 *     → Promise<{
 *         orderId, orderSerialNo, queueNumber,
 *         idempotencyKey, totalAmount, replayed,
 *         quote, order, paymentConfirm,
 *       }>
 *
 *   placeKioskOrderTwice(page, payload)
 *     → Promise<[firstResult, secondResult]>  (second.replayed === true expected)
 *
 *   placeKioskOrderTwiceDifferentPayload(page, payload1, payload2)
 *     → Promise<{ first: result1, second: { status, body } }>
 *       second.status === 409 expected (IdempotencyKeyMiddleware payload conflict)
 *
 *   cleanupKioskAuditOrders(prefix = 'AUDIT-KIOSK-WAVE-E')
 *     → JSON summary of rows deleted (orders / order_items / domain_events / ...)
 *
 *   resetKioskToken()
 *     → void  (clears the module-level token cache)
 *
 * Constraints honoured:
 *   - kiosk-machine-login endpoint resolved from routes/api.php:158
 *     → POST /api/auth/kiosk-login   (throttle:kiosk-login, no auth, no apiKey
 *       since the apiKey middleware is on the parent 'auth' group via the
 *       installed+apiKey+localization stack — we POST with the same header
 *       the in-browser axios uses, see KIOSK_LOGIN_HEADERS below).
 *   - All order endpoints run inside page.evaluate() so they reuse the
 *     browser's CSRF cookie + axios interceptors. The token-issuance call is
 *     ALSO done via page.evaluate() — POSTs to /api/auth/kiosk-login do NOT
 *     require pre-existing auth (route is in the apiKey-gated group but the
 *     in-browser axios instance already injects X-API-KEY in
 *     KioskLoginComponent — mirrored here via the same window.axios).
 *   - X-Idempotency-Key UUID v4 generated client-side per placement, surfaced
 *     in the return value so replay tests can re-use it.
 *   - Rate-limit awareness: callers should `clearFoodKingRateLimits()` from
 *     './rate-limit.js' between scenarios that would otherwise blow through
 *     throttle:kiosk-orders (30/min) or throttle:kiosk-login.
 *
 * NF525 note: backend remains the SSOT for pricing — this helper only
 * forwards item_id + quantity + variation/extra/addon IDs; quote + order
 * totals come straight from PricingService.
 */

const { execFileSync } = require('child_process');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');

// Source / order_type codes (mirror sync-journey-trace.js for parity).
const SOURCE_KIOSK = 5;
const ORDER_TYPE_KIOSK = 25;
const ASK_NO = 10;

// PaymentGateway interface values (app/Enums/PaymentGateway.php).
const PAYMENT_CASH = 1;       // CASH_ON_DELIVERY
const PAYMENT_CARD = 4;       // CARD (TPE)
const PAYMENT_PREPAID = 5;    // TICKET_RESTAURANT

// Seeded kiosk machine on the dev DB — verified 2026-05-10 via
// `KioskMachine::query()->select('id','username','branch_id','status')->get()`:
//   id=1, username=kiosk-lecayenne, branch_id=1, status=ACTIVE(5).
// TODO: seeded kiosk machine ID — read from DB or env if you need a different
// machine. Override via env KIOSK_E2E_USERNAME / KIOSK_E2E_PASSWORD /
// KIOSK_E2E_MACHINE_ID.
const DEFAULT_KIOSK_USERNAME = process.env.KIOSK_E2E_USERNAME || 'kiosk-lecayenne';
const DEFAULT_KIOSK_PASSWORD = process.env.KIOSK_E2E_PASSWORD || 'kiosk123';
const DEFAULT_KIOSK_MACHINE_ID = Number(process.env.KIOSK_E2E_MACHINE_ID || 1);

const KIOSK_AUDIT_PREFIX = 'AUDIT-KIOSK-WAVE-E';

// Module-level token cache. One Sanctum token per node process is plenty —
// TTL is 480 minutes (config/sanctum.php) and Wave E runs in well under that.
let cachedToken = null;
let cachedTokenForMachineId = null;

/**
 * Reset the module-level token cache. Call between tests if you need a fresh
 * Sanctum token (e.g. to assert revocation behaviour, or to re-issue after a
 * deliberate logout).
 *
 * @returns {void}
 */
function resetKioskToken() {
  cachedToken = null;
  cachedTokenForMachineId = null;
}

/**
 * Spawn `php artisan tinker --execute` synchronously and return its stdout.
 *
 * @param {string} code PHP code passed to --execute
 * @returns {string} trimmed stdout
 */
function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

/**
 * Pick the last JSON line out of an artisan execute output (tinker prefixes
 * include framework warnings + boot noise we want to skip).
 *
 * @param {string} output raw stdout
 * @returns {any} parsed JSON
 */
function parseArtisanJson(output) {
  const lines = String(output)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  const jsonLine = [...lines].reverse().find((line) => line.startsWith('{') || line.startsWith('['));
  if (!jsonLine) {
    throw new Error(`No JSON payload found in artisan output:\n${output}`);
  }
  return JSON.parse(jsonLine);
}

/**
 * Escape a PHP single-quoted string literal body.
 *
 * @param {string} value
 * @returns {string}
 */
function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

/**
 * Crypto-strength UUID v4 (RFC 4122). We avoid the Node 16 `crypto.randomUUID`
 * dependency to keep this helper usable from the in-browser evaluate context
 * if needed — but the primary caller path is Node-side.
 *
 * @returns {string} UUID v4
 */
function uuidV4() {
  // eslint-disable-next-line global-require
  const { randomBytes } = require('crypto');
  const b = randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = b.toString('hex');
  return (
    `${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-` +
    `${h.substring(16, 20)}-${h.substring(20, 32)}`
  );
}

/**
 * Resolve branch_id (and confirm machine is ACTIVE) by hitting the DB
 * directly via tinker. Used as a fallback when the caller does not pass
 * a branchId AND the cached token's machine record is unknown.
 *
 * @param {number} machineId
 * @returns {{ id: number, username: string, branch_id: number, status: number }}
 */
function lookupKioskMachine(machineId) {
  const id = Number(machineId);
  if (!Number.isFinite(id) || id <= 0) {
    throw new Error(`Invalid kiosk machine id: ${machineId}`);
  }
  return parseArtisanJson(artisan(`
    $m = \\App\\Models\\KioskMachine::withoutGlobalScopes()->find(${id});
    if (! $m) { echo json_encode(['error' => 'kiosk_machine_not_found', 'id' => ${id}]); return; }
    echo json_encode([
      'id' => (int) $m->id,
      'username' => (string) $m->username,
      'branch_id' => (int) $m->branch_id,
      'status' => (int) $m->status,
    ]);
  `));
}

/**
 * Authenticate as a kiosk machine and return a Sanctum bearer token with the
 * `kiosk:order` ability. Result is cached on this module — call
 * `resetKioskToken()` to force a fresh issuance.
 *
 * Endpoint: POST /api/auth/kiosk-login (routes/api.php line 158),
 * middleware `throttle:kiosk-login` (no auth — credentials in body).
 *
 * @param {import('@playwright/test').Page} page Playwright page (used to
 *   reuse the in-browser axios so apiKey + CSRF + base URL match the real
 *   client). Pass null/undefined to fall back to Node-side fetch (see below).
 * @param {number|null} [machineId] Optional explicit machine id; defaults to
 *   the seeded DEFAULT_KIOSK_MACHINE_ID. The id is currently only used to
 *   key the cache + resolve a branch — the actual auth uses username/password.
 * @returns {Promise<string>} bearer token (without the `Bearer ` prefix)
 */
async function getKioskApiToken(page, machineId = null) {
  const targetMachineId = machineId == null ? DEFAULT_KIOSK_MACHINE_ID : Number(machineId);
  if (cachedToken && cachedTokenForMachineId === targetMachineId) {
    return cachedToken;
  }

  const credentials = {
    username: DEFAULT_KIOSK_USERNAME,
    password: DEFAULT_KIOSK_PASSWORD,
  };

  // In-browser path: window.axios already carries X-API-KEY / language / CSRF.
  if (page && typeof page.evaluate === 'function') {
    const result = await page.evaluate(async (creds) => {
      try {
        const response = await window.axios.post('auth/kiosk-login', creds);
        return { ok: true, status: response.status, data: response.data };
      } catch (err) {
        return {
          ok: false,
          status: err?.response?.status ?? 0,
          data: err?.response?.data ?? { message: String(err?.message || err) },
        };
      }
    }, credentials);

    if (!result.ok || !result.data || !result.data.token) {
      throw new Error(
        `Kiosk login failed (HTTP ${result.status}): ${JSON.stringify(result.data).slice(0, 400)}`,
      );
    }
    cachedToken = result.data.token;
    cachedTokenForMachineId = targetMachineId;
    return cachedToken;
  }

  // Node-side fallback — not the primary path but kept so the helper is
  // standalone-runnable without a Playwright page (useful for unit-style probes).
  // eslint-disable-next-line global-require
  const http = require('http');
  const body = JSON.stringify(credentials);
  const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
  const url = new URL('/api/auth/kiosk-login', baseUrl);
  const result = await new Promise((resolve, reject) => {
    const req = http.request(
      {
        method: 'POST',
        hostname: url.hostname,
        port: url.port || 80,
        path: url.pathname,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'Content-Length': Buffer.byteLength(body),
        },
      },
      (res) => {
        let chunks = '';
        res.on('data', (c) => { chunks += c.toString(); });
        res.on('end', () => {
          try {
            resolve({ status: res.statusCode, data: JSON.parse(chunks) });
          } catch (parseErr) {
            resolve({ status: res.statusCode, data: { raw: chunks, parseErr: String(parseErr) } });
          }
        });
      },
    );
    req.on('error', reject);
    req.write(body);
    req.end();
  });

  if (result.status !== 201 || !result.data || !result.data.token) {
    throw new Error(
      `Kiosk login failed (HTTP ${result.status}): ${JSON.stringify(result.data).slice(0, 400)}`,
    );
  }
  cachedToken = result.data.token;
  cachedTokenForMachineId = targetMachineId;
  return cachedToken;
}

/**
 * Resolve the branch_id to bill the order to. Order of precedence:
 *   1. explicit `branchId` argument
 *   2. machine record lookup (DB) — single round-trip cached at module level
 *
 * @param {number|null} branchId
 * @param {number} machineId
 * @returns {number}
 */
let cachedBranchForMachineId = null;
let cachedBranchId = null;
function resolveBranchId(branchId, machineId) {
  if (branchId != null && Number.isFinite(Number(branchId))) return Number(branchId);
  if (cachedBranchForMachineId === machineId && cachedBranchId != null) return cachedBranchId;
  const machine = lookupKioskMachine(machineId);
  if (machine.error) {
    throw new Error(`Cannot resolve branch_id: ${JSON.stringify(machine)}`);
  }
  cachedBranchForMachineId = machineId;
  cachedBranchId = machine.branch_id;
  return cachedBranchId;
}

/**
 * Place a kiosk order end-to-end (quote → store → payment-confirm).
 *
 * Runs the three HTTP calls inside `page.evaluate()` so the in-browser axios
 * instance handles CSRF, base URL, X-API-KEY, and Accept-Language headers
 * identically to a real kiosk client. The Sanctum bearer token is injected
 * per-call via the `Authorization` header (cleaner than mutating axios
 * defaults — leaves the in-browser session untouched).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} options
 * @param {Array<{
 *   item_id: number,
 *   quantity: number,
 *   item_variations?: Array<{ id: number, quantity?: number }>,
 *   item_extras?: Array<{ id: number, quantity?: number }>,
 *   item_addons?: Array<{ id: number, quantity?: number }>,
 *   instruction?: string,
 * }>} options.items
 * @param {number} options.paymentMethod 1=cash, 4=card, 5=prepaid
 * @param {string|null} [options.idempotencyKey] auto-UUID v4 if null
 * @param {number|null} [options.branchId] auto-resolved from machine if null
 * @param {number} [options.orderType=25] kiosk order type
 * @param {number} [options.source=5] SOURCE_KIOSK
 * @param {number} [options.machineId=DEFAULT_KIOSK_MACHINE_ID]
 * @param {boolean} [options.skipPaymentConfirm=false] skip the
 *   payment-confirm POST (useful for card flows that go via TPE reconcile)
 * @returns {Promise<{
 *   orderId: number,
 *   orderSerialNo: string,
 *   queueNumber: number|null,
 *   idempotencyKey: string,
 *   totalAmount: number,
 *   replayed: boolean,
 *   quote: any,
 *   order: any,
 *   paymentConfirm: any|null,
 * }>}
 */
async function placeKioskOrder(page, options) {
  if (!page || typeof page.evaluate !== 'function') {
    throw new Error('placeKioskOrder requires a Playwright page (for in-browser axios).');
  }
  const {
    items,
    paymentMethod,
    idempotencyKey = null,
    branchId = null,
    orderType = ORDER_TYPE_KIOSK,
    source = SOURCE_KIOSK,
    machineId = DEFAULT_KIOSK_MACHINE_ID,
    skipPaymentConfirm = false,
  } = options || {};

  if (!Array.isArray(items) || items.length === 0) {
    throw new Error('placeKioskOrder: items must be a non-empty array.');
  }
  if (![PAYMENT_CASH, PAYMENT_CARD, PAYMENT_PREPAID].includes(Number(paymentMethod))) {
    throw new Error(
      `placeKioskOrder: paymentMethod must be 1 (cash), 4 (card) or 5 (prepaid); got ${paymentMethod}`,
    );
  }

  const token = await getKioskApiToken(page, machineId);
  const resolvedBranchId = resolveBranchId(branchId, machineId);
  const idemKey = idempotencyKey || uuidV4();
  const runStamp = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const orderToken = `${KIOSK_AUDIT_PREFIX}-${runStamp}`;

  const evalResult = await page.evaluate(async ({
    bearer,
    items,
    paymentMethod,
    idemKey,
    branchId,
    orderType,
    source,
    orderToken,
    askNo,
    skipPaymentConfirm,
  }) => {
    const authHeader = { Authorization: `Bearer ${bearer}` };

    const basePayload = {
      branch_id: branchId,
      token: orderToken,
      discount: 0,
      order_type: orderType,
      is_advance_order: askNo,
      source,
      payment_method: paymentMethod,
      items: JSON.stringify(items),
    };

    // 1. Quote — backend returns signed totals.
    let quoteResp;
    try {
      quoteResp = await window.axios.post('frontend/order/quote', basePayload, {
        headers: { ...authHeader },
      });
    } catch (err) {
      return {
        ok: false,
        stage: 'quote',
        status: err?.response?.status ?? 0,
        data: err?.response?.data ?? { message: String(err?.message || err) },
      };
    }
    const quote = quoteResp.data?.data || quoteResp.data;

    // 2. Store — locks fiscal sequence + composition snapshot.
    let storeResp;
    try {
      storeResp = await window.axios.post('frontend/order', {
        ...basePayload,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
        subtotal: quote.subtotal,
        discount: quote.discount,
        delivery_charge: quote.delivery_charge,
        total: quote.total_ttc,
      }, {
        headers: {
          ...authHeader,
          'X-Idempotency-Key': idemKey,
        },
      });
    } catch (err) {
      return {
        ok: false,
        stage: 'store',
        status: err?.response?.status ?? 0,
        data: err?.response?.data ?? { message: String(err?.message || err) },
        quote,
        // Surface Idempotency-Replayed for 409 conflict diagnostics.
        headers: err?.response?.headers ?? null,
      };
    }
    const order = storeResp.data?.data || storeResp.data;
    const storeHeaders = storeResp.headers || {};
    const replayed =
      String(storeHeaders['idempotency-replayed'] || storeHeaders['Idempotency-Replayed'] || '')
        .toLowerCase() === 'true';

    // 3. Payment-confirm — cash flow only completes here. Card flow is normally
    // driven by PaymentReconcileController after TPE response, but the
    // payment-confirm endpoint accepts simulated transaction IDs for tests
    // (parity with createKioskCardOrderViaApi in sync-journey-trace.js).
    let paymentConfirm = null;
    if (!skipPaymentConfirm) {
      try {
        const confirmResp = await window.axios.post(
          `frontend/order/${order.id}/payment-confirm`,
          {
            transaction_id: `${orderToken}-TPE-${Date.now()}`,
            card_type: 'simulated-card',
            payment_method: paymentMethod,
          },
          {
            headers: {
              ...authHeader,
              'X-Idempotency-Key': `${idemKey}-confirm`,
            },
          },
        );
        paymentConfirm = confirmResp.data;
      } catch (err) {
        return {
          ok: false,
          stage: 'payment-confirm',
          status: err?.response?.status ?? 0,
          data: err?.response?.data ?? { message: String(err?.message || err) },
          quote,
          order,
          replayed,
        };
      }
    }

    return {
      ok: true,
      quote,
      order,
      paymentConfirm,
      replayed,
      headers: storeHeaders,
    };
  }, {
    bearer: token,
    items,
    paymentMethod: Number(paymentMethod),
    idemKey,
    branchId: resolvedBranchId,
    orderType: Number(orderType),
    source: Number(source),
    orderToken,
    askNo: ASK_NO,
    skipPaymentConfirm: Boolean(skipPaymentConfirm),
  });

  if (!evalResult.ok) {
    const err = new Error(
      `placeKioskOrder failed at stage="${evalResult.stage}" HTTP ${evalResult.status}: ` +
        `${JSON.stringify(evalResult.data).slice(0, 600)}`,
    );
    err.stage = evalResult.stage;
    err.status = evalResult.status;
    err.body = evalResult.data;
    err.idempotencyKey = idemKey;
    throw err;
  }

  const { quote, order, paymentConfirm, replayed } = evalResult;
  return {
    orderId: Number(order?.id ?? order?.order_id ?? 0),
    orderSerialNo: String(order?.order_serial_no ?? ''),
    queueNumber: order?.queue_number == null ? null : Number(order.queue_number),
    idempotencyKey: idemKey,
    totalAmount: Number(order?.total ?? quote?.total_ttc ?? 0),
    replayed: Boolean(replayed),
    quote,
    order,
    paymentConfirm,
  };
}

/**
 * Place the SAME order payload twice with the same X-Idempotency-Key. The
 * second call should be replayed by IdempotencyKeyMiddleware (HTTP 2xx with
 * `Idempotency-Replayed: true` header — surfaced as `replayed: true`).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} payload same shape as `placeKioskOrder` options
 * @returns {Promise<[
 *   Awaited<ReturnType<typeof placeKioskOrder>>,
 *   Awaited<ReturnType<typeof placeKioskOrder>>
 * ]>}
 */
async function placeKioskOrderTwice(page, payload) {
  const key = payload.idempotencyKey || uuidV4();
  const first = await placeKioskOrder(page, { ...payload, idempotencyKey: key });
  const second = await placeKioskOrder(page, { ...payload, idempotencyKey: key });
  return [first, second];
}

/**
 * Send two DIFFERENT payloads under the SAME idempotency key. The second
 * call must produce HTTP 409 Conflict (payload-hash mismatch in
 * IdempotencyKeyMiddleware).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} payload1
 * @param {object} payload2
 * @returns {Promise<{
 *   first: Awaited<ReturnType<typeof placeKioskOrder>>,
 *   second: { status: number, body: any, idempotencyKey: string },
 * }>}
 */
async function placeKioskOrderTwiceDifferentPayload(page, payload1, payload2) {
  const key = payload1.idempotencyKey || payload2.idempotencyKey || uuidV4();
  const first = await placeKioskOrder(page, { ...payload1, idempotencyKey: key });

  // Second call must NOT throw — we want the structured 409 instead, so we
  // catch the placeKioskOrder error and unpack stage/status/body.
  let second;
  try {
    const okResult = await placeKioskOrder(page, { ...payload2, idempotencyKey: key });
    second = { status: 200, body: okResult, idempotencyKey: key };
  } catch (err) {
    second = {
      status: err.status ?? 0,
      body: err.body ?? { message: String(err.message || err) },
      idempotencyKey: key,
      stage: err.stage,
    };
  }
  return { first, second };
}

/**
 * Best-effort cleanup of kiosk audit orders + related rows. Mirrors the
 * sync-journey-trace.js cleanupTraceAudit shape but scoped to the Wave E
 * prefix (orders.token LIKE '<prefix>%' OR orders.order_serial_no LIKE
 * '<prefix>%'). Cache::flush() at the end keeps the kiosk menu cache from
 * leaking pre-cleanup state into a re-run.
 *
 * @param {string} [prefix='AUDIT-KIOSK-WAVE-E']
 * @returns {{ orders: number, order_items: number, domain_events: number }}
 */
function cleanupKioskAuditOrders(prefix = KIOSK_AUDIT_PREFIX) {
  const escaped = phpString(prefix);
  return parseArtisanJson(artisan(`
    $prefix = '${escaped}';
    $orderIds = DB::table('orders')
      ->where('token', 'like', $prefix . '%')
      ->orWhere('order_serial_no', 'like', $prefix . '%')
      ->pluck('id');
    $orderItems = 0;
    $domainEvents = 0;
    $transitions = 0;
    $stockMovements = 0;
    $auditLogs = 0;
    $transactions = 0;
    if ($orderIds->isNotEmpty()) {
      if (Schema::hasTable('transactions')) {
        $transactions = DB::table('transactions')->whereIn('order_id', $orderIds)->delete();
      }
      if (Schema::hasTable('order_status_transitions')) {
        $transitions = DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
      }
      if (Schema::hasTable('stock_movements')) {
        $stockMovements = DB::table('stock_movements')->whereIn('reference_id', $orderIds)->delete();
      }
      if (Schema::hasTable('domain_events')) {
        $eventQuery = DB::table('domain_events')->whereIn('aggregate_id', $orderIds);
        if (Schema::hasColumn('domain_events', 'aggregate_type')) {
          $eventQuery->whereIn('aggregate_type', ['order', App\\Models\\Order::class, App\\Models\\FrontendOrder::class]);
        }
        $domainEvents = $eventQuery->delete();
      }
      if (Schema::hasTable('audit_logs')) {
        $auditLogs = DB::table('audit_logs')
          ->where('resource', 'order')
          ->whereIn('resource_id', $orderIds)
          ->delete();
      }
      if (Schema::hasTable('order_items')) {
        $orderItems = DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
      }
      DB::table('orders')->whereIn('id', $orderIds)->update(['fiscal_sequence_no' => null]);
      DB::table('orders')->whereIn('id', $orderIds)->delete();
    }
    if (Schema::hasTable('idempotency_keys')) {
      DB::table('idempotency_keys')->where('request_path', 'like', '%frontend/order%')
        ->where('created_at', '<', now())
        ->where('response_body', 'like', '%' . $prefix . '%')
        ->delete();
    }
    Cache::flush();
    echo json_encode([
      'orders' => $orderIds->count(),
      'order_items' => (int) $orderItems,
      'domain_events' => (int) $domainEvents,
      'transitions' => (int) $transitions,
      'stock_movements' => (int) $stockMovements,
      'audit_logs' => (int) $auditLogs,
      'transactions' => (int) $transactions,
    ]);
  `));
}

module.exports = {
  // Constants — exported so specs don't have to re-derive payment codes.
  SOURCE_KIOSK,
  ORDER_TYPE_KIOSK,
  PAYMENT_CASH,
  PAYMENT_CARD,
  PAYMENT_PREPAID,
  KIOSK_AUDIT_PREFIX,
  DEFAULT_KIOSK_MACHINE_ID,
  DEFAULT_KIOSK_USERNAME,
  // Token lifecycle.
  getKioskApiToken,
  resetKioskToken,
  // Placement primitives.
  placeKioskOrder,
  placeKioskOrderTwice,
  placeKioskOrderTwiceDifferentPayload,
  // Cleanup.
  cleanupKioskAuditOrders,
  // Util re-exports for spec convenience.
  uuidV4,
};
