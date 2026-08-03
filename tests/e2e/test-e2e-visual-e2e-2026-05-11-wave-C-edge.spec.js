// FoodKing E2E — Visual end-to-end audit Wave C — 2026-05-11
// Run ID : visual-end-to-end-2026-05-11
//
// Wave C scope : Defensive UX edge states — empty carts, error states,
// backdrop dismissal, modal stacking, mid-flow back nav, rapid double-click,
// simulated payment failure, slow network. These are the "third-eye" fuel
// the Master Dev tier hunts.
//
// Audit plan : reports/test-e2e/visual-end-to-end-2026-05-11/AUDIT_PLAN.md
// Reviewer protocol : adversarial supervisor + Master Dev consume the 4-file
// quartet per state from __screenshots__/test-e2e-wave-C-edge/.
//
// Critical authoring decisions (verified live before line 1) :
//   1. Kiosk cart is a CLIENT-SIDE Vuex store (kioskCart). There is no
//      `/api/cart` endpoint. The networked paths that gate UX (and therefore
//      can render a loading state) are :
//        - POST `frontend/order/quote`  (signed totals, gates payment screen)
//        - POST `frontend/order`        (store + fiscal seq)
//        - POST `frontend/order/{id}/payment-confirm`
//      For slow-network capture we throttle `**/frontend/order/quote**` since
//      that's the FIRST endpoint hit when transitioning catalogue→payment,
//      ensuring the loading state is actually rendered.
//   2. Payment failure simulation : `page.route('**/payment-confirm**', ...)`
//      returns 422 with JSON `{ message: 'simulated failure' }` — kiosk
//      surfaces this through `[data-testid="kiosk-payment-error"]` per the
//      KioskPaymentComponent template.
//   3. Modal stacking : kiosk toast component exposes `$refs.toast.show()`
//      on the KioskAppComponent root. We open the payment screen, then fire
//      a toast via page.evaluate() into the live Vue instance to render
//      both simultaneously.
//   4. Direct-add product (no wizard) for kiosk : id=404 Glace (cat 316
//      Desserts), price 3.80 €. hasOptions() returns false for cat 316.
//      Verified in tests/e2e/test-e2e-borne-2026-05-10-wave-D.spec.js.
//   5. POS direct-add product : id=362 Boisson Seule (cat 315). Vue
//      #item-variation-modal still opens but the vanilla pos-wizard.js
//      injects nothing — we click the visible "Ajouter au panier" CTA.
//      Verified in test-e2e-pos-kds-sync-2026-05-10-wave-A.spec.js.
//   6. pos-wizard.js is FROZEN (`public/js/pos-wizard.js`). State 10
//      captures ESC-key behavior but the spec does NOT modify the file.
//      The B-002 deferred finding (ESC may not dismiss) is precisely what
//      this state captures — the adversarial reviewer decides.
//   7. Rapid double-click on tile : click().click() with no wait. The
//      kiosk `loadingItemId` guard in onProductCardActivate should prevent
//      double-add via the `if (this.loadingItemId) return` short-circuit.
//      The POS path has no such guard — the variation modal *re-opens*
//      with the same item but the cart should NOT receive a duplicate
//      since the modal is single-instance.
//   8. observations[] is dumped to console at end-of-each test for reviewer
//      ingestion alongside the 4-file artifact quartet.
//   9. Cleanup : we don't actually create any orders in this spec (all the
//      payment flows are intercepted/cancelled BEFORE order creation).
//      cleanupOrphanTestOrders(['AUDIT-VISUAL-C-EDGE-']) is called as a
//      safety net but should be a no-op.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const {
  loginAsKiosk,
  loginAsPosOperator,
  cleanupOrphanTestOrders,
} = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-wave-C-edge');
const REPORT_DIR = path.resolve(
  __dirname,
  '..',
  '..',
  'reports',
  'test-e2e',
  'visual-end-to-end-2026-05-11',
  'round-1',
);

// ---------------------------------------------------------------------------
// Direct-add catalog items (no wizard required, verified DB live)
// ---------------------------------------------------------------------------
const KIOSK_DIRECT_ADD = { id: 404, name: 'Glace', price: 3.80 };  // cat 316 (Desserts)
const POS_DIRECT_ADD = { id: 362, name: 'Boisson Seule', price: 2.0 };

// Allowlist of routes whose interception/throttling we DO want to surface in
// observations[]. Used to verify the route handler actually fired (cf.
// advisor note : "verify whether slow-network path actually fires").
const observationsKiosk = [];
const observationsPos = [];

// Findings JSON aggregator (filled during teardown of each test).
const allFindings = [];
const allObservations = [];

// Common helper : ensure the screenshots dir exists (mega-audit-snap also
// mkdirs, but we want a clean baseline for each round 1 capture pass).
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

// Safety-net cleanup. This spec doesn't CREATE orders (everything is
// intercepted/cancelled pre-storage), so the token prefix sweep is purely
// defensive. Won't throw if no rows match.
try {
  cleanupOrphanTestOrders(['AUDIT-VISUAL-C-EDGE-']);
} catch (_e) { /* best-effort */ }

// ===========================================================================
// KIOSK EDGE — 8 states
// ===========================================================================
test.describe('visual-end-to-end wave-C edge — Kiosk', () => {
  test.setTimeout(240_000);

  test('kiosk edge states (01-08)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    const obs = observationsKiosk;

    try {
      // -------------------------------------------------------------------
      // Bootstrap : login + reach the categories screen so we have a
      // surface to drive edge states against.
      // -------------------------------------------------------------------
      clearFoodKingRateLimits();
      await loginAsKiosk(page);
      if (!/\/kiosk\/idle/.test(page.url())) {
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      }
      await page.waitForTimeout(1_500);

      // Click "À emporter" (V1 dine-in disabled) to reach /kiosk/categories
      if (!/\/kiosk\/categories/i.test(page.url())) {
        const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
        if (await takeaway.isVisible({ timeout: 8_000 }).catch(() => false)) {
          await takeaway.click({ timeout: 5_000 });
          await page.waitForURL(/\/kiosk\/(categories|menu)/, { timeout: 15_000 }).catch(() => {});
        }
      }
      await page.waitForTimeout(1_200);
      await expect(page.locator('[data-testid="kiosk-categories-sidebar"]').first())
        .toBeVisible({ timeout: 15_000 });

      // -------------------------------------------------------------------
      // STATE 01 — kiosk-cart-empty. Navigate to /kiosk/cart with an
      // empty cart. The kiosk-cart-empty container has testid
      // `kiosk-cart-empty` + a CTA `kiosk-cart-empty-cta`. We assert
      // (a) empty container visible, (b) copy ≥ 20 chars, (c) primary
      // CTA renders.
      // -------------------------------------------------------------------
      // Bypass the disabled cart indicator (when cartCount===0, the
      // bottom-bar button is disabled) by direct URL navigation.
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1_200);
      const cartEmptyState = await page.evaluate(() => {
        const empty = document.querySelector('[data-testid="kiosk-cart-empty"]');
        const cta = document.querySelector('[data-testid="kiosk-cart-empty-cta"]');
        const allText = (empty?.textContent || '').trim();
        const illustration = !!empty?.querySelector('img, svg, .kiosk-empty-illustration, .kiosk-cart-empty-icon');
        return {
          empty_visible: !!empty && empty.offsetParent !== null,
          empty_copy_len: allText.length,
          empty_copy_sample: allText.substring(0, 140),
          has_cta: !!cta && cta.offsetParent !== null,
          cta_text: cta?.textContent?.trim() || null,
          has_illustration: illustration,
        };
      });
      obs.push(`state01: ${JSON.stringify(cartEmptyState)}`);
      await snap('01-kiosk-cart-empty');

      // -------------------------------------------------------------------
      // STATE 02 — kiosk-backdrop-dismiss. Add a direct-add item, navigate
      // to /kiosk/payment, then check what happens on a click outside the
      // primary payment surface (no payment modal is rendered as a true
      // modal in kiosk — the payment IS the screen). Instead, we probe
      // the kiosk-wizard backdrop (an actual overlay) by tapping a
      // wizard-required item briefly, then clicking the overlay backdrop.
      // -------------------------------------------------------------------
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-categories-sidebar"]').first())
        .toBeVisible({ timeout: 15_000 });
      // Select Desserts cat
      const dessertsCat = page.locator(`[data-testid="kiosk-categories-sidebar-item-316"]`).first();
      if (await dessertsCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await dessertsCat.click({ timeout: 5_000 });
        await page.waitForTimeout(800);
      }
      // Add Glace (direct-add, no wizard)
      const glaceCard = page.locator(`[data-testid="kiosk-product-card-${KIOSK_DIRECT_ADD.id}"]`).first();
      const glaceVisible = await glaceCard.isVisible({ timeout: 5_000 }).catch(() => false);
      obs.push(`state02: glace_visible=${glaceVisible}`);
      if (glaceVisible) {
        await glaceCard.click({ timeout: 5_000 });
        await page.waitForTimeout(1_000);
      }
      // Now drive to payment via cart
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(800);
      // Click the cart's pay CTA (varies by component)
      const cartPay = page.locator(
        '[data-testid="kiosk-cart-pay"], [data-testid="kiosk-cart-checkout"], [data-testid="kiosk-cart-next"]'
      ).first();
      if (await cartPay.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await cartPay.click({ timeout: 5_000 }).catch(() => {});
        await page.waitForTimeout(1_000);
      } else {
        // Fallback : direct navigation
        await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1_200);
      }
      // Now we should be on /kiosk/payment. Click outside the primary
      // payment card (top-left corner) to test backdrop behavior.
      const paymentRoot = page.locator('[data-testid="kiosk-payment-root"]').first();
      const paymentVisible = await paymentRoot.isVisible({ timeout: 5_000 }).catch(() => false);
      obs.push(`state02: payment_visible=${paymentVisible} url=${page.url()}`);
      if (paymentVisible) {
        // Click far edge — simulate accidental backdrop tap
        await page.mouse.click(10, 10).catch(() => {});
        await page.waitForTimeout(500);
      }
      const backdropState = await page.evaluate(() => ({
        url: window.location.pathname,
        payment_still_visible: !!document.querySelector('[data-testid="kiosk-payment-root"]'),
        focused_tag: document.activeElement?.tagName || null,
      }));
      obs.push(`state02: backdrop_dismiss_state=${JSON.stringify(backdropState)}`);
      await snap('02-kiosk-backdrop-dismiss');

      // -------------------------------------------------------------------
      // STATE 03 — kiosk-modal-stacking. Payment surface + toast
      // simultaneously. The toast component is mounted on KioskAppComponent
      // and exposed via `$refs.toast.show(msg, type, duration)`. We reach
      // into the Vue tree to fire a toast while the payment screen is up.
      // -------------------------------------------------------------------
      if (paymentVisible) {
        const toastFireResult = await page.evaluate(() => {
          try {
            // Find KioskAppComponent in the Vue tree. KioskToastComponent is
            // mounted as ref="toast" in the app shell.
            const app = window.app || window.__VUE_APP__;
            // Walk active components via __vueParentComponent on any element
            // inside the kiosk app. The simplest path: query the toast
            // container and walk up to find the AppComponent.
            // Alternative: the toast component itself exposes .show() via its
            // proxy. Look for any DOM node with __vueParentComponent that
            // has $refs.toast.
            const all = Array.from(document.querySelectorAll('[data-testid="kiosk-payment-root"]'));
            for (const el of all) {
              let p = el.__vueParentComponent;
              while (p) {
                const proxy = p.proxy || p.ctx;
                if (proxy && proxy.$refs && proxy.$refs.toast && typeof proxy.$refs.toast.show === 'function') {
                  proxy.$refs.toast.show('Erreur réseau simulée — vérifiez la connexion', 'error', 10000);
                  return { ok: true, via: 'app-ref' };
                }
                p = p.parent;
              }
            }
            // Fallback: try to directly mutate the toast container by
            // dispatching a custom event the app listens to. If not wired,
            // create a synthetic toast element so the stacking visual is at
            // least captured.
            const container = document.querySelector('.kiosk-toast-container');
            if (container) {
              const div = document.createElement('div');
              div.className = 'kiosk-toast error';
              div.setAttribute('role', 'alert');
              div.setAttribute('data-testid', 'synthetic-toast-fallback');
              div.innerHTML = '<span class="kiosk-toast-icon">✕</span><span class="kiosk-toast-msg">Erreur réseau simulée</span>';
              container.appendChild(div);
              return { ok: true, via: 'synthetic-dom' };
            }
            return { ok: false, reason: 'no_toast_path' };
          } catch (e) {
            return { ok: false, reason: String(e.message || e).slice(0, 200) };
          }
        });
        obs.push(`state03: toast_fire=${JSON.stringify(toastFireResult)}`);
        await page.waitForTimeout(500);
      } else {
        obs.push(`state03: payment not visible — capturing whatever surface is on screen`);
      }
      // Capture the state of both surfaces visible
      const stackState = await page.evaluate(() => {
        const toast = document.querySelector('.kiosk-toast-container .kiosk-toast');
        const payment = document.querySelector('[data-testid="kiosk-payment-root"]');
        return {
          toast_present: !!toast,
          toast_visible: !!toast && toast.offsetParent !== null,
          toast_role: toast?.getAttribute('role') || null,
          payment_present: !!payment,
          payment_visible: !!payment && payment.offsetParent !== null,
        };
      });
      obs.push(`state03: stack_state=${JSON.stringify(stackState)}`);
      await snap('03-kiosk-modal-stacking');

      // -------------------------------------------------------------------
      // STATE 04 — kiosk-mid-flow-back. Open a wizard item, advance once,
      // then click the wizard-close button or browser back. Capture
      // whether the cart preserves prior items (it should — wizard close
      // != cart clear).
      // -------------------------------------------------------------------
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-categories-sidebar"]').first())
        .toBeVisible({ timeout: 12_000 });
      // Pick a wizard-required category (309 Assiettes)
      const assiettesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-309"]').first();
      if (await assiettesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await assiettesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(800);
      }
      // Pick the first product card in the visible grid
      const firstWizardItem = page.locator('[data-testid^="kiosk-product-card-"]').first();
      if (await firstWizardItem.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await firstWizardItem.click({ timeout: 5_000 });
        // Wait for wizard mount
        await page.waitForSelector('.kiosk-wizard[role="dialog"], .kiosk-wizard', {
          timeout: 8_000,
        }).catch(() => {});
        await page.waitForTimeout(1_000);
      }
      // Capture pre-back state
      const preBackCart = await page.evaluate(() => {
        const items = document.querySelectorAll('[data-testid^="kiosk-cart-bottom-sheet-item-"]');
        return { sheet_item_count: items.length };
      });
      obs.push(`state04: pre_back_cart=${JSON.stringify(preBackCart)}`);
      // Now click the wizard close button (the × in header). It may also
      // trigger an abandon-confirmation modal — capture that flow too.
      const closeBtn = page.locator('.kiosk-wizard-close, [data-testid="kiosk-wizard-close"]').first();
      const closeVisible = await closeBtn.isVisible({ timeout: 3_000 }).catch(() => false);
      obs.push(`state04: close_btn_visible=${closeVisible}`);
      if (closeVisible) {
        await closeBtn.click({ timeout: 5_000 });
        await page.waitForTimeout(500);
        // If abandon overlay shows, click "Yes, abandon"
        const abandonYes = page.locator('.kiosk-wizard-abandon-yes').first();
        if (await abandonYes.isVisible({ timeout: 1_500 }).catch(() => false)) {
          await abandonYes.click({ timeout: 3_000 });
          await page.waitForTimeout(500);
        }
      }
      const postBackState = await page.evaluate(() => {
        const wizard = document.querySelector('.kiosk-wizard[role="dialog"]');
        const sheetItems = document.querySelectorAll('[data-testid^="kiosk-cart-bottom-sheet-item-"]');
        return {
          wizard_present: !!wizard && wizard.offsetParent !== null,
          sheet_item_count: sheetItems.length,
          url: window.location.pathname,
        };
      });
      obs.push(`state04: post_back=${JSON.stringify(postBackState)}`);
      await snap('04-kiosk-mid-flow-back');

      // -------------------------------------------------------------------
      // STATE 05 — kiosk-rapid-double-click. Tap a direct-add tile twice
      // with no wait. The kiosk loadingItemId guard SHOULD prevent
      // double-add. Capture cart count BEFORE and AFTER to record actual
      // behavior — if it doubles, that's a P0 finding the reviewer will
      // see in observations + DOM.
      // -------------------------------------------------------------------
      // Reset cart by clearing it via Vuex (best-effort)
      await page.evaluate(() => {
        try {
          const app = window.app || window.__VUE_APP__;
          const store = app?.config?.globalProperties?.$store || window.$store;
          if (store && store.dispatch) {
            store.dispatch('kioskCart/clearCart').catch(() => {});
          }
        } catch (_e) { /* ignore */ }
      });
      await page.waitForTimeout(500);
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-categories-sidebar"]').first())
        .toBeVisible({ timeout: 12_000 });
      // Select Desserts (cat 316) for direct-add
      const dessertsCat2 = page.locator('[data-testid="kiosk-categories-sidebar-item-316"]').first();
      if (await dessertsCat2.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await dessertsCat2.click({ timeout: 5_000 });
        await page.waitForTimeout(800);
      }
      const glaceCard2 = page.locator(`[data-testid="kiosk-product-card-${KIOSK_DIRECT_ADD.id}"]`).first();
      const glaceVisible2 = await glaceCard2.isVisible({ timeout: 5_000 }).catch(() => false);
      obs.push(`state05: glace_visible=${glaceVisible2}`);
      if (glaceVisible2) {
        // Read cart count BEFORE
        const cartBefore = await page.evaluate(() => {
          const ind = document.querySelector('[data-testid="kiosk-categories-cart-indicator"]');
          const m = (ind?.textContent || '').match(/(\d+)/);
          return m ? parseInt(m[1], 10) : 0;
        });
        // RAPID DOUBLE-CLICK — no wait between
        await glaceCard2.click({ timeout: 3_000 });
        await glaceCard2.click({ timeout: 3_000 });
        // Wait for store to settle
        await page.waitForTimeout(1_500);
        const cartAfter = await page.evaluate(() => {
          const ind = document.querySelector('[data-testid="kiosk-categories-cart-indicator"]');
          const m = (ind?.textContent || '').match(/(\d+)/);
          return m ? parseInt(m[1], 10) : 0;
        });
        obs.push(`state05: cart_before=${cartBefore} cart_after=${cartAfter} (expected +1, double-click should NOT double-add)`);
      }
      await snap('05-kiosk-rapid-double-click');

      // -------------------------------------------------------------------
      // STATE 06 — kiosk-slow-network. Intercept frontend/order/quote
      // with a 3-second delay BEFORE driving to /kiosk/payment. Capture
      // the in-flight loading state.
      // -------------------------------------------------------------------
      // Install route BEFORE the action that triggers the request
      let slowNetworkRouteFired = false;
      await page.route('**/frontend/order/quote**', async (route) => {
        slowNetworkRouteFired = true;
        // Hold the response for 3 seconds, then continue normally so the
        // payment screen can eventually render (avoid hung test). Wrap
        // continue() in try/catch — if the route was already handled
        // (page unroute called mid-flight), swallow gracefully.
        try {
          await new Promise((resolve) => setTimeout(resolve, 3000));
          await route.continue();
        } catch (_e) {
          /* route already handled — happens when page.unroute() ran while
             we were sleeping. Safe to ignore — the visual capture during
             the 3s window is what matters. */
        }
      });
      // Trigger the quote via cart → payment navigation
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(800);
      // Click pay if there is one
      const cartPay2 = page.locator(
        '[data-testid="kiosk-cart-pay"], [data-testid="kiosk-cart-checkout"], [data-testid="kiosk-cart-next"]'
      ).first();
      if (await cartPay2.isVisible({ timeout: 3_000 }).catch(() => false)) {
        // Click WITHOUT awaiting completion — we want to snap mid-flight
        cartPay2.click({ timeout: 5_000 }).catch(() => {});
      } else {
        // Trigger navigation that fires the quote
        page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
      // Snap during the 3s window — wait ~1500ms so request is in-flight
      await page.waitForTimeout(1_500);
      const inflightState = await page.evaluate(() => {
        const spinners = document.querySelectorAll('.kiosk-wizard-spinner, .kiosk-spinner, [aria-busy="true"], .spinner, .kiosk-loading, .kiosk-pay-processing-spinner');
        const skeletons = document.querySelectorAll('.kiosk-skeleton, .skeleton, [data-loading="true"]');
        const procPanel = document.querySelector('[data-testid="kiosk-payment-processing"]');
        return {
          spinner_count: spinners.length,
          skeleton_count: skeletons.length,
          processing_panel_visible: !!procPanel && procPanel.offsetParent !== null,
          url: window.location.pathname,
        };
      });
      obs.push(`state06: route_fired=${slowNetworkRouteFired} inflight=${JSON.stringify(inflightState)}`);
      await snap('06-kiosk-slow-network');
      // Wait for the in-flight handler to complete naturally (3s + buffer)
      // BEFORE unrouting — otherwise the in-flight handler's route.continue()
      // hits "Route is already handled" and the error propagates to the test.
      await page.waitForTimeout(2_500);
      await page.unroute('**/frontend/order/quote**').catch(() => {});

      // -------------------------------------------------------------------
      // STATE 07 — kiosk-payment-failure. Intercept the payment-confirm
      // endpoint to return 422 simulated failure. Tap the cash/card
      // method to trigger confirmation, capture UX response.
      // -------------------------------------------------------------------
      let paymentFailureFired = false;
      await page.route('**/payment-confirm**', async (route) => {
        paymentFailureFired = true;
        await route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'simulated payment failure (test)', errors: { payment: ['network'] } }),
        });
      });
      // Ensure we're on /kiosk/payment with a non-empty cart. If not,
      // re-navigate to /kiosk/payment.
      if (!/\/kiosk\/payment/.test(page.url())) {
        await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1_500);
      }
      // Click a payment method (card if visible, else cash)
      const cardMethod = page.locator('[data-testid="kiosk-payment-method-card"]').first();
      const cashMethod = page.locator('[data-testid="kiosk-payment-method-cash"]').first();
      let methodClicked = null;
      if (await cardMethod.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await cardMethod.click({ timeout: 5_000 });
        methodClicked = 'card';
      } else if (await cashMethod.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await cashMethod.click({ timeout: 5_000 });
        methodClicked = 'cash';
      }
      obs.push(`state07: method_clicked=${methodClicked}`);
      // Confirm if there's an explicit confirm CTA
      const confirmBtn = page.locator('[data-testid="kiosk-payment-confirm"]').first();
      if (await confirmBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await confirmBtn.click({ timeout: 5_000 }).catch(() => {});
      }
      // Wait for failure surface to render (poll a few times)
      await page.waitForTimeout(2_500);
      const failureSurface = await page.evaluate(() => {
        const err = document.querySelector('[data-testid="kiosk-payment-error"]');
        const toast = document.querySelector('.kiosk-toast.error');
        const alert = document.querySelector('[role="alert"]');
        return {
          payment_error_visible: !!err && err.offsetParent !== null,
          payment_error_text: err?.textContent?.trim()?.substring(0, 200) || null,
          error_toast_visible: !!toast && toast.offsetParent !== null,
          any_alert_visible: !!alert,
          alert_text: alert?.textContent?.trim()?.substring(0, 200) || null,
        };
      });
      obs.push(`state07: failure_route_fired=${paymentFailureFired} surface=${JSON.stringify(failureSurface)}`);
      await snap('07-kiosk-payment-failure');
      await page.unroute('**/payment-confirm**').catch(() => {});

      // -------------------------------------------------------------------
      // STATE 08 — kiosk-i18n-EN-deep. Switch to EN at idle, navigate deep
      // into the catalogue, hunt for i18n leaks (raw keys like
      // kiosk.cart.empty). Note ADR-007 : changeLanguage() is a no-op at
      // runtime; the EN toggle may not actually flip locale. We capture
      // whatever the actual behavior is.
      // -------------------------------------------------------------------
      // Bootstrap a fresh kiosk session — clear rate limits and re-login
      // because the prior simulated-payment-failure may have left the
      // session in an odd state (or pushed us back to /kiosk/login).
      clearFoodKingRateLimits();
      await loginAsKiosk(page).catch((e) => obs.push(`state08: loginAsKiosk threw ${e.message?.substring(0, 200)}`));
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(1_500);
      const langEn = page.locator('[data-testid="kiosk-idle-lang-en"]').first();
      const langEnVisible = await langEn.isVisible({ timeout: 3_000 }).catch(() => false);
      obs.push(`state08: lang_en_visible=${langEnVisible} url=${page.url()}`);
      if (langEnVisible) {
        await langEn.click({ timeout: 5_000 }).catch(() => {});
        await page.waitForTimeout(500);
      }
      // Drive to categories : click takeaway if still on idle
      const takeawayBtn08 = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
      if (await takeawayBtn08.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await takeawayBtn08.click({ timeout: 5_000 }).catch(() => {});
        await page.waitForURL(/\/kiosk\/(categories|menu)/, { timeout: 12_000 }).catch(() => {});
      } else if (!/\/kiosk\/categories/.test(page.url())) {
        await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
      await page.waitForTimeout(1_500);
      const sidebar08 = page.locator('[data-testid="kiosk-categories-sidebar"]').first();
      const sidebar08Visible = await sidebar08.isVisible({ timeout: 12_000 }).catch(() => false);
      obs.push(`state08: sidebar_visible=${sidebar08Visible}`);
      if (sidebar08Visible) {
        const assiettesCat2 = page.locator('[data-testid="kiosk-categories-sidebar-item-309"]').first();
        if (await assiettesCat2.isVisible({ timeout: 5_000 }).catch(() => false)) {
          await assiettesCat2.click({ timeout: 5_000 }).catch(() => {});
          await page.waitForTimeout(800);
        }
        const firstItemEn = page.locator('[data-testid^="kiosk-product-card-"]').first();
        if (await firstItemEn.isVisible({ timeout: 5_000 }).catch(() => false)) {
          await firstItemEn.click({ timeout: 5_000 }).catch(() => {});
          await page.waitForSelector('.kiosk-wizard', { timeout: 8_000 }).catch(() => {});
          await page.waitForTimeout(1_500);
        }
      }
      // Scan DOM for i18n leaks (regex from REVIEWER_PROTOCOL.md cat 1)
      const i18nScan = await page.evaluate(() => {
        const re = /^[a-z]+(\.[a-z_]+){1,4}$/;
        const leaks = [];
        const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let n;
        while ((n = walk.nextNode())) {
          const t = (n.nodeValue || '').trim();
          if (t && re.test(t)) {
            leaks.push({ text: t, parent: n.parentElement?.tagName + '.' + (n.parentElement?.className || '').toString().substring(0, 60) });
            if (leaks.length >= 25) break;
          }
        }
        return { leak_count: leaks.length, samples: leaks.slice(0, 10) };
      });
      obs.push(`state08: i18n_scan=${JSON.stringify(i18nScan)}`);
      await snap('08-kiosk-i18n-EN-deep');

      // -------------------------------------------------------------------
      // Sanity : 8 kiosk PNGs minimum
      // -------------------------------------------------------------------
      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => /^0[1-8]-kiosk-/.test(f) && f.endsWith('.png'));
      // eslint-disable-next-line no-console
      console.log(`[WAVE-C-KIOSK] obs:\n  ${obs.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-C-KIOSK] kiosk PNGs written: ${written.length} → ${written.sort().join(', ')}`);
      expect(written.length, `Wave C kiosk expects 8 PNGs, got ${written.length}`).toBeGreaterThanOrEqual(8);

      // Persist observations to disk for the next stage
      allObservations.push(...obs.map((o) => `[KIOSK] ${o}`));
    } finally {
      try { dispose(); } catch (_e) { /* ignore */ }
      await ctx.close().catch(() => {});
    }
  });
});

// ===========================================================================
// POS EDGE — 7 states
// ===========================================================================
test.describe('visual-end-to-end wave-C edge — POS', () => {
  test.setTimeout(240_000);

  test('pos edge states (09-15)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    const obs = observationsPos;

    try {
      clearFoodKingRateLimits();
      await loginAsPosOperator(page);
      await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 25_000 });
      await page.waitForTimeout(1_500);

      // -------------------------------------------------------------------
      // STATE 09 — pos-cart-empty. POS cart panel has .pos-v5-cart__empty
      // placeholder. Assert presence + copy quality + pay button is
      // disabled (no items).
      // -------------------------------------------------------------------
      const posEmpty = await page.evaluate(() => {
        const empty = document.querySelector('.pos-v5-cart__empty');
        const allText = (empty?.textContent || '').trim();
        const payBtn = document.querySelector('[data-testid="pos-v5-pay"]');
        const grand = document.querySelector('[data-testid="pos-grand-total"]')?.textContent?.trim() || null;
        return {
          empty_visible: !!empty && empty.offsetParent !== null,
          empty_copy_len: allText.length,
          empty_copy_sample: allText.substring(0, 140),
          pay_btn_disabled: payBtn?.disabled === true || payBtn?.getAttribute('disabled') !== null,
          grand_total: grand,
        };
      });
      obs.push(`state09: ${JSON.stringify(posEmpty)}`);
      await snap('09-pos-cart-empty');

      // -------------------------------------------------------------------
      // STATE 10 — pos-wizard-escape. Open the variation modal on a tile,
      // press ESC, capture behavior. B-002 deferred finding suggests ESC
      // may NOT dismiss in pos-wizard.js. We CAPTURE the actual behavior.
      // FROZEN: pos-wizard.js — we do not modify it, only observe.
      // -------------------------------------------------------------------
      const directTile = page.locator(`button[data-pos-item-id="${POS_DIRECT_ADD.id}"]`).first();
      const tileVisible = await directTile.isVisible({ timeout: 5_000 }).catch(() => false);
      obs.push(`state10: direct_tile_visible=${tileVisible}`);
      if (tileVisible) {
        await directTile.click({ timeout: 5_000 });
        const modal = page.locator('#item-variation-modal');
        await expect(modal).toHaveClass(/active/, { timeout: 8_000 });
        await page.waitForTimeout(800);
        // Press ESC — capture whether modal dismisses
        await page.keyboard.press('Escape');
        await page.waitForTimeout(800);
      }
      const escState = await page.evaluate(() => {
        const modal = document.querySelector('#item-variation-modal');
        return {
          modal_present: !!modal,
          modal_has_active_class: modal?.classList?.contains('active') || false,
          modal_visible: !!modal && modal.offsetParent !== null,
          focused_tag: document.activeElement?.tagName || null,
        };
      });
      obs.push(`state10: post_esc=${JSON.stringify(escState)} (B-002 deferred: ESC may not dismiss pos-wizard)`);
      await snap('10-pos-wizard-escape');
      // Always close the modal before next state (click outside or X button)
      const closeWiz = page.locator('#item-variation-modal .modal-close, #item-variation-modal .close-modal, #item-variation-modal [data-dismiss]').first();
      if (await closeWiz.isVisible({ timeout: 1_500 }).catch(() => false)) {
        await closeWiz.click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(400);
      } else {
        // Force close via classList
        await page.evaluate(() => {
          const m = document.getElementById('item-variation-modal');
          if (m) m.classList.remove('active');
        });
        await page.waitForTimeout(300);
      }

      // -------------------------------------------------------------------
      // STATE 11 — pos-payment-cancel. Get a line into the cart, open
      // the payment modal, click the cancel/close. Captures focus return
      // + cart preservation.
      // -------------------------------------------------------------------
      // Re-add the direct-add item
      if (await directTile.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await directTile.click({ timeout: 5_000 });
        const modal2 = page.locator('#item-variation-modal');
        await expect(modal2).toHaveClass(/active/, { timeout: 8_000 }).catch(() => {});
        await page.waitForTimeout(800);
        // Click the visible "Ajouter au panier" CTA
        const addBtns = page.locator('#item-variation-modal button').filter({ hasText: /Ajouter au panier|Add to cart/i });
        const total = await addBtns.count();
        for (let i = 0; i < total; i++) {
          if (await addBtns.nth(i).isVisible({ timeout: 1_000 }).catch(() => false)) {
            await addBtns.nth(i).click({ timeout: 5_000 });
            break;
          }
        }
        // Wait for modal to close
        await expect(modal2).not.toHaveClass(/active/, { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(700);
      }
      // Open payment
      const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
      const payVisible = await payBtn.isVisible({ timeout: 3_000 }).catch(() => false);
      obs.push(`state11: pay_btn_visible=${payVisible} lines=${await page.locator('.pos-v5-cart-item').count()}`);
      if (payVisible) {
        await payBtn.click({ timeout: 5_000 });
        await expect(page.locator('#orderpayment')).toHaveClass(/active/, { timeout: 8_000 }).catch(() => {});
        await page.waitForTimeout(800);
      }
      // Click cancel (header ✕)
      const closePay = page.locator('#orderpayment .pos-v5-payment-close, #orderpayment .modal-close').first();
      const closeVisible = await closePay.isVisible({ timeout: 3_000 }).catch(() => false);
      obs.push(`state11: close_visible=${closeVisible}`);
      if (closeVisible) {
        await closePay.click({ timeout: 5_000 });
        await page.waitForTimeout(600);
      }
      const cancelState = await page.evaluate(() => {
        const modal = document.querySelector('#orderpayment');
        const cartLines = document.querySelectorAll('.pos-v5-cart-item').length;
        return {
          modal_active: modal?.classList?.contains('active') || false,
          cart_lines: cartLines,
          focused_tag: document.activeElement?.tagName || null,
        };
      });
      obs.push(`state11: cancel_state=${JSON.stringify(cancelState)}`);
      await snap('11-pos-payment-cancel');

      // -------------------------------------------------------------------
      // STATE 12 — pos-receipt-print-fail. Intercept the print endpoint
      // (admin/pos/print-receipt or similar) to return 500. Capture UX
      // response — there should be a visible error, not silent failure.
      // -------------------------------------------------------------------
      let printRouteFired = false;
      await page.route('**/print-receipt**', async (route) => {
        printRouteFired = true;
        await route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'simulated print failure (test)' }),
        });
      });
      await page.route('**/admin/pos/print**', async (route) => {
        printRouteFired = true;
        await route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'simulated print failure (test)' }),
        });
      });
      // We didn't complete a payment so there may be no print trigger available.
      // We attempt to find any visible "Imprimer" or "Print receipt" button,
      // click it, and capture the surface. If nothing exists, we just capture
      // the current state with the routes installed (effectively a no-trigger
      // baseline for the reviewer).
      const printBtn = page.locator(
        'button:has-text("Imprimer"), button:has-text("Print"), [data-testid*="print"]'
      ).first();
      const printBtnVisible = await printBtn.isVisible({ timeout: 2_500 }).catch(() => false);
      obs.push(`state12: print_btn_visible=${printBtnVisible}`);
      if (printBtnVisible) {
        await printBtn.click({ timeout: 5_000 }).catch(() => {});
        await page.waitForTimeout(2_000);
      } else {
        // No print path available without a completed order — record this.
        obs.push(`state12: no print trigger available — receipt-print path inaccessible without completed order`);
      }
      const printFailState = await page.evaluate(() => {
        const errAlert = document.querySelector('[role="alert"], .alert-danger, .toast-error, .pos-v5-toast.error');
        const text = errAlert?.textContent?.trim()?.substring(0, 200) || null;
        return {
          alert_visible: !!errAlert && errAlert.offsetParent !== null,
          alert_text: text,
        };
      });
      obs.push(`state12: print_route_fired=${printRouteFired} state=${JSON.stringify(printFailState)}`);
      await snap('12-pos-receipt-print-fail');
      await page.unroute('**/print-receipt**').catch(() => {});
      await page.unroute('**/admin/pos/print**').catch(() => {});

      // -------------------------------------------------------------------
      // STATE 13 — pos-double-tap. Rapid double-click on a tile. The Vue
      // variation modal opens on EVERY tile click. Double-click should
      // not double-open / not double-add (modal is single-instance).
      // -------------------------------------------------------------------
      // Ensure cart is empty for clarity — navigate back to POS
      await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(1_500);
      const tileForDbl = page.locator(`button[data-pos-item-id="${POS_DIRECT_ADD.id}"]`).first();
      const dblVisible = await tileForDbl.isVisible({ timeout: 5_000 }).catch(() => false);
      obs.push(`state13: tile_visible=${dblVisible}`);
      if (dblVisible) {
        // RAPID DOUBLE-CLICK — no wait between. The second click may
        // legitimately fail because the variation modal opened by the
        // first click intercepts pointer events — that's a defensive
        // edge-state OBSERVATION, not a spec bug. Wrap in catch so the
        // spec continues to capture. Use force:true on the second to
        // try to click *through* the modal (worst-case test).
        let secondClickError = null;
        await tileForDbl.click({ timeout: 3_000 }).catch((e) => {
          obs.push(`state13: FIRST click threw ${e.message?.substring(0, 200)}`);
        });
        await tileForDbl.click({ timeout: 2_500, force: true }).catch((e) => {
          secondClickError = e.message?.substring(0, 200);
        });
        obs.push(`state13: second_click_error=${secondClickError} (legit edge-state finding if modal intercepted pointer events)`);
        await page.waitForTimeout(1_000);
      }
      const dblState = await page.evaluate(() => {
        const modals = document.querySelectorAll('#item-variation-modal');
        const activeModals = document.querySelectorAll('#item-variation-modal.active');
        const cartLines = document.querySelectorAll('.pos-v5-cart-item').length;
        return {
          modal_count: modals.length,
          active_modal_count: activeModals.length,
          cart_lines: cartLines,
        };
      });
      obs.push(`state13: post_dbl_click=${JSON.stringify(dblState)} (expected: 1 modal, NOT 2)`);
      await snap('13-pos-double-tap');
      // Cleanup modal
      await page.evaluate(() => {
        const m = document.getElementById('item-variation-modal');
        if (m) m.classList.remove('active');
      });
      await page.waitForTimeout(400);

      // -------------------------------------------------------------------
      // STATE 14 — pos-back-from-payment. Get a line, open payment modal,
      // press browser back. Capture whether modal closes + cart preserves.
      // -------------------------------------------------------------------
      // Re-add the direct-add item
      if (await tileForDbl.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await tileForDbl.click({ timeout: 5_000 });
        const modal3 = page.locator('#item-variation-modal');
        await expect(modal3).toHaveClass(/active/, { timeout: 8_000 }).catch(() => {});
        await page.waitForTimeout(800);
        const addBtns = page.locator('#item-variation-modal button').filter({ hasText: /Ajouter au panier|Add to cart/i });
        const total = await addBtns.count();
        for (let i = 0; i < total; i++) {
          if (await addBtns.nth(i).isVisible({ timeout: 1_000 }).catch(() => false)) {
            await addBtns.nth(i).click({ timeout: 5_000 });
            break;
          }
        }
        await expect(modal3).not.toHaveClass(/active/, { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(700);
      }
      const payBtn2 = page.locator('[data-testid="pos-v5-pay"]').first();
      if (await payBtn2.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await payBtn2.click({ timeout: 5_000 });
        await page.waitForTimeout(800);
      }
      const prePaymentLines = await page.locator('.pos-v5-cart-item').count();
      obs.push(`state14: pre_back_payment_lines=${prePaymentLines}`);
      // Browser back
      await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(1_200);
      const postBackPaymentState = await page.evaluate(() => {
        const modal = document.querySelector('#orderpayment');
        const cartLines = document.querySelectorAll('.pos-v5-cart-item').length;
        return {
          modal_active: modal?.classList?.contains('active') || false,
          cart_lines: cartLines,
          url: window.location.pathname,
        };
      });
      obs.push(`state14: post_back=${JSON.stringify(postBackPaymentState)}`);
      await snap('14-pos-back-from-payment');
      // If we got bumped to login, return to POS
      if (!/\/admin\/pos/.test(page.url())) {
        await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.waitForTimeout(1_000);
      }

      // -------------------------------------------------------------------
      // STATE 15 — pos-rapid-qty-spam. With a line in cart, click the
      // qty + button 10× rapidly. Total should reflect correct math (no
      // race / no integer corruption / no NaN).
      // -------------------------------------------------------------------
      // Ensure POS surface
      await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 12_000 });
      await page.waitForTimeout(1_200);
      // If cart empty, re-add
      const linesNow = await page.locator('.pos-v5-cart-item').count();
      if (linesNow === 0) {
        const tile15 = page.locator(`button[data-pos-item-id="${POS_DIRECT_ADD.id}"]`).first();
        if (await tile15.isVisible({ timeout: 5_000 }).catch(() => false)) {
          await tile15.click({ timeout: 5_000 });
          const modal4 = page.locator('#item-variation-modal');
          await expect(modal4).toHaveClass(/active/, { timeout: 8_000 }).catch(() => {});
          await page.waitForTimeout(800);
          const addBtns = page.locator('#item-variation-modal button').filter({ hasText: /Ajouter au panier|Add to cart/i });
          const total = await addBtns.count();
          for (let i = 0; i < total; i++) {
            if (await addBtns.nth(i).isVisible({ timeout: 1_000 }).catch(() => false)) {
              await addBtns.nth(i).click({ timeout: 5_000 });
              break;
            }
          }
          await expect(modal4).not.toHaveClass(/active/, { timeout: 10_000 }).catch(() => {});
          await page.waitForTimeout(800);
        }
      }
      // Read initial state
      const initialQtyState = await page.evaluate(() => {
        const grand = document.querySelector('[data-testid="pos-grand-total"]')?.textContent?.trim() || null;
        const linePrice = document.querySelector('.pos-v5-cart-item__price')?.textContent?.trim() || null;
        const qty = document.querySelector('.pos-v5-cart-item__qty')?.textContent?.trim() || null;
        return { grand, linePrice, qty };
      });
      obs.push(`state15: initial=${JSON.stringify(initialQtyState)}`);
      // Find the + button
      const incBtn = page.locator('.pos-v5-cart-item__qty button').filter({ hasText: /^\+$/ }).first();
      const incVisible = await incBtn.isVisible({ timeout: 3_000 }).catch(() => false);
      obs.push(`state15: inc_btn_visible=${incVisible}`);
      if (incVisible) {
        // RAPID SPAM — 10 clicks with NO wait
        for (let i = 0; i < 10; i++) {
          await incBtn.click({ timeout: 2_000 }).catch(() => {});
        }
        await page.waitForTimeout(1_500); // store settle
      }
      const finalQtyState = await page.evaluate(() => {
        const grand = document.querySelector('[data-testid="pos-grand-total"]')?.textContent?.trim() || null;
        const linePrice = document.querySelector('.pos-v5-cart-item__price')?.textContent?.trim() || null;
        const qty = document.querySelector('.pos-v5-cart-item__qty')?.textContent?.trim() || null;
        const hasNaN = /NaN|undefined/i.test(`${grand}${linePrice}${qty}`);
        return { grand, linePrice, qty, hasNaN };
      });
      obs.push(`state15: final=${JSON.stringify(finalQtyState)}`);
      // Soft-assert no NaN
      expect.soft(finalQtyState.hasNaN, `Qty spam must not produce NaN/undefined`).toBe(false);
      await snap('15-pos-rapid-qty-spam');

      // -------------------------------------------------------------------
      // Sanity : 7 POS PNGs minimum (states 09-15)
      // -------------------------------------------------------------------
      const posWritten = fs.readdirSync(SCREENSHOT_DIR).filter((f) => /^(09|1[0-5])-pos-/.test(f) && f.endsWith('.png'));
      // eslint-disable-next-line no-console
      console.log(`[WAVE-C-POS] obs:\n  ${obs.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-C-POS] pos PNGs written: ${posWritten.length} → ${posWritten.sort().join(', ')}`);
      expect(posWritten.length, `Wave C POS expects 7 PNGs, got ${posWritten.length}`).toBeGreaterThanOrEqual(7);

      allObservations.push(...obs.map((o) => `[POS] ${o}`));
    } finally {
      try { dispose(); } catch (_e) { /* ignore */ }
      await ctx.close().catch(() => {});
    }
  });
});

// ===========================================================================
// AFTER ALL — dump aggregated findings + observations
// ===========================================================================
test.afterAll(async () => {
  // Persist observations + an initial findings stub. The full findings.json
  // is produced by the reasoning step the GStack agent emits separately,
  // but the spec emits enough scaffolding for the adversarial reviewer.
  try {
    fs.writeFileSync(
      path.join(REPORT_DIR, 'wave-C-gstack-observations.json'),
      JSON.stringify({
        wave: 'C',
        round: 1,
        captured_at: new Date().toISOString(),
        observations: allObservations,
      }, null, 2),
    );
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn('[WAVE-C] observations dump failed:', e?.message || e);
  }
});
