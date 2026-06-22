/**
 * Wave C / Z3-WIZARD — Sandwich + Tacos + Cayenne wizard capture (Kiosk + POS).
 *
 * Items targeted (per supervisor brief 2026-05-28):
 *   22 Sandwich Cayenne   — expect 10 sauces (sans Cayenne), fromagère pre-included
 *   25 Sandwich Classique — expect 11 sauces (avec Cayenne), no pre-include
 *   24 Galette Cayenne    — expect 10 sauces, fromagère pre-included
 *   26 Tacos              — expect 10 sauces, fromagère pre-included
 *   27 Big Tacos          — expect 10 sauces, fromagère pre-included
 *   36 Big Cayenne        — expect 10 sauces, fromagère pre-included
 *
 * Output:
 *   /tmp/foodking-wave-c-2026-05-28/wizard-sandwich-tacos/<slug>-<surface>-<step>.png
 *   /tmp/foodking-wave-c-2026-05-28/wizard-sandwich-tacos/<slug>-<surface>.dom.txt
 *   reports/test-e2e/supervisor-wave-c-2026-05-28/Z3-WIZARD/sandwich-tacos.json
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('./helpers/login');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT_SHOT = '/tmp/foodking-wave-c-2026-05-28/wizard-sandwich-tacos';
const OUT_JSON = path.resolve(
  __dirname,
  '../../reports/test-e2e/supervisor-wave-c-2026-05-28/Z3-WIZARD',
);

for (const dir of [OUT_SHOT, OUT_JSON]) {
  fs.mkdirSync(dir, { recursive: true });
}

const ITEMS = [
  { id: 22, name: 'Sandwich Cayenne',   slug: 'sandwich-cayenne',   category: 'SANDWICH CAYENNE',   expected: 10, fromageresInclude: true  },
  { id: 25, name: 'Sandwich Classique', slug: 'sandwich-classique', category: 'SANDWICH CLASSIQUE', expected: 11, fromageresInclude: false },
  { id: 24, name: 'Galette Cayenne',    slug: 'galette-cayenne',    category: 'GALETTE',            expected: 10, fromageresInclude: true  },
  { id: 26, name: 'Tacos',              slug: 'tacos',              category: 'TACOS',              expected: 10, fromageresInclude: true  },
  { id: 27, name: 'Big Tacos',          slug: 'big-tacos',          category: 'TACOS',              expected: 10, fromageresInclude: true  },
  { id: 36, name: 'Big Cayenne',        slug: 'big-cayenne',        category: 'SANDWICH CAYENNE',   expected: 10, fromageresInclude: true  },
];

const findings = {
  wave: 'C',
  zone: 'Z3-WIZARD',
  topic: 'sandwich-tacos',
  ranAt: new Date().toISOString(),
  base: BASE,
  results: [],
};

function recordResult(r) {
  findings.results.push(r);
}

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(180_000);

/** Kiosk capture per-item — clicks the EXACT item card by name, not a category. */
async function captureKiosk(page, item) {
  const result = {
    item_id: item.id,
    item_name: item.name,
    surface: 'kiosk',
    sauce_count_actual: null,
    sauce_names: [],
    fromageres_pre_included: null,
    cayenne_in_list: null,
    steps_captured: [],
    pass: false,
    failures: [],
  };

  try {
    // 1. Idle → catalogue (À emporter tap-to-start).
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-kiosk-00-idle.png`, fullPage: true });
    result.steps_captured.push('00-idle');

    const idleStart = page.getByText(/À emporter|Sur place|Commencer|Tap/i).first();
    if (await idleStart.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await idleStart.click({ timeout: 4_000 }).catch(() => {});
      await page.waitForTimeout(2_000);
    }

    // 2. Click category sidebar entry first (kiosk left rail).
    //    Categories may be styled as text/links — match case-insensitive.
    const catRe = new RegExp(`^${item.category.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`, 'i');
    const catLink = page.getByText(catRe).first();
    if (await catLink.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await catLink.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(1_500);
    }

    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-kiosk-01-catalogue.png`, fullPage: true });
    result.steps_captured.push('01-catalogue');

    // Find item card — kiosk renders the item NAME on the card, sometimes in upper-case.
    const itemRe = new RegExp(`^${item.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`, 'i');
    const itemLocator = page.getByText(itemRe).first();
    let itemVisible = await itemLocator.isVisible({ timeout: 3_000 }).catch(() => false);
    if (!itemVisible) {
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await page.waitForTimeout(800);
      itemVisible = await itemLocator.isVisible({ timeout: 2_500 }).catch(() => false);
    }
    if (!itemVisible) {
      result.failures.push(`item "${item.name}" not found in kiosk catalogue (cat=${item.category})`);
      return result;
    }

    // Click the item itself (card) — opens product detail with Personnaliser button
    await itemLocator.click({ timeout: 4_000 }).catch(() => {});
    await page.waitForTimeout(1_500);

    // Click Personnaliser if a button is shown
    const persoBtn = page.getByText(/Personnaliser|Composer/i).first();
    if (await persoBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await persoBtn.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(2_500);
    }

    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-kiosk-02-wizard-step1.png`, fullPage: true });
    result.steps_captured.push('02-wizard-step1');

    // 3. Step viande/style: pick first available, then NEXT (SUIVANT)
    const viandeCard = page.locator('.kiosk-viande-card, .kiosk-option-card, .kiosk-step-choices .kiosk-option').first();
    if (await viandeCard.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await viandeCard.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(800);
    }

    // SUIVANT loop to push to sauce step (max 3 hops e.g. format→viande→sauce)
    for (let hop = 0; hop < 3; hop += 1) {
      const sauceVisible = await page.locator('.kiosk-sauce-grid').isVisible({ timeout: 1_500 }).catch(() => false);
      if (sauceVisible) break;

      // Re-select any first option card on current step (idempotent)
      const anyOpt = page.locator('.kiosk-option-card, .kiosk-viande-card').first();
      if (await anyOpt.isVisible({ timeout: 1_500 }).catch(() => false)) {
        const isSelected = await anyOpt.getAttribute('class').then((c) => /selected/.test(c || ''));
        if (!isSelected) await anyOpt.click({ timeout: 1_500 }).catch(() => {});
        await page.waitForTimeout(400);
      }

      const next = page.getByRole('button', { name: /^suivant$/i }).first();
      if (await next.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await next.click({ timeout: 2_000 }).catch(() => {});
        await page.waitForTimeout(1_500);
      } else {
        break;
      }
    }

    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-kiosk-03-sauce-step.png`, fullPage: true });
    result.steps_captured.push('03-sauce-step');

    // 4. DOM-validate sauce count
    const dom = await page.evaluate(() => {
      const grid = document.querySelector('.kiosk-sauce-grid');
      if (!grid) return { count: -1, names: [], html: document.title };
      const cards = Array.from(grid.querySelectorAll(':scope > .kiosk-option-card, :scope > *'));
      const names = cards
        .map((c) => {
          const nameEl = c.querySelector('.kiosk-sauce-name');
          return nameEl ? nameEl.textContent.trim() : c.textContent.trim().slice(0, 40);
        })
        .filter(Boolean);
      return { count: cards.length, names };
    });

    result.sauce_count_actual = dom.count;
    result.sauce_names = dom.names;
    // Cayenne sauce in UI is labelled "Sauce fromagère maison" — Classique should
    // have it selectable (count=11), Cayenne items should NOT (pre-included, count=10).
    const fromagereSelectable = dom.names.some((n) => /fromag/i.test(n));
    result.cayenne_in_list = fromagereSelectable;
    result.fromageres_pre_included = !fromagereSelectable;

    fs.writeFileSync(
      `${OUT_SHOT}/${item.slug}-kiosk.dom.txt`,
      `SAUCE COUNT: ${dom.count}\nFROMAGERE SELECTABLE: ${fromagereSelectable}\nSAUCES:\n${dom.names.map((n, i) => `  ${i + 1}. ${n}`).join('\n')}\n`,
    );

    const countOK = dom.count === item.expected;
    const cayenneOK = item.fromageresInclude ? !fromagereSelectable : fromagereSelectable;
    if (!countOK) result.failures.push(`sauce count ${dom.count} !== expected ${item.expected}`);
    if (!cayenneOK) {
      result.failures.push(
        item.fromageresInclude
          ? 'Sauce fromagère maison (Cayenne) selectable but should be pre-included'
          : 'Sauce fromagère maison missing — Classique must allow selecting it',
      );
    }
    result.pass = result.failures.length === 0;
  } catch (err) {
    result.failures.push(`exception: ${String(err.message || err).slice(0, 200)}`);
  }

  return result;
}

/** POS capture per-item — login admin → POS catalog → wizard popup. */
async function capturePos(page, item) {
  const result = {
    item_id: item.id,
    item_name: item.name,
    surface: 'pos',
    sauce_count_actual: null,
    sauce_names: [],
    fromageres_pre_included: null,
    cayenne_in_list: null,
    steps_captured: [],
    pass: false,
    failures: [],
  };

  try {
    // Stay on /admin/pos (Vue SPA — the POS Operator session persists here).
    // pos-v4 Blade page drops Sanctum cookie on full-nav and bounces to /login.
    if (!/\/admin\/pos(?!-v4)/.test(page.url())) {
      await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_000);
    }

    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-pos-00-catalog.png`, fullPage: true });
    result.steps_captured.push('00-catalog');

    // POS V5 categories use button[aria-label="<exact category name>"].
    // Map UPPERCASE brief value to Title-Case used in DOM.
    const dbCategoryMap = {
      'SANDWICH CAYENNE':   'Sandwich Cayenne',
      'SANDWICH CLASSIQUE': 'Sandwich Classique',
      'GALETTE':            'Galette',
      'TACOS':              'Tacos',
    };
    const catDbName = dbCategoryMap[item.category] || item.category;
    const catBtn = page.locator(`button.pos-v5-category[aria-label="${catDbName}"]`).first();
    if (await catBtn.isVisible({ timeout: 4_000 }).catch(() => false)) {
      await catBtn.click({ timeout: 2_000 }).catch(() => {});
      await page.waitForTimeout(1_500);
    } else {
      result.failures.push(`POS category "${catDbName}" not found in nav`);
      return result;
    }

    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-pos-01-cat-filtered.png`, fullPage: true });
    result.steps_captured.push('01-cat-filtered');

    // Item tile aria-label is "Ajouter <name>, <price>" — match prefix exactly.
    const itemTile = page.locator(`.pos-v5-tile[aria-label^="Ajouter ${item.name},"]`).first();
    let visible = await itemTile.isVisible({ timeout: 4_000 }).catch(() => false);
    if (!visible) {
      result.failures.push(`POS item tile "${item.name}" not visible (cat=${item.category})`);
      return result;
    }

    await itemTile.click({ timeout: 3_000 }).catch(() => {});
    await page.waitForTimeout(2_500);
    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-pos-02-wizard-open.png`, fullPage: true });
    result.steps_captured.push('02-wizard-open');

    // POS uses pos-wizard.single-page (frozen Vanilla JS) — all steps visible at once.
    // Sauce buttons appear under a "Sauce" heading; the wizard duplicates the
    // sauce-pill markup once per slot if there are multiple viande slots.
    // We DOM-validate by scoping to the sauce section.
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT_SHOT}/${item.slug}-pos-03-sauce-step.png`, fullPage: true });
    result.steps_captured.push('03-sauce-step');

    const dom = await page.evaluate(() => {
      // POS V5 wizard uses .sauce-chips-grid > button.sauce-chip
      // The grid may be duplicated per viande-slot (Sandwich Cayenne has 2 slots).
      // Pick the FIRST grid and dedup just in case.
      const grids = Array.from(document.querySelectorAll('.sauce-chips-grid'));
      if (grids.length === 0) return { count: -1, names: [], debug: 'no .sauce-chips-grid' };
      const chips = Array.from(grids[0].querySelectorAll('button.sauce-chip, .sauce-chip'));
      const names = chips.map((c) => (c.textContent || '').trim()).filter(Boolean);
      // Dedup
      const seen = new Set();
      const uniq = [];
      for (const n of names) if (!seen.has(n)) { seen.add(n); uniq.push(n); }
      return { count: uniq.length, names: uniq, grids: grids.length, totalChips: names.length };
    });

    result.sauce_count_actual = dom.count;
    result.sauce_names = dom.names || [];
    const fromagereSelectable = (dom.names || []).some((n) => /fromag/i.test(n));
    result.cayenne_in_list = fromagereSelectable;
    result.fromageres_pre_included = !fromagereSelectable;

    fs.writeFileSync(
      `${OUT_SHOT}/${item.slug}-pos.dom.txt`,
      `SAUCE COUNT: ${dom.count}\nFROMAGERE SELECTABLE: ${fromagereSelectable}\nSAUCES:\n${(dom.names || []).map((n, i) => `  ${i + 1}. ${n}`).join('\n')}\n${dom.debug ? `DEBUG: ${dom.debug}\n` : ''}`,
    );

    const countOK = dom.count === item.expected;
    const cayenneOK = item.fromageresInclude ? !fromagereSelectable : fromagereSelectable;
    if (!countOK) result.failures.push(`POS sauce count ${dom.count} !== expected ${item.expected}`);
    if (!cayenneOK) {
      result.failures.push(
        item.fromageresInclude
          ? 'POS: Sauce fromagère maison selectable but should be pre-included'
          : 'POS: Sauce fromagère maison missing — Classique must allow it',
      );
    }
    result.pass = result.failures.length === 0;
  } catch (err) {
    result.failures.push(`POS exception: ${String(err.message || err).slice(0, 200)}`);
  }

  return result;
}

test.describe('Wave C / Z3-WIZARD — Sandwich + Tacos + Cayenne (Kiosk + POS)', () => {
  test.describe.configure({ mode: 'serial' });

  for (const item of ITEMS) {
    test(`KIOSK ${item.id} ${item.name}`, async ({ page }) => {
      const r = await captureKiosk(page, item);
      recordResult(r);
      console.log(`[KIOSK ${item.id}] count=${r.sauce_count_actual} expected=${item.expected} pass=${r.pass}`);
      // Soft assertion — we record all results regardless
    });
  }

  test('POS — login pos operator and capture all 6 items', async ({ page }) => {
    // pos@lecayenne.fr has POS Operator role → lands directly on /admin/pos.
    await loginAsPosOperator(page);
    await page.waitForTimeout(4_000); // let SPA load catalog + cash drawer state
    await page.screenshot({ path: `${OUT_SHOT}/_pos-loaded.png`, fullPage: true });

    for (const item of ITEMS) {
      const r = await capturePos(page, item);
      recordResult(r);
      console.log(`[POS ${item.id}] count=${r.sauce_count_actual} expected=${item.expected} pass=${r.pass}`);
      // Close wizard modal. Annuler button text is "× Annuler" — match on Annuler substring.
      // NO page.goto: hard-navigation drops the Vue SPA Sanctum session.
      for (let kill = 0; kill < 4; kill += 1) {
        const annul = page.locator('button').filter({ hasText: /Annuler/i }).first();
        if (await annul.isVisible({ timeout: 1_000 }).catch(() => false)) {
          await annul.click({ timeout: 1_000 }).catch(() => {});
        } else {
          await page.keyboard.press('Escape').catch(() => {});
        }
        await page.waitForTimeout(700);
        const modalOpen = await page.locator('.pos-v5-item-modal.active, .pos-v4-item-wizard-modal.active').isVisible({ timeout: 400 }).catch(() => false);
        if (!modalOpen) break;
      }
      await page.waitForTimeout(500);
    }
  });

  test.afterAll(async () => {
    // Compute verdict
    let allVerified = true;
    const drift = [];
    for (const r of findings.results) {
      if (!r.pass) {
        allVerified = false;
        drift.push({
          item_id: r.item_id,
          item_name: r.item_name,
          surface: r.surface,
          actual: r.sauce_count_actual,
          expected: ITEMS.find((it) => it.id === r.item_id)?.expected,
          failures: r.failures,
        });
      }
    }
    findings.verdict = allVerified ? 'ALL_VERIFIED' : 'DRIFT_DETECTED';
    findings.drift = drift;

    fs.writeFileSync(
      path.join(OUT_JSON, 'sandwich-tacos.json'),
      JSON.stringify(findings, null, 2),
    );
    console.log(`\n=== WAVE C / Z3-WIZARD VERDICT: ${findings.verdict} ===`);
    if (drift.length) console.log('DRIFT items:', JSON.stringify(drift, null, 2));
  });
});
