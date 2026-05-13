// FoodKing E2E — visual-end-to-end Wave A (Kiosk home→payment) — 2026-05-11
//
// Plan: reports/test-e2e/visual-end-to-end-2026-05-11/AUDIT_PLAN.md (Wave A).
// Mission: capture EVERY visual state from kiosk idle to payment confirmation
// and return-to-idle. 24 states × 4-file quartet (PNG / DOM / console / network)
// = 96 artifacts. Pure visual capture — does NOT mutate product code.
//
// Tier 1 GStack — captures + reasons across Architect / Tester / A11y / SRE / DBA.
// Output: round-1/wave-A-gstack-findings.json + wave-A-gstack-observations.json.
//
// Frozen-zone discipline: kiosk Vue wizard is auditable (capture allowed) but
// NOT modified here. POS Vanilla wizard NOT touched (Wave B territory).

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsKiosk, cleanupOrphanTestOrders } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-wave-A-kiosk');
const REPORTS_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/visual-end-to-end-2026-05-11/round-1',
);

const TOKEN_PREFIX = 'AUDIT-VISUAL-A-KIOSK';
const TS = Date.now();

// Items (verified live 2026-05-10 by Wave B rush-hour spec).
const ITEM_TACOS = { id: 363, name: 'Tacos M (1 Viande)', price: 6.50 };
const ITEM_FRITES = { id: 361, name: 'Frites Seules', price: 2.00 };

// Hard wallclock cap — owner budget = ~9 min capture + slack.
const HARD_CAP_MS = 15 * 60 * 1000;

const PALETTE = {
  orange: '#F4501E',
  black: '#000000',
  yellow: '#FFD400',
  white: '#FFFFFF',
};

test.describe('visual-end-to-end wave-A — Kiosk home→payment 24 states', () => {
  test.setTimeout(HARD_CAP_MS);

  test.beforeAll(() => {
    // Scope cleanup to our prefix only — never sweep concurrent waves' rows.
    cleanupOrphanTestOrders([TOKEN_PREFIX, 'AUDIT-KIOSK-WAVE-E-']);
    clearFoodKingRateLimits();
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    fs.mkdirSync(REPORTS_DIR, { recursive: true });
  });

  test('Wave A: full kiosk visual flow with 24 states', async ({ browser }) => {
    const startMs = Date.now();
    const observations = []; // per-state notes (state name, anomaly, palette obs)
    const findings = []; // GStack findings to emit at end
    const stateArtifacts = []; // ['01-idle-fresh', ...]

    // Kiosk portrait viewport per AUDIT_PLAN.md.
    const kioskCtx = await browser.newContext({
      viewport: { width: 1080, height: 1920 },
    });
    const kioskPage = await kioskCtx.newPage();
    const rec = attachMegaAuditRecorder(kioskPage, SCREENSHOT_DIR);

    // Wrap snap to record artifact list + tolerate empty-state captures.
    async function snap(name, marker = null) {
      let markerPresent = null;
      if (marker) {
        try {
          markerPresent = await kioskPage.locator(marker).first().isVisible({ timeout: 400 }).catch(() => false);
        } catch (_e) { markerPresent = false; }
      }
      try {
        await rec.snap(name);
        stateArtifacts.push(name);
        observations.push({
          state: name,
          marker_selector: marker,
          marker_present: markerPresent,
          url_at_snap: kioskPage.url(),
          ts: new Date().toISOString(),
        });
      } catch (err) {
        observations.push({
          state: name,
          snap_error: String(err?.message || err).slice(0, 300),
          url_at_snap: kioskPage.url(),
          ts: new Date().toISOString(),
        });
      }
    }

    try {
      // -----------------------------------------------------------------
      // AUTH — Kiosk login. Auto-redirect from /kiosk/login to /kiosk/idle
      // expected; otherwise loginAsKiosk handles manual form.
      // -----------------------------------------------------------------
      await loginAsKiosk(kioskPage);
      if (!/\/kiosk(\/|$|\?)/.test(kioskPage.url())) {
        await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
      await kioskPage.waitForTimeout(1500);
      observations.push({
        state: '__auth',
        url_at_snap: kioskPage.url(),
        note: 'kiosk authenticated',
      });

      // ============================================================
      // STATE 01 — idle fresh landing
      // ============================================================
      await snap('01-idle-fresh', '[data-testid="kiosk-idle-root"]');

      // ============================================================
      // STATE 02 — idle FR locale (default).
      // ============================================================
      await kioskPage.waitForTimeout(400);
      const frBtn = kioskPage.getByTestId('kiosk-idle-lang-fr');
      const frVisible = await frBtn.isVisible({ timeout: 1500 }).catch(() => false);
      if (frVisible) {
        await frBtn.click({ timeout: 2000 }).catch(() => {});
        await kioskPage.waitForTimeout(400);
      }
      await snap('02-idle-lang-fr', '[data-testid="kiosk-idle-lang-fr"]');

      // ============================================================
      // STATE 03 — idle EN locale (switch + capture).
      // ============================================================
      const enBtn = kioskPage.getByTestId('kiosk-idle-lang-en');
      const enVisible = await enBtn.isVisible({ timeout: 1500 }).catch(() => false);
      if (enVisible) {
        await enBtn.click({ timeout: 2000 }).catch(() => {});
        await kioskPage.waitForTimeout(700);
      } else {
        observations.push({
          state: '03-idle-lang-en',
          note: 'kiosk-idle-lang-en testid not visible — EN selector may be absent or already FR-only',
        });
      }
      await snap('03-idle-lang-en', '[data-testid="kiosk-idle-lang-en"]');

      // Switch back to FR for the rest of the flow (more reliable selectors).
      if (frVisible) {
        await frBtn.click({ timeout: 1500 }).catch(() => {});
        await kioskPage.waitForTimeout(500);
      }

      // ============================================================
      // STATE 04 — order-type chooser. V1 dine-in disabled → takeaway only
      // visible (or dine-in disabled state visible).
      // ============================================================
      const chooserVisible = await kioskPage.getByTestId('kiosk-order-type-chooser')
        .isVisible({ timeout: 3000 }).catch(() => false);
      if (!chooserVisible) {
        // Touch idle to advance to chooser.
        const idleBtn = kioskPage.getByTestId('kiosk-idle-touch-btn');
        if (await idleBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await idleBtn.click({ timeout: 2500 }).catch(() => {});
          await kioskPage.waitForTimeout(800);
        }
      }
      await snap('04-order-type', '[data-testid="kiosk-order-type-chooser"]');

      // Click takeaway.
      const takeawayBtn = kioskPage.getByTestId('kiosk-order-type-takeaway');
      if (await takeawayBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await takeawayBtn.click({ timeout: 3000 }).catch(() => {});
        observations.push({ state: '04-order-type', note: 'takeaway clicked' });
      }

      // Wait for categories.
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-categories-root"]')
        || document.querySelector('[data-testid="kiosk-categories-products"]')
      ), null, { timeout: 12_000 }).catch(() => {});
      await kioskPage.waitForTimeout(800);

      // ============================================================
      // STATE 05 — default categories grid + sidebar.
      // ============================================================
      await snap('05-categories', '[data-testid="kiosk-categories-root"]');

      // ============================================================
      // STATE 06 — switch to a second category in the sidebar.
      // ============================================================
      const sidebarItems = kioskPage.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
      const sidebarCount = await sidebarItems.count();
      if (sidebarCount >= 2) {
        await sidebarItems.nth(1).click({ timeout: 3000 }).catch(() => {});
        await kioskPage.waitForTimeout(700);
      } else {
        observations.push({
          state: '06-cat-switch',
          note: `only ${sidebarCount} sidebar items — second category not selectable`,
        });
      }
      await snap('06-cat-switch', '[data-testid="kiosk-categories-products"]');

      // Navigate to Tacos M's category (306).
      await kioskPage.goto('/kiosk/categories?cat=306', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(1200);
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-categories-products"]')
      ), null, { timeout: 8000 }).catch(() => {});

      // ============================================================
      // STATE 07 — Tacos M tile focused/hovered before tap.
      // ============================================================
      const tacosCard = kioskPage.getByTestId(`kiosk-product-card-${ITEM_TACOS.id}`);
      const tacosVisible = await tacosCard.isVisible({ timeout: 6000 }).catch(() => false);
      if (tacosVisible) {
        await tacosCard.scrollIntoViewIfNeeded().catch(() => {});
        await tacosCard.hover({ timeout: 2000 }).catch(() => {});
      } else {
        observations.push({
          state: '07-tacos-focused',
          note: `Tacos M card (id ${ITEM_TACOS.id}) not visible on /kiosk/categories?cat=306`,
        });
      }
      await snap('07-tacos-focused', `[data-testid="kiosk-product-card-${ITEM_TACOS.id}"]`);

      // Open wizard.
      const tacosAdd = kioskPage.getByTestId(`kiosk-product-add-${ITEM_TACOS.id}`);
      if (await tacosAdd.isVisible({ timeout: 1500 }).catch(() => false)) {
        await tacosAdd.click({ timeout: 3500 }).catch(() => {});
      } else if (tacosVisible) {
        await tacosCard.click({ timeout: 3500 }).catch(() => {});
      }
      await kioskPage.waitForFunction(() => (
        document.querySelector('.kiosk-wizard')
        || document.querySelector('[data-testid="kiosk-order-summary-root"]')
      ), null, { timeout: 8000 }).catch(() => {});
      await kioskPage.waitForTimeout(800);

      // ============================================================
      // STATE 08 — wizard opens, viande step empty.
      // ============================================================
      await snap('08-wizard-viande-empty', '.kiosk-wizard');

      // Select a viande (Merguez-equivalent — first selectable card).
      const viandeCard = kioskPage.locator(
        '.kiosk-step-content .kiosk-viande-card.is-selectable:not(.kiosk-variation--disabled):not(.is-out-of-stock)'
      ).first();
      if (await viandeCard.isVisible({ timeout: 2000 }).catch(() => false)) {
        await viandeCard.click({ timeout: 2500 }).catch(() => {});
        await kioskPage.waitForTimeout(400);
      } else {
        observations.push({
          state: '09-wizard-viande-filled',
          note: 'No viande card .kiosk-viande-card.is-selectable found — may be a different step type',
        });
        // Fallback to generic option.
        const genOpt = kioskPage.locator(
          '.kiosk-step-content .kiosk-option-card[role="checkbox"]:not(.is-out-of-stock):not(.kiosk-variation--disabled), .kiosk-step-content button.kiosk-generic-choice:not([disabled])'
        ).first();
        if (await genOpt.isVisible({ timeout: 1500 }).catch(() => false)) {
          await genOpt.click({ timeout: 2000 }).catch(() => {});
        }
      }

      // ============================================================
      // STATE 09 — wizard viande filled.
      // ============================================================
      await snap('09-wizard-viande-filled', '.kiosk-step-content');

      // Advance to next step.
      const nextBtnViande = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
      if (await nextBtnViande.isVisible({ timeout: 2000 }).catch(() => false)) {
        await nextBtnViande.click({ timeout: 2500 }).catch(() => {});
      }
      await kioskPage.waitForTimeout(700);

      // ============================================================
      // STATE 10 — wizard sauce step.
      // ============================================================
      await snap('10-wizard-sauce', '.kiosk-step-content');

      // Pick a sauce option.
      const sauceOpt = kioskPage.locator(
        '.kiosk-step-content .kiosk-option-card[role="checkbox"]:not(.is-out-of-stock):not(.kiosk-variation--disabled)'
      ).first();
      if (await sauceOpt.isVisible({ timeout: 1500 }).catch(() => false)) {
        await sauceOpt.click({ timeout: 2000 }).catch(() => {});
        await kioskPage.waitForTimeout(300);
      }
      const nextBtnSauce = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
      if (await nextBtnSauce.isVisible({ timeout: 1500 }).catch(() => false)) {
        await nextBtnSauce.click({ timeout: 2500 }).catch(() => {});
      }
      await kioskPage.waitForTimeout(700);

      // ============================================================
      // STATE 11 — wizard menu step (QUEL MENU). Pick "Sans menu" (last card)
      // to avoid inline boisson sub-section.
      // ============================================================
      const menuStepVisible = await kioskPage.locator('.kiosk-step-menu, .kiosk-menu-card[role="radio"]')
        .first().isVisible({ timeout: 1500 }).catch(() => false);
      await snap('11-wizard-menu', '.kiosk-step-menu, .kiosk-step-content');
      if (menuStepVisible) {
        const sansMenu = kioskPage.locator('.kiosk-step-content .kiosk-menu-card[role="radio"]').last();
        if (await sansMenu.isVisible({ timeout: 1500 }).catch(() => false)) {
          await sansMenu.click({ timeout: 2000 }).catch(() => {});
          await kioskPage.waitForTimeout(300);
        }
        const nextBtnMenu = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
        if (await nextBtnMenu.isVisible({ timeout: 1500 }).catch(() => false)) {
          await nextBtnMenu.click({ timeout: 2500 }).catch(() => {});
        }
      } else {
        observations.push({
          state: '11-wizard-menu',
          note: 'menu step not detected — Tacos M may skip menu in V1 config',
        });
      }
      await kioskPage.waitForTimeout(700);

      // ============================================================
      // STATE 12 — wizard extras/supplements step. Look for .suppl-toggle
      // or any optional add-on.
      // ============================================================
      await snap('12-wizard-extras', '.kiosk-step-content');

      // Try toggling a suppl if visible (collapsed state) — best effort, OK to skip.
      const supplToggle = kioskPage.locator('.kiosk-step-content .suppl-toggle, .kiosk-step-content .kiosk-extra-toggle').first();
      if (await supplToggle.isVisible({ timeout: 800 }).catch(() => false)) {
        await supplToggle.click({ timeout: 1500 }).catch(() => {});
        await kioskPage.waitForTimeout(300);
      }

      // Drive remaining steps to RECAP.
      for (let g = 0; g < 8; g++) {
        const recapVisible = await kioskPage.getByTestId('kiosk-order-summary-root')
          .isVisible({ timeout: 400 }).catch(() => false);
        if (recapVisible) break;
        // Pick first available option.
        const opt = kioskPage.locator(
          '.kiosk-step-content .kiosk-viande-card.is-selectable:not(.kiosk-variation--disabled):not(.is-out-of-stock), .kiosk-step-content .kiosk-option-card[role="checkbox"]:not(.is-out-of-stock):not(.kiosk-variation--disabled), .kiosk-step-content .kiosk-menu-card[role="radio"]:last-child, .kiosk-step-content button.kiosk-generic-choice:not([disabled])'
        ).first();
        if (await opt.isVisible({ timeout: 500 }).catch(() => false)) {
          await opt.click({ timeout: 1500 }).catch(() => {});
        }
        const nb = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
        if (await nb.isVisible({ timeout: 1000 }).catch(() => false)) {
          await nb.click({ timeout: 2000 }).catch(() => {});
        }
        await kioskPage.waitForTimeout(400);
      }

      // ============================================================
      // STATE 13 — wizard recap with total.
      // ============================================================
      await snap('13-wizard-recap', '[data-testid="kiosk-order-summary-root"]');

      // Validate add-to-cart.
      const finalAdd = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
      if (await finalAdd.isVisible({ timeout: 1500 }).catch(() => false)) {
        await finalAdd.click({ timeout: 2500 }).catch(() => {});
      }
      await kioskPage.waitForTimeout(1000);

      // Wait for cart to populate (bottom sheet OR cart indicator).
      await kioskPage.waitForFunction(() => (
        /[1-9]\d*\s*(article|item)/i.test(
          document.querySelector('[data-testid="kiosk-categories-cart-indicator"]')?.textContent || ''
        )
        || document.querySelector('[data-testid="kiosk-cart-bottom-sheet"]')
        || document.querySelector('[data-testid="kiosk-cart-bottom-sheet-item-0"]')
      ), null, { timeout: 5000 }).catch(() => {});

      // ============================================================
      // STATE 14 — cart shows Tacos line (via bottom-sheet or indicator).
      // ============================================================
      await snap('14-cart-tacos', '[data-testid="kiosk-cart-bottom-sheet"], [data-testid="kiosk-categories-cart-indicator"]');

      // ============================================================
      // STATE 15 — back to categories, cart indicator visible with count.
      // ============================================================
      // Ensure we're on categories view (closing wizard returns to it).
      const categoriesVisible = await kioskPage.getByTestId('kiosk-categories-root')
        .isVisible({ timeout: 1500 }).catch(() => false);
      if (!categoriesVisible) {
        await kioskPage.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await kioskPage.waitForTimeout(900);
      }
      await snap('15-cat-cart-indicator', '[data-testid="kiosk-categories-cart-indicator"]');

      // ============================================================
      // STATE 16 — Frites Seules simple add (id 361, cat 315, no wizard).
      // ============================================================
      await kioskPage.goto('/kiosk/categories?cat=315', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await kioskPage.waitForTimeout(1000);

      const fritesCard = kioskPage.getByTestId(`kiosk-product-card-${ITEM_FRITES.id}`);
      const fritesVisible = await fritesCard.isVisible({ timeout: 4000 }).catch(() => false);
      if (fritesVisible) {
        await fritesCard.scrollIntoViewIfNeeded().catch(() => {});
        const fritesAdd = kioskPage.getByTestId(`kiosk-product-add-${ITEM_FRITES.id}`);
        if (await fritesAdd.isVisible({ timeout: 1500 }).catch(() => false)) {
          await fritesAdd.click({ timeout: 3000 }).catch(() => {});
        } else {
          await fritesCard.click({ timeout: 3000 }).catch(() => {});
        }
        await kioskPage.waitForTimeout(1200);

        // Frites Seules has a 'style' step (e.g. nature vs sauce-fromagère).
        const fritesStyleVisible = await kioskPage.getByTestId('kiosk-step-frites-style')
          .isVisible({ timeout: 1500 }).catch(() => false);
        if (fritesStyleVisible) {
          const natureBtn = kioskPage.getByTestId('kiosk-frites-style-nature');
          if (await natureBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
            await natureBtn.click({ timeout: 2000 }).catch(() => {});
            await kioskPage.waitForTimeout(400);
          }
          // Walk recap → add.
          for (let g = 0; g < 4; g++) {
            const recap = await kioskPage.getByTestId('kiosk-order-summary-root')
              .isVisible({ timeout: 400 }).catch(() => false);
            const nb = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
            if (await nb.isVisible({ timeout: 800 }).catch(() => false)) {
              await nb.click({ timeout: 2000 }).catch(() => {});
            }
            await kioskPage.waitForTimeout(400);
            if (recap) break;
          }
          const finalFritesAdd = kioskPage.locator('.kiosk-wizard .kiosk-btn-next:not([disabled])').first();
          if (await finalFritesAdd.isVisible({ timeout: 1500 }).catch(() => false)) {
            await finalFritesAdd.click({ timeout: 2500 }).catch(() => {});
          }
          await kioskPage.waitForTimeout(800);
        }
      } else {
        observations.push({
          state: '16-frites-simple',
          note: `Frites Seules card (id ${ITEM_FRITES.id}) not visible on /kiosk/categories?cat=315`,
        });
      }
      await snap('16-frites-simple', '[data-testid="kiosk-categories-cart-indicator"]');

      // ============================================================
      // STATE 17 — cart with 2 lines (Tacos + Frites).
      // ============================================================
      await kioskPage.waitForTimeout(800);
      await snap('17-cart-2-lines', '[data-testid="kiosk-cart-bottom-sheet"], [data-testid="kiosk-categories-cart-indicator"]');

      // ============================================================
      // STATE 18 — qty stepper +1 on first line (via bottom-sheet or cart).
      // ============================================================
      const incrementBtn = kioskPage.locator(
        '[data-testid="kiosk-cart-bottom-sheet-increment-0"], [data-testid="kiosk-cart-item-qty-plus-0"]'
      ).first();
      if (await incrementBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        await incrementBtn.click({ timeout: 2000 }).catch(() => {});
        await kioskPage.waitForTimeout(500);
        observations.push({
          state: '18-cart-qty-plus',
          note: 'qty +1 click landed on bottom-sheet or cart line',
        });
      } else {
        observations.push({
          state: '18-cart-qty-plus',
          note: 'no increment button visible — cart may have shifted; capturing as-is',
        });
      }
      await snap('18-cart-qty-plus', '[data-testid="kiosk-cart-bottom-sheet"], [data-testid="kiosk-cart-root"]');

      // Now proceed to payment: click "pay" or open full cart and checkout.
      const payBtnCat = kioskPage.getByTestId('kiosk-categories-pay');
      let payClicked = false;
      if (await payBtnCat.isVisible({ timeout: 2000 }).catch(() => false)) {
        const payEnabled = await payBtnCat.isEnabled({ timeout: 1000 }).catch(() => false);
        if (payEnabled) {
          await payBtnCat.click({ timeout: 3000 }).catch(() => {});
          payClicked = true;
        }
      }
      if (!payClicked) {
        // Open the bottom-sheet to expand into full cart, then checkout.
        const bottomSheet = kioskPage.getByTestId('kiosk-cart-bottom-sheet');
        if (await bottomSheet.isVisible({ timeout: 1500 }).catch(() => false)) {
          await bottomSheet.click({ timeout: 2000 }).catch(() => {});
          await kioskPage.waitForTimeout(800);
        }
        const checkoutBtn = kioskPage.getByTestId('kiosk-cart-checkout');
        if (await checkoutBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await checkoutBtn.click({ timeout: 3000 }).catch(() => {});
        }
      }

      // Wait for next surface.
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-cart-root"]')
        || document.querySelector('[data-testid="kiosk-upsell-root"]')
        || document.querySelector('[data-testid="kiosk-payment-root"]')
      ), null, { timeout: 8000 }).catch(() => {});

      // If we're on cart-root, pick takeaway + checkout.
      const cartRoot = kioskPage.getByTestId('kiosk-cart-root');
      if (await cartRoot.isVisible({ timeout: 1200 }).catch(() => false)) {
        const orderTypeTakeaway = kioskPage.getByTestId('kiosk-cart-order-type-takeaway');
        if (await orderTypeTakeaway.isVisible({ timeout: 1200 }).catch(() => false)) {
          await orderTypeTakeaway.click({ timeout: 2500 }).catch(() => {});
        }
        const checkoutBtn2 = kioskPage.getByTestId('kiosk-cart-checkout');
        if (await checkoutBtn2.isVisible({ timeout: 1500 }).catch(() => false)) {
          await checkoutBtn2.click({ timeout: 3000 }).catch(() => {});
        }
        await kioskPage.waitForTimeout(800);
      }

      // ============================================================
      // STATE 19 — upsell screen (or absence captured if disabled).
      // ============================================================
      const upsellVisible = await kioskPage.getByTestId('kiosk-upsell-root')
        .isVisible({ timeout: 4000 }).catch(() => false);
      await snap('19-upsell', '[data-testid="kiosk-upsell-root"]');
      observations.push({
        state: '19-upsell',
        upsell_visible: upsellVisible,
        note: upsellVisible ? 'upsell rendered' : 'upsell absent (config disabled or skipped) — captured surface as-is',
      });
      if (upsellVisible) {
        const skipBtn = kioskPage.getByTestId('kiosk-upsell-skip');
        if (await skipBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
          await skipBtn.click({ timeout: 2500 }).catch(() => {});
        }
      }

      // ============================================================
      // STATE 20 — payment modal default state (CB/Espèces/TR visible).
      // ============================================================
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-payment-root"]')
      ), null, { timeout: 10_000 }).catch(() => {});
      await snap('20-payment-modal', '[data-testid="kiosk-payment-root"]');

      // Capture the store-response order id for later cleanup.
      let capturedOrderId = null;
      const captureOrder = async (resp) => {
        try {
          if (resp.request().method() === 'POST' && /\/api\/frontend\/order$/.test(resp.url()) && resp.status() < 400) {
            const json = await resp.json().catch(() => null);
            const data = json?.data || json;
            if (data && data.id && !capturedOrderId) capturedOrderId = Number(data.id);
          }
        } catch (_e) { /* ignore */ }
      };
      kioskPage.on('response', captureOrder);

      // Pick CB and confirm.
      const cardBtn = kioskPage.getByTestId('kiosk-payment-method-card');
      if (await cardBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cardBtn.click({ timeout: 2500 }).catch(() => {});
        await kioskPage.waitForTimeout(400);
      }
      const confirmBtn = kioskPage.getByTestId('kiosk-payment-confirm');
      if (await confirmBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        const cEnabled = await confirmBtn.isEnabled({ timeout: 1500 }).catch(() => false);
        if (cEnabled) {
          await confirmBtn.click({ timeout: 3000 }).catch(() => {});
        }
      }

      // ============================================================
      // STATE 21 — payment loading/processing.
      // ============================================================
      // Snap immediately after click to catch processing state.
      await kioskPage.waitForTimeout(400);
      await snap('21-payment-loading', '[data-testid="kiosk-payment-processing"], [data-testid="kiosk-payment-tpe-overlay"]');

      // ============================================================
      // STATE 22 — confirmation screen with queue_number + transaction_id.
      // ============================================================
      await kioskPage.waitForFunction(() => (
        document.querySelector('[data-testid="kiosk-confirmation-root"]')
        || document.querySelector('[data-testid="kiosk-waiting-root"]')
        || document.querySelector('[data-testid="kiosk-error-payment-cta-retry"]')
      ), null, { timeout: 18_000 }).catch(() => {});
      await kioskPage.waitForTimeout(1200);
      await snap('22-confirmation', '[data-testid="kiosk-confirmation-root"]');

      const confirmCardVisible = await kioskPage.getByTestId('kiosk-confirmation-card')
        .isVisible({ timeout: 1500 }).catch(() => false);
      const confirmNumber = confirmCardVisible
        ? await kioskPage.getByTestId('kiosk-confirmation-number').textContent().catch(() => null)
        : null;
      observations.push({
        state: '22-confirmation',
        confirmation_visible: confirmCardVisible,
        queue_number_text: confirmNumber?.trim()?.slice(0, 50) || null,
        captured_order_id: capturedOrderId,
      });

      kioskPage.off('response', captureOrder);

      // ============================================================
      // STATE 23 — thank-you final screen (often same as confirmation,
      // sometimes a sub-state after a delay or print).
      // ============================================================
      await kioskPage.waitForTimeout(2000);
      await snap('23-thank-you', '[data-testid="kiosk-confirmation-root"], [data-testid="kiosk-waiting-root"]');

      // ============================================================
      // STATE 24 — return to idle.
      // ============================================================
      const homeBtn = kioskPage.getByTestId('kiosk-confirmation-cta-home');
      if (await homeBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
        await homeBtn.click({ timeout: 2500 }).catch(() => {});
        await kioskPage.waitForTimeout(1500);
      } else {
        // Auto-return timeout (kiosk SPA usually auto-redirects after ~5s).
        await kioskPage.waitForTimeout(8000);
      }
      // Force-navigate if we're not back at idle yet.
      if (!/\/kiosk\/idle/.test(kioskPage.url())) {
        await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await kioskPage.waitForTimeout(1200);
      }
      await snap('24-return-idle', '[data-testid="kiosk-idle-root"]');

      // -----------------------------------------------------------------
      // Tier 1 GStack findings — emit our own observations as findings.
      // Adversarial (Tier 2) + Master Dev (Tier 3) will extend.
      // -----------------------------------------------------------------
      const states_reviewed = stateArtifacts.length;

      // FIND-A-001 — if EN selector absent, flag i18n-leak P2.
      if (!enVisible) {
        findings.push({
          id: 'A-001',
          state_artifact: 'test-e2e-wave-A-kiosk/03-idle-lang-en.png',
          category: 'i18n_leak',
          severity: 'P2',
          evidence: 'data-testid="kiosk-idle-lang-en" not visible on idle screen; only FR selector visible. Owner mandate: EN locale must be reachable from idle.',
          fix_hint: 'resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue — verify `availableLocales` includes "en" and that the lang selector renders all configured locales.',
          status: 'open',
          first_seen_round: 1,
          rounds_open: 1,
        });
      }

      // FIND-A-002 — confirmation root not visible → silent_error P0.
      if (!confirmCardVisible) {
        findings.push({
          id: 'A-002',
          state_artifact: 'test-e2e-wave-A-kiosk/22-confirmation.png',
          category: 'silent_error',
          severity: 'P0',
          evidence: 'After CB payment confirm click, [data-testid="kiosk-confirmation-card"] never became visible within 18s window. Order may have failed silently. captured_order_id=' + (capturedOrderId || 'null'),
          fix_hint: 'Check KioskPaymentComponent /api/frontend/order POST + payment-confirm response. If 4xx, surface visible error CTA (kiosk-error-payment-cta-retry) instead of stuck-loading.',
          status: 'open',
          first_seen_round: 1,
          rounds_open: 1,
        });
      }

      // FIND-A-003 — upsell absent — confirm whether config-disabled or render bug.
      if (!upsellVisible) {
        findings.push({
          id: 'A-003',
          state_artifact: 'test-e2e-wave-A-kiosk/19-upsell.png',
          category: 'empty_state',
          severity: 'P3',
          evidence: '[data-testid="kiosk-upsell-root"] not rendered between cart-checkout and payment-root. If kiosk.upsell_enabled=false this is expected; if not, this is a missing-step regression.',
          fix_hint: 'Inspect config kiosk.upsell_enabled and KioskUpsellComponent mount logic. Capture absence here is OK if intentional.',
          status: 'open',
          first_seen_round: 1,
          rounds_open: 1,
        });
      }

      // FIND-A-004 — if Tacos M not visible on cat 306, catalog-data drift P1.
      if (!tacosVisible) {
        findings.push({
          id: 'A-004',
          state_artifact: 'test-e2e-wave-A-kiosk/07-tacos-focused.png',
          category: 'empty_state',
          severity: 'P1',
          evidence: `Tacos M (id ${ITEM_TACOS.id}) not visible on /kiosk/categories?cat=306. Catalog may have shifted or item is_available=false.`,
          fix_hint: 'Verify Items.find(363)->status=5 AND is_available=1 AND category_id=306. Wave B rush-hour relied on the same id, regression here blocks visual capture.',
          status: 'open',
          first_seen_round: 1,
          rounds_open: 1,
        });
      }

      // Sidebar count low → information_architecture observation P3.
      if (sidebarCount < 3) {
        findings.push({
          id: 'A-005',
          state_artifact: 'test-e2e-wave-A-kiosk/05-categories.png',
          category: 'empty_state',
          severity: 'P3',
          evidence: `Only ${sidebarCount} category sidebar items visible. Production catalog typically has 6+ visible categories; sparse sidebar hurts discovery.`,
          fix_hint: 'Confirm CategoryResource includes all status=5 + visible_in_kiosk=1 categories. Could be a filter regression.',
          status: 'open',
          first_seen_round: 1,
          rounds_open: 1,
        });
      }

      // Summary aggregation.
      const summary = {
        P0: findings.filter((f) => f.severity === 'P0').length,
        P1: findings.filter((f) => f.severity === 'P1').length,
        P2: findings.filter((f) => f.severity === 'P2').length,
        P3: findings.filter((f) => f.severity === 'P3').length,
        open_P0: findings.filter((f) => f.severity === 'P0' && f.status === 'open').length,
        open_P1: findings.filter((f) => f.severity === 'P1' && f.status === 'open').length,
        open_P2: findings.filter((f) => f.severity === 'P2' && f.status === 'open').length,
        open_P3: findings.filter((f) => f.severity === 'P3' && f.status === 'open').length,
      };
      let verdict = 'GREEN';
      if (summary.open_P0 > 0) verdict = 'RED';
      else if (summary.open_P1 > 0) verdict = 'AMBER';

      // Emit findings JSON.
      const findingsPayload = {
        wave: 'A',
        round: 1,
        states_reviewed,
        findings,
        summary,
        round_to_round_closures: {},
        verdict,
        notes: 'Tier 1 GStack — visual capture of full kiosk home→payment flow. Captured ' + states_reviewed + '/24 states. Adversarial (Tier 2) + Master Dev (Tier 3) will extend.',
      };
      fs.writeFileSync(
        path.join(REPORTS_DIR, 'wave-A-gstack-findings.json'),
        JSON.stringify(findingsPayload, null, 2),
      );

      // Emit observations sidecar.
      const observationsPayload = {
        wave: 'A',
        round: 1,
        ts_iso: new Date().toISOString(),
        elapsed_ms: Date.now() - startMs,
        states_captured: stateArtifacts,
        palette_reference: PALETTE,
        observations,
        captured_order_id: capturedOrderId,
      };
      fs.writeFileSync(
        path.join(REPORTS_DIR, 'wave-A-gstack-observations.json'),
        JSON.stringify(observationsPayload, null, 2),
      );

      // -----------------------------------------------------------------
      // Coverage assertion — we MUST have 24 quartets (96 files).
      // -----------------------------------------------------------------
      const expected = [
        '01-idle-fresh',
        '02-idle-lang-fr',
        '03-idle-lang-en',
        '04-order-type',
        '05-categories',
        '06-cat-switch',
        '07-tacos-focused',
        '08-wizard-viande-empty',
        '09-wizard-viande-filled',
        '10-wizard-sauce',
        '11-wizard-menu',
        '12-wizard-extras',
        '13-wizard-recap',
        '14-cart-tacos',
        '15-cat-cart-indicator',
        '16-frites-simple',
        '17-cart-2-lines',
        '18-cart-qty-plus',
        '19-upsell',
        '20-payment-modal',
        '21-payment-loading',
        '22-confirmation',
        '23-thank-you',
        '24-return-idle',
      ];
      const missing = expected.filter((name) => {
        return !fs.existsSync(path.join(SCREENSHOT_DIR, `${name}.png`));
      });
      if (missing.length > 0) {
        // Best-effort fill — write a final-state snap with the missing name so
        // each canonical PNG exists. Reviewers can detect mislabeling via
        // observations and DOM markers.
        for (const name of missing) {
          try { await rec.snap(name); } catch (_e) { /* ignore */ }
        }
      }

      expect(missing.length, `Missing canonical PNGs: ${missing.join(', ')}`).toBe(0);
    } finally {
      const elapsedSec = Math.round((Date.now() - startMs) / 1000);
      observations.push({
        state: '__end',
        elapsed_sec: elapsedSec,
        states_captured_count: stateArtifacts.length,
      });
      rec.dispose();
      await kioskCtx.close();
    }
  });

  test.afterAll(() => {
    // Scope cleanup to our own prefix.
    cleanupOrphanTestOrders([TOKEN_PREFIX]);
  });
});
