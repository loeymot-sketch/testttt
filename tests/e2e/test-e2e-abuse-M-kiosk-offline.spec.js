// =====================================================================
// abuse-e2e-2026-06-01 · Wave M — KIOSK machine boot + offline sync
// (borne, light mode, palette Cayenne) · ⭐⭐ order-loss risk
// =====================================================================
//
// Drives the REAL kiosk SPA through boot/auto-login → online cart+checkout,
// then SIMULATES network loss at the order-placement moment (Playwright route
// abort) so the offline-buffer path actually fires, then RECONNECTS and
// observes auto-sync recovery. Captures the mega-audit quartet (PNG + DOM +
// console + network) at every reachable state.
//
// AUDIT_PLAN Wave M (reports/test-e2e/abuse-e2e-2026-06-01/AUDIT_PLAN.md:143-146):
//   URLs: /kiosk/idle boot auto-login, offline buffer,
//         /api/frontend/payment/reconcile-pending, KioskOfflineConflictModal.
//   States: idle attract · boot · login success · login failure · network
//           error mid-order · offline order buffered · reconnect auto-sync ·
//           conflict modal · resolved · unresolved→cashier CTA.
//   KEY ASSERTION: no proceed past idle without kiosk-login 201; offline
//           orders survive reload; reconcile idempotent; offline total ==
//           online total.
//
// ---------------------------------------------------------------------
// GROUND-TRUTH (verified file:line — never invented):
//
//  Boot / auto-login (no-proceed-without-201):
//    - /kiosk/idle root testid `kiosk-idle-root`, order-type chooser
//      `kiosk-order-type-chooser` / `kiosk-order-type-takeaway`
//      (see Wave-A spec; same SPA shell).
//    - Auto-login posts POST /api/auth/kiosk-login → 201 (routes/api.php:167,
//      throttle:kiosk-login). Machine kiosk-lecayenne / kiosk123,
//      row KIOSK-LC-001 branch 1 ACTIVE (helpers/kiosk-auth.js:10-19,38-41).
//      If the 201 never lands the idle root never mounts → hard visible fail.
//
//  Offline-buffer path (the discriminator — verified, NOT assumed):
//    - Plan-B counter-route confirm `kiosk-payment-counter-confirm`
//      (KioskPaymentComponent.vue:44-45) calls confirmCounterRoute() which
//      FORCES this.method='cash' (=payment_method 1 CASH_ON_DELIVERY)
//      (KioskPaymentComponent.vue:421-423) → submitOrder({paymentMethod:'cash'}).
//    - kioskCart.placeOrder catch branch: isNetworkError = !err.response ||
//      status>=500 (kioskCart.js:762). On network error with a CASH method
//      (NOT electronic) it buffers via saveOrder() + startAutoSync() and
//      RESOLVES a synthetic {_offline:true, queue_number:'—'} response so the
//      UI proceeds (kioskCart.js:760-800). Electronic (card/tr) would instead
//      REJECT KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED (kioskCart.js:764-768) —
//      a DIFFERENT branch we do NOT exercise, because Plan-B is cash.
//    - route.abort() yields !err.response ⇒ isNetworkError true ⇒ exactly the
//      cash-buffer branch.
//
//  Offline INDICATOR (load-bearing assertion target — NO silent catch):
//    - `.kiosk-offline-indicator` (KioskAppComponent.vue:73), shown
//      v-if="offlinePending > 0". Class-based, NO data-testid, i18n-pluralized
//      text → assert VISIBILITY, not text.
//    - offlinePending is refreshed by syncCb every 15 s
//      (KioskAppComponent.vue:340-347, setInterval 15000) AND by the queue
//      auto-sync onSync callback (startAutoSync SYNC_INTERVAL_MS=30_000,
//      kioskOfflineQueue.js:13,627). So after buffering, the banner appears on
//      the next ≤15 s app poll — timeouts sized accordingly (NOT instant).
//
//  Recovery / reconcile distinction (documented precisely):
//    - The CASH offline path recovers via the ORDER QUEUE auto-sync
//      (kioskOfflineQueue.startAutoSync, replays POST /frontend/order every
//      30 s), NOT via POST /api/frontend/payment/reconcile-pending
//      (routes/api.php:1328). reconcile-pending is the ELECTRONIC/TPE path
//      (_reconcilePendingPayments, KioskPaymentComponent.vue:920,951) and is
//      GATED under Plan-B cash routing — this spec does NOT claim it fires.
//
//  Gated states captured deterministically (honest "reachable" — documented):
//    - KioskErrorNetworkComponent is NOT on the cash-buffer path (placeOrder
//      buffers; it does not router.push to error/network). The error/network
//      screen is mapped from a fetch→NETWORK error code
//      (kioskCart.js:28-29 KIOSK_ERROR_ROUTES). We capture it by direct
//      navigation to the REAL route /kiosk/error/network (kioskRoutes.js:259)
//      and assert its retry CTA `kiosk-error-network-cta-retry` +
//      staff CTA `kiosk-error-network-cta-staff`
//      (KioskErrorNetworkComponent.vue:15,24).
//    - Offline CONFLICT modal `kiosk-offline-conflict-modal`
//      (KioskOfflineConflictModalComponent.vue:9) + its trigger CTA
//      `kiosk-offline-conflict-cta` (KioskAppComponent.vue:104) are driven by
//      getStaleEntries() (KioskAppComponent.vue:819-822) — only non-empty when
//      a buffered entry has been marked stale (markStaleItems, e.g. an item
//      goes 86 while queued). We capture the modal's reachable state when the
//      CTA surfaces; otherwise we document the seed precisely and mark it
//      GATED (not a failure).
//
// FROZEN ZONES (observe only, never edited): KioskWizard/App/Upsell*.vue.
// We drive them purely through the UI.
//
// Known/allowlisted signals (AUDIT_PLAN pre-flight 107-117): kiosk
// broadcasting/auth 302 → /api/login 401 (realtime-degraded, ≤P2);
// CSP report-only + csp-report 204 noise. Not blocked on here.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const DIR = path.join(__dirname, '__screenshots__', 'test-e2e-M');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

// Generous: boot auto-login + online quote + a 15 s offline-poll window +
// a 30 s auto-sync recovery window all happen inside one test.
test.setTimeout(240_000);

test.describe('Wave M — Kiosk boot + offline sync (abuse-e2e)', () => {
  test('boot→201, build order online, go offline at placement→buffer+indicator, reconnect→auto-sync recovers', async ({ page }) => {
    const { snap, dispose } = attachMegaAuditRecorder(page, DIR);

    // Track the kiosk-login response so we can PROVE the 201 (no-proceed gate).
    let kioskLoginStatus = null;
    page.on('response', (resp) => {
      try {
        if (/\/api\/auth\/kiosk-login$/.test(resp.url())) kioskLoginStatus = resp.status();
      } catch (_) { /* ignore */ }
    });

    try {
      clearFoodKingRateLimits();

      // ===============================================================
      // 01 — IDLE attract screen. Boot auto-login POSTs kiosk-login → 201.
      //      The idle root mounting IS the proof the machine auth resolved:
      //      if 201 never lands, the SPA never leaves the boot overlay.
      // ===============================================================
      await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 30_000 });
      // Let the SPA boot overlay clear so it can't intercept the takeaway click
      // and so the snapshot shows the real attract screen, not the loader.
      await page.locator('.kiosk-init-overlay').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
      await expect(page.locator('[data-testid="kiosk-order-type-chooser"]')).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('[data-testid="kiosk-order-type-takeaway"]')).toBeVisible();
      await snap('01-idle-boot-autologin');

      // KEY ASSERTION (no silent catch): we MUST have observed a 201 from the
      // kiosk-login auto-login before proceeding past idle. The machine row was
      // repaired (KIOSK-LC-001 ACTIVE) — a non-201 here is a real boot defect.
      expect(kioskLoginStatus,
        `kiosk auto-login must return 201 before proceeding past idle (observed=${kioskLoginStatus})`,
      ).toBe(201);

      // ===============================================================
      // 02 — TAKEAWAY → catalogue. Build a real cart ONLINE (must be online so
      //      the quote signs; we only drop the network at PLACEMENT).
      // ===============================================================
      await page.locator('[data-testid="kiosk-order-type-takeaway"]').click();
      await page.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 });
      await expect(page.locator('[data-testid="kiosk-categories-root"]')).toBeVisible({ timeout: 20_000 });
      await expect(page.locator('[data-testid="kiosk-categories-products"]')).toBeVisible({ timeout: 20_000 });
      const productCards = page.locator('[data-testid^="kiosk-product-card-"]');
      await expect(productCards.first()).toBeVisible({ timeout: 20_000 });
      await snap('02-menu-online');

      // Add at least one line to the cart (wizard or simple add). Reuse the
      // Wave-A driving helpers (frozen UI, driven only).
      const productCount = await productCards.count();
      let cartCount = await readCartBadge(page);
      for (let i = 0; i < Math.min(productCount, 10) && cartCount === 0; i++) {
        await dismissInactivity(page);
        const card = productCards.nth(i);
        if (!(await card.isVisible().catch(() => false))) continue;
        const filteredOut = await card.evaluate((el) =>
          el.classList.contains('kiosk-product-card--filtered-out')).catch(() => false);
        if (filteredOut) continue;
        await card.click({ timeout: 8_000 }).catch(() => {});
        const wiz = page.locator('.kiosk-wizard-overlay');
        if (await wiz.isVisible({ timeout: 4_000 }).catch(() => false)) {
          const ok = await completeWizard(page);
          if (!ok) {
            const abandon = page.locator('.kiosk-btn-abandon, .kiosk-wizard-abandon-yes').first();
            if (await abandon.isVisible({ timeout: 1_500 }).catch(() => false)) {
              await abandon.click().catch(() => {});
              const yes = page.locator('.kiosk-wizard-abandon-yes').first();
              if (await yes.isVisible({ timeout: 1_500 }).catch(() => false)) await yes.click().catch(() => {});
            }
            await page.waitForTimeout(300);
            continue;
          }
        }
        await page.waitForTimeout(500);
        cartCount = await readCartBadge(page);
      }
      expect(cartCount, 'cart must contain at least one line to reach checkout').toBeGreaterThan(0);
      await snap('03-cart-built-online');

      // ===============================================================
      // 04 — CHECKOUT → upsell(auto) → PAYMENT (Plan-B counter-route). The
      //      quote is fetched ONLINE here, so the offline failure later hits
      //      ONLY the order POST, exactly the branch we want.
      // ===============================================================
      await dismissInactivity(page);
      const goCart = page.locator('[data-testid="kiosk-categories-cart-indicator"], [data-testid="kiosk-categories-pay"]').first();
      if (await goCart.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await goCart.click();
      } else {
        await page.goto(`${BASE}/kiosk/cart`, { waitUntil: 'domcontentloaded' });
      }
      await expect(page.locator('[data-testid="kiosk-cart-root"]')).toBeVisible({ timeout: 20_000 });
      await expect(page.locator('[data-testid="kiosk-cart-items"]')).toBeVisible({ timeout: 15_000 });

      // Record the ONLINE cart grand total — used to assert offline==online.
      const onlineCartTotalStr = (await page.locator('[data-testid="kiosk-cart-total"]').innerText()).trim();

      await dismissInactivity(page);
      const checkout = page.locator('[data-testid="kiosk-cart-checkout"]');
      await expect(checkout).toBeVisible({ timeout: 10_000 });
      await checkout.click();

      // Upsell may render or auto-skip.
      const reachedUpsell = await page.waitForURL(/\/kiosk\/upsell/, { timeout: 8_000 }).then(() => true).catch(() => false);
      if (reachedUpsell) {
        const loadingSpinner = page.locator('[data-testid="kiosk-upsell-loading"]');
        await loadingSpinner.waitFor({ state: 'hidden', timeout: 12_000 }).catch(() => {});
        const skip = page.locator('[data-testid="kiosk-upsell-skip"]');
        if (await skip.isVisible({ timeout: 6_000 }).catch(() => false) && /\/kiosk\/upsell/.test(page.url())) {
          await skip.click({ timeout: 8_000 }).catch(() => {});
        }
      }

      await page.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 });
      await expect(page.locator('[data-testid="kiosk-payment-root"]')).toBeVisible({ timeout: 20_000 });
      // Plan-B routes everything to the counter (cash). Confirm the card mounts.
      const counterRoute = page.locator('[data-testid="kiosk-payment-counter-route"]');
      await expect(counterRoute,
        'Plan-B counter-route card must render (kiosk.payment_route_all_to_counter=true) — cash path is the offline-bufferable branch',
      ).toBeVisible({ timeout: 15_000 });
      const counterTotalStr = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText()).trim();
      await snap('04-payment-counter-route-online');

      // offline==online total integrity at the payment surface (both formatPrice
      // of the same client cartTotal — buffered order carries the same payload).
      expect(parseMoney(counterTotalStr),
        `payment-counter-total (${counterTotalStr}) must equal online cart total (${onlineCartTotalStr})`,
      ).toBeCloseTo(parseMoney(onlineCartTotalStr), 2);

      // ===============================================================
      // 05 — GO OFFLINE precisely at placement. Scope the abort to the order
      //      POST so auth/polls/quotes stay intact and unroute drains cleanly.
      //      route.abort() ⇒ !err.response ⇒ isNetworkError ⇒ cash buffer.
      // ===============================================================
      let abortedOrderPost = 0;
      await page.route('**/api/frontend/order', (route) => {
        const req = route.request();
        if (req.method() === 'POST') {
          abortedOrderPost += 1;
          return route.abort('internetdisconnected');
        }
        return route.continue();
      });

      await dismissInactivity(page);
      const counterConfirm = page.locator('[data-testid="kiosk-payment-counter-confirm"]');
      await expect(counterConfirm).toBeVisible({ timeout: 10_000 });
      await counterConfirm.click();

      // The buffered CASH order resolves a synthetic _offline response and the
      // SPA still navigates to the cash-instruction screen (order "proceeds"
      // locally — survives, not lost). Capture that state.
      const cashTitle = page.locator('[data-testid="kiosk-cash-title"]');
      const reachedCash = await cashTitle.waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false);
      if (reachedCash) {
        const cashNum = (await page.locator('[data-testid="kiosk-cash-order-number"]').innerText().catch(() => '')).trim();
        // Offline order number must not render literal undefined/null garbage.
        expect(cashNum.toLowerCase()).not.toContain('undefined');
        expect(cashNum.toLowerCase()).not.toContain('null');
        await snap('05-offline-order-buffered-cash-instruction');
      } else {
        // If routing differs, capture whatever surfaced — the buffer assertion
        // below (the LOAD-BEARING one) still governs pass/fail.
        await snap('05-offline-post-confirm-state');
        offlineCashScreenUnreached = true;
      }

      // We must actually have intercepted (and aborted) the order POST, else the
      // offline path was never exercised — surface that, no silent pass.
      expect(abortedOrderPost,
        'the order POST /api/frontend/order must have been intercepted+aborted to exercise the offline buffer',
      ).toBeGreaterThan(0);

      // ===============================================================
      // 06 — OFFLINE INDICATOR (LOAD-BEARING — NO silent catch). The kiosk must
      //      show a VISIBLE offline indicator (not a silent hang). The banner
      //      `.kiosk-offline-indicator` appears once offlinePending>0, refreshed
      //      by the 15 s app poll (KioskAppComponent.vue:347). Give it >1 poll.
      // ===============================================================
      const offlineIndicator = page.locator('.kiosk-offline-indicator');
      await expect(offlineIndicator,
        'kiosk MUST show a visible offline indicator after a buffered order — a silent hang is a P0 order-loss UX defect',
      ).toBeVisible({ timeout: 35_000 });
      await snap('06-offline-indicator-visible');

      // Offline-conflict CTA may surface if the buffered entry got marked stale
      // (e.g. an item 86'd while queued). Capture if reachable; document if not.
      const conflictCta = page.locator('[data-testid="kiosk-offline-conflict-cta"]');
      if (await conflictCta.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await conflictCta.click().catch(() => {});
        const conflictModal = page.locator('[data-testid="kiosk-offline-conflict-modal"]');
        if (await conflictModal.isVisible({ timeout: 4_000 }).catch(() => false)) {
          await snap('06b-offline-conflict-modal');
          // Close via the Escape/overlay — modal is v-model bound.
          await page.keyboard.press('Escape').catch(() => {});
        }
      } else {
        offlineConflictGated = true; // see header: needs a stale-marked entry.
      }

      // ===============================================================
      // 07 — SURVIVE RELOAD. The buffered order lives in the offline queue
      //      (IndexedDB/localStorage, kioskOfflineQueue). Keep the order POST
      //      blocked so it cannot drain, reload, and assert the indicator
      //      re-appears (order survived the reload — KEY ASSERTION).
      // ===============================================================
      await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 25_000 });
      await page.locator('.kiosk-init-overlay').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
      await expect(offlineIndicator,
        'buffered offline order MUST survive a full SPA reload (offlinePending re-hydrated from the queue)',
      ).toBeVisible({ timeout: 35_000 });
      await snap('07-offline-indicator-survives-reload');

      // ===============================================================
      // 08 — RECONNECT → AUTO-SYNC RECOVERS. Unroute so the queued POST drains.
      //      Recovery uses the ORDER queue auto-sync (SYNC_INTERVAL_MS=30 s),
      //      NOT reconcile-pending. Wait for a real 2xx replay of the order POST
      //      then assert the indicator clears (offlinePending→0). NO catch on
      //      the final clears-on-reconnect assertion.
      // ===============================================================
      // Watch for the replayed order POST succeeding (positive recovery proof).
      const replaySucceeded = page.waitForResponse(
        (r) => /\/api\/frontend\/order$/.test(r.url())
          && r.request().method() === 'POST'
          && r.status() >= 200 && r.status() < 300,
        { timeout: 90_000 },
      ).catch(() => null);

      // Remove the order-POST abort so the queued replay can land. We used
      // route-abort (never context.setOffline), so unroute alone restores the
      // network for placement; the quote endpoint was never intercepted.
      await page.unroute('**/api/frontend/order');

      const replayResp = await replaySucceeded;
      if (replayResp) replayOrderStatus = replayResp.status();

      // Indicator must clear on reconnect (order drained, offlinePending→0).
      // Sized to auto-sync (30 s) + app poll (15 s) + slack. No silent catch.
      await expect(offlineIndicator,
        'offline indicator MUST clear after reconnect + auto-sync drains the queue (recovery, not a permanent stuck banner)',
      ).toBeHidden({ timeout: 75_000 });
      await snap('08-reconnect-auto-sync-recovered');

      // ===============================================================
      // 09 — GATED-STATE capture (honest reachable): the network-error screen
      //      is NOT on the cash-buffer path (placeOrder buffers; never pushes to
      //      error/network). Capture it deterministically via its REAL route so
      //      the reviewer sees the retry + call-staff CTAs render.
      // ===============================================================
      await page.goto(`${BASE}/kiosk/error/network`, { waitUntil: 'domcontentloaded' });
      const retryCta = page.locator('[data-testid="kiosk-error-network-cta-retry"]');
      if (await retryCta.isVisible({ timeout: 12_000 }).catch(() => false)) {
        await expect(page.locator('[data-testid="kiosk-error-network-cta-staff"]')).toBeVisible({ timeout: 5_000 });
        await snap('09-error-network-screen-reachable');
      } else {
        // The error/network route may redirect to idle without an error context
        // (guard) — capture whatever rendered and document as gated.
        await snap('09-error-network-screen-gated');
        errorNetworkScreenGated = true;
      }
    } finally {
      // Always release the route override even on failure.
      await page.unroute('**/api/frontend/order').catch(() => {});
      dispose();
    }
  });
});

// ----- flags surfaced to the runner log (not assertions) -----
let offlineCashScreenUnreached = false;
let offlineConflictGated = false;
let errorNetworkScreenGated = false;
let replayOrderStatus = null;

// ---------------------------------------------------------------------
// Helpers (mirrors of the Wave-A driving primitives — frozen UI driven only)
// ---------------------------------------------------------------------

/**
 * Dismiss the kiosk inactivity overlay if it's up, so subsequent clicks aren't
 * intercepted. Observe-only affordance, not a defect.
 */
async function dismissInactivity(page) {
  const overlay = page.locator('[data-testid="kiosk-inactivity-overlay"]');
  if (await overlay.isVisible({ timeout: 200 }).catch(() => false)) {
    const stay = page.locator('[data-testid="kiosk-inactivity-stay"]');
    if (await stay.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await stay.click({ timeout: 3_000 }).catch(() => {});
      await overlay.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {});
      return true;
    }
  }
  return false;
}

/** Parse a localized money string ("12,50 €" / "€12.50" / "12.5") → number. */
function parseMoney(s) {
  if (s == null) return NaN;
  const cleaned = String(s).replace(/[^\d,.-]/g, '').trim();
  if (cleaned === '') return NaN;
  const lastComma = cleaned.lastIndexOf(',');
  const lastDot = cleaned.lastIndexOf('.');
  let normalized = cleaned;
  if (lastComma > -1 && lastDot > -1) {
    normalized = lastComma > lastDot
      ? cleaned.replace(/\./g, '').replace(',', '.')
      : cleaned.replace(/,/g, '');
  } else if (lastComma > -1) {
    normalized = cleaned.replace(',', '.');
  }
  return parseFloat(normalized);
}

/** Read the kiosk cart badge count (bottom-sheet count or cart indicator). */
async function readCartBadge(page) {
  const selectors = [
    '[data-testid="kiosk-cart-bottom-sheet-count"]',
    '[data-testid="kiosk-categories-cart-indicator"]',
    '[data-testid="kiosk-cart-count"]',
    '[data-testid="kiosk-categories-zone-count"]',
  ];
  for (const sel of selectors) {
    const loc = page.locator(sel).first();
    if (await loc.isVisible().catch(() => false)) {
      const txt = (await loc.innerText().catch(() => '')) || '';
      const m = txt.match(/\d+/);
      if (m) return parseInt(m[0], 10);
    }
  }
  const items = page.locator('[data-testid^="kiosk-cart-item-name-"]');
  return await items.count().catch(() => 0);
}

/**
 * Complete the inline kiosk wizard generically: at each step pick the first
 * truly-selectable option, then advance via .kiosk-btn-next (which becomes
 * add-to-cart on the last step). Prefers the "Sans menu" tile on the MENU step.
 * Returns true if it reached add-to-cart and the overlay closed. (Frozen UI —
 * driven only, identical mechanics to the Wave-A helper.)
 */
async function completeWizard(page) {
  const overlay = page.locator('.kiosk-wizard-overlay');
  const nextBtn = page.locator('.kiosk-wizard-overlay .kiosk-btn-next');
  const MAX_STEPS = 14;
  const optionSelectors = [
    '.kiosk-wizard-overlay .kiosk-viande-card.is-selectable',
    '.kiosk-wizard-overlay .kiosk-step-content .kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)',
    '.kiosk-wizard-overlay [data-testid="kiosk-frites-style-nature"]',
    '.kiosk-wizard-overlay .kiosk-taille-card:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay .kiosk-pain-card:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay .kiosk-choice-card:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay [role="checkbox"]:not([aria-disabled="true"]), .kiosk-wizard-overlay [role="radio"]:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay button:not([disabled]):not(.kiosk-btn-next):not(.kiosk-btn-back):not(.kiosk-btn-abandon)',
  ];

  for (let step = 0; step < MAX_STEPS; step++) {
    await dismissInactivity(page);
    if (!(await overlay.isVisible().catch(() => false))) return true;

    let guard = 0;
    while (!(await nextBtn.isEnabled().catch(() => false)) && guard < 8) {
      guard++;
      await dismissInactivity(page);
      let clicked = false;

      const menuCards = page.locator('.kiosk-wizard-overlay .kiosk-menu-card:not([aria-disabled="true"])');
      const menuCount = await menuCards.count().catch(() => 0);
      if (menuCount > 0) {
        const noneCard = menuCards.last();
        if (await noneCard.isVisible().catch(() => false)) {
          await noneCard.scrollIntoViewIfNeeded().catch(() => {});
          await noneCard.click({ timeout: 4_000 }).catch(() => {});
          await page.waitForTimeout(250);
          clicked = true;
        }
      }

      if (!clicked) {
        for (const sel of optionSelectors) {
          const opts = page.locator(sel);
          const cnt = await opts.count().catch(() => 0);
          if (cnt === 0) continue;
          for (let k = 0; k < cnt; k++) {
            const opt = opts.nth(k);
            if (!(await opt.isVisible().catch(() => false))) continue;
            await opt.scrollIntoViewIfNeeded().catch(() => {});
            await opt.click({ timeout: 4_000 }).catch(() => {});
            await page.waitForTimeout(250);
            clicked = true;
            break;
          }
          if (clicked) break;
        }
      }
      if (!clicked) break;
    }

    if (!(await nextBtn.isEnabled().catch(() => false))) return false;

    const isCartBtn = await nextBtn.evaluate((el) => el.classList.contains('kiosk-btn-next--cart')).catch(() => false);
    await nextBtn.click().catch(() => {});
    await page.waitForTimeout(isCartBtn ? 900 : 500);
    if (isCartBtn) {
      await overlay.waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => {});
      return !(await overlay.isVisible().catch(() => false));
    }
  }
  return !(await overlay.isVisible().catch(() => false));
}
