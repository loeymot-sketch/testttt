// FoodKing E2E — Borne audit cats 309–318 — Wave D : direct-add simples (316/317/318)
//
// Audit run-id : borne-cats-309-318-2026-05-10
// Plan source  : reports/test-e2e/borne-cats-309-318-2026-05-10/AUDIT_PLAN.md §Wave D
// Branch       : feature/mobile-app-le-cayenne-2026-05-10
//
// Mission de cette wave (Phase A behavior in `KioskCategoriesComponent.hasOptions`) :
//   - Cat 318 (Suppléments) : `hasOptions` short-circuit `false` (catId=318 ou catName
//     starts-with 'supplement'), donc tap → addItem direct, AUCUN wizard overlay.
//   - Cats 316 (Desserts) / 317 (Boissons) : aucun itemAttributes/extras/variations
//     ni `has_menu`, le check `addons.length>0` a été retiré (V3.5 2026-05-10),
//     donc fallback `hasOptions` retourne `false` → addItem direct également.
//   - Si un wizard apparaît pour un de ces 3 cats → régression Phase A (P1 minimum).
//
// Bottom-sheet target : 280×80 px (horizontal compact V3, NOT vertical 364px V2).
//
// Capture : 10 states via attachMegaAuditRecorder (PNG + DOM + console + network),
// stockés dans tests/e2e/__screenshots__/test-e2e-borne-D/.
//
// Acceptance assertions critiques :
//   - States 02 / 05 / 08 : wizard overlay invisible APRÈS confirmation cart count++.
//     Le code fait `await dispatch('frontendItem/details')` avant `hasOptions(detail)`,
//     donc on attend l'incrément cart pour prouver le chemin direct-add a été emprunté
//     avant d'asserter wizard absent (sinon race async = faux vert).
//   - State 03 : 3 desserts en panier, total = 3 × 3.80 = 11.40€
//   - State 06 : Coca line à qty=3 via stepper +, line total = 3 × 1.50 = 4.50€
//   - State 09 : `kiosk-cart-bottom-sheet-item-0`.boundingBox() ≈ 280×80 (±15px)
//   - State 10 : cart total === Σ(items × qty) ; mix attendu :
//                3×3.80 + 3×1.50 + 1×1.00 (Fromage suppl) = 11.40 + 4.50 + 1.00 = 16.90€

const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-borne-D');

// IDs vérifiés en DB (19 items actifs cats 316/317/318) :
const ITEM_DESSERTS = [
  { id: 404, name: 'Glace', price: 3.80 },
  { id: 405, name: 'Tarte Daim', price: 3.80 },
  { id: 406, name: 'Tiramisu', price: 3.80 },
];
const ITEM_COCA_33CL = { id: 407, name: 'Coca-Cola 33cl', price: 1.50 };
const ITEM_FROMAGE_SUPPL = { id: 416, name: 'Fromage supplémentaire', price: 1.00 };

const CAT_DESSERTS = 316;
const CAT_BOISSONS = 317;
const CAT_SUPPLEMENTS = 318;

const WIZARD_OVERLAY_SELECTOR = '.kiosk-wizard-overlay';
const CART_INDICATOR_TESTID = '[data-testid="kiosk-categories-cart-indicator"]';
const CART_TOTAL_TESTID = '[data-testid="kiosk-categories-cart-total"]';
const SHEET_OUTER_TESTID = '[data-testid="kiosk-cart-bottom-sheet"]';
const SHEET_ITEM_PREFIX = '[data-testid^="kiosk-cart-bottom-sheet-item-"]';

/**
 * Click sur la sidebar pour basculer la catégorie. Vérifie que la zone produits
 * affiche bien le bon test-id grid après bascule.
 */
async function selectCategory(page, categoryId) {
  const sidebarBtn = page.locator(`[data-testid="kiosk-categories-sidebar-item-${categoryId}"]`).first();
  await expect(sidebarBtn).toBeVisible({ timeout: 10_000 });
  await sidebarBtn.click({ timeout: 5_000 });
  // Petit délai pour laisser le store kioskMenu enregistrer la sélection + transition CSS
  await page.waitForTimeout(700);
}

/**
 * Lit le compteur d'items (cart count) depuis l'indicator du bandeau bas. Robuste :
 * extrait le nombre du texte (peut contenir « 1 article » ou « 3 articles »).
 */
async function readCartCount(page) {
  const txt = await page.locator(CART_INDICATOR_TESTID).innerText({ timeout: 5_000 });
  const m = txt.match(/(\d+)/);
  return m ? parseInt(m[1], 10) : 0;
}

/**
 * Lit le total panier (kiosk-categories-cart-total). Le texte est ex « 11.40 € »
 * ou « 11,40€ » selon locale → on parse les deux formes.
 */
async function readCartTotal(page) {
  const txt = await page.locator(CART_TOTAL_TESTID).innerText({ timeout: 5_000 });
  const cleaned = txt.replace(/[^\d.,-]/g, '').replace(',', '.');
  return parseFloat(cleaned);
}

/**
 * Tape sur la card produit (non sur le bouton + flottant pour rester proche de
 * l'expérience tactile borne — owner spec « tap on card »). Le composant écoute
 * @click sur la card ET sur le bouton + (deux event handlers identiques), donc
 * cliquer la card est équivalent business mais reflète mieux l'UX réelle.
 *
 * Attention : `openProduct` fait un await dispatch('frontendItem/details') AVANT
 * de décider direct-add vs wizard. Si on assert wizard absent immédiatement on
 * teste avant la décision → faux green. On attend cart count++ comme preuve
 * que le chemin direct-add a été emprunté, PUIS on asserte overlay invisible.
 */
async function tapProductCardAndExpectDirectAdd(page, productId, expectedCountAfter) {
  const card = page.locator(`[data-testid="kiosk-product-card-${productId}"]`).first();
  await expect(card).toBeVisible({ timeout: 10_000 });
  await card.click({ timeout: 5_000 });

  // Attendre l'incrément cart count (preuve que addItem a été appelé)
  await expect.poll(
    async () => readCartCount(page),
    { timeout: 10_000, message: `cart count should reach ${expectedCountAfter} after tap` },
  ).toBe(expectedCountAfter);

  // MAINTENANT (et seulement maintenant) on peut asserter que le wizard NE s'est PAS ouvert.
  await expect(page.locator(WIZARD_OVERLAY_SELECTOR)).not.toBeVisible({ timeout: 1_000 });
}

/**
 * Setup : bascule kiosk vers /kiosk/categories en passant par /kiosk/idle puis
 * choix « À emporter » (V1 dine-in disabled). Tolérant à l'auto-login borne.
 */
async function bootstrapKioskToCategories(page) {
  await loginAsKiosk(page);
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1_500);

  if (/\/kiosk\/login/i.test(page.url())) {
    test.fixme(true, 'Kiosk machine not configured in DB — adversarial Wave D requires kiosk-lecayenne seed');
  }

  // Sélection FR par défaut (kiosk-idle-lang-fr) — tolérant si déjà actif.
  const langFr = page.locator('[data-testid="kiosk-idle-lang-fr"]').first();
  if (await langFr.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await langFr.click({ timeout: 3_000 }).catch(() => {});
    await page.waitForTimeout(300);
  }

  // Choix « À emporter ». Si déjà sur categories (state-machine fast-path) skip.
  if (!/\/kiosk\/categories/i.test(page.url())) {
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
    await expect(takeaway).toBeVisible({ timeout: 15_000 });
    await takeaway.click({ timeout: 5_000 });
    await page.waitForURL(/\/kiosk\/(categories|menu)/, { timeout: 15_000 });
  }
  await page.waitForTimeout(1_500);

  // Sanity : sidebar visible
  await expect(page.locator('[data-testid="kiosk-categories-sidebar"]').first())
    .toBeVisible({ timeout: 10_000 });
}

test.describe('Borne audit cats 309–318 — Wave D : direct-add simples (316/317/318)', () => {
  test.setTimeout(180_000);

  test('Wave D — round 1 capture (10 states)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 }); // borne portrait standard

    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    try {
      // ---------------------------------------------------------------------
      // Bootstrap : login kiosk → idle → takeaway → categories
      // ---------------------------------------------------------------------
      await bootstrapKioskToCategories(page);

      // ---------------------------------------------------------------------
      // STATE 01 : cat 316 baseline (cart vide, 3 desserts visibles)
      // ---------------------------------------------------------------------
      await selectCategory(page, CAT_DESSERTS);
      await expect(page.locator(`[data-testid="kiosk-product-card-${ITEM_DESSERTS[0].id}"]`))
        .toBeVisible({ timeout: 10_000 });
      // Cart vide → bottom-sheet PAS rendu (v-if="items.length > 0")
      await expect(page.locator(SHEET_OUTER_TESTID)).not.toBeVisible({ timeout: 500 });
      await expect.poll(() => readCartCount(page), { timeout: 5_000 }).toBe(0);
      await snap('01-d-cat-316-baseline');

      // ---------------------------------------------------------------------
      // STATE 02 : tap Glace → direct-add, NO wizard
      // ---------------------------------------------------------------------
      await tapProductCardAndExpectDirectAdd(page, ITEM_DESSERTS[0].id, 1);
      await snap('02-d-316-tap-glace-direct-add');

      // ---------------------------------------------------------------------
      // STATE 03 : 3 desserts en panier (Glace + Tarte Daim + Tiramisu)
      // total = 3 × 3.80 = 11.40€
      // ---------------------------------------------------------------------
      await tapProductCardAndExpectDirectAdd(page, ITEM_DESSERTS[1].id, 2);
      await tapProductCardAndExpectDirectAdd(page, ITEM_DESSERTS[2].id, 3);

      const totalAfter3Desserts = await readCartTotal(page);
      expect(totalAfter3Desserts).toBeCloseTo(3 * 3.80, 2);
      await snap('03-d-316-cart-after-3-desserts');

      // ---------------------------------------------------------------------
      // STATE 04 : cat 317 baseline — 8 boissons grid
      // ---------------------------------------------------------------------
      await selectCategory(page, CAT_BOISSONS);
      await expect(page.locator(`[data-testid="kiosk-product-card-${ITEM_COCA_33CL.id}"]`))
        .toBeVisible({ timeout: 10_000 });
      // Sheet déjà rendu (cart non vide depuis state 03)
      await expect(page.locator(SHEET_OUTER_TESTID)).toBeVisible({ timeout: 5_000 });
      await snap('04-d-cat-317-baseline');

      // ---------------------------------------------------------------------
      // STATE 05 : tap Coca → direct-add (cart count : 3 → 4)
      // ---------------------------------------------------------------------
      await tapProductCardAndExpectDirectAdd(page, ITEM_COCA_33CL.id, 4);
      await snap('05-d-317-tap-coca-direct-add');

      // ---------------------------------------------------------------------
      // STATE 06 : same Coca line à qty 3 via stepper +.
      // Important : multi-tap card créerait des lignes séparées (chaque addItem
      // ajoute une nouvelle line dans buildSimpleCartItem). On utilise le stepper
      // de la bottom-sheet pour incrémenter la quantité de la ligne Coca existante.
      // ---------------------------------------------------------------------
      // La sheet liste les items dans l'ordre où ils ont été ajoutés. La ligne Coca
      // est l'index 3 (après 3 desserts). On localise par item_id pour robustesse.
      const sheetItems = page.locator(SHEET_ITEM_PREFIX);
      const sheetCount = await sheetItems.count();
      expect(sheetCount).toBe(4);

      // Lookup index Coca via DOM : chaque card affiche le nom tronqué
      // (truncate(name, 22)) — on filtre par texte. Coca-Cola 33cl rentre.
      const cocaItem = sheetItems.filter({ hasText: 'Coca' }).first();
      await expect(cocaItem).toBeVisible({ timeout: 5_000 });

      // Récupérer l'idx réel du DOM pour cliquer le bon increment button.
      const cocaIdx = await cocaItem.evaluate((el) => {
        const tid = el.getAttribute('data-testid') || '';
        const m = tid.match(/-(\d+)$/);
        return m ? parseInt(m[1], 10) : -1;
      });
      expect(cocaIdx).toBeGreaterThanOrEqual(0);

      const incrementBtn = page.locator(`[data-testid="kiosk-cart-bottom-sheet-increment-${cocaIdx}"]`);
      await expect(incrementBtn).toBeVisible({ timeout: 5_000 });
      // qty=1 → 2 → 3 (deux clicks)
      await incrementBtn.click({ timeout: 3_000 });
      await page.waitForTimeout(250);
      await incrementBtn.click({ timeout: 3_000 });
      await page.waitForTimeout(400);

      // Verify qty display = 3 et line total = 3×1.50 = 4.50
      const qtyTxt = await page.locator(`[data-testid="kiosk-cart-bottom-sheet-qty-${cocaIdx}"]`)
        .innerText({ timeout: 3_000 });
      expect(qtyTxt.trim()).toBe('3');

      // Cart total maintenant : 3×3.80 + 3×1.50 = 11.40 + 4.50 = 15.90
      const totalAfterCocaX3 = await readCartTotal(page);
      expect(totalAfterCocaX3).toBeCloseTo(11.40 + 4.50, 2);

      // Cart count est exposé par count() store getter qui somme les quantités —
      // 3 desserts (1 chacun) + 3 cocas = 6.
      const countAfterCocaX3 = await readCartCount(page);
      expect(countAfterCocaX3).toBe(6);
      await snap('06-d-317-multi-add-coca-x3');

      // ---------------------------------------------------------------------
      // STATE 07 : cat 318 baseline (8 suppléments)
      // ---------------------------------------------------------------------
      await selectCategory(page, CAT_SUPPLEMENTS);
      await expect(page.locator(`[data-testid="kiosk-product-card-${ITEM_FROMAGE_SUPPL.id}"]`))
        .toBeVisible({ timeout: 10_000 });
      await snap('07-d-cat-318-baseline');

      // ---------------------------------------------------------------------
      // STATE 08 : tap Fromage supplémentaire → direct-add (cat 318 spec critical :
      // hasOptions short-circuit cat=318). cart count : 6 → 7
      // ---------------------------------------------------------------------
      await tapProductCardAndExpectDirectAdd(page, ITEM_FROMAGE_SUPPL.id, 7);
      await snap('08-d-318-tap-fromage-direct-add');

      // ---------------------------------------------------------------------
      // STATE 09 : bottom-sheet visible — measure first card 280×80px (±15px tolerance)
      // ---------------------------------------------------------------------
      await expect(page.locator(SHEET_OUTER_TESTID)).toBeVisible({ timeout: 5_000 });
      const firstSheetCard = page.locator(SHEET_ITEM_PREFIX).first();
      await expect(firstSheetCard).toBeVisible({ timeout: 5_000 });

      const bbox = await firstSheetCard.boundingBox();
      expect(bbox).not.toBeNull();
      const TARGET_W = 280;
      const TARGET_H = 80;
      const TOL = 15;
      // eslint-disable-next-line no-console
      console.log(`[wave-D state 09] sheet-card-0 boundingBox: ${JSON.stringify(bbox)}`);
      expect(Math.abs(bbox.width - TARGET_W)).toBeLessThanOrEqual(TOL);
      expect(Math.abs(bbox.height - TARGET_H)).toBeLessThanOrEqual(TOL);
      await snap('09-d-bottom-sheet-after-mixed-cart');

      // ---------------------------------------------------------------------
      // STATE 10 : final mixed cart total === Σ(items × qty)
      // Mix attendu : 3 desserts × 3.80 + 3 Coca × 1.50 + 1 Fromage × 1.00 = 16.90€
      // ---------------------------------------------------------------------
      const finalTotal = await readCartTotal(page);
      const expectedTotal = 3 * 3.80 + 3 * 1.50 + 1 * 1.00; // 16.90
      expect(finalTotal).toBeCloseTo(expectedTotal, 2);

      const finalCount = await readCartCount(page);
      // Σ qty = 3 + 3 + 1 = 7 (cart count agrège quantities)
      expect(finalCount).toBe(7);

      // Snap final
      await snap('10-d-cart-totals-mixed');
    } finally {
      dispose();
    }
  });
});
