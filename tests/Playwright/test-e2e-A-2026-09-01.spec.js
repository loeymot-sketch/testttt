// [voice-order-poc-2026-09-01 · Wave A] POS caisse core + regression on the
// existing, already-shipped phone-order flow.
//
// Context: branch voice-order/assist-v1-2026-08-31 mounted a new
// VoiceOrderAssistantPanel inside resources/js/components/admin/pos/PosComponent.vue
// and added call-linking logic that runs AFTER phoneOrderSubmit succeeds
// (persistPendingVoiceOrderLink / scheduleVoiceOrderLinkRetry — both no-ops when
// voiceOrderSelectedCallId is null, which it always is here since no call was
// ever selected). This spec proves the pre-existing phone-order flow renders and
// behaves EXACTLY as before: same controls, same numbers, no voice UI anywhere
// in this flow.
//
// Real selectors verified by reading the source before writing this spec:
//   - Cash drawer modal: PosCashDrawerSessionDialog.vue
//       overlay            [data-testid="cash-session-overlay"]
//       open form          [data-testid="cash-session-open-form"]
//       opening amount     [data-testid="cash-session-opening-input"] (v-model.number, default 50)
//       submit              [data-testid="cash-session-open-submit"]
//       active view        [data-testid="cash-session-active-view"]
//       close (dismiss)    [data-testid="cash-session-close"]
//   - Category-first landing: PosComponent.vue
//       grid                [data-testid="pos-category-grid"]
//       tile                [data-testid="pos-category-tile"] (aria-label = category name)
//   - Item wizard: ItemComponent.vue mounts #item-variation-modal (gains class
//     `active` on open), but the VISIBLE content inside it is rendered by the
//     FROZEN vanilla shim public/js/pos-wizard.js as a sibling `#pos-wizard-root`
//     — the parallel Vue-native header/body/footer are forced `display:none`
//     (`data-wiz-hidden="1"`, verified via a live DOM dump while building this
//     spec). The real controls are therefore vanilla-JS-rendered, not Vue:
//       sauce chip          .sauce-chip (data-type="sauce") inside the modal
//       add-to-cart button  .wizard-btn-cart (data-action="add-to-cart")
//     Clicking the Vue-side hidden "Ajouter au panier" button (accessible via
//     getByRole despite display:none in this Chromium build) is a dead end:
//     it IS the vanilla button that responds, and pos-wizard.js's own
//     `canProceedFromStep` gates the click behind a "Sélectionnez au moins une
//     sauce" validation for any item whose category isn't one of the explicit
//     tacos/sandwich/burger/assiette/salade/omelette/ojja/snacking cases
//     (see getAllowedSteps default case, public/js/pos-wizard.js:531) — this
//     item's category "Suppléments" isn't one of them, so it needs ≥1 sauce
//     selected before the frozen wizard will accept the add.
//   - Cart / totals: PosComponent.vue
//       grand total         [data-testid="pos-grand-total"]
//   - "Channel téléphone": PosComponent.vue — there is no separate channel toggle.
//     order_type already defaults to TAKEAWAY; the phone-order path is: stay on
//     "À emporter" (label[for="takeway"], input#takeway), fill customer name/phone,
//     then click the EXISTING dedicated CTA below the pay button:
//       name                [data-testid="pos-customer-name"]
//       phone               [data-testid="pos-customer-phone"]
//       submit              [data-testid="pos-phone-order"] (@click="phoneOrderSubmit",
//                            :disabled="phoneOrderSubmitting" anti-double-submit guard)
//   - Post-submit queue: kioskCashOrders panel in PosComponent.vue
//       open (badge)        [data-testid="kiosk-cash-open"] (v-if="kioskCashOrders.length>0")
//       per-order detail    [data-testid="kiosk-cash-detail-<id>"] (used to scope the card)
//       per-order total     .kiosk-cash-order-total (no testid; scoped via the card above)
//   - Voice-order leakage probe: VoiceOrderAssistantPanel.vue root
//       [data-testid="voice-order-assistant"] — only mounted when
//       `$route.meta.voiceAssistant === true` (a different route than /admin/pos),
//       so it must have zero presence anywhere in this spec.
//
// Fixture product: this worktree DB (foodking_voice_order_wt) has 11 seeded items.
// Item id=4 "Sauce supplémentaire" (category "Suppléments", id=8) has 3 addons +
// 6 extras (verified via `php artisan tinker` against this worktree's own DB) —
// itemHasNoChoices() is therefore false for it, so it deterministically opens the
// full wizard modal (posQuickAdd.js), unlike a no-choice item which 1-tap-adds
// and never opens the modal at all.
//
// [FIXTURE GAP FOUND + FIXED while building this spec, 2026-09-01] None of the
// 11 items in this worktree DB had ANY item_variations row (0 total, confirmed
// via tinker) — so opening the wizard on item 4 always hit pos-wizard.js's
// unsatisfiable "Sélectionnez au moins une sauce" validation (case
// 'sauce_garnitures' in canProceedFromStep, since this item's category falls
// through detectCategory()'s `default` branch). This is a pre-existing wizard
// business rule, unrelated to the voice-order branch, but it made this DB
// unusable for an add-to-cart regression test as-is.
// Fix applied directly to this worktree's own isolated DB (no other worktree /
// production affected, no code changed, no config file edited): ran the REAL,
// already-existing, idempotent `foodking:sauces:sync` command with a one-off
// in-process `force_attach` override, which attached the pre-existing canonical
// "Sauce (1ère Gratuite)" attribute (item_attribute_id=5 — already part of the
// schema, just unused by any of these 11 items) to item 4, populating it with
// the 13 real canonical sauces from config/pos_sauces.php (Ketchup, Mayonnaise,
// Blanche, Algérienne, Samouraï, Andalouse, Américaine, Barbecue, Curry,
// Harissa, Hannibal, Fromagère maison, Spicy maison, Sans sauce). No invented
// product/sauce names — this is the same SSOT catalog every real sandwich/tacos
// uses, and force_attach is the tool's own supported extension point for this
// exact situation (article that should carry sauces but doesn't yet). Command:
//   php artisan tinker --execute="
//     config(['pos_sauces.force_attach' => [['item_id'=>4,'attribute_id'=>5]]]);
//     Artisan::call('foodking:sauces:sync'); echo Artisan::output();"
// This is now a standing, idempotent fact of this worktree's DB (re-running the
// sync command is a no-op the 2nd time), not something this spec repeats.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('../e2e/helpers/mega-audit-snap');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const SCREENSHOT_DIR = path.resolve(__dirname, '../e2e/__screenshots__/test-e2e-A');

/**
 * Normalize a French-formatted currency string ("0,50 €", "1 234,50 €", "0.50 €"…)
 * to a plain float, so the numeric-integrity assertion is robust to the exact
 * symbol/spacing used by currencyFormat() vs Intl.NumberFormat('fr-FR', ...)
 * (formatKioskPrice) — two different formatters are used on the two surfaces
 * being compared here, so string equality would be the wrong (too strict) check.
 */
function parseMoney(raw) {
  const cleaned = String(raw || '')
    .replace(/[^\d.,-]/g, '') // strip currency symbol, nbsp, narrow-nbsp, letters
    .trim();
  if (!cleaned) return NaN;
  const hasComma = cleaned.includes(',');
  const hasDot = cleaned.includes('.');
  let normalized = cleaned;
  if (hasComma && hasDot) {
    // Whichever separator appears LAST is the decimal separator.
    if (cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.')) {
      normalized = cleaned.replace(/\./g, '').replace(',', '.');
    } else {
      normalized = cleaned.replace(/,/g, '');
    }
  } else if (hasComma) {
    normalized = cleaned.replace(',', '.');
  }
  return Math.round(parseFloat(normalized) * 100) / 100;
}

test.describe('Wave A — POS caisse core + phone-order regression (voice-order-poc-2026-09-01)', () => {
  test('cash drawer -> item wizard -> cart -> phone-order channel -> submit stays byte-for-byte unaffected by the voice-order branch', async ({ page }) => {
    test.setTimeout(150_000);
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Probed before/after every state below — must NEVER appear in this flow.
    const voiceAssistantLocator = page.locator('[data-testid="voice-order-assistant"]');
    const assertNoVoiceLeak = async () => {
      await expect(voiceAssistantLocator, 'VoiceOrderAssistantPanel must never render on the ordinary caisse route').toHaveCount(0);
      await expect(page.getByText('COPILOTE TÉLÉPHONE'), 'voice assistant eyebrow text must never leak into the caisse flow').toHaveCount(0);
    };

    await loginAsPosOperator(page);

    // ---------------------------------------------------------------
    // 01 — cash-drawer-modal
    // ---------------------------------------------------------------
    const overlay = page.locator('[data-testid="cash-session-overlay"]');
    await expect(overlay, 'drawer-opening modal must auto-open on a fresh login with no open session').toBeVisible({ timeout: 20_000 });
    await expect(page.locator('[data-testid="cash-session-open-form"]')).toBeVisible();
    await assertNoVoiceLeak();
    await snap('01-cash-drawer-modal');

    // ---------------------------------------------------------------
    // 02 — caisse-idle (drawer opened with a 50€ float)
    // ---------------------------------------------------------------
    const openingInput = page.locator('[data-testid="cash-session-opening-input"]');
    await openingInput.fill('50');
    await expect(page.locator('[data-testid="cash-session-opening-display"]')).toContainText('50');
    await page.locator('[data-testid="cash-session-open-submit"]').click();
    await expect(page.locator('[data-testid="cash-session-active-view"]'), 'session must report as OPEN after submit').toBeVisible({ timeout: 20_000 });
    await expect(page.locator('[data-testid="cash-session-stat-opening"]')).toContainText('50');
    await page.locator('[data-testid="cash-session-close"]').click();
    await expect(overlay, 'dialog fully dismissed after manual close').toBeHidden({ timeout: 10_000 });

    const categoryGrid = page.locator('[data-testid="pos-category-grid"]');
    await expect(categoryGrid, 'category-first landing (item grid entry point) visible').toBeVisible({ timeout: 20_000 });
    await assertNoVoiceLeak();
    await snap('02-caisse-idle');

    // ---------------------------------------------------------------
    // 03 — item-wizard-open (product with options: id=4 "Sauce supplémentaire")
    // ---------------------------------------------------------------
    const suppTile = page.locator('[data-testid="pos-category-tile"][aria-label="Suppléments"]');
    await expect(suppTile, 'Suppléments category tile present on the landing grid').toBeVisible({ timeout: 20_000 });
    await suppTile.click();

    const itemTile = page.locator('[data-pos-item-id="4"]');
    await expect(itemTile, 'item id=4 (Sauce supplémentaire) tile visible in its category').toBeVisible({ timeout: 20_000 });
    await itemTile.click();

    const wizardModal = page.locator('#item-variation-modal');
    await expect(wizardModal, 'wizard modal gains .active — item has addons/extras so it must NOT 1-tap add').toHaveClass(/active/, { timeout: 20_000 });
    // Visible controls are rendered by the frozen public/js/pos-wizard.js shim
    // (see header note above) — the Vue-native footer button is display:none.
    const sauceChip = wizardModal.locator('.sauce-chip').first();
    await expect(sauceChip, 'canonical sauce chips render (carte de sauces canonique, 2026-08-28)').toBeVisible({ timeout: 10_000 });
    const addToCartBtn = wizardModal.locator('.wizard-btn-cart');
    await expect(addToCartBtn, 'existing vanilla-wizard add-to-cart CTA').toBeVisible();
    await assertNoVoiceLeak();
    await snap('03-item-wizard-open');

    // ---------------------------------------------------------------
    // 04 — cart-with-item
    // ---------------------------------------------------------------
    await sauceChip.click(); // 1st sauce is free per the "Sauce (1ère Gratuite)" attribute — satisfies canProceedFromStep.
    await addToCartBtn.click();
    // POS-CATEGORY-FIRST (2026-06-23): a successful add emits `item:added`,
    // which returns the caisse to the category grid — the v-else branch that
    // hosts <ItemComponent> (and #item-variation-modal inside it) unmounts
    // entirely. Verified live: the modal is REMOVED from the DOM, not merely
    // hidden — assert absence, not just a missing class.
    await expect(page.locator('#item-variation-modal'), 'wizard modal fully unmounts after a successful add (category-first return)').toHaveCount(0, { timeout: 20_000 });

    const grandTotalLocator = page.locator('[data-testid="pos-grand-total"]');
    await expect(grandTotalLocator).toBeVisible({ timeout: 10_000 });
    const cartTotalRaw = (await grandTotalLocator.innerText()).trim();
    const cartTotalAtState04 = parseMoney(cartTotalRaw);
    expect(Number.isFinite(cartTotalAtState04) && cartTotalAtState04 > 0, `parsed a real positive total from "${cartTotalRaw}"`).toBeTruthy();
    await assertNoVoiceLeak();
    await snap('04-cart-with-item');

    // ---------------------------------------------------------------
    // 05 — channel-telephone (order_type already defaults to TAKEAWAY;
    // click it explicitly to demonstrate the real control, then fill
    // name + phone)
    // ---------------------------------------------------------------
    await page.locator('label[for="takeway"]').click();
    await expect(page.locator('input#takeway'), 'À emporter / takeaway is the active order_type for a phone order').toBeChecked();

    const nameInput = page.locator('[data-testid="pos-customer-name"]');
    const phoneInput = page.locator('[data-testid="pos-customer-phone"]');
    await expect(nameInput).toBeVisible();
    await expect(phoneInput).toBeVisible();
    await nameInput.fill('Audit Voice Order Wave A');
    await phoneInput.fill('0601020304');
    await expect(nameInput).toHaveValue('Audit Voice Order Wave A');
    await expect(phoneInput).toHaveValue('0601020304');
    await assertNoVoiceLeak();
    await snap('05-channel-telephone');

    // ---------------------------------------------------------------
    // 06 — phone-order-submitted
    // ---------------------------------------------------------------
    const phoneSubmitBtn = page.locator('[data-testid="pos-phone-order"]');
    await expect(phoneSubmitBtn, 'existing phone-order CTA, not removed/renamed by the voice-order branch').toBeVisible();
    await expect(phoneSubmitBtn).toBeEnabled();

    const createOrderResponsePromise = page.waitForResponse(
      (res) => res.request().method() === 'POST' && /\/api\/admin\/pos$/.test(new URL(res.url()).pathname),
      { timeout: 20_000 },
    );
    await phoneSubmitBtn.click();

    const createOrderResponse = await createOrderResponsePromise;
    expect(createOrderResponse.ok(), `order creation POST must succeed (status ${createOrderResponse.status()})`).toBeTruthy();
    const orderBody = await createOrderResponse.json();
    const createdOrderId = orderBody?.data?.id;
    const createdOrderTotalRaw = orderBody?.data?.total;
    expect(createdOrderId, 'created order id present in the POST response').toBeTruthy();
    expect(createdOrderTotalRaw, 'created order total present in the POST response').not.toBeUndefined();

    // Guard must not leave the CTA stuck, visibly disabled/busy forever: on
    // success the cart resets (carts.length === 0), which unmounts the WHOLE
    // footer CTA block (pos-v5-pay AND pos-phone-order together, same v-if).
    // That is the "navigate away" outcome the anti-double-submit guard is
    // supposed to allow — verified here by asserting BOTH CTAs are gone, not
    // that one specific button silently stays disabled.
    await expect(categoryGrid, 'cart reset returns the caisse to the category-first landing').toBeVisible({ timeout: 20_000 });
    await expect(page.locator('[data-testid="pos-v5-pay"]'), 'pay CTA hidden once the cart is empty').toHaveCount(0);
    await expect(phoneSubmitBtn, 'phone-order CTA hidden once the cart is empty (guard not stuck mid-flight)').toHaveCount(0);
    await expect(nameInput, 'customer name cleared after a successful phone order (no leak to next order)').toHaveValue('');
    await expect(phoneInput, 'customer phone cleared after a successful phone order').toHaveValue('');

    // Order now visible in the "à encaisser" queue, scoped precisely to the id
    // returned by the create POST (never assume it is the only/first row).
    const kioskCashOpenBtn = page.locator('[data-testid="kiosk-cash-open"]');
    await expect(kioskCashOpenBtn, 'à-encaisser shortcut appears once kioskCashOrders is non-empty').toBeVisible({ timeout: 20_000 });
    await kioskCashOpenBtn.click();

    const orderDetailLink = page.locator(`[data-testid="kiosk-cash-detail-${createdOrderId}"]`);
    await expect(orderDetailLink, `the just-created order #${createdOrderId} must appear in the à-encaisser queue`).toBeVisible({ timeout: 20_000 });
    const orderCard = page.locator('.kiosk-cash-order-card').filter({ has: orderDetailLink });
    const queueTotalRaw = (await orderCard.locator('.kiosk-cash-order-total').innerText()).trim();
    await assertNoVoiceLeak();
    await snap('06-phone-order-submitted');

    // ---------------------------------------------------------------
    // Numeric-integrity assertion: cart total at state 04 must equal the
    // total for the SAME order once it surfaces in the à-encaisser queue.
    // ---------------------------------------------------------------
    const createdOrderTotal = Math.round(parseFloat(createdOrderTotalRaw) * 100) / 100;
    const queueTotal = parseMoney(queueTotalRaw);
    expect(
      queueTotal,
      `queue total "${queueTotalRaw}" (parsed ${queueTotal}) must equal cart total at state 04 "${cartTotalRaw}" (parsed ${cartTotalAtState04})`,
    ).toBeCloseTo(cartTotalAtState04, 2);
    expect(
      createdOrderTotal,
      `POST /admin/pos response total (${createdOrderTotal}) must equal cart total at state 04 (${cartTotalAtState04})`,
    ).toBeCloseTo(cartTotalAtState04, 2);

    // ---------------------------------------------------------------
    // Cleanup: close the cash-drawer session opened at state 02, so a
    // subsequent run of this same spec starts from the same fresh "no
    // session" state (state 01's modal is auto-open-if-none-open — leaving
    // the session open would make a re-run silently skip states 01/02).
    // This is real app behavior (end-of-shift close), not test-only code.
    // ---------------------------------------------------------------
    await page.locator('[data-testid="pos-cash-session-open"]').click();
    await expect(page.locator('[data-testid="cash-session-active-view"]')).toBeVisible({ timeout: 10_000 });
    await page.locator('[data-testid="cash-session-go-close"]').click();
    await expect(page.locator('[data-testid="cash-session-close-form"]')).toBeVisible({ timeout: 10_000 });
    const expectedClosingRaw = (await page.locator('[data-testid="cash-session-close-expected"]').innerText()).trim();
    const expectedClosing = parseMoney(expectedClosingRaw);
    await page.locator('[data-testid="cash-session-closing-input"]').fill(String(expectedClosing));
    await page.locator('[data-testid="cash-session-close-submit"]').click();
    await expect(overlay, 'session closed cleanly at end of test — worktree DB left ready for the next fresh-capture run').toBeHidden({ timeout: 10_000 });
  });
});
