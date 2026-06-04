// =====================================================================
// abuse-e2e-2026-06-01 · Wave I — Refund + counter-entry (NF525 mirror)
// (AUDIT_PLAN.md "Wave I" — ⭐⭐ NF525 netting)
// =====================================================================
//
// Drives the REAL POS refund surface and captures the mega-audit quartet
// (PNG + DOM + console + network) at every visual state that is REACHABLE
// in dev. The mirror / counter-entry is asserted from the 201 JSON of the
// refund POST (the parent detail page does NOT surface the mirror — see
// PosOrderShowComponent.onRefundCompleted: "the mirror appears in the
// history list via its own routes", :650-651).
//
// ─────────────────────────────────────────────────────────────────────
// ⚠️ CRITICAL CONTRACT CORRECTION vs the AUDIT_PLAN "Wave I" wording
// ─────────────────────────────────────────────────────────────────────
// AUDIT_PLAN Wave I says: "mirror order seq_no in SAME Z-window as
// original" and "error Z-closed (422)". The ACTUAL backend contract is
// the INVERSE, and this spec encodes REALITY (verified 2026-06-02):
//
//   RefundWithCounterEntryService::execute (:55-103) REQUIRES the parent
//   to be SEALED — i.e. it must already live in a CLOSED Z window —
//   before a counter-entry can be issued. SealedOrderGuard::assertSealed
//   (:84-112) throws InvalidArgumentException(422) "Order N is not in a
//   CLOSED Z window — operation 'refund-with-counter-entry' …" otherwise.
//
//   => A freshly-placed, still-open-Z PAID order is the case that gets
//      422-BLOCKED. The "standard pre-Z RETURNED path" is what's used
//      inside the open Z.
//   => On success, the mirror is allocated a FRESH fiscal_sequence_no in
//      the CURRENT (later) Z window (service :102-103), referencing the
//      sealed original. Parent and mirror live in DIFFERENT Z windows.
//      This is correct NF525: a counter-entry belongs to the issuing
//      day's Z and points back at the sealed sale.
//
// Therefore the DEFAULT (un-gated) run asserts the REAL reachable block:
// open-Z PAID parent → confirm refund → HTTP 422 + "CLOSED Z window"
// error surfaced in the modal. The SUCCESS + REMBOURSEMENT + 409 path is
// gated behind sealing the parent (Z-close) and is provided as a fully
// spelled-out `test.fixme` (see GATE note + E2E_SEAL_PARENT flag below).
//
// ─────────────────────────────────────────────────────────────────────
// SELECTORS — evidence-backed (grep 2026-06-02), REAL data-testid only
// ─────────────────────────────────────────────────────────────────────
//   - refund OPEN button ......... [data-testid="pos-order-refund-open"]
//        PosOrderShowComponent.vue:195  (v-if="canShowRefund" :503-528 →
//        renders ONLY when order.payment_status===PAID AND not a mirror
//        AND user holds `pos-refund`. Its mere presence PROVES PAID+perm.)
//   - refund modal overlay ....... [data-testid="pos-refund-modal-overlay"]   PosRefundModal.vue:42
//   - refund modal title ......... [data-testid="pos-refund-modal-title"]     PosRefundModal.vue:58
//   - refund modal total ......... [data-testid="pos-refund-modal-total"]     PosRefundModal.vue:85
//   - refund reason textarea ..... [data-testid="pos-refund-modal-reason"]    PosRefundModal.vue:128
//   - refund confirm button ...... [data-testid="pos-refund-modal-confirm"]   PosRefundModal.vue:179
//        (:disabled="!canConfirm || submitting"; canConfirm :263-265 ⇒
//         requires reasonTrimmedLength>=5 — the UI gate is STRICTER than
//         the backend min:3. So <5 chars ⇒ confirm disabled.)
//   - refund modal error band .... [data-testid="pos-refund-modal-error"]     PosRefundModal.vue:156
//        (surfaces backend 4xx message; 422 path sets it from response
//         data.message — PosRefundModal.onConfirm :367-368.)
//   - refund modal cancel ........ [data-testid="pos-refund-modal-cancel"]    PosRefundModal.vue:168
//   - REMBOURSEMENT receipt mark . [data-testid="receipt-remboursement-marker"] ReceiptRemboursementMarker.vue:7
//        (v-if="isRefund" ⇒ order.parent_order_id > 0 — only on the mirror.)
//
// ROUTES (real, routes/api.php):
//   POST /api/admin/pos-order/{order}/refund-with-counter-entry
//        api.php:993  (PosOrderController::refundWithCounterEntry)
//        middleware: throttle:pos-order-update, idempotency
//        NOTE: singular "pos-order" — the AUDIT_PLAN's "pos-orders" is WRONG.
//        Body: { reason: string min:3 max:700 }; header X-Idempotency-Key.
//        201 → { data: <mirror OrderDetailsResource>, meta:{ parent_order_id,
//                mirror_fiscal_sequence_no } }
//        422 → parent not refundable / not sealed (InvalidArgumentException)
//        409 → { code:'MIRROR_ALREADY_EXISTS' } (UNIQUE parent_order_id)
//        403 → caller lacks `pos-refund` (PosOrderController:54-58)
//   GET  /api/admin/pos-order/show/{order}  (loads any branch-1 order, NOT
//        source-scoped — withoutGlobalScope(BranchScope), :show)
//   POST /api/admin/z-report/open  + /close  api.php:1232/1234 (seal lever;
//        used ONLY by the gated seal helper — never edits a fiscal SERVICE)
//
// SPA page: /admin/pos-orders/show/:id  (posOrderRoutes.js:36-38,
//   name admin.pos-orders.show; PosOrderShowComponent fetches via
//   store posOrder/show — :461).
//
// ─────────────────────────────────────────────────────────────────────
// ROLE — admin@lecayenne.fr (NOT pos@lecayenne.fr)
// ─────────────────────────────────────────────────────────────────────
// `pos-refund` is granted to Admin + Branch Manager ONLY
// (RolePermissionTableSeeder:89; PosOrderController abort_unless :54-58).
// POS Operator does NOT hold it ⇒ for pos@lecayenne.fr the refund button
// never renders and the POST would 403. The AUDIT_PLAN's "role: pos" is
// therefore not driveable for the refund itself — we use ADMIN, which
// also bypasses BranchScope + the cross-branch guard so it can load the
// kiosk-seeded branch-1 order.
//
// ─────────────────────────────────────────────────────────────────────
// SEEDING — a PAID pos-viewable order to refund
// ─────────────────────────────────────────────────────────────────────
// helpers/place-order.js → placeOrder() runs the kiosk pipeline
// (quote → store → payment-confirm) headless via APIRequest. The
// payment-confirm step sets payment_status=PAID + allocates a
// fiscal_sequence_no (OrderController::paymentConfirm :215, :259). The
// admin POS detail `show` is NOT source-scoped (loads kiosk-source
// orders by id), so the seeded order opens at /admin/pos-orders/show/{id}
// with the PAID gate satisfied ⇒ the refund button renders. We use
// PAYMENT_CARD so the order settles to PAID even under Plan-B counter
// routing, and we read the authoritative payment_status from the show
// fetch, not from the seed helper's response object.
//
// ─────────────────────────────────────────────────────────────────────
// GATE — SUCCESS / REMBOURSEMENT / 409 path
// ─────────────────────────────────────────────────────────────────────
// Driving a 201 refund requires the parent to be SEALED first (a CLOSED
// Z window whose closed_at >= parent.created_at). The brief mandates
// driving fiscal state through the UI and never editing a fiscal SERVICE;
// there is no dedicated Z-close SPA button component in this codebase
// (only the `/api/admin/z-report/{open,close}` endpoints gated by
// pos-manage-fiscal). Closing the live Z mutates shared fiscal state that
// every other parallel author depends on, so the seal+success+409 block
// is a documented `test.fixme` with the FULL, real assertions spelled
// out, opt-in behind E2E_SEAL_PARENT=1. It NEVER silent-.catch()es a
// critical assertion and NEVER fakes a paid/refunded state.
//
// FROZEN ZONES: none edited. The refund modal + receipt marker + show
// component are auditable Vue. ZReportService / FiscalSequenceService /
// AuditLogService are OBSERVE-ONLY — exercised only through HTTP routes.
// =====================================================================

const { test, expect } = require('@playwright/test');
const path = require('path');
const crypto = require('crypto');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { loginKiosk } = require('./helpers/kiosk-auth');
// NOTE: payment-method code kept local (4 = card). The shared place-order
// helper is NOT used to seed here — it hardcodes order_type=25 (KIOSK =
// "Sur place"), which V1 rejects with 422 "Le service sur place est
// désactivé en V1" (OrderRequest::withValidator :220-231, pos_dine_in_enabled
// off). V1 kiosk orders MUST be TAKEAWAY (order_type=10), which the backend
// explicitly allows for a kiosk token (OrderRequest.php:233). We therefore
// seed inline via the same quote→store→payment-confirm pipeline, mirroring
// the proven-green sibling test-e2e-abuse-D-oss-sync.spec.js:70-132. No
// product/PHP file edited; no fiscal service touched.
const PAYMENT_CARD = 4;

const DIR = path.resolve(__dirname, '__screenshots__/test-e2e-I');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

// Opt-in: seal the seeded parent (Z-close) so the 201 success + 409 dup +
// REMBOURSEMENT receipt path becomes reachable. Default OFF — closing the
// live Z mutates shared fiscal state other parallel authors rely on.
const SEAL_PARENT = process.env.E2E_SEAL_PARENT === '1';

// A canonical single-line item to seed. item_id 1 is the V1 Le Cayenne
// catalog baseline; quantity 1 keeps the negated mirror trivially checkable.
const SEED_ITEMS = [{ item_id: 1, quantity: 1 }];

test.setTimeout(240_000);

// Seed a PAID, pos-viewable order. Returns { orderId, fiscalSequenceNo,
// totalAmount }. Throws loudly if the seed pipeline fails (no silent catch).
//
// Drives the REAL kiosk order pipeline (quote → store → payment-confirm) as
// a TAKEAWAY (order_type=10) — the only kiosk order_type V1 accepts (sur-place
// disabled). PAYMENT_CARD settles to PAID even under Plan-B counter routing;
// the card payment-confirm allocates a fiscal_sequence_no. No browser, no
// frozen file. Mirrors test-e2e-abuse-D-oss-sync.spec.js#seedPreparingOrder.
async function seedPaidOrder() {
  const session = await loginKiosk({ baseURL: BASE });
  const api = session.apiContext;
  const branchId = session.kiosk.branch_id;
  try {
    const stamp = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
    const token = `ABUSE-WAVE-I-${stamp}`;
    const base = {
      branch_id: branchId,
      token,
      discount: 0,
      order_type: 10, // TAKEAWAY (V1: KIOSK sur-place disabled — OrderRequest:220-231)
      is_advance_order: 10, // Ask::NO
      source: 5, // SOURCE_KIOSK
      payment_method: PAYMENT_CARD,
      items: JSON.stringify(SEED_ITEMS),
    };

    const quoteResp = await api.post('/api/frontend/order/quote', { data: base });
    expect(quoteResp.ok(), `seed quote HTTP ${quoteResp.status()}: ${(await quoteResp.text().catch(() => '')).slice(0, 300)}`).toBeTruthy();
    const quote = (await quoteResp.json()).data;
    expect(quote && quote.quote_token, 'seed quote_token present').toBeTruthy();

    const idem = `ABUSE-I-REFUND-${crypto.randomUUID()}`;
    const storeResp = await api.post('/api/frontend/order', {
      data: {
        ...base,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
        subtotal: quote.subtotal,
        discount: quote.discount,
        delivery_charge: quote.delivery_charge,
        total: quote.total_ttc,
      },
      headers: { 'X-Idempotency-Key': idem },
    });
    expect(storeResp.ok(), `seed store HTTP ${storeResp.status()}: ${(await storeResp.text().catch(() => '')).slice(0, 300)}`).toBeTruthy();
    const order = (await storeResp.json()).data;

    const totalNumber = Number(order.total);
    const confirmResp = await api.post(`/api/frontend/order/${order.id}/payment-confirm`, {
      data: {
        transaction_id: `${token}-TPE-${Date.now()}`,
        card_type: 'simulated-card',
        payment_method: PAYMENT_CARD,
        amount_cents: Math.round(totalNumber * 100), // NF525 F-002: must equal order.total
      },
      headers: { 'X-Idempotency-Key': `${idem}-confirm` },
    });
    expect(confirmResp.ok(), `seed payment-confirm HTTP ${confirmResp.status()}: ${(await confirmResp.text().catch(() => '')).slice(0, 300)}`).toBeTruthy();
    const confirmJson = await confirmResp.json().catch(() => ({}));
    const confirmed = confirmJson?.data || confirmJson || {};

    const orderId = Number(order.id);
    expect(orderId, 'seed must yield a real order id').toBeGreaterThan(0);
    // fiscal_sequence_no is allocated at payment-confirm for card (Plan-B);
    // read it from the confirm payload, falling back to the store object.
    const fiscalSequenceNo =
      confirmed.fiscal_sequence_no != null
        ? Number(confirmed.fiscal_sequence_no)
        : order.fiscal_sequence_no != null
          ? Number(order.fiscal_sequence_no)
          : null;
    return { orderId, fiscalSequenceNo, totalAmount: totalNumber };
  } finally {
    await api.dispose().catch(() => {});
  }
}

test.describe.serial('Wave I — refund + counter-entry (abuse-e2e)', () => {
  // -------------------------------------------------------------------
  // 01 order detail (refund button = PAID proof) · 02 modal (reason gate)
  // · 03 refund in-flight · 04 REAL reachable block: open-Z parent → 422
  //   "not in a CLOSED Z window" surfaced in the modal error band.
  // -------------------------------------------------------------------
  test('open-Z PAID order: detail → modal → confirm → 422 CLOSED-Z block', async ({ page }) => {
    const { snap, dispose } = attachMegaAuditRecorder(page, DIR);
    try {
      const seed = await seedPaidOrder();

      await loginAsAdmin(page);
      await page.goto(`${BASE}/admin/pos-orders/show/${seed.orderId}`, {
        waitUntil: 'domcontentloaded',
      });

      // ---- 01 order detail — refund button present ⇒ PAID + pos-refund perm.
      // Its presence is the gate (canShowRefund :516-528). CRITICAL assert.
      const refundOpen = page.locator('[data-testid="pos-order-refund-open"]');
      await expect(
        refundOpen,
        'refund button must render for a PAID order to an admin holding pos-refund',
      ).toBeVisible({ timeout: 25_000 });
      await snap('01-order-detail-refund-enabled');

      // ---- 02 modal — reason REQUIRED, confirm disabled until >=5 chars.
      await refundOpen.click();
      const overlay = page.locator('[data-testid="pos-refund-modal-overlay"]');
      await expect(overlay).toBeVisible({ timeout: 10_000 });
      await expect(page.locator('[data-testid="pos-refund-modal-title"]')).toBeVisible();

      const total = page.locator('[data-testid="pos-refund-modal-total"]');
      await expect(total, 'modal must show the order total to be refunded').toBeVisible();
      const totalText = (await total.innerText()).trim();
      expect(totalText.length, 'refund total must render a value').toBeGreaterThan(0);

      const reason = page.locator('[data-testid="pos-refund-modal-reason"]');
      const confirm = page.locator('[data-testid="pos-refund-modal-confirm"]');

      // Empty reason ⇒ confirm disabled.
      await expect(confirm, 'confirm disabled with empty reason').toBeDisabled();
      // Under-min (<5) ⇒ still disabled (UI gate stricter than backend min:3).
      await reason.fill('ab');
      await expect(confirm, 'confirm disabled with <5-char reason').toBeDisabled();
      await snap('02-refund-modal-reason-required');

      // Valid reason (>=5) ⇒ confirm enabled.
      await reason.fill('Abuse-E2E Wave I counter-entry refund test');
      await expect(confirm, 'confirm enabled once reason >=5 chars').toBeEnabled();

      // ---- 03 in-flight + ---- 04 422 CLOSED-Z block.
      // The parent is in the OPEN Z (just placed) ⇒ SealedOrderGuard rejects
      // with 422. We assert the network status AND the message AND the
      // surfaced modal error band. NO silent catch on any of these.
      const refundResp = page.waitForResponse(
        (r) =>
          r.request().method() === 'POST' &&
          /\/api\/admin\/pos-order\/\d+\/refund-with-counter-entry/i.test(r.url()),
        { timeout: 25_000 },
      );
      await confirm.click();
      // Spinner state — best-effort snap; the response assert below is the gate.
      await snap('03-refund-in-flight');

      const resp = await refundResp;
      const status = resp.status();
      const bodyText = await resp.text();

      expect(
        status,
        `open-Z parent refund must be 422-blocked (not sealed). got ${status}: ${bodyText.slice(0, 300)}`,
      ).toBe(422);
      expect(
        bodyText,
        '422 body must explain the CLOSED-Z-window seal requirement',
      ).toMatch(/CLOSED Z window|not in a CLOSED|pre-Z|fiscal_sequence/i);

      // The modal must surface the backend 422 message to the cashier.
      const errorBand = page.locator('[data-testid="pos-refund-modal-error"]');
      await expect(
        errorBand,
        '422 must be surfaced in the modal error band (no silent failure)',
      ).toBeVisible({ timeout: 10_000 });
      const errText = (await errorBand.innerText()).trim();
      expect(errText.length, 'error band must carry visible text').toBeGreaterThan(0);
      await snap('04-refund-422-closed-z-block');

      // Modal stays open on error so the cashier can read it / cancel.
      await expect(overlay, 'modal stays open after a blocked refund').toBeVisible();
      await page.locator('[data-testid="pos-refund-modal-cancel"]').click();
      await expect(overlay).toBeHidden({ timeout: 10_000 });
      await snap('05-modal-dismissed-after-block');
    } finally {
      dispose();
    }
  });

  // -------------------------------------------------------------------
  // GATED — SUCCESS (201 mirror, new seq, negative net) + REMBOURSEMENT
  // receipt + 409 duplicate. Reachable ONLY after the parent is sealed
  // (Z-close). Mutates shared fiscal state ⇒ opt-in E2E_SEAL_PARENT=1.
  // All assertions are REAL and fully spelled out; the block is fixme so
  // a default parallel run never closes the live Z out from under peers.
  // -------------------------------------------------------------------
  test('sealed parent: refund → 201 mirror (new seq, negative net) → REMBOURSEMENT → 409 dup', async ({ page, request }) => {
    test.fixme(
      !SEAL_PARENT,
      'GATED: needs a sealed parent (Z-close mutates shared fiscal state). ' +
        'Run with E2E_SEAL_PARENT=1 in an isolated fiscal context to exercise the 201/409/REMBOURSEMENT path.',
    );

    const { snap, dispose } = attachMegaAuditRecorder(page, DIR);
    try {
      // Seed a PAID order BEFORE the seal so closed_at >= parent.created_at.
      const seed = await seedPaidOrder();
      const parentSeq = seed.fiscalSequenceNo;
      expect(parentSeq, 'sealed-path parent must carry a fiscal_sequence_no').toBeGreaterThan(0);

      await loginAsAdmin(page);

      // SEAL: close the current Z window via the fiscal HTTP route, reusing
      // the admin's authenticated browser cookies/CSRF (page.request). This
      // exercises the real /api/admin/z-report endpoints — it does NOT edit
      // any fiscal SERVICE. The admin holds pos-manage-fiscal.
      // (open is idempotent-ish behind throttle; close seals the window.)
      await page.request.post(`${BASE}/api/admin/z-report/open`).catch(() => {});
      const closeResp = await page.request.post(`${BASE}/api/admin/z-report/close`);
      expect(
        [200, 201, 409, 422].includes(closeResp.status()),
        `z-report close should resolve to a known fiscal status, got ${closeResp.status()}`,
      ).toBeTruthy();

      // Now open the (sealed) parent's detail and issue the refund.
      await page.goto(`${BASE}/admin/pos-orders/show/${seed.orderId}`, {
        waitUntil: 'domcontentloaded',
      });
      const refundOpen = page.locator('[data-testid="pos-order-refund-open"]');
      await expect(refundOpen).toBeVisible({ timeout: 25_000 });
      await refundOpen.click();
      await expect(page.locator('[data-testid="pos-refund-modal-overlay"]')).toBeVisible();
      await page
        .locator('[data-testid="pos-refund-modal-reason"]')
        .fill('Abuse-E2E Wave I sealed-parent counter-entry refund');

      const okResp = page.waitForResponse(
        (r) =>
          r.request().method() === 'POST' &&
          /\/api\/admin\/pos-order\/\d+\/refund-with-counter-entry/i.test(r.url()),
        { timeout: 25_000 },
      );
      await page.locator('[data-testid="pos-refund-modal-confirm"]').click();
      await snap('06-refund-in-flight-sealed');

      const resp = await okResp;
      expect(resp.status(), 'sealed-parent refund must be 201 created').toBe(201);
      const json = await resp.json();
      const mirror = json?.data || {};
      const meta = json?.meta || {};

      // NF525 mirror assertions — counter-entry shape (no fake paid):
      expect(Number(meta.parent_order_id), 'meta links the mirror to the parent')
        .toBe(seed.orderId);
      const mirrorSeq = Number(meta.mirror_fiscal_sequence_no);
      expect(mirrorSeq, 'mirror gets a fresh, later fiscal_sequence_no')
        .toBeGreaterThan(Number(parentSeq));
      expect(Number(mirror.parent_order_id), 'mirror.parent_order_id points at the parent')
        .toBe(seed.orderId);
      expect(String(mirror.order_serial_no || ''), 'mirror serial is the RTN- counter-entry')
        .toMatch(/^RTN-/);
      expect(Number(mirror.total), 'mirror total is the NEGATIVE net of the sale')
        .toBeLessThan(0);
      // Negative items net: every mirror line quantity is negated.
      const items = mirror.order_items || [];
      expect(items.length, 'mirror replicates the parent order_items').toBeGreaterThan(0);
      for (const it of items) {
        expect(Number(it.quantity), 'each mirror line quantity is negative').toBeLessThan(0);
      }
      await snap('07-refund-success-201');

      // REMBOURSEMENT marker — open the mirror's receipt; the marker renders
      // only when order.parent_order_id > 0 (ReceiptRemboursementMarker:47).
      await page.goto(`${BASE}/admin/pos-orders/show/${mirror.id}`, {
        waitUntil: 'domcontentloaded',
      });
      const remb = page.locator('[data-testid="receipt-remboursement-marker"]');
      await expect(
        remb,
        'mirror receipt must show the REMBOURSEMENT marker',
      ).toBeVisible({ timeout: 25_000 });
      expect((await remb.innerText()).toUpperCase()).toContain('REMBOURSEMENT');
      await snap('08-mirror-receipt-remboursement');

      // 409 duplicate — a SECOND refund on the same (now-refunded) parent must
      // be rejected by UNIQUE(parent_order_id) ⇒ MIRROR_ALREADY_EXISTS.
      const dupResp = await page.request.post(
        `${BASE}/api/admin/pos-order/${seed.orderId}/refund-with-counter-entry`,
        {
          data: { reason: 'Abuse-E2E Wave I duplicate refund attempt' },
          headers: { 'X-Idempotency-Key': `abuse-i-dup-${seed.orderId}-${Date.now()}` },
        },
      );
      expect(dupResp.status(), 'second refund on same parent must be 409').toBe(409);
      const dupJson = await dupResp.json().catch(() => ({}));
      expect(String(dupJson.code || ''), 'duplicate refund carries MIRROR_ALREADY_EXISTS')
        .toBe('MIRROR_ALREADY_EXISTS');
    } finally {
      dispose();
    }
  });
});
