// =============================================================================
// CANONICAL E2E — CAISSE POS Ergonomics (Wave-1 audit heals)
// =============================================================================
// Proves the POS-ERG-0x ergonomics defects are HEALED with concrete, anti-theater
// assertions (boundingBox geometry + FR currency regex). NO bare expect(true).
//
// Defects covered (source: reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md):
//   • POS-ERG-01 — product grid right column occluded by fixed cart at 1024×768
//   • POS-ERG-02 — operator-bar brand column collapses / nav overlaps title
//   • POS-ERG-03 — cart totals / grand-total / pay CTA render en-US 0.90€ not FR 0,90 €
//   • POS-ERG-04 — in-cart qty stepper buttons < 44px touch target
//   • POS-ERG-05 — order-type segmented + discount controls < 44px touch target
//
// HARNESS FACTS (worktree pre-cloud-exec):
//   - LIVE server = http://127.0.0.1:8765 (PLAYWRIGHT_BASE_URL). reuseExistingServer.
//   - PosComponent compiles into public/js/pos-shell.js ; pos-v5.css → public/css/app.css.
//   - Coca-Cola tile = [data-pos-item-id="52"] (no composer steps).
//   - CHAIN-SAFETY: abort any POST to /api/admin/pos so NO order ever submits.
// =============================================================================

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../../helpers/login');

const SHOTS_DIR = path.resolve(__dirname, '../../../../reports/test-e2e/all-systems-2026-06-06/caisse-ergonomics');
fs.mkdirSync(SHOTS_DIR, { recursive: true });

const COCA_TILE = '[data-pos-item-id="52"]';
const MIN_TAP = 44; // WCAG 2.1 AAA target size baseline used across FoodKing POS

/** FR money: comma decimal + trailing ' €' (allow optional space) → "0,90 €" */
const FR_MONEY = /\d+,\d{2}\s?€/;
/** en-US money the heal must NOT produce: "0.90€" / "12.50 €" (dot decimal) */
const ENUS_MONEY = /\d+\.\d{2}\s?€/;

const consoleErrorsByTest = new Map();

/**
 * Block every real order POST so the NF525 chain never advances.
 * Visual/layout tests do not need to create orders.
 */
async function installChainSafety(page, testTitle) {
  const errors = [];
  consoleErrorsByTest.set(testTitle, errors);
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });
  await page.route('**/*', (route) => {
    const req = route.request();
    if (req.method() === 'POST' && /\/api\/admin\/pos(\?|$)/.test(req.url())) {
      return route.abort();
    }
    return route.continue();
  });
}

/**
 * Add Coca-Cola to the cart.
 * The POS tile is a <button> bound only to @keyup.enter/space → press Enter to
 * trigger addItem(), which opens the item wizard modal (#item-variation-modal).
 * Coca has no required composer steps → the add CTA (.pos-v5-item-add-cta) is
 * immediately enabled. The CTA can render 0×0 → dispatchEvent('click').
 */
async function addCocaToCart(page) {
  const tile = page.locator(COCA_TILE).first();
  await expect(tile).toBeVisible({ timeout: 20_000 });
  await tile.focus();
  await tile.press('Enter');
  // Wizard modal opens
  const modal = page.locator('#item-variation-modal.active, #item-variation-modal');
  await expect(page.locator('#item-variation-modal')).toHaveClass(/active/, { timeout: 15_000 });
  const addCta = page.locator('.pos-v5-item-add-cta').first();
  await expect(addCta).toBeAttached({ timeout: 10_000 });
  await addCta.dispatchEvent('click');
  // Coca lands in cart → wait for a cart line.
  await expect(page.locator('.pos-v5-cart-item').first()).toBeVisible({ timeout: 20_000 });
}

/** Open the cart panel on viewports where it is off-canvas (md hidden until toggled). */
async function ensureCartVisible(page) {
  const aside = page.locator('#pos-cart');
  // On lg/xl the aside is fixed-visible; on smaller it may be off-canvas.
  const box = await aside.boundingBox().catch(() => null);
  if (!box || box.width === 0) {
    // best-effort open via the cart toggle if present
    const toggle = page.locator('[data-pos-cart-toggle], .db-pos-cartOpen').first();
    if (await toggle.count()) await toggle.dispatchEvent('click').catch(() => {});
  }
}

function noConsoleErrors(testTitle) {
  const errs = (consoleErrorsByTest.get(testTitle) || []).filter(
    (e) =>
      // ignore aborted order POST (our own chain-safety route) + benign net noise
      !/Failed to load resource|net::ERR_FAILED|aborted|ERR_ABORTED/i.test(e),
  );
  expect(errs, `console errors: ${JSON.stringify(errs, null, 2)}`).toEqual([]);
}

// -----------------------------------------------------------------------------

test.describe('CAISSE POS Ergonomics (Wave-1 heals)', () => {
  test.describe.configure({ timeout: 90_000 });

  // ---- POS-ERG-01 : occlusion at 1024×768 -----------------------------------
  test('POS-ERG-01 — right-most product tile not occluded by fixed cart @1024x768', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    await installChainSafety(page, testInfo.title);
    await loginAsPosOperator(page);
    await page.waitForLoadState('networkidle').catch(() => {});

    const tiles = page.locator('.pos-v5-tile');
    await expect(tiles.first()).toBeVisible({ timeout: 20_000 });
    const aside = page.locator('#pos-cart');
    const asideBox = await aside.boundingBox();
    expect(asideBox, 'cart aside must be measurable').toBeTruthy();

    // Find the right-most tile geometrically and assert it ends left of the cart.
    const n = await tiles.count();
    let maxRight = 0;
    let worst = null;
    for (let i = 0; i < n; i++) {
      const b = await tiles.nth(i).boundingBox();
      if (!b) continue;
      const right = b.x + b.width;
      if (right > maxRight) {
        maxRight = right;
        worst = b;
      }
    }
    expect(worst, 'at least one tile measurable').toBeTruthy();

    await page.screenshot({ path: path.join(SHOTS_DIR, 'erg01-occlusion-1024.png'), fullPage: false });

    // The right edge of the right-most tile must not slide under the cart panel.
    // Allow a 1px rounding tolerance.
    expect(
      maxRight,
      `right-most tile right=${maxRight.toFixed(1)} must be <= cart-aside left=${asideBox.x.toFixed(1)} (else occluded)`,
    ).toBeLessThanOrEqual(asideBox.x + 1);

    noConsoleErrors(testInfo.title);
  });

  // ---- POS-ERG-02 : operator-bar brand collapse / nav overlap ---------------
  test('POS-ERG-02 — operator-bar brand keeps width; nav does not overlap title', async ({ page }, testInfo) => {
    // lg viewport — the densest nav (all action buttons rendered with labels).
    await page.setViewportSize({ width: 1280, height: 800 });
    await installChainSafety(page, testInfo.title);
    await loginAsPosOperator(page);
    await page.waitForLoadState('networkidle').catch(() => {});

    const brand = page.locator('.pos-v5-operator-bar__brand');
    const title = page.locator('.pos-v5-operator-bar__title');
    const nav = page.locator('.pos-v5-operator-bar__actions');
    // The operator-bar itself must be attached. The brand may be COLLAPSED to 0
    // (the defect) → do NOT wait for toBeVisible; measure geometry directly so a
    // collapsed brand reproduces RED instead of timing out.
    await expect(page.locator('.pos-v5-operator-bar')).toBeAttached({ timeout: 20_000 });
    await expect(nav).toBeVisible({ timeout: 15_000 });

    // ANTI-THEATER PRECONDITION: the defect (brand starved to 0) only manifests
    // with the DENSEST nav — i.e. when the kiosk-cash button is present. If this
    // DB has 0 pending kiosk-cash orders the button vanishes, the nav shrinks,
    // and the collapse can't occur → the test would green-wash. Guard it so the
    // test fails loudly if its triggering condition is absent.
    // (Chain-safety forbids creating orders, so we assert the precondition holds.)
    await expect(
      page.locator('[data-testid="kiosk-cash-open"]'),
      'ERG-02 requires the dense nav: kiosk-cash button must be present (DB must have >=1 pending kiosk-cash order)',
    ).toBeVisible({ timeout: 15_000 });

    const brandBox = await brand.boundingBox();
    const titleBox = await title.boundingBox();
    const navBox = await nav.boundingBox();
    expect(brandBox && titleBox && navBox, 'brand/title/nav measurable').toBeTruthy();

    await page.screenshot({ path: path.join(SHOTS_DIR, 'erg02-operator-bar-1280.png'), fullPage: false });

    // Brand column must not be starved to 0 — title must have real width.
    expect(titleBox.width, `operator-bar title width=${titleBox.width} must be > 40px (not collapsed)`).toBeGreaterThan(40);
    expect(brandBox.width, `operator-bar brand width=${brandBox.width} must be > 60px`).toBeGreaterThan(60);

    // Nav must not horizontally overlap the title (its left edge >= title right edge,
    // 2px tolerance). On a 2-col grid they sit side by side.
    expect(
      navBox.x,
      `nav left=${navBox.x.toFixed(1)} must be >= title right=${(titleBox.x + titleBox.width).toFixed(1)} (no overlap)`,
    ).toBeGreaterThanOrEqual(titleBox.x + titleBox.width - 2);

    noConsoleErrors(testInfo.title);
  });

  // ---- POS-ERG-03 : FR currency on cart totals / grand-total / pay CTA -------
  test('POS-ERG-03 — cart total, grand-total and pay CTA render FR currency (0,90 €)', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await installChainSafety(page, testInfo.title);
    await loginAsPosOperator(page);
    await page.waitForLoadState('networkidle').catch(() => {});
    await addCocaToCart(page);
    await ensureCartVisible(page);

    const linePrice = page.locator('.pos-v5-cart-item__price').first();
    const grandTotal = page.locator('[data-testid="pos-grand-total"]');
    const payCta = page.locator('[data-testid="pos-v5-pay"]');

    await expect(linePrice).toBeVisible({ timeout: 15_000 });
    await expect(grandTotal).toBeVisible();
    await expect(payCta).toBeVisible();

    const lineText = (await linePrice.innerText()).trim();
    const grandText = (await grandTotal.innerText()).trim();
    const ctaText = (await payCta.innerText()).trim();

    await page.screenshot({ path: path.join(SHOTS_DIR, 'erg03-currency-fr.png'), fullPage: false });

    for (const [label, txt] of [
      ['cart line price', lineText],
      ['grand total', grandText],
      ['pay CTA', ctaText],
    ]) {
      expect(txt, `${label} "${txt}" must match FR money /\\d+,\\d{2} €/`).toMatch(FR_MONEY);
      expect(txt, `${label} "${txt}" must NOT be en-US dot-decimal`).not.toMatch(ENUS_MONEY);
    }

    noConsoleErrors(testInfo.title);
  });

  // ---- POS-ERG-04 : in-cart qty stepper >= 44px -----------------------------
  test('POS-ERG-04 — in-cart qty stepper buttons >= 44px touch target', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await installChainSafety(page, testInfo.title);
    await loginAsPosOperator(page);
    await page.waitForLoadState('networkidle').catch(() => {});
    await addCocaToCart(page);
    await ensureCartVisible(page);

    const stepperBtns = page.locator('.pos-v5-cart-item__qty .pos-v5-qty__btn');
    await expect(stepperBtns.first()).toBeVisible({ timeout: 15_000 });

    await page.screenshot({ path: path.join(SHOTS_DIR, 'erg04-stepper.png'), fullPage: false });

    const count = await stepperBtns.count();
    expect(count, 'at least one stepper button').toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
      const b = await stepperBtns.nth(i).boundingBox();
      expect(b, `stepper btn #${i} measurable`).toBeTruthy();
      expect(b.height, `stepper btn #${i} height=${b.height} must be >= ${MIN_TAP}`).toBeGreaterThanOrEqual(MIN_TAP);
      expect(b.width, `stepper btn #${i} width=${b.width} must be >= ${MIN_TAP}`).toBeGreaterThanOrEqual(MIN_TAP);
    }

    // OVERFLOW GUARD: enlarging the stepper to 44px must not blow out the narrow
    // (lg = 360px) cart panel horizontally. scrollWidth must not exceed clientWidth.
    const overflow = await page.locator('#pos-cart').evaluate((el) => ({
      scrollW: el.scrollWidth,
      clientW: el.clientWidth,
    }));
    expect(
      overflow.scrollW,
      `cart horizontal overflow: scrollWidth=${overflow.scrollW} > clientWidth=${overflow.clientW}`,
    ).toBeLessThanOrEqual(overflow.clientW + 1);

    noConsoleErrors(testInfo.title);
  });

  // ---- POS-ERG-05 : segmented + discount controls >= 44px -------------------
  test('POS-ERG-05 — order-type segmented + discount controls >= 44px', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await installChainSafety(page, testInfo.title);
    await loginAsPosOperator(page);
    await page.waitForLoadState('networkidle').catch(() => {});
    await addCocaToCart(page);
    await ensureCartVisible(page);

    const segmented = page.locator('.pos-v5-segmented__item');
    const discountInput = page.locator('[data-testid="pos-discount-input"]');
    const discountApply = page.locator('[data-testid="pos-discount-apply"]');

    await expect(segmented.first()).toBeVisible({ timeout: 15_000 });
    await expect(discountInput).toBeVisible();
    await expect(discountApply).toBeVisible();

    await page.screenshot({ path: path.join(SHOTS_DIR, 'erg05-segmented-discount.png'), fullPage: false });

    const segCount = await segmented.count();
    for (let i = 0; i < segCount; i++) {
      const b = await segmented.nth(i).boundingBox();
      expect(b, `segmented item #${i} measurable`).toBeTruthy();
      expect(b.height, `segmented item #${i} height=${b.height} must be >= ${MIN_TAP}`).toBeGreaterThanOrEqual(MIN_TAP);
    }

    const inBox = await discountInput.boundingBox();
    const applyBox = await discountApply.boundingBox();
    expect(inBox.height, `discount input height=${inBox.height} must be >= ${MIN_TAP}`).toBeGreaterThanOrEqual(MIN_TAP);
    expect(applyBox.height, `discount apply height=${applyBox.height} must be >= ${MIN_TAP}`).toBeGreaterThanOrEqual(MIN_TAP);

    // OVERFLOW GUARD: the discount row (dropdown + input + Apply) and segmented
    // control must still fit the narrow (lg = 360px) cart with no h-scroll.
    const overflow = await page.locator('#pos-cart').evaluate((el) => ({
      scrollW: el.scrollWidth,
      clientW: el.clientWidth,
    }));
    expect(
      overflow.scrollW,
      `cart horizontal overflow: scrollWidth=${overflow.scrollW} > clientWidth=${overflow.clientW}`,
    ).toBeLessThanOrEqual(overflow.clientW + 1);

    noConsoleErrors(testInfo.title);
  });
});
