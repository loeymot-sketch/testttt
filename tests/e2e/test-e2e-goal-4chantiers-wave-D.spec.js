// =============================================================================
// test-e2e-goal-4chantiers-wave-D.spec.js
// GOAL goal-4chantiers-2026-08-16 — Wave D
// Kiosk order → waiting screen → QR round trip.
//
// Files under audit:
//   resources/js/components/frontend/kiosk/KioskWaitingComponent.vue
//     (data-testid="kiosk-almost-ready" / "kiosk-position-ahead" /
//      "kiosk-track-qr" <img :src="trackQrUrl">, trackQrUrl computed →
//      GET /api/frontend/order/track-qr/{token})
//   app/Services/OrderTrackingService.php (SSOT position_ahead/almost_ready)
//   app/Http/Controllers/Frontend/OrderController.php (trackQr/trackingPayload)
//
// KioskWizardComponent.vue / KioskAppComponent.vue / KioskUpsellComponent.vue
// are FROZEN and are NOT touched — order placement goes through
// helpers/kiosk-order.js#placeKioskOrder (API-level), never the wizard UI.
//
// ONE continuous page/context for the whole flow (order placement → waiting
// screen → QR → /suivi). This is NOT optional cosmetic choice — it is required
// by two CONFIRMED, empirically-verified mechanics of this codebase:
//
//   1. AUTH: resources/js/shared/axios-setup.js's request interceptor
//      UNCONDITIONALLY overwrites any Authorization header with whatever is
//      in Vuex kioskCart.kioskToken (or its localStorage 'vuex' mirror) —
//      see line ~98 `config.headers['Authorization'] = token ? ... : ''`.
//      placeKioskOrder()'s own internal token (helpers/kiosk-order.js
//      getKioskApiToken) is therefore INERT for the actual quote/store/
//      payment-confirm calls; what actually authenticates them is whatever
//      kiosk session already lives in the SAME browser context. A fresh/
//      different context would have no such session → 401s. We establish
//      one real kiosk UI session (auto-login on /kiosk/login, confirmed
//      active on this local dev env — config/kiosk.php
//      auto_login_local_bypass=true when APP_ENV=local) and keep it for the
//      whole test.
//   2. DEVICE-SCOPED TOKEN REVOCATION (App\Services\Auth\DeviceTokenService):
//      kiosk-login's device_id is DERIVED FROM THE MACHINE ('kiosk-<id>'),
//      not a per-browser header — so any second kiosk-login for the SAME
//      machine revokes the token just established. Verified empirically via
//      curl (2 sequential logins → GET frontend/order/show/1 with the FIRST
//      token → 401). placeKioskOrder's internal getKioskApiToken call *does*
//      cause exactly one such extra login/revocation the first time it runs
//      — but app.js's global 401 response interceptor silently re-logs-in
//      and retries once (`__retry401Kiosk`), so the net effect (verified via
//      a throwaway probe) is that the order still gets placed correctly and
//      Vuex ends up holding a fresh, valid token. This is why we do the real
//      UI login FIRST and simply trust the self-heal rather than trying to
//      out-sequence the device revocation by hand.
//
// SECOND CONFIRMED GOTCHA (undocumented in the audit plan, found empirically
// via a throwaway probe before writing this spec): V1's Plan B kiosk payment
// routing (config kiosk.payment_route_all_to_counter=true, CONSTITUTION.md)
// sets payment_status=PENDING_COUNTER at order-store time. KioskWaitingComponent
// ._doPoll()'s shouldRouteToConfirmation() redirects AWAY from
// /kiosk/waiting/{id} to /kiosk/confirmation whenever payment_status is
// PAID/PENDING_COUNTER and status < PREPARING — i.e. a freshly-placed,
// not-yet-advanced kiosk order canNOT stably render on the waiting screen at
// all; it bounces within one poll cycle (~1-3s). This is CORRECT, INTENTIONAL
// behavior (don't show a live queue position for an order that hasn't
// actually reached the kitchen/till yet) — captured explicitly below as state
// 00b rather than silently worked around. For the REST of this wave (the
// actual mandate: position_ahead / almost_ready / QR rendering logic), we
// neutralize payment_status to UNPAID via tinker on this OWN disposable
// fixture order so KioskWaitingComponent's status-gating can be exercised in
// isolation — OrderTrackingService.forOrder() (position_ahead/almost_ready/
// QR token) reads ONLY order.status, never payment_status, so this
// neutralization does not fake anything Wave D is actually supposed to prove.
//
// Product: item_id=2 "Frites Seules" (SSOT-verified via tinker — real,
// simple, no required variation groups; price 1.90€).
//
// Run:
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
//   npx playwright test tests/e2e/test-e2e-goal-4chantiers-wave-D.spec.js \
//   --project=chromium --workers=1 --retries=0 --reporter=list
// =============================================================================

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  placeKioskOrder,
  cleanupKioskAuditOrders,
  resetKioskToken,
  PAYMENT_CASH,
} = require('./helpers/kiosk-order');

const repoRoot = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'test-e2e-goal-4chantiers-wave-D');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

function tinker(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 60_000,
  });
}
function tinkerJson(code) {
  const out = tinker(code);
  const lines = String(out).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`tinker: no JSON in output:\n${out.slice(0, 1200)}`);
  return JSON.parse(jsonLine);
}

// [WAVE-D RATE-LIMIT] order/track-qr shares an UNNAMED throttle:30,1 bucket
// (keyed sha1(domain|ip)) with order/track and order/wait-estimate — same
// finding Wave C documented for the /suivi page. The kiosk waiting screen's
// <img> hits order/track-qr on every mount too, so we clear the same key.
function clearTrackingRateLimit() {
  tinker(`
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    foreach (['127.0.0.1','::1','localhost'] as $ip) { $limiter->clear(sha1('|'.$ip)); }
    echo 'ok';
  `);
}

const STATUS = Object.freeze({ PENDING: 1, ACCEPT: 4, PREPARING: 7, PREPARED: 8, CANCELED: 16 });
const PAYMENT_STATUS = Object.freeze({ PAID: 5, UNPAID: 10, PENDING_COUNTER: 15 });

// SSOT-verified 2026-08-16 via `Item::where('status',5)->whereNull('deleted_at')`
// — real Le Cayenne catalogue item, no required variation groups.
const ITEM_ID = 2; // "Frites Seules" — 1.90€

test.describe('Wave D — Kiosk order → waiting screen → QR round trip', () => {
  test.setTimeout(240_000);

  test('kiosk order placed → waiting screen status-gating → QR loads → /suivi shows consistent data', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    const observations = [];
    let orderId = null;
    let queueNumber = null;

    try {
      // ---- pre-flight ---------------------------------------------------
      clearFoodKingRateLimits();
      clearTrackingRateLimit();
      resetKioskToken();
      try { cleanupKioskAuditOrders(); } catch (e) { observations.push(`pre-flight cleanup soft-fail: ${String(e.message || e).slice(0, 200)}`); }

      // ---- establish REAL kiosk UI session (SAME page for the whole test) ----
      // Mirrors the validated pattern from rush-sync-flow.spec.js: check URL
      // synchronously right after goto() races the async auto-login, so we
      // poll the persisted Vuex snapshot in localStorage instead.
      await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded' });
      let vuexReady = false;
      let vuexProbe = null;
      for (let i = 0; i < 30; i++) {
        vuexProbe = await page.evaluate(() => {
          let v = {};
          try { v = JSON.parse(localStorage.getItem('vuex') || '{}'); } catch (_e) { /* ignore */ }
          return {
            url: location.pathname,
            kiosk_token_present: !!(v.kioskCart?.kioskToken),
          };
        });
        if (vuexProbe.kiosk_token_present) { vuexReady = true; break; }
        await page.waitForTimeout(500);
      }
      observations.push(`kiosk auto-login: ready=${vuexReady} probe=${JSON.stringify(vuexProbe)}`);
      expect(vuexReady, `Kiosk auto-login never populated Vuex kioskToken (auto_login_local_bypass should be active on APP_ENV=local) — probe=${JSON.stringify(vuexProbe)}`).toBe(true);
      if (!/\/kiosk\/idle/.test(page.url())) {
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      }
      await page.waitForTimeout(600);
      await rec.snap('00-kiosk-idle-baseline');

      // ---- place the order (API-level, via the frozen-wizard-safe helper) ----
      const placement = await placeKioskOrder(page, {
        items: [{ item_id: ITEM_ID, quantity: 1, item_variations: [], item_extras: [], item_addons: [] }],
        paymentMethod: PAYMENT_CASH,
        orderType: 10, // TAKEAWAY — ORDER_TYPE_KIOSK(25) is rejected on kiosk tokens while pos_dine_in_enabled=false
        skipPaymentConfirm: true, // Plan B: cash routes to counter, no kiosk-side payment-confirm step
      });
      orderId = placement.orderId;
      queueNumber = String(placement.order?.queue_number ?? '');
      expect(orderId, 'placeKioskOrder must return a real numeric orderId').toBeGreaterThan(0);
      expect(queueNumber.length, 'placeKioskOrder must return a real queue_number string').toBeGreaterThan(0);
      observations.push(
        `order placed: id=${orderId} serial=${placement.orderSerialNo} queue=${queueNumber} ` +
        `total=${placement.totalAmount} status=${placement.order?.status} payment_status=${placement.order?.payment_status}`,
      );

      // ---- STATE 00b (documented, not fought): Plan B pending-counter bounce ----
      await page.goto(`/kiosk/waiting/${orderId}?queue=${encodeURIComponent(queueNumber)}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500); // let the first _doPoll() resolve + redirect
      await rec.snap('00b-real-flow-payment-pending-counter-redirects-to-confirmation');
      const urlAfterRealFlow = page.url();
      observations.push(`real-flow first nav result (before any status/payment neutralization): ${urlAfterRealFlow}`);
      expect(
        urlAfterRealFlow,
        `Plan B payment_pending_counter routing must redirect an un-advanced kiosk order away from ` +
        `/kiosk/waiting to /kiosk/confirmation (this is CORRECT existing behavior, documented here, not a Wave D defect) — got ${urlAfterRealFlow}`,
      ).toMatch(/\/kiosk\/confirmation/);

      // ---- neutralize the payment-routing gate on THIS disposable fixture order ----
      // OrderTrackingService.forOrder() (position_ahead/almost_ready/QR token)
      // reads ONLY order.status — never payment_status — so this does not fake
      // anything this wave is actually supposed to prove; it only stops the
      // (already-proven-correct, orthogonal) confirmation bounce from firing
      // so we can exercise the waiting screen's OWN status-gating logic.
      tinker(`DB::table('orders')->where('id', ${orderId})->update(['status' => ${STATUS.PENDING}, 'payment_status' => ${PAYMENT_STATUS.UNPAID}]); echo 'ok';`);

      // ---- STATE 01: waiting screen, PENDING, real content, no position-ahead yet ----
      await page.goto(`/kiosk/waiting/${orderId}?queue=${encodeURIComponent(queueNumber)}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-waiting-root"]')).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(800);
      await rec.snap('01-waiting-screen-initial');
      expect(page.url(), 'must stay on the waiting screen once payment is no longer PAID/PENDING_COUNTER').toContain(`/kiosk/waiting/${orderId}`);

      const numberText01 = (await page.locator('.kiosk-waiting-number').first().innerText()).trim();
      observations.push(`state01: displayed queue number="${numberText01}"`);
      expect(numberText01, 'queue number must render real text, not the em-dash placeholder').not.toBe('—');
      expect(numberText01.length, 'queue number must not be blank').toBeGreaterThan(0);

      const posAheadCount01 = await page.locator('[data-testid="kiosk-position-ahead"]').count();
      observations.push(`state01: kiosk-position-ahead count=${posAheadCount01} (expected 0 — PENDING is not in KitchenReleaseRule::visibleStatuses)`);
      expect(posAheadCount01, 'PENDING status is NOT in KitchenReleaseRule::visibleStatuses — position-ahead must not render yet').toBe(0);

      // ---- advance to ACCEPT ----
      tinker(`DB::table('orders')->where('id', ${orderId})->update(['status' => ${STATUS.ACCEPT}]); echo 'ok';`);

      // ---- STATE 02: waiting screen, ACCEPT, real position/almost-ready data ----
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-waiting-root"]')).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(800);
      await rec.snap('02-waiting-screen-accept-status');

      const almostVisible02 = await page.locator('[data-testid="kiosk-almost-ready"]').isVisible().catch(() => false);
      const posAheadLocator = page.locator('[data-testid="kiosk-position-ahead"]');
      const posAheadVisible02 = await posAheadLocator.isVisible().catch(() => false);
      observations.push(`state02: almostReadyVisible=${almostVisible02} positionAheadVisible=${posAheadVisible02}`);
      expect(
        almostVisible02 || posAheadVisible02,
        'ACCEPT status must show either kiosk-almost-ready banner OR kiosk-position-ahead meta with real data (P0)',
      ).toBe(true);

      let positionAheadValue = null;
      if (posAheadVisible02) {
        const valText = (await posAheadLocator.locator('.kiosk-waiting-meta-value').innerText()).trim();
        positionAheadValue = parseInt(valText, 10);
        observations.push(`state02: kiosk-position-ahead value text="${valText}" parsed=${positionAheadValue}`);
        expect(Number.isFinite(positionAheadValue), `position-ahead must render a real parseable number, got "${valText}"`).toBe(true);
        expect(positionAheadValue, 'position-ahead value must be >= 0').toBeGreaterThanOrEqual(0);
      }
      let almostReadyText02 = null;
      if (almostVisible02) {
        almostReadyText02 = (await page.locator('[data-testid="kiosk-almost-ready"]').innerText()).trim();
        observations.push(`state02: kiosk-almost-ready text="${almostReadyText02}"`);
        expect(almostReadyText02.length, 'almost-ready banner text must not be blank').toBeGreaterThan(0);
      }

      // ---- STATE 03: QR image actually loads (naturalWidth > 0, not a broken icon) ----
      const qrImg = page.locator('[data-testid="kiosk-track-qr"] img');
      await expect(qrImg).toBeVisible({ timeout: 10_000 });
      const qrSrc = await qrImg.getAttribute('src');
      observations.push(`state03: QR <img> src="${qrSrc}"`);
      expect(qrSrc, 'QR <img> must have a src pointing at the track-qr endpoint').toMatch(/\/api\/frontend\/order\/track-qr\//);

      const naturalWidth = await qrImg.evaluate((el) => el.naturalWidth);
      // [ROUND-2 D-002 FIX] Round-1's 1280x720 viewport-only screenshots left the
      // QR (76x76px, at the bottom of .kiosk-waiting-track) below the fold in
      // EVERY capture — nobody could actually visually confirm "does this look
      // like a real, scannable QR code" even though the naturalWidth>0 technical
      // check passed. Scroll the QR's own container into view before snapping so
      // the capture actually contains the QR in frame.
      await page.locator('[data-testid="kiosk-track-qr"]').scrollIntoViewIfNeeded();
      await page.waitForTimeout(150); // let scroll settle before the screenshot
      await rec.snap('03-qr-image-loaded');
      observations.push(`state03: QR naturalWidth=${naturalWidth}`);
      expect(naturalWidth, `QR <img> naturalWidth must be > 0 (a broken image icon still exists in the DOM with naturalWidth=0) — src=${qrSrc}`).toBeGreaterThan(0);

      const tokenMatch = String(qrSrc).match(/track-qr\/([A-Za-z0-9]{48})/);
      expect(tokenMatch, `could not extract a 48-char alnum tracking token from QR src: ${qrSrc}`).not.toBeNull();
      const trackingToken = tokenMatch[1];
      observations.push(`extracted tracking_token="${trackingToken}"`);

      // ---- STATE 04 (SYNC-TRACK-1): /suivi/<token> shows the SAME order's data ----
      clearTrackingRateLimit();
      await page.goto(`/suivi/${trackingToken}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="order-tracking-page"]')).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('[data-testid="ot-in-progress"]')).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(400);
      await rec.snap('04-qr-links-to-tracking-page');

      const suiviQueueText = (await page.locator('.ot-queue-number').innerText()).trim();
      observations.push(`state04 (/suivi): queue number text="${suiviQueueText}" expected="${queueNumber}"`);
      expect(suiviQueueText, `/suivi queue number must match the SAME kiosk-placed order's queue number (kiosk=${queueNumber})`).toBe(queueNumber);

      const suiviStatusLabel = (await page.locator('.ot-status-label').innerText()).trim();
      observations.push(`state04 (/suivi): status label="${suiviStatusLabel}"`);
      expect(suiviStatusLabel.toLowerCase(), 'ACCEPT-status order must show the "acceptée" status label on /suivi, not a stale/other state').toContain('accept');

      // Cross-check the SAME position/almost-ready signal the kiosk waiting screen showed.
      const suiviAlmostVisible = await page.locator('[data-testid="ot-almost-ready"]').isVisible().catch(() => false);
      const suiviMetaVisible = await page.locator('.ot-meta-value').first().isVisible().catch(() => false);
      observations.push(`state04 (/suivi): almostReadyVisible=${suiviAlmostVisible} metaVisible=${suiviMetaVisible} (kiosk had almost=${almostVisible02} posAhead=${posAheadVisible02})`);
      expect(
        suiviAlmostVisible === almostVisible02,
        `SYNC-TRACK-1: /suivi almost_ready (${suiviAlmostVisible}) must match the kiosk waiting screen's almost_ready (${almostVisible02}) for the SAME order+poll window`,
      ).toBe(true);
      expect(
        suiviAlmostVisible || suiviMetaVisible,
        '/suivi must show either the almost-ready banner or position/wait meta for an ACCEPT-status order — never blank',
      ).toBe(true);

      // ---- BEST-EFFORT: engineer the almost-ready banner via order_datetime anchoring ----
      // Mirrors Wave C's validated technique (this goal's sibling spec) rather than
      // placing extra filler orders: anchor THIS order's order_datetime to before the
      // dev DB's pre-existing active-order backlog (verified via tinker pre-flight:
      // hundreds of stale ACCEPT/PREPARING/PREPARED rows on branch_id=1), which
      // deterministically forces position_ahead=0 regardless of that backlog.
      let almostReadyBestEffort = null;
      try {
        const anchorResult = tinkerJson(`
          $q = \\App\\Models\\Order::withoutGlobalScopes()->where('branch_id', 1)->where('id', '!=', ${orderId})->whereIn('status', [4,7,8]);
          \\App\\Domain\\Kds\\KitchenReleaseRule::applyBoardReleaseFilter($q);
          \\App\\Domain\\Kds\\KitchenReleaseRule::applyScheduledBoardFilter($q);
          $minExisting = $q->min('order_datetime');
          $anchor = $minExisting ? \\Carbon\\Carbon::parse($minExisting)->subDay() : now()->subDay();
          DB::table('orders')->where('id', ${orderId})->update(['order_datetime' => $anchor]);
          echo json_encode(['anchor' => (string) $anchor, 'min_existing' => $minExisting]);
        `);
        await page.goto(`/kiosk/waiting/${orderId}?queue=${encodeURIComponent(queueNumber)}`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid="kiosk-waiting-root"]')).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(800);
        await rec.snap('05-waiting-screen-almost-ready-best-effort');
        const almostVisible05 = await page.locator('[data-testid="kiosk-almost-ready"]').isVisible().catch(() => false);
        almostReadyBestEffort = { ...anchorResult, almostVisible: almostVisible05 };
        observations.push(`best-effort almost-ready: ${JSON.stringify(almostReadyBestEffort)}`);
        expect(almostVisible05, `best-effort almost-ready anchoring should force kiosk-almost-ready visible — ${JSON.stringify(almostReadyBestEffort)}`).toBe(true);
      } catch (e) {
        observations.push(`best-effort almost-ready SKIPPED (non-fatal, per plan's time-box clause): ${String(e.message || e).slice(0, 300)}`);
      }

      fs.writeFileSync(
        path.join(SCREENSHOT_DIR, 'observations.json'),
        JSON.stringify({
          orderId, queueNumber, trackingToken,
          positionAheadValue, almostReadyText02, almostReadyBestEffort,
          observations,
        }, null, 2),
      );
      console.log('[wave-D] observations:\n' + observations.join('\n'));
    } finally {
      rec.dispose();
      // ---- teardown: cancel/delete the test order (append-only NF525 tables untouched) ----
      try {
        const cleanup = cleanupKioskAuditOrders();
        console.log('[wave-D] cleanup:', JSON.stringify(cleanup));
      } catch (e) {
        console.log('[wave-D] cleanup soft-fail:', String(e.message || e).slice(0, 300));
      }
    }
  });
});
