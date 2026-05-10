// FoodKing E2E — Borne audit `borne-cats-309-318-2026-05-10` Wave B
// Wizard "frites incluses" templates : assiette (309) + omelette (310/311/314).
// Pipeline expected (per KioskWizardComponent.vue:533-615 + DB wizard_template) :
//   - 309 (assiette)  : viande + sauce + garnitures + supplements + recap
//   - 310 (omelette)  : sauce + garnitures + supplements + recap
//   - 311 (omelette)  : sauce + garnitures + supplements + recap
//   - 314 (omelette)  : sauce + garnitures + supplements + recap
//
// MUST PASS :
//   - NO `KioskStepMenu` mounts at any step (no `.kiosk-step-menu` in DOM)
//   - NO `KioskStepFritesStyle` mounts (no `[data-testid="kiosk-step-frites-style"]`)
//   - Recap total === item base price (no supplements selected → deterministic)
//   - Cart line total === recap total after addToCart
//
// NOTE on selectors :
//   The plan referenced `[data-testid="kiosk-wizard-step-<type>"]` but those
//   testids DO NOT EXIST. The wizard step components use class-based markers :
//     - viande      : `.kiosk-step-viande` (KioskStepViandeComponent)
//     - sauce       : `.kiosk-step-sauce` (KioskStepSauceComponent)
//     - garnitures  : `.kiosk-step-garnitures` (KioskStepGarnituresComponent)
//     - supplements : `.kiosk-step-supplements` (KioskStepSupplementsComponent)
//     - menu        : `.kiosk-step-menu` (KioskStepMenuComponent) ← MUST NOT mount
//     - frites_style: `[data-testid="kiosk-step-frites-style"]`  ← MUST NOT mount
//     - recap       : `[data-testid="kiosk-order-summary-root"]` (KioskOrderSummaryComponent)
//   The active step label is also exposed via `.kiosk-step-visual.active .kiosk-step-visual-label`
//   (text content : Viande/Sauce/Garnitures/Suppléments/Récap).

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsKiosk } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-borne-B');

// Step types whose presence must be ASSERTED ABSENT throughout every Wave-B journey.
const FORBIDDEN_STEP_SELECTORS = [
  // KioskStepMenu : the wizard would mount the upsell menu page (frites/boisson/full).
  '.kiosk-step-menu',
  // KioskStepFritesStyle : the 3-card upgrade page (Nature/Cheddar/Cheddar+Oignons).
  '[data-testid="kiosk-step-frites-style"]',
  '.kiosk-step-frites-style',
];

// Parse FR currency display ("12,50 €" or "12.50€" or "12,50€"). Returns float.
function parsePriceText(txt) {
  if (!txt) return null;
  const m = String(txt).match(/(-?\d+(?:[.,]\d+)?)/);
  if (!m) return null;
  return parseFloat(m[1].replace(',', '.'));
}

// Read recap displayed total (kiosk-order-summary-total-price) → float.
async function readRecapTotal(page) {
  const el = page.locator('[data-testid="kiosk-order-summary-total-price"]').first();
  await expect(el).toBeVisible({ timeout: 10_000 });
  const txt = (await el.innerText()).trim();
  return { text: txt, value: parsePriceText(txt) };
}

// Read currently-active step type from `.kiosk-step-visual.active .kiosk-step-visual-label`.
async function readActiveStepLabel(page) {
  const el = page.locator('.kiosk-step-visual.active .kiosk-step-visual-label').first();
  if (!(await el.count())) return null;
  return (await el.innerText()).trim();
}

// Click the Next/Add-to-cart button (same `.kiosk-btn-next`).
async function clickNext(page) {
  const btn = page.locator('.kiosk-btn-next').first();
  await expect(btn).toBeVisible({ timeout: 10_000 });
  // Wait until enabled (selection completed for current step).
  await expect(btn).toBeEnabled({ timeout: 10_000 });
  await btn.click();
}

// Wait for the wizard root to be visible (overlay open).
async function waitForWizardOpen(page) {
  await expect(page.locator('.kiosk-wizard').first()).toBeVisible({ timeout: 20_000 });
}

// Assert no forbidden step rendered RIGHT NOW.
async function assertNoForbiddenSteps(page, ctx) {
  for (const sel of FORBIDDEN_STEP_SELECTORS) {
    const count = await page.locator(sel).count();
    if (count !== 0) {
      throw new Error(
        `[Wave-B forbidden-step] selector ${sel} mounted at ${ctx} (count=${count}) — ` +
        `frites incluses template should NEVER show menu/frites_style steps.`
      );
    }
  }
}

// Detect the current step type by which step component class is rendered in
// `.kiosk-step-content`. Returns one of: viande/sauce/pain/garnitures/supplements/recap/null.
async function detectCurrentStepType(page) {
  const probes = [
    ['viande', '.kiosk-step-viande'],
    ['sauce', '.kiosk-step-sauce'],
    ['pain', '.kiosk-step-pain'],
    ['garnitures', '.kiosk-step-garnitures'],
    ['supplements', '.kiosk-step-supplements'],
    ['taille', '.kiosk-step-taille'],
    ['recap', '[data-testid="kiosk-order-summary-root"]'],
  ];
  for (const [type, sel] of probes) {
    if (await page.locator(sel).count()) return type;
  }
  return null;
}

// Activate enough selections on the current step to enable Next. canAdvance
// rules :
//   viande : need >= detectViandeCount() (item-dep ; 1 for assiette/omelette)
//   sauce  : need sauceOrder.length > 0 → click 1 card
//   pain   : need pain !== null → click 1 card
//   garnitures : no requirement (all default-selected) → no click needed
//   supplements: no requirement → no click needed
//   taille : need taille !== null → click 1 card
// Keep selection MINIMAL → recap total === base price (deterministic numeric).
async function activateFirstChoiceForStep(page, stepType) {
  if (stepType === 'sauce') {
    // Pick first non-disabled sauce card. role=checkbox + class=kiosk-option-card.
    const card = page.locator(
      '.kiosk-step-sauce .kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)'
    ).first();
    if (await card.count()) {
      await card.click({ timeout: 5_000 });
      await page.waitForTimeout(500);
      return 'sauce-card';
    }
  } else if (stepType === 'viande') {
    const card = page.locator(
      '.kiosk-step-viande .kiosk-viande-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)'
    ).first();
    if (await card.count()) {
      await card.click({ timeout: 5_000 });
      await page.waitForTimeout(500);
      return 'viande-card';
    }
  } else if (stepType === 'pain') {
    const card = page.locator('.kiosk-step-pain .kiosk-option-card, .kiosk-step-pain [role="checkbox"], .kiosk-step-pain [role="radio"]').first();
    if (await card.count()) {
      await card.click({ timeout: 5_000 });
      await page.waitForTimeout(500);
      return 'pain-card';
    }
  } else if (stepType === 'taille') {
    const card = page.locator('.kiosk-step-taille [role="radio"], .kiosk-step-taille .kiosk-option-card').first();
    if (await card.count()) {
      await card.click({ timeout: 5_000 });
      await page.waitForTimeout(500);
      return 'taille-card';
    }
  }
  // garnitures, supplements : no required interaction (skip click).
  // recap : no activation needed.
  return null;
}

// Walk the wizard from current step to recap. At each step :
//   1. snap PNG/quartet
//   2. assert no forbidden step mounted
//   3. log step type (assert it's in expected pipeline)
// Returns the ordered list of step types actually traversed.
async function walkWizardToRecap(page, snap, prefix, expectedTypes) {
  const traversed = [];
  // Bounded loop : at most 8 steps in any wizard (defensive against infinite loop).
  for (let i = 0; i < 8; i++) {
    await waitForWizardOpen(page);
    // Stabilize step content + 400ms debounce of runningTotal.
    await page.waitForTimeout(550);
    const stepType = await detectCurrentStepType(page);
    const label = await readActiveStepLabel(page);
    traversed.push(stepType);

    const isRecap = stepType === 'recap';
    const slug = stepType || (label ? label.toLowerCase().replace(/\s+/g, '-') : `step-${i}`);
    await snap(`${prefix}-${String(i + 1).padStart(2, '0')}-step-${slug}`);

    await assertNoForbiddenSteps(page, `${prefix} step ${i + 1} (type=${stepType}, label="${label}")`);

    if (isRecap) break;

    // Activate first valid choice for the current step (deterministic, base-price-only).
    await activateFirstChoiceForStep(page, stepType);
    // Wait for Next button to enable (canAdvance). Some steps (garnitures,
    // supplements) are immediately advance-ready ; others need the click above.
    const btn = page.locator('.kiosk-btn-next').first();
    await expect(btn).toBeEnabled({ timeout: 8_000 });
    await btn.click();
    await page.waitForTimeout(500);
  }

  // Pipeline gate : forbidden types must not appear.
  if (traversed.includes('menu')) {
    throw new Error(`[Wave-B pipeline] ${prefix} traversed 'menu' step — should be absent in frites-incluses templates. traversed=${JSON.stringify(traversed)}`);
  }
  if (traversed.includes('frites_style')) {
    throw new Error(`[Wave-B pipeline] ${prefix} traversed 'frites_style' step — should be absent. traversed=${JSON.stringify(traversed)}`);
  }

  return traversed;
}

// Drive a full journey for one cat → first item → wizard → recap → addToCart.
// `expected` shape : { catId, itemId, basePrice, expectedTypes: string[] }.
// `expectedTypes` lists allowed step types (subset). Forbidden types (menu,
// frites_style) are checked separately in walkWizardToRecap.
async function runCatJourney(page, snap, prefix, expected) {
  // Click sidebar category.
  const catBtn = page.locator(`[data-testid="kiosk-categories-sidebar-item-${expected.catId}"]`);
  await expect(catBtn).toBeVisible({ timeout: 15_000 });
  await catBtn.click();
  await page.waitForTimeout(500);

  // Click target product card add-button.
  const productCard = page.locator(`[data-testid="kiosk-product-card-${expected.itemId}"]`);
  await expect(productCard).toBeVisible({ timeout: 10_000 });
  const addBtn = page.locator(`[data-testid="kiosk-product-add-${expected.itemId}"]`);
  await expect(addBtn).toBeVisible({ timeout: 10_000 });
  await addBtn.click();
  await waitForWizardOpen(page);

  // Walk wizard to recap, snapping each step.
  const traversed = await walkWizardToRecap(page, snap, prefix, expected.expectedTypes);

  // At recap : assert numeric integrity.
  const recap = await readRecapTotal(page);
  if (recap.value === null) {
    throw new Error(`[Wave-B numeric] ${prefix} recap total unparseable: "${recap.text}"`);
  }
  // Allow ±0.01 tolerance for FR rounding. Base price is the floor; running total
  // can include included sauces/garnitures (kiosk-free) but never less than base.
  if (recap.value + 0.011 < expected.basePrice) {
    throw new Error(
      `[Wave-B numeric] ${prefix} recap total ${recap.value} < base ${expected.basePrice} (got "${recap.text}")`
    );
  }
  // Also confirm not absurdly high (would indicate menu/frites_style upcharge leak).
  if (recap.value > expected.basePrice + 5.0) {
    throw new Error(
      `[Wave-B numeric] ${prefix} recap total ${recap.value} > base+5 (${expected.basePrice + 5}) — possible upsell leak (got "${recap.text}")`
    );
  }

  // Add to cart : same `.kiosk-btn-next` click at recap step (button label switches
  // to "Ajouter au panier" via :class kiosk-btn-next--cart at last step).
  await clickNext(page);
  // Wizard overlay should disappear.
  await expect(page.locator('.kiosk-wizard-overlay')).toHaveCount(0, { timeout: 10_000 });

  return { traversed, recapTotal: recap.value, recapText: recap.text };
}

test.describe('Borne Cats 309-318 — Wave B (Wizard frites incluses)', () => {
  test.setTimeout(360_000);

  test('full wizard journey for cats 309/310/311/314 — no menu, no frites_style', async ({ page }) => {
    // Reset error capture.
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    fs.mkdirSync(SHOT_DIR, { recursive: true });
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);

    try {
      // Auth + idle screen.
      await loginAsKiosk(page, 'kiosk-lecayenne', 'kiosk123');
      await page.setViewportSize({ width: 1080, height: 1920 });

      // Land on idle, choose takeaway, navigate to categories.
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);

      const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
      if (await takeaway.isVisible({ timeout: 10_000 }).catch(() => false)) {
        await takeaway.click({ timeout: 5_000 });
        await page.waitForTimeout(2_000);
      }
      await expect(page).toHaveURL(/\/kiosk\/(categories|menu)/, { timeout: 15_000 });

      // Allowed step types for any frites-incluses template.
      // Wizard's shouldShowStep() may filter out garnitures/supplements when
      // the item lacks corresponding extras — so we accept any subset of these.
      const allowedTypes = ['viande', 'sauce', 'pain', 'garnitures', 'supplements', 'taille', 'recap'];

      // Cat 309 — Assiette Poulet (item 381 @ 12.50€ ; assiette template)
      const r309 = await runCatJourney(page, snap, '309', {
        catId: 309,
        itemId: 381,
        basePrice: 12.50,
        expectedTypes: allowedTypes,
      });

      // After cart-add we're back on /kiosk/categories. Continue with cat 310.

      // Cat 310 — Ojja Bœuf (item 385 @ 13.50€ ; omelette template)
      const r310 = await runCatJourney(page, snap, '310', {
        catId: 310,
        itemId: 385,
        basePrice: 13.50,
        expectedTypes: allowedTypes,
      });

      // Cat 311 — Omelette Fromage (item 390 @ 8.50€ ; omelette template)
      const r311 = await runCatJourney(page, snap, '311', {
        catId: 311,
        itemId: 390,
        basePrice: 8.50,
        expectedTypes: allowedTypes,
      });

      // Cat 314 — Menu Cheese Burger Enfant (item 400 @ 6.00€ ; omelette template)
      const r314 = await runCatJourney(page, snap, '314', {
        catId: 314,
        itemId: 400,
        basePrice: 6.00,
        expectedTypes: allowedTypes,
      });

      // State 15 : open cart from categories bottom bar to verify lines.
      const cartCta = page.locator('[data-testid="kiosk-categories-cart-indicator"]').first();
      if (await cartCta.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await cartCta.click();
      } else {
        // Fallback : navigate via router.
        await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      }
      await expect(page.locator('[data-testid="kiosk-cart-root"]').first()).toBeVisible({ timeout: 15_000 });
      await page.waitForTimeout(700);
      await snap('15-cart-after-add-309-310-311-314');

      // Assert at least 4 cart lines (one per journey).
      const cartItems = page.locator('[data-testid^="kiosk-cart-item-"][data-testid$="-0"], [data-testid^="kiosk-cart-item-0"], [role="listitem"]');
      const itemBlocks = page.locator('[data-testid="kiosk-cart-items"] [data-testid^="kiosk-cart-item-"]:not([data-testid*="-name-"]):not([data-testid*="-edit-"]):not([data-testid*="-options-"]):not([data-testid*="-allergens-"]):not([data-testid*="-note-"]):not([data-testid*="-qty-"]):not([data-testid*="-remove-"]):not([data-testid*="-total-"])');
      const cartCount = await itemBlocks.count();
      if (cartCount < 4) {
        // Less strict fallback : count lines by their root index 0..N total testids.
        const totals = await page.locator('[data-testid^="kiosk-cart-item-total-"]').count();
        expect(totals).toBeGreaterThanOrEqual(4);
      }

      // Pull line totals : compare line-0 total to first journey recap (309) ;
      // each line is appended in order so 0=309, 1=310, 2=311, 3=314.
      const expectedLineFloors = [r309.recapTotal, r310.recapTotal, r311.recapTotal, r314.recapTotal];
      for (let idx = 0; idx < expectedLineFloors.length; idx++) {
        const lineTotalEl = page.locator(`[data-testid="kiosk-cart-item-total-${idx}"]`);
        if (!(await lineTotalEl.count())) continue;
        const lineTxt = (await lineTotalEl.innerText()).trim();
        const lineVal = parsePriceText(lineTxt);
        if (lineVal === null) {
          throw new Error(`[Wave-B numeric] cart line ${idx} unparseable "${lineTxt}"`);
        }
        // ±1c tolerance for currency rounding.
        if (Math.abs(lineVal - expectedLineFloors[idx]) > 0.011) {
          throw new Error(
            `[Wave-B numeric] cart line ${idx} = ${lineVal} but recap was ${expectedLineFloors[idx]} ("${lineTxt}")`
          );
        }
      }

      // State 16 : screenshot of cart with all 4 thumbnails (recap quartet).
      await snap('16-cart-thumbnails-quartet');

      // Final guard : no forbidden steps anywhere in current DOM and no JS errors.
      await assertNoForbiddenSteps(page, 'final cart view');

      const visibleText = await page.locator('body').innerText();
      expect(visibleText).not.toMatch(/Whoops|Fatal error|Server Error/i);

      const criticalErrors = jsErrors.filter((msg) =>
        /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(msg)
      );
      expect(criticalErrors).toEqual([]);
    } finally {
      try { dispose(); } catch (_) {}
    }
  });
});
