// test-e2e-mobile-design-full-wave-B — Wave B : Mobile catalog + Tacos XXL wizard
//
// Audit `mobile-design-full-2026-05-11` round-1, GStack main team.
// Covers : Home (greeting + featured + loyalty banner segment + categories grid)
//        → Menu (all items + 13 chips filter sampler : tacos + burgers)
//        → Item Tacos XXL wizard (6 captured steps + recap)
//        → Cart 1-line (18,00 €) → Cart empty (after trash).
// 16 numbered states, each captured via mega-audit-snap (PNG + DOM + console +
// network) into tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-B/
//
// Forked from tests/e2e/audit-mobile-wave-B-2026-05-11.spec.js. Differences :
//   - 16 states (vs 36 in source) — design-audit scope focuses Tacos XXL only
//   - Pricing decomposition hard-asserted on recap + cart (PARITY-1 P0)
//   - Layout assertions are SOFT (log + snap) so the adversarial reviewer
//     receives intact quartets even when design gaps exist (e.g. Home loyalty
//     banner missing, Home categories grid only 6 of 13 visible).
//   - Featured slug verification via window.LC.menu.findItem() JS eval (no
//     data-slug attribute on the DOM card).
//
// Pricing target (Tacos XXL composition, sourced from mobile/data/menu.js
// priceFor + FRITES_STYLES + FORMULES) :
//   12,50 (base)
// + 1,00 (Œuf supplement, step3-supplements)
// + 3,00 (Menu complet f-menu, step4-menu)
// + 1,00 (Cheddar fondu fs-cheddar, step5-frites-style)
// + 0,00 (1ère sauce frites Ketchup — gratuite per priceFor)
// + 0,50 (2ème sauce frites Barbecue — +0,50 € per priceFor)
// ──────────────────────────────────────────────────────────────
// = 18,00 €
//
// Wizard cascade (computeActiveSteps for Tacos XXL, menu='full') :
//   viandes → sauce → crudités → supplements → menu → drink → fritesStyle
//          → fritesSauce → recap         (9 internal steps)
// We CAPTURE 6 step states (08-13) + recap (14), advancing through crudités
// and drink internally without snap.
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js \
//     tests/e2e/test-e2e-mobile-design-full-wave-B.spec.js \
//     --reporter=list --workers=1 --timeout=180000

const { test, expect } = require('@playwright/test');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-mobile-design-full-wave-B');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

test.use({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
  userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
});

// Soft-check helper : log observation, never throw — preserves capture flow so
// the adversarial supervisor receives the artifact even when the assertion
// would have failed (the gap becomes a finding in the next layer).
function softCheck(observations, label, cond, evidence) {
  observations.push(`${label}: ${cond ? 'PASS' : 'FAIL'} (${evidence})`);
}

// Helper : guarantee aria-disabled='false' on the wizard CTA before tap.
async function clickNext(page, name = /Suivant|Ajouter au panier/i) {
  const cta = page.locator('button.lc-btn').filter({ hasText: name }).last();
  await expect(cta).toBeVisible({ timeout: 10_000 });
  await expect(cta).toHaveAttribute('aria-disabled', 'false', { timeout: 10_000 });
  await cta.click();
  // Small settle for React re-render + step focus transition (useEffect on heading).
  await page.waitForTimeout(180);
}

// Helper : extract the price string from the wizard sticky CTA (last span = currency).
async function readCtaPrice(page) {
  return await page.evaluate(() => {
    const btns = document.querySelectorAll('button.lc-btn');
    const last = btns[btns.length - 1];
    if (!last) return null;
    const spans = last.querySelectorAll('span');
    return spans[spans.length - 1].textContent.trim();
  });
}

test('mobile-design-full wave B — home → menu (13 cats) → Tacos XXL wizard → cart (16 states)', async ({ browser }) => {
  test.setTimeout(360_000);

  // Mobile context — match iOS-frame target documented at the top of
  // mobile/screens-item-steps.jsx ("adapted to a 390×844 mobile viewport").
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });

  // Pre-seed auth + onboarding so the App boots straight to 'home'.
  // Loyalty default seed in mobile/data/loyalty.js sets balance=347 — matches
  // state 03 expectation (points pill 347 + CTA).
  await ctx.addInitScript(() => {
    localStorage.setItem('lecayenne.onboarding_seen', JSON.stringify(true));
    localStorage.setItem('lecayenne.auth', JSON.stringify({
      token: 'mock-v0',
      phone: '+33642799884',
      user_id: 12345,
    }));
  });

  const page = await ctx.newPage();
  const { snap, dispose } = attachMegaAuditRecorder(page, SCREEN_DIR);
  const observations = [];

  try {
    // ===============================================================
    // BOOT → STATE 01 — home greeting (BONJOUR or BONSOIR)
    // ===============================================================
    await page.goto(MOBILE_URL, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-screen-label="07 Home"]')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(500); // marquee + featured + categories paint

    // Greeting line — h1.lc-display starts with Bonjour or Bonsoir.
    const greetingH1 = page.locator('[data-screen-label="07 Home"] h1.lc-display').first();
    await expect(greetingH1).toBeVisible();
    const greetingText = (await greetingH1.innerText()).replace(/\s+/g, ' ').trim();
    const isMorning = /bonjour/i.test(greetingText);
    const isEvening = /bonsoir/i.test(greetingText);
    softCheck(observations, 'state01 greeting', isMorning || isEvening, `text="${greetingText}"`);
    observations.push(`state01: ${isMorning ? 'BONJOUR' : isEvening ? 'BONSOIR' : 'unknown'} branch active (h=${new Date().getHours()})`);
    await snap('01-home-greeting-am');

    // ===============================================================
    // STATE 02 — featured signature card (Tacos XXL)
    // ===============================================================
    // No data-slug attr on the rendered card — verify via window.LC.menu data
    // layer + visible name text.
    const featuredSlug = await page.evaluate(() => {
      try {
        const it = window.LC && window.LC.menu && window.LC.menu.findItem('tacos-xxl');
        return it ? it.slug : null;
      } catch (_e) { return null; }
    });
    softCheck(observations, 'state02 featured slug eval', featuredSlug === 'tacos-xxl', `LC.menu.findItem("tacos-xxl").slug="${featuredSlug}"`);
    // Featured card renders item.name uppercased — Tacos XXL → "TACOS XXL (4 VIANDES)" split across lines.
    const featuredCardText = await page.locator('[data-screen-label="07 Home"]').innerText();
    softCheck(observations, 'state02 featured name visible', /TACOS XXL/i.test(featuredCardText), 'home contains "TACOS XXL"');
    observations.push(`state02: featured card → ${featuredSlug || 'unknown'} (Tacos XXL id 104, base 12,50 €)`);
    await snap('02-home-featured-tacos-xxl');

    // ===============================================================
    // STATE 03 — home loyalty banner segment (347 pts)
    // ===============================================================
    // NOTE : ScreenHome does NOT currently render a loyalty banner. The seeded
    // balance (347) is bound on Profile screen + Orders screen + Loyalty
    // screen. We capture Home as-is and log the gap. The adversarial reviewer
    // is expected to flag "loyalty banner missing on Home" as a finding (P1 or
    // P2 depending on design spec).
    const homeFor03 = page.locator('[data-screen-label="07 Home"]');
    const homeText03 = await homeFor03.innerText();
    const has347OnHome = /\b347\b/.test(homeText03);
    const hasLoyaltyWordOnHome = /(fidélité|loyalty|points?|carte)/i.test(homeText03);
    softCheck(observations, 'state03 home shows 347', has347OnHome, `text contains "347"=${has347OnHome}`);
    softCheck(observations, 'state03 home mentions loyalty', hasLoyaltyWordOnHome, `loyalty-word match=${hasLoyaltyWordOnHome}`);

    // Bonus : verify the data layer DOES carry 347 (so we know the gap is UI,
    // not data). If LC.loyalty seed differs, log it.
    const balanceFromData = await page.evaluate(() => {
      try { return window.LC && window.LC.loyalty && window.LC.loyalty.account.balance; } catch (_e) { return null; }
    });
    softCheck(observations, 'state03 LC.loyalty.account.balance', balanceFromData === 347, `actual=${balanceFromData}`);
    observations.push(`state03: home loyalty banner — data layer seed=${balanceFromData}, visible on home=${has347OnHome}`);
    await snap('03-home-loyalty-banner');

    // ===============================================================
    // STATE 04 — home categories grid (target 13, source renders 6)
    // ===============================================================
    // ScreenHome renders CATS.slice(0, 6) only (screens-main.jsx ~line 93).
    // The full 13-grid lives on the Menu screen as filter chips. Soft-log the
    // grid count so the reviewer can decide if this is a design gap (Home
    // should show all 13) or expected (Menu is the canonical 13-grid).
    const homeGridCount = await page.evaluate(() => {
      const home = document.querySelector('[data-screen-label="07 Home"]');
      if (!home) return 0;
      // Categories grid uses inline grid-template-columns: 'repeat(3, 1fr)'
      // with each child being a clickable category tile (onClick goes to 'menu').
      const grids = Array.from(home.querySelectorAll('div')).filter((d) => {
        const s = d.getAttribute('style') || '';
        return /grid-template-columns:\s*repeat\(3,\s*1fr\)/.test(s);
      });
      // Return the max children count among matched grids (categories section).
      let max = 0;
      for (const g of grids) {
        const c = g.children.length;
        if (c > max) max = c;
      }
      return max;
    });
    softCheck(observations, 'state04 home grid count', homeGridCount === 13, `home renders ${homeGridCount} category tiles (target 13)`);
    // Verify the data layer carries 13 categories (CATS.length).
    const dataLayerCatsCount = await page.evaluate(() => (window.CATS || []).length);
    softCheck(observations, 'state04 CATS.length', dataLayerCatsCount === 13, `data layer carries ${dataLayerCatsCount} categories`);
    observations.push(`state04: home cat-grid renders ${homeGridCount} tiles; data layer has ${dataLayerCatsCount} categories`);
    await snap('04-home-categories-grid-13');

    // ===============================================================
    // STATE 05 — menu screen, all items (filter='all', no chip selected)
    // ===============================================================
    await page.locator('.lc-tabs .lc-tab').nth(1).click();
    await expect(page.locator('[data-screen-label="08 Menu"]')).toBeVisible({ timeout: 5_000 });
    await page.waitForTimeout(400);

    // Count filter chips (1 "Tout" + 13 categories = 14 chips).
    const chipCount = await page.evaluate(() => {
      const menu = document.querySelector('[data-screen-label="08 Menu"]');
      if (!menu) return 0;
      // Filter chip row uses `display: flex; gap: 8px; overflow-x: auto;` direct child of menu.
      const chips = Array.from(menu.querySelectorAll('button')).filter((b) => {
        const s = b.getAttribute('style') || '';
        return /border-radius:\s*999/.test(s) && /padding:\s*10px\s+16px/.test(s);
      });
      return chips.length;
    });
    softCheck(observations, 'state05 menu chips count', chipCount === 14, `menu shows ${chipCount} filter chips (1 Tout + 13 cats = 14)`);

    // Count distinct category section h3 headings ("[icon] [name]").
    const sectionCount = await page.evaluate(() => {
      const menu = document.querySelector('[data-screen-label="08 Menu"]');
      if (!menu) return 0;
      return menu.querySelectorAll('h3.lc-display').length;
    });
    softCheck(observations, 'state05 menu category sections', sectionCount === 13, `menu shows ${sectionCount} category sections (target 13)`);
    observations.push(`state05: menu all items — ${chipCount} chips, ${sectionCount} sections`);
    await snap('05-menu-all-items');

    // ===============================================================
    // STATE 06 — menu filter on Tacos category
    // ===============================================================
    const tacosChip = page.locator('[data-screen-label="08 Menu"]').getByRole('button', { name: /Nos Tacos/i }).first();
    await expect(tacosChip).toBeVisible({ timeout: 5_000 });
    await tacosChip.click();
    await page.waitForTimeout(350);
    // After filter, expect only 1 section (Nos Tacos).
    const tacosSectionCount = await page.evaluate(() => {
      const menu = document.querySelector('[data-screen-label="08 Menu"]');
      return menu ? menu.querySelectorAll('h3.lc-display').length : 0;
    });
    softCheck(observations, 'state06 tacos filter section count', tacosSectionCount === 1, `${tacosSectionCount} sections visible`);
    observations.push(`state06: menu filter=Nos Tacos, ${tacosSectionCount} section visible`);
    await snap('06-menu-filter-tacos');

    // ===============================================================
    // STATE 07 — menu filter on Burgers category
    // ===============================================================
    const burgersChip = page.locator('[data-screen-label="08 Menu"]').getByRole('button', { name: /Nos Burgers/i }).first();
    await expect(burgersChip).toBeVisible({ timeout: 5_000 });
    await burgersChip.click();
    await page.waitForTimeout(350);
    const burgersSectionCount = await page.evaluate(() => {
      const menu = document.querySelector('[data-screen-label="08 Menu"]');
      return menu ? menu.querySelectorAll('h3.lc-display').length : 0;
    });
    softCheck(observations, 'state07 burgers filter section count', burgersSectionCount === 1, `${burgersSectionCount} sections visible`);
    observations.push(`state07: menu filter=Nos Burgers, ${burgersSectionCount} section visible`);
    await snap('07-menu-filter-burgers');

    // Reset to "Tout" so Tacos XXL item card is reachable below.
    await page.locator('[data-screen-label="08 Menu"]').getByRole('button', { name: /^Tout$/i }).first().click();
    await page.waitForTimeout(250);

    // ===============================================================
    // WIZARD : Open Tacos XXL (4 viandes) — target total 18,00 €
    // ===============================================================
    const tacosCard = page.locator('[data-screen-label="08 Menu"]').getByText('Tacos XXL (4 Viandes)', { exact: false }).first();
    await expect(tacosCard).toBeVisible({ timeout: 5_000 });
    await tacosCard.click();
    await expect(page.locator('[data-screen-label^="09 Item Wizard"]')).toBeVisible({ timeout: 5_000 });
    await page.waitForTimeout(400);

    // ---------------------------------------------------------------
    // STATE 08 — step 1 viandes (0/4 progress, CTA aria-disabled='true')
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Viandes"]')).toBeVisible({ timeout: 5_000 });
    const ctaInit = page.locator('button.lc-btn').filter({ hasText: /Suivant/i }).last();
    await expect(ctaInit).toHaveAttribute('aria-disabled', 'true');
    observations.push('state08: viandes step 1, 0/4 picked, CTA aria-disabled=true (expected)');
    await snap('08-item-tacos-xxl-step1-viandes');

    // Pick 4 meats — order chosen to match the documented audit (Merguez, Kefta, Cordon Bleu, Tenders).
    for (const meat of ['Merguez', 'Kefta', 'Cordon Bleu', 'Tenders']) {
      await page.getByRole('checkbox', { name: new RegExp(meat, 'i') }).first().click();
    }
    await page.waitForTimeout(180);
    await expect(ctaInit).toHaveAttribute('aria-disabled', 'false', { timeout: 5_000 });
    await clickNext(page);

    // ---------------------------------------------------------------
    // STATE 09 — step 2 sauce (Ketchup picked, 1ère gratuite)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Sauce"]')).toBeVisible({ timeout: 5_000 });
    await page.getByRole('checkbox', { name: /^Ketchup$/i }).first().click();
    await page.waitForTimeout(150);
    // CTA price at this point should still equal base (12,50 € — no extra cost
    // for the 1st sauce).
    const priceAfterSauce = await readCtaPrice(page);
    softCheck(observations, 'state09 price after Ketchup', /12,50\s*€/.test(priceAfterSauce), `cta="${priceAfterSauce}"`);
    observations.push(`state09: sauce step, Ketchup picked (1ère gratuite), cta=${priceAfterSauce}`);
    await snap('09-item-tacos-xxl-step2-sauce');
    await clickNext(page);

    // ---------------------------------------------------------------
    // (Crudités step — advance without capture)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Crudités"]')).toBeVisible({ timeout: 5_000 });
    // Keep default crudités; just advance.
    await clickNext(page);

    // ---------------------------------------------------------------
    // STATE 10 — step 3 supplements (Œuf +1,00 €)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Suppléments"]')).toBeVisible({ timeout: 5_000 });
    // Suppléments cards use role="checkbox" (custom). Name starts with "Œuf".
    await page.getByRole('checkbox', { name: /^Œuf/i }).first().click();
    await page.waitForTimeout(180);
    const priceAfterOeuf = await readCtaPrice(page);
    softCheck(observations, 'state10 price after Œuf', /13,50\s*€/.test(priceAfterOeuf), `cta="${priceAfterOeuf}" (expect 13,50 €)`);
    observations.push(`state10: supplements, Œuf +1,00 €, cta=${priceAfterOeuf}`);
    await snap('10-item-tacos-xxl-step3-supplement-oeuf');
    await clickNext(page);

    // ---------------------------------------------------------------
    // STATE 11 — step 4 menu (Menu complet +3,00 €)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Faire un menu"]')).toBeVisible({ timeout: 5_000 });
    await page.getByRole('radio', { name: /Menu complet/i }).first().click();
    await page.waitForTimeout(180);
    const priceAfterMenu = await readCtaPrice(page);
    softCheck(observations, 'state11 price after Menu complet', /16,50\s*€/.test(priceAfterMenu), `cta="${priceAfterMenu}" (expect 16,50 €)`);
    observations.push(`state11: menu, Menu complet f-menu +3,00 €, cta=${priceAfterMenu}`);
    await snap('11-item-tacos-xxl-step4-menu');
    await clickNext(page);

    // ---------------------------------------------------------------
    // (Drink step — advance without capture)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Boisson"]')).toBeVisible({ timeout: 5_000 });
    await page.getByRole('radio', { name: /Coca-Cola 33cl/i }).first().click();
    await page.waitForTimeout(180);
    await clickNext(page);

    // ---------------------------------------------------------------
    // STATE 12 — step 5 frites style (Cheddar fondu +1,00 €)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Style de frites"]')).toBeVisible({ timeout: 5_000 });
    await page.getByRole('radio', { name: /^Cheddar fondu/i }).first().click();
    await page.waitForTimeout(180);
    const priceAfterFs = await readCtaPrice(page);
    softCheck(observations, 'state12 price after Cheddar fondu', /17,50\s*€/.test(priceAfterFs), `cta="${priceAfterFs}" (expect 17,50 €)`);
    observations.push(`state12: frites style, Cheddar fondu fs-cheddar +1,00 €, cta=${priceAfterFs}`);
    await snap('12-item-tacos-xxl-step5-frites-style-cheddar-fondu');
    await clickNext(page);

    // ---------------------------------------------------------------
    // STATE 13 — step 6 frites sauce (Ketchup 1ère gratuite + Barbecue 2ème +0,50 €)
    // ---------------------------------------------------------------
    await expect(page.locator('[data-screen-label="09 Item Wizard Sauce frites"]')).toBeVisible({ timeout: 5_000 });
    // Pick 1ère frites sauce (Ketchup, free).
    await page.getByRole('checkbox', { name: /^Ketchup$/i }).first().click();
    await page.waitForTimeout(150);
    // Pick 2ème (Barbecue, +0,50 €) — this is the "2ème sauce BBQ" target.
    await page.getByRole('checkbox', { name: /^Barbecue$/i }).first().click();
    await page.waitForTimeout(180);
    const priceAfterFsauce = await readCtaPrice(page);
    softCheck(observations, 'state13 price after 2 frites sauces', /18,00\s*€/.test(priceAfterFsauce), `cta="${priceAfterFsauce}" (expect 18,00 €)`);
    observations.push(`state13: frites sauce, Ketchup (free) + Barbecue (+0,50 €), cta=${priceAfterFsauce}`);
    await snap('13-item-tacos-xxl-step6-frites-sauce-bbq');
    await clickNext(page);

    // ===============================================================
    // STATE 14 — recap (PARITY-1 P0 hard-assert : 18,00 €)
    // ===============================================================
    await expect(page.locator('[data-screen-label="09 Item Wizard Récapitulatif"]')).toBeVisible({ timeout: 5_000 });
    await page.waitForTimeout(300);
    const recapPrice = await readCtaPrice(page);
    observations.push(`state14: recap CTA price="${recapPrice}" (PARITY-1 target 18,00 €)`);
    // HARD ASSERT — PARITY-1 P0 numeric_integrity.
    expect(recapPrice, 'recap total must be 18,00 € (PARITY-1)').toMatch(/18,00\s*€/);
    await snap('14-item-tacos-xxl-recap');

    // ===============================================================
    // STATE 15 — cart (1 line @ 18,00 €) — PARITY-1 P0 hard-assert
    // ===============================================================
    await clickNext(page, /Ajouter au panier/i);
    await expect(page.locator('[data-screen-label="10 Cart"]')).toBeVisible({ timeout: 5_000 });
    await page.waitForTimeout(500);

    // Read the cart sticky-checkout total (lc-display, format "18,00 €").
    const cartTotal = await page.evaluate(() => {
      const cart = document.querySelector('[data-screen-label="10 Cart"]');
      if (!cart) return null;
      // Sticky checkout total : div.lc-display inside the bottom checkout bar.
      // Heuristic : the largest font-size display = total.
      const displays = Array.from(cart.querySelectorAll('div.lc-display'));
      // Filter to those whose text contains "€" (totals only).
      const totals = displays.filter((d) => /€/.test(d.textContent || ''));
      return totals.length ? totals[totals.length - 1].textContent.trim() : null;
    });
    observations.push(`state15: cart total="${cartTotal}" (PARITY-1 target 18,00 €)`);
    // HARD ASSERT — PARITY-1 P0 numeric_integrity (cart === recap).
    expect(cartTotal, 'cart total must be 18,00 € (PARITY-1 cross-check vs recap)').toMatch(/18,00\s*€/);

    // Soft-check : qty stepper min=1 clamp.
    const stepperVal = await page.locator('[data-screen-label="10 Cart"] .lc-stepper-val').first().textContent();
    softCheck(observations, 'state15 qty stepper init', stepperVal && stepperVal.trim() === '1', `stepper="${stepperVal}"`);
    // Try to decrement once → stays at 1 (Math.max(1, qty - 1) in setCart).
    const minusBtn = page.locator('[data-screen-label="10 Cart"] .lc-stepper button').first();
    await minusBtn.click();
    await page.waitForTimeout(200);
    const stepperAfter = await page.locator('[data-screen-label="10 Cart"] .lc-stepper-val').first().textContent();
    softCheck(observations, 'state15 stepper clamp min=1', stepperAfter && stepperAfter.trim() === '1', `after decrement stepper="${stepperAfter}"`);

    await snap('15-cart-1-line');

    // ===============================================================
    // STATE 16 — cart empty (after trash)
    // ===============================================================
    // The line row ends with a transparent <button> wrapping the trash icon
    // (I.Trash) — locate it via its position (last button inside the row).
    const trashBtn = page.locator('[data-screen-label="10 Cart"] button').filter({
      has: page.locator('svg'),
    }).last();
    // Heuristic above can be ambiguous (back arrow also has svg); narrow by
    // selecting the trash icon button which sits at the end of each line row.
    // Click via JS for robustness.
    await page.evaluate(() => {
      const cart = document.querySelector('[data-screen-label="10 Cart"]');
      if (!cart) return false;
      // Find the cart item row (display:flex with cream bg in inline style),
      // then click its last <button> child (the trash button).
      const rows = Array.from(cart.querySelectorAll('div')).filter((d) => {
        const s = d.getAttribute('style') || '';
        return /background:\s*var\(--cream\)/.test(s) && /border-radius:\s*16px/.test(s) && /display:\s*flex/.test(s);
      });
      if (!rows.length) return false;
      // The first matching is the line item row (subsequent are loyalty banner /
      // upsell cards). Click its last button.
      const row = rows[0];
      const btns = row.querySelectorAll('button');
      if (btns.length) {
        btns[btns.length - 1].click();
        return true;
      }
      return false;
    });
    await page.waitForTimeout(500);

    // After trash, the cart shows the empty-state block (no line item, no
    // sticky checkout). Verify empty-state copy.
    const emptyCopy = await page.locator('[data-screen-label="10 Cart"]').innerText();
    const hasEmptyState = /panier est vide/i.test(emptyCopy);
    softCheck(observations, 'state16 empty-state visible', hasEmptyState, `copy snippet="${emptyCopy.replace(/\s+/g, ' ').slice(0, 80)}..."`);

    // Soft-check : empty-state copy length ≥ 20 chars (combined empty title + subtitle).
    const emptyBlockText = await page.evaluate(() => {
      const cart = document.querySelector('[data-screen-label="10 Cart"]');
      if (!cart) return '';
      // Find the empty-state block (cream bg, has 🛒 emoji).
      const blocks = Array.from(cart.querySelectorAll('div')).filter((d) => /panier est vide/i.test(d.textContent || ''));
      return blocks.length ? blocks[0].textContent.trim() : '';
    });
    softCheck(observations, 'state16 empty copy length ≥ 20', emptyBlockText.length >= 20, `length=${emptyBlockText.length}, text="${emptyBlockText}"`);

    // Soft-check : primary CTA presence (button.lc-btn--orange OR any button in
    // the empty block). Source currently renders NO CTA — this gap is the
    // adversarial finding target.
    const emptyHasCta = await page.evaluate(() => {
      const cart = document.querySelector('[data-screen-label="10 Cart"]');
      if (!cart) return false;
      const blocks = Array.from(cart.querySelectorAll('div')).filter((d) => /panier est vide/i.test(d.textContent || ''));
      if (!blocks.length) return false;
      return blocks[0].querySelector('button') !== null;
    });
    softCheck(observations, 'state16 empty-state has primary CTA', emptyHasCta, `cta in empty block=${emptyHasCta}`);
    observations.push(`state16: cart after trash, empty-state visible=${hasEmptyState}, copy length=${emptyBlockText.length}, has CTA=${emptyHasCta}`);
    await snap('16-cart-empty');

    // ===============================================================
    // Emit observation log for adversarial supervisor.
    // ===============================================================
    console.log('\n[wave-B observations]\n  ' + observations.join('\n  '));
  } finally {
    dispose();
    await ctx.close();
  }
});
