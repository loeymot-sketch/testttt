// [test-e2e goal-4chantiers-2026-08-16] Wave A — POS cart-item edit button +
// wizard sauce/garniture restore (Round 1, GStack main-team capture).
//
// Plan: reports/test-e2e/goal-4chantiers-2026-08-16/AUDIT_PLAN.md § Wave A.
// Feature under audit: commit 69b10f0aa "fix(caisse): panier — bouton
// modifier visible + restauration sauce gratuite" (2026-08-16).
//
// Product: Item id=22 "Cayenne" (branch_id=1, status=5, category "Sandwichs")
// — verified live via tinker (CLAUDE.md §3bis SSOT discipline, no invented
// product). Chosen over a plain Tacos because Cayenne carries BOTH a free
// sauce VARIATION group (ItemAttribute id=5 "Sauce (1ère Gratuite)", 13
// options incl. "Andalouse"/"Algérienne") AND free crudité EXTRAS (Salade,
// Tomate, Oignon @0€, "Oignons cuits" opt-in @0€) — the two categories the
// P0 assertion must prove render/restore independently. A bare Tacos M/L has
// NO free-priced extras at all (its only extras are paid: Oignons frits
// 0.9€, "Sauce supplémentaire" 0.5€, "Viande supplémentaire" 2.5€), so it
// cannot exercise the garniture side of the assertion.
//
// [AUDIT NOTE — verified by direct DB read 2026-08-16] The 2026-08-16 fix
// commit's own message and its companion unit test
// (tests/js/posCartEditRestoreFreeSauce.spec.js) describe the regression as
// living in the `item_extras` branch of buildWizardRestorePayload, reproduced
// with a SYNTHETIC fixture item whose free sauce is modeled as an
// `item_extras` row named "Sauce Andalouse" @0€. A full sweep of the live
// dev DB (`ItemExtra::where('name','like','%sauce%')`) found ZERO real rows
// matching that shape anywhere in the catalogue — every real product's only
// sauce-named extra is the generic PAID "Sauce supplémentaire" @0.50€ (2nd+
// sauce), never a free name-specific one. The real catalogue always models
// the free "1ère sauce gratuite" as an ItemVariation (attribute "Sauce (1ère
// Gratuite)"), a code path the test file's own docstring calls "déjà correct
// par lecture de code" — while ALSO admitting the implementer observed an
// "Andalouse → restores as Algérienne" inconsistency live in the browser and
// dismissed it, UNVERIFIED, as a "synthetic test-click delivery artifact"
// (dispatchEvent vs authentic click) rather than confirming or refuting it
// with real browser automation. That is exactly what this spec exercises:
// authentic Playwright `.click()` (real CDP input, not `element.click()`
// JS-dispatch) on the REAL item_variations sauce path, to settle whether the
// dismissal was justified.
//
// Quartet (png + dom.html + console.json + network.json) per state via
// helpers/mega-audit-snap.js → tests/e2e/__screenshots__/test-e2e-goal-4chantiers-wave-A/.
// Run: PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 \
//   npx playwright test tests/e2e/test-e2e-goal-4chantiers-wave-A.spec.js \
//   --project=chromium --workers=1 --retries=0 --reporter=list

const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'test-e2e-goal-4chantiers-wave-A');

const ID_CAYENNE = 22; // Cayenne — Sandwichs category, verified live via tinker.
// [note] chip text includes a "✓"/price badge suffix appended to the sauce name
// (rendered as its own <span>, e.g. "Andalouse\n✓") — non-anchored regexes match
// the sauce name as a substring; "Andalouse"/"Algérienne" are distinct enough
// names that a partial match cannot collide with any other chip in this list.
const SAUCE_NAME_RE = /andalouse/i; // chosen sauce
const DECOY_SAUCE_NAME_RE = /algérienne/i; // "1ère de la liste" — the name the pre-fix bug allegedly defaulted back to
const GARNITURE_NAME_RE = /oignons cuits/i; // opt-in free crudité (default OFF), distinct from the sauce entirely

test.describe('test-e2e goal-4chantiers Wave A — POS cart-item edit + wizard sauce/garniture restore', () => {
  test.describe.configure({ timeout: 300_000, retries: 0 });
  test.use({ viewport: { width: 1440, height: 900 } });

  test('01→06 — edit pencil visible + free-sauce restore lands in SAUCE section, not garniture', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SHOT_DIR);
    const modal = page.locator('#item-variation-modal');
    const grid = page.getByTestId('pos-category-grid');

    /** Modal fade-in (.modal { transition: all .3s }) — wait for opacity to settle before capture. */
    const waitModalPainted = async () => {
      await page.waitForFunction(() => {
        const m = document.querySelector('#item-variation-modal');
        return m && getComputedStyle(m).opacity === '1';
      }, { timeout: 5000 });
      await page.waitForTimeout(150);
    };

    // ── 01 : cart-empty baseline ──────────────────────────────────────────
    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
    await expect(grid).toBeVisible({ timeout: 30_000 });
    const cartArticles = page.locator('.pos-v5-cart-item');
    // [best-effort hygiene] a stray line from a prior interrupted run must not
    // contaminate this run's numeric-integrity assertions — clear it first.
    const cancelLastPre = page.getByTestId('pos-cancel-last-line');
    for (let i = 0; i < 10; i++) {
      if (!(await cancelLastPre.isVisible({ timeout: 800 }).catch(() => false))) break;
      await cancelLastPre.click().catch(() => {});
      await page.waitForTimeout(500);
    }
    await expect(cartArticles, 'panier non vide au départ — hygiène de run cassée').toHaveCount(0, { timeout: 10_000 });
    await rec.snap('01-cart-empty');

    // ── Navigate to Sandwichs category → Cayenne tile ─────────────────────
    const catTile = grid.getByTestId('pos-category-tile').filter({ hasText: /sandwich/i }).first();
    await expect(catTile, 'tuile catégorie Sandwichs absente du hub').toBeVisible({ timeout: 20_000 });
    await catTile.click();
    await page.waitForTimeout(600);
    const cayenneTile = page.locator(`[data-pos-item-id="${ID_CAYENNE}"]`).first();
    await expect(cayenneTile, 'tuile Cayenne absente de la grille Sandwichs').toBeVisible({ timeout: 20_000 });

    // ── 02 : wizard-open-add (before any selection) ───────────────────────
    await cayenneTile.click();
    await expect(modal).toBeVisible({ timeout: 10_000 });
    const sauceSection = modal.locator('.sauce-section');
    const cruditesSection = modal.locator('.crudites-section');
    const viandeSection = modal.locator('.viande-section');
    await expect(sauceSection, 'section Sauce absente du wizard Cayenne').toBeVisible({ timeout: 20_000 });
    await expect(cruditesSection, 'section Crudités absente du wizard Cayenne').toBeVisible({ timeout: 20_000 });
    await expect(viandeSection, 'section Viande absente du wizard Cayenne').toBeVisible({ timeout: 20_000 });
    // Baseline: neither the target sauce nor the target garniture pre-selected.
    const andalouseChip = sauceSection.locator('.sauce-chip').filter({ hasText: SAUCE_NAME_RE }).first();
    await expect(andalouseChip, 'chip sauce Andalouse absente').toBeVisible({ timeout: 10_000 });
    await expect(andalouseChip).not.toHaveClass(/selected/);
    const oignonsCuitsToggle = cruditesSection.locator('.garniture-toggle-btn').filter({ hasText: GARNITURE_NAME_RE }).first();
    await expect(oignonsCuitsToggle, 'toggle « Oignons cuits » absent').toBeVisible({ timeout: 10_000 });
    await expect(oignonsCuitsToggle, '« Oignons cuits » ne doit PAS être inclus par défaut').not.toHaveClass(/included/);
    await waitModalPainted();
    await rec.snap('02-wizard-open-add');

    // Cayenne requires exactly 1 included viande ("0/1 incluse" gate) before
    // canAddToCart passes — select "Poulet mariné" first (required, not part
    // of the sauce/garniture assertion but a precondition to reach add-to-cart).
    const pouletTile = viandeSection.locator('.wizard-viande-tile').filter({ hasText: /poulet mariné/i }).first();
    await expect(pouletTile, 'tuile viande Poulet mariné absente').toBeVisible({ timeout: 10_000 });
    await pouletTile.locator('.viande-tile-add').first().click();
    await expect(pouletTile, 'viande Poulet mariné non marquée active après clic').toHaveClass(/active/, { timeout: 5000 });

    // ── Select: free sauce Andalouse (variation) + garniture Oignons cuits (extra) ──
    // Authentic Playwright input (real CDP click, not element.click() JS-dispatch) —
    // this is precisely the delivery mechanism the implementer's own test docstring
    // speculated (without verifying) might be responsible for a prior observed
    // "Andalouse → restores as Algérienne" inconsistency.
    await andalouseChip.click();
    await expect(andalouseChip, 'chip Andalouse non sélectionnée après clic').toHaveClass(/selected/, { timeout: 5000 });
    await oignonsCuitsToggle.click();
    await expect(oignonsCuitsToggle, '« Oignons cuits » non coché après clic').toHaveClass(/included/, { timeout: 5000 });

    // ── 03 : wizard-sauce-selected — both categories correctly placed, simultaneously ──
    // Sauce: exactly Andalouse selected, inside .sauce-section, NOT the decoy Algérienne.
    const selectedSauceChips = sauceSection.locator('.sauce-chip.selected');
    await expect(selectedSauceChips, 'plus d’une sauce sélectionnée à ce stade').toHaveCount(1);
    await expect(selectedSauceChips.first()).toHaveText(SAUCE_NAME_RE);
    const decoyChip = sauceSection.locator('.sauce-chip').filter({ hasText: DECOY_SAUCE_NAME_RE }).first();
    await expect(decoyChip, 'la sauce-piège Algérienne (1ère de la liste) ne doit PAS être sélectionnée').not.toHaveClass(/selected/);
    // Garniture: Oignons cuits included, inside .crudites-section, no sauce chip leaked in there.
    await expect(oignonsCuitsToggle).toHaveClass(/included/);
    await expect(cruditesSection.locator('.garniture-toggle-btn').filter({ hasText: SAUCE_NAME_RE }), 'la sauce a fuité dans la section Crudités').toHaveCount(0);
    await waitModalPainted();
    await rec.snap('03-wizard-sauce-selected');

    // ── Add to cart ────────────────────────────────────────────────────────
    const addToCart = modal.locator('button[data-action="add-to-cart"]').first();
    await expect(addToCart).toBeVisible({ timeout: 5000 });
    await addToCart.click();
    await expect(modal).toBeHidden({ timeout: 10_000 });

    // ── 04 : cart-line-added — edit pencil visible, 28px, non-transparent ──
    const cartLine = page.locator('article.pos-v5-cart-item').filter({ has: page.locator('.pos-v5-cart-item__name', { hasText: /cayenne/i }) }).first();
    await expect(cartLine, 'ligne panier Cayenne absente').toBeVisible({ timeout: 10_000 });
    const editBtn = cartLine.locator('.pos-v5-cart-item__edit').first();
    await expect(editBtn, 'bouton crayon Modifier absent de la ligne panier').toBeVisible({ timeout: 10_000 });
    // [AUDIT NOTE] resources/js/helpers/caisseZoom.js applies an INTENTIONAL,
    // owner-approved `document.body.style.zoom = 0.9` for the whole POS surface
    // (CAISSE_ZOOM, mounted/unmounted with PosComponent) — pre-existing, unrelated
    // to this fix. It uniformly shrinks EVERY on-screen pixel measurement
    // (getBoundingClientRect) by 10%, so the real rendered box is ~25.2px even
    // though the CSS authors 28px. `getComputedStyle().width` is zoom-invariant
    // in Chromium and reflects the authored CSS value — use it for "is the fix's
    // 28px really in the stylesheet" (plan's literal ask), and separately compute
    // the REAL on-screen size (rect / zoom) to confirm it clears the WCAG 2.5.8
    // 24px floor once the deliberate zoom is applied — the honest end-user reality.
    const editBtnBox = await editBtn.evaluate((el) => {
      const cs = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      const bodyZoom = parseFloat(document.body.style.zoom || '1') || 1;
      return {
        computedWidth: parseFloat(cs.width), computedHeight: parseFloat(cs.height),
        rectWidth: rect.width, rectHeight: rect.height,
        bodyZoom,
        realWidth: rect.width / bodyZoom, realHeight: rect.height / bodyZoom,
        bg: cs.backgroundColor, opacity: cs.opacity,
      };
    });
    console.log('[wave-A] edit button computed style:', JSON.stringify(editBtnBox));
    expect(editBtnBox.computedWidth, 'CSS authored largeur < 28px (régression seuil tactile WCAG 2.5.8)').toBeGreaterThanOrEqual(27.5);
    expect(editBtnBox.computedHeight, 'CSS authored hauteur < 28px').toBeGreaterThanOrEqual(27.5);
    expect(editBtnBox.realWidth, 'taille RÉELLE à l’écran (une fois le zoom caisse 0.9 appliqué) < seuil WCAG 2.5.8 24px').toBeGreaterThanOrEqual(24);
    expect(editBtnBox.bg, 'fond bouton crayon TRANSPARENT (ancien défaut 22px non visible)').not.toBe('rgba(0, 0, 0, 0)');
    const priceAtAdd = (await cartLine.locator('.pos-v5-cart-item__price').first().innerText()).trim();
    console.log('[wave-A] cart line price at state 4 (post-add):', priceAtAdd);
    const compositionAtAdd = (await cartLine.locator('.pos-v5-cart-item__detail').first().innerText()).trim();
    console.log('[wave-A] cart line composition at state 4 (post-add):', JSON.stringify(compositionAtAdd));
    await cartLine.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await rec.snap('04-cart-line-added');

    // ── Reopen wizard via the edit pencil ───────────────────────────────────
    await editBtn.click();
    await expect(modal, 'wizard ne se rouvre pas après clic crayon').toBeVisible({ timeout: 10_000 });
    await expect(sauceSection, 'section Sauce absente au ré-ouverture édition').toBeVisible({ timeout: 20_000 });
    await expect(cruditesSection, 'section Crudités absente au ré-ouverture édition').toBeVisible({ timeout: 20_000 });

    // ── 05 : wizard-reopen-edit — THE CRITICAL P0 ASSERTION ────────────────
    // The free sauce chosen in state 3 (Andalouse) must appear SELECTED inside
    // the SAUCE section specifically — not the garniture/crudités section, and
    // not silently defaulted to the alphabetically-first decoy (Algérienne).
    const restoredSelectedSauces = sauceSection.locator('.sauce-chip.selected');
    const restoredSauceCount = await restoredSelectedSauces.count();
    console.log('[wave-A] P0 check — sauce chips selected on reopen:', restoredSauceCount);
    if (restoredSauceCount > 0) {
      const restoredSauceTexts = await restoredSelectedSauces.allInnerTexts();
      console.log('[wave-A] P0 check — restored selected sauce chip text(s):', JSON.stringify(restoredSauceTexts));
    }
    expect(restoredSauceCount, 'P0 — aucune sauce sélectionnée à la ré-ouverture (restauration perdue)').toBe(1);
    await expect(restoredSelectedSauces.first(), 'P0 — la sauce restaurée n’est PAS Andalouse (le vrai choix client)').toHaveText(SAUCE_NAME_RE);
    await expect(decoyChip, 'P0 — restauration retombée sur la sauce-piège Algérienne (1ère de la liste), pas le vrai choix').not.toHaveClass(/selected/);
    // Cross-section leak check: the restored sauce name must not appear as a
    // selected/included garniture chip (would prove the extras-misclassification
    // regression the fix commit targeted).
    const leakedIntoGarnitures = cruditesSection.locator('.garniture-toggle-btn.included').filter({ hasText: SAUCE_NAME_RE });
    await expect(leakedIntoGarnitures, 'P0 — la sauce Andalouse est apparue comme garniture cochée (misclassification confirmée)').toHaveCount(0);
    // Independent garniture restore: Oignons cuits must still be included, in its own section.
    const restoredGarniture = cruditesSection.locator('.garniture-toggle-btn').filter({ hasText: GARNITURE_NAME_RE }).first();
    await expect(restoredGarniture, 'garniture Oignons cuits non restaurée à la ré-ouverture').toHaveClass(/included/, { timeout: 10_000 });
    // [test-e2e fix A-001 round-1 2026-08-16] Was a non-fatal, log-only anomaly
    // check ("hors P0"). Promoted to a HARD assertion after the round-1 fix:
    // buildWizardRestorePayload (ItemComponent.vue) now writes an EXPLICIT
    // `false` for every free/garniture-eligible extra that isn't present on the
    // cart line, not just `true` for the ones that are — so raw "Oignon"
    // (excluded at add-time via the cooked/raw mutual-exclusivity swap to
    // "Oignons cuits") must stay excluded on a pure reopen, not silently win
    // back the frozen wizard's own name-aware default (cruditeDefaultIncluded).
    // This assertion is the real-browser proof that the fix landed — if this
    // regresses, this spec must go red again.
    // [test-e2e fix A-001 round-1 2026-08-16] The single-page crudités block
    // actually rendered here (public/js/pos-wizard.js, the `.crudites-section`
    // branch ~L3179-3202) uses `data-garniture="c_<id>"` — not `data-name` (that
    // attribute belongs to a different, legacy multi-step renderer) — and the
    // rendered label is `emoji + (isIncluded ? '✓ ' : '✕ Sans ') + name` (e.g.
    // "🧅 ✕ Sans Oignon" / "🧅 ✓ Oignon"). A text-anchored regex like
    // `/^✓?\s*oignon$/i` never matches (the emoji prefix breaks the `^` anchor)
    // and silently finds zero elements — exactly why the PRE-fix version of
    // this check was non-fatal AND never actually fired. Match on substring
    // "oignon" while excluding "cuit" to isolate the raw-onion toggle from its
    // "Oignons cuits" mutual-exclusivity sibling, independent of attribute name.
    const rawOnionRestored = cruditesSection.locator('.garniture-toggle-btn')
      .filter({ hasText: /oignon/i })
      .filter({ hasNotText: /cuit/i })
      .first();
    await expect(
      rawOnionRestored,
      'A-001 RÉGRESSION — "Oignon" (cru, exclu explicitement à l’ajout via l’exclusivité "Oignons cuits") réapparaît INCLUS à la ré-ouverture : le round-trip réintroduit silencieusement un ingrédient exclu, exactement le défaut que le fix A-001 corrige',
    ).not.toHaveClass(/included/);
    await waitModalPainted();
    await rec.snap('05-wizard-reopen-edit');

    // ── Confirm edit without changing anything ──────────────────────────────
    const addToCartEdit = modal.locator('button[data-action="add-to-cart"]').first();
    await expect(addToCartEdit).toBeVisible({ timeout: 5000 });
    await addToCartEdit.click();
    await expect(modal).toBeHidden({ timeout: 10_000 });

    // ── 06 : wizard-edit-confirm — numeric integrity, price(6) === price(4) ──
    const cartLineAfterEdit = page.locator('article.pos-v5-cart-item').filter({ has: page.locator('.pos-v5-cart-item__name', { hasText: /cayenne/i }) }).first();
    await expect(cartLineAfterEdit, 'ligne panier Cayenne absente après edit-confirm').toBeVisible({ timeout: 10_000 });
    const priceAfterEdit = (await cartLineAfterEdit.locator('.pos-v5-cart-item__price').first().innerText()).trim();
    console.log('[wave-A] cart line price at state 6 (post-edit-confirm):', priceAfterEdit);
    expect(priceAfterEdit, 'DRIFT PRIX — le round-trip édition a changé le prix ligne (sauce/garniture mal reclassée facturée en trop/moins)').toBe(priceAtAdd);
    // Cart count must still be exactly 1 line (replaceCartLine, not a duplicate append).
    await expect(cartArticles, 'edit-confirm a dupliqué la ligne panier au lieu de la remplacer').toHaveCount(1, { timeout: 5000 });
    // [test-e2e fix A-001 round-1 2026-08-16] Composition text comparison — was
    // log-only ("hors P0, non-fatal"), promoted to a HARD assertion. Proves the
    // onion-exclusivity fix landed in the SAVED cart line, not just the
    // transient wizard UI at state 5: price alone can't catch this (both onion
    // variants are 0€), but the COMPOSITION text must be byte-identical
    // before/after a pure reopen+confirm round-trip with zero user changes —
    // any drift here is exactly the silent dietary-exclusion/kitchen-ticket
    // defect A-001 targets, and a cashier confirming an edit would never see it
    // on the receipt total.
    const compositionAfterEdit = (await cartLineAfterEdit.locator('.pos-v5-cart-item__detail').first().innerText()).trim();
    console.log('[wave-A] cart line composition at state 6 (post-edit-confirm):', JSON.stringify(compositionAfterEdit));
    expect(
      compositionAfterEdit,
      'A-001 RÉGRESSION — DRIFT DE COMPOSITION au round-trip édition (prix identique, ingrédients différents) :\n  AVANT (état 4): ' + JSON.stringify(compositionAtAdd) + '\n  APRÈS (état 6): ' + JSON.stringify(compositionAfterEdit),
    ).toBe(compositionAtAdd);
    await cartLineAfterEdit.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await rec.snap('06-wizard-edit-confirm');

    // ── Cleanup: empty the cart (no payment ever touched — pure cart/wizard audit) ──
    const cancelLast = page.getByTestId('pos-cancel-last-line');
    for (let i = 0; i < 10; i++) {
      if (!(await cancelLast.isVisible({ timeout: 800 }).catch(() => false))) break;
      await cancelLast.click().catch(() => {});
      await page.waitForTimeout(500);
    }
    await expect(cartArticles, 'panier non vidé en fin de parcours').toHaveCount(0, { timeout: 10_000 });

    rec.dispose();
  });
});
