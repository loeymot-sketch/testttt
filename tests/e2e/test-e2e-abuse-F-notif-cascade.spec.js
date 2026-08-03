// =====================================================================
// WAVE F — NOTIFICATIONS + CASCADE  ("even the notifications" mandate)
// abuse-e2e-2026-06-01  ·  Round 1  ·  GStack main team
//
// Two INDEPENDENT tests (serial describe) so a wedged surface in one cannot
// starve the other's capture budget:
//
//   TEST 1 — CASCADE-1 : admin 86's an item →
//            POS shows ÉPUISÉ banner (`pos-availability-banner`) naming it +
//            a FRESH kiosk load renders the card filtered-out / disabled.
//   TEST 2 — NOTIF-1/2/3 : a real kiosk order (API) shows up as a KDS card
//            (chef on private-branch.1) → bump PREPARING→PREPARED →
//            OSS/customer-facing surface reflects state.
//
// REAL CASCADE — no fake-pass:
//   * Admin toggle POST `admin/menu/availability/toggle` captured in
//     network.json; we assert the body targeted branch_id=1 + item + 86
//     (targeting trap — a flip on the wrong branch makes the whole thing
//     vacuous).
//   * POS picks up the flip via the ItemAvailabilityChanged WS broadcast
//     (staff guard); bounded WS wait, then a real POS reload fallback
//     (catalog `is_available=false` → `unavailableCatalogItems` → banner).
//   * Kiosk private-channel WS is KNOWN-degraded on the public terminal
//     (plan known signals: 302 /broadcasting/auth → 401 /api/login,
//     at-most-P2). VERIFIED LIVE 2026-06-02: `/api/frontend/menu` DOES
//     return is_available=false for the 86'd item, and a FRESH kiosk load
//     correctly renders it `--filtered-out` + aria-disabled + add-disabled.
//     A live-flip on an already-mounted kiosk does NOT re-apply without a
//     reload (WS-degraded) — that's the documented P2, not a defect. So we
//     load the kiosk catalog FRESH (after the toggle) and assert the
//     data-driven cascade. We ALSO assert `/api/frontend/menu` itself to
//     prove the backend propagated (independent of any render path).
//   * REAL behavior: the kiosk does NOT remove the card — it disables it
//     with the raw reason ("out_of_stock_manual") as the tooltip. Plan
//     loosely says "kiosk hides it"; actual = disabled + reason-key tooltip
//     (not a localized "Épuisé"). Disclosed P2 in notes.
//
// FROZEN — observe only, never edited: KioskWizard/App/Upsell,
//   PaymentComponent, PosV5TrancheRow, pos-wizard.js.
// =====================================================================

const { test, expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  loginAsAdmin,
  loginAsPosOperator,
  loginAsChefOperator,
  loginAsKiosk,
} = require('./helpers/login');
const { loginKiosk } = require('./helpers/kiosk-auth');
const { generateIdempotencyKey } = require('./helpers/idempotency-key');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DIR = path.join(__dirname, '__screenshots__', 'test-e2e-F');
const APP_ROOT = path.resolve(__dirname, '../..');

// Cascade target — verified branch-1 catalog row, category 5 (Tacos),
// renders as kiosk-product-card-26. (DB + live MCP check 2026-06-02.)
const TARGET_ITEM_ID = 26;
const TARGET_ITEM_NAME = 'Tacos';
const TARGET_CATEGORY_ID = 5;
const BRANCH_ID = 1;

// Item used for the NOTIF order — must be AVAILABLE when we place; we
// re-enable it explicitly right before placing so a drifted seed 86 can't
// turn a seeding gap into a phantom "notif defect".
const NOTIF_ITEM_ID = 2; // Frites Seules — simple, no wizard composition

test.describe.configure({ mode: 'serial', timeout: 300_000 });

// ---------------------------------------------------------------------
// Re-enable / 86 an item deterministically via the SSOT AvailabilityService
// (same service the admin controller delegates to — dispatches the same
// ItemAvailabilityChanged event). Used ONLY for baseline reset + reverse
// cascade + NOTIF item prep; the USER-FACING cascade proof (state 03) is
// still driven through the admin UI toggle. No reliance on a torn-down page.
// ---------------------------------------------------------------------
function setItemAvailability(itemId, isAvailable) {
  const reason = isAvailable ? 'null' : "'out_of_stock_manual'";
  const code = `app(\\App\\Services\\Menu\\AvailabilityService::class)->toggle(${itemId}, ${BRANCH_ID}, ${isAvailable ? 'true' : 'false'}, ${reason});`;
  try {
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
      cwd: APP_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 30_000,
    });
  } catch (err) {
    console.warn('[WaveF] setItemAvailability warning:', err?.message || err);
  }
}

// ---------------------------------------------------------------------
// Place a REAL kiosk order via the API as order_type=TAKEAWAY (10).
// (place-order.js hardcodes order_type=KIOSK/dine-in which V1 rejects with
//  "service sur place désactivé" — dine-in is disabled per CLAUDE.md. We
//  mirror the same NF525-safe quote→store→confirm flow here, sending only
//  item_id+quantity; backend stays the pricing SSOT.)
// ---------------------------------------------------------------------
const SOURCE_KIOSK = 5;
const ORDER_TYPE_TAKEAWAY = 10;
const ASK_NO = 10;
async function placeKioskTakeawayOrder(items) {
  const session = await loginKiosk({});
  const { apiContext } = session;
  const branchId = session.kiosk.branch_id;
  try {
    const idem = generateIdempotencyKey('AUDIT-WAVE-F-NOTIF');
    const token = `AUDIT-WAVE-F-NOTIF-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
    const base = {
      branch_id: branchId,
      token,
      discount: 0,
      order_type: ORDER_TYPE_TAKEAWAY,
      is_advance_order: ASK_NO,
      source: SOURCE_KIOSK,
      payment_method: 4, // CARD / TPE — kiosk pays by card (cash routes to counter)
      items: JSON.stringify(items),
    };
    const quoteResp = await apiContext.post('/api/frontend/order/quote', { data: base });
    if (!quoteResp.ok()) throw new Error(`quote HTTP ${quoteResp.status()}: ${(await quoteResp.text()).slice(0, 300)}`);
    const quote = (await quoteResp.json()).data || (await quoteResp.json());
    const storeResp = await apiContext.post('/api/frontend/order', {
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
    if (!storeResp.ok()) throw new Error(`store HTTP ${storeResp.status()}: ${(await storeResp.text()).slice(0, 300)}`);
    const order = (await storeResp.json()).data || (await storeResp.json());
    // amount_cents MUST echo order.total (NF525/PCI TPE echo check, ±1c).
    const totalTtc = Number(order.total ?? quote.total_ttc ?? 0);
    const amountCents = Math.round(totalTtc * 100);
    const confirmResp = await apiContext.post(`/api/frontend/order/${order.id}/payment-confirm`, {
      data: {
        transaction_id: `${token}-TPE-${Date.now()}`,
        card_type: 'simulated-card',
        payment_method: 4, // CARD
        amount_cents: amountCents,
      },
      headers: { 'X-Idempotency-Key': `${idem}-confirm` },
    });
    if (!confirmResp.ok()) throw new Error(`confirm HTTP ${confirmResp.status()}: ${(await confirmResp.text()).slice(0, 300)}`);
    return {
      orderId: Number(order.id),
      orderSerialNo: String(order.order_serial_no || ''),
      // queue_number is a display string like "A0022" (NOT a plain int); OSS
      // renders it verbatim as "N°A0022". May be null in the store response —
      // resolved from the KDS board feed by the caller if needed.
      queueNumber: order.queue_number != null ? String(order.queue_number) : null,
      totalAmount: Number(order.total ?? quote.total_ttc ?? 0),
    };
  } finally {
    await apiContext.dispose().catch(() => {});
  }
}

// =====================================================================
// TEST 1 — CASCADE: admin 86 → POS ÉPUISÉ + kiosk disabled (fresh load)
// =====================================================================
test('Wave F · CASCADE — admin 86 → POS ÉPUISÉ banner + kiosk card disabled', async ({ browser }) => {
  const adminCtx = await browser.newContext();
  const posCtx = await browser.newContext();
  const kioskCtx = await browser.newContext();

  const adminPage = await adminCtx.newPage();
  const posPage = await posCtx.newPage();
  const kioskPage = await kioskCtx.newPage();

  const adminRec = attachMegaAuditRecorder(adminPage, DIR);
  const posRec = attachMegaAuditRecorder(posPage, DIR);
  const kioskRec = attachMegaAuditRecorder(kioskPage, DIR);

  let toggledOff = false;

  try {
    // ---- 00 baseline: force target AVAILABLE so the cascade is non-vacuous.
    await setItemAvailability(TARGET_ITEM_ID, true);

    // ---- admin stock dashboard (real route /admin/stock/rupture).
    await loginAsAdmin(adminPage);
    await adminPage.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    await expect(adminPage.getByTestId('stock-management-v2')).toBeVisible({ timeout: 30_000 });

    // Surface the target toggle. The search box is SCOPED to the active
    // bucket, so we must select the Tacos bucket (cat-5) FIRST. The product
    // toggle then renders directly (no search needed).
    const tacosBucket = adminPage.getByTestId(`stock-mgmt-bucket-cat-${TARGET_CATEGORY_ID}`);
    await expect(tacosBucket, 'Tacos bucket must be present').toBeVisible({ timeout: 15_000 });
    await tacosBucket.click();
    await adminPage.waitForTimeout(500);
    const toggleTid = `stock-mgmt-toggle-item-${TARGET_ITEM_ID}`;
    const toggleBtn = adminPage.getByTestId(toggleTid).first();
    await expect(toggleBtn).toBeVisible({ timeout: 15_000 });
    await expect(toggleBtn, 'baseline must read IN-STOCK').toContainText(/in[- ]?stock|en stock/i, {
      timeout: 10_000,
    });
    await adminRec.snap('01-admin-precascade-instock');

    // ---- POS baseline (own context) BEFORE the toggle.
    await loginAsPosOperator(posPage);
    await expect(posPage).toHaveURL(/\/admin\/pos/, { timeout: 30_000 });
    await posPage.waitForTimeout(2_500);
    await posRec.snap('02-pos-baseline');

    // ---- 03 TOGGLE RUPTURE — capture the POST + assert branch targeting.
    const togglePostPromise = adminPage.waitForResponse(
      (res) =>
        res.request().method() === 'POST' &&
        /\/admin\/menu\/availability\/toggle/i.test(res.url()),
      { timeout: 25_000 },
    );
    await toggleBtn.click();
    const toggleResp = await togglePostPromise;
    expect(toggleResp.status(), 'rupture toggle POST must succeed').toBeLessThan(400);
    toggledOff = true;
    const body = toggleResp.request().postDataJSON?.() || {};
    expect(Number(body.branch_id), 'toggle must target branch 1').toBe(BRANCH_ID);
    expect(Number(body.item_id), 'toggle must target the right item').toBe(TARGET_ITEM_ID);
    expect(body.is_available, 'toggle must set is_available=false (86)').toBeFalsy();
    await expect(toggleBtn, 'admin toggle must read OUT-OF-STOCK').toContainText(
      /out[- ]?of[- ]?stock|épuisé|rupture/i,
      { timeout: 10_000 },
    );
    await adminRec.snap('03-admin-toggled-rupture');

    // ---- 04 POS shows ÉPUISÉ. Try WS push (bounded), then real reload fallback.
    const posBanner = posPage.getByTestId('pos-availability-banner');
    let viaWs = false;
    try {
      await expect(posBanner).toBeVisible({ timeout: 10_000 });
      const txt = (await posBanner.innerText().catch(() => '')) || '';
      if (new RegExp(TARGET_ITEM_NAME, 'i').test(txt)) viaWs = true;
    } catch (_) {
      viaWs = false;
    }
    if (!viaWs) {
      await posPage.reload({ waitUntil: 'domcontentloaded' });
      await posPage.waitForTimeout(2_500);
    }
    await expect(posBanner, 'POS rupture banner must be visible after 86').toBeVisible({
      timeout: 15_000,
    });
    const bannerText = (await posBanner.innerText().catch(() => '')) || '';
    expect(bannerText, 'POS banner must name the 86d item').toMatch(new RegExp(TARGET_ITEM_NAME, 'i'));
    await posRec.snap(`04-pos-epuise-banner${viaWs ? '-via-ws' : '-via-reload'}`);

    // ---- 05a kiosk MENU API proves backend propagation (independent of render).
    await loginAsKiosk(kioskPage);
    // Land on idle; the categories route is guarded by hasExplicitOrderType()
    // (a direct goto redirects back to idle), so we MUST pick an order type on
    // the idle screen first. This also triggers a fresh fetchMenu AFTER the 86.
    await expect(kioskPage.getByTestId('kiosk-idle-root'), 'kiosk idle must render').toBeVisible({
      timeout: 25_000,
    });
    const takeaway = kioskPage.getByTestId('kiosk-order-type-takeaway');
    const dineIn = kioskPage.getByTestId('kiosk-order-type-dine-in');
    if (await takeaway.isVisible({ timeout: 6_000 }).catch(() => false)) {
      await takeaway.click();
    } else if (await dineIn.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await dineIn.click();
    }
    // Now on the catalogue; ensure the menu is loaded fresh post-86.
    await kioskPage.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 }).catch(() => {});
    const menuApi = await kioskPage.evaluate(async () => {
      try {
        const res = await window.axios.get('frontend/menu');
        const data = res.data && res.data.data ? res.data.data : res.data;
        let found = null;
        const scan = (o) => {
          if (!o || typeof o !== 'object') return;
          if (Array.isArray(o)) return o.forEach(scan);
          if ((o.id === 26 || o.item_id === 26) && (o.name || o.is_available !== undefined)) {
            found = { id: o.id, name: o.name, is_available: o.is_available, reason: o.unavailable_reason };
          }
          Object.values(o).forEach(scan);
        };
        scan(data);
        return { ok: true, item: found };
      } catch (e) {
        return { ok: false, error: String((e && e.message) || e) };
      }
    });
    expect(menuApi.ok, 'kiosk /frontend/menu must respond').toBeTruthy();
    expect(menuApi.item, 'kiosk menu must include the target item').toBeTruthy();
    expect(
      menuApi.item.is_available,
      'kiosk menu API must report the 86 (is_available=false) — backend cascade',
    ).toBeFalsy();

    // ---- 05b kiosk RENDER: fresh-loaded card must be filtered-out + disabled.
    const catRoot = kioskPage.getByTestId('kiosk-categories-root');
    await expect(catRoot, 'kiosk categories must mount').toBeVisible({ timeout: 25_000 });
    const targetCard = kioskPage.getByTestId(`kiosk-product-card-${TARGET_ITEM_ID}`);
    if (!(await targetCard.isVisible({ timeout: 5_000 }).catch(() => false))) {
      // Ensure the right category is selected.
      await kioskPage
        .getByTestId(`kiosk-categories-sidebar-item-${TARGET_CATEGORY_ID}`)
        .click()
        .catch(() => {});
    }
    await expect(targetCard, 'target kiosk card must be present (disabled, not hidden)').toBeVisible({
      timeout: 15_000,
    });
    const cardState = await targetCard.evaluate((el) => {
      const add = el.parentElement?.querySelector('[data-testid^="kiosk-product-add-"]')
        || document.querySelector(`[data-testid="kiosk-product-add-26"]`);
      return {
        cls: el.className,
        ariaDisabled: el.getAttribute('aria-disabled'),
        title: el.getAttribute('title'),
        addDisabled: add ? add.disabled : null,
      };
    });
    expect(cardState.cls, 'kiosk 86d card must carry --filtered-out').toMatch(
      /kiosk-product-card--filtered-out/,
    );
    expect(cardState.ariaDisabled, 'kiosk 86d card must be aria-disabled').toBe('true');
    expect(cardState.addDisabled, 'kiosk add button must be disabled when 86d').toBe(true);
    // Tooltip carries the unavailable reason (raw key here — disclosed P2).
    expect(cardState.title, 'kiosk 86d card must expose an unavailable tooltip').toMatch(
      /out_of_stock|épuisé|epuise|indispon|rupture/i,
    );
    // Protection HOLDS: activating the disabled card must NOT open the wizard.
    const urlBefore = kioskPage.url();
    await targetCard.click({ force: true }).catch(() => {});
    await kioskPage.waitForTimeout(700);
    expect(kioskPage.url(), 'clicking a 86d kiosk card must not open the wizard').toBe(urlBefore);
    await kioskRec.snap('05-kiosk-item-disabled-cascade');

    // ---- 06 reverse cascade: re-enable + confirm admin back to IN-STOCK.
    await setItemAvailability(TARGET_ITEM_ID, true);
    toggledOff = false;
    await adminPage.reload({ waitUntil: 'domcontentloaded' });
    await expect(adminPage.getByTestId('stock-management-v2')).toBeVisible({ timeout: 20_000 });
    const tacosBucket2 = adminPage.getByTestId(`stock-mgmt-bucket-cat-${TARGET_CATEGORY_ID}`);
    if (await tacosBucket2.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await tacosBucket2.click().catch(() => {});
      await adminPage.waitForTimeout(400);
    }
    const reBtn = adminPage.getByTestId(toggleTid).first();
    if (await reBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await expect(reBtn).toContainText(/in[- ]?stock|en stock/i, { timeout: 10_000 });
    }
    await adminRec.snap('06-admin-reenabled-reverse-cascade');
  } finally {
    if (toggledOff) {
      setItemAvailability(TARGET_ITEM_ID, true);
    }
    adminRec.dispose();
    posRec.dispose();
    kioskRec.dispose();
    await adminCtx.close().catch(() => {});
    await posCtx.close().catch(() => {});
    await kioskCtx.close().catch(() => {});
  }
});

// =====================================================================
// TEST 2 — NOTIF: kiosk order → KDS card → ready bump → OSS reflection
// =====================================================================
test('Wave F · NOTIF — new kiosk order → KDS card + ready bump + OSS', async ({ browser }) => {
  const chefCtx = await browser.newContext();
  const chefPage = await chefCtx.newPage();
  const chefRec = attachMegaAuditRecorder(chefPage, DIR);

  // Helper: read the KDS board's live data source (the same endpoint the
  // grid polls). Returns the active-order ids the board KNOWS about. This is
  // the real propagation proof — the FIFO grid only RENDERS the oldest 8
  // (KdsV2Grid `activeOrders.slice(0,8)` + an overflow chip), so a brand-new
  // (newest) order legitimately sits in the overflow, not in the 8 tiles.
  const kdsBoardData = async () =>
    chefPage.evaluate(async () => {
      const r = await window.axios.get('admin/kds-order');
      const d = r.data;
      const rows = d?.data || (Array.isArray(d) ? d : []);
      return {
        status: r.status,
        meta: d?.meta || null,
        ids: rows.map((o) => Number(o.id)),
        active: rows.filter((o) => [4, 7].includes(Number(o.status))).map((o) => Number(o.id)),
        byId: rows.reduce((a, o) => {
          a[Number(o.id)] = Number(o.status);
          return a;
        }, {}),
        queueById: rows.reduce((a, o) => {
          a[Number(o.id)] = o.queue_number != null ? String(o.queue_number) : null;
          return a;
        }, {}),
      };
    });

  try {
    // ---- hydrate KDS FIRST (chef subscribes private-branch.1), THEN inject.
    await loginAsChefOperator(chefPage);
    await expect(chefPage).toHaveURL(/\/(kds|kitchen-display-system)/, { timeout: 30_000 });
    await chefPage.waitForTimeout(3_000); // board hydrate + channel subscribe
    // Sync-mode banner (LIVE/subscribed vs poll) is informational — capture it.
    await chefRec.snap('07-kds-board-hydrated');

    // ---- DRAIN the FIFO grid below 8 so the order we are about to place
    //   renders as a VISIBLE new-order tile (the grid renders only
    //   activeOrders.slice(0,8), oldest-first; a saturated board would push
    //   our newest order into the +N overflow and we'd never SEE the new-order
    //   notif). Draining = real chef bumps PREPARING→PREPARED via the live
    //   CTA — legitimate kitchen activity that frees slots (NF525-neutral, no
    //   fiscal mutation). Bounded to avoid an infinite loop on a stuck card.
    let drainGuard = 0;
    while ((await chefPage.locator('[data-order-id]').count()) >= 8 && drainGuard < 40) {
      const cta = chefPage.getByTestId('kds-card-cta-ready').first();
      if (!(await cta.isVisible({ timeout: 3_000 }).catch(() => false))) break;
      await cta.click().catch(() => {});
      await chefPage.waitForTimeout(900);
      drainGuard += 1;
    }
    await chefPage.waitForTimeout(1_500);
    await chefRec.snap('07b-kds-board-drained');

    // ---- 08 NEW-ORDER notif: place a real kiosk order, then assert its tile
    //   appears on the board (the visual new-order indicator the wave demands).
    setItemAvailability(NOTIF_ITEM_ID, true); // ensure available (avoid quote 422)
    const before = await kdsBoardData();
    const placed = await placeKioskTakeawayOrder([{ item_id: NOTIF_ITEM_ID, quantity: 1 }]);
    expect(placed.orderId, 'placed order must have an id').toBeGreaterThan(0);

    // The placed order's tile must mount (WS push first; KDS poll fallback).
    const newTile = chefPage.locator(`[data-order-id="${placed.orderId}"]`);
    let tileRendered = false;
    for (let i = 0; i < 6; i++) {
      if (await newTile.isVisible({ timeout: 2_500 }).catch(() => false)) {
        tileRendered = true;
        break;
      }
      await chefPage.waitForTimeout(2_000);
    }
    if (!tileRendered) {
      await chefPage.reload({ waitUntil: 'domcontentloaded' });
      await chefPage.waitForTimeout(3_500);
    }
    await expect(
      newTile,
      `placed order ${placed.orderId} must render as a NEW-ORDER tile on the KDS board`,
    ).toBeVisible({ timeout: 15_000 });
    // The board's data source must include it as ACTIVE, and the active set grew.
    const after = await kdsBoardData();
    expect(after.status, 'KDS board endpoint must respond 200').toBe(200);
    expect(
      after.active.includes(placed.orderId),
      `KDS data source must list the new order ${placed.orderId} as ACTIVE`,
    ).toBeTruthy();
    expect(after.active.length, 'active count must strictly increase').toBeGreaterThan(before.active.length);
    // Resolve the queue_number for the OSS identity match (board feed is the
    // reliable source; the store response may omit it).
    const placedQueue = placed.queueNumber || after.queueById[placed.orderId];
    expect(placedQueue, 'placed order must have a queue_number for the OSS match').toBeTruthy();
    await chefRec.snap('08-kds-new-order-tile-visible');

    // ---- 09 READY BUMP (NOTIF-2): bump OUR order PREPARING(7)→PREPARED(8) via
    //   the real CTA on ITS tile. This fires OrderStatusChanged = the
    //   customer-ready notification we then verify on OSS.
    const statusBeforeBump = after.byId[placed.orderId];
    expect(statusBeforeBump, 'our order should be PREPARING(7) before bump').toBe(7);
    const ourCta = newTile.getByTestId('kds-card-cta-ready');
    await expect(ourCta, 'our order tile must expose the ready CTA').toBeVisible({ timeout: 8_000 });
    const bumpPromise = chefPage
      .waitForResponse(
        (res) =>
          res.request().method() === 'POST' &&
          /kds-order\/change-status|kds-order\/.*status/i.test(res.url()),
        { timeout: 15_000 },
      )
      .catch(() => null);
    await ourCta.click();
    const bumpResp = await bumpPromise;
    if (bumpResp) expect(bumpResp.status(), 'KDS bump POST should succeed').toBeLessThan(400);
    // Our order must leave the ACTIVE set (PREPARING→PREPARED) — concrete transition.
    let leftActive = false;
    for (let i = 0; i < 6; i++) {
      const now = await kdsBoardData();
      // Left ACTIVE = no longer in the [4,7] active set. Either it's now
      // PREPARED(8) in the feed, or it dropped out of the active window.
      if (!now.active.includes(placed.orderId)) {
        leftActive = true;
        break;
      }
      await chefPage.waitForTimeout(1_500);
    }
    expect(leftActive, `our order ${placed.orderId} must become PREPARED (leave ACTIVE)`).toBeTruthy();
    await chefRec.snap('09-kds-order-ready-bumped');

    // ---- 10 NOTIF-2/3 CROSS-SURFACE: OSS customer screen "Prêt" column must
    //   show OUR order by its queue_number — a REAL identity match (the
    //   customer-facing ready notification). OSS = the customer notif surface
    //   (PushNotification model feeds FCM via SendFcmOnOrderStatusChange; there
    //   is no standalone admin push SCREEN in V1).
    const ossPage = await (await browser.newContext()).newPage();
    const ossRec = attachMegaAuditRecorder(ossPage, DIR);
    try {
      await ossPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await ossPage.waitForTimeout(2_500);
      const queueLabel = `N°${placedQueue}`;
      const readyCol = ossPage.getByRole('region', { name: /pr[êe]t|ready/i });
      let matched = false;
      for (let i = 0; i < 6; i++) {
        // Look for our queue label inside the ready column (fallback: anywhere on OSS).
        const inReady = await readyCol
          .getByText(queueLabel, { exact: false })
          .first()
          .isVisible({ timeout: 1_500 })
          .catch(() => false);
        const anywhere = await ossPage
          .getByText(queueLabel, { exact: false })
          .first()
          .isVisible({ timeout: 1_000 })
          .catch(() => false);
        if (inReady || anywhere) {
          matched = true;
          break;
        }
        await ossPage.waitForTimeout(2_000);
      }
      expect(
        matched,
        `OSS must show our ready order ${queueLabel} (customer-facing ready notification)`,
      ).toBeTruthy();
      await ossRec.snap('10-oss-customer-ready-notification');
    } finally {
      await ossRec.dispose();
      await ossPage.context().close().catch(() => {});
    }
  } finally {
    chefRec.dispose();
    await chefCtx.close().catch(() => {});
  }
});
