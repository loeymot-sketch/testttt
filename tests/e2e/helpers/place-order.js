/**
 * High-level place-order helper — Supervisor Wave C / Z4 LATENCY infra.
 *
 * Wraps the kiosk order pipeline (login → quote → store → payment-confirm)
 * behind a single async call that returns just the data the caller needs
 * (orderId, fiscalSequenceNo, totalAmount, idempotencyKey, latency).
 *
 * IMPORTANT distinction vs the existing `kiosk-order.js`:
 *   - `kiosk-order.js#placeKioskOrder()` requires a Playwright `page`
 *     (browser-bound — uses window.axios + CSRF cookie).
 *   - THIS helper requires NO browser — uses Playwright's APIRequest context.
 *     Designed specifically for latency / stress / concurrency specs that
 *     would otherwise be skewed by Chromium boot + page-render time.
 *
 * Endpoints exercised (verified 2026-05-28 in routes/api.php:1280-1289):
 *   1. POST /api/frontend/order/quote
 *        middleware: auth:sanctum, throttle:kiosk-orders
 *   2. POST /api/frontend/order
 *        middleware: auth:sanctum, throttle:kiosk-orders, idempotency
 *   3. POST /api/frontend/order/{id}/payment-confirm
 *        middleware: auth:sanctum, idempotency
 *
 * NF525 contract preserved:
 *   - Backend remains the SSOT for pricing (this helper sends item_id +
 *     quantity + variation IDs ONLY — no client-side totals).
 *   - The signed quote_token + quote_signature returned by step 1 are
 *     forwarded verbatim to step 2 (any tampering → 422 quote_invalid).
 *   - fiscal_sequence_no is returned from the order store response, NOT
 *     computed client-side.
 */

const { loginKiosk } = require('./kiosk-auth');
const { generateIdempotencyKey } = require('./idempotency-key');

// Source / order_type / payment codes — kept in sync with kiosk-order.js
// (mirror app/Enums/PaymentGateway.php + sync-journey-trace.js).
const SOURCE_KIOSK = 5;
const ORDER_TYPE_KIOSK = 25;
const ASK_NO = 10; // not is_advance_order

const PAYMENT_CASH = 1;
const PAYMENT_CARD = 4;
const PAYMENT_PREPAID = 5;

/**
 * Place a kiosk order programmatically via the API (no browser).
 *
 * @param {object} options
 * @param {Array<{
 *   item_id: number,
 *   quantity: number,
 *   item_variations?: Array<{ id: number, quantity?: number }>,
 *   item_extras?: Array<{ id: number, quantity?: number }>,
 *   item_addons?: Array<{ id: number, quantity?: number }>,
 *   instruction?: string,
 * }>} options.items REQUIRED
 * @param {number} [options.paymentMethod=1] 1=cash, 4=card, 5=prepaid
 * @param {string} [options.idempotencyKey] auto-generated if absent
 * @param {string} [options.idempotencyPrefix='Z4-LATENCY'] prefix for auto key
 * @param {import('@playwright/test').APIRequestContext} [options.apiContext]
 *        pre-authenticated kiosk context; if null, a fresh login happens
 * @param {string} [options.kioskToken] re-use a known kiosk token (pairs
 *        with apiContext for fast batch runs)
 * @param {number} [options.branchId] optional override; resolved from kiosk
 * @param {boolean} [options.skipPaymentConfirm=false]
 * @param {string} [options.tokenPrefix='SUPERVISOR-WAVE-C-Z4'] order.token prefix
 * @param {string} [options.baseURL] forwarded to loginKiosk when no context
 * @returns {Promise<{
 *   orderId: number,
 *   orderSerialNo: string,
 *   fiscalSequenceNo: number|null,
 *   queueNumber: number|null,
 *   totalAmount: number,
 *   idempotencyKey: string,
 *   replayed: boolean,
 *   latencyMs: { quote: number, store: number, confirm: number|null, total: number },
 *   stages: { quote: object, order: object, paymentConfirm: object|null },
 * }>}
 */
async function placeOrder(options = {}) {
  const {
    items,
    paymentMethod = PAYMENT_CASH,
    idempotencyKey: providedKey = null,
    idempotencyPrefix = 'Z4-LATENCY',
    apiContext: providedContext = null,
    kioskToken: providedToken = null,
    branchId: providedBranchId = null,
    skipPaymentConfirm = false,
    tokenPrefix = 'SUPERVISOR-WAVE-C-Z4',
    baseURL,
  } = options;

  if (!Array.isArray(items) || items.length === 0) {
    throw new Error('placeOrder: items must be a non-empty array of { item_id, quantity, ... }');
  }
  if (![PAYMENT_CASH, PAYMENT_CARD, PAYMENT_PREPAID].includes(Number(paymentMethod))) {
    throw new Error(
      `placeOrder: paymentMethod must be 1 (cash), 4 (card) or 5 (prepaid); got ${paymentMethod}`,
    );
  }

  // Step 0 — acquire kiosk context if caller didn't pre-login.
  let apiContext = providedContext;
  let branchId = providedBranchId;
  let ownsContext = false;
  if (!apiContext) {
    const session = await loginKiosk(baseURL ? { baseURL } : {});
    apiContext = session.apiContext;
    branchId = branchId == null ? session.kiosk.branch_id : branchId;
    ownsContext = true;
  } else if (branchId == null) {
    // Caller provided context but no branchId — we still need one for the payload.
    throw new Error(
      'placeOrder: when passing apiContext directly, you must also pass branchId. ' +
        'Use loginKiosk() to get both in one call.',
    );
  }

  const idempotencyKey = providedKey || generateIdempotencyKey(idempotencyPrefix);
  const runStamp = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const orderToken = `${tokenPrefix}-${runStamp}`;

  const basePayload = {
    branch_id: branchId,
    token: orderToken,
    discount: 0,
    order_type: ORDER_TYPE_KIOSK,
    is_advance_order: ASK_NO,
    source: SOURCE_KIOSK,
    payment_method: Number(paymentMethod),
    items: JSON.stringify(items),
  };

  const latency = { quote: 0, store: 0, confirm: null, total: 0 };
  const overallStart = Date.now();

  try {
    // Step 1 — quote.
    const quoteStart = Date.now();
    const quoteResp = await apiContext.post('/api/frontend/order/quote', { data: basePayload });
    latency.quote = Date.now() - quoteStart;
    if (!quoteResp.ok()) {
      const body = await quoteResp.text().catch(() => '');
      throw new Error(`Quote HTTP ${quoteResp.status()}: ${body.slice(0, 400)}`);
    }
    const quoteJson = await quoteResp.json();
    const quote = quoteJson?.data || quoteJson;

    if (!quote || !quote.quote_token || !quote.signature) {
      throw new Error(
        `Quote response missing quote_token/signature: ${JSON.stringify(quoteJson).slice(0, 400)}`,
      );
    }

    // Step 2 — store (idempotency-protected).
    const storeStart = Date.now();
    const storeResp = await apiContext.post('/api/frontend/order', {
      data: {
        ...basePayload,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
        subtotal: quote.subtotal,
        discount: quote.discount,
        delivery_charge: quote.delivery_charge,
        total: quote.total_ttc,
      },
      headers: { 'X-Idempotency-Key': idempotencyKey },
    });
    latency.store = Date.now() - storeStart;
    if (!storeResp.ok()) {
      const body = await storeResp.text().catch(() => '');
      throw new Error(`Order store HTTP ${storeResp.status()}: ${body.slice(0, 400)}`);
    }
    const storeJson = await storeResp.json();
    const order = storeJson?.data || storeJson;
    const headers = storeResp.headers();
    const replayed = String(
      headers['idempotency-replayed'] || headers['Idempotency-Replayed'] || '',
    ).toLowerCase() === 'true';

    // Step 3 — payment-confirm (idempotency-protected; key suffixed to avoid
    // colliding with the order-store key in the same dedup window).
    let paymentConfirm = null;
    if (!skipPaymentConfirm) {
      const confirmStart = Date.now();
      const confirmResp = await apiContext.post(
        `/api/frontend/order/${order.id}/payment-confirm`,
        {
          data: {
            transaction_id: `${orderToken}-TPE-${Date.now()}`,
            card_type: 'simulated-card',
            payment_method: Number(paymentMethod),
          },
          headers: { 'X-Idempotency-Key': `${idempotencyKey}-confirm` },
        },
      );
      latency.confirm = Date.now() - confirmStart;
      if (!confirmResp.ok()) {
        const body = await confirmResp.text().catch(() => '');
        throw new Error(`payment-confirm HTTP ${confirmResp.status()}: ${body.slice(0, 400)}`);
      }
      paymentConfirm = await confirmResp.json();
    }

    latency.total = Date.now() - overallStart;

    return {
      orderId: Number(order?.id ?? order?.order_id ?? 0),
      orderSerialNo: String(order?.order_serial_no ?? ''),
      fiscalSequenceNo: order?.fiscal_sequence_no == null ? null : Number(order.fiscal_sequence_no),
      queueNumber: order?.queue_number == null ? null : Number(order.queue_number),
      totalAmount: Number(order?.total ?? quote?.total_ttc ?? 0),
      idempotencyKey,
      replayed,
      latencyMs: latency,
      stages: { quote, order, paymentConfirm },
    };
  } finally {
    if (ownsContext) {
      await apiContext.dispose().catch(() => {});
    }
  }
}

module.exports = {
  placeOrder,
  SOURCE_KIOSK,
  ORDER_TYPE_KIOSK,
  PAYMENT_CASH,
  PAYMENT_CARD,
  PAYMENT_PREPAID,
};
