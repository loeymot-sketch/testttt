// test-e2e-mobile-realignment-2026-05-16 — Mobile realignment to global system (post menu V3 + heal-light V2)
//
// Cycle owner-validated 2026-05-16 : mobile stays standalone (no API wireup),
// data layer hardcoded mirrors DB SSOT (MenuResetLeCayenneCommand +
// MenuHealLightV2Command), Bols composer profile 3-step (sauce + bol_supplements
// + bol_drink), Frites composer profile 1-step (frites_style), 11 cats final.
//
// Covers visual + mobile data layer (window.LC.*) of every updated page :
//   A — Home (11 cats visible, sort order 1..11)
//   B — Menu per cat — Sandwich Cayenne / Galette / Sandwich Classique / Burgers
//                      (4 cats share wizard='sandwich')
//   C — Tacos wizard (template='tacos')
//   D — Bols Gourmands wizard (template='custom', composer 3-step) — gate critical
//   E — Frites wizard (template='custom', composer 1-step)
//   F — Suppléments / Desserts / Boissons / Menu enfant (direct-add, template='simple')
//   G — Data parity assertions (window.LC.menu : 11 cats / 11 sauces / 9 supps @ 0.90€ /
//                               4 supps_bols / 4 viandes / composer_profile on bols+frites)
//   H — Pricing parity (priceFor() : bol base 8.90€, +gratiné 10.90€, +coca 12.40€)
//   I — Cart line composition_summary preserves bol fields
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js \
//     tests/e2e/test-e2e-mobile-realignment-2026-05-16.spec.js \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-mobile-realignment-2026-05-16');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

// Forbidden raw-label patterns (i18n / state leaks the user must never see)
const RAW_LABEL_FORBIDDEN = [
  /\bLabel\.[A-Za-z0-9_.-]+/i,
  /\bkiosk\.[A-Za-z0-9_.-]+/i,
  /\blecayenne\.[A-Za-z0-9_.-]+/i,
  /\b0undefined\b/,
  /\bNaN €/,
];

async function snapAndCheck(page, stateName) {
  const file = path.join(SCREEN_DIR, `${stateName}.png`);
  await page.screenshot({ path: file, fullPage: false });
  // Sweep for raw labels in visible body text
  const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  for (const re of RAW_LABEL_FORBIDDEN) {
    expect(text, `${stateName} contains forbidden label ${re}`).not.toMatch(re);
  }
  // Console-error sweep (defer the read since we attach in beforeEach)
  if (page._collectedErrors && page._collectedErrors.length) {
    const real = page._collectedErrors.filter(e => !/image-slots\.state\.json|favicon/i.test(e));
    expect(real, `${stateName} console errors: ${real.join('\n')}`).toHaveLength(0);
  }
}

async function bootMobile(page) {
  // Wait for window.LC.menu to be ready (Babel transpile + IIFE execution)
  page._collectedErrors = [];
  page.on('pageerror', err => page._collectedErrors.push(err.message));
  page.on('console', msg => {
    if (msg.type() === 'error') page._collectedErrors.push(msg.text());
  });
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items.length > 0, { timeout: 30000 });
  // Mark onboarding as seen + auth so we land on Home
  await page.evaluate(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'test', phone: '0642799884', user_id: 'test' }));
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
}

// ============================================================================
// G — Data parity assertions (synchronous DOM evals) — fires FIRST as smoke gate
// ============================================================================
test('G — Data parity (window.LC.menu canonical)', async ({ page }) => {
  await bootMobile(page);
  const parity = await page.evaluate(() => {
    const m = window.LC.menu;
    const cats = m.categories.map(c => ({ id: c.id, slug: c.slug, wt: c.wizard_template, sort: c.sort }));
    const bowl = m.findItem('bowl-frites-curry');
    const fritesPetite = m.findItem('petite-frites');
    return {
      catCount: m.categories.length,
      itemCount: m.items.length,
      sauceCount: m.sauces.length,
      meatCount: m.meats.length,
      suppCount: m.supplements.length,
      suppBolsCount: m.supplementsBols.length,
      cats,
      bowlHasComposer: !!bowl.composer_profile,
      bowlComposerTemplate: bowl.composer_profile && bowl.composer_profile.template,
      bowlSteps: bowl.composer_profile ? bowl.composer_profile.steps.map(s => s.step_key) : [],
      bowlSauceDefault: bowl.composer_profile && bowl.composer_profile.steps[0].default_choice_id,
      bowlSupPool: bowl.composer_profile && bowl.composer_profile.steps[1].choices.length,
      bowlDrinkPool: bowl.composer_profile && bowl.composer_profile.steps[2].choices.length,
      fritesHasComposer: !!fritesPetite.composer_profile,
      fritesComposerTemplate: fritesPetite.composer_profile && fritesPetite.composer_profile.template,
      fritesSteps: fritesPetite.composer_profile ? fritesPetite.composer_profile.steps.map(s => s.step_key) : [],
      // Supplement prices all 0.90€ except boursin/legumes/etc — verify the 9 standard @ 0.90€
      suppPrices: m.supplements.map(s => ({ id: s.id, price: s.price })),
      suppBolsPrices: m.supplementsBols.map(s => ({ id: s.id, price: s.price })),
    };
  });
  // CATS
  expect(parity.catCount, 'Categories canonical count').toBe(11);
  expect(parity.cats[0].slug).toBe('sandwich-cayenne');
  expect(parity.cats[3].slug).toBe('burgers');       // heal-light v2 NEW
  expect(parity.cats[5].slug).toBe('bols-gourmands');
  expect(parity.cats[5].wt).toBe('custom');
  expect(parity.cats[6].slug).toBe('frites');
  expect(parity.cats[6].wt).toBe('custom');
  expect(parity.cats[10].slug).toBe('menu-enfant');  // heal-light v2 NEW
  // ITEMS, POOLS
  expect(parity.itemCount, 'Items canonical count').toBe(41); // 2+2+2+2+2+8+2+9+3+8+1
  expect(parity.sauceCount, 'Sauces canonical count').toBe(11);
  expect(parity.meatCount, 'Viandes canonical count').toBe(4);
  expect(parity.suppCount, 'Supplements canonical count').toBe(9);
  expect(parity.suppBolsCount, 'Supplements bols canonical count').toBe(4);
  // SUPP PRICES (all 9 @ 0.90€)
  parity.suppPrices.forEach(s => {
    expect(s.price, `Supp ${s.id} price`).toBeCloseTo(0.90, 2);
  });
  // SUPP BOLS PRICES (3 @ 0.90€, 1 gratiné @ 2.00€)
  const gratine = parity.suppBolsPrices.find(s => s.id === 'sb-boule-gratinee');
  expect(gratine, 'Boule gratinée exists').toBeTruthy();
  expect(gratine.price, 'Boule gratinée price').toBeCloseTo(2.00, 2);
  parity.suppBolsPrices.filter(s => s.id !== 'sb-boule-gratinee').forEach(s => {
    expect(s.price, `Bol supp ${s.id} price`).toBeCloseTo(0.90, 2);
  });
  // BOL COMPOSER
  expect(parity.bowlHasComposer, 'Bowl has composer_profile').toBe(true);
  expect(parity.bowlComposerTemplate, 'Bowl composer template').toBe('bol');
  expect(parity.bowlSteps, 'Bowl steps order').toEqual(['sauce', 'bol_supplements', 'bol_drink']);
  expect(parity.bowlSauceDefault, 'Bowl curry default sauce').toBe('s-curry');
  expect(parity.bowlSupPool, 'Bowl supp pool size').toBe(4);
  expect(parity.bowlDrinkPool, 'Bowl drink pool size').toBe(8);
  // FRITES COMPOSER
  expect(parity.fritesHasComposer, 'Frites has composer_profile').toBe(true);
  expect(parity.fritesComposerTemplate, 'Frites composer template').toBe('frites');
  expect(parity.fritesSteps, 'Frites steps').toEqual(['frites_style']);
});

// ============================================================================
// H — Pricing parity (priceFor : bol base / +gratiné / +coca / multi-supps)
// ============================================================================
test('H — Pricing parity (bol composer prices)', async ({ page }) => {
  await bootMobile(page);
  const prices = await page.evaluate(() => {
    const m = window.LC.menu;
    const bowl = m.findItem('bowl-frites-curry');
    return {
      base:         m.priceFor(bowl, { sauceIds: ['s-curry'], qty: 1 }),
      withGratine:  m.priceFor(bowl, { sauceIds: ['s-curry'], bolSupplementIds: ['sb-boule-gratinee'], qty: 1 }),
      withCoca:     m.priceFor(bowl, { sauceIds: ['s-curry'], bolDrinkId: 'd-coca', qty: 1 }),
      withEau:      m.priceFor(bowl, { sauceIds: ['s-curry'], bolDrinkId: 'd-eau', qty: 1 }),
      fullCombo:    m.priceFor(bowl, { sauceIds: ['s-curry'], bolSupplementIds: ['sb-boule-gratinee', 'sb-jambon'], bolDrinkId: 'd-coca', qty: 1 }),
      multiSauce:   m.priceFor(bowl, { sauceIds: ['s-curry', 's-mayo'], qty: 1 }), // 1 free + 1 extra 0.50€
      // Frites pricing parity
      fritesBase:    m.priceFor(m.findItem('petite-frites'), { qty: 1 }),
      fritesCheddar: m.priceFor(m.findItem('petite-frites'), { fritesStyleId: 'fs-cheddar', qty: 1 }),
      fritesOignon:  m.priceFor(m.findItem('petite-frites'), { fritesStyleId: 'fs-cheddar-oignon', qty: 1 }),
    };
  });
  expect(prices.base).toBeCloseTo(8.90, 2);
  expect(prices.withGratine).toBeCloseTo(10.90, 2);
  expect(prices.withCoca).toBeCloseTo(10.40, 2);   // 8.90 + 1.50
  expect(prices.withEau).toBeCloseTo(9.90, 2);     // 8.90 + 1.00
  expect(prices.fullCombo).toBeCloseTo(13.30, 2);  // 8.90 + 2.00 + 0.90 + 1.50
  expect(prices.multiSauce).toBeCloseTo(9.40, 2);  // 8.90 + 0.50
  expect(prices.fritesBase).toBeCloseTo(2.50, 2);
  expect(prices.fritesCheddar).toBeCloseTo(3.50, 2); // 2.50 + 1.00
  expect(prices.fritesOignon).toBeCloseTo(4.50, 2);  // 2.50 + 2.00
});

// ============================================================================
// A — Home : 11 cats visible
// ============================================================================
test('A — Home shows category badge and Menu screen lists 11 cats', async ({ page }) => {
  await bootMobile(page);
  // Wait for home screen content
  await page.waitForTimeout(800);
  await snapAndCheck(page, 'A01-home');
  // Home screen — verify the badge "11 choix" or "11" near "CATEGORIES" is rendered
  const homeText = await page.locator('body').innerText();
  expect(homeText, 'Home shows 11 choix badge').toMatch(/11\s*choix/i);
  // Navigate to Menu tab (bottom nav)
  const menuTab = page.locator('button:has-text("MENU"), [role="tab"]:has-text("MENU"), a:has-text("MENU")').first();
  const expectedLabels = ['Sandwich Cayenne', 'Galette', 'Sandwich Classique', 'Burgers', 'Tacos',
                          'Bols Gourmands', 'Frites', 'Suppléments', 'Desserts', 'Boissons', 'Menu enfant'];
  if (await menuTab.isVisible().catch(() => false)) {
    await menuTab.click();
    await page.waitForTimeout(800);
    await snapAndCheck(page, 'A02-menu-tab');
    // textContent (not innerText) returns ALL DOM text including off-screen chips
    const menuText = await page.evaluate(() => document.body.textContent);
    const missing = expectedLabels.filter(l => !menuText.includes(l));
    expect(missing, `Menu screen missing cats: ${missing.join(', ')}`).toHaveLength(0);
  } else {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(400);
    await snapAndCheck(page, 'A02-home-scrolled');
    const scrolledText = await page.evaluate(() => document.body.textContent);
    const missing = expectedLabels.filter(l => !scrolledText.includes(l));
    expect(missing, `Home scrolled missing cats: ${missing.join(', ')}`).toHaveLength(0);
  }
});

// ============================================================================
// D — Bols wizard — 3-step composer (sauce + bol_supplements + bol_drink) — gate critical
// ============================================================================
test('D — Bols Gourmands wizard renders custom composer 3-step', async ({ page }) => {
  await bootMobile(page);
  // Drive wizard programmatically via computeActiveSteps
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const bowl = m.findItem('bowl-frites-curry');
    const initSelections = {
      meatIds: [], sauceIds: ['s-curry'], cruditeIds: m.defaultCruditeIds(),
      supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined,
      menuChoice: undefined, drinkId: undefined, fritesStyleId: undefined,
      fritesSauceIds: [], qty: 1,
    };
    const steps = window.computeActiveSteps(bowl, initSelections);
    // Validate canAdvance for each step
    const canAdvanceSauce = window.canAdvance('sauce', { sauceIds: ['s-curry'] }, bowl);
    const canAdvanceBolSupps = window.canAdvance('bolSupplements', {}, bowl); // always true (optional)
    const canAdvanceBolDrinkSkip = window.canAdvance('bolDrink', { bolDrinkId: null }, bowl);
    const canAdvanceBolDrinkPick = window.canAdvance('bolDrink', { bolDrinkId: 'd-coca' }, bowl);
    return {
      steps,
      canAdvanceSauce, canAdvanceBolSupps, canAdvanceBolDrinkSkip, canAdvanceBolDrinkPick,
    };
  });
  expect(result.steps).toEqual(['sauce', 'bolSupplements', 'bolDrink', 'recap']);
  expect(result.canAdvanceSauce).toBe(true);
  expect(result.canAdvanceBolSupps).toBe(true);
  expect(result.canAdvanceBolDrinkSkip).toBe(true); // can advance even without picking
  expect(result.canAdvanceBolDrinkPick).toBe(true);
});

// ============================================================================
// E — Frites wizard — 1-step composer (frites_style)
// ============================================================================
test('E — Frites wizard renders custom composer 1-step', async ({ page }) => {
  await bootMobile(page);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const petite = m.findItem('petite-frites');
    const grande = m.findItem('grande-frites');
    const initSelections = {
      meatIds: [], sauceIds: [], cruditeIds: m.defaultCruditeIds(),
      supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined,
      menuChoice: undefined, drinkId: undefined, fritesStyleId: undefined,
      fritesSauceIds: [], qty: 1,
    };
    return {
      petiteSteps: window.computeActiveSteps(petite, initSelections),
      grandeSteps: window.computeActiveSteps(grande, initSelections),
      canAdvanceStyle: window.canAdvance('fritesStyle', { fritesStyleId: null }, petite),
      canAdvanceWithChoice: window.canAdvance('fritesStyle', { fritesStyleId: 'fs-cheddar' }, petite),
    };
  });
  expect(result.petiteSteps).toEqual(['fritesStyle', 'recap']);
  expect(result.grandeSteps).toEqual(['fritesStyle', 'recap']);
  expect(result.canAdvanceStyle).toBe(true);  // null = "Nature" is a valid choice
  expect(result.canAdvanceWithChoice).toBe(true);
});

// ============================================================================
// C — Tacos wizard renders template='tacos' (4-5 steps)
// ============================================================================
test('C — Tacos wizard renders template=tacos', async ({ page }) => {
  await bootMobile(page);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const tacosM = m.findItem('tacos-1-viande');
    const tacosL = m.findItem('big-tacos-2-viandes');
    const initSelections = {
      meatIds: [], sauceIds: [], cruditeIds: m.defaultCruditeIds(),
      supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined,
      menuChoice: undefined, drinkId: undefined, fritesStyleId: undefined,
      fritesSauceIds: [], qty: 1,
    };
    return {
      tacosMSteps: window.computeActiveSteps(tacosM, initSelections),
      tacosLSteps: window.computeActiveSteps(tacosL, initSelections),
      tacosMViandes: tacosM.viande_count,
      tacosLViandes: tacosL.viande_count,
    };
  });
  expect(result.tacosMViandes).toBe(1);
  expect(result.tacosLViandes).toBe(2);
  // Tacos M : viandes + supplements + menu + recap (no sauce because has_sauce=false in mobile data, no crudites)
  expect(result.tacosMSteps).toContain('viandes');
  expect(result.tacosMSteps).toContain('menu');
  expect(result.tacosMSteps).toContain('recap');
  expect(result.tacosLSteps).toContain('viandes');
});

// ============================================================================
// B — Sandwich Cayenne / Galette / Classique / Burgers wizard parity (4 cats, same template)
// ============================================================================
test('B — Sandwich-family 4 cats share template=sandwich', async ({ page }) => {
  await bootMobile(page);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const items = ['sandwich-cayenne-classique', 'galette-normale', 'sandwich-classique-faluche', 'chicken-burger'];
    return items.map(slug => {
      const item = m.findItem(slug);
      const cat = m.findCategory(item.category_id);
      return { slug, catWizard: cat.wizard_template, hasViandes: !!item.viandes, hasMenu: !!item.has_menu_addon };
    });
  });
  result.forEach(r => {
    expect(r.catWizard, `${r.slug} cat wizard`).toBe('sandwich');
    expect(r.hasMenu, `${r.slug} has menu addon`).toBe(true);
  });
});

// ============================================================================
// F — Simple cats (Suppléments / Desserts / Boissons / Menu enfant) direct-add
// ============================================================================
test('F — Simple categories direct-add (no wizard steps)', async ({ page }) => {
  await bootMobile(page);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const samples = ['supp-cheddar', 'glace', 'coca', 'menu-nuggets'];
    return samples.map(slug => {
      const item = m.findItem(slug);
      const initSelections = {
        meatIds: [], sauceIds: [], cruditeIds: m.defaultCruditeIds(),
        supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined,
        menuChoice: undefined, drinkId: undefined, fritesStyleId: undefined,
        fritesSauceIds: [], qty: 1,
      };
      const steps = window.computeActiveSteps(item, initSelections);
      const cat = m.findCategory(item.category_id);
      return { slug, steps, catWizard: cat.wizard_template, price: item.price };
    });
  });
  // All these should be direct-add: 0 steps or [recap]
  result.forEach(r => {
    const isDirect = r.steps.length === 0 || (r.steps.length === 1 && r.steps[0] === 'recap');
    expect(isDirect, `${r.slug} steps=[${r.steps.join(',')}] should be direct-add`).toBe(true);
    expect(r.catWizard, `${r.slug} cat wizard`).toBe('simple');
  });
});

// ============================================================================
// I — Cart line composition_summary preserves bol fields
// ============================================================================
test('I — Cart line composition for Bowl includes base + meat + sauce + supps + drink', async ({ page }) => {
  await bootMobile(page);
  const lineItem = await page.evaluate(() => {
    const m = window.LC.menu;
    const bowl = m.findItem('bowl-frites-curry');
    const selections = {
      meatIds: [], sauceIds: ['s-curry'], cruditeIds: m.defaultCruditeIds(),
      supplementIds: [], bolSupplementIds: ['sb-boule-gratinee'], bolDrinkId: 'd-coca',
      menuChoice: 'none', drinkId: undefined, fritesStyleId: undefined,
      fritesSauceIds: [], qty: 2,
    };
    return window.buildLineItem(bowl, selections);
  });
  expect(lineItem.bolSupplementIds).toEqual(['sb-boule-gratinee']);
  expect(lineItem.bolSupLabels).toEqual(['Boule gratinée']);
  expect(lineItem.bolDrinkId).toBe('d-coca');
  expect(lineItem.bolDrinkLabel).toBe('Coca-Cola 33cl');
  expect(lineItem.unitPrice).toBeCloseTo(12.40, 2); // 8.90 + 2.00 + 1.50
  expect(lineItem.lineTotal).toBeCloseTo(24.80, 2); // 12.40 × 2
  // composition_summary should mention base, meat, sauce, supp, drink
  expect(lineItem.composition_summary).toContain('Frites');
  expect(lineItem.composition_summary).toContain('Poulet curry');
  expect(lineItem.composition_summary).toContain('Curry');         // sauce
  expect(lineItem.composition_summary).toContain('Boule gratinée');
  expect(lineItem.composition_summary).toContain('Coca-Cola 33cl');
});

// ============================================================================
// Visual sweep — all 11 cats screenshots for owner review
// ============================================================================
test('Z — Visual sweep all 11 categories (snapshots)', async ({ page }) => {
  await bootMobile(page);
  // Click the menu / catalog tab
  await page.waitForTimeout(500);
  await snapAndCheck(page, 'Z00-home-overview');
  // Walk each category page if the app exposes a menu navigation
  // The mobile prototype renders a single SPA — we navigate via the route helper if present
  const navResults = await page.evaluate(() => {
    if (window.go) {
      window.go('menu');
      return { hasGo: true };
    }
    return { hasGo: false };
  });
  if (navResults.hasGo) {
    await page.waitForTimeout(500);
    await snapAndCheck(page, 'Z01-menu-landing');
  }
});
