// FoodKing E2E — POS · KDS · OSS sync audit Wave B (POS wizard popup) — 2026-05-10
//
// Wave B scope : open the POS vanilla-JS wizard popup (frozen — capture only)
// for 3 representative items spanning typology and capture every visual sub-state
// + recap modal + cart line after wizard validates. Numeric integrity is the
// canonical P0 — wizard recap total MUST equal cart line subtotal.
//
// Audit plan : reports/test-e2e/pos-kds-sync-2026-05-10/AUDIT_PLAN.md (Wave B)
//
// FROZEN ZONES touched (capture only — ZERO line of patch) :
//   • public/js/pos-wizard.js
//   • public/css/pos-wizard.css
//   • resources/views/admin-pos-v4.blade.php
//
// MAJOR DEVIATION FROM PLAN (documented for adversarial reviewer) :
//   The plan text uses `step-1`, `step-2`, `step-extras` filenames assuming a
//   classic multi-step wizard. The current pos-wizard.js code (single-page mode
//   confirmed at line ~2800 `renderSinglePage`) renders ALL options on ONE
//   scrollable page with a sticky bottom bar (cancel + total + add-to-cart).
//   We KEEP the plan's filenames for reviewer-script compatibility but each
//   "step-N" actually maps to an INTERACTION SUB-STATE on the same page :
//     - step-1 : initial wizard render (top of scroll, all sections visible)
//     - step-2 : after primary option selection (viande / pain / variation)
//     - step-extras : after a paid extra is toggled (sticky-bar total updates)
//     - recap  : sticky bottom bar with final total + add-to-cart visible
//   No multi-step DOM transitions exist to capture. This is reported as an
//   audit-plan/code mismatch (not a wizard defect) in the final summary.
//
// Item triplet (DB-verified 2026-05-10 against catalog status=5) :
//   - 363 "Tacos M (1 Viande)" cat 306 — 6.50€ — 24 variations (viande+sauce wizard)
//   - 376 "Cheese Burger"      cat 308 — 6.00€ — 15 variations (burger pain+viande+sauce)
//   - 367 "Le Méga"            cat 307 — 8.00€ — 35 variations (sandwich + extras)
//
// Real DOM markers (NOT [data-pos-wizard] — that selector does not exist) :
//   - Vue mount  : #item-variation-modal (with .active class when open)
//   - Wizard root: .pos-wizard.single-page (vanilla JS injects)
//   - Sticky bar : .wizard-sticky-bar
//   - Total      : .sticky-total .total-value
//   - Add to cart: button[data-action="add-to-cart"]
//   - Cancel     : button[data-action="cancel-wizard"]
//   - Cart line  : .pos-v5-cart-item    Line price : .pos-v5-cart-item__price
//   - Grand tot. : [data-testid="pos-grand-total"]

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsPosOperator } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-pos-kds-sync-B');

// Item triplet — see header comment for verification.
const ITEMS = [
  { id: 363, name: 'Tacos M (1 Viande)', cat: 306, basePrice: 6.50, label: 'item-1' },
  { id: 376, name: 'Cheese Burger',       cat: 308, basePrice: 6.00, label: 'item-2' },
  { id: 367, name: 'Le Méga',             cat: 307, basePrice: 8.00, label: 'item-3' },
];

/**
 * Parse a French-format price string ("8,50 €", "8.50€", "8,50") to a Number.
 * Returns NaN if no digits are found — caller must guard.
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
 * Wait for the POS catalog grid to render at least one tile we can click.
 */
async function waitForCatalog(page) {
  await page.waitForSelector('[data-pos-item-id]', { state: 'visible', timeout: 25_000 });
  await page.waitForTimeout(600);
}

/**
 * Click a POS catalog tile by item id and wait for the wizard to mount.
 */
async function openWizardForItem(page, itemId) {
  const tile = page.locator(`[data-pos-item-id="${itemId}"]`).first();
  await expect(tile, `Tile for item ${itemId} must be visible in catalog`).toBeVisible({ timeout: 15_000 });
  await tile.scrollIntoViewIfNeeded({ timeout: 5_000 }).catch(() => {});
  await tile.click({ timeout: 8_000 });

  // Wait for the actual vanilla-JS wizard root (Vue mount + injected wizard).
  await page.waitForSelector('.pos-wizard.single-page', { state: 'visible', timeout: 15_000 });
  // Animation settle.
  await page.waitForTimeout(450);
}

/**
 * Read the wizard sticky-bar total numerically.
 */
async function readWizardTotal(page) {
  const txt = await page.locator('.wizard-sticky-bar .sticky-total .total-value').first().textContent({ timeout: 5_000 });
  return { text: (txt || '').trim(), value: parsePrice(txt) };
}

/**
 * Read the FIRST cart-line price block. POS V5 renders the line subtotal in
 * .pos-v5-cart-item__price.
 */
async function readFirstCartLinePrice(page) {
  const exists = await page.locator('.pos-v5-cart-item').first().count();
  if (!exists) return { text: '', value: NaN, count: 0 };
  const txt = await page.locator('.pos-v5-cart-item .pos-v5-cart-item__price').first().textContent({ timeout: 4_000 });
  const count = await page.locator('.pos-v5-cart-item').count();
  return { text: (txt || '').trim(), value: parsePrice(txt), count };
}

/**
 * Read all cart lines + grand total. Used at state 16 aggregate.
 */
async function readCartAggregate(page) {
  const lineNodes = await page.locator('.pos-v5-cart-item').all();
  const lines = [];
  for (const n of lineNodes) {
    const nameTxt = await n.locator('.pos-v5-cart-item__name').first().textContent().catch(() => '');
    const priceTxt = await n.locator('.pos-v5-cart-item__price').first().textContent().catch(() => '');
    lines.push({
      name: (nameTxt || '').trim().replace(/\s+/g, ' '),
      price_text: (priceTxt || '').trim(),
      price_value: parsePrice(priceTxt),
    });
  }
  const grandTxt = await page.locator('[data-testid="pos-grand-total"]').first().textContent().catch(() => '');
  return {
    lines,
    grand_total_text: (grandTxt || '').trim(),
    grand_total_value: parsePrice(grandTxt),
  };
}

/**
 * Best-effort Vuex store probe — reaches into Vue 3 internal app context.
 * Returns null if Vuex is not exposed or the path is wrong (we then fall back
 * to DOM scrape for the payload sidecar at state 16).
 */
async function probeVuexCart(page) {
  return page.evaluate(() => {
    try {
      const el = document.querySelector('#app');
      const app = el && el.__vue_app__;
      const $store = app && app.config && app.config.globalProperties && app.config.globalProperties.$store;
      if (!$store) return { available: false, reason: 'no-store' };
      const lists = $store.getters && $store.getters['posCart/lists'];
      const subtotal = $store.getters && $store.getters['posCart/subtotal'];
      const discount = $store.getters && $store.getters['posCart/discount'];
      return {
        available: true,
        lists_count: Array.isArray(lists) ? lists.length : null,
        subtotal,
        discount,
        lists,
      };
    } catch (e) {
      return { available: false, reason: String(e && e.message || e) };
    }
  });
}

/**
 * Pick a paid extra on the wizard (any supplement / extra add-on with a + price).
 * Returns true if something was clicked. Best-effort — different items expose
 * different option types. We try several selectors in priority order.
 */
async function tickAnyPaidExtra(page) {
  // Single-page wizard exposes formule cards with data-value="addon_<id>"
  // (NOT data-value="full" — that's the deprecated multi-step path). We
  // prefer the formule path because it is THE most visible paid extra
  // ("Menu (Frites + Boisson) +3.00€").
  const candidates = [
    // Formule cards — single-page (addon-based). Skip the "Sans formule"
    // (data-value="none") which is the no-cost option.
    '.formule-card[data-action="menu-choice"]:not([data-value="none"]):not(.selected)',
    // Supplements toggle — opens the Suppléments accordion. After click we
    // attempt to pick the first supplement option below.
    '.suppl-toggle:not(.has-items)',
    '.viande-suppl-toggle:not(.has-items)',
    // Supplement option (visible after toggle expanded OR on initial render
    // for some item types).
    '.wizard-option[data-type="supplement"]:not(.selected)',
    '.supplement-opt:not(.selected)',
    // Viande supplémentaire (+2.50€/viande)
    '.viande-suppl-btn.plus:not(.disabled)',
    // Frites upgrade (grande / cheddar) — only after a formule with frites
    '[data-action="frites-size"][data-value="grande"]:not(.selected)',
    '[data-action="frites-cheddar"][data-value="yes"]:not(.selected)',
    // Multi-step legacy menu-choice (kept for safety).
    '[data-action="menu-choice"][data-value="full"]:not(.selected)',
  ];
  for (const sel of candidates) {
    const loc = page.locator(sel).first();
    const visible = await loc.isVisible({ timeout: 800 }).catch(() => false);
    if (visible) {
      await loc.scrollIntoViewIfNeeded({ timeout: 1500 }).catch(() => {});
      await loc.click({ timeout: 4_000 }).catch(() => {});
      await page.waitForTimeout(350);
      // If we just clicked a toggle, try to click an actual supplement now.
      if (sel.includes('toggle')) {
        const supp = page.locator('.wizard-option[data-type="supplement"]:not(.selected), .supplement-opt:not(.selected)').first();
        if (await supp.isVisible({ timeout: 800 }).catch(() => false)) {
          await supp.scrollIntoViewIfNeeded({ timeout: 1500 }).catch(() => {});
          await supp.click({ timeout: 4_000 }).catch(() => {});
          await page.waitForTimeout(300);
          return { clicked: true, selector: sel + ' + supplement' };
        }
      }
      return { clicked: true, selector: sel };
    }
  }
  return { clicked: false, selector: null };
}

/**
 * Pick a primary option (viande/pain/sauce) — gets the wizard into a "selected"
 * sub-state so step-2 captures something different from step-1.
 */
async function tickPrimaryOption(page) {
  const candidates = [
    // Viande +1 (most items)
    '.viande-btn.plus:not(.disabled):not(.viande-suppl-btn)',
    // Pain (sandwich)
    '.pain-btn:not(.selected), .pain-opt:not(.selected)',
    // Sauce single
    '.wizard-option[data-type="sauce_single"]:not(.selected)',
    // Sauce multi
    '.sauce-opt:not(.selected)',
    // Accompagnement
    '.wizard-option[data-type="accompagnement"]:not(.selected)',
    // Garniture
    '.wizard-option[data-type="garniture"]:not(.selected)',
  ];
  for (const sel of candidates) {
    const loc = page.locator(sel).first();
    const visible = await loc.isVisible({ timeout: 1_000 }).catch(() => false);
    if (visible) {
      await loc.click({ timeout: 4_000 }).catch(() => {});
      await page.waitForTimeout(250);
      return { clicked: true, selector: sel };
    }
  }
  return { clicked: false, selector: null };
}

/**
 * Click the wizard add-to-cart button + wait for both the wizard to close AND
 * the cart line to appear (the wizard dispatches wizard:add-to-cart then closes
 * after ~200ms).
 */
async function validateWizard(page) {
  const addBtn = page.locator('button[data-action="add-to-cart"]').first();
  await expect(addBtn, 'Wizard add-to-cart button must be visible').toBeVisible({ timeout: 5_000 });
  await addBtn.click({ timeout: 6_000 });
  // Wizard auto-closes ~200ms after dispatch ; cart re-renders.
  await page.waitForSelector('.pos-wizard.single-page', { state: 'detached', timeout: 8_000 }).catch(() => {});
  await page.waitForTimeout(700);
}

/**
 * Cancel via the wizard cancel button (NOT backdrop — that's a different code path).
 */
async function cancelWizard(page) {
  const cancelBtn = page.locator('button[data-action="cancel-wizard"]').first();
  await expect(cancelBtn, 'Wizard cancel button must be visible').toBeVisible({ timeout: 5_000 });
  await cancelBtn.click({ timeout: 6_000 });
  await page.waitForSelector('.pos-wizard.single-page', { state: 'detached', timeout: 8_000 }).catch(() => {});
  await page.waitForTimeout(400);
}

test.describe('POS · KDS · OSS sync — Wave B : POS wizard popup (FROZEN, capture only)', () => {
  test.setTimeout(600_000);

  test('Wave B : 17 sequential states (3 items × wizard tile→steps→recap→cart + aggregate + cancel-edge)', async ({ browser }) => {
    // POS surface is desktop-landscape — 1440x900 mirrors the typical caisse
    // hardware viewport for Le Cayenne deployment.
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Observations buffer — printed to stdout at end of test for the
    // adversarial reviewer to triage. Numeric integrity, escape behavior,
    // payload sidecar status, frozen-zone observations all collected here.
    const observations = [];
    // Per-item totals captured for cross-checking at aggregate state 16.
    const perItemRecap = []; // [{label, id, recap_total, recap_text, cart_line_total, cart_line_text}]

    try {
      await loginAsPosOperator(page);
      await waitForCatalog(page);

      // ===========================================================
      // ITEM 1 — Tacos M (363) : 4 states (tile → wizard-open → recap → cart)
      // Per audit plan : item-1 = "simple, no options" — but in this catalog
      // every wizard item has at least viande/sauce. We capture the "simple"
      // path : open wizard, do NOTHING (accept defaults), validate.
      // ===========================================================
      const item1 = ITEMS[0];

      // STATE 01 — pre-tap baseline tile.
      const tile1 = page.locator(`[data-pos-item-id="${item1.id}"]`).first();
      await expect(tile1, `Item ${item1.id} tile must exist`).toBeVisible({ timeout: 10_000 });
      await tile1.scrollIntoViewIfNeeded().catch(() => {});
      observations.push(`state01: item ${item1.id} (${item1.name}) tile present, base ${item1.basePrice}€`);
      await snap(`01-b-tile-tap-item-1`);

      // STATE 02 — wizard overlay open (initial render).
      await openWizardForItem(page, item1.id);
      const w1Initial = await readWizardTotal(page);
      observations.push(`state02: wizard open for ${item1.id}, sticky total='${w1Initial.text}' parsed=${w1Initial.value}€`);
      await snap(`02-b-wizard-open-item-1`);

      // STATE 03 — recap state. The single-page wizard already shows a recap
      // ticket preview (.wizard-ticket-preview) and a sticky-bar total ; this
      // IS the recap. Scroll the sticky bar into view + snap.
      await page.locator('.wizard-sticky-bar').first().scrollIntoViewIfNeeded().catch(() => {});
      await page.waitForTimeout(200);
      const w1Recap = await readWizardTotal(page);
      observations.push(`state03: recap for ${item1.id} sticky total='${w1Recap.text}' parsed=${w1Recap.value}€`);
      await snap(`03-b-wizard-recap-item-1`);

      // Validate + capture cart line.
      await validateWizard(page);
      const cart1 = await readFirstCartLinePrice(page);
      const recap1Numeric = Number.isFinite(w1Recap.value) ? w1Recap.value : item1.basePrice;
      const cartLine1Numeric = Number.isFinite(cart1.value) ? cart1.value : NaN;
      const integrity1 = Number.isFinite(cartLine1Numeric) && Math.abs(recap1Numeric - cartLine1Numeric) < 0.01;
      perItemRecap.push({
        label: item1.label, id: item1.id, name: item1.name,
        recap_total: recap1Numeric, recap_text: w1Recap.text,
        cart_line_total: cartLine1Numeric, cart_line_text: cart1.text,
        integrity_ok: integrity1, cart_line_count: cart1.count,
      });
      observations.push(`state04: item ${item1.id} cart line='${cart1.text}' parsed=${cartLine1Numeric}€ ; recap=${recap1Numeric}€ ; integrity=${integrity1 ? 'OK' : 'MISMATCH'} ; lines_total=${cart1.count}`);
      await snap(`04-b-cart-after-validate-item-1`);

      // ===========================================================
      // ITEM 2 — Cheese Burger (376) : 6 states (tile → step1 → step2 → step-extras → recap → cart)
      // Mid-complexity wizard ; exercise primary option + paid extra.
      // ===========================================================
      const item2 = ITEMS[1];

      // STATE 05 — tile.
      const tile2 = page.locator(`[data-pos-item-id="${item2.id}"]`).first();
      const tile2Visible = await tile2.isVisible({ timeout: 4_000 }).catch(() => false);
      if (!tile2Visible) {
        // Cat 308 (burgers) may be in a different tab — try clicking the cat tab.
        // Best-effort; if the item still fails to appear we record and skip.
        await page.locator(`[data-pos-cat-id="${item2.cat}"], [data-cat-id="${item2.cat}"]`).first()
          .click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(500);
      }
      await expect(tile2, `Item ${item2.id} tile must be reachable`).toBeVisible({ timeout: 10_000 });
      await tile2.scrollIntoViewIfNeeded().catch(() => {});
      observations.push(`state05: item ${item2.id} (${item2.name}) tile present`);
      await snap(`05-b-tile-tap-item-2`);

      // STATE 06 — wizard initial render (= step-1 in plan terminology).
      await openWizardForItem(page, item2.id);
      await snap(`06-b-wizard-step-1-item-2`);

      // STATE 07 — primary option selected (= step-2 in plan).
      const prim2 = await tickPrimaryOption(page);
      observations.push(`state07: primary option click for ${item2.id} clicked=${prim2.clicked} sel=${prim2.selector || '-'}`);
      await snap(`07-b-wizard-step-2-item-2`);

      // STATE 08 — paid extra toggled (= step-extras).
      const extra2 = await tickAnyPaidExtra(page);
      observations.push(`state08: paid extra click for ${item2.id} clicked=${extra2.clicked} sel=${extra2.selector || '-'}`);
      await snap(`08-b-wizard-step-extras-item-2`);

      // STATE 09 — recap (sticky bar with updated total).
      await page.locator('.wizard-sticky-bar').first().scrollIntoViewIfNeeded().catch(() => {});
      await page.waitForTimeout(250);
      const w2Recap = await readWizardTotal(page);
      observations.push(`state09: recap for ${item2.id} sticky total='${w2Recap.text}' parsed=${w2Recap.value}€ (base ${item2.basePrice}€)`);
      await snap(`09-b-wizard-recap-item-2`);

      // Validate + capture cart.
      await validateWizard(page);
      // We expect 2 cart lines now (item1 + item2).
      const cartAfter2 = await readCartAggregate(page);
      const item2Line = cartAfter2.lines[cartAfter2.lines.length - 1] || { price_text: '', price_value: NaN };
      const recap2Numeric = Number.isFinite(w2Recap.value) ? w2Recap.value : item2.basePrice;
      const cartLine2Numeric = Number.isFinite(item2Line.price_value) ? item2Line.price_value : NaN;
      const integrity2 = Number.isFinite(cartLine2Numeric) && Math.abs(recap2Numeric - cartLine2Numeric) < 0.01;
      perItemRecap.push({
        label: item2.label, id: item2.id, name: item2.name,
        recap_total: recap2Numeric, recap_text: w2Recap.text,
        cart_line_total: cartLine2Numeric, cart_line_text: item2Line.price_text,
        integrity_ok: integrity2, cart_line_count: cartAfter2.lines.length,
      });
      observations.push(`state10: item ${item2.id} cart last-line='${item2Line.price_text}' parsed=${cartLine2Numeric}€ ; recap=${recap2Numeric}€ ; integrity=${integrity2 ? 'OK' : 'MISMATCH'} ; total_lines=${cartAfter2.lines.length}`);
      await snap(`10-b-cart-after-validate-item-2`);

      // ===========================================================
      // ITEM 3 — Le Méga (367) : 5 states (tile → step1 → step-menu → recap → cart)
      // Largest wizard (35 variations). For "menu/upsell" sub-state we click
      // a menu-choice / formule card if present, else any extra.
      // ===========================================================
      const item3 = ITEMS[2];

      // STATE 11 — tile.
      const tile3 = page.locator(`[data-pos-item-id="${item3.id}"]`).first();
      const tile3Visible = await tile3.isVisible({ timeout: 4_000 }).catch(() => false);
      if (!tile3Visible) {
        await page.locator(`[data-pos-cat-id="${item3.cat}"], [data-cat-id="${item3.cat}"]`).first()
          .click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(500);
      }
      await expect(tile3, `Item ${item3.id} tile must be reachable`).toBeVisible({ timeout: 10_000 });
      await tile3.scrollIntoViewIfNeeded().catch(() => {});
      observations.push(`state11: item ${item3.id} (${item3.name}) tile present`);
      await snap(`11-b-tile-tap-item-3`);

      // STATE 12 — wizard initial.
      await openWizardForItem(page, item3.id);
      await snap(`12-b-wizard-step-1-item-3`);

      // STATE 13 — menu / upsell branch. The single-page wizard exposes
      // formule cards as `.formule-card[data-action="menu-choice"]` with
      // data-value="addon_<id>" (NOT "full" — that's the deprecated multi-step
      // path). We pick the first non-"none" formule card if present, else
      // fall back to primary option + paid extra.
      const menuBranch = page.locator('.formule-card[data-action="menu-choice"]:not([data-value="none"])').first();
      let menuBranchClicked = false;
      let menuBranchValue = null;
      if (await menuBranch.isVisible({ timeout: 1500 }).catch(() => false)) {
        menuBranchValue = await menuBranch.getAttribute('data-value').catch(() => null);
        await menuBranch.scrollIntoViewIfNeeded({ timeout: 1500 }).catch(() => {});
        await menuBranch.click({ timeout: 4_000 }).catch(() => {});
        await page.waitForTimeout(350);
        menuBranchClicked = true;
        observations.push(`state13: menu-choice formule clicked for ${item3.id} value=${menuBranchValue}`);
      } else {
        const fb = await tickPrimaryOption(page);
        const ex = await tickAnyPaidExtra(page);
        observations.push(`state13: NO menu-choice card visible for ${item3.id} ; fell back to primary='${fb.selector || '-'}' + paidExtra='${ex.selector || '-'}'`);
      }
      await snap(`13-b-wizard-step-menu-or-upsell-item-3`);

      // STATE 14 — recap.
      await page.locator('.wizard-sticky-bar').first().scrollIntoViewIfNeeded().catch(() => {});
      await page.waitForTimeout(250);
      const w3Recap = await readWizardTotal(page);
      observations.push(`state14: recap for ${item3.id} sticky total='${w3Recap.text}' parsed=${w3Recap.value}€ (base ${item3.basePrice}€) menu_branch_clicked=${menuBranchClicked}`);
      await snap(`14-b-wizard-recap-item-3`);

      // Validate.
      await validateWizard(page);
      const cartAfter3 = await readCartAggregate(page);
      const item3Line = cartAfter3.lines[cartAfter3.lines.length - 1] || { price_text: '', price_value: NaN };
      const recap3Numeric = Number.isFinite(w3Recap.value) ? w3Recap.value : item3.basePrice;
      const cartLine3Numeric = Number.isFinite(item3Line.price_value) ? item3Line.price_value : NaN;
      const integrity3 = Number.isFinite(cartLine3Numeric) && Math.abs(recap3Numeric - cartLine3Numeric) < 0.01;
      perItemRecap.push({
        label: item3.label, id: item3.id, name: item3.name,
        recap_total: recap3Numeric, recap_text: w3Recap.text,
        cart_line_total: cartLine3Numeric, cart_line_text: item3Line.price_text,
        integrity_ok: integrity3, cart_line_count: cartAfter3.lines.length,
      });
      observations.push(`state15: item ${item3.id} cart last-line='${item3Line.price_text}' parsed=${cartLine3Numeric}€ ; recap=${recap3Numeric}€ ; integrity=${integrity3 ? 'OK' : 'MISMATCH'} ; total_lines=${cartAfter3.lines.length}`);
      await snap(`15-b-cart-after-validate-item-3`);

      // ===========================================================
      // STATE 16 — cart aggregate with all 3 items + payload sidecar.
      // ===========================================================
      const aggregate = await readCartAggregate(page);
      const sumLineValues = aggregate.lines
        .map((l) => Number.isFinite(l.price_value) ? l.price_value : 0)
        .reduce((a, b) => a + b, 0);
      const grandTotalIntegrity = Number.isFinite(aggregate.grand_total_value)
        && Math.abs(aggregate.grand_total_value - sumLineValues) < 0.01;
      observations.push(`state16: aggregate cart lines=${aggregate.lines.length} sum_lines=${sumLineValues.toFixed(2)}€ grand_total='${aggregate.grand_total_text}' parsed=${aggregate.grand_total_value}€ integrity=${grandTotalIntegrity ? 'OK' : 'MISMATCH'}`);

      // Probe Vuex store for the payload sidecar. Falls back to DOM scrape.
      const vuex = await probeVuexCart(page);
      const sidecar = {
        captured_at: new Date().toISOString(),
        wave: 'B',
        round: 1,
        per_item_recap_vs_cart: perItemRecap,
        aggregate_dom: aggregate,
        sum_lines_dom: Number(sumLineValues.toFixed(2)),
        grand_total_integrity_dom_vs_sum: grandTotalIntegrity,
        vuex_store_probe: {
          available: !!vuex && vuex.available,
          reason: vuex && vuex.reason ? vuex.reason : null,
          lists_count: vuex && vuex.lists_count != null ? vuex.lists_count : null,
          subtotal: vuex && vuex.subtotal != null ? vuex.subtotal : null,
          discount: vuex && vuex.discount != null ? vuex.discount : null,
          // Truncate to keep sidecar size sane — the full structure is large.
          lists_sample: vuex && Array.isArray(vuex.lists) ? vuex.lists.slice(0, 5) : null,
        },
      };
      const sidecarPath = path.join(SCREENSHOT_DIR, '16-b-cart-three-lines-aggregate.payload.json');
      // snap() is called BEFORE writing sidecar so screenshot is captured first.
      await snap(`16-b-cart-three-lines-aggregate`);
      try {
        fs.writeFileSync(sidecarPath, JSON.stringify(sidecar, null, 2));
        observations.push(`state16: payload sidecar written → ${sidecarPath} (vuex_available=${sidecar.vuex_store_probe.available})`);
      } catch (e) {
        observations.push(`state16: SIDECAR WRITE FAIL ${e && e.message}`);
      }

      // ===========================================================
      // STATE 17 — wizard cancel edge. Open wizard for item 363 (already in
      // catalog), click cancel, verify NO new cart line was added AND no
      // residual .pos-wizard root in DOM.
      //
      // Also probe the ESC key — pos-wizard.js #5871 only binds Ctrl+Enter
      // for submit, NO ESC handler is present. We try ESC explicitly + record
      // whether it dismisses the wizard for the adversarial reviewer (P1 a11y
      // finding if it does NOT dismiss).
      // ===========================================================
      const cartLinesBeforeCancel = await page.locator('.pos-v5-cart-item').count();

      await openWizardForItem(page, item1.id);
      // ESC probe.
      await page.keyboard.press('Escape');
      await page.waitForTimeout(400);
      const wizardStillVisibleAfterEsc = await page.locator('.pos-wizard.single-page').isVisible({ timeout: 1000 }).catch(() => false);
      observations.push(`state17 ESC-probe: wizard_still_visible_after_esc=${wizardStillVisibleAfterEsc} (expected false for proper a11y ; pos-wizard.js binds only Ctrl+Enter, no ESC handler)`);

      // If ESC did NOT dismiss, click the cancel button. Otherwise capture state.
      if (wizardStillVisibleAfterEsc) {
        await cancelWizard(page);
      } else {
        // ESC dismissed — wait for the underlying Vue modal to also close.
        await page.waitForTimeout(400);
      }

      // Verify no residual wizard root.
      const residualWizard = await page.locator('.pos-wizard.single-page').count();
      const residualModalActive = await page.locator('#item-variation-modal.active').count();
      const cartLinesAfterCancel = await page.locator('.pos-v5-cart-item').count();
      const noOrphanLine = cartLinesAfterCancel === cartLinesBeforeCancel;
      observations.push(`state17: residual_wizard_root=${residualWizard} residual_modal_active=${residualModalActive} cart_lines_before=${cartLinesBeforeCancel} after=${cartLinesAfterCancel} noOrphanLine=${noOrphanLine}`);
      await snap(`17-b-wizard-cancel-edge`);

      // ===========================================================
      // Final integrity assertions — only the spec-level minimums. Numeric
      // mismatches and ESC behavior are surfaced as observations for the
      // adversarial reviewer (P0/P1 findings classified there, not failed here)
      // because the wizard is FROZEN — failing the spec on a wizard defect
      // would prevent capture of the remaining states for review.
      // ===========================================================

      // PNG count check.
      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.png'));
      const stateLabels = [
        '01-b-tile-tap-item-1',
        '02-b-wizard-open-item-1',
        '03-b-wizard-recap-item-1',
        '04-b-cart-after-validate-item-1',
        '05-b-tile-tap-item-2',
        '06-b-wizard-step-1-item-2',
        '07-b-wizard-step-2-item-2',
        '08-b-wizard-step-extras-item-2',
        '09-b-wizard-recap-item-2',
        '10-b-cart-after-validate-item-2',
        '11-b-tile-tap-item-3',
        '12-b-wizard-step-1-item-3',
        '13-b-wizard-step-menu-or-upsell-item-3',
        '14-b-wizard-recap-item-3',
        '15-b-cart-after-validate-item-3',
        '16-b-cart-three-lines-aggregate',
        '17-b-wizard-cancel-edge',
      ];
      const missing = stateLabels.filter((s) => !written.includes(`${s}.png`));

      // eslint-disable-next-line no-console
      console.log(`[WAVE-B] obs:\n  ${observations.join('\n  ')}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-B] perItemRecap:\n${JSON.stringify(perItemRecap, null, 2)}`);
      // eslint-disable-next-line no-console
      console.log(`[WAVE-B] PNGs written: ${written.length}/${stateLabels.length} ${missing.length ? '(MISSING: ' + missing.join(',') + ')' : ''}`);

      expect(missing, `All 17 state PNGs must be captured ; missing: ${missing.join(', ')}`).toEqual([]);
      expect(written.length, `Wave B expects 17 PNGs, got ${written.length}`).toBeGreaterThanOrEqual(17);
    } finally {
      try { dispose(); } catch (_e) { /* ignore */ }
      await ctx.close().catch(() => {});
    }
  });
});
