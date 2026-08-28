/**
 * WAVE P — Idempotency / duplicate-submit (double-charge prevention).
 * Abuse-E2E campaign 2026-06-01 (AUDIT_PLAN.md §"Wave P", line 138-141).
 *
 * KEY ASSERTION (AUDIT_PLAN.md:141):
 *   same X-Idempotency-Key ⇒ same response, ZERO duplicate write
 *   (no 2 orders / 2 payments / 2 refunds).
 *
 * ── Mechanism under test (FROZEN — observe only, never modify) ─────────────
 * app/Http/Middleware/IdempotencyKeyMiddleware.php
 *   - HEADER constant            : 'X-Idempotency-Key'        (line 29)
 *   - REPLAY_HEADER              : 'Idempotency-Replayed'     (line 30)
 *   - CONFLICT_HEADER            : 'Idempotency-Key-Conflict' (line 32)
 *   - Scope (scopedKey)          : sprintf('idempotency:v1:%d:%d:%s',
 *                                    branchId, userId, sha256(key))  (line 77-82)
 *                                  → dedup is scoped on (branch_id, user_id, hash(key)).
 *   - Same key + same payloadHash → rebuildReplayResponse() : cached body +
 *                                   'Idempotency-Replayed: true'    (line 87-95, 222-243)
 *   - Same key + DIFFERENT payloadHash → HTTP 409 IDEMPOTENCY_KEY_CONFLICT
 *                                   (line 88-93 and again 110-115)
 *   - payloadHash = sha256(raw request body)                 (line 76)
 *   - Only 2xx responses are cached for replay (line 145-151); non-2xx → release.
 *
 * config/idempotency.php
 *   - 'enabled'                  : env IDEMPOTENCY_MIDDLEWARE_ENABLED (line 28).
 *                                  ⚠ Default OFF. If the middleware is disabled in
 *                                  the test env, replay/409 cannot fire — the spec
 *                                  DETECTS this and marks the scenario "gated"
 *                                  rather than silently passing on a no-op.
 *   - 'required_routes'          : includes 'api/frontend/order' (line 41) and
 *                                  the payment-confirm wildcard route (line 42).
 *
 * ── Chosen mutating endpoint: POST /api/frontend/order (kiosk order create) ──
 *   routes/api.php:1316 (order group) — middleware ['throttle:kiosk-orders','idempotency'].
 *   Controller FrontendOrderController::store.
 *   WHY SAFE (documented choice):
 *     1. It is one of the canonical idempotency-protected routes (required_routes
 *        line 41) — the exact double-charge surface Wave P targets.
 *     2. It returns a stable order `id` in the body, so a replay is provable by
 *        comparing the SECOND response's id to the FIRST (must be identical).
 *     3. Test orders carry a scoped token prefix ('AUDIT-KIOSK-WAVE-E-…') and are
 *        fully sweepable via cleanupKioskAuditOrders() — including order_items,
 *        domain_events, transitions, stock_movements, audit_logs, transactions —
 *        so no fiscal state is corrupted and the DB returns to baseline.
 *     4. It does NOT touch a signed Z-report or mutate an existing order's fiscal
 *        chain; the alternative (KDS change-status / refund) would mutate a live
 *        order's NF525-relevant state. Order CREATE under a disposable token is the
 *        least-destructive proof of the dedup invariant.
 *
 * ── Helpers reused (no new mechanism invented) ──────────────────────────────
 *   helpers/kiosk-order.js:
 *     getKioskApiToken(page)            → kiosk:order Sanctum bearer
 *     cleanupKioskAuditOrders(prefix)   → JSON rows-deleted summary
 *     resetKioskToken(), uuidV4()
 *   helpers/rate-limit.js: clearFoodKingRateLimits (throttle reset)
 *   helpers/mega-audit-snap.js: attachMegaAuditRecorder → snap() 4-file quartet
 *
 * Dedup is exercised via storeReplayProbe() defined in this file (NOT the full
 * placeKioskOrder pipeline). Rationale: placeKioskOrder re-runs quote→store→
 * payment-confirm per call, regenerating the order `token` and confirm
 * `transaction_id` each time — so a "replay" call would send DIFFERENT bytes
 * under the same key (false 409) and also trip the separate payment-confirm
 * route's validation. To prove the STORE-POST dedup contract we must POST the
 * SAME store body BYTE-IDENTICALLY twice; storeReplayProbe does exactly that and
 * skips payment-confirm (a different route, not the dedup surface under test).
 *
 * SAFETY: requires a live server on baseURL with the kiosk SPA reachable. The
 * IdempotencyKeyMiddleware + idempotency.php config are FROZEN — observe only.
 */

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const {
  cleanupKioskAuditOrders,
  resetKioskToken,
  getKioskApiToken,
  uuidV4,
  KIOSK_AUDIT_PREFIX,
  PAYMENT_CASH,
  resolveSimpleOrderableItem,
  prefixeAuditPourSpec,
} = require('./helpers/kiosk-order');

// [GOAL CONSOLIDATION T-4.2.1] Préfixe d'audit PROPRE à cette spec.
// Avant : huit specs écrivaient sous 'AUDIT-KIOSK-WAVE-E' et se nettoyaient
// mutuellement par LIKE. Dormant tant que playwright.config.js fixe workers:1,
// destructeur dès qu'on parallélise.
const PREFIXE_AUDIT = prefixeAuditPourSpec(__filename);
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const repoRoot = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(
  repoRoot,
  'reports/test-e2e/abuse-e2e-2026-06-01/round-1/wave-P-idempotency',
);

/**
 * HARDENING (2026-06-03): persist the decisive idempotency evidence to disk so
 * the ZERO-duplicate-write invariant is auditable WITHOUT re-running the spec.
 * Previously the proof (r1.orderId===r2.orderId, before/after DB counts, the
 * replay/conflict statuses) lived only in spec memory + console; a green run
 * left nothing durable a reviewer could inspect. We write the JSON BEFORE the
 * hard DB-count assertion so the artifact survives even if that assertion
 * throws (the artifact is the point — it captures the observed reality whether
 * pass or fail). Lands next to the mega-audit quartet in the wave-P dir.
 */
function persistIdempotencyEvidence(scenario, evidence) {
  try {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    const file = path.join(SCREENSHOT_DIR, `${scenario}-idempotency-evidence.json`);
    fs.writeFileSync(
      file,
      JSON.stringify({ scenario, capturedAt: new Date().toISOString(), ...evidence }, null, 2),
    );
    console.log(`[Wave P][${scenario}] evidence persisted: ${file}`);
    return file;
  } catch (err) {
    console.warn(`[Wave P][${scenario}] failed to persist evidence:`, err?.message || err);
    return null;
  }
}

// [FIX 2026-08-25] Article résolu à l'exécution, plus d'identifiant figé.
//
// Le banc codait `item_id = 52` (Coca-Cola 33cl). Ce produit existe toujours mais il est
// l'UNIQUE article en RUPTURE de la branche 1 — vérifié en base : `item_branch_availability`
// le marque `is_available = 0`, motif `stock_rupture`. Le devis répondait donc 422
// « n'est plus disponible » AVANT d'atteindre le sujet du test, qui est la déduplication.
// La pastille de santé caisse affichait « 1 en rupture » : c'est le même fait, vu ailleurs.
//
// Le commentaire d'origine annonçait déjà le remède (« use the kioskMenu store discovery
// pattern ») sans qu'il soit appliqué. On demande maintenant au helper partagé un article
// qui satisfait le BESOIN du banc — actif, disponible sur la branche, sans variation et sans
// étape d'assistant obligatoire — au lieu de parier sur un identifiant.
//
// `WAVE_P_ITEM_ID` reste honoré pour forcer un article précis en investigation.
let SIMPLE_ITEM_ID = Number(process.env.WAVE_P_ITEM_ID || 0);

// OrderType::TAKEAWAY = 10 (app/Enums/OrderType.php:8). Kiosk orders in V1 Le
// Cayenne must be TAKEAWAY — order_type=25 (KIOSK) is rejected 422 server-side
// (dine-in/on-site disabled). Not exported from the helper, so define locally.
const ORDER_TYPE_TAKEAWAY = 10;

/**
 * Count orders whose token OR order_serial_no starts with the given prefix.
 * Mirrors the artisan/tinker pattern used inside cleanupKioskAuditOrders so we
 * have a real DB witness of "how many orders exist" before vs after — this is
 * the concrete ZERO-duplicate-write evidence (not just a header check).
 */
function countOrdersByPrefix(prefix) {
  const escaped = String(prefix).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  const code = `
    $prefix = '${escaped}';
    $n = DB::table('orders')
      ->where('token', 'like', $prefix . '%')
      ->orWhere('order_serial_no', 'like', $prefix . '%')
      ->count();
    echo json_encode(['count' => (int) $n]);
  `;
  try {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 20_000,
    });
    const line = String(out).split(/\r?\n/).reverse().find((l) => l.trim().startsWith('{'));
    return line ? Number(JSON.parse(line).count) : NaN;
  } catch (err) {
    console.warn('[Wave P] countOrdersByPrefix failed:', err?.message || err);
    return NaN;
  }
}

/**
 * Drive the STORE-POST idempotency surface directly (the dedup-protected route
 * is POST /api/frontend/order — routes/api.php:1316, middleware 'idempotency').
 *
 * WHY NOT placeKioskOrderTwice: that helper re-runs the full quote→store→
 * payment-confirm pipeline per call, rebuilding the order `token` (Date.now+rand)
 * and the confirm `transaction_id` each time — so the second submit sends
 * DIFFERENT bytes under the same key (→ 409 harness artifact) and additionally
 * fails payment-confirm validation (amount_cents/payment_method shape belongs to
 * a separate route, PaymentConfirmRequest). To prove STORE dedup we must POST the
 * store body BYTE-IDENTICALLY twice. payment-confirm is intentionally skipped:
 * the store POST is the dedup surface, confirm is a different route.
 *
 * Steps, all inside one page.evaluate so window.axios carries X-API-KEY + CSRF +
 * Accept-Language exactly like a real kiosk client, with the kiosk:order bearer
 * injected per-call:
 *   1. ONE quote (frontend/order/quote) → signed totals + quote_token/signature.
 *   2. Build ONE canonical store body (token prefixed KIOSK_AUDIT_PREFIX so the
 *      DB witness countOrdersByPrefix + cleanup catch it).
 *   3. POST frontend/order N times. Same key + same body → replay. Same key +
 *      mutated body (override) → 409 conflict.
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} opts
 * @param {string} opts.bearer kiosk:order Sanctum bearer (no "Bearer " prefix)
 * @param {number} opts.branchId
 * @param {string} opts.idemKey shared X-Idempotency-Key for all submits
 * @param {string} opts.token order token (KIOSK_AUDIT_PREFIX-scoped)
 * @param {Array<object>} opts.items kiosk order items
 * @param {Array<object|null>} opts.submits one entry per store POST; null = post
 *   the canonical body byte-identically, object = shallow-merge override onto the
 *   canonical body (produces a DIFFERENT body hash under the same key → 409).
 * @returns {Promise<{ quote: any, results: Array<{ status:number, replayed:boolean,
 *   conflict:boolean, orderId:number, body:any }> }>}
 */
async function storeReplayProbe(page, { bearer, branchId, idemKey, token, items, submits }) {
  return page.evaluate(async ({ bearer, branchId, idemKey, token, items, submits, askNo, source, orderType, paymentMethod }) => {
    const authHeader = { Authorization: `Bearer ${bearer}` };
    const basePayload = {
      branch_id: branchId,
      token,
      discount: 0,
      order_type: orderType,
      is_advance_order: askNo,
      source,
      payment_method: paymentMethod,
      items: JSON.stringify(items),
    };

    // 1. ONE quote — signed totals re-used for every store POST so the store
    //    body is identical across byte-identical submits.
    let quote;
    try {
      const qr = await window.axios.post('frontend/order/quote', basePayload, { headers: { ...authHeader } });
      quote = qr.data?.data || qr.data;
    } catch (err) {
      return { fatal: 'quote', status: err?.response?.status ?? 0, data: err?.response?.data ?? { message: String(err?.message || err) } };
    }

    // 2. Canonical store body (built ONCE, frozen).
    const canonical = {
      ...basePayload,
      quote_token: quote.quote_token,
      quote_signature: quote.signature,
      subtotal: quote.subtotal,
      discount: quote.discount,
      delivery_charge: quote.delivery_charge,
      total: quote.total_ttc,
    };

    // 3. N store POSTs, all under the same X-Idempotency-Key.
    const results = [];
    for (const override of submits) {
      const body = override == null ? canonical : { ...canonical, ...override };
      // If items overridden, re-stringify (campaign mutates item quantity).
      if (override && override.items && Array.isArray(override.items)) {
        body.items = JSON.stringify(override.items);
      }
      try {
        const r = await window.axios.post('frontend/order', body, {
          headers: { ...authHeader, 'X-Idempotency-Key': idemKey },
        });
        const o = r.data?.data || r.data;
        const h = r.headers || {};
        results.push({
          status: r.status,
          replayed: String(h['idempotency-replayed'] || '').toLowerCase() === 'true',
          conflict: String(h['idempotency-key-conflict'] || '').toLowerCase() === 'true',
          orderId: Number(o?.id ?? o?.order_id ?? 0),
          body: o,
        });
      } catch (err) {
        const resp = err?.response;
        results.push({
          status: resp?.status ?? 0,
          replayed: String(resp?.headers?.['idempotency-replayed'] || '').toLowerCase() === 'true',
          conflict: String(resp?.headers?.['idempotency-key-conflict'] || '').toLowerCase() === 'true',
          orderId: 0,
          body: resp?.data ?? { message: String(err?.message || err) },
        });
      }
    }
    return { quote, results };
  }, {
    bearer, branchId, idemKey, token, items, submits,
    askNo: 10, source: 5, orderType: ORDER_TYPE_TAKEAWAY, paymentMethod: PAYMENT_CASH,
  });
}

/** Minimal valid kiosk order items (no variations/extras/addons). */
function simpleItems(quantity = 1) {
  return [{ item_id: SIMPLE_ITEM_ID, quantity, item_variations: [], item_extras: [], item_addons: [] }];
}

/** Build a KIOSK_AUDIT_PREFIX-scoped order token (sweepable + DB-witnessable). */
function auditToken() {
  return `${KIOSK_AUDIT_PREFIX}-P-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
}

test.describe('Wave P — Idempotency / duplicate-submit (double-charge prevention)', () => {
  let recorder;

  test.beforeAll(() => {
    // Reset throttle buckets (throttle:kiosk-orders 30/min, throttle:kiosk-login)
    // so the two placement bursts don't 429 mid-wave.
    clearFoodKingRateLimits();
    resetKioskToken();
    // Start from a clean slate for our scoped prefix so before/after counts are honest.
    cleanupKioskAuditOrders(PREFIXE_AUDIT);

    // [FIX 2026-08-25] Article résolu ici, contre l'état RÉEL du menu.
    if (!SIMPLE_ITEM_ID) {
      const article = resolveSimpleOrderableItem({ branchId: 1 });
      SIMPLE_ITEM_ID = article.id;
      // eslint-disable-next-line no-console
      console.log(`[Wave P] article commandable résolu : #${article.id} « ${article.name} » @ ${article.price}`);
    }
  });

  test.afterAll(() => {
    // Sweep every order this wave created (and its idempotency records / children)
    // so no AUDIT-KIOSK row leaks into the live KDS pile or skews fiscal counts.
    const summary = cleanupKioskAuditOrders(PREFIXE_AUDIT);
    console.log('[Wave P] cleanup summary:', JSON.stringify(summary));
  });

  test.beforeEach(async ({ page }) => {
    recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    // Land on the kiosk surface so window.axios (X-API-KEY + CSRF) is available;
    // the kiosk-order helper self-issues a kiosk:order Sanctum token from here.
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  });

  test.afterEach(async () => {
    if (recorder) recorder.dispose();
  });

  /**
   * P-01 — SAME key + SAME payload ⇒ REPLAY (no duplicate order).
   * Real assertions: (a) both calls return the SAME order id; (b) the 2nd call
   * carries Idempotency-Replayed:true (helper surfaces it as `replayed`);
   * (c) the DB order count for our prefix increases by EXACTLY ONE across the
   * two POSTs — the hard ZERO-duplicate-write witness.
   */
  test('P-01 same-key + same-payload ⇒ replay, exactly ONE order written', async ({ page }) => {
    await recorder.snap('P-01-before-double-submit');

    const before = countOrdersByPrefix(KIOSK_AUDIT_PREFIX);

    // ONE quote → ONE canonical store body → POST it twice BYTE-IDENTICALLY under
    // the same X-Idempotency-Key (submits = [null, null] = canonical both times).
    const bearer = await getKioskApiToken(page);
    const idemKey = uuidV4();
    const probe = await storeReplayProbe(page, {
      bearer,
      branchId: 1, // seeded kiosk machine branch (helpers/kiosk-order.js:80)
      idemKey,
      token: auditToken(),
      items: simpleItems(1),
      submits: [null, null],
    });

    if (probe.fatal) {
      throw new Error(`P-01 quote stage failed (not a dedup outcome): HTTP ${probe.status} ${JSON.stringify(probe.data).slice(0, 400)}`);
    }
    const [r1, r2] = probe.results;

    const after = countOrdersByPrefix(KIOSK_AUDIT_PREFIX);
    await recorder.snap('P-01-after-double-submit');

    // Persist the decisive evidence to disk BEFORE any hard assertion can throw,
    // so the ZERO-duplicate-write invariant is auditable from the artifact dir.
    persistIdempotencyEvidence('P-01-same-key-same-payload', {
      invariant: 'same key + same payload ⇒ replay, exactly ONE order written',
      idemKey,
      r1: { status: r1.status, orderId: r1.orderId, replayed: r1.replayed, conflict: r1.conflict },
      r2: { status: r2.status, orderId: r2.orderId, replayed: r2.replayed, conflict: r2.conflict },
      sameOrderId: r1.orderId === r2.orderId,
      dbCount: { before, after, delta: Number.isFinite(after - before) ? after - before : null },
    });

    // First POST must genuinely create the order (2xx + real id).
    expect(r1.status, `first POST must succeed (got ${r1.status}: ${JSON.stringify(r1.body).slice(0, 300)})`).toBeGreaterThanOrEqual(200);
    expect(r1.status, 'first POST must be 2xx').toBeLessThan(300);
    expect(r1.orderId, 'first POST must create an order').toBeGreaterThan(0);
    expect(r2.orderId, 'second POST must return an order id').toBeGreaterThan(0);

    // Reachable-vs-gated guard: if middleware were disabled, the 2nd byte-identical
    // POST would be a genuinely new order (distinct id, replayed=false). We do NOT
    // silently pass — we record it GATED and let the count assertion below hold the
    // line via the app-layer UNIQUE backstop. (Env here: enabled=true, .env:87.)
    let middlewareGated = false;
    if (!r2.replayed && r2.orderId !== r1.orderId) {
      middlewareGated = true;
      console.warn(
        '[Wave P][P-01] GATED: Idempotency-Replayed=false and distinct order ids ' +
          `(first=${r1.orderId}, second=${r2.orderId}). ` +
          'IDEMPOTENCY_MIDDLEWARE_ENABLED likely OFF (config/idempotency.php:28). ' +
          'HTTP-level replay NOT exercised; relying on count assertion + app UNIQUE backstop.',
      );
    }

    if (!middlewareGated) {
      // HTTP-level dedup IS active — assert the replay contract directly. A 2nd
      // byte-identical POST that creates a DISTINCT id (count +2) is a real
      // double-charge defect: this assertion keeps it RED.
      expect(r2.replayed, 'second response must be a replay (Idempotency-Replayed:true)').toBe(true);
      expect(r2.orderId, 'replay must return the SAME order id, not a new order').toBe(r1.orderId);
    }

    // HARD invariant regardless of gating: exactly ONE row was written.
    if (Number.isFinite(before) && Number.isFinite(after)) {
      expect(after - before, 'exactly ONE order row may be created by two same-key POSTs').toBe(1);
    } else {
      console.warn('[Wave P][P-01] order-count witness unavailable (tinker failed) — relying on id/replay assertions.');
      expect(r2.orderId, 'second POST must not be a distinct new order').toBe(r1.orderId);
    }
  });

  /**
   * P-02 — SAME key + DIFFERENT payload ⇒ HTTP 409 conflict (no second order).
   * Real assertions: (a) second.status === 409; (b) body code/message signals the
   * IDEMPOTENCY_KEY_CONFLICT path (middleware line 90-92); (c) DB count rose by
   * EXACTLY ONE (only the first payload's order exists — the conflicting retry
   * created nothing).
   */
  test('P-02 same-key + different-payload ⇒ 409 conflict, no second order', async ({ page }) => {
    await recorder.snap('P-02-before-conflict');

    const before = countOrdersByPrefix(KIOSK_AUDIT_PREFIX);

    // ONE quote → canonical body (qty 1) posted first, then the SAME key with a
    // mutated body (qty 2 → different sha256(raw body)) — the genuine payload
    // conflict the middleware must reject 409 (NOT a harness byte-mismatch: the
    // first body is the canonical, the override is a real semantic change).
    const bearer = await getKioskApiToken(page);
    const idemKey = uuidV4();
    const probe = await storeReplayProbe(page, {
      bearer,
      branchId: 1,
      idemKey,
      token: auditToken(),
      items: simpleItems(1),
      submits: [
        null,                       // canonical (qty 1) — creates the order
        { items: simpleItems(2) },  // same key, different body hash (qty 2) → 409
      ],
    });

    if (probe.fatal) {
      throw new Error(`P-02 quote stage failed (not a dedup outcome): HTTP ${probe.status} ${JSON.stringify(probe.data).slice(0, 400)}`);
    }
    const [first, second] = probe.results;

    const after = countOrdersByPrefix(KIOSK_AUDIT_PREFIX);
    await recorder.snap('P-02-after-conflict');

    // Persist the conflict evidence to disk BEFORE any hard assertion can throw.
    persistIdempotencyEvidence('P-02-same-key-different-payload', {
      invariant: 'same key + different payload ⇒ 409 conflict, no second order',
      idemKey,
      first: { status: first.status, orderId: first.orderId, replayed: first.replayed, conflict: first.conflict },
      second: { status: second.status, orderId: second.orderId, replayed: second.replayed, conflict: second.conflict, body: second.body },
      dbCount: { before, after, delta: Number.isFinite(after - before) ? after - before : null },
    });

    expect(first.status, `first payload must succeed (got ${first.status}: ${JSON.stringify(first.body).slice(0, 300)})`).toBeLessThan(300);
    expect(first.orderId, 'first payload must create an order').toBeGreaterThan(0);

    // Reachable-vs-gated guard: with middleware OFF the second (different) payload
    // is accepted as a brand-new order (2xx, distinct id) — record GATED, do not
    // fake a pass. (Env here: enabled=true, .env:87 — 409 expected.)
    if (second.status !== 409) {
      console.warn(
        `[Wave P][P-02] GATED: expected 409 conflict, got status=${second.status}. ` +
          'IDEMPOTENCY_MIDDLEWARE_ENABLED likely OFF (config/idempotency.php:28); ' +
          'payload-conflict rejection NOT exercised. body=' + JSON.stringify(second.body).slice(0, 300),
      );
      // A 2xx here = different payload under same key was ACCEPTED = real dedup
      // defect (double-write under retry). The count assertion below stays HARD.
      if (Number.isFinite(before) && Number.isFinite(after)) {
        expect(after - before, 'different-payload retry under same key must NOT create a second order')
          .toBe(1);
      }
      return;
    }

    expect(second.status, 'different payload under same key must be 409 conflict').toBe(409);
    const body = second.body || {};
    const codeOrMsg = String(body.code || body.message || JSON.stringify(body));
    expect(
      /IDEMPOTENCY_KEY_CONFLICT|reused with different payload/i.test(codeOrMsg),
      `conflict body must signal the IDEMPOTENCY_KEY_CONFLICT path (got: ${codeOrMsg.slice(0, 200)})`,
    ).toBe(true);

    if (Number.isFinite(before) && Number.isFinite(after)) {
      expect(after - before, 'the conflicting retry must create ZERO additional orders').toBe(1);
    }
  });
});
