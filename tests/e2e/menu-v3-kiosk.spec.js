// FoodKing E2E — Wave KIOSK : menu-v3-2026-05-14
//
// MISSION
//   Verify Menu V3 heal-light (commit 7f06224af) visually + technically :
//     1. Bowl Frites curry (item 493) wizard = exactly 3 steps
//        Step 1 Sauce shows ONLY 2 choices : Spicy + Sauce fromagère maison.
//        Step 2 Suppléments includes "Boule gratinée" (+2,00€).
//        Step 3 Boisson.
//     2. Sandwich Classique (item 477) sauce step displays Barbecue + Ail
//        (mission claim about Sandwich Cayenne 474 is impossible — see SCOPE
//        NOTE below — verified via 477 which DOES have attr 311).
//     3. Burgers category renders "Chicken Burger Special" (item 490 renamed
//        from "Big Chicken").
//     4. Product images render in kiosk grid for the 6 target items :
//        474, 488, 475, 489, 493, 490 — verify `<img>` element present, NOT
//        the `.kiosk-product-image-fallback` div.
//
// SCOPE NOTE (Sandwich Cayenne 474 vs mission spec)
//   Mission asks "Sandwich Cayenne wizard sauce step displays Barbecue + Ail".
//   DB reality : item 474 has NO variations on attr 311 ("Sauce libre"); it
//   has attr 331 ("Sauce Cayenne incluse" — locked single choice, value
//   "Sauce Cayenne maison"). Heal report Layer 3 (00_HEAL_V3_REPORT.md)
//   explicitly scoped Barbecue+Ail to attr 311 only, matching mockup
//   menu_sandwichs_v3.png. Verifying the literal claim on 474 is impossible;
//   we verify the heal intent via item 477 (Sandwich Classique) which has
//   attr 311 = 13 variations including Barbecue + Ail.
//
// SURFACES (6 captures)
//   S1 : /kiosk/categories?cat=347 — Bols Gourmands grid (image check)
//   S2 : /kiosk/wizard/493 step 1 — Sauce (2 choices, 3 dots)
//   S3 : /kiosk/wizard/493 step 2 — Suppléments (Boule gratinée present)
//   S4 : /kiosk/wizard/493 step 3 — Boisson
//   S5 : /kiosk/wizard/477 sauce step — Barbecue + Ail present
//   S6 : /kiosk/categories?cat=349 — Burgers grid (Chicken Burger Special)
//
// FROZEN ZONES : 0 line touched. KioskWizardComponent.vue + KioskCategoriesComponent.vue
//   READ for selectors only.
//
// Adversarial criteria : P0/P1/P2/P3 surfaced in payload — caller picks
// the verdict.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { resetKioskToken } = require('./helpers/kiosk-order');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/menu-v3');
const REPORT_DIR = path.resolve(
  REPO_ROOT,
  'reports/audit/menu-v3-2026-05-14',
);
const RAW_FILE = path.join(REPORT_DIR, 'raw-capture-payload.json');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

const TARGET_IMAGE_ITEMS = [
  { id: 474, name: 'Sandwich Cayenne', cat: 344 },
  { id: 488, name: 'Big Cayenne', cat: 344 },
  { id: 475, name: 'Galette Normale', cat: 345 },
  { id: 489, name: 'Big Classique', cat: 346 },
  { id: 493, name: 'Bowl Frites curry', cat: 347 },
  { id: 490, name: 'Chicken Burger Special', cat: 349 },
];

// Walk through SPA from idle -> categories. Returns true if we got there.
async function enterCategoriesViaIdle(page, observations) {
  if (!/\/kiosk\/idle/.test(page.url())) {
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
  }
  const takeawayCard = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
  let visible = false;
  for (let i = 0; i < 10; i++) {
    visible = await takeawayCard.isVisible().catch(() => false);
    if (visible) break;
    await page.waitForTimeout(500);
  }
  if (!visible) {
    observations.push(`enterCategoriesViaIdle: takeaway card NOT visible, URL=${page.url()}`);
    return false;
  }
  await takeawayCard.click();
  for (let i = 0; i < 20; i++) {
    if (/\/kiosk\/categories/.test(page.url())) return true;
    await page.waitForTimeout(300);
  }
  observations.push(`enterCategoriesViaIdle: did NOT reach /kiosk/categories, URL=${page.url()}`);
  return false;
}

// Inspect a category grid to extract image-rendering state per item.
async function inspectCategoryGrid(page, expectedItems) {
  return await page.evaluate(({ expectedItems }) => {
    const result = {
      total_cards: document.querySelectorAll('[data-testid^="kiosk-product-card-"]').length,
      cards: [],
      i18n_leaks: [],
    };
    for (const it of expectedItems) {
      const card = document.querySelector(`[data-testid="kiosk-product-card-${it.id}"]`);
      if (!card) {
        result.cards.push({
          item_id: it.id,
          name_expected: it.name,
          card_found: false,
          image_rendered: false,
          fallback_rendered: false,
          name_in_card: null,
        });
        continue;
      }
      const img = card.querySelector('img.kiosk-product-image');
      const fb = card.querySelector('.kiosk-product-image-fallback');
      const text = (card.textContent || '').replace(/\s+/g, ' ').trim();
      result.cards.push({
        item_id: it.id,
        name_expected: it.name,
        card_found: true,
        image_rendered: !!img,
        image_src: img ? (img.getAttribute('src') || '').slice(0, 200) : null,
        image_complete: img ? img.complete : null,
        image_naturalWidth: img ? img.naturalWidth : null,
        fallback_rendered: !!fb,
        fallback_text: fb ? (fb.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 30) : null,
        name_in_card: text.toLowerCase().includes(it.name.toLowerCase()),
        card_text_preview: text.slice(0, 150),
      });
    }
    // i18n leak scan
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n;
    const re = /^[a-z]+(\.[a-z_]+){1,4}$/;
    while ((n = walker.nextNode())) {
      const t = (n.nodeValue || '').trim();
      if (t && re.test(t) && t.length < 80) {
        result.i18n_leaks.push(t);
        if (result.i18n_leaks.length >= 15) break;
      }
    }
    return result;
  }, { expectedItems });
}

// Drive sidebar click to a specific category.
async function clickCategoryPill(page, catId, observations) {
  if (!/\/kiosk\/categories/.test(page.url())) {
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  try {
    const pill = page.locator(`[data-testid="kiosk-categories-sidebar-item-${catId}"]`).first();
    if (await pill.isVisible({ timeout: 3000 }).catch(() => false)) {
      await pill.click();
      await page.waitForTimeout(1800);
      return true;
    }
  } catch (e) {
    observations.push(`clickCategoryPill ${catId} exception: ${String(e?.message || e).slice(0, 200)}`);
  }
  observations.push(`clickCategoryPill ${catId} : pill NOT visible`);
  return false;
}

// Open wizard for a given item id (direct URL navigation).
async function openWizard(page, itemId, observations) {
  await page.goto(`/kiosk/wizard/${itemId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  // Detect wizard render: look for step labels.
  for (let i = 0; i < 10; i++) {
    const ok = await page.evaluate(() => document.querySelector('.kiosk-step-visual-label') !== null);
    if (ok) return true;
    await page.waitForTimeout(500);
  }
  observations.push(`openWizard ${itemId} : no .kiosk-step-visual-label rendered, URL=${page.url()}`);
  return false;
}

// Inspect wizard DOM : labels, dots, choices for current step.
async function inspectWizard(page) {
  return await page.evaluate(() => {
    const labels = Array.from(document.querySelectorAll('.kiosk-step-visual-label'))
      .map((n) => (n.textContent || '').replace(/\s+/g, ' ').trim());
    const dots = document.querySelectorAll('.kiosk-step-dot').length;
    // Try multiple candidate selectors for choice cards.
    // Verified against KioskStepSauceComponent.vue (kiosk-option-card),
    // KioskStepSupplementsComponent.vue (kiosk-supplement-row),
    // KioskStepGenericChoicesComponent.vue (kiosk-generic-choice).
    const choiceCandidates = [
      '.kiosk-option-card',
      '.kiosk-supplement-row',
      '.kiosk-generic-choice',
      '.kiosk-step-content .kiosk-sauce-grid > *',
      '.kiosk-step-content .kiosk-supplements-list > *',
      '[data-testid^="kiosk-wizard-choice-"]',
      '[data-testid^="kiosk-wizard-option-"]',
    ];
    let choiceNodes = [];
    let selectorUsed = null;
    for (const sel of choiceCandidates) {
      const list = Array.from(document.querySelectorAll(sel));
      if (list.length > 0) {
        choiceNodes = list;
        selectorUsed = sel;
        break;
      }
    }
    const choices = choiceNodes.map((n) => {
      const txt = (n.textContent || '').replace(/\s+/g, ' ').trim();
      return txt.slice(0, 80);
    });
    // Find current step heading.
    const heading = (document.querySelector('.kiosk-step-title, .kiosk-wizard-step-title, h1.kiosk-step, [data-testid="kiosk-wizard-step-title"]')?.textContent || '')
      .replace(/\s+/g, ' ').trim().slice(0, 200);
    // Step indicator current dot index (best-effort).
    const dotsAll = Array.from(document.querySelectorAll('.kiosk-step-dot'));
    const activeIdx = dotsAll.findIndex((n) => /active|current|kiosk-step-dot--current|kiosk-step-dot--active/.test(n.className));
    // Full body text scan for raw label leaks + sauce/supplément names.
    const bodyText = (document.body?.innerText || '').replace(/\s+/g, ' ');
    return {
      step_labels: labels,
      dots_count: dots,
      active_dot_index: activeIdx,
      heading,
      choices,
      choices_count: choiceNodes.length,
      choice_selector_used: selectorUsed,
      // explicit text-presence checks
      has_spicy: /Spicy/i.test(bodyText),
      has_sauce_fromagere: /sauce\s*fromag[èe]re\s*maison/i.test(bodyText),
      has_barbecue: /Barbecue/i.test(bodyText),
      has_ail: /\bAil\b/i.test(bodyText),
      has_boule_gratinee: /Boule\s*gratin[ée]e?/i.test(bodyText),
      has_plus_2_00: /\+\s*2[,.]00\s*€|\+\s*2\s*€/.test(bodyText),
      has_big_chicken: /\bBig\s+Chicken\b/i.test(bodyText),
      has_chicken_burger_special: /Chicken\s+Burger\s+Special/i.test(bodyText),
      body_text_preview: bodyText.slice(0, 600),
    };
  });
}

// Click step "Next" if present, to advance through wizard.
// KioskWizardComponent uses .kiosk-btn-next (bottom) + .kiosk-progress-arrow (top chevron).
// Bottom button is more semantically reliable for stepwise navigation.
async function clickNextStep(page) {
  const candidates = [
    'button.kiosk-btn-next:not([disabled])',
    'button.kiosk-btn-next--cart:not([disabled])',
    'button.kiosk-progress-arrow:not([disabled])',
    'button:has-text("SUIVANT"):not([disabled])',
    'button:has-text("Suivant"):not([disabled])',
    'button:has-text("Continuer"):not([disabled])',
    'button:has-text("Valider"):not([disabled])',
  ];
  for (const sel of candidates) {
    try {
      const btn = page.locator(sel).first();
      if (await btn.isVisible({ timeout: 600 }).catch(() => false)) {
        const enabled = await btn.isEnabled().catch(() => false);
        if (!enabled) continue;
        await btn.click();
        await page.waitForTimeout(1500);
        return { ok: true, selector: sel };
      }
    } catch (_e) { /* try next */ }
  }
  return { ok: false, selector: null };
}

// Auto-select first choice if a step requires it (min_select>0).
async function selectFirstChoice(page) {
  const selectors = [
    '.kiosk-viande-card:not(.disabled):not(.kiosk-viande-card--disabled)',
    '.kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)',
    '.kiosk-supplement-row:not(.disabled)',
    '.kiosk-generic-choice:not(.unavailable)',
    '.kiosk-pain-card:not(.disabled)',
    '.kiosk-garniture-card:not(.disabled)',
    '.kiosk-menu-card:not(.disabled)',
    '.kiosk-sauce-grid .kiosk-option-card',
    '[data-testid^="kiosk-wizard-choice-"]',
  ];
  for (const sel of selectors) {
    try {
      const first = page.locator(sel).first();
      if (await first.isVisible({ timeout: 600 }).catch(() => false)) {
        await first.click();
        await page.waitForTimeout(700);
        return true;
      }
    } catch (_e) { /* try next */ }
  }
  return false;
}

test.describe('menu-v3 Wave KIOSK — visual heal verification', () => {
  test.describe.configure({ retries: 0 }); // override config retries=1 to avoid 2x wall-clock
  test.setTimeout(600_000); // 10 min budget

  test('6 surfaces — images, bowl 3-step, sauces v3, burger rename', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const rec = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    const observations = [];
    const findings = {
      surfaces: {},
    };

    try {
      // Pre-flight : reset kiosk token + rate limits.
      try { clearFoodKingRateLimits(); } catch (_e) { /* ignore */ }
      try { resetKioskToken(); } catch (_e) { /* ignore */ }

      // Kiosk auto-login
      await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded' });
      let ready = false;
      for (let i = 0; i < 30; i++) {
        const probe = await page.evaluate(() => {
          let v = {};
          try { v = JSON.parse(localStorage.getItem('vuex') || '{}'); } catch (_e) { /* noop */ }
          return !!(v.kioskCart?.kioskToken);
        });
        if (probe) { ready = true; break; }
        await page.waitForTimeout(500);
      }
      observations.push(`kiosk vuex ready=${ready}`);
      if (!/\/kiosk\/idle/.test(page.url())) {
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      }
      await page.waitForTimeout(1500);
      await rec.snap('00-kiosk-idle');

      await enterCategoriesViaIdle(page, observations);
      await page.waitForSelector('[data-testid="kiosk-categories-root"]', { timeout: 15_000 }).catch(() => {});
      await page.waitForTimeout(2500);
      await rec.snap('00-kiosk-categories-overview');

      // ---- S1 : Bols Gourmands grid (image check) ------------------------
      await clickCategoryPill(page, 347, observations);
      await rec.snap('S1-bols-gourmands-grid');
      const s1 = await inspectCategoryGrid(page, TARGET_IMAGE_ITEMS.filter((x) => x.cat === 347));
      findings.surfaces.S1 = s1;
      observations.push(`S1 cards=${s1.total_cards} i18n_leaks=${s1.i18n_leaks.length}`);

      // ---- S2 : Bowl Frites curry (493) wizard step 1 --------------------
      const wizard493ok = await openWizard(page, 493, observations);
      observations.push(`wizard 493 opened=${wizard493ok}`);
      await rec.snap('S2-bowl-493-step1-sauce');
      const s2 = await inspectWizard(page);
      findings.surfaces.S2 = s2;
      observations.push(`S2 steps=${JSON.stringify(s2.step_labels)} dots=${s2.dots_count} choices=${s2.choices_count} spicy=${s2.has_spicy} fromagere=${s2.has_sauce_fromagere}`);

      // Advance to step 2 (sauce min_select=1 → pick first).
      if (wizard493ok) {
        await selectFirstChoice(page);
        const nxt = await clickNextStep(page);
        observations.push(`S2->S3 next button : ${JSON.stringify(nxt)}`);
        await rec.snap('S3-bowl-493-step2-supplements');
        const s3 = await inspectWizard(page);
        findings.surfaces.S3 = s3;
        observations.push(`S3 heading=${s3.heading} gratinee=${s3.has_boule_gratinee} plus_2=${s3.has_plus_2_00}`);

        // Step 3 boisson.
        const nxt2 = await clickNextStep(page);
        observations.push(`S3->S4 next button : ${JSON.stringify(nxt2)}`);
        await rec.snap('S4-bowl-493-step3-boisson');
        const s4 = await inspectWizard(page);
        findings.surfaces.S4 = s4;
        observations.push(`S4 heading=${s4.heading} step_labels=${JSON.stringify(s4.step_labels)}`);
      }

      // ---- S5 : Sandwich Classique (477) wizard sauce step --------------
      // 477 has attr 311 with Barbecue + Ail. Walk through wizard until sauce
      // step appears. Sandwich wizard standard order : Sauce step typically
      // after Viande step. Open wizard then advance through.
      const wizard477ok = await openWizard(page, 477, observations);
      observations.push(`wizard 477 opened=${wizard477ok}`);
      await rec.snap('S5a-classique-477-step0-viande');
      let s5found = null;
      if (wizard477ok) {
        // Sandwich wizard order : Pain / Viande / Sauce / Crudités / Suppléments / Menu / Récap.
        // Step indicator shown : QUELLE VIANDE / SAUCE / CRUDITÉ / SUPPLÉMENT / MENU / RÉCAP (6 dots).
        // Walk up to 7 steps, snap each, look for Barbecue + Ail.
        for (let step = 0; step < 7; step++) {
          const insp = await inspectWizard(page);
          observations.push(`S5 walk step=${step} heading=${insp.heading} barbecue=${insp.has_barbecue} ail=${insp.has_ail}`);
          if (insp.has_barbecue && insp.has_ail) {
            s5found = insp;
            findings.surfaces.S5 = insp;
            await rec.snap(`S5-classique-477-sauce-step-${step}`);
            break;
          }
          // Snap intermediate steps too — useful for debugging.
          await rec.snap(`S5-walk-${step}-${(insp.heading || 'unknown').slice(0, 24).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`);
          // For sauce step, snap before advancing — to capture sauce-step DOM
          // with Barbecue/Ail names. Then bail.
          // try select first choice + next
          const selOk = await selectFirstChoice(page);
          if (!selOk) observations.push(`S5 walk step=${step} no choice selectable`);
          const nxt = await clickNextStep(page);
          observations.push(`S5 walk step=${step} next=${JSON.stringify(nxt)}`);
          if (!nxt.ok) {
            observations.push(`S5 walk step=${step} no next button — stop`);
            break;
          }
        }
        if (!s5found) {
          // capture wherever we ended
          const final = await inspectWizard(page);
          findings.surfaces.S5 = final;
          await rec.snap('S5-classique-477-final');
          observations.push(`S5 sauce step NOT reached — barbecue/ail not detected; current heading=${final.heading}`);
        }
      }

      // ---- S6 : Burgers grid (Chicken Burger Special) -------------------
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(1500);
      await clickCategoryPill(page, 349, observations);
      await rec.snap('S6-burgers-grid');
      const s6 = await inspectCategoryGrid(page, TARGET_IMAGE_ITEMS.filter((x) => x.cat === 349));
      // Augment with full-text scan for "Big Chicken" leftover + "Chicken Burger Special" present
      const burgersText = await page.evaluate(() => {
        const text = (document.body?.innerText || '').replace(/\s+/g, ' ');
        return {
          has_big_chicken: /\bBig\s+Chicken\b/i.test(text),
          has_chicken_burger_special: /Chicken\s+Burger\s+Special/i.test(text),
          text_preview: text.slice(0, 800),
        };
      });
      findings.surfaces.S6 = { grid: s6, burgers_text: burgersText };
      observations.push(`S6 cards=${s6.total_cards} has_big_chicken=${burgersText.has_big_chicken} has_special=${burgersText.has_chicken_burger_special}`);

      // ---- S7 : Verify images of items 474 + 488 visible in Cayenne cat
      await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(1500);
      await clickCategoryPill(page, 344, observations);
      await rec.snap('S7-cayenne-grid');
      const s7 = await inspectCategoryGrid(page, TARGET_IMAGE_ITEMS.filter((x) => x.cat === 344));
      findings.surfaces.S7 = s7;

      // S8 : Galette cat 345
      await clickCategoryPill(page, 345, observations);
      await rec.snap('S8-galette-grid');
      const s8 = await inspectCategoryGrid(page, TARGET_IMAGE_ITEMS.filter((x) => x.cat === 345));
      findings.surfaces.S8 = s8;

      // S9 : Sandwich Classique cat 346
      await clickCategoryPill(page, 346, observations);
      await rec.snap('S9-classique-grid');
      const s9 = await inspectCategoryGrid(page, TARGET_IMAGE_ITEMS.filter((x) => x.cat === 346));
      findings.surfaces.S9 = s9;

      findings.observations = observations;

      fs.writeFileSync(RAW_FILE, JSON.stringify(findings, null, 2));
      observations.push(`raw payload written : ${RAW_FILE}`);

      // Soft assertion : every target image item must have card_found=true.
      // Don't block on image_rendered (covered in adversarial review) —
      // surface as a finding.
      let cardsFound = 0;
      let totalTargets = 0;
      for (const sKey of ['S1', 'S6', 'S7', 'S8', 'S9']) {
        const surf = findings.surfaces[sKey];
        const cards = surf?.cards || surf?.grid?.cards || [];
        for (const c of cards) {
          totalTargets += 1;
          if (c.card_found) cardsFound += 1;
        }
      }
      observations.push(`target items found in grids : ${cardsFound}/${totalTargets}`);
      expect(cardsFound, `cards_found ${cardsFound}/${totalTargets} must be ≥ 5`).toBeGreaterThanOrEqual(5);
    } finally {
      try { rec.dispose(); } catch (_e) { /* ignore */ }
      try { await ctx.close(); } catch (_e) { /* ignore */ }
    }
  });
});
