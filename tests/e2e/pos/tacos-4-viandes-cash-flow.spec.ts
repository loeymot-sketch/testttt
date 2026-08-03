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
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { loginAsPosOperator } = require('../helpers/login');
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { ensurePosTacosMenuForE2e } = require('../helpers/pos-tacos-fixture');

const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';

/** Regex libellé item catalogue (seed variable : Tacos M/L, wrap, etc.) */
const ITEM_CATALOG_RE = new RegExp(
  process.env.E2E_POS_TACOS_ITEM_RE || 'tacos|galette|wrap|kebab|tacos\\s*l|tacos.*2',
  'i',
);
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

async function loginAsPOS(page: Page) {
  await loginAsPosOperator(page, POS_EMAIL, POS_PASSWORD);
}

/**
 * Ligne variation « multi » (compteurs +/-) dans le modal, identifiée par le nom affiché (h3).
 */
function variationQuantityRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal.active div.flex.items-center.gap-3.rounded-lg.border')
    .filter({ has: page.locator('h3').filter({ hasText: nameRe }) });
}

function wizardViandeRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal.active .wizard-viande-row')
    .filter({ has: page.locator('.viande-name').filter({ hasText: nameRe }) });
}

function extraQuantityRow(page: Page, nameRe: RegExp) {
  return page
    .locator('#item-variation-modal.active div.flex.items-center.gap-3.rounded-lg.border')
    .filter({ has: page.locator('h3').filter({ hasText: nameRe }) });
}

/**
 * Tuile catalogue POS V5 : bouton avec `aria-label` « Ajouter {item}, {price} »
 * (voir ItemComponent.vue) — plus fiable que le seul `h3` si le DOM varie.
 */
function productTile(page: Page) {
  return page.getByRole('button', { name: ITEM_CATALOG_RE }).first();
}

function escapeRegex(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function closeBlockingPosPanels(page: Page) {
  const kioskCashClose = page.locator('.kiosk-cash-panel-close').first();
  if (await kioskCashClose.isVisible({ timeout: 500 }).catch(() => false)) {
    await kioskCashClose.click();
  }
}

async function openTacosProductModal(page: Page, seededItemName?: string) {
  for (let attempt = 1; attempt <= 2; attempt++) {
    await closeBlockingPosPanels(page);

    let tile = productTile(page);
    if (!await tile.isVisible({ timeout: 10_000 }).catch(() => false)) {
      const tacosCategory = page
        .getByRole('button', { name: /category\s+(Nos Tacos|PW-E2E Tacos)|Nos Tacos|PW-E2E Tacos/i })
        .first();

      if (await tacosCategory.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await tacosCategory.click();
        tile = productTile(page);
      }
    }

    if (!await tile.isVisible({ timeout: 5_000 }).catch(() => false) && seededItemName) {
      const searchBox = page.getByRole('searchbox', { name: /rechercher un article|search/i }).first();
      if (await searchBox.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await searchBox.fill(seededItemName);
        await page.waitForTimeout(800);
        tile = page.getByRole('button', { name: new RegExp(escapeRegex(seededItemName), 'i') }).first();
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

  const legacyRow = variationQuantityRow(page, nameRe).first();
  await expect(legacyRow, `${label} introuvable — adapter regex / seed`).toBeVisible({ timeout: 5_000 });
  await clickPlusInRow(legacyRow, times);
}

async function selectExtra(page: Page, nameRe: RegExp) {
  const modal = page.locator('#item-variation-modal');
  const wizardChip = modal.locator('.sauce-chip').filter({ hasText: nameRe }).first();
  if (await wizardChip.isVisible({ timeout: 1_500 }).catch(() => false)) {
    await wizardChip.click();
    return;
  }

  // Single-page wizard: paid extras live under a collapsed « Suppléments » panel (pos-wizard.js).
  const supplToggle = modal.locator('.suppl-toggle').first();
  if (await supplToggle.isVisible({ timeout: 1_500 }).catch(() => false)) {
    await supplToggle.click();
  }

  const supplementOpt = modal.locator('.supplement-opt').filter({ hasText: nameRe }).first();
  if (await supplementOpt.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await supplementOpt.click();
    return;
  }

  const legacyExtra = extraQuantityRow(page, nameRe).first();
  await expect(legacyExtra, 'Extra introuvable — adapter E2E_POS_EXTRA_RE / seed').toBeVisible({ timeout: 5_000 });
  await clickPlusInRow(legacyExtra, 1);
}

/**
 * Total panier affiché (dernière ligne « Total » dans #pos-cart).
 * Pas de data-testid `pos-cart-total` — sélecteur structurel documenté pour ops.
 */
function posCartTotalLocator(page: Page) {
  // POS V5: totals live in footer `PosV5TotalRow` (not legacy `#pos-cart li`).
  return page.locator('#pos-cart [data-testid="pos-grand-total"] .pos-v5-total-row__amount').first();
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
    const seeded = ensurePosTacosMenuForE2e(POS_EMAIL);
    if (!seeded.ok) {
      test.skip(true, `Fixture POS tacos indisponible (${seeded.reason || 'unknown'}) — seed menu ou compte E2E_POS_USER.`);
    }
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 25_000 });
    await page.waitForTimeout(2_000);

    // 1. Catalogue — tuile item (landing best sellers ou catégorie Tacos).
    await openTacosProductModal(page, seeded.name);

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
    const cartTotalNumber = parseAmount(await totalEl.innerText());
    expect(cartTotalNumber, 'Total panier illisible').toBeGreaterThan(0);

    // 6. Commande → paiement (POS V5 CTA includes amount: "Order · 12,50 €")
    await page.getByTestId('pos-v5-pay').click();
    await expect(page.locator('#orderpayment')).toBeVisible({ timeout: 10_000 });

    await page.locator('[data-tab="#cash"]').click();
    await expect(page.locator('#cashInput')).toBeVisible();
    // Montant encaissé = SSOT affiché dans le modal (évite décalage panier vs form.total si régression).
    const modalTotalEl = page.locator('#orderpayment .pos-v5-payment-total-value').first();
    await expect(modalTotalEl).toBeVisible({ timeout: 5_000 });
    await page.waitForTimeout(400);
    const payTotalNumber = parseAmount(await modalTotalEl.innerText());
    expect(payTotalNumber, 'Total modal paiement illisible').toBeGreaterThan(0);
    const due = Math.max(payTotalNumber, cartTotalNumber);
    const received = String(Math.ceil(due + 5));
    await page.locator('#cashInput').fill(received);

    const confirmPayment = page.locator('#orderpayment button').filter({ hasText: /confirmer|confirm/i }).last();
    await expect(confirmPayment).toBeVisible({ timeout: 10_000 });
    let saveResp;
    for (let attempt = 0; attempt < 3; attempt += 1) {
      clearFoodKingRateLimits();
      const saveRespPromise = page.waitForResponse(
        (response) => response.request().method() === 'POST' && /\/api\/admin\/pos$/.test(response.url()),
        { timeout: 25_000 },
      );
      await confirmPayment.click();
      saveResp = await saveRespPromise;
      if (saveResp.status() !== 429) {
        break;
      }
      await page.waitForTimeout(2000);
    }
    expect(
      saveResp.status(),
      `POST /api/admin/pos doit réussir (reçu=${received}, statut API)`,
    ).toBeLessThan(400);

    // 7. Reçu — composition + pied fiscal (si seed branch fiscal)
    const receipt = page.locator('#receiptModal');
    await expect(receipt).toBeVisible({ timeout: 20_000 });

    // POS V5: viandes / extras peuvent n'apparaître que sur le ticket cuisine (bloc Instruction),
    // pas sur le ticket client (ligne article seule + TVA). On valide sur les deux panneaux.
    const receiptPrint = receipt.locator('#print-receipt-kitchen, #print-receipt-client');
    await expect(receiptPrint.getByText(MEAT_A_RE).first()).toBeVisible({ timeout: 10_000 });
    await expect(receiptPrint.getByText(MEAT_B_RE).first()).toBeVisible({ timeout: 10_000 });

    await expect(receipt.locator('#print-receipt-client').getByText(/NF525|SIRET|TVA/i).first()).toBeVisible();

    await page.screenshot({ path: 'reports/e2e/pos-tacos-4-viandes-cash-end.png', fullPage: true });
  });
});
