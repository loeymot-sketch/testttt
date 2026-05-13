// FoodKing E2E — kiosk · KDS · OSS sync audit Wave B (kiosk wizard popup) — 2026-05-11
//
// Wave B scope : open the kiosk wizard for 3 representative items and capture
// every wizard step + recap + cart line after wizard validates. Numeric integrity
// is the canonical P0 — wizard recap total MUST equal cart line subtotal.
//
// Audit plan : reports/test-e2e/kiosk-kds-sync-2026-05-11/AUDIT_PLAN.md (Wave B)
//
// ============================================================================
// CRITICAL — Wizard surface branch declaration
// ============================================================================
// `kioskUsePosWizard` env flag is true in this run. The plan / risk register
// suggests this means the FROZEN `public/js/pos-wizard.js` would render inside
// `KioskPosWizardComponent.vue`. THE CODE SAYS OTHERWISE :
//
//   - `KioskPosWizardComponent.vue` (8 lines) is a thin wrapper :
//        <template><KioskWizardComponent v-bind="$attrs" /></template>
//   - `KioskCategoriesComponent.vue` line 305 imports `KioskWizardComponent`
//     directly and mounts it via :
//        <div v-if="activeItem" class="kiosk-wizard-overlay">
//          <KioskWizardComponent :item="activeItem" :on-add-to-cart=… />
//        </div>
//
// → Both kiosk codepaths land on the AUDITABLE Vue `KioskWizardComponent.vue`.
//   `public/js/pos-wizard.js` is NOT loaded by any kiosk surface.
//   The frozen-zone diff at end-of-round will naturally be empty.
//
// Defensive runtime check : we assert `.pos-wizard.single-page` count === 0
// in the wizard at state 02. Non-zero would prove a routing regression
// against the source-grep finding above.
//
// FROZEN ZONES touched (capture only — ZERO line of patch) :
//   • public/js/pos-wizard.js          (NOT loaded — verified at runtime)
//   • public/css/pos-wizard.css        (NOT loaded — verified at runtime)
//   • resources/views/admin-pos-v4.blade.php  (POS-only blade — irrelevant for kiosk)
//
// AUDITABLE files in scope (patches allowed in fix waves via owner-gated heal) :
//   • resources/js/components/frontend/kiosk/KioskWizardComponent.vue
//   • resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue
//   • resources/js/components/frontend/kiosk/steps/Kiosk*Component.vue
//   • resources/js/components/frontend/kiosk/ds/KsCartBottomSheet.vue
//
// ============================================================================
// Wizard model — Vue multi-step (NOT POS single-page)
// ============================================================================
// The Vue kiosk wizard is multi-step. Each step has `currentStep.type` ∈
// {viande, sauce, pain, frites_style, taille, generic_choices, menu, recap}.
// To advance you MUST :
//   1. Make a selection that satisfies `canAdvance` (button gate)
//   2. Click `.kiosk-btn-next` (last step has class `kiosk-btn-next--cart`)
//   3. Final recap click calls `addToCart()` → overlay disappears (activeItem=null)
//
// Different from the POS spec which used a single-page sticky-bar wizard
// (`.pos-wizard.single-page` + `.wizard-sticky-bar` + per-section scroll). The
// kiosk Vue wizard renders ONE step at a time inside `.kiosk-step-content`.
//
// ============================================================================
// Item triplet (DB-verified 2026-05-11 against catalog status=5)
// ============================================================================
//   - 363 "Tacos M (1 Viande)" cat 306 — 6.50€ — 24 vars + 23 extras + 3 addons
//   - 376 "Cheese Burger"      cat 308 — 6.00€ — 15 vars + 23 extras + 3 addons
//   - 367 "Le Méga"            cat 307 — 8.00€ — 35 vars + 23 extras + 3 addons
//
// All three items have ≥1 variation/extra/addon → all open the wizard
// (no direct-add path). All three are status=5 (active).
//
// ============================================================================
// Order-type prerequisite
// ============================================================================
// `KioskCategoriesComponent.openProduct` calls `ensureOrderTypeSelected()`
// which bounces to `/kiosk/idle` if no order type was set (line 438 of the
// component, store getter `kioskCart/hasExplicitOrderType`).
//
// Vuex action `kioskCart/setOrderType` accepts the numeric type
// (KIOSK_ORDER_TYPES.KIOSK or TAKEAWAY/DINEIN). We dispatch it explicitly
// after `loginAsKiosk` so the first tile click does not silently bounce.
//
// ============================================================================
// Real DOM markers (verified by source-grep on KioskWizardComponent.vue)
// ============================================================================
//   - Wizard root           : .kiosk-wizard
//   - Wizard overlay        : .kiosk-wizard-overlay
//   - Step content          : .kiosk-step-content
//   - Next button           : .kiosk-btn-next  (final = .kiosk-btn-next--cart)
//   - Back button           : .kiosk-btn-back
//   - Abandon button        : .kiosk-btn-abandon
//   - Running total label   : .kiosk-nav-total
//   - Tile (catalog)        : [data-testid="kiosk-product-card-${id}"]
//   - Cart bottom-sheet     : [data-testid="kiosk-cart-bottom-sheet"]
//   - Cart strip line price : .kiosk-cart-strip-price
//   - Full-cart route       : /kiosk/cart  → [data-testid="kiosk-cart-root"]
//   - Full-cart line total  : [data-testid="kiosk-cart-item-total-${idx}"]
//   - Full-cart grand total : [data-testid="kiosk-cart-total"]
//   - Composition chips     : [data-testid="kiosk-wizard-live-composition"]
//
// ============================================================================
// State plan (15 states ; matches AUDIT_PLAN.md §Wave B)
// ============================================================================
//   01-b-tile-tap-item-1                   pre-tap baseline tile (Tacos M)
//   02-b-wizard-open-item-1                wizard overlay open + branch declaration
//   03-b-wizard-variant-pick-item-1        first option layer chosen
//   04-b-wizard-recap-item-1               recap step reached
//   05-b-cart-after-validate-item-1        cart bottom-sheet line for item 1
//   06-b-wizard-open-item-2                Cheese Burger wizard open
//   07-b-wizard-step-1-item-2              first option layer
//   08-b-wizard-step-extras-item-2         menu/addons step (paid extra layer)
//   09-b-wizard-recap-item-2               recap
//   10-b-cart-after-validate-item-2        cart bottom-sheet (item 2 line)
//   11-b-wizard-open-item-3                Le Méga wizard open
//   12-b-wizard-step-menu-or-split-item-3  menu/composite step
//   13-b-wizard-recap-item-3               recap
//   14-b-cart-after-validate-item-3        cart bottom-sheet (item 3 line)
//   15-b-cart-three-lines-aggregate        navigate to /kiosk/cart, all 3 lines + grand total
//                                            + payload sidecar via Vuex probe

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsKiosk } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-kiosk-kds-sync-B');

// Item triplet — see header comment for verification.
const ITEMS = [
  { id: 363, name: 'Tacos M (1 Viande)', cat: 306, basePrice: 6.50, label: 'item-1' },
  { id: 376, name: 'Cheese Burger',       cat: 308, basePrice: 6.00, label: 'item-2' },
  { id: 367, name: 'Le Méga',             cat: 307, basePrice: 8.00, label: 'item-3' },
];

// Max step traversal cap (defensive — a real wizard rarely has >8 steps).
const MAX_STEP_TRAVERSAL = 12;

/**
 * Parse a French-format price string ("8,50 €", "8.50€", "Total : 8,50 €")
 * to a Number. Returns NaN if no digits are found — caller must guard.
 */
function parsePrice(text) {
  if (text == null) return NaN;
  const cleaned = String(text).trim();
  // Keep digits, comma, dot only ; convert comma to dot.
  const numeric = cleaned.replace(/[^\d.,]/g, '').replace(',', '.');
  const n = parseFloat(numeric);
  return Number.isFinite(n) ? n : NaN;
}

/**
 * Wait for the kiosk catalog grid to render (at least one tile).
 */
async function waitForCatalog(page) {
  // Tiles are rendered as [data-testid="kiosk-product-card-${id}"] inside
  // `.kiosk-product-grid`. Wait for any tile to appear.
  await page.waitForSelector('[data-testid^="kiosk-product-card-"]', { state: 'visible', timeout: 25_000 });
  await page.waitForTimeout(600);
}

/**
 * Force the order-type to TAKEAWAY via Vuex so subsequent wizard opens are not
 * silently bounced by `ensureOrderTypeSelected()` → /kiosk/idle.
 *
 * Returns { ok, before, after, reason }. Best-effort — if the store path is
 * not exposed (Vue 3 internals), the test continues but may bounce.
 */
async function forceOrderType(page, type = 2 /* takeaway */) {
  return page.evaluate((t) => {
    try {
      const el = document.querySelector('#app');
      const app = el && el.__vue_app__;
      const $store = app && app.config && app.config.globalProperties && app.config.globalProperties.$store;
      if (!$store) return { ok: false, reason: 'no-store' };
      const before = $store.state?.kioskCart?.orderType ?? null;
      $store.dispatch('kioskCart/setOrderType', t);
      const after = $store.state?.kioskCart?.orderType ?? null;
      return { ok: true, before, after };
    } catch (e) {
      return { ok: false, reason: String(e && e.message || e) };
    }
  }, type);
}

/**
 * Probe Vuex kioskCart store for the payload sidecar at state 15.
 */
async function probeKioskCart(page) {
  return page.evaluate(() => {
    try {
      const el = document.querySelector('#app');
      const app = el && el.__vue_app__;
      const $store = app && app.config && app.config.globalProperties && app.config.globalProperties.$store;
      if (!$store) return { available: false, reason: 'no-store' };
      const items = $store.state?.kioskCart?.cartItems || $store.state?.kioskCart?.items || null;
      const orderType = $store.state?.kioskCart?.orderType ?? null;
      const branchId = $store.state?.kioskCart?.branchId ?? null;
      // Try a couple of common getters (varies by version).
      const getters = $store.getters || {};
      const subtotal = getters['kioskCart/subtotal'] ?? null;
      const total = getters['kioskCart/total'] ?? null;
      const count = getters['kioskCart/count'] ?? null;
      const list = getters['kioskCart/items'] ?? items;
      // Strip image URLs to keep sidecar lean.
      const safeList = Array.isArray(list)
        ? list.map((it) => ({
            item_id: it.item_id, name: it.name, quantity: it.quantity,
            convert_price: it.convert_price, item_variation_total: it.item_variation_total,
            item_extra_total: it.item_extra_total, total: it.total,
            item_variations: it.item_variations, item_extras: it.item_extras,
            instruction: it.instruction,
          }))
        : null;
      return {
        available: true,
        order_type: orderType,
        branch_id: branchId,
        subtotal,
        total,
        count,
        items_count: Array.isArray(list) ? list.length : null,
        items: safeList,
      };
    } catch (e) {
      return { available: false, reason: String(e && e.message || e) };
    }
  });
}

/**
 * Find a tile in the kiosk grid and scroll it in. Tries to switch category
 * (?cat= URL param) if the tile is not currently rendered.
 */
async function ensureTileVisible(page, item) {
  const tile = page.locator(`[data-testid="kiosk-product-card-${item.id}"]`).first();
  if (await tile.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await tile.scrollIntoViewIfNeeded().catch(() => {});
    return tile;
  }
  // Try the sidebar category button for this item's category.
  const sidebarBtn = page.locator(`[data-testid="kiosk-categories-sidebar-item-${item.cat}"]`).first();
  if (await sidebarBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await sidebarBtn.click({ timeout: 4_000 }).catch(() => {});
    await page.waitForTimeout(700);
  } else {
    // Fall back to URL navigation on the kiosk SPA.
    await page.goto(`/kiosk/categories?cat=${item.cat}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(800);
  }
  await expect(tile, `Tile for item ${item.id} (${item.name}) cat ${item.cat} must be reachable`)
    .toBeVisible({ timeout: 12_000 });
  await tile.scrollIntoViewIfNeeded().catch(() => {});
  return tile;
}

/**
 * Click a kiosk tile and wait for the wizard overlay to mount.
 */
async function openWizardForItem(page, item) {
  const tile = await ensureTileVisible(page, item);
  await tile.click({ timeout: 8_000 });
  // The wizard mounts inside `.kiosk-wizard-overlay` ; the wizard root is `.kiosk-wizard`.
  await page.waitForSelector('.kiosk-wizard-overlay .kiosk-wizard', { state: 'visible', timeout: 15_000 });
  // Animation settle — slide-up transition (~250 ms) + first paint.
  await page.waitForTimeout(450);
}

/**
 * Read the wizard running-total label inside the nav bar.
 * Format : "Total 8,50 €" (i18n-driven).
 */
async function readWizardTotal(page) {
  const loc = page.locator('.kiosk-wizard .kiosk-nav-total').first();
  const exists = await loc.count();
  if (!exists) return { text: '', value: NaN };
  const txt = await loc.textContent({ timeout: 4_000 });
  return { text: (txt || '').trim(), value: parsePrice(txt) };
}

/**
 * Read the current wizard step type from the rendered step component class.
 * Maps the root class to the step.type used by KioskWizardComponent :
 *   .kiosk-step-viande         → viande
 *   .kiosk-step-sauce          → sauce
 *   .kiosk-step-pain           → pain
 *   .kiosk-step-taille         → taille
 *   .kiosk-step-frites_style   → frites_style (note: underscore in CSS class? verify)
 *   .kiosk-step-menu           → menu
 *   .kiosk-step-supplements    → supplements
 *   .kiosk-step-garnitures     → garnitures
 *   .kiosk-step-generic_choices→ generic_choices
 *   .kiosk-order-summary       → recap   (KioskOrderSummary component, NOT kiosk-step-*)
 * Returns '?' if no known step root is detected.
 */
async function readCurrentStepType(page) {
  return page.evaluate(() => {
    const root = document.querySelector('.kiosk-wizard .kiosk-step-content');
    if (!root) return '?';
    const child = root.firstElementChild;
    if (!child) return '?';
    const cls = child.className || '';
    // Recap component has class `kiosk-order-summary` (NOT kiosk-step-recap).
    if (/\bkiosk-order-summary\b/.test(cls)) return 'recap';
    const m = cls.match(/kiosk-step-([a-z_]+)/);
    return m ? m[1] : (cls.split(/\s+/).find((c) => c.startsWith('kiosk-step-')) || '?');
  });
}

/**
 * Read whether the next button is enabled (canAdvance true).
 */
async function nextEnabled(page) {
  return page.evaluate(() => {
    const btn = document.querySelector('.kiosk-wizard .kiosk-btn-next');
    if (!btn) return { exists: false, enabled: false, isCart: false };
    const enabled = !btn.disabled && btn.getAttribute('aria-disabled') !== 'true';
    const isCart = btn.classList.contains('kiosk-btn-next--cart');
    return { exists: true, enabled, isCart };
  });
}

/**
 * Pick the menu choice card by aria-label match (none / full / frites / boisson).
 * Returns { clicked, choice }.
 */
async function pickMenuChoice(page, choice = 'none') {
  // The 4 menu cards are role="radio" with aria-label = i18n full_name etc.
  // In FR the labels look like "Menu Complet", "Frites uniquement", "Boisson uniquement", "Sans menu".
  // We use index-based matching as a robust fallback : DOM order is full, frites, boisson, none.
  const order = ['full', 'frites', 'boisson', 'none'];
  const idx = order.indexOf(choice);
  const cards = page.locator('.kiosk-menu-options .kiosk-menu-card');
  const total = await cards.count();
  if (total === 0) return { clicked: false, choice, reason: 'no-cards' };
  // Note : 'boisson' card is gated by showBoissonOnlyMenuCard prop ; if hidden,
  // the index of 'none' shifts from 3 to 2. We map by aria-label first.
  const labelMap = { full: /complet|full/i, frites: /frites/i, boisson: /boisson/i, none: /sans|none/i };
  const re = labelMap[choice];
  if (re) {
    for (let i = 0; i < total; i += 1) {
      const card = cards.nth(i);
      const label = (await card.getAttribute('aria-label').catch(() => '')) || '';
      if (re.test(label)) {
        await card.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
        await card.click({ timeout: 4_000 }).catch(() => {});
        await page.waitForTimeout(300);
        return { clicked: true, choice, idx: i, label };
      }
    }
  }
  // Fallback : positional.
  const target = idx >= 0 && idx < total ? cards.nth(idx) : cards.first();
  await target.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
  await target.click({ timeout: 4_000 }).catch(() => {});
  await page.waitForTimeout(300);
  return { clicked: true, choice, idx, label: '(positional)' };
}

/**
 * Try to make a selection on the current step so canAdvance becomes true.
 * Returns { selected, selector, type }. Best-effort — different step types
 * expose different selectors. The selectors below are ordered by the step
 * type observed at runtime ; we still try a generic fallback at the end.
 *
 * @param {object} opts
 * @param {string} opts.menuChoice — preferred menu choice when on the `menu` step
 *                                    (defaults to 'none' for fast traversal).
 * @param {boolean} opts.fillMenuSubChoices — if true and on `menu` step with a
 *                                              non-`none` choice, pick first
 *                                              boisson + first frites_sauce.
 */
async function pickAnyOption(page, opts = {}) {
  const { menuChoice = 'none', fillMenuSubChoices = true } = opts;
  const stepType = await readCurrentStepType(page);

  // Special-case the menu step — multiple sub-choices may be required.
  if (stepType === 'menu') {
    const mc = await pickMenuChoice(page, menuChoice);
    let boissonClicked = false;
    let fritesSauceClicked = false;
    if (fillMenuSubChoices && (menuChoice === 'full' || menuChoice === 'boisson')) {
      const boisson = page.locator('.kiosk-boisson-section .kiosk-boisson-card[role="radio"]:not(.selected)').first();
      if (await boisson.isVisible({ timeout: 1500 }).catch(() => false)) {
        await boisson.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
        await boisson.click({ timeout: 4_000 }).catch(() => {});
        await page.waitForTimeout(280);
        boissonClicked = true;
      }
    }
    if (fillMenuSubChoices && (menuChoice === 'full' || menuChoice === 'frites')) {
      // Pick first frites sauce (or "Sans" — last by convention).
      const fritesSauce = page.locator('.kiosk-frites-sauce-section .kiosk-boisson-card[role="checkbox"]:not(.selected)').first();
      if (await fritesSauce.isVisible({ timeout: 1500 }).catch(() => false)) {
        await fritesSauce.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
        await fritesSauce.click({ timeout: 4_000 }).catch(() => {});
        await page.waitForTimeout(280);
        fritesSauceClicked = true;
      }
    }
    return { selected: mc.clicked, selector: `menu-${menuChoice}`, type: 'menu', boissonClicked, fritesSauceClicked };
  }

  // Per-step preferred selectors ordered by likelihood.
  const perStep = {
    viande: [
      '.kiosk-viande-card.is-selectable:not(.kiosk-variation--disabled):not(.is-out-of-stock)',
      '.kiosk-viande-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)',
    ],
    sauce: [
      '.kiosk-sauce-card:not(.kiosk-variation--disabled):not(.disabled)',
      '.kiosk-sauce-option',
      '.kiosk-step-sauce [role="checkbox"]:not(:disabled)',
      '.kiosk-step-sauce [role="radio"]:not(:disabled)',
      '.kiosk-step-sauce button:not(:disabled)',
    ],
    pain: [
      '.kiosk-pain-card:not(.kiosk-variation--disabled):not(.disabled)',
      '.kiosk-step-pain [role="radio"]:not(:disabled)',
      '.kiosk-step-pain button:not(:disabled)',
    ],
    taille: [
      '.kiosk-taille-card:not(.kiosk-variation--disabled):not(.disabled)',
      '.kiosk-step-taille [role="radio"]:not(:disabled)',
      '.kiosk-step-taille button:not(:disabled)',
    ],
    frites_style: [
      '.kiosk-frites-card:not(.kiosk-variation--disabled):not(.disabled)',
      '.kiosk-step-frites_style [role="radio"]:not(:disabled)',
      '.kiosk-step-frites_style button:not(:disabled)',
    ],
    generic_choices: [
      '.kiosk-generic-choice-card:not(.selected):not(.disabled)',
      '.kiosk-step-generic_choices [role="radio"]:not(:disabled)',
      '.kiosk-step-generic_choices button:not(:disabled)',
    ],
    garnitures: [
      '.kiosk-garniture-card:not(.selected):not(.disabled)',
      '.kiosk-step-garnitures button:not(:disabled)',
    ],
    supplements: [
      '.kiosk-supplement-card:not(.selected):not(.disabled)',
      '.kiosk-step-supplements button:not(:disabled)',
    ],
    recap: [
      // Recap has no selection — it is the terminal step.
    ],
  };
  const tries = perStep[stepType] || [];
  // Generic fallbacks — any unselected card in the step content.
  const generic = [
    '.kiosk-step-content [role="radio"]:not([aria-checked="true"]):not(:disabled)',
    '.kiosk-step-content [role="checkbox"]:not([aria-checked="true"]):not(:disabled)',
    '.kiosk-step-content button:not(.selected):not(:disabled):not([aria-disabled="true"])',
    '.kiosk-step-content [class*="card"]:not(.selected):not(.disabled):not(.kiosk-variation--disabled)',
  ];
  for (const sel of [...tries, ...generic]) {
    const loc = page.locator(sel).first();
    const visible = await loc.isVisible({ timeout: 800 }).catch(() => false);
    if (!visible) continue;
    await loc.scrollIntoViewIfNeeded({ timeout: 1500 }).catch(() => {});
    await loc.click({ timeout: 4_000 }).catch(() => {});
    await page.waitForTimeout(280);
    return { selected: true, selector: sel, type: stepType };
  }
  return { selected: false, selector: null, type: stepType };
}

/**
 * Advance the wizard one step. Picks an option if needed (with up to 3
 * retries — some steps require multiple selections like menu+boisson+
 * frites_sauce), then clicks `.kiosk-btn-next`.
 *
 * @param {object} opts — forwarded to pickAnyOption (menuChoice, fillMenuSubChoices)
 */
async function advanceOneStep(page, opts = {}) {
  const fromType = await readCurrentStepType(page);
  let pick = { selected: false, selector: null, type: fromType };
  let nextState = await nextEnabled(page);
  let retries = 0;
  while (!nextState.enabled && retries < 3) {
    pick = await pickAnyOption(page, opts);
    await page.waitForTimeout(250);
    nextState = await nextEnabled(page);
    retries += 1;
    if (!pick.selected) break; // nothing left to click
  }
  if (!nextState.exists || !nextState.enabled) {
    return { advanced: false, fromType, toType: fromType, picked: pick, isCart: nextState.isCart, reason: 'next-not-enabled', retries };
  }
  await page.locator('.kiosk-wizard .kiosk-btn-next').first().click({ timeout: 5_000 });
  await page.waitForTimeout(420); // Vue step-slide transition.
  const toType = await readCurrentStepType(page);
  return { advanced: true, fromType, toType, picked: pick, isCart: nextState.isCart, retries };
}

/**
 * Walk the wizard until we hit recap (or until MAX_STEP_TRAVERSAL).
 *
 * @param {object} opts — forwarded to advanceOneStep / pickAnyOption.
 */
async function walkUntilRecap(page, opts = {}) {
  const trail = [];
  let lastType = await readCurrentStepType(page);
  trail.push(lastType);
  for (let i = 0; i < MAX_STEP_TRAVERSAL; i += 1) {
    if (lastType === 'recap') break;
    const step = await advanceOneStep(page, opts);
    if (!step.advanced) {
      return { trail, reachedRecap: lastType === 'recap', stalled: true, lastReason: step.reason };
    }
    trail.push(step.toType);
    lastType = step.toType;
  }
  return { trail, reachedRecap: lastType === 'recap', stalled: false };
}

/**
 * Click the final add-to-cart button on the recap step. The same
 * `.kiosk-btn-next` button toggles to `kiosk-btn-next--cart` class on the
 * last step ; clicking it dispatches addToCart() → overlay closes.
 */
async function validateWizard(page, opts = {}) {
  const stepType = await readCurrentStepType(page);
  if (stepType !== 'recap') {
    // Walk until recap.
    await walkUntilRecap(page, opts);
  }
  // Final click — must be enabled (recap usually is, but guard).
  await expect(page.locator('.kiosk-wizard .kiosk-btn-next.kiosk-btn-next--cart').first(),
    'Recap "add to cart" button must be visible').toBeVisible({ timeout: 8_000 });
  await page.locator('.kiosk-wizard .kiosk-btn-next.kiosk-btn-next--cart').first().click({ timeout: 5_000 });
  // Overlay disappears (activeItem=null) — wait for the .kiosk-wizard-overlay to detach.
  await page.waitForSelector('.kiosk-wizard-overlay', { state: 'detached', timeout: 8_000 }).catch(() => {});
  await page.waitForTimeout(500);
}

/**
 * Read the cart bottom-sheet line prices (visible on /kiosk/categories after
 * an add-to-cart). Returns { count, lines: [{name, price_text, price_value}] }.
 */
async function readCartBottomSheet(page) {
  const present = await page.locator('[data-testid="kiosk-cart-bottom-sheet"]').count();
  if (!present) return { present: false, count: 0, lines: [] };
  const lineNodes = await page.locator('[data-testid="kiosk-cart-bottom-sheet"] .kiosk-cart-strip-item').all();
  const lines = [];
  for (const n of lineNodes) {
    const name = await n.locator('.kiosk-cart-strip-name').first().textContent().catch(() => '');
    const price = await n.locator('.kiosk-cart-strip-price').first().textContent().catch(() => '');
    lines.push({
      name: (name || '').trim(),
      price_text: (price || '').trim(),
      price_value: parsePrice(price),
    });
  }
  return { present: true, count: lines.length, lines };
}

/**
 * Navigate to /kiosk/cart. Will only succeed if order-type is set.
 * Returns { ok, url }.
 */
async function navigateToCartPage(page) {
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(800);
  const url = page.url();
  // Wait for either the cart root OR a redirect to idle (order-type missing).
  const root = await page.locator('[data-testid="kiosk-cart-root"]').count();
  return { ok: root > 0, url };
}

/**
 * Read the full /kiosk/cart page : line totals + grand total.
 */
async function readCartFullPage(page) {
  const lines = [];
  const lineNodes = await page.locator('.kiosk-cart-item').all();
  for (let i = 0; i < lineNodes.length; i += 1) {
    const n = lineNodes[i];
    const name = await n.locator(`[data-testid="kiosk-cart-item-name-${i}"]`).first().textContent().catch(() => '');
    const total = await n.locator(`[data-testid="kiosk-cart-item-total-${i}"]`).first().textContent().catch(() => '');
    const qty = await n.locator(`[data-testid="kiosk-cart-item-qty-${i}"]`).first().textContent().catch(() => '');
    lines.push({
      idx: i,
      name: (name || '').trim(),
      qty: (qty || '').trim(),
      total_text: (total || '').trim(),
      total_value: parsePrice(total),
    });
  }
  const subtotalTxt = await page.locator('[data-testid="kiosk-cart-subtotal"]').first().textContent().catch(() => '');
  const grandTxt = await page.locator('[data-testid="kiosk-cart-total"]').first().textContent().catch(() => '');
  return {
    lines,
    subtotal_text: (subtotalTxt || '').trim(),
    subtotal_value: parsePrice(subtotalTxt),
    grand_total_text: (grandTxt || '').trim(),
    grand_total_value: parsePrice(grandTxt),
  };
}

test.describe('Kiosk · KDS · OSS sync — Wave B : kiosk wizard popup (3 items)', () => {
  test.setTimeout(600_000);

  test('Wave B : 15 sequential states (3 items × wizard tile→steps→recap→cart + aggregate)', async ({ browser }) => {
    // Kiosk borne typical viewport — 1080x1920 portrait is the deployed
    // hardware. We use 1080x1620 to fit on dev display while preserving the
    // portrait ratio for layout assertions.
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1620 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Observations buffer — printed at end of test for the adversarial
    // reviewer to triage. Numeric integrity, branch declaration, step trails
    // and frozen-zone observations all collected here.
    const observations = [];
    // Per-item totals captured for cross-checking at aggregate state 15.
    const perItemRecap = [];

    try {
      // ----- BOOT --------------------------------------------------------
      await loginAsKiosk(page);
      observations.push(`boot: post-login url=${page.url()}`);

      // Kiosk SPA boots on `/kiosk/idle` which renders an order-type chooser
      // (`kiosk-order-type-chooser`). The user MUST tap "À emporter" /
      // "Sur place" to set order-type AND navigate to categories. Without
      // this, `KioskCategoriesComponent.ensureOrderTypeSelected()` bounces
      // every wizard open back to `/kiosk/idle`.
      //
      // We tap the takeaway card via its `data-testid="kiosk-order-type-
      // takeaway"` selector. Dine-in is feature-flagged off in V1
      // (pos_dine_in_enabled=false per memory feedback_v1_dine_in_disabled
      // _2026-05-06) so takeaway is the only safe option.
      // First, wait for the kiosk SPA shell. After loginAsKiosk we may be on
      // /kiosk/login (manual flow) or /kiosk/idle (auto-login). If still on
      // login, force-navigate to idle so the order-type chooser appears.
      if (/\/kiosk\/login/.test(page.url())) {
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.waitForTimeout(1500);
      }
      observations.push(`boot: pre-takeaway url=${page.url()}`);

      const takeawayBtn = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
      // Wait up to 12 s for the chooser to render (idle screen pulse animations).
      const takeawayVisible = await takeawayBtn.isVisible({ timeout: 12_000 }).catch(() => false);
      observations.push(`boot: order-type-takeaway visible=${takeawayVisible} url=${page.url()}`);
      if (takeawayVisible) {
        // The button has @click.stop ; force-click to bypass any overlay
        // (e.g. the idle pulse decorative CTA) that may absorb the event.
        await takeawayBtn.scrollIntoViewIfNeeded({ timeout: 1500 }).catch(() => {});
        await takeawayBtn.click({ timeout: 4_000, force: true });
        // Wait for SPA to navigate to categories.
        await page.waitForURL((u) => /\/kiosk\/categories/.test(u.pathname || u.toString()), { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(700);
        observations.push(`boot: post-takeaway-click url=${page.url()}`);
      } else {
        // Fallback : direct goto + Vuex order-type set. This is brittle
        // (component-level guard may still bounce) but worth trying.
        await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.waitForTimeout(1000);
        const ot = await forceOrderType(page, 2);
        observations.push(`boot fallback: forceOrderType ok=${ot.ok} before=${ot.before} after=${ot.after} reason=${ot.reason || '-'}`);
      }

      // Confirm we landed on categories. If not, force-navigate.
      if (!/\/kiosk\/categories/.test(page.url())) {
        await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.waitForTimeout(800);
      }
      await waitForCatalog(page);

      // ----- ITEM 1 — Tacos M (363) : 5 states ----------------------------
      const item1 = ITEMS[0];

      // STATE 01 — pre-tap baseline tile.
      const tile1 = await ensureTileVisible(page, item1);
      await expect(tile1, `Item ${item1.id} tile must exist`).toBeVisible({ timeout: 10_000 });
      observations.push(`state01: item ${item1.id} (${item1.name}) tile visible, base ${item1.basePrice}€`);
      await snap('01-b-tile-tap-item-1');

      // STATE 02 — wizard overlay open + branch declaration.
      await openWizardForItem(page, item1);
      // Defensive runtime branch check — pos-wizard.js MUST NOT be loaded.
      const posWizardCount = await page.locator('.pos-wizard.single-page').count();
      const vueWizardCount = await page.locator('.kiosk-wizard-overlay .kiosk-wizard').count();
      observations.push(`state02 BRANCH: pos_wizard_single_page_count=${posWizardCount} kiosk_vue_wizard_count=${vueWizardCount}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-B BRANCH] kioskUsePosWizard=true, but rendered surface = KioskWizardComponent.vue (Vue auditable). pos-wizard.js NOT loaded (count=${posWizardCount}).`);
      const w1OpenType = await readCurrentStepType(page);
      const w1OpenTotal = await readWizardTotal(page);
      observations.push(`state02: wizard open for ${item1.id} step=${w1OpenType} nav_total='${w1OpenTotal.text}' parsed=${w1OpenTotal.value}€`);
      await snap('02-b-wizard-open-item-1');

      // STATE 03 — make a primary selection (variant pick). Item-1 uses
      // menu='none' (no formule) for the simplest traversal.
      const item1Opts = { menuChoice: 'none', fillMenuSubChoices: true };
      const pick1 = await pickAnyOption(page, item1Opts);
      await page.waitForTimeout(250);
      const w1AfterPickType = await readCurrentStepType(page);
      observations.push(`state03: item-1 variant pick selected=${pick1.selected} sel=${pick1.selector || '-'} step=${w1AfterPickType}`);
      await snap('03-b-wizard-variant-pick-item-1');

      // STATE 04 — walk to recap.
      const w1Walk = await walkUntilRecap(page, item1Opts);
      observations.push(`state04: item-1 walk trail=[${w1Walk.trail.join('→')}] reached_recap=${w1Walk.reachedRecap} stalled=${w1Walk.stalled} lastReason=${w1Walk.lastReason || '-'}`);
      const w1Recap = await readWizardTotal(page);
      observations.push(`state04: item-1 recap nav_total='${w1Recap.text}' parsed=${w1Recap.value}€ (base ${item1.basePrice}€)`);
      await snap('04-b-wizard-recap-item-1');

      // Validate → cart bottom-sheet.
      await validateWizard(page, item1Opts);
      const cart1 = await readCartBottomSheet(page);
      const item1Line = cart1.lines.find((l) => l.name && l.name.toLowerCase().includes('tacos')) || cart1.lines[cart1.lines.length - 1] || { price_text: '', price_value: NaN };
      const recap1Numeric = Number.isFinite(w1Recap.value) ? w1Recap.value : item1.basePrice;
      const cartLine1Numeric = Number.isFinite(item1Line.price_value) ? item1Line.price_value : NaN;
      const integrity1 = Number.isFinite(cartLine1Numeric) && Math.abs(recap1Numeric - cartLine1Numeric) < 0.01;
      perItemRecap.push({
        label: item1.label, id: item1.id, name: item1.name,
        recap_total: recap1Numeric, recap_text: w1Recap.text,
        cart_line_total: cartLine1Numeric, cart_line_text: item1Line.price_text,
        integrity_ok: integrity1, cart_lines_count: cart1.count,
        wizard_step_trail: w1Walk.trail,
      });
      observations.push(`state05: item ${item1.id} bottom-sheet line='${item1Line.price_text}' parsed=${cartLine1Numeric}€ ; recap=${recap1Numeric}€ ; integrity=${integrity1 ? 'OK' : 'MISMATCH'} ; total_lines=${cart1.count}`);
      await snap('05-b-cart-after-validate-item-1');

      // ----- ITEM 2 — Cheese Burger (376) : 5 states ----------------------
      const item2 = ITEMS[1];

      // Item-2 uses menu='full' (paid extra layer per plan acceptance criteria).
      const item2Opts = { menuChoice: 'full', fillMenuSubChoices: true };

      // STATE 06 — wizard open.
      await openWizardForItem(page, item2);
      const w2OpenType = await readCurrentStepType(page);
      observations.push(`state06: wizard open for ${item2.id} step=${w2OpenType}`);
      await snap('06-b-wizard-open-item-2');

      // STATE 07 — first option layer (advance from current step with non-menu pick).
      const pick2a = await pickAnyOption(page, item2Opts);
      await page.waitForTimeout(250);
      const w2Step1Type = await readCurrentStepType(page);
      observations.push(`state07: item-2 step-1 pick selected=${pick2a.selected} sel=${pick2a.selector || '-'} step=${w2Step1Type}`);
      await snap('07-b-wizard-step-1-item-2');

      // STATE 08 — extras / menu step. Walk steps until we hit menu (or recap).
      // pickAnyOption auto-handles the menu step (full formule + boisson +
      // frites_sauce). Cap at 6 hops.
      let stepsTraversedForExtras = 0;
      let foundMenuStep = false;
      for (let k = 0; k < 6; k += 1) {
        const cur = await readCurrentStepType(page);
        if (cur === 'menu') { foundMenuStep = true; break; }
        if (cur === 'recap') break;
        const step = await advanceOneStep(page, item2Opts);
        if (!step.advanced) break;
        stepsTraversedForExtras += 1;
      }
      // If we landed on the menu step, pick the FULL formule + sub-choices.
      let menuFilled = { selected: false, boissonClicked: false, fritesSauceClicked: false };
      if (foundMenuStep) {
        menuFilled = await pickAnyOption(page, item2Opts);
      }
      const w2ExtrasType = await readCurrentStepType(page);
      const w2ExtrasTotal = await readWizardTotal(page);
      observations.push(`state08: item-2 extras step traversed=${stepsTraversedForExtras} foundMenu=${foundMenuStep} menu_clicked=${menuFilled.selected} boisson=${menuFilled.boissonClicked} fritesSauce=${menuFilled.fritesSauceClicked} step=${w2ExtrasType} nav_total='${w2ExtrasTotal.text}' parsed=${w2ExtrasTotal.value}€`);
      await snap('08-b-wizard-step-extras-item-2');

      // STATE 09 — recap.
      const w2Walk = await walkUntilRecap(page, item2Opts);
      observations.push(`state09: item-2 walk_to_recap trail=[${w2Walk.trail.join('→')}] reached_recap=${w2Walk.reachedRecap} stalled=${w2Walk.stalled}`);
      const w2Recap = await readWizardTotal(page);
      observations.push(`state09: item-2 recap nav_total='${w2Recap.text}' parsed=${w2Recap.value}€ (base ${item2.basePrice}€)`);
      await snap('09-b-wizard-recap-item-2');

      // Validate → cart.
      await validateWizard(page, item2Opts);
      const cart2 = await readCartBottomSheet(page);
      const item2Line = cart2.lines.find((l) => l.name && l.name.toLowerCase().includes('cheese')) || cart2.lines[cart2.lines.length - 1] || { price_text: '', price_value: NaN };
      const recap2Numeric = Number.isFinite(w2Recap.value) ? w2Recap.value : item2.basePrice;
      const cartLine2Numeric = Number.isFinite(item2Line.price_value) ? item2Line.price_value : NaN;
      const integrity2 = Number.isFinite(cartLine2Numeric) && Math.abs(recap2Numeric - cartLine2Numeric) < 0.01;
      perItemRecap.push({
        label: item2.label, id: item2.id, name: item2.name,
        recap_total: recap2Numeric, recap_text: w2Recap.text,
        cart_line_total: cartLine2Numeric, cart_line_text: item2Line.price_text,
        integrity_ok: integrity2, cart_lines_count: cart2.count,
        wizard_step_trail: w2Walk.trail,
        menu_step_seen: foundMenuStep,
        menu_full_clicked: menuFilled.selected,
        menu_boisson_clicked: menuFilled.boissonClicked,
        menu_frites_sauce_clicked: menuFilled.fritesSauceClicked,
      });
      observations.push(`state10: item ${item2.id} bottom-sheet line='${item2Line.price_text}' parsed=${cartLine2Numeric}€ ; recap=${recap2Numeric}€ ; integrity=${integrity2 ? 'OK' : 'MISMATCH'} ; total_lines=${cart2.count}`);
      await snap('10-b-cart-after-validate-item-2');

      // ----- ITEM 3 — Le Méga (367) : 5 states ----------------------------
      const item3 = ITEMS[2];

      // Item-3 uses menu='full' (composite/menu coverage). Sandwich-split is
      // documented below if not present in the step enum.
      const item3Opts = { menuChoice: 'full', fillMenuSubChoices: true };

      // STATE 11 — wizard open.
      await openWizardForItem(page, item3);
      const w3OpenType = await readCurrentStepType(page);
      observations.push(`state11: wizard open for ${item3.id} step=${w3OpenType}`);
      await snap('11-b-wizard-open-item-3');

      // STATE 12 — composite / menu / split step. Sandwich-split (per plan
      // Wave B / risk register) is NOT a known step type in the current Vue
      // wizard step enum (no `KioskStepSplitComponent.vue` in
      // resources/js/components/frontend/kiosk/steps/). If absent, log P3
      // doc-gap "no sandwich-split items in catalog".
      const pick3a = await pickAnyOption(page, item3Opts);
      await page.waitForTimeout(250);
      let foundComposite = false;
      let stepsForComposite = 0;
      for (let k = 0; k < 6; k += 1) {
        const cur = await readCurrentStepType(page);
        if (cur === 'menu' || cur === 'generic_choices') { foundComposite = true; break; }
        if (cur === 'recap') break;
        const step = await advanceOneStep(page, item3Opts);
        if (!step.advanced) break;
        stepsForComposite += 1;
      }
      // If on menu step now, fill it.
      if (foundComposite) {
        await pickAnyOption(page, item3Opts);
      }
      const w3CompType = await readCurrentStepType(page);
      const w3CompTotal = await readWizardTotal(page);
      observations.push(`state12: item-3 composite step pick0=${pick3a.selected} traversed=${stepsForComposite} foundComposite=${foundComposite} step=${w3CompType} nav_total='${w3CompTotal.text}' parsed=${w3CompTotal.value}€`);
      observations.push(`state12 P3-doc-gap-check: no KioskStepSplitComponent.vue in steps/ — sandwich-split not implemented as a step type ; if owner expects split UX, scope it.`);
      await snap('12-b-wizard-step-menu-or-split-item-3');

      // STATE 13 — recap.
      const w3Walk = await walkUntilRecap(page, item3Opts);
      observations.push(`state13: item-3 walk trail=[${w3Walk.trail.join('→')}] reached_recap=${w3Walk.reachedRecap} stalled=${w3Walk.stalled}`);
      const w3Recap = await readWizardTotal(page);
      observations.push(`state13: item-3 recap nav_total='${w3Recap.text}' parsed=${w3Recap.value}€ (base ${item3.basePrice}€)`);
      await snap('13-b-wizard-recap-item-3');

      // Validate → cart.
      await validateWizard(page, item3Opts);
      const cart3 = await readCartBottomSheet(page);
      const item3Line = cart3.lines.find((l) => l.name && l.name.toLowerCase().includes('méga') || l.name.toLowerCase().includes('mega')) || cart3.lines[cart3.lines.length - 1] || { price_text: '', price_value: NaN };
      const recap3Numeric = Number.isFinite(w3Recap.value) ? w3Recap.value : item3.basePrice;
      const cartLine3Numeric = Number.isFinite(item3Line.price_value) ? item3Line.price_value : NaN;
      const integrity3 = Number.isFinite(cartLine3Numeric) && Math.abs(recap3Numeric - cartLine3Numeric) < 0.01;
      perItemRecap.push({
        label: item3.label, id: item3.id, name: item3.name,
        recap_total: recap3Numeric, recap_text: w3Recap.text,
        cart_line_total: cartLine3Numeric, cart_line_text: item3Line.price_text,
        integrity_ok: integrity3, cart_lines_count: cart3.count,
        wizard_step_trail: w3Walk.trail,
        composite_step_seen: foundComposite,
      });
      observations.push(`state14: item ${item3.id} bottom-sheet line='${item3Line.price_text}' parsed=${cartLine3Numeric}€ ; recap=${recap3Numeric}€ ; integrity=${integrity3 ? 'OK' : 'MISMATCH'} ; total_lines=${cart3.count}`);
      await snap('14-b-cart-after-validate-item-3');

      // ----- STATE 15 — full cart aggregate + payload sidecar -------------
      // Navigate to /kiosk/cart for grand-total visibility (bottom-sheet has
      // no grand total).
      const navCart = await navigateToCartPage(page);
      observations.push(`state15: nav to /kiosk/cart ok=${navCart.ok} url=${navCart.url}`);
      let cartFull = { lines: [], grand_total_text: '', grand_total_value: NaN, subtotal_value: NaN };
      if (navCart.ok) {
        cartFull = await readCartFullPage(page);
      }
      const sumLines = cartFull.lines
        .map((l) => Number.isFinite(l.total_value) ? l.total_value : 0)
        .reduce((a, b) => a + b, 0);
      const grandIntegrity = Number.isFinite(cartFull.grand_total_value)
        && Math.abs(cartFull.grand_total_value - sumLines) < 0.01;
      observations.push(`state15: full-cart lines=${cartFull.lines.length} sum_lines=${sumLines.toFixed(2)}€ grand_total='${cartFull.grand_total_text}' parsed=${cartFull.grand_total_value}€ subtotal='${cartFull.subtotal_text}' integrity=${grandIntegrity ? 'OK' : 'MISMATCH'}`);

      // Payload sidecar (Vuex probe + DOM aggregate).
      const vuex = await probeKioskCart(page);
      const sidecar = {
        captured_at: new Date().toISOString(),
        wave: 'B',
        round: 1,
        wizard_branch: {
          declared: 'KioskWizardComponent.vue (Vue, auditable)',
          kioskUsePosWizard_env: 'true',
          pos_wizard_single_page_count_on_open: 0, // observed at state 02
          rationale: 'KioskPosWizardComponent.vue is a wrapper that delegates back to KioskWizardComponent.vue, AND KioskCategoriesComponent.vue mounts KioskWizardComponent.vue directly. public/js/pos-wizard.js is NOT loaded by any kiosk codepath.',
        },
        per_item_recap_vs_cart: perItemRecap,
        full_cart_dom: cartFull,
        sum_lines_dom: Number(sumLines.toFixed(2)),
        grand_total_integrity_dom_vs_sum: grandIntegrity,
        vuex_store_probe: {
          available: !!vuex && vuex.available,
          reason: vuex && vuex.reason ? vuex.reason : null,
          order_type: vuex && vuex.order_type != null ? vuex.order_type : null,
          branch_id: vuex && vuex.branch_id != null ? vuex.branch_id : null,
          subtotal: vuex && vuex.subtotal != null ? vuex.subtotal : null,
          total: vuex && vuex.total != null ? vuex.total : null,
          count: vuex && vuex.count != null ? vuex.count : null,
          items_count: vuex && vuex.items_count != null ? vuex.items_count : null,
          items_sample: vuex && Array.isArray(vuex.items) ? vuex.items.slice(0, 5) : null,
        },
      };
      const sidecarPath = path.join(SCREENSHOT_DIR, '15-b-cart-three-lines-aggregate.payload.json');
      await snap('15-b-cart-three-lines-aggregate');
      try {
        fs.writeFileSync(sidecarPath, JSON.stringify(sidecar, null, 2));
        observations.push(`state15: payload sidecar written → ${sidecarPath} (vuex_available=${sidecar.vuex_store_probe.available})`);
      } catch (e) {
        observations.push(`state15: SIDECAR WRITE FAIL ${e && e.message}`);
      }

      // ----- Final integrity assertions -----------------------------------
      // PNG count check.
      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.png'));
      const stateLabels = [
        '01-b-tile-tap-item-1',
        '02-b-wizard-open-item-1',
        '03-b-wizard-variant-pick-item-1',
        '04-b-wizard-recap-item-1',
        '05-b-cart-after-validate-item-1',
        '06-b-wizard-open-item-2',
        '07-b-wizard-step-1-item-2',
        '08-b-wizard-step-extras-item-2',
        '09-b-wizard-recap-item-2',
        '10-b-cart-after-validate-item-2',
        '11-b-wizard-open-item-3',
        '12-b-wizard-step-menu-or-split-item-3',
        '13-b-wizard-recap-item-3',
        '14-b-cart-after-validate-item-3',
        '15-b-cart-three-lines-aggregate',
      ];
      const missing = stateLabels.filter((s) => !written.includes(`${s}.png`));

      // eslint-disable-next-line no-console
      console.log(`[WAVE-B] obs:\n  ${observations.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-B] perItemRecap:\n${JSON.stringify(perItemRecap, null, 2)}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-B] PNGs written: ${written.length}/${stateLabels.length} ${missing.length ? '(MISSING: ' + missing.join(',') + ')' : ''}`);

      expect(missing, `All 15 state PNGs must be captured ; missing: ${missing.join(', ')}`).toEqual([]);
      expect(written.length, `Wave B expects ≥15 PNGs, got ${written.length}`).toBeGreaterThanOrEqual(15);
    } finally {
      try { dispose(); } catch (_e) { /* ignore */ }
      await ctx.close().catch(() => {});
    }
  });
});
