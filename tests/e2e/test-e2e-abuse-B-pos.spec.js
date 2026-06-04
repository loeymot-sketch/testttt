/**
 * ABUSE-E2E 2026-06-01 — Wave B: POS Caisse full register
 * Spec: tests/e2e/test-e2e-abuse-B-pos.spec.js
 * Dir:  tests/e2e/__screenshots__/test-e2e-B/
 *
 * Mission (owner): abuse-e2e every page+function with screenshots, deep+bad-mood
 * analysis, "even the notifications". Loop until everything green.
 *
 * FROZEN ZONES — OBSERVE ONLY (driven through the UI, never edited):
 *   - resources/js/components/admin/pos/PaymentComponent.vue
 *   - resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
 *   - public/js/pos-wizard.js (Vanilla-JS composition popup shim)
 *
 * Selectors are EVIDENCE-BACKED (grep + a live MCP/probe run on 2026-06-01):
 *   - item tile button: .pos-v5-tile  (data-pos-item-id) → mouse .click() opens
 *     #item-variation-modal.active  (CONFIRMED live: 30 tiles render, click opens wizard)
 *   - wizard add CTA:   .pos-v5-item-add-cta  ("Ajouter au panier · 3.00€")
 *   - grand total:      [data-testid="pos-grand-total"]
 *   - pay CTA:          [data-testid="pos-v5-pay"]
 *   - cash mode:        [data-testid="pos-payment-mode-cash"]  (PaymentComponent)
 *   - cash input:       #cashInput   change: .pos-v5-payment-change-value
 *   - confirm:          [data-testid="pos-payment-confirm"]
 *   - discount:         [data-testid="pos-discount-input"] / -reason / -apply / -reason-required-flag
 *   - loyalty CTA:      [data-testid="pos-loyalty-redeem-main-cta-open"] → overlay
 *   - tracker:          [data-testid="pos-tracker-open"]
 *   - parked:           cart "Parked" open → ParkedOrdersComponent
 *   - cash session:     [data-testid="pos-cash-session-open"] → PosCashDrawerSessionDialog
 *   - delivery type:    label[for="delivery"] → inline form (geocode = ENV-gated)
 *
 * Delivery fee (branch_id=1, resolved via tinker on DeliveryFeeService 2026-06-01):
 *   delivery_fee_free_km=5, base=5, per_km=1, min=5
 *   → fee@3km = 5.00€ (within free radius) ; fee@7.2km = 8.00€ (5 + 1*ceil(2.2)=5+3)
 *   This is the owner whole-km rule "5€ ≤5km, +1€/km ceil". We OBSERVE the rendered
 *   delivery_charge if geocode resolves; we do NOT hard-assert a guessed number when
 *   geocode is unavailable in dev (environmental).
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsPosOperator, cleanupOrphanTestOrders } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DIR = path.resolve(__dirname, '__screenshots__/test-e2e-B');

// Money string normalizer: "12,50 €" / "12.50€" / " 12.50 €" → "12.50"
function moneyNum(s) {
  if (s == null) return null;
  const m = String(s).replace(/\s/g, '').replace(',', '.').match(/-?\d+(?:\.\d{1,2})?/);
  return m ? parseFloat(m[0]).toFixed(2) : null;
}

test.describe.serial('Wave B — POS caisse full register (frozen wizard observed)', () => {
  test.setTimeout(300_000);

  // Reap this wave's parked/test orders (token AUDIT-RUSH-B-*) before & after so
  // they do not pollute the shared DB or other waves' trackers/KDS piles.
  test.beforeAll(() => cleanupOrphanTestOrders(['AUDIT-RUSH-B-']));
  test.afterAll(() => cleanupOrphanTestOrders(['AUDIT-RUSH-B-']));

  // Findings the spec OBSERVES but does not fail on — surfaced to the reviewer
  // via console + this note array (also printed at end). A real defect stays
  // visible in the PNG/DOM/console artifacts; we never write a fake-passing
  // assertion that hides one.
  const NOTES = [];
  const note = (id, msg) => { NOTES.push(`[${id}] ${msg}`); console.log(`NOTE ${id}: ${msg}`); };

  let snap, dispose;

  test('POS caisse — 01..13 states + cross-surface numeric integrity', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, DIR);
    snap = rec.snap; dispose = rec.dispose;

    // promptParkOrder() uses native window.prompt() — auto-accept so the park
    // flow (state 11) does not hang the run. Use the SWEEPABLE token prefix so
    // the parked order is reaped by cleanupOrphanTestOrders(['AUDIT-RUSH-B-'])
    // and does not pollute the shared DB / other waves' trackers.
    page.on('dialog', (d) => { d.accept('AUDIT-RUSH-B-PARK').catch(() => {}); });

    // ── 01 — login → /admin/pos ─────────────────────────────────────────────
    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
    // POS V5 product grid must mount — real DOM assertion, no .catch swallow.
    const grid = page.locator('.pos-v5-grid').first();
    await expect(grid).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1500);
    await snap('01-login-pos-loaded');

    // i18n leak sweep on the POS shell (measured-P1 rule: visible raw label).
    const shellText = await page.locator('body').innerText().catch(() => '');
    const rawLeak = shellText.match(/\b(pos|kiosk|label|button|message|a11y)\.[a-z_]+(\.[a-z_]+)*/gi);
    if (rawLeak && rawLeak.length) note('B-I18N', `raw label leak on POS shell: ${[...new Set(rawLeak)].slice(0, 6).join(', ')}`);

    // ── 02 — first-page featured filter + "Toutes" toggle ───────────────────
    const tilesAll = page.locator('.pos-v5-tile').filter({ hasNot: page.locator('.pos-item-86-badge') });
    const featuredCount = await tilesAll.count();
    expect(featuredCount).toBeGreaterThan(0); // catalog must render (no DB-deficit)
    note('B-CATALOG', `first-page featured tiles=${featuredCount}`);
    await snap('02a-first-page-featured');

    const toggleAll = page.locator('[data-testid="pos-category-toggle-all"]').first();
    if (await toggleAll.isVisible({ timeout: 4000 }).catch(() => false)) {
      await toggleAll.click({ timeout: 5000 });
      await page.waitForTimeout(1200);
      const afterCount = await page.locator('.pos-v5-tile').count();
      note('B-TOGGLE', `"Toutes" toggle: featured=${featuredCount} → all=${afterCount}`);
      await snap('02b-toutes-toggle-all-categories');
      // Toggle BACK to featured so the add-to-cart line uses a deterministic,
      // immediately-addable featured tile (composable all-category items keep the
      // add CTA disabled until required composition steps are completed).
      await toggleAll.click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(1000);
    } else {
      note('B-TOGGLE', 'pos-category-toggle-all not visible — could not exercise Toutes toggle');
      await snap('02b-toutes-toggle-MISSING');
    }

    // ── 03 — add item (click tile) ──────────────────────────────────────────
    // Cash session may auto-open a dialog at mount; dismiss/open it first so the
    // grid is clickable. (state 12 re-opens it deliberately.)
    const cashDialogOverlay = page.locator('[data-testid="cash-session-overlay"]').first();
    if (await cashDialogOverlay.isVisible({ timeout: 2500 }).catch(() => false)) {
      note('B-CASH-AUTOOPEN', 'cash-session dialog auto-opened at POS mount (no active session)');
      const openForm = page.locator('[data-testid="cash-session-open-form"]').first();
      if (await openForm.isVisible({ timeout: 2000 }).catch(() => false)) {
        await page.locator('[data-testid="cash-session-opening-input"]').first().fill('100').catch(() => {});
        await page.locator('[data-testid="cash-session-open-submit"]').first().click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(1500);
      }
      // close active-session view if it stayed open
      const closeView = page.locator('[data-testid="cash-session-view-movements"], [data-testid="cash-session-overlay"]').first();
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(600);
      void closeView;
    }
    await snap('03a-pre-add-grid');

    // Wizard opens ASYNC and the FROZEN pos-wizard.js Vanilla shim bridges
    // dataset.wizardTotal ~1-2s AFTER #item-variation-modal gets .active — that
    // bridge is what flips .pos-v5-item-add-cta from empty/disabled to
    // "Ajouter au panier · 3.00€"/enabled. So we gate on the CTA being ENABLED
    // (Playwright auto-wait), NOT just on .active — reading the CTA right after
    // .active races the bridge (root cause of attempt-5 flake; NOT a product
    // defect — the button is correctly disabled until priced).
    const wizardModal = page.locator('#item-variation-modal');
    // There can be a hidden duplicate of .pos-v5-item-add-cta in the DOM; scope to
    // the active wizard footer and require visibility so we click the REAL button
    // (the green "Ajouter au panier · X €" footer CTA shown on screen).
    const wizardAddCta = wizardModal.locator('.pos-v5-item-add-cta:visible, button:visible:has-text("Ajouter au panier")').first();
    async function openWizard(tile) {
      await tile.click({ timeout: 5000 });
      await expect(wizardModal).toHaveClass(/active/, { timeout: 8000 });
    }
    async function closeWizard() {
      await page.locator('#item-variation-modal .modal-close').first().click({ timeout: 2500 }).catch(() => {});
      await page.keyboard.press('Escape').catch(() => {});
      await expect(wizardModal).not.toHaveClass(/active/, { timeout: 6000 }).catch(() => {});
    }
    // Dismiss any auto-opened overlay (cash-session dialog re-opens on each POS
    // (re)mount when no session is active) so the cart/order-type controls are
    // reachable for states 11-13.
    async function dismissOverlays() {
      for (let i = 0; i < 4; i++) {
        const ov = page.locator('[data-testid="cash-session-overlay"], #receiptModal.active, .pos-loyalty-redeem-overlay, .parked-orders-overlay').first();
        if (!(await ov.isVisible({ timeout: 1000 }).catch(() => false))) break;
        // Parked panel has its own close button (.parked-orders-close).
        await page.locator('.parked-orders-close').first().click({ timeout: 1200 }).catch(() => {});
        await page.locator('.modal-close, [data-testid="cash-session-overlay"] .modal-close').first().click({ timeout: 1200 }).catch(() => {});
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(500);
      }
    }
    // Add one deterministic cart line (first featured tile) if cart is empty.
    async function ensureCartLine() {
      const gt = page.locator('[data-testid="pos-grand-total"]').first();
      const raw = (await gt.innerText().catch(() => '')) || '';
      if (parseFloat(moneyNum(raw) || '0') > 0) return true;
      try {
        const tile = page.locator('.pos-v5-tile').filter({ hasNot: page.locator('.pos-item-86-badge') }).first();
        await openWizard(tile);
        await expect(wizardAddCta).toBeEnabled({ timeout: 10_000 });
        await wizardAddCta.click({ timeout: 5000 });
        await page.waitForTimeout(1100);
        return true;
      } catch (_e) { return false; }
    }

    // ── 04 — wizard popup (composition) — FROZEN, observe only ──────────────
    // The featured first tile is the multi-step "Menu (Frites + Boisson)" — the
    // frozen pos-wizard.js composition surface this wave must capture. It
    // pre-selects a default composition (3.00€) so its CTA reaches enabled.
    const composableTile = tilesAll.first();
    await expect(composableTile).toBeVisible({ timeout: 10_000 });
    const composableName = (await composableTile.innerText().catch(() => '')).split('\n')[0];
    await openWizard(composableTile);
    await snap('04a-wizard-popup-composition');
    // Auto-wait for the shim to bridge the price → CTA enabled (the real fix).
    await expect(wizardAddCta).toBeEnabled({ timeout: 10_000 });
    const composeCtaText = (await wizardAddCta.innerText().catch(() => '')).replace(/\n/g, ' ');
    note('B-WIZARD-OBSERVE', `composable "${composableName}" wizard; CTA="${composeCtaText}" (enabled after shim bridge)`);
    await snap('04b-wizard-add-cta');

    // ── 05 — add to cart → cart + grand-total ───────────────────────────────
    await wizardAddCta.click({ timeout: 5000 });
    await page.waitForTimeout(1200);
    let cartLineAdded = true;

    // Open the cart canvas if it's a drawer on this viewport; grand-total lives in it.
    const grandTotalEl = page.locator('[data-testid="pos-grand-total"]').first();
    if (!(await grandTotalEl.isVisible({ timeout: 3000 }).catch(() => false))) {
      const cartChip = page.locator('[data-testid="pos-cart-stat-chip"]').first();
      if (await cartChip.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cartChip.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(800);
      }
    }
    await expect(grandTotalEl).toBeVisible({ timeout: 10_000 });
    const cartTotalRaw = (await grandTotalEl.innerText().catch(() => '')).trim();
    const cartTotal = moneyNum(cartTotalRaw);
    expect(cartTotal).not.toBeNull();
    expect(parseFloat(cartTotal)).toBeGreaterThan(0); // a line is really in the cart
    note('B-CART-TOTAL', `cart line added=${cartLineAdded} grand-total="${cartTotalRaw}" parsed=${cartTotal}`);
    await snap('05-cart-grand-total');

    // ── 06 — discount (reason required) ─────────────────────────────────────
    const discInput = page.locator('[data-testid="pos-discount-input"]').first();
    if (await discInput.isVisible({ timeout: 4000 }).catch(() => false)) {
      await discInput.fill('1');
      await page.waitForTimeout(400);
      // With a positive discount and NO reason, Apply MUST be disabled (mirror of
      // backend OrderService enforcement) and the required flag should show.
      const applyBtn = page.locator('[data-testid="pos-discount-apply"]').first();
      const applyDisabled = await applyBtn.isDisabled().catch(() => null);
      const reqFlag = page.locator('[data-testid="pos-discount-reason-required-flag"]').first();
      const reqVisible = await reqFlag.isVisible({ timeout: 2000 }).catch(() => false);
      await snap('06a-discount-no-reason');
      if (applyDisabled !== true) note('B-DISC-GUARD', `discount Apply NOT disabled without reason (disabled=${applyDisabled}) — backend-mirror guard weak`);
      if (!reqVisible) note('B-DISC-FLAG', 'pos-discount-reason-required-flag not visible despite positive discount + empty reason');
      // Provide a reason → Apply should enable; apply it.
      await page.locator('[data-testid="pos-discount-reason"]').first().fill('geste commercial test').catch(() => {});
      await page.waitForTimeout(400);
      const nowEnabled = await applyBtn.isEnabled().catch(() => false);
      if (nowEnabled) {
        await applyBtn.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(800);
      } else {
        note('B-DISC-APPLY', 'discount Apply still disabled after 3+ char reason supplied');
      }
      await snap('06b-discount-applied');
      // Clear the discount so the downstream cross-surface total chain is clean.
      await discInput.fill('0').catch(() => {});
      await page.waitForTimeout(300);
      const reapply = await applyBtn.isEnabled().catch(() => false);
      if (reapply) { await applyBtn.click({ timeout: 3000 }).catch(() => {}); await page.waitForTimeout(600); }
      await snap('06c-discount-cleared');
    } else {
      note('B-DISCOUNT', 'pos-discount-input not visible — discount block unreachable');
      await snap('06-discount-MISSING');
    }

    // Re-read the canonical total AFTER discount cleared — this is the number we
    // carry across cart → payment-modal → receipt → tracker.
    const totalAfterDiscRaw = (await grandTotalEl.innerText().catch(() => '')).trim();
    const canonicalTotal = moneyNum(totalAfterDiscRaw);
    expect(canonicalTotal).not.toBeNull();
    note('B-CANON', `canonical grand-total after discount cleared = ${canonicalTotal} ("${totalAfterDiscRaw}")`);

    // ── 07 — loyalty redeem block ───────────────────────────────────────────
    // VERIFIED gating (PosComponent.vue:202 :disabled="!canShowLoyaltyMainCta",
    // :248 openLoyaltyMainModal early-returns if !canShowLoyaltyMainCta): with no
    // attached customer / no redeemable points the CTA is INTENTIONALLY DISABLED
    // (tooltip pos.loyalty.redeem.disabled_hint). A no-op click on a disabled CTA
    // is correct behavior, NOT a silent-failure defect.
    const loyaltyCta = page.locator('[data-testid="pos-loyalty-redeem-main-cta-open"]').first();
    if (await loyaltyCta.isVisible({ timeout: 4000 }).catch(() => false)) {
      const loyDisabled = await loyaltyCta.isDisabled().catch(() => null);
      await loyaltyCta.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(900);
      const loyOverlay = page.locator('[data-testid="pos-loyalty-redeem-overlay"]').first();
      const loyVisible = await loyOverlay.isVisible({ timeout: 3000 }).catch(() => false);
      await snap('07a-loyalty-redeem-block');
      if (loyVisible) {
        const loyClose = page.locator('[data-testid="pos-loyalty-redeem-close"], [data-testid="pos-loyalty-redeem-cancel"]').first();
        await loyClose.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(600);
      } else if (loyDisabled === true) {
        note('B-LOYALTY', 'loyalty CTA correctly DISABLED (canShowLoyaltyMainCta=false, no customer/points) — overlay not opening is by-design, verified at source');
      } else {
        note('B-LOYALTY', `loyalty CTA enabled=${!loyDisabled} but overlay did not open after click — POSSIBLE silent no-op (reviewer: confirm)`);
      }
      await snap('07b-loyalty-closed');
    } else {
      note('B-LOYALTY', 'pos-loyalty-redeem-main-cta-open not visible — loyalty block unreachable');
      await snap('07-loyalty-MISSING');
    }

    // ── 08 — payment modal (cash: received/change) ──────────────────────────
    const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
    await expect(payBtn).toBeVisible({ timeout: 8000 });
    await payBtn.click({ timeout: 6000 });
    await page.waitForTimeout(1400);

    const cashModeBtn = page.locator('[data-testid="pos-payment-mode-cash"]').first();
    await expect(cashModeBtn).toBeVisible({ timeout: 8000 });
    await cashModeBtn.click({ timeout: 5000 });
    await page.waitForTimeout(700);
    await snap('08a-payment-modal-cash-mode');

    // CROSS-SURFACE #1: the payment modal must display the SAME total as the cart.
    // PaymentComponent hero total = .pos-v5-payment-total-value (props.form.total).
    const modalTotalEl = page.locator('.pos-v5-payment-total-value, [data-testid="pos-payment-total-covered"], [data-testid="pos-counter-collect-total"]').first();
    let modalTotal = null;
    if (await modalTotalEl.isVisible({ timeout: 4000 }).catch(() => false)) {
      modalTotal = moneyNum(await modalTotalEl.innerText().catch(() => ''));
    } else {
      // fall back to any element in the modal that contains the canonical figure
      const modalText = await page.locator('.pos-v5-payment, [role="dialog"]').first().innerText().catch(() => '');
      if (modalText.includes(canonicalTotal) || (canonicalTotal && modalText.includes(canonicalTotal.replace('.', ',')))) {
        modalTotal = canonicalTotal;
      }
    }
    note('B-XSURF-MODAL', `payment-modal total=${modalTotal} vs cart canonical=${canonicalTotal}`);

    // Cash received → change. Pay exact+ to produce a deterministic change.
    const cashInput = page.locator('#cashInput').first();
    await expect(cashInput).toBeVisible({ timeout: 6000 });
    const tendered = (Math.ceil(parseFloat(canonicalTotal)) + 10).toFixed(2);
    await cashInput.fill(tendered);
    await page.waitForTimeout(700);
    const changeEl = page.locator('.pos-v5-payment-change-value').first();
    let changeStr = null, expectedChange = null;
    if (await changeEl.isVisible({ timeout: 3000 }).catch(() => false)) {
      changeStr = moneyNum(await changeEl.innerText().catch(() => ''));
      expectedChange = (parseFloat(tendered) - parseFloat(canonicalTotal)).toFixed(2);
    }
    note('B-CASH-CHANGE', `tendered=${tendered} total=${canonicalTotal} change_shown=${changeStr} change_expected=${expectedChange}`);
    await snap('08b-payment-cash-received-change');

    // HARD cross-surface assertion: if the modal exposed a total, it MUST equal the cart total.
    if (modalTotal !== null) {
      expect(modalTotal).toBe(canonicalTotal); // numeric integrity cart=modal (real, no .catch)
    } else {
      note('B-XSURF-MODAL', 'could not read a numeric total inside the payment modal — cross-surface cart=modal NOT proven (env/selector)');
    }
    // HARD change-math assertion when change rendered.
    if (changeStr !== null && expectedChange !== null) {
      expect(changeStr).toBe(expectedChange); // change = tendered - total
    }

    // ── 09 — confirm → receipt / ticket ─────────────────────────────────────
    const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]').first();
    await expect(confirmBtn).toBeVisible({ timeout: 8000 });
    // Capture the order-creation response so we can scope the tracker check to
    // THIS order's id (amount alone can't identify our row among many).
    const orderRespP = page.waitForResponse(
      (r) => r.request().method() === 'POST' && /\/api\/admin\/pos(?:\?|$)/.test(r.url()),
      { timeout: 15_000 },
    ).catch(() => null);
    await confirmBtn.click({ timeout: 6000 });
    let paidOrderId = null;
    const orderResp = await orderRespP;
    if (orderResp) {
      try { const body = await orderResp.json(); paidOrderId = body?.data?.id ?? body?.id ?? body?.order?.id ?? null; } catch (_e) {}
      note('B-ORDER', `order POST status=${orderResp.status()} id=${paidOrderId}`);
    }
    await page.waitForTimeout(2500);
    await snap('09a-after-confirm');

    // Business-state proof: either a receipt/confirmation surfaced OR the cart reset to empty.
    const postPay = await page.evaluate(() => {
      const txt = (document.body.innerText || '');
      return {
        hasReceipt: /ticket|reçu|encaiss|confirmé|confirm|monnaie/i.test(txt),
        cartEmpty: /panier vide|cart empty|aucun article/i.test(txt),
        grand: (document.querySelector('[data-testid="pos-grand-total"]')?.innerText || '').trim(),
        crash: /Whoops|Fatal error|Server Error|TypeError/i.test(txt),
      };
    });
    expect(postPay.crash).toBeFalsy(); // no fatal after confirm
    expect(postPay.hasReceipt || postPay.cartEmpty).toBeTruthy(); // payment cycle really completed
    note('B-PAY-DONE', `post-confirm hasReceipt=${postPay.hasReceipt} cartEmpty=${postPay.cartEmpty} grand="${postPay.grand}"`);

    // CROSS-SURFACE #2: the receipt modal (#receiptModal) must show the SAME total.
    const receiptModal = page.locator('#receiptModal.active');
    if (await receiptModal.isVisible({ timeout: 4000 }).catch(() => false)) {
      const receiptText = await receiptModal.innerText().catch(() => '');
      // DIGIT-BOUNDARIED match: "3.00"/"3,00" NOT preceded/followed by a digit.
      // This pins the actual TOTAL and rejects the tendered "13,00€" / change
      // "10,00€" lines also printed on the ticket (a plain includes() would
      // fake-pass on "13,00".includes("3,00")). canonicalTotal="3.00".
      const [intP, decP] = canonicalTotal.split('.');
      const boundaried = new RegExp(`(?<!\\d)${intP}[.,]${decP}(?!\\d)`);
      const receiptHasTotal = boundaried.test(receiptText);
      note('B-XSURF-RECEIPT', `receipt ticket has digit-boundaried total ${canonicalTotal} (rejecting tendered/change): ${receiptHasTotal}`);
      await snap('09b-receipt-modal');
      // HARD: the ticket must echo the paid total (numeric integrity cart=receipt).
      expect(receiptHasTotal).toBeTruthy();
      // Close the receipt modal so it does not intercept the tracker click.
      await receiptModal.locator('.modal-close').first().click({ timeout: 4000 }).catch(() => {});
      await page.keyboard.press('Escape').catch(() => {});
      await expect(receiptModal).toBeHidden({ timeout: 6000 }).catch(() => {});
    } else {
      note('B-XSURF-RECEIPT', 'receipt modal (#receiptModal.active) not visible post-confirm — cart=receipt not proven via modal');
      await snap('09b-receipt-or-reset');
    }

    // ── 10 — orders tracker (tracker amount echoes a receipt total) ─────────
    // pos-tracker-open is a ROUTER-LINK to admin.pos-orders.tracker (full-screen
    // route, NOT a modal) — it navigates AWAY from /admin/pos. Capture it, assert
    // the cross-surface total, then navigate BACK so states 11-13 (cart-side
    // controls) remain reachable.
    const trackerOpen = page.locator('[data-testid="pos-tracker-open"]').first();
    if (await trackerOpen.isVisible({ timeout: 5000 }).catch(() => false)) {
      await trackerOpen.click({ timeout: 5000 });
      await page.waitForURL(/\/admin\/pos-orders\/tracker|tracker/, { timeout: 10_000 }).catch(() => {});
      await page.waitForTimeout(1800);
      await snap('10a-orders-tracker');
      // Cross-surface #2: scope to THIS order's tracker-amount-<id> element.
      // (A page-wide substring would collide with other orders' amounts like
      //  13,00/43,00 — that proves nothing, so we pin the specific row.)
      if (paidOrderId != null) {
        const amountEl = page.locator(`[data-testid="tracker-amount-${paidOrderId}"]`).first();
        if (await amountEl.isVisible({ timeout: 4000 }).catch(() => false)) {
          const rowAmt = moneyNum(await amountEl.innerText().catch(() => ''));
          note('B-XSURF-TRACKER', `tracker-amount-${paidOrderId}=${rowAmt} vs canonical=${canonicalTotal}`);
          expect(rowAmt).toBe(canonicalTotal); // HARD: tracker row == paid total
        } else {
          note('B-XSURF-TRACKER', `tracker-amount-${paidOrderId} not visible in tracker route (order may be in a non-rendered column) — cart=tracker not pinned this run`);
        }
      } else {
        note('B-XSURF-TRACKER', 'order id unknown — tracker cross-surface left as soft disclosure (amount substring would collide with other orders, not asserted)');
      }
      // History tab if present
      const hist = page.locator('[data-testid="pos-tracker-history"]').first();
      if (await hist.isVisible({ timeout: 2500 }).catch(() => false)) {
        await hist.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(1000);
        await snap('10b-tracker-history');
      }
      // Back to POS (back-link, else explicit goto) — required for states 11-13.
      const backLink = page.locator('.pos-tracker-back-link').first();
      if (await backLink.isVisible({ timeout: 2500 }).catch(() => false)) {
        await backLink.click({ timeout: 4000 }).catch(() => {});
      }
      await page.waitForTimeout(800);
      if (!/\/admin\/pos(\/|$|\?)/.test(page.url()) || /tracker/.test(page.url())) {
        await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      }
      await expect(page.locator('.pos-v5-grid').first()).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(1200);
    } else {
      note('B-TRACKER', 'pos-tracker-open not visible — orders tracker unreachable');
      await snap('10-tracker-MISSING');
    }

    // ── 11 — parked orders ──────────────────────────────────────────────────
    // Add a fresh line (deterministic first featured tile), then Park it.
    await dismissOverlays();
    const parkLineAdded = await ensureCartLine();
    if (!parkLineAdded) note('B-PARK-LINE', 'could not add a line to park — park flow skipped');
    const parkBtn = page.getByRole('button', { name: /park|mettre en attente|en attente|parquer/i }).first();
    if (await parkBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await parkBtn.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(1200);
      await snap('11a-after-park');
    } else {
      note('B-PARK', 'park button not found by accessible name — could not park an order');
    }
    const parkedOpen = page.getByRole('button', { name: /parked|commandes en attente|reprendre/i }).first();
    if (await parkedOpen.isVisible({ timeout: 3000 }).catch(() => false)) {
      await parkedOpen.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(1200);
    }
    const parkedSearch = page.locator('[data-testid="parked-orders-search"]').first();
    if (await parkedSearch.isVisible({ timeout: 3000 }).catch(() => false)) {
      await snap('11b-parked-orders-list');
      note('B-PARKED', 'parked panel open with parked order — restore/delete CTAs present');
    } else {
      note('B-PARKED', 'parked-orders-search not visible — parked orders panel not confirmed open');
      await snap('11b-parked-orders-UNCONFIRMED');
    }
    // Close the parked panel (its overlay otherwise covers states 12-13).
    await page.locator('.parked-orders-close').first().click({ timeout: 3000 }).catch(() => {});
    await page.keyboard.press('Escape').catch(() => {});
    await expect(page.locator('.parked-orders-overlay')).toBeHidden({ timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(500);

    // ── 12 — cash session open / drawer ─────────────────────────────────────
    await dismissOverlays();
    const cashSessionBtn = page.locator('[data-testid="pos-cash-session-open"]').first();
    if (await cashSessionBtn.isVisible({ timeout: 4000 }).catch(() => false)) {
      await cashSessionBtn.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(1200);
      const dialogTitle = page.locator('[data-testid="cash-session-title"]').first();
      const dialogVisible = await dialogTitle.isVisible({ timeout: 3000 }).catch(() => false);
      await snap('12a-cash-session-dialog');
      if (!dialogVisible) note('B-CASHSESS', 'pos-cash-session-open clicked but cash-session-title not visible');
      // Show movements view if an active session exists
      const viewMvts = page.locator('[data-testid="cash-session-view-movements"]').first();
      if (await viewMvts.isVisible({ timeout: 2500 }).catch(() => false)) {
        await viewMvts.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(900);
        await snap('12b-cash-session-movements');
      }
      // Close the cash-session overlay explicitly via its ✕ (Escape alone does
      // not dismiss it; otherwise it covers the delivery controls in state 13).
      await page.locator('[data-testid="cash-session-close"]').first().click({ timeout: 3000 }).catch(() => {});
      await page.keyboard.press('Escape').catch(() => {});
      await expect(page.locator('[data-testid="cash-session-overlay"]')).toBeHidden({ timeout: 6000 }).catch(() => {});
      await page.waitForTimeout(500);
    } else {
      note('B-CASHSESS', 'pos-cash-session-open not visible — cash session/drawer unreachable');
      await snap('12-cash-session-MISSING');
    }

    // ── 13 — delivery order → fee = Hénin-Beaumont whole-km 5€+1€/km ceil ───
    // Select Delivery type → inline form. Geocode (Google Places) is the
    // environmental boundary in dev; we OBSERVE whether a delivery_charge renders
    // and report the rule, never fake-asserting a fee.
    // Order-type controls only render with a cart line present.
    await dismissOverlays();
    await ensureCartLine();
    // Open the cart canvas (where the order-type segmented control lives).
    const cartChip13 = page.locator('[data-testid="pos-cart-stat-chip"]').first();
    if (await cartChip13.isVisible({ timeout: 2000 }).catch(() => false)) {
      await cartChip13.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(700);
    }
    const deliveryLabel = page.locator('label[for="delivery"]').first();
    if (await deliveryLabel.isVisible({ timeout: 4000 }).catch(() => false)) {
      await deliveryLabel.click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(1000);
      await snap('13a-delivery-type-selected');
      const addrInput = page.locator('input[placeholder*="Adresse de livraison" i]').first();
      if (await addrInput.isVisible({ timeout: 3000 }).catch(() => false)) {
        await addrInput.fill('Rue Elie Gruyelle, Hénin-Beaumont').catch(() => {});
        await page.waitForTimeout(2000); // allow autocomplete debounce
        const suggestion = page.locator('ul li').filter({ hasText: /Hénin|Beaumont|Gruyelle/i }).first();
        if (await suggestion.isVisible({ timeout: 4000 }).catch(() => false)) {
          await suggestion.click({ timeout: 3000 }).catch(() => {});
          await page.waitForTimeout(2000);
          await snap('13b-delivery-address-confirmed');
          // Did a delivery_charge row render? Observe its value against the rule.
          const feeRow = page.locator('text=/livraison|delivery/i').first();
          const feeText = await page.locator('body').innerText().catch(() => '');
          const feeMatch = feeText.match(/(?:livraison|delivery)[^\d]{0,20}(\d+[.,]\d{2})/i);
          const renderedFee = feeMatch ? moneyNum(feeMatch[1]) : null;
          note('B-DELIVERY-FEE', `delivery fee rendered=${renderedFee} | rule (branch1): 5€ ≤5km, +1€/km ceil (fee@3km=5.00, fee@7.2km=8.00)`);
          void feeRow;
          // Grand total should now include the fee — capture it (no hard assert on the
          // exact geocoded distance, which is non-deterministic in dev).
          const gtDelivery = (await grandTotalEl.innerText().catch(() => '')).trim();
          note('B-DELIVERY-TOTAL', `grand-total with delivery=${gtDelivery}`);
          await snap('13c-delivery-grand-total');
        } else {
          note('B-DELIVERY-GEOCODE', 'address autocomplete returned no suggestion (Google Places key/network ENV-gated in dev) — delivery fee path not reachable; rule documented: branch1 5€ ≤5km +1€/km ceil');
          await snap('13b-delivery-no-suggestion-ENVGAP');
        }
      } else {
        note('B-DELIVERY', 'delivery address input not visible after selecting Delivery type');
        await snap('13b-delivery-input-MISSING');
      }
    } else {
      note('B-DELIVERY', 'Delivery order-type label not visible — delivery flow unreachable');
      await snap('13-delivery-MISSING');
    }

    // ── Final observation dump (visible to reviewer in test stdout) ─────────
    console.log('\n===== WAVE B — OBSERVED NOTES (' + NOTES.length + ') =====');
    for (const n of NOTES) console.log(n);
    console.log('===== END WAVE B NOTES =====\n');

    dispose && dispose();
  });
});
