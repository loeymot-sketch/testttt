// [test-e2e goal4-predeploy-2026-07-17] Wave B — CAISSE (Round 1, GStack capture)
// États B01→B08 du PLAN (reports/test-e2e/goal4-predeploy-2026-07-17/PLAN.md) :
//   B01 login POS OK · B02 grille home (vignettes cat) · B03 grille menu-enfant
//   B04 popup Nuggets (chips 12 sauces + « 1ère gratuite ») · B05 sauce choisie →
//   Ajouter au panier → ligne ticket 4,90 · B06 popup kids-burger (crudités cochées)
//   B07 accordéon « + Suppléments » DÉPLIÉ (liste @0,90) · B08 modal Bol Frites
//   (« Option Gratiné » @2,00 dans les extras).
// Quartet (png + dom.html + console.json + network.json) par état via
// helpers/mega-audit-snap.js → tests/e2e/__screenshots__/test-e2e-B/.
// AUCUN paiement (pas d'ordre fiscal) ; panier vidé en fin via « annuler dernière ligne ».
// Popups fermées via bouton Annuler SCOPÉ #item-variation-modal + waitFor hidden.
const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'test-e2e-B');

const ID_NUGGETS = 40; // Menu Enfant Nuggets — 4,90 €
const ID_KIDS_BURGER = 106; // Menu Enfant Chicken Burger
const ID_BOL_FRITES = 41; // Bol Frites — Option Gratiné @2,00

test.describe('test-e2e goal4 Wave B — CAISSE B01→B08', () => {
  test.describe.configure({ timeout: 300_000, retries: 0 });
  test.use({ viewport: { width: 1440, height: 900 } });

  test('B01→B08 — parcours caisse popup wizard (prix 4,90 / 0,90 / 2,00), sans paiement', async ({ page }) => {
    const rec = attachMegaAuditRecorder(page, SHOT_DIR);
    const modal = page.locator('#item-variation-modal');
    const grid = page.getByTestId('pos-category-grid');

    /**
     * Attend la fin du fade-in du modal (.modal a `transition: all .3s` ;
     * un snap déclenché ~150-250ms après .active capture un état translucide
     * illisible — prouvé au round 1, probe opacité : régime stable = 1).
     */
    const waitModalPainted = async () => {
      await page.waitForFunction(() => {
        const m = document.querySelector('#item-variation-modal');
        return m && getComputedStyle(m).opacity === '1';
      }, { timeout: 5000 });
      await page.waitForTimeout(150);
    };

    /** Ferme la popup via Annuler SCOPÉ au modal, puis attend sa disparition. */
    const closeModal = async () => {
      const cancel = modal.locator('button').filter({ hasText: /annuler/i }).first();
      await expect(cancel).toBeVisible({ timeout: 8000 });
      await cancel.click();
      await expect(modal).toBeHidden({ timeout: 8000 });
      await page.waitForTimeout(400);
    };

    /** Depuis la grille produits, revient au hub catégories si nécessaire, puis ouvre une catégorie. */
    const openCategory = async (nameRe) => {
      if (!(await grid.isVisible({ timeout: 1500 }).catch(() => false))) {
        const back = page.getByTestId('pos-browse-back');
        if (await back.isVisible({ timeout: 1500 }).catch(() => false)) {
          await back.click();
        }
      }
      await expect(grid).toBeVisible({ timeout: 20_000 });
      const tile = grid.getByTestId('pos-category-tile').filter({ hasText: nameRe }).first();
      await expect(tile).toBeVisible({ timeout: 20_000 });
      await tile.click();
      await page.waitForTimeout(1200);
    };

    // ── B01 : login POS OK ────────────────────────────────────────────────
    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
    // Shell POS chargé = hub catégories (POS category-first) + panneau ticket.
    await expect(grid).toBeVisible({ timeout: 30_000 });
    await rec.snap('B01-login-pos');

    // ── B02 : grille home (vignettes catégories) ─────────────────────────
    const tiles = grid.getByTestId('pos-category-tile');
    expect(await tiles.count(), 'au moins 4 tuiles catégorie attendues sur le hub').toBeGreaterThanOrEqual(4);
    // Vignettes : les tuiles Menu enfant et Bols portent une image (pas le fallback lettre).
    await expect(
      tiles.filter({ hasText: /menu enfant/i }).first().locator('img'),
      'tuile Menu enfant sans vignette image'
    ).toBeVisible({ timeout: 10_000 });
    await expect(
      tiles.filter({ hasText: /bols/i }).first().locator('img'),
      'tuile Bols sans vignette image'
    ).toBeVisible({ timeout: 10_000 });
    // Amener la grille de vignettes dans le viewport pour la capture.
    await grid.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await rec.snap('B02-grille-categories');

    // ── B03 : grille menu-enfant ─────────────────────────────────────────
    await openCategory(/menu enfant/i);
    const nuggetsImg = page.locator('img[src*="nuggets"]').first();
    await expect(nuggetsImg).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText('Menu Enfant Chicken Burger', { exact: true })).toBeVisible({ timeout: 10_000 });
    // Intégrité prix grille : tuile Nuggets affiche 4,90.
    const nuggetsTilePrice = page.locator(`[data-pos-item-id="${ID_NUGGETS}"] .pos-v5-tile__price`).first();
    await expect(nuggetsTilePrice, 'prix grille Nuggets ≠ 4,90').toHaveText(/4,90/, { timeout: 10_000 });
    await rec.snap('B03-grille-menu-enfant');

    // ── B04 : popup Nuggets — chips 12 sauces + « 1ère gratuite » ────────
    await nuggetsImg.click();
    await expect(modal).toBeVisible({ timeout: 10_000 });
    // Le wizard single-page (shim frozen pos-wizard.js) rend les chips après le XHR item/details.
    await expect(modal.locator('.sauce-chip'), 'chips sauces ≠ 12').toHaveCount(12, { timeout: 20_000 });
    await expect(
      modal.locator('.sauce-badge').filter({ hasText: /1ère gratuite/i }).first(),
      'badge « 1ère gratuite » absent'
    ).toBeVisible({ timeout: 5000 });
    // Intégrité prix wizard : total sticky à 4,90 avant toute sélection.
    // NB anomalie visuelle (frozen pos-wizard.js fmtPrice) : rend « €4.90 » (US)
    // là où la grille rend « 4,90 € » (FR) — accepté ici, reporté au run.
    await expect(modal.locator('.sticky-total .total-value').first()).toHaveText(/4,90|4\.90/, { timeout: 5000 });
    await waitModalPainted();
    await rec.snap('B04-popup-nuggets-sauces');

    // ── B05 : sauce choisie → Ajouter au panier → ligne ticket 4,90 ──────
    const mayoChip = modal.locator('.sauce-chip').filter({ hasText: /mayonnaise/i }).first();
    await expect(mayoChip).toBeVisible({ timeout: 5000 });
    await mayoChip.click();
    await expect(mayoChip, 'chip sauce non sélectionnée après clic').toHaveClass(/selected/, { timeout: 5000 });
    // 1ère sauce = gratuite → le total reste 4,90.
    await expect(modal.locator('.sticky-total .total-value').first()).toHaveText(/4,90|4\.90/, { timeout: 5000 });
    const addToCart = modal.locator('button[data-action="add-to-cart"]').first();
    await expect(addToCart).toBeVisible({ timeout: 5000 });
    await addToCart.click();
    await expect(modal).toBeHidden({ timeout: 10_000 });
    // Ligne ticket : nom Nuggets + prix ligne 4,90 ; grand total 4,90. AUCUN encaissement.
    const cartLine = page
      .locator('.pos-v5-cart-item__name')
      .filter({ hasText: /nuggets/i })
      .first();
    await expect(cartLine, 'ligne ticket Nuggets absente').toBeVisible({ timeout: 10_000 });
    const linePrice = page
      .locator('article')
      .filter({ has: page.locator('.pos-v5-cart-item__name', { hasText: /nuggets/i }) })
      .locator('.pos-v5-cart-item__price')
      .first();
    await expect(linePrice, 'prix ligne ticket ≠ 4,90').toHaveText(/4,90/, { timeout: 5000 });
    await expect(page.getByTestId('pos-grand-total'), 'grand total ≠ 4,90').toContainText('4,90', { timeout: 5000 });
    // Rendre la ligne visible dans la fenêtre du panneau ticket pour la capture,
    // et mesurer sa fenêtre visible (zone items compressée par le footer à 900px ?).
    await cartLine.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    const lineBox = await cartLine.boundingBox();
    console.log('[waveB] cart line boundingBox:', JSON.stringify(lineBox));
    await rec.snap('B05-ticket-nuggets-4-90');

    // ── B06 : popup kids-burger — crudités cochées ───────────────────────
    // (l'ajout panier auto-renvoie au hub catégories → on re-rentre dans Menu enfant)
    await openCategory(/menu enfant/i);
    await page.getByText('Menu Enfant Chicken Burger', { exact: true }).first().click();
    await expect(modal).toBeVisible({ timeout: 10_000 });
    const crudites = modal.locator('.crudites-section');
    await expect(crudites, 'section Crudités absente du wizard kids-burger').toBeVisible({ timeout: 20_000 });
    const included = crudites.locator('.garniture-toggle-btn.included');
    expect(await included.count(), 'aucune crudité cochée par défaut').toBeGreaterThanOrEqual(2);
    await expect(crudites.locator('.garniture-toggle-btn.included').filter({ hasText: /salade/i }).first()).toBeVisible();
    await expect(crudites.locator('.garniture-toggle-btn.included').filter({ hasText: /tomate/i }).first()).toBeVisible();
    await waitModalPainted();
    await rec.snap('B06-popup-kidsburger-crudites');

    // ── B07 : accordéon « + Suppléments » DÉPLIÉ — liste @0,90 ───────────
    const supplToggle = modal.locator('.suppl-toggle').first();
    await expect(supplToggle, 'accordéon « + Suppléments » absent').toBeVisible({ timeout: 10_000 });
    await expect(supplToggle).toHaveText(/suppléments/i);
    await supplToggle.scrollIntoViewIfNeeded();
    await supplToggle.click();
    const supplPanel = modal.locator('.suppl-panel').first();
    await expect(supplPanel, 'panel suppléments toujours replié après clic').not.toHaveClass(/collapsed/, { timeout: 5000 });
    for (const re of [/cheddar/i, /raclette/i, /emmental/i, /œuf|oeuf/i]) {
      const opt = supplPanel.locator('.supplement-opt').filter({ hasText: re }).first();
      await expect(opt, `supplément ${re} absent de la liste dépliée`).toBeVisible({ timeout: 5000 });
    }
    // Prix suppléments @0,90 visibles (Cheddar & co).
    const cheddarPrice = supplPanel
      .locator('.supplement-opt')
      .filter({ hasText: /cheddar/i })
      .first()
      .locator('.option-price');
    await expect(cheddarPrice, 'prix supplément Cheddar ≠ 0,90').toHaveText(/0[.,]90/, { timeout: 5000 });
    await supplPanel.scrollIntoViewIfNeeded();
    await rec.snap('B07-popup-kidsburger-supplements-0-90');
    await closeModal();

    // ── B08 : modal Bol Frites — « Option Gratiné » @2,00 dans les extras ─
    await openCategory(/bols/i);
    const bolTile = page.locator(`[data-pos-item-id="${ID_BOL_FRITES}"]`).first();
    await expect(bolTile, 'tuile Bol Frites absente de la grille Bols').toBeVisible({ timeout: 20_000 });
    await bolTile.click();
    await expect(modal).toBeVisible({ timeout: 10_000 });
    // Attendre le rendu wizard (chips/CTA) avant de chercher le gratiné.
    await expect(modal.locator('button[data-action="add-to-cart"], .wizard-btn-cart').first()).toBeVisible({ timeout: 20_000 });
    let gratine = modal.locator('.supplement-opt').filter({ hasText: /gratin/i }).first();
    if (!(await gratine.isVisible({ timeout: 2500 }).catch(() => false))) {
      // Le gratiné vit dans l'accordéon suppléments → le déplier.
      const bolSupplToggle = modal.locator('.suppl-toggle').first();
      await expect(bolSupplToggle, 'ni option gratiné visible, ni accordéon suppléments').toBeVisible({ timeout: 10_000 });
      await bolSupplToggle.scrollIntoViewIfNeeded();
      await bolSupplToggle.click();
      gratine = modal.locator('.supplement-opt').filter({ hasText: /gratin/i }).first();
    }
    await expect(gratine, '« Option Gratiné » absente des extras du modal Bol Frites').toBeVisible({ timeout: 8000 });
    await expect(gratine.locator('.option-price'), 'prix gratiné ≠ 2,00').toHaveText(/2[.,]00/, { timeout: 5000 });
    await waitModalPainted();
    // block:center — sinon la tuile atterrit sous la sticky bar (prix masqué à la capture).
    await gratine.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    await rec.snap('B08-popup-bolfrites-gratine-2-00');
    await closeModal();

    // ── Cleanup : vider le panier (1 ligne Nuggets) — pas de paiement ─────
    const cancelLast = page.getByTestId('pos-cancel-last-line');
    if (await cancelLast.isVisible({ timeout: 3000 }).catch(() => false)) {
      for (let i = 0; i < 5; i++) {
        if (!(await cancelLast.isVisible({ timeout: 1000 }).catch(() => false))) break;
        await cancelLast.click().catch(() => {});
        await page.waitForTimeout(600);
      }
    }
    await expect(cancelLast, 'panier non vidé en fin de parcours').toBeHidden({ timeout: 5000 });

    rec.dispose();
  });

  // Intégrité API (PLAN Wave B : « 4,90 ticket = grille = API ; 0,90 suppléments ;
  // 2,00 gratiné ») — même projection NormalItemResource que la caisse (surface pos).
  test('API — prix 4,90 Nuggets / 0,90 suppléments kids / 2,00 gratiné Bol Frites', async ({ request }) => {
    const env = fs.readFileSync(path.resolve(__dirname, '../../.env'), 'utf8');
    const key = (env.match(/^(?:MIX_)?API_KEY=(.+)$/m) || [])[1];
    expect(key, 'API key introuvable dans .env').toBeTruthy();
    const get = async (id) => {
      const r = await request.get(`/api/frontend/item/details/${id}`, {
        headers: { 'x-api-key': key.trim(), Accept: 'application/json' },
      });
      expect(r.ok(), `GET item ${id} → ${r.status()}`).toBe(true);
      return (await r.json()).data;
    };

    const nuggets = await get(ID_NUGGETS);
    expect(parseFloat(nuggets.convert_price), 'prix API Nuggets ≠ 4,90').toBeCloseTo(4.9, 2);

    const kids = await get(ID_KIDS_BURGER);
    const cheddar = (kids.extras || []).filter((e) => /cheddar/i.test(e.name));
    expect(cheddar.length, 'supplément Cheddar absent du payload kids-burger').toBeGreaterThanOrEqual(1);
    expect(parseFloat(cheddar[0].convert_price), 'prix API Cheddar ≠ 0,90').toBeCloseTo(0.9, 2);

    const bol = await get(ID_BOL_FRITES);
    const gratine = (bol.extras || []).filter((e) => /gratin/i.test(e.name));
    expect(gratine, 'Option Gratiné absente du payload Bol Frites').toHaveLength(1);
    expect(parseFloat(gratine[0].convert_price ?? gratine[0].price), 'prix API gratiné ≠ 2,00').toBeCloseTo(2.0, 2);
  });
});
