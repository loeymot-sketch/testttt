/**
 * E2E partiel T22 — Catalogue → Tacos → extra → caisse (espèces, sans retry) → reçu.
 * Plan 10 phases — **Phase 10** ; E2E **GATE** opt-in (`workflows/qa-loop.md`) : compte POS
 * + seed alignés, voir `E2E_POS_USER` / `E2E_POS_PASS` (défauts souvent absents en local).
 *
 * Prérequis data : item type tacos avec au moins deux lignes de sélection et un extra, branche avec identité fiscale (SIRET/TVA) pour le pied de reçu.
 *
 * Sélecteurs catalogue / viandes / extras : surtout via REGEX configurables (env ci-dessous) car les libellés dépendent du seed.
 *
 * TODO data-testid : `pos-cart-total` n’existe pas — le total est lu via `#pos-cart` (ligne Total). Voir T18 / tâche hooks test.
 */
import { test, expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// eslint-disable-next-line @typescript-eslint/no-var-requires
const { clearFoodKingRateLimits } = require('../helpers/rate-limit');

const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';

/** Regex libellé item catalogue (ex. Tacos M) */
const ITEM_CATALOG_RE = new RegExp(process.env.E2E_POS_TACOS_ITEM_RE || 'tacos\\s*l|tacos.*2', 'i');
/** Sélection A — défaut aligné sur le seed local courant, surchargeable en CI. */
const MEAT_A_RE = new RegExp(process.env.E2E_POS_MEAT_A_RE || 'merguez|jambon|dinde|steak|bœuf|boeuf', 'i');
/** Sélection B — défaut aligné sur le seed local courant, surchargeable en CI. */
const MEAT_B_RE = new RegExp(process.env.E2E_POS_MEAT_B_RE || 'kefta|poulet|chicken|fromage', 'i');
/** Extra — 1× + */
const EXTRA_RE = new RegExp(process.env.E2E_POS_EXTRA_RE || 'ketchup|sauce supplément|cheddar|fromage', 'i');
const MEAT_A_QTY = parsePositiveInt(process.env.E2E_POS_MEAT_A_QTY, 1);
const MEAT_B_QTY = parsePositiveInt(process.env.E2E_POS_MEAT_B_QTY, 1);

function parsePositiveInt(value: string | undefined, fallback: number): number {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

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

function wizardViandeRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal .wizard-viande-row')
    .filter({ has: page.locator('.viande-name').filter({ hasText: nameRe }) });
}

function extraQuantityRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal div.flex.items-center.gap-3.rounded-lg.border')
    .filter({ has: page.locator('h3').filter({ hasText: nameRe }) });
}

function productTile(page: Page) {
  return page
    .locator('[data-modal="#item-variation-modal"]')
    .filter({ has: page.locator('h3').filter({ hasText: ITEM_CATALOG_RE }) })
    .first();
}

async function closeBlockingPosPanels(page: Page) {
  const kioskCashClose = page.locator('.kiosk-cash-panel-close').first();
  if (await kioskCashClose.isVisible({ timeout: 500 }).catch(() => false)) {
    await kioskCashClose.click();
  }
}

async function openTacosProductModal(page: Page) {
  for (let attempt = 1; attempt <= 2; attempt++) {
    await closeBlockingPosPanels(page);

    let tile = productTile(page);
    if (!await tile.isVisible({ timeout: 10_000 }).catch(() => false)) {
      const tacosCategory = page
        .getByRole('button', { name: /category\s+Nos Tacos|Nos Tacos/i })
        .first();

      if (await tacosCategory.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await tacosCategory.click();
        tile = productTile(page);
      }
    }

    if (!await tile.isVisible({ timeout: 5_000 }).catch(() => false)) {
      if (attempt === 1) {
        await page.reload();
        await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 20_000 });
        continue;
      }

      await expect(tile, 'Aucune tuile catalogue ne correspond à E2E_POS_TACOS_ITEM_RE — adapter le seed ou l’env').toBeVisible({
        timeout: 15_000,
      });
    }

    await tile.scrollIntoViewIfNeeded();

    await Promise.all([
      page.waitForResponse((response) => (
        response.status() === 200 && /\/api\/admin\/item\/details\/\d+/.test(response.url())
      ), { timeout: 15_000 }).catch(() => null),
      tile.click(),
    ]);

    const modal = page.locator('#item-variation-modal');
    if (await modal.evaluate((el) => el.classList.contains('active')).catch(() => false)) {
      await expect(modal).toBeVisible({ timeout: 10_000 });
      return;
    }

    if (attempt === 1) {
      await page.reload();
      await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 20_000 });
      continue;
    }

    await expect(modal).toHaveClass(/active/, { timeout: 15_000 });
    await expect(modal).toBeVisible({ timeout: 10_000 });
  }
}

async function clickPlusInRow(row: Locator, times: number) {
  const plus = row.locator('button.indec-plus');
  for (let i = 0; i < times; i++) {
    await plus.click();
  }
}

async function selectViande(page: Page, nameRe: RegExp, times: number, label: string) {
  const wizardRow = wizardViandeRow(page, nameRe);
  if (await wizardRow.first().isVisible({ timeout: 1_000 }).catch(() => false)) {
    const plus = wizardRow.first().locator('button.viande-btn.plus');
    for (let i = 0; i < times; i++) {
      await expect(plus, `${label} plus button disabled before requested quantity`).not.toHaveClass(/disabled/);
      await plus.click();
    }
    return;
  }

  const legacyRow = variationQuantityRow(page, nameRe);
  await expect(legacyRow, `${label} introuvable — adapter regex / seed`).toBeVisible({ timeout: 5_000 });
  await clickPlusInRow(legacyRow, times);
}

async function selectExtra(page: Page, nameRe: RegExp) {
  const wizardChip = page.locator('#item-variation-modal .sauce-chip').filter({ hasText: nameRe }).first();
  if (await wizardChip.isVisible({ timeout: 1_000 }).catch(() => false)) {
    await wizardChip.click();
    return;
  }

  const legacyExtra = extraQuantityRow(page, nameRe);
  await expect(legacyExtra, 'Extra introuvable — adapter E2E_POS_EXTRA_RE / seed').toBeVisible({ timeout: 5_000 });
  await clickPlusInRow(legacyExtra, 1);
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

test.describe('POS — Tacos seed-adapted mix → espèces → reçu (T22 partiel)', () => {
  test.describe.configure({ timeout: 120_000 });

  test.beforeEach(async ({ page }) => {
    const shotDir = path.join(process.cwd(), 'reports', 'e2e');
    fs.mkdirSync(shotDir, { recursive: true });
    clearFoodKingRateLimits();
    await loginAsPOS(page);
  });

  test('flux: tacos + sélections + extra + paiement espèces + composition reçu', async ({ page }) => {
    await page.waitForTimeout(2_000);

    // 1. Catalogue — tuile item (landing best sellers ou catégorie Tacos).
    await openTacosProductModal(page);

    // 2. Sélections configurables (compteurs +). Le seed local par défaut expose
    // Tacos L avec deux choix; les seeds 4 viandes peuvent surcharger A_QTY=3/B_QTY=1.
    await selectViande(page, MEAT_A_RE, MEAT_A_QTY, 'Viande A');
    await selectViande(page, MEAT_B_RE, MEAT_B_QTY, 'Viande B');

    // 3. Extra (section extras : même pattern de ligne)
    await selectExtra(page, EXTRA_RE);

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

    const confirmPayment = page.locator('#orderpayment button').filter({ hasText: /confirmer|confirm/i }).last();
    await expect(confirmPayment).toBeVisible({ timeout: 10_000 });
    await Promise.all([
      page.waitForResponse((response) => (
        response.request().method() === 'POST' && /\/api\/admin\/pos$/.test(response.url())
      ), { timeout: 15_000 }).catch(() => null),
      confirmPayment.click(),
    ]);

    // 7. Reçu — composition + pied fiscal (si seed branch fiscal)
    const receipt = page.locator('#receiptModal');
    await expect(receipt).toBeVisible({ timeout: 20_000 });

    const receiptBody = receipt.locator('#print');
    // Ligne variations / instruction wizard : les quantités dépendent du seed.
    await expect(receiptBody.getByText(MEAT_A_RE).first()).toBeVisible();
    await expect(receiptBody.getByText(MEAT_B_RE).first()).toBeVisible();

    await expect(receiptBody.getByText(/NF525|SIRET|TVA/i).first()).toBeVisible();

    await page.screenshot({ path: 'reports/e2e/pos-tacos-4-viandes-cash-end.png', fullPage: true });
  });
});
