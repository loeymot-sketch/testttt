// test-e2e-website-realignment-2026-05-16 — Le Cayenne website realignment to canonical system
//
// Cycle GOAL LONG-TERM 2026-05-16 : website refit menu fictif → canonical 11 cats / 41 items,
// 4 wizard templates (sandwich / tacos / custom / simple), Pepper Club paliers aligned.
// Mobile + Web COMPLETELY separate from each other AND from central system.
//
// Tests run multi-viewport (mobile / tablet / desktop / wide) via playwright projects.
//
// Boot manually :
//   python3 -m http.server 8082 --directory /Users/1millnonstop/Downloads/web
//
// Run :
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js \
//     --reporter=list --workers=1 --timeout=120000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-website-realignment-2026-05-16');
if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

const RAW_LABEL_FORBIDDEN = [
  /\bLabel\.[A-Za-z0-9_.-]+/i,                  // i18n key leak: Label.xxx
  /\bkiosk\.wizard\.[a-z_]+/i,                  // i18n key leak: kiosk.wizard.xxx (more specific to avoid kiosk.fr false-positives)
  /\b0undefined\b/,
  /\bNaN €/,
  // Anti-fictional sweep — these must NOT appear (old menu drift)
  /Box Nashville/,
  /Cheese Smash/,
  /Le Gourmet/,
  /Bowl Cheesy/,
  /Bowl Veggie/,
  /Wrap Poulet/,
  /Box Familiale/,
];

async function bootWeb(page, projectName) {
  page._errors = [];
  page.on('pageerror', err => page._errors.push(err.message));
  page.on('console', msg => { if (msg.type() === 'error') page._errors.push(msg.text()); });
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.waitForTimeout(400);
}

async function snapAndCheckLabels(page, name, projectName) {
  const file = path.join(SCREEN_DIR, `${projectName}-${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  // innerText returns only rendered visible text (excludes <script>/<style> content)
  const text = await page.evaluate(() => document.body.innerText);
  for (const re of RAW_LABEL_FORBIDDEN) {
    const match = text.match(re);
    if (match) {
      // Log surrounding context for debugging
      const idx = text.search(re);
      const ctx = text.substring(Math.max(0, idx - 40), Math.min(text.length, idx + match[0].length + 40));
      throw new Error(`${projectName}/${name} forbidden text ${re} matched: "${match[0]}" — context: "...${ctx}..."`);
    }
  }
  const real = (page._errors || []).filter(e => !/favicon|image-slots/i.test(e));
  expect(real, `${projectName}/${name} console errors: ${real.join('\n')}`).toHaveLength(0);
}

// ============================================================================
// G — Data parity SSOT (window.LC.menu)
// Runs on all 4 viewports (multi-project)
// ============================================================================
test('G — Data parity SSOT (11 cats / 41 items / pools)', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const data = await page.evaluate(() => {
    const m = window.LC.menu;
    const bowl = m.findItem('bowl-frites-curry');
    const petite = m.findItem('petite-frites');
    return {
      catCount: m.categories.length,
      itemCount: m.items.length,
      sauceCount: m.sauces.length,
      meatCount: m.meats.length,
      suppCount: m.supplements.length,
      suppBolsCount: m.supplementsBols.length,
      cats: m.categories.map(c => c.slug),
      bowlComposer: bowl.composer_profile && bowl.composer_profile.steps.map(s => s.step_key),
      bowlDefaultSauce: bowl.composer_profile && bowl.composer_profile.steps[0].default_choice_id,
      fritesComposer: petite.composer_profile && petite.composer_profile.steps.map(s => s.step_key),
      pepperRatio: m.pepperClub.earn_ratio,
      pepperTiers: m.pepperClub.tiers.map(t => `${t.name}@${t.threshold}`),
      // W_* exposed for legacy code
      wCats: window.W_CATS.length,
      wItems: window.W_ITEMS.length,
      wDiet: window.W_DIET.length,
    };
  });
  expect(data.catCount).toBe(11);
  expect(data.itemCount).toBe(41);
  expect(data.sauceCount).toBe(11);
  expect(data.meatCount).toBe(4);
  expect(data.suppCount).toBe(9);
  expect(data.suppBolsCount).toBe(4);
  expect(data.cats).toEqual(['sandwich-cayenne', 'galette', 'sandwich-classique', 'burgers', 'tacos',
                             'bols-gourmands', 'frites', 'supplements', 'desserts', 'boissons', 'menu-enfant']);
  expect(data.bowlComposer).toEqual(['sauce', 'bol_supplements', 'bol_drink']);
  expect(data.bowlDefaultSauce).toBe('s-curry');
  expect(data.fritesComposer).toEqual(['frites_style']);
  expect(data.pepperRatio).toBe(1);
  expect(data.pepperTiers).toEqual(['Novice@0', 'Pepper@500', 'Master@1500', 'Légende@5000']);
  expect(data.wCats).toBe(12); // 11 + 'Tout' chip
  expect(data.wItems).toBe(41);
  expect(data.wDiet).toBe(4);
});

// ============================================================================
// H — Pricing parity (priceFor canonical)
// ============================================================================
test('H — Pricing parity (canonical priceFor)', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const prices = await page.evaluate(() => {
    const m = window.LC.menu;
    const bowl = m.findItem('bowl-frites-curry');
    const petite = m.findItem('petite-frites');
    const sandCayenne = m.findItem('sandwich-cayenne-classique');
    return {
      bowlBase:        m.priceFor(bowl, { sauceIds: ['s-curry'], qty: 1 }),
      bowlGratine:     m.priceFor(bowl, { sauceIds: ['s-curry'], bolSupplementIds: ['sb-boule-gratinee'], qty: 1 }),
      bowlFull:        m.priceFor(bowl, { sauceIds: ['s-curry'], bolSupplementIds: ['sb-boule-gratinee', 'sb-jambon'], bolDrinkId: 'd-coca', qty: 1 }),
      fritesBase:      m.priceFor(petite, { qty: 1 }),
      fritesCheddar:   m.priceFor(petite, { fritesStyleId: 'fs-cheddar', qty: 1 }),
      sandCayenneFull: m.priceFor(sandCayenne, { formuleId: 'f-menu', qty: 1 }),
    };
  });
  expect(prices.bowlBase).toBeCloseTo(8.90, 2);
  expect(prices.bowlGratine).toBeCloseTo(10.90, 2);
  expect(prices.bowlFull).toBeCloseTo(13.30, 2);
  expect(prices.fritesBase).toBeCloseTo(2.50, 2);
  expect(prices.fritesCheddar).toBeCloseTo(3.50, 2);
  expect(prices.sandCayenneFull).toBeCloseTo(10.00, 2); // 7.50 + 2.50 menu
});

// ============================================================================
// A — Home page renders + canonical content (no fictional)
// ============================================================================
test('A — Home page renders canonical brand + content', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  await snapAndCheckLabels(page, 'A01-home', testInfo.project.name);
  const text = await page.evaluate(() => document.body.textContent);
  // Brand mentions
  expect(text).toMatch(/Le Cayenne|le cayenne/i);
  expect(text).toMatch(/Hénin-Beaumont/i);
  expect(text).toContain('Sandwich');
  expect(text).toContain('Tacos');
  expect(text).toContain('Bols');
});

// ============================================================================
// B — Menu page lists 11 canonical categories
// ============================================================================
test('B — Menu page lists all 11 canonical categories', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  // Navigate to Menu route — desktop has .lc-nav-link visible, mobile needs burger open first
  const deskLink = page.locator('button.lc-nav-link', { hasText: 'Menu' }).first();
  if (await deskLink.isVisible().catch(() => false)) {
    await deskLink.click();
  } else {
    // Mobile : open burger first, then click .lc-mobile-link
    const burger = page.locator('button.lc-nav-burger').first();
    if (await burger.isVisible().catch(() => false)) {
      await burger.click();
      await page.waitForTimeout(300);
      const mobLink = page.locator('button.lc-mobile-link', { hasText: 'Menu' }).first();
      if (await mobLink.isVisible().catch(() => false)) await mobLink.click();
    }
  }
  await page.waitForTimeout(600);
  await snapAndCheckLabels(page, 'B01-menu', testInfo.project.name);
  // textContent (not innerText) returns ALL DOM text including off-screen chips on narrow viewports
  const text = await page.evaluate(() => document.body.textContent);
  const expected = ['Sandwich Cayenne', 'Galette', 'Sandwich Classique', 'Burgers', 'Tacos',
                    'Bols Gourmands', 'Frites', 'Suppléments', 'Desserts', 'Boissons', 'Menu enfant'];
  const missing = expected.filter(l => !text.includes(l));
  expect(missing, `Menu missing cats: ${missing.join(', ')}`).toHaveLength(0);
});

// ============================================================================
// D — Wizard buildSteps for canonical templates
// ============================================================================
test('D — buildWizardSteps returns canonical steps per template', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const result = await page.evaluate(() => {
    const M = window.LC.menu;
    const fn = window.buildWizardSteps;
    if (!fn) return { error: 'buildWizardSteps not exposed' };
    return {
      sandwich: fn(M.findItem('sandwich-cayenne-classique')).map(s => s.id),
      galette:  fn(M.findItem('galette-normale')).map(s => s.id),
      burger:   fn(M.findItem('chicken-burger')).map(s => s.id),
      tacos:    fn(M.findItem('tacos-1-viande')).map(s => s.id),
      bowl:     fn(M.findItem('bowl-frites-curry')).map(s => s.id),
      frites:   fn(M.findItem('petite-frites')).map(s => s.id),
      coca:     fn(M.findItem('coca')).map(s => s.id),
    };
  });
  // Sandwich Cayenne : sauce_locked → no sauce step. viandes + crudites + supplements + menu + recap
  expect(result.sandwich).toEqual(['viandes', 'crudites', 'supplements', 'menu', 'recap']);
  // Galette Normale : viandes + sauce + crudites + supplements + menu + recap
  expect(result.galette).toEqual(['viandes', 'sauce', 'crudites', 'supplements', 'menu', 'recap']);
  // Chicken Burger : viandes + sauce + crudites + supplements + menu + recap
  expect(result.burger).toEqual(['viandes', 'sauce', 'crudites', 'supplements', 'menu', 'recap']);
  // Tacos M : has_sauce=false, has_crudites=false → viandes + supplements + menu + recap
  expect(result.tacos).toEqual(['viandes', 'supplements', 'menu', 'recap']);
  // Bowl curry : custom composer → sauce + bol_supplements + bol_drink + recap
  expect(result.bowl).toEqual(['sauce', 'bol_supplements', 'bol_drink', 'recap']);
  // Petite Frites : custom composer → frites_style + recap
  expect(result.frites).toEqual(['frites_style', 'recap']);
  // Coca : simple → no steps (direct-add)
  expect(result.coca).toEqual([]);
});

// ============================================================================
// E — computeWizardTotal canonical
// ============================================================================
test('E — computeWizardTotal mirrors priceFor', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const totals = await page.evaluate(() => {
    const M = window.LC.menu;
    const bowl = M.findItem('bowl-frites-curry');
    const sandCayenne = M.findItem('sandwich-cayenne-classique');
    return {
      bowlFullCombo: window.computeWizardTotal(bowl, {
        sauce: 's-curry', bol_supplements: ['sb-boule-gratinee', 'sb-jambon'], bol_drink: 'd-coca',
      }),
      sandCayenneMenu: window.computeWizardTotal(sandCayenne, { menu: 'full' }),
    };
  });
  expect(totals.bowlFullCombo).toBeCloseTo(13.30, 2);
  expect(totals.sandCayenneMenu).toBeCloseTo(10.00, 2);
});

// ============================================================================
// F — Photo assets resolve (no 404 on critical items)
// ============================================================================
test('F — Critical product photos resolve (no 404)', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const photoStatuses = await page.evaluate(async () => {
    const M = window.LC.menu;
    const critical = ['sandwich-cayenne-classique', 'big-cayenne', 'galette-cayenne', 'chicken-burger',
                      'tacos-1-viande', 'bowl-frites-curry', 'petite-frites', 'glace', 'coca', 'menu-nuggets'];
    const results = {};
    for (const slug of critical) {
      const item = M.findItem(slug);
      try {
        const r = await fetch(item.image, { method: 'HEAD' });
        results[slug] = r.status;
      } catch (e) { results[slug] = 'error'; }
    }
    return results;
  });
  const missing = Object.entries(photoStatuses).filter(([k, v]) => v !== 200);
  expect(missing, `Photos missing: ${missing.map(([k, v]) => `${k}=${v}`).join(', ')}`).toHaveLength(0);
});

// ============================================================================
// L — [MASSIVE-LOGIC 2026-05-17] Multi-sauce pricing edge cases (no negative price bug)
// ============================================================================
test('L — Multi-sauce pricing edges (1 free, +0.50€ each extra)', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const prices = await page.evaluate(() => {
    const m = window.LC.menu;
    const galette = m.findItem('galette-normale'); // 6.50€
    return {
      zero:  m.priceFor(galette, { sauceIds: [], qty: 1 }),
      one:   m.priceFor(galette, { sauceIds: ['s-mayo'], qty: 1 }),
      two:   m.priceFor(galette, { sauceIds: ['s-mayo', 's-ketchup'], qty: 1 }),
      three: m.priceFor(galette, { sauceIds: ['s-mayo', 's-ketchup', 's-curry'], qty: 1 }),
    };
  });
  expect(prices.zero).toBeCloseTo(6.50, 2);   // no negative bug
  expect(prices.one).toBeCloseTo(6.50, 2);
  expect(prices.two).toBeCloseTo(7.00, 2);
  expect(prices.three).toBeCloseTo(7.50, 2);
});

// ============================================================================
// M — [MASSIVE-LOGIC 2026-05-17] Bol sauce default fallback (rename-resistant)
// ============================================================================
test('M — Bol sauce default fallback when name lookup fails', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const fakeItem = { slug: 'fake-bowl', category_id: 6 };
    const profile = m.buildBolComposerProfile(fakeItem, { bol_sauce_default: 'NonExistentSauceXYZ' });
    return { hasDefault: !!profile.steps[0].default_choice_id, id: profile.steps[0].default_choice_id };
  });
  expect(result.hasDefault).toBe(true);
  expect(result.id).toBeTruthy();
});

// ============================================================================
// N — [MASSIVE-LOGIC 2026-05-17] Sandwich Cayenne sauce_locked skips SAUCE step
// ============================================================================
test('N — Sandwich Cayenne buildSteps skips sauce (sauce_locked)', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const steps = await page.evaluate(() => {
    const m = window.LC.menu;
    const cayenne = m.findItem('sandwich-cayenne-classique');
    return window.buildWizardSteps(cayenne).map(s => s.id);
  });
  expect(steps).not.toContain('sauce');
  expect(steps).toContain('viandes');
  expect(steps).toContain('crudites');
  expect(steps).toContain('supplements');
  expect(steps).toContain('menu');
  expect(steps).toContain('recap');
});

// ============================================================================
// O — [MASSIVE-LOGIC 2026-05-17] Big Cayenne viande_count=2 enforcement
// ============================================================================
test('O — Big Cayenne viandes step requires exactly 2 picks', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const big = m.findItem('big-cayenne');
    const steps = window.buildWizardSteps(big);
    const viandesStep = steps.find(s => s.id === 'viandes');
    return {
      viandeCount: big.viande_count,
      stepMin: viandesStep && viandesStep.min,
      stepMax: viandesStep && viandesStep.max,
      stepKind: viandesStep && viandesStep.kind,
    };
  });
  expect(result.viandeCount).toBe(2);
  expect(result.stepKind).toBe('multi');
  expect(result.stepMin).toBe(2);
  expect(result.stepMax).toBe(2);
});

// ============================================================================
// P — [MASSIVE-LOGIC 2026-05-17] suppOptions allergens populated from canonical data
// ============================================================================
test('P — Supplement allergens propagate to wizard options', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  const result = await page.evaluate(() => {
    const m = window.LC.menu;
    const sandwich = m.findItem('sandwich-classique-faluche');
    const steps = window.buildWizardSteps(sandwich);
    const suppStep = steps.find(s => s.id === 'supplements');
    if (!suppStep) return { hasStep: false };
    const oeuf = suppStep.options.find(o => o.id === 'sup-oeuf');
    const cheddar = suppStep.options.find(o => o.id === 'sup-cheddar');
    return {
      hasStep: true,
      oeufAllergens: oeuf && oeuf.allergens,
      cheddarAllergens: cheddar && cheddar.allergens,
    };
  });
  expect(result.hasStep).toBe(true);
  expect(result.oeufAllergens).toContain('oeuf');
  expect(result.cheddarAllergens).toContain('lactose');
});

// ============================================================================
// Z — Visual sweep snapshots (multi-viewport via project)
// ============================================================================
test('Z — Visual sweep home + menu screenshot', async ({ page }, testInfo) => {
  await bootWeb(page, testInfo.project.name);
  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-Z01-home.png`), fullPage: false });
  // Click menu
  const menuLink = page.locator('a:has-text("Menu"), button:has-text("Menu"), [data-route="menu"]').first();
  if (await menuLink.isVisible().catch(() => false)) {
    await menuLink.click();
    await page.waitForTimeout(600);
  }
  await page.screenshot({ path: path.join(SCREEN_DIR, `${testInfo.project.name}-Z02-menu.png`), fullPage: false });
});
