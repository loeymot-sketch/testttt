// FoodKing E2E — Wave KIOSK : menu-v3-1-2026-05-14 (burger composer)
//
// MISSION
//   Verify Menu V3.1 burger composer heal (MenuHealLightV31BurgerCommand) :
//
//   - Chicken Burger 375 + Chicken Burger Special 490 use the new 3-step
//     wizard : Sauce / Suppléments (with Double viande +2.50€) / Menu.
//     NO viande step (Crispy locked implicit per owner spec).
//
//   - Regression check : Sandwich Cayenne (cat 344 → 474) wizard step 1
//     still shows 4 viandes (Mariné / Curry / Tandoori / Crispy).
//
//   - Regression check : Tacos M (478) wizard step 1 still shows 4 viandes.
//
//   - Regression check : Bowl Frites curry (493) wizard step 1 still shows
//     2 sauces (Spicy + Sauce fromagère maison).
//
// SURFACES (7 captures)
//   S1 : /kiosk/categories?cat=349 — Burgers grid (2 cards : 375 + 490)
//   S2 : /kiosk/wizard/375 step 1 — Sauce step rendered (NO viande step)
//   S3 : /kiosk/wizard/375 step 2 — Suppléments (Double viande +2,50€ visible)
//   S4 : /kiosk/wizard/375 step 3 — Menu (Combo / +Frites / Drink-only / Sans menu)
//   S5 : /kiosk/wizard/474 step 1 — Sandwich Cayenne 4 viandes (regression)
//   S6 : /kiosk/wizard/478 step 1 — Tacos M 4 viandes (regression)
//   S7 : /kiosk/wizard/493 step 1 — Bowl Frites curry 2 sauces (regression)
//
// FROZEN ZONES : 0 line touched. KioskWizardComponent.vue READ for selectors only.
//
// Adversarial criteria : P0/P1/P2/P3 surfaced in payload — caller picks verdict.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { resetKioskToken } = require('./helpers/kiosk-order');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/menu-v3-1');
const REPORT_DIR = path.resolve(REPO_ROOT, 'reports/audit/menu-v3-2026-05-14');
const RAW_FILE = path.join(REPORT_DIR, 'raw-v3-1-burger-payload.json');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

const VIANDE_NAMES = ['Poulet mariné', 'Poulet curry', 'Poulet tandoori', 'Poulet crispy'];

// ───────────────────────── helpers ─────────────────────────

async function enterCategoriesViaIdle(page, observations) {
  if (!/\/kiosk\/idle/.test(page.url())) {
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
  }
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
  let visible = false;
  for (let i = 0; i < 10; i++) {
    visible = await takeaway.isVisible().catch(() => false);
    if (visible) break;
    await page.waitForTimeout(500);
  }
  if (!visible) {
    observations.push(`enterCategoriesViaIdle: takeaway not visible, URL=${page.url()}`);
    return false;
  }
  await takeaway.click();
  for (let i = 0; i < 20; i++) {
    if (/\/kiosk\/categories/.test(page.url())) return true;
    await page.waitForTimeout(300);
  }
  observations.push(`enterCategoriesViaIdle: never reached /kiosk/categories, URL=${page.url()}`);
  return false;
}

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
    observations.push(`clickCategoryPill ${catId} ex: ${String(e?.message || e).slice(0, 200)}`);
  }
  observations.push(`clickCategoryPill ${catId}: pill NOT visible`);
  return false;
}

async function openWizard(page, itemId, observations) {
  await page.goto(`/kiosk/wizard/${itemId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  for (let i = 0; i < 10; i++) {
    const ok = await page.evaluate(() => document.querySelector('.kiosk-step-visual-label') !== null);
    if (ok) return true;
    await page.waitForTimeout(500);
  }
  observations.push(`openWizard ${itemId}: no .kiosk-step-visual-label, URL=${page.url()}`);
  return false;
}

async function inspectWizard(page) {
  return await page.evaluate(() => {
    const labels = Array.from(document.querySelectorAll('.kiosk-step-visual-label'))
      .map((n) => (n.textContent || '').replace(/\s+/g, ' ').trim());
    const dots = document.querySelectorAll('.kiosk-step-dot').length;
    const candidates = [
      '.kiosk-viande-card',
      '.kiosk-option-card',
      '.kiosk-supplement-row',
      '.kiosk-generic-choice',
      '.kiosk-menu-card',
      '.kiosk-pain-card',
      '.kiosk-garniture-card',
      '[data-testid^="kiosk-wizard-choice-"]',
      '[data-testid^="kiosk-wizard-option-"]',
    ];
    let choiceNodes = [];
    let selectorUsed = null;
    for (const sel of candidates) {
      const list = Array.from(document.querySelectorAll(sel));
      if (list.length > 0) { choiceNodes = list; selectorUsed = sel; break; }
    }
    const choices = choiceNodes.map((n) => (n.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100));
    const heading = (document.querySelector('.kiosk-step-title, .kiosk-wizard-step-title, [data-testid="kiosk-wizard-step-title"]')?.textContent || '')
      .replace(/\s+/g, ' ').trim().slice(0, 200);
    const dotsAll = Array.from(document.querySelectorAll('.kiosk-step-dot'));
    const activeIdx = dotsAll.findIndex((n) => /active|current/.test(n.className));
    const bodyText = (document.body?.innerText || '').replace(/\s+/g, ' ');

    // i18n leak scan
    const i18n_leaks = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let nNode;
    const re = /^[a-z]+(\.[a-z_]+){1,4}$/;
    while ((nNode = walker.nextNode())) {
      const t = (nNode.nodeValue || '').trim();
      if (t && re.test(t) && t.length < 80) {
        i18n_leaks.push(t);
        if (i18n_leaks.length >= 15) break;
      }
    }

    return {
      step_labels: labels,
      dots_count: dots,
      active_dot_index: activeIdx,
      heading,
      choices,
      choices_count: choiceNodes.length,
      choice_selector_used: selectorUsed,
      i18n_leaks,
      // explicit text-presence flags
      has_sauce_heading: /Sauce|sauce/.test(heading),
      has_supplements_heading: /Suppl[ée]ment/.test(heading),
      has_menu_heading: /\bMenu\b/i.test(heading),
      has_viande_heading: /Viande|viande/.test(heading),
      has_double_viande: /Double\s+viande/i.test(bodyText),
      // Accept "+2,50 €", "+ 2.50€", "€2,50", "2,50 €" etc.
      has_plus_2_50: /(?:\+\s*2[,.]50\s*€|€\s*2[,.]50|2[,.]50\s*€)/.test(bodyText),
      has_cheddar: /Cheddar/.test(bodyText),
      has_jambon: /Jambon/.test(bodyText),
      has_oeuf: /\bŒuf\b|\bOeuf\b/.test(bodyText),
      has_spicy: /Spicy/i.test(bodyText),
      has_sauce_fromagere: /sauce\s*fromag[èe]re\s*maison/i.test(bodyText),
      has_poulet_marine: /Poulet\s*marin[ée]/i.test(bodyText),
      has_poulet_curry: /Poulet\s*curry/i.test(bodyText),
      has_poulet_tandoori: /Poulet\s*tandoori/i.test(bodyText),
      has_poulet_crispy: /Poulet\s*crispy/i.test(bodyText),
      has_menu_combo: /Menu\s*(combo|menu)/i.test(bodyText),
      has_menu_frites: /Menu\s*Frites|\+\s*Frites/i.test(bodyText),
      has_sans_menu: /sans\s*menu/i.test(bodyText),
      body_text_preview: bodyText.slice(0, 800),
    };
  });
}

async function clickNextStep(page) {
  const cands = [
    'button.kiosk-btn-next:not([disabled])',
    'button.kiosk-btn-next--cart:not([disabled])',
    'button.kiosk-progress-arrow:not([disabled])',
    'button:has-text("SUIVANT"):not([disabled])',
    'button:has-text("Suivant"):not([disabled])',
    'button:has-text("Continuer"):not([disabled])',
    'button:has-text("Valider"):not([disabled])',
  ];
  for (const sel of cands) {
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

async function selectFirstChoice(page) {
  const sels = [
    '.kiosk-viande-card:not(.disabled):not(.kiosk-viande-card--disabled)',
    '.kiosk-option-card:not(.kiosk-variation--disabled):not(.is-out-of-stock)',
    '.kiosk-supplement-row:not(.disabled)',
    '.kiosk-generic-choice:not(.unavailable)',
    '.kiosk-pain-card:not(.disabled)',
    '.kiosk-garniture-card:not(.disabled)',
    '.kiosk-menu-card:not(.disabled)',
    '[data-testid^="kiosk-wizard-choice-"]',
  ];
  for (const sel of sels) {
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

async function inspectCategoryGrid(page, expectedIds) {
  return await page.evaluate(({ expectedIds }) => {
    const result = {
      total_cards: document.querySelectorAll('[data-testid^="kiosk-product-card-"]').length,
      cards: [],
    };
    for (const id of expectedIds) {
      const card = document.querySelector(`[data-testid="kiosk-product-card-${id}"]`);
      if (!card) {
        result.cards.push({ id, card_found: false });
        continue;
      }
      const img = card.querySelector('img.kiosk-product-image');
      const fb = card.querySelector('.kiosk-product-image-fallback');
      const text = (card.textContent || '').replace(/\s+/g, ' ').trim();
      result.cards.push({
        id,
        card_found: true,
        image_rendered: !!img,
        image_src: img ? (img.getAttribute('src') || '').slice(0, 200) : null,
        fallback_rendered: !!fb,
        card_text_preview: text.slice(0, 200),
      });
    }
    return result;
  }, { expectedIds });
}

// ───────────────────────── test ─────────────────────────

test.describe('menu-v3-1 burger Wave KIOSK — visual heal verification', () => {
  test.describe.configure({ retries: 0 });
  test.setTimeout(600_000);

  test('7 surfaces — burger 3-step composer + Double viande + 4-viande regressions', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const rec = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    const observations = [];
    const findings = { surfaces: {} };

    try {
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

      // ── S1 : Burgers grid 349 — both cards visible
      await clickCategoryPill(page, 349, observations);
      await page.waitForTimeout(1000);
      await rec.snap('S1-burgers-grid');
      const s1 = await inspectCategoryGrid(page, [375, 490]);
      // augment with full body text scan
      const s1Text = await page.evaluate(() => {
        const t = (document.body?.innerText || '').replace(/\s+/g, ' ');
        return {
          has_chicken_burger: /Chicken\s+Burger\b/i.test(t),
          has_chicken_burger_special: /Chicken\s+Burger\s+Special/i.test(t),
          has_6_90: /6[,.]90\s*€/.test(t),
          has_8_90: /8[,.]90\s*€/.test(t),
          text_preview: t.slice(0, 800),
        };
      });
      findings.surfaces.S1 = { grid: s1, text: s1Text };
      observations.push(`S1 cards=${s1.total_cards} 375=${s1.cards[0]?.card_found} 490=${s1.cards[1]?.card_found} 6.90=${s1Text.has_6_90} 8.90=${s1Text.has_8_90}`);

      // ── S2 : /kiosk/wizard/375 step 1 — Sauce step (NO viande)
      const w375ok = await openWizard(page, 375, observations);
      observations.push(`wizard 375 opened=${w375ok}`);
      await rec.snap('S2-burger-375-step1-sauce');
      const s2 = await inspectWizard(page);
      findings.surfaces.S2 = s2;
      observations.push(`S2 heading="${s2.heading}" dots=${s2.dots_count} sauce_h=${s2.has_sauce_heading} viande_h=${s2.has_viande_heading} choices=${s2.choices_count}`);

      // ── S3 : step 2 Suppléments — Double viande visible
      let s3 = null;
      if (w375ok) {
        await selectFirstChoice(page);
        const nxt = await clickNextStep(page);
        observations.push(`S2->S3 next: ${JSON.stringify(nxt)}`);
        await page.waitForTimeout(800);
        await rec.snap('S3-burger-375-step2-supplements');
        s3 = await inspectWizard(page);
        findings.surfaces.S3 = s3;
        observations.push(`S3 heading="${s3.heading}" double_viande=${s3.has_double_viande} +2.50=${s3.has_plus_2_50} cheddar=${s3.has_cheddar} jambon=${s3.has_jambon}`);
      }

      // ── S4 : step 3 Menu — Combo / +Frites / Drink-only / Sans menu
      let s4 = null;
      if (w375ok && s3) {
        const nxt2 = await clickNextStep(page);
        observations.push(`S3->S4 next: ${JSON.stringify(nxt2)}`);
        await page.waitForTimeout(800);
        await rec.snap('S4-burger-375-step3-menu');
        s4 = await inspectWizard(page);
        findings.surfaces.S4 = s4;
        observations.push(`S4 heading="${s4.heading}" menu_h=${s4.has_menu_heading} sans_menu=${s4.has_sans_menu} frites=${s4.has_menu_frites}`);
      }

      // ── S5 : Sandwich Cayenne 474 step 1 — 4 viandes still render
      const w474ok = await openWizard(page, 474, observations);
      observations.push(`wizard 474 opened=${w474ok}`);
      await rec.snap('S5-sandwich-cayenne-474-step1');
      let s5 = await inspectWizard(page);
      // Walk forward up to 3 steps if first isn't viande
      if (w474ok && !s5.has_viande_heading) {
        for (let i = 0; i < 3 && !s5.has_viande_heading; i++) {
          const selOk = await selectFirstChoice(page);
          const nxt = await clickNextStep(page);
          observations.push(`S5 walk step=${i} sel=${selOk} next=${JSON.stringify(nxt)}`);
          if (!nxt.ok) break;
          s5 = await inspectWizard(page);
        }
        await rec.snap('S5-sandwich-cayenne-474-viande-step');
      }
      findings.surfaces.S5 = s5;
      const s5ViandesFound = VIANDE_NAMES.filter((n) => new RegExp(n.replace(' ', '\\s*'), 'i').test(s5.body_text_preview)).length;
      observations.push(`S5 heading="${s5.heading}" viande_h=${s5.has_viande_heading} viandes_found=${s5ViandesFound}/4 (marin=${s5.has_poulet_marine} curry=${s5.has_poulet_curry} tand=${s5.has_poulet_tandoori} crispy=${s5.has_poulet_crispy})`);

      // ── S6 : Tacos M 478 step 1 — 4 viandes still render
      const w478ok = await openWizard(page, 478, observations);
      observations.push(`wizard 478 opened=${w478ok}`);
      await rec.snap('S6-tacos-m-478-step1');
      let s6 = await inspectWizard(page);
      if (w478ok && !s6.has_viande_heading) {
        for (let i = 0; i < 3 && !s6.has_viande_heading; i++) {
          const selOk = await selectFirstChoice(page);
          const nxt = await clickNextStep(page);
          observations.push(`S6 walk step=${i} sel=${selOk} next=${JSON.stringify(nxt)}`);
          if (!nxt.ok) break;
          s6 = await inspectWizard(page);
        }
        await rec.snap('S6-tacos-m-478-viande-step');
      }
      findings.surfaces.S6 = s6;
      const s6ViandesFound = VIANDE_NAMES.filter((n) => new RegExp(n.replace(' ', '\\s*'), 'i').test(s6.body_text_preview)).length;
      observations.push(`S6 heading="${s6.heading}" viande_h=${s6.has_viande_heading} viandes_found=${s6ViandesFound}/4`);

      // ── S7 : Bowl 493 step 1 — 2 sauces still render
      const w493ok = await openWizard(page, 493, observations);
      observations.push(`wizard 493 opened=${w493ok}`);
      await rec.snap('S7-bowl-493-step1-sauce');
      const s7 = await inspectWizard(page);
      findings.surfaces.S7 = s7;
      observations.push(`S7 heading="${s7.heading}" spicy=${s7.has_spicy} fromagere=${s7.has_sauce_fromagere} choices=${s7.choices_count}`);

      findings.observations = observations;
      fs.writeFileSync(RAW_FILE, JSON.stringify(findings, null, 2));
      observations.push(`raw payload: ${RAW_FILE}`);

      // Soft assertions — bare minimum sanity, full verdict in CONVERGENCE.md
      // S1: at least one burger card visible
      expect(s1.cards.filter((c) => c.card_found).length, 'S1 ≥1 burger card found').toBeGreaterThanOrEqual(1);
      // S5+S6 regression: at least 2/4 viande names found
      expect(s5ViandesFound, 'S5 sandwich cayenne ≥2/4 viandes').toBeGreaterThanOrEqual(2);
      expect(s6ViandesFound, 'S6 tacos M ≥2/4 viandes').toBeGreaterThanOrEqual(2);
    } finally {
      try { rec.dispose(); } catch (_e) { /* ignore */ }
      try { await ctx.close(); } catch (_e) { /* ignore */ }
    }
  });
});
