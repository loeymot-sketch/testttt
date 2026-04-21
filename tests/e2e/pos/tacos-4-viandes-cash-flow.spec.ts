/**
 * E2E partiel T22 — Catalogue → Tacos (4 viandes mix 3+1) → extra → caisse (espèces, sans retry) → reçu.
 *
 * Prérequis data : item type tacos M avec attribut viandes min/max adaptés, extras (ex. cheddar), branche avec identité fiscale (SIRET/TVA) pour le pied de reçu.
 *
 * Sélecteurs catalogue / viandes / extras : surtout via REGEX configurables (env ci-dessous) car les libellés dépendent du seed.
 *
 * TODO data-testid : `pos-cart-total` n’existe pas — le total est lu via `#pos-cart` (ligne Total). Voir T18 / tâche hooks test.
 */
import { test, expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';

/** Regex libellé item catalogue (ex. Tacos M) */
const ITEM_CATALOG_RE = new RegExp(process.env.E2E_POS_TACOS_ITEM_RE || 'tacos', 'i');
/** Viande A — cliquer 3× sur + */
const MEAT_A_RE = new RegExp(process.env.E2E_POS_MEAT_A_RE || 'steak|bœuf|boeuf', 'i');
/** Viande B — cliquer 1× sur + */
const MEAT_B_RE = new RegExp(process.env.E2E_POS_MEAT_B_RE || 'poulet|chicken', 'i');
/** Extra — 1× + */
const EXTRA_RE = new RegExp(process.env.E2E_POS_EXTRA_RE || 'cheddar', 'i');

/**
 * Même contrat que `tests/e2e/helpers/login.js` : `/login`, `#formEmail`, `#formPassword`.
 */
async function loginAsPOS(page: Page) {
  await page.goto('/login');
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
  await page.locator('#formEmail').fill(POS_EMAIL);
  await page.locator('#formPassword').fill(POS_PASSWORD);
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 25_000 });
}

/**
 * Ligne variation « multi » (compteurs +/-) dans le modal, identifiée par le nom affiché (h3).
 */
function variationQuantityRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal div.flex.items-center.gap-3.rounded-lg.border')
    .filter({ has: page.locator('h3').filter({ hasText: nameRe }) });
}

function extraQuantityRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal div.flex.items-center.gap-3.rounded-lg.border')
    .filter({ has: page.locator('h3').filter({ hasText: nameRe }) });
}

async function clickPlusInRow(row: Locator, times: number) {
  const plus = row.locator('button.indec-plus');
  for (let i = 0; i < times; i++) {
    await plus.click();
  }
}

/**
 * Total panier affiché (dernière ligne « Total » dans #pos-cart).
 * Pas de data-testid `pos-cart-total` — sélecteur structurel documenté pour ops.
 */
function posCartTotalLocator(page: Page) {
  return page.locator('#pos-cart li').filter({ hasText: /^Total\b/i }).locator('span.text-primary').first();
}

function parseAmount(text: string): number {
  const normalized = text.replace(/\s/g, '').replace(/[^\d.,-]/g, '').replace(',', '.');
  const n = parseFloat(normalized);
  return Number.isFinite(n) ? n : NaN;
}

test.describe('POS — Tacos 4 viandes mix → espèces → reçu (T22 partiel)', () => {
  test.describe.configure({ timeout: 120_000 });

  test.beforeEach(async ({ page }) => {
    const shotDir = path.join(process.cwd(), 'reports', 'e2e');
    fs.mkdirSync(shotDir, { recursive: true });
    await loginAsPOS(page);
  });

  test('flux: tacos + viandes 3+1 + extra + paiement espèces + composition reçu', async ({ page }) => {
    await page.waitForTimeout(2_000);

    // 1. Catalogue — tuile item (data-modal du grid produit)
    const itemTile = page.locator('div[data-modal="#item-variation-modal"]').filter({ hasText: ITEM_CATALOG_RE }).first();
    await expect(itemTile, 'Aucune tuile catalogue ne correspond à E2E_POS_TACOS_ITEM_RE — adapter le seed ou l’env').toBeVisible({
      timeout: 15_000,
    });
    await itemTile.click();

    await expect(page.locator('#item-variation-modal')).toBeVisible({ timeout: 10_000 });

    // 2. Viandes 3× A + 1× B (compteurs +)
    const rowA = variationQuantityRow(page, MEAT_A_RE);
    const rowB = variationQuantityRow(page, MEAT_B_RE);
    await expect(rowA, 'Viande A introuvable — adapter E2E_POS_MEAT_A_RE / seed').toBeVisible({ timeout: 5_000 });
    await expect(rowB, 'Viande B introuvable — adapter E2E_POS_MEAT_B_RE / seed').toBeVisible({ timeout: 5_000 });
    await clickPlusInRow(rowA, 3);
    await clickPlusInRow(rowB, 1);

    // 3. Extra (section extras : même pattern de ligne)
    const extraRow = extraQuantityRow(page, EXTRA_RE);
    await expect(extraRow, 'Extra introuvable — adapter E2E_POS_EXTRA_RE / seed').toBeVisible({ timeout: 5_000 });
    await clickPlusInRow(extraRow, 1);

    // 4. Ajouter au panier (libellé i18n : fr « Ajouter au panier », en « Add to Cart », etc.)
    await page.getByRole('button', { name: /ajouter au panier|add to cart/i }).click();
    await expect(page.locator('#item-variation-modal')).toBeHidden({ timeout: 10_000 });

    // 5. Panier — ligne produit + extra
    await expect(page.locator('#pos-cart').getByText(ITEM_CATALOG_RE).first()).toBeVisible();
    await expect(page.locator('#pos-cart').getByText(EXTRA_RE).first()).toBeVisible();

    const totalEl = posCartTotalLocator(page);
    await expect(totalEl).toBeVisible();
    const totalNumber = parseAmount(await totalEl.innerText());
    expect(totalNumber, 'Total panier illisible').toBeGreaterThan(0);

    // 6. Commande → paiement
    await page.getByRole('button', { name: /^(commande|order)$/i }).click();
    await expect(page.locator('#orderpayment')).toBeVisible({ timeout: 10_000 });

    await page.locator('[data-tab="#cash"]').click();
    await expect(page.locator('#cashInput')).toBeVisible();
    const received = String(Math.ceil(totalNumber + 5));
    await page.locator('#cashInput').fill(received);

    await page.getByRole('button', { name: /confirmer|confirm/i }).click();

    // 7. Reçu — composition + pied fiscal (si seed branch fiscal)
    const receipt = page.locator('#receiptModal');
    await expect(receipt).toBeVisible({ timeout: 20_000 });

    const receiptBody = receipt.locator('#print');
    // Ligne variations : « 3× … » et « 1× … » (ReceiptComponent + normalizeReceiptVariations)
    await expect(receiptBody).toContainText(/3\s*×/);
    await expect(receiptBody).toContainText(/1\s*×/);
    await expect(receiptBody.getByText(MEAT_A_RE).first()).toBeVisible();
    await expect(receiptBody.getByText(MEAT_B_RE).first()).toBeVisible();

    await expect(receiptBody.getByText(/SIRET|TVA/i).first()).toBeVisible();

    await page.screenshot({ path: 'reports/e2e/pos-tacos-4-viandes-cash-end.png', fullPage: true });
  });
});
