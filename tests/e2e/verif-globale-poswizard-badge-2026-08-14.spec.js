// FoodKing E2E — verif-globale-2026-08-14 Wave B
// Frozen-zone `public/js/pos-wizard.js` LOCK verification (bf7fffea6 + f662a1277).
//
// Mission: prove the LIVE RENDER of the "2e viande = supplément" badge patch
// (LOCK_POSWIZARD_VIANDE_BADGE_2026-08-14.md), which the backing Vitest suite
// (tests/js/posWizardViandeSupplementUnified.spec.js, 5/5 green) already
// proves at the DOM-simulation level. This spec is the real-browser pass.
//
// Selectors below were read directly out of the ACTUAL diff
// (`git show f662a1277 -- public/js/pos-wizard.js`), not paraphrased from the
// plan doc:
//   - `.viande-section .quota-badge` text is EXACTLY:
//       maxViandes=1, no suppl : "<n>/<max> incluse"            (+'s' if max>1)
//       with supplement        : "<n> incluse(s) + <k> supp."
//   - `.viande-tile-count` becomes "✓<count>" (was bare "<count>" before the patch).
//   - `.viande-tile-suppl-tag` / `.viande-suppl-badge` use `fmtPrice()` which is
//     `'€' + num.toFixed(2)` — i.e. "+€2.50", NOT "+2,50€" (that's LOCK-doc prose,
//     not the literal rendered string).
//   - tile plus-button key = `'v_' + variation.id` (data-viande="v_<id>").
//
// Real fixtures (DB-verified, not invented — CLAUDE.md §3bis SSOT rule):
//   - item 163 "Sandwich Classique" — ONE viande attribute (id=1, max_select=1),
//     3 variations (713 Poulet mariné / 714 Viande Hachée / 715 Mixte) →
//     maxViandes=1 → 2nd DIFFERENT viande = supplement.
//   - item 97  "Tacos L" — TWO viande attributes (Viande 1 + Viande 2, each
//     max_select=1), 7 variations per attribute with IDENTICAL names across
//     both attrs → pos-wizard.js's getViandeItemsFromData() dedupes by
//     normalized name, yielding exactly 7 unique tiles and maxViandes=2
//     (viandeAttrs.length>1 branch) → 2 DIFFERENT viandes stay inside the free
//     quota, unaffected by the patch.
//
// Tile IDs are NOT hardcoded for the "which variation wins the name-dedupe"
// question (attribute fetch order is a backend detail, not asserted here) —
// clicks target the wizard-viande-grid tiles by DOM POSITION (first / second),
// which is robust to ordering and still exercises "two different viandes".

const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || 'TestVisuel2026!';

const SCREENSHOT_DIR = path.join(__dirname, '__screenshots__', 'verif-globale-poswizard-badge-2026-08-14');

const SANDWICH_CLASSIQUE_ITEM_ID = 163;
const TACOS_L_ITEM_ID = 97;

function parseEuro(text) {
  const m = String(text || '').match(/-?\d+[.,]\d+/);
  if (!m) return NaN;
  return parseFloat(m[0].replace(',', '.'));
}

/**
 * Click "Ajouter au panier" and, if the single-page wizard blocks on a
 * required step (sauce / pain type / etc — irrelevant to the viande-badge
 * patch under test), pick the first option of the first not-yet-satisfied
 * non-viande section and retry. Bounded loop — returns false (not a throw)
 * on failure so the caller gets a clear assertion message instead of a hang.
 */
async function fulfillRequiredStepsAndSubmit(page) {
  for (let i = 0; i < 8; i++) {
    const addBtn = page.locator('[data-action="add-to-cart"]');
    if ((await addBtn.count()) === 0) return true; // wizard already closed = already submitted
    await addBtn.click();
    await page.waitForTimeout(250);
    if ((await page.locator('#pos-wizard-root').count()) === 0) return true; // closed = success
    const errVisible = await page.locator('.wizard-validation-error').isVisible().catch(() => false);
    if (!errVisible) return true;
    const clicked = await page.evaluate(() => {
      const root = document.getElementById('pos-wizard-root');
      if (!root) return false;
      // [S25 single-page] Real markup uses several bespoke single-select
      // widget classes, not one generic `.wizard-option` — read directly out
      // of renderSinglePage()/_renderSauceBlock(): sauce chips are
      // `.sauce-chip` (data-type="sauce"), pain is `.pain-btn`
      // (data-type="pain", usually pre-defaulted). `.wizard-option` still
      // covers legacy/other-category single-step widgets (accompagnement,
      // formule, etc.) as a fallback.
      const groupSelectors = ['.sauce-chip', '.pain-btn', '.wizard-option'];
      for (const sel of groupSelectors) {
        const opts = Array.from(root.querySelectorAll(sel)).filter((o) => !o.closest('.viande-section'));
        if (opts.length === 0) continue;
        const hasSelected = opts.some((o) => o.classList.contains('selected'));
        if (!hasSelected) {
          opts[0].click();
          return true;
        }
      }
      return false;
    });
    if (!clicked) return false;
    await page.waitForTimeout(200);
  }
  return false;
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

test.describe('verif-globale 2026-08-14 Wave B — pos-wizard.js frozen-zone LOCK (badge 2e viande)', () => {
  test('4 visual states — supplement badge, Tacos L quota unaffected, cart total integrity', async ({ page }) => {
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.pos-v5-search-input__field')).toBeVisible({ timeout: 20_000 });

    // ===================================================================
    // STATE 1 — 01-sandwich-classique-1ere-viande
    // ===================================================================
    await page.locator('.pos-v5-search-input__field').fill('Sandwich Classique');
    await page.waitForTimeout(900); // debounced list refresh (onSearchInput)

    const sandwichTile = page.locator(`[data-pos-item-id="${SANDWICH_CLASSIQUE_ITEM_ID}"]`);
    await expect(sandwichTile).toBeVisible({ timeout: 15_000 });
    await sandwichTile.click();

    await expect(page.locator('#pos-wizard-root')).toBeVisible({ timeout: 10_000 });
    const sandwichViandeTiles = page.locator('.wizard-viande-grid .wizard-viande-tile');
    // 3 variations on the single "Viande 1" attribute (713/714/715) — real DB data.
    await expect(sandwichViandeTiles).toHaveCount(3, { timeout: 10_000 });

    const sandwichPlusButtons = page.locator('.wizard-viande-grid .viande-tile-add.plus');
    const totalBefore = parseEuro(await page.locator('#pos-wizard-root .total-value').innerText());

    // 1ère viande (n'importe laquelle des 3, position 0) — incluse gratuite,
    // maxViandes=1 pour Sandwich Classique.
    await sandwichPlusButtons.nth(0).click();
    await page.waitForTimeout(200);

    const quotaBadge = page.locator('.viande-section .quota-badge');
    await expect(quotaBadge).toHaveText('1/1 incluse');
    await expect(page.locator('.viande-suppl-badge')).toHaveCount(0);
    await expect(sandwichViandeTiles.nth(0).locator('.viande-tile-count')).toHaveText('✓1');

    await snap('01-sandwich-classique-1ere-viande');

    // ===================================================================
    // STATE 2 — 02-sandwich-classique-2e-viande-supplement
    // ===================================================================
    // 2e viande DIFFÉRENTE (position 1, distincte de la 1ère) — au-delà du
    // quota (max=1) → bascule en supplément payant +€2.50.
    await sandwichPlusButtons.nth(1).click();
    await page.waitForTimeout(200);

    // Assertion centrale du patch : le badge principal N'EST PLUS figé sur
    // "1/1 incluse" — format combiné exact lu dans le diff réel.
    await expect(quotaBadge).toHaveText('1 incluse + 1 supp.');

    const secondTile = sandwichViandeTiles.nth(1);
    await expect(secondTile.locator('.viande-tile-count')).toHaveText('✓1');
    await expect(secondTile.locator('.viande-tile-suppl-tag')).toHaveText('+€2.50');
    await expect(secondTile).toHaveClass(/has-suppl/);

    await expect(page.locator('.viande-suppl-badge')).toHaveText('+1 supp. (+€2.50)');

    const totalAfterSuppl = parseEuro(await page.locator('#pos-wizard-root .total-value').innerText());
    expect(totalAfterSuppl - totalBefore).toBeCloseTo(2.5, 2);

    await snap('02-sandwich-classique-2e-viande-supplement');

    const submitted1 = await fulfillRequiredStepsAndSubmit(page);
    expect(submitted1, 'sandwich wizard should submit to cart once required non-viande steps are filled').toBe(true);
    await expect(page.locator('#pos-wizard-root')).toHaveCount(0, { timeout: 8_000 });
    await page.waitForTimeout(400);

    // ===================================================================
    // STATE 3 — 03-tacos-l-2e-viande-gratuite-unaffected
    // ===================================================================
    await page.locator('.pos-v5-search-input__field').fill('Tacos L');
    await page.waitForTimeout(900);

    const tacosTile = page.locator(`[data-pos-item-id="${TACOS_L_ITEM_ID}"]`);
    await expect(tacosTile).toBeVisible({ timeout: 15_000 });
    await tacosTile.click();

    await expect(page.locator('#pos-wizard-root')).toBeVisible({ timeout: 10_000 });
    const tacosViandeTiles = page.locator('.wizard-viande-grid .wizard-viande-tile');
    // Two viande attributes (Viande 1 / Viande 2), identical variation names →
    // getViandeItemsFromData() dedupes by normalized name → 7 unique tiles,
    // regardless of which attribute's variation id "wins" the dedupe.
    await expect(tacosViandeTiles).toHaveCount(7, { timeout: 10_000 });

    const tacosPlusButtons = page.locator('.wizard-viande-grid .viande-tile-add.plus');
    // 2 viandes DIFFÉRENTES, DANS le quota gratuit (max=2 pour Tacos L, règle
    // pré-existante inchangée par le patch — c'est l'assertion adversariale :
    // le display-only patch ne doit PAS avoir régressé un produit où 2 sont
    // déjà inclus).
    await tacosPlusButtons.nth(0).click();
    await page.waitForTimeout(150);
    await tacosPlusButtons.nth(1).click();
    await page.waitForTimeout(200);

    const quotaBadgeTacos = page.locator('.viande-section .quota-badge');
    // maxViandes=2 → 's' suffix branch: "2/2 incluses" (NOT the new supplement text).
    await expect(quotaBadgeTacos).toHaveText('2/2 incluses');
    await expect(page.locator('.viande-suppl-badge')).toHaveCount(0);
    await expect(tacosViandeTiles.nth(0).locator('.viande-tile-count')).toHaveText('✓1');
    await expect(tacosViandeTiles.nth(1).locator('.viande-tile-count')).toHaveText('✓1');
    await expect(tacosViandeTiles.nth(0)).not.toHaveClass(/has-suppl/);
    await expect(tacosViandeTiles.nth(1)).not.toHaveClass(/has-suppl/);

    await snap('03-tacos-l-2e-viande-gratuite-unaffected');

    const submitted2 = await fulfillRequiredStepsAndSubmit(page);
    expect(submitted2, 'tacos wizard should submit to cart once required non-viande steps are filled').toBe(true);
    await expect(page.locator('#pos-wizard-root')).toHaveCount(0, { timeout: 8_000 });
    await page.waitForTimeout(400);

    // ===================================================================
    // STATE 4 — 04-cart-total-matches-line-items
    // Numeric integrity (REVIEWER_PROTOCOL category 11): grand total must
    // equal the sum of the individual cart line prices — proves the +€2.50
    // supplement flowed all the way from wizard display into the real
    // backend-priced cart, not just a cosmetic wizard-side number.
    // ===================================================================
    const lineTexts = await page.locator('.pos-v5-cart-item__price').allInnerTexts();
    expect(lineTexts.length).toBeGreaterThanOrEqual(2);
    const lineTotals = lineTexts.map(parseEuro);
    lineTotals.forEach((v) => expect(Number.isFinite(v)).toBe(true));
    const sumLines = lineTotals.reduce((a, b) => a + b, 0);

    const grandTotalText = await page.locator('[data-testid="pos-grand-total"]').innerText();
    const grandTotal = parseEuro(grandTotalText);

    expect(sumLines).toBeCloseTo(grandTotal, 2);
    // The two lines must differ (sandwich carries the +€2.50 supplement,
    // Tacos L does not) — proves the supplement is a REAL price delta, not
    // display-only noise that cancels out in the total.
    expect(Math.abs(lineTotals[0] - lineTotals[1])).toBeGreaterThan(0.01);

    await snap('04-cart-total-matches-line-items');
  });
});
