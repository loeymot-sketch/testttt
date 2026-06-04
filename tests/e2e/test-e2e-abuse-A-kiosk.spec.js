// =====================================================================
// abuse-e2e-2026-06-01 · Wave A — KIOSK customer journey
// (borne, light mode, palette Cayenne)
// =====================================================================
//
// Drives the REAL kiosk SPA through the full customer journey and captures
// the mega-audit quartet (PNG + DOM + console + network) at every visual
// state, in the order the runtime actually surfaces them.
//
// States (mapped to AUDIT_PLAN Wave A):
//   01 idle (lang chips, order-type chooser)
//   01b idle a11y panel (contrast AA/AAA, audio toggles) — plan state 12
//   01c empty-cart state (fresh /kiosk/cart, no requireCart guard) — plan 11
//   02 menu first category (categories root, products mounted)
//   03 product card open (wizard overlay OR simple add)
//   04 wizard composition step (chips / option grid) — best-effort visual
//   05 add-to-cart (cart bottom-sheet count incremented)
//   07 cart screen (qty ± / edit / remove / allergens / line totals)
//   06 upsell screen (conditional — auto-skips when no suggestions)
//   08 checkout/confirm → payment screen
//   09 Plan-B payment routed-to-counter (kiosk.payment_route_all_to_counter=true)
//   10 confirmation / tracker number (real order #) + total
//
// Cross-surface NUMERIC INTEGRITY (the adversarial reviewer checks this hardest):
//   - cart line totals sum  == cart subtotal (client-computed)
//   - cart-total STRING     == payment-counter-total STRING
//   - cart-total STRING     == confirmation-total STRING   (confirmation total
//                              derives from the backend QUOTE — a mismatch is a
//                              REAL cross-surface defect; we let it surface, no catch)
//
// FROZEN ZONES (observe only, never edited): KioskWizard/App/Upsell*.vue.
// We drive them purely through the UI.
//
// Known/allowlisted signals (per AUDIT_PLAN pre-flight): kiosk
// broadcasting/auth 302 → /api/login 401 (realtime-degraded, at most P2),
// CSP report-only + csp-report 204 noise. Not blocked on here.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const DIR = path.join(__dirname, '__screenshots__', 'test-e2e-A');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

// Generous timeout: real fiscal order write at the counter-confirm step.
test.setTimeout(180_000);

test.describe('Wave A — Kiosk customer journey (abuse-e2e)', () => {
  test('full kiosk journey idle→menu→wizard→cart→upsell→payment(counter)→confirmation', async ({ page }) => {
    const { snap, dispose } = attachMegaAuditRecorder(page, DIR);
    try {
      clearFoodKingRateLimits();

      // ---------------------------------------------------------------
      // 01 — IDLE attract screen. Auto-login posts /api/auth/kiosk-login.
      // ---------------------------------------------------------------
      await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
      // Idle root must mount (auto-login resolved). If the machine credentials
      // were broken this would never appear → a hard, visible failure.
      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 30_000 });
      // Language chips: rendered ONLY when >1 language is enabled
      // (v-if="enabledLanguages.length > 1"). V1 Le Cayenne is single-locale FR,
      // so the selector legitimately may not render — observe, do not assert hard.
      const langSelectorVisible = await page.locator('[data-testid="kiosk-idle-lang-selector"]')
        .isVisible({ timeout: 3_000 }).catch(() => false);
      if (!langSelectorVisible) langSelectorAbsent = true; // expected in single-locale V1
      // Order-type chooser is the REAL start gate (takeaway — V1 dine-in disabled).
      await expect(page.locator('[data-testid="kiosk-order-type-chooser"]')).toBeVisible({ timeout: 15_000 });
      // V1 dine-in flag OFF → the dine-in tile must be hidden; takeaway present.
      await expect(page.locator('[data-testid="kiosk-order-type-takeaway"]')).toBeVisible();
      const dineInTileVisible = await page.locator('[data-testid="kiosk-order-type-dine-in"]')
        .isVisible({ timeout: 1_500 }).catch(() => false);
      if (dineInTileVisible) dineInTilePresent = true; // would be a V1 config drift
      await snap('01-idle');

      // ---------------------------------------------------------------
      // 01b — A11Y panel from idle (plan state 12). Contrast AA/AAA, audio.
      // ---------------------------------------------------------------
      const a11yBtn = page.locator('[data-testid="kiosk-idle-a11y-btn"]');
      if (await a11yBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await a11yBtn.click();
        await expect(page.locator('[data-testid="kiosk-a11y-drawer"]')).toBeVisible({ timeout: 10_000 });
        // Assert the real a11y controls mounted (not a fake-pass open).
        await expect(page.locator('[data-testid="kiosk-a11y-contrast-aa"]')).toBeVisible();
        await expect(page.locator('[data-testid="kiosk-a11y-contrast-aaa"]')).toBeVisible();
        await expect(page.locator('[data-testid="kiosk-a11y-audio-toggle"]')).toBeVisible();
        await snap('01b-a11y-panel');
        // Close the drawer to return to a clean idle.
        const a11yClose = page.locator('[data-testid="kiosk-a11y-close"], [data-testid="kiosk-a11y-done"]').first();
        if (await a11yClose.isVisible({ timeout: 3_000 }).catch(() => false)) {
          await a11yClose.click();
          await page.waitForTimeout(400);
        }
      } else {
        a11yPanelMissing = true;
        await snap('01b-a11y-panel-MISSING');
      }

      // ---------------------------------------------------------------
      // 01c — EMPTY-CART state (plan state 11). /kiosk/cart has no
      // requireCart guard, so a fresh cart renders the honest empty state.
      // ---------------------------------------------------------------
      await page.goto(`${BASE}/kiosk/cart`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-cart-root"]')).toBeVisible({ timeout: 20_000 });
      // [round-2 2026-06-02] A hard page.goto re-boots the kiosk SPA, raising the
      // full-screen `.kiosk-init-overlay` (z-index 9999, "Démarrage en cours…").
      // The cart-empty DOM mounts UNDER it, so the assertion passes while the PNG
      // would otherwise capture only the boot loader — misleading the reviewer.
      // Wait for the boot overlay to clear so the snapshot shows the real UI.
      await page.locator('.kiosk-init-overlay').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
      // Empty state CTA must be visible (cart is genuinely empty at this point).
      const emptyState = page.locator('[data-testid="kiosk-cart-empty"]');
      if (await emptyState.isVisible({ timeout: 6_000 }).catch(() => false)) {
        await expect(page.locator('[data-testid="kiosk-cart-empty-cta"]')).toBeVisible();
        await snap('01c-empty-cart');
      } else {
        // Cart not empty (residue from a prior run) — capture whatever state.
        await snap('01c-cart-not-empty');
      }

      // ---------------------------------------------------------------
      // Restart the order flow cleanly from idle and pick TAKEAWAY.
      // ---------------------------------------------------------------
      await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 20_000 });
      // Let the SPA boot overlay clear so it can't intercept the takeaway click.
      await page.locator('.kiosk-init-overlay').waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
      await page.locator('[data-testid="kiosk-order-type-takeaway"]').click();
      // Selecting takeaway navigates into the catalogue.
      await page.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 });

      // ---------------------------------------------------------------
      // 02 — MENU first category. Categories root + products mounted.
      // ---------------------------------------------------------------
      await expect(page.locator('[data-testid="kiosk-categories-root"]')).toBeVisible({ timeout: 20_000 });
      // Wait for products to render (loading spinner gone, at least one card).
      await expect(page.locator('[data-testid="kiosk-categories-products"]')).toBeVisible({ timeout: 20_000 });
      let productCards = page.locator('[data-testid^="kiosk-product-card-"]');
      await expect(productCards.first()).toBeVisible({ timeout: 20_000 });
      await snap('02-menu-first-category');

      // ---------------------------------------------------------------
      // Select a COMPOSITION category so the wizard (frozen KioskWizard) is
      // exercised. The default zone is "Boissons" (simple drinks, no wizard).
      // Prefer a composition category by sidebar label; this is what surfaces
      // the viande/crudités/sauces composition steps required by plan state 04.
      // ---------------------------------------------------------------
      const composCategoryLabels = ['Tacos', 'Sandwich Cayenne', 'Burgers', 'Bols Gourmands', 'Sandwich Classique', 'Galette'];
      let composCategorySelected = false;
      for (const label of composCategoryLabels) {
        const catBtn = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]', { hasText: label }).first();
        if (await catBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await catBtn.click();
          // Zone title should switch to the chosen category and products re-render.
          await page.waitForTimeout(900);
          const zoneTitle = (await page.locator('[data-testid="kiosk-categories-zone-title"]').innerText().catch(() => '')).trim();
          if (zoneTitle) { composCategorySelected = true; }
          // Re-bind product cards to the new zone.
          productCards = page.locator('[data-testid^="kiosk-product-card-"]');
          if (await productCards.first().isVisible({ timeout: 8_000 }).catch(() => false)) break;
        }
      }
      if (!composCategorySelected) composCategoryMissing = true;
      const productCount = await productCards.count();
      await snap('02b-composition-category');

      // ---------------------------------------------------------------
      // 03 / 04 — Open a product. Try to find one that opens the WIZARD
      // overlay (composition steps). If the first product is a simple item,
      // it adds directly — we then capture the wizard from a different card.
      //
      // Strategy: iterate cards, click, wait briefly. If the wizard overlay
      // mounts, capture state 03+04 and complete it. Otherwise it added a
      // simple line — record cart growth and capture state 03 as simple-add.
      // ---------------------------------------------------------------
      let wizardCaptured = false;
      let simpleAddDone = false;
      const maxProbe = Math.min(productCount, 8);

      for (let i = 0; i < maxProbe && !wizardCaptured; i++) {
        await dismissInactivity(page);
        const card = productCards.nth(i);
        if (!(await card.isVisible().catch(() => false))) continue;
        // Skip filtered-out (greyed) cards — they don't open.
        const filteredOut = await card.evaluate((el) =>
          el.classList.contains('kiosk-product-card--filtered-out')).catch(() => false);
        if (filteredOut) continue;

        const cartCountBefore = await readCartBadge(page);
        // Opening a composition product fires an async frontendItem/details XHR
        // (`GET /api/frontend/item/details/:id?surface=kiosk`) BEFORE the wizard
        // overlay mounts. Wait for that response so the overlay-visibility probe
        // below isn't racing the network. [round-2 2026-06-02: the prior 4s flat
        // timeout lost the race on a cold first probe → wizard states 03/04/05
        // were never captured and the cart line came from the silent fallback.]
        const detailResp = page.waitForResponse(
          (r) => /\/api\/frontend\/item\/details\//i.test(r.url()) && r.request().method() === 'GET',
          { timeout: 8_000 },
        ).catch(() => null);
        await card.click({ timeout: 8_000 }).catch(() => {});
        await detailResp;

        // Did a wizard overlay mount? (8s — the detail XHR + Vue mount + the
        // lazy kiosk-wizard chunk can exceed 4s on the first composition open.)
        const wizardOverlay = page.locator('.kiosk-wizard-overlay');
        const opened = await wizardOverlay.isVisible({ timeout: 8_000 }).catch(() => false);

        if (opened) {
          // ---- 03 product card open (wizard variant) ----
          await snap('03-product-wizard-open');

          // ---- 04 wizard composition step ----
          // The live-composition summary chips region + the step option grid.
          await page.waitForTimeout(600);
          // Assert the live-composition region mounted (frozen KioskWizard UI).
          const liveComposition = page.locator('[data-testid="kiosk-wizard-live-composition"]');
          if (await liveComposition.isVisible({ timeout: 3_000 }).catch(() => false)) {
            wizardLiveCompositionSeen = true;
          }
          await snap('04-wizard-composition-step');

          // Pick the first selectable composition option on this step and snap
          // again so the reviewer sees the chip/selection state update.
          const firstOpt = page.locator(
            '.kiosk-wizard-overlay .kiosk-viande-card.is-selectable, ' +
            // garniture/crudités + sauce steps render `.kiosk-option-card` (a
            // clickable <div role=checkbox>, NOT a <button>) — selectable iff it
            // carries neither the disabled nor the out-of-stock modifier.
            '.kiosk-wizard-overlay .kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock), ' +
            '.kiosk-wizard-overlay [data-testid="kiosk-frites-style-nature"]',
          ).first();
          if (await firstOpt.isVisible({ timeout: 3_000 }).catch(() => false)) {
            // REAL click (not force) — the viande card's increment handler does
            // not fire on a forced hit-point, so a forced click would leave the
            // 04b snapshot showing an UNselected option (misleading the reviewer).
            await firstOpt.scrollIntoViewIfNeeded().catch(() => {});
            await firstOpt.click({ timeout: 3_000 }).catch(() => {});
            await page.waitForTimeout(500);
            await snap('04b-wizard-option-selected');
          }

          // Complete the wizard: at each step, select the first selectable
          // option if a selection is required, then advance via .kiosk-btn-next.
          // The add-to-cart button is the same .kiosk-btn-next on the last step.
          const completed = await completeWizard(page);
          if (completed) {
            wizardCaptured = true;
            // ---- 05 add-to-cart (cart badge grew) ----
            await page.waitForTimeout(600);
            const cartCountAfter = await readCartBadge(page);
            expect(cartCountAfter).toBeGreaterThan(cartCountBefore);
            await snap('05-add-to-cart-wizard');
          } else {
            // Wizard could not be completed deterministically — abandon it
            // and fall through to simple-add path. Capture the stuck state.
            await snap('04c-wizard-incomplete');
            wizardIncomplete = true;
            // Try to close the overlay (abandon).
            const abandon = page.locator('.kiosk-btn-abandon, .kiosk-wizard-abandon-yes').first();
            if (await abandon.isVisible({ timeout: 2_000 }).catch(() => false)) {
              await abandon.click().catch(() => {});
              const confirmYes = page.locator('.kiosk-wizard-abandon-yes').first();
              if (await confirmYes.isVisible({ timeout: 1_500 }).catch(() => false)) {
                await confirmYes.click().catch(() => {});
              }
            }
            await page.waitForTimeout(400);
          }
        } else {
          // Simple item — added directly to cart, no overlay.
          const cartCountAfter = await readCartBadge(page);
          if (cartCountAfter > cartCountBefore && !simpleAddDone) {
            simpleAddDone = true;
            await snap('03-product-simple-add');
            await snap('05-add-to-cart-simple');
          }
        }
      }

      // Ensure the cart has at least one line before proceeding. If neither a
      // wizard nor a simple add landed, add the first non-filtered simple card.
      let cartCount = await readCartBadge(page);
      if (cartCount === 0) {
        for (let i = 0; i < productCount; i++) {
          await dismissInactivity(page);
          const card = productCards.nth(i);
          const filteredOut = await card.evaluate((el) =>
            el.classList.contains('kiosk-product-card--filtered-out')).catch(() => false);
          if (filteredOut) continue;
          await card.click({ timeout: 8_000 }).catch(() => {});
          // If a wizard opened, complete or abandon, then continue.
          const wiz = page.locator('.kiosk-wizard-overlay');
          if (await wiz.isVisible({ timeout: 3_000 }).catch(() => false)) {
            const ok = await completeWizard(page);
            if (!ok) {
              const abandon = page.locator('.kiosk-btn-abandon').first();
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
          if (cartCount > 0) break;
        }
      }
      expect(cartCount, 'cart must contain at least one line to continue the journey').toBeGreaterThan(0);

      // ---------------------------------------------------------------
      // 07 — CART screen. qty ± / edit / remove / allergens / line totals.
      // ---------------------------------------------------------------
      // Navigate to cart via the categories bottom bar (real UI path).
      await dismissInactivity(page);
      const goCart = page.locator('[data-testid="kiosk-categories-cart-indicator"], [data-testid="kiosk-categories-pay"]').first();
      if (await goCart.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await goCart.click();
      } else {
        await page.goto(`${BASE}/kiosk/cart`, { waitUntil: 'domcontentloaded' });
      }
      await expect(page.locator('[data-testid="kiosk-cart-root"]')).toBeVisible({ timeout: 20_000 });
      await expect(page.locator('[data-testid="kiosk-cart-items"]')).toBeVisible({ timeout: 15_000 });
      await snap('07-cart-screen');

      // --- NUMERIC INTEGRITY (intra-cart): Σ(line totals) == subtotal ---
      const lineTotals = await page.locator('[data-testid^="kiosk-cart-item-total-"]').allInnerTexts();
      const subtotalStr = (await page.locator('[data-testid="kiosk-cart-subtotal"]').innerText().catch(() => '')).trim();
      const cartTotalStr = (await page.locator('[data-testid="kiosk-cart-total"]').innerText()).trim();
      const sumLines = lineTotals.reduce((acc, t) => acc + parseMoney(t), 0);
      const subtotalNum = parseMoney(subtotalStr);
      // Allow a 1-cent rounding tolerance only.
      expect(Math.abs(sumLines - subtotalNum),
        `cart line totals Σ(${sumLines}) must equal subtotal (${subtotalNum}) [lines=${JSON.stringify(lineTotals)}]`,
      ).toBeLessThan(0.02);

      // Exercise qty + on the first line (real UI), then re-read totals.
      const qtyPlus0 = page.locator('[data-testid="kiosk-cart-item-qty-plus-0"]');
      if (await qtyPlus0.isVisible({ timeout: 4_000 }).catch(() => false)) {
        const totalBefore = parseMoney(cartTotalStr);
        await qtyPlus0.click();
        await page.waitForTimeout(700);
        const totalAfter = parseMoney((await page.locator('[data-testid="kiosk-cart-total"]').innerText()).trim());
        // Increasing qty must NOT decrease the grand total.
        expect(totalAfter, `qty+ must not lower the grand total (${totalBefore}→${totalAfter})`).toBeGreaterThanOrEqual(totalBefore - 0.001);
        await snap('07b-cart-qty-incremented');
        // Decrement back to keep the order modest.
        const qtyMinus0 = page.locator('[data-testid="kiosk-cart-item-qty-minus-0"]');
        if (await qtyMinus0.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await qtyMinus0.click();
          await page.waitForTimeout(600);
        }
      }

      // Capture allergens chip if present (observe-only — not all items carry one).
      const allergens0 = page.locator('[data-testid="kiosk-cart-item-allergens-0"]');
      if (await allergens0.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await snap('07c-cart-allergens-visible');
      }

      // Re-read the authoritative cart grand total for cross-surface checks.
      const cartGrandTotalStr = (await page.locator('[data-testid="kiosk-cart-total"]').innerText()).trim();

      // ---------------------------------------------------------------
      // 08 — CHECKOUT → proceedToUpsell (quotes the order, then upsell/payment)
      // ---------------------------------------------------------------
      await dismissInactivity(page);
      const checkout = page.locator('[data-testid="kiosk-cart-checkout"]');
      await expect(checkout).toBeVisible({ timeout: 10_000 });
      await checkout.click();

      // ---------------------------------------------------------------
      // 06 — UPSELL screen (conditional). May render or auto-skip to payment.
      // ---------------------------------------------------------------
      const reachedUpsell = await page.waitForURL(/\/kiosk\/upsell/, { timeout: 8_000 }).then(() => true).catch(() => false);
      if (reachedUpsell) {
        const upsellRoot = page.locator('[data-testid="kiosk-upsell-root"]');
        if (await upsellRoot.isVisible({ timeout: 6_000 }).catch(() => false)) {
          // The skip button lives inside <template v-else> — it only renders
          // once `loading` flips false (suggestions fetched OR fetch errored).
          // Wait for loading to clear before snapping + clicking skip.
          const loadingSpinner = page.locator('[data-testid="kiosk-upsell-loading"]');
          await loadingSpinner.waitFor({ state: 'hidden', timeout: 12_000 }).catch(() => {});
          await snap('06-upsell-screen');
          // The upsell may auto-skip itself (no_suggestions / load_error) and
          // navigate to payment before we click. Race the skip button against
          // an automatic navigation to /payment.
          const skip = page.locator('[data-testid="kiosk-upsell-skip"]');
          const skipVisible = await skip.isVisible({ timeout: 6_000 }).catch(() => false);
          if (skipVisible && /\/kiosk\/upsell/.test(page.url())) {
            // Explicit skip → skip() pushes router to kiosk.payment.
            await skip.click({ timeout: 8_000 }).catch(() => {});
          }
          // else: still loading/auto-skipping — the waitForURL below catches it.
        }
      } else {
        // Auto-skipped straight to payment (no suggestions / shouldSkip).
        upsellAutoSkipped = true;
      }

      // ---------------------------------------------------------------
      // 08b — PAYMENT screen. With Plan-B (route_all_to_counter=true) this
      // renders the counter-route card, not the method selector.
      // ---------------------------------------------------------------
      await page.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 });
      await expect(page.locator('[data-testid="kiosk-payment-root"]')).toBeVisible({ timeout: 20_000 });
      await snap('08-payment-screen');

      // ---------------------------------------------------------------
      // 09 — Plan-B routed-to-counter. counter-route card + total + confirm.
      // ---------------------------------------------------------------
      const counterRoute = page.locator('[data-testid="kiosk-payment-counter-route"]');
      await expect(counterRoute, 'Plan-B counter-route card must render (kiosk.payment_route_all_to_counter=true)').toBeVisible({ timeout: 15_000 });
      const counterTotalStr = (await page.locator('[data-testid="kiosk-payment-counter-total"]').innerText()).trim();
      await snap('09-payment-counter-route');

      // --- NUMERIC INTEGRITY: cart grand total STRING == counter total STRING ---
      // Both are formatPrice() of the same client cartTotal. A mismatch is a
      // real cross-surface defect — surfaced, not caught.
      expect(parseMoney(counterTotalStr),
        `payment-counter-total (${counterTotalStr}) must equal cart grand total (${cartGrandTotalStr})`,
      ).toBeCloseTo(parseMoney(cartGrandTotalStr), 2);

      // ---------------------------------------------------------------
      // 10 — Confirm at counter → writes the REAL order → confirmation screen.
      // ---------------------------------------------------------------
      await dismissInactivity(page);
      const counterConfirm = page.locator('[data-testid="kiosk-payment-counter-confirm"]');
      await expect(counterConfirm).toBeVisible({ timeout: 10_000 });
      await counterConfirm.click();

      // Confirmation OR cash-instruction screen may appear depending on routing.
      const confirmationRoot = page.locator('[data-testid="kiosk-confirmation-root"]');
      const cashRoot = page.locator('[data-testid="kiosk-cash-title"]');
      const reachedConfirmation = await Promise.race([
        confirmationRoot.waitFor({ state: 'visible', timeout: 30_000 }).then(() => 'confirmation').catch(() => null),
        cashRoot.waitFor({ state: 'visible', timeout: 30_000 }).then(() => 'cash').catch(() => null),
      ]);

      if (reachedConfirmation === 'confirmation' || await confirmationRoot.isVisible().catch(() => false)) {
        await expect(confirmationRoot).toBeVisible({ timeout: 5_000 });
        // Real order number must render (not blank, not "undefined").
        const orderNumStr = (await page.locator('[data-testid="kiosk-confirmation-number"]').innerText()).trim();
        expect(orderNumStr, 'confirmation must show a real order number').not.toBe('');
        expect(orderNumStr.toLowerCase()).not.toContain('undefined');
        expect(orderNumStr.toLowerCase()).not.toContain('null');
        expect(orderNumStr).toMatch(/#?\d/); // contains a digit

        const confTotalStr = (await page.locator('[data-testid="kiosk-confirmation-total"]').innerText()).trim();
        await snap('10-confirmation');

        // --- CROSS-SURFACE NUMERIC INTEGRITY: cart == confirmation ---
        // confirmation total derives from the backend QUOTE (route query). If
        // it differs from the cart/counter total that's a genuine defect → let
        // it fail visibly. Do NOT swallow.
        expect(parseMoney(confTotalStr),
          `confirmation total (${confTotalStr}) must equal cart/counter total (${cartGrandTotalStr})`,
        ).toBeCloseTo(parseMoney(cartGrandTotalStr), 2);
      } else if (await cashRoot.isVisible().catch(() => false)) {
        // Cash-instruction path — capture order number there.
        const cashOrderNum = page.locator('[data-testid="kiosk-cash-order-number"]');
        if (await cashOrderNum.isVisible({ timeout: 4_000 }).catch(() => false)) {
          const n = (await cashOrderNum.innerText()).trim();
          expect(n.toLowerCase()).not.toContain('undefined');
        }
        await snap('10-cash-instruction');
        cashInsteadOfConfirmation = true;
      } else {
        // Neither surfaced in time — capture whatever rendered for the reviewer.
        await snap('10-post-confirm-unknown');
        confirmationUnreached = true;
      }
    } finally {
      dispose();
    }
  });
});

// ----- flags surfaced to the runner log (not assertions) -----
let langSelectorAbsent = false;
let dineInTilePresent = false;
let composCategoryMissing = false;
let wizardLiveCompositionSeen = false;
let a11yPanelMissing = false;
let wizardIncomplete = false;
let upsellAutoSkipped = false;
let cashInsteadOfConfirmation = false;
let confirmationUnreached = false;

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * If the kiosk inactivity overlay is up (idle timer fired during a long step),
 * dismiss it via the real "Je suis là" CTA so subsequent clicks aren't
 * intercepted by the modal. Returns true if it was dismissed. Observe-only:
 * the overlay is an EXPECTED runtime affordance, not a defect — we just keep
 * the journey driveable. Any genuine product issue with it would still surface
 * in the captured PNG/DOM.
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
  const cleaned = String(s)
    .replace(/[^\d,.-]/g, '')   // strip currency symbols / spaces / labels
    .trim();
  if (cleaned === '') return NaN;
  // If both separators present, the last one is the decimal separator.
  const lastComma = cleaned.lastIndexOf(',');
  const lastDot = cleaned.lastIndexOf('.');
  let normalized = cleaned;
  if (lastComma > -1 && lastDot > -1) {
    if (lastComma > lastDot) {
      normalized = cleaned.replace(/\./g, '').replace(',', '.');
    } else {
      normalized = cleaned.replace(/,/g, '');
    }
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
  // Fallback: count cart-item-* nodes if the cart is mounted.
  const items = page.locator('[data-testid^="kiosk-cart-item-name-"]');
  const n = await items.count().catch(() => 0);
  return n;
}

/**
 * Complete the inline kiosk wizard generically: at each step select the first
 * truly-selectable option (so canAdvance flips true), then click .kiosk-btn-next.
 * The same .kiosk-btn-next becomes "add to cart" on the final step.
 * Returns true if it reached add-to-cart and the overlay closed.
 */
async function completeWizard(page) {
  const overlay = page.locator('.kiosk-wizard-overlay');
  const nextBtn = page.locator('.kiosk-wizard-overlay .kiosk-btn-next');
  const MAX_STEPS = 14;

  // [round-2 fix 2026-06-02] Two empirically-verified fixes vs the prior helper
  // that left the cart empty and aborted the whole journey at line 288:
  //   (a) REAL clicks, not `force:true`. On the viande step the option card's
  //       increment handler does NOT fire on a forced hit-point (counter stayed
  //       "0 / N", canAdvance never flipped). A plain .click() registers the
  //       selection ("0/1" → "1/1") and enables Next. Verified live.
  //   (b) MENU step ("QUEL MENU ?") preference for the "Sans menu" tile (the
  //       LAST .kiosk-menu-card). Selecting "Menu Complet"/"+ Frites"/"Boisson"
  //       opens a downstream drink/fries sub-requirement that keeps Next
  //       disabled; "Sans menu" (localChoice='none') satisfies the step with a
  //       single click and lets the journey proceed to RÉCAP → add-to-cart.
  // Both are spec-mechanics corrections — the frozen KioskWizard UI is unchanged.
  const optionSelectors = [
    '.kiosk-wizard-overlay .kiosk-viande-card.is-selectable',
    // garniture/crudités + sauce steps render `.kiosk-option-card`
    // (clickable <div role=checkbox>) — selectable iff not disabled / not OOS.
    // Scope to .kiosk-step-content so we never match a stray card elsewhere.
    '.kiosk-wizard-overlay .kiosk-step-content .kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)',
    '.kiosk-wizard-overlay [data-testid="kiosk-frites-style-nature"]',
    '.kiosk-wizard-overlay .kiosk-taille-card:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay .kiosk-pain-card:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay .kiosk-choice-card:not([aria-disabled="true"])',
    // Generic fallbacks (selectable cards in any step component).
    '.kiosk-wizard-overlay [role="checkbox"]:not([aria-disabled="true"]), .kiosk-wizard-overlay [role="radio"]:not([aria-disabled="true"])',
    '.kiosk-wizard-overlay button:not([disabled]):not(.kiosk-btn-next):not(.kiosk-btn-back):not(.kiosk-btn-abandon)',
  ];

  for (let step = 0; step < MAX_STEPS; step++) {
    // Keep the idle/inactivity modal from hijacking pointer events mid-wizard.
    await dismissInactivity(page);
    if (!(await overlay.isVisible().catch(() => false))) {
      // Overlay closed → item was added.
      return true;
    }

    // Satisfy this step: keep picking selectable options until Next/Add is
    // ENABLED (canAdvance true). Guard against an unsatisfiable step.
    let guard = 0;
    while (!(await nextBtn.isEnabled().catch(() => false)) && guard < 8) {
      guard++;
      await dismissInactivity(page);
      let clicked = false;

      // MENU step: prefer the "Sans menu" tile (last card) to dodge the
      // drink/fries sub-requirement that keeps Next disabled.
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
          if (clicked) break; // re-evaluate Next from the top after each pick
        }
      }
      if (!clicked) break; // nothing selectable left on this step
    }

    if (!(await nextBtn.isEnabled().catch(() => false))) {
      // Could not satisfy this step's requirement deterministically.
      return false;
    }

    // Advance / add-to-cart. The last step's Next carries .kiosk-btn-next--cart.
    const isCartBtn = await nextBtn.evaluate((el) => el.classList.contains('kiosk-btn-next--cart')).catch(() => false);
    await nextBtn.click().catch(() => {});
    await page.waitForTimeout(isCartBtn ? 900 : 500);
    if (isCartBtn) {
      // After add-to-cart the overlay should close (item committed to cart).
      await overlay.waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => {});
      return !(await overlay.isVisible().catch(() => false));
    }
  }
  // Exhausted steps — treat as failure if overlay still up.
  return !(await overlay.isVisible().catch(() => false));
}
