/**
 * [GOAL CAISSE/CUISINE 2026-10-02] Captures visuelles des écrans modifiés (CLAUDE.md §6).
 * Les PNG vont dans E2E_SHOTS (hors dépôt : ne pas committer les captures, cf. mémoire disque).
 *
 * Prérequis étape 05 (KDS) : 3 commandes de dev `GOAL0210-KDS-1..3` (K911..K913) dont `order_datetime` date
 * de moins de 8 h (fenêtre `oss.stale_window_hours`), sinon le board est vide.
 *
 *   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 E2E_SHOTS=/tmp/shots \
 *     npx playwright test tests/Playwright/goal-caisse-cuisine-2026-10-02.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const { loginAsPosOperator, loginAsChefOperator, loginAsAdmin } = require('../e2e/helpers/login');

const SHOTS = process.env.E2E_SHOTS || path.join(__dirname, '..', 'captures', 'goal-caisse-cuisine-2026-10-02');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (page, name, opts = {}) => page.screenshot({ path: path.join(SHOTS, name), fullPage: false, ...opts });
const settle = async (page, ms = 1200) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);
test.use({ actionTimeout: 20_000, viewport: { width: 1366, height: 800 } });

test('00 instrument : cet arbre, ce port, ce SHA', async ({ page }) => {
  const sha = execSync('git rev-parse --short HEAD', { cwd: path.resolve(__dirname, '../..') }).toString().trim();
  await page.goto('/login');
  const html = await page.content();
  const baseUrl = (html.match(/baseUrl:\s*"([^"]+)"/) || [])[1];
  const info = { base: process.env.PLAYWRIGHT_BASE_URL, appUrl: (baseUrl || '').replace(/\\\//g, '/'), sha };
  console.log('INSTRUMENT', JSON.stringify(info));
  expect(info.appUrl).toContain(new URL(info.base).host);
});

test('01 caisse : catégorie « Sauces supplémentaires » visible, vente seule', async ({ page }) => {
  await loginAsPosOperator(page);
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await settle(page, 2500);
  await shot(page, '01-caisse-accueil.png');
  const cat = page.getByText('Sauces supplémentaires', { exact: false }).first();
  await expect(cat).toBeVisible({ timeout: 20_000 });
  await cat.click();
  await settle(page, 1500);
  await shot(page, '02-caisse-categorie-sauces.png');
});

async function ouvrirSauces(page) {
  await loginAsPosOperator(page);
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await settle(page, 2500);
  await page.getByText('Sauces supplémentaires', { exact: false }).first().click();
  await settle(page, 1200);
}

test('02 caisse : 3 sauces au panier puis écran de paiement (4 boutons, CB partielle)', async ({ page }) => {
  await ouvrirSauces(page);
  for (const nom of ['Sauce Ketchup', 'Sauce Mayonnaise', 'Sauce Blanche', 'Sauce Ketchup']) {
    if (!(await page.locator(`button.pos-item-tile[aria-label^="Ajouter ${nom}"]`).first().isVisible().catch(() => false))) {
      await page.getByText('Sauces supplémentaires', { exact: false }).first().click();
      await page.waitForTimeout(600);
    }
    await page.locator(`button.pos-item-tile[aria-label^="Ajouter ${nom}"]`).first().click({ timeout: 15_000 });
    await page.waitForTimeout(500);
  }
  await settle(page, 800);
  await shot(page, '03-caisse-panier-4-sauces.png');
  const commander = page.getByRole('button', { name: /^\W*commande\s*·/i }).first();
  await expect(commander).toBeVisible();
  await commander.click();
  await expect(page.locator('[data-testid="pos-payment-mode-ticket"]')).toBeVisible({ timeout: 20_000 });
  await settle(page, 800);
  await shot(page, '04-paiement-4-boutons.png');
  // CB partielle
  await page.locator('[data-testid="pos-payment-mode-card"]').click();
  await page.locator('[data-testid="pos-payment-card-amount"]').fill('1,00');
  await page.waitForTimeout(900);
  await expect(page.locator('[data-testid="pos-payment-card-split"]')).toBeEnabled();
  await shot(page, '05-paiement-cb-montant-partiel.png');
  await page.locator('[data-testid="pos-payment-card-split"]').click();
  await expect(page.locator('[data-testid="pos-payment-split-block"]')).toBeVisible();
  await settle(page, 600);
  await shot(page, '06-paiement-cb-puis-reste.png');
  await page.locator('[data-testid="pos-payment-tranche-add"]').scrollIntoViewIfNeeded();
  await page.waitForTimeout(500);
  await shot(page, '06b-paiement-reste-tranche-2.png');
  // Titres-resto
  await page.locator('[data-testid="pos-payment-mode-ticket"]').click();
  await settle(page, 600);
  await shot(page, '07-paiement-titres-resto.png');
});

test('03 caisse : frites + 2e sauce → bouton Offert', async ({ page }) => {
  await loginAsPosOperator(page);
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await settle(page, 2500);
  await page.getByText('Frites', { exact: true }).first().click();
  await settle(page, 1000);
  await page.locator('button.pos-item-tile[aria-label^="Ajouter Petite Frites"]').first().click();
  await settle(page, 1500);
  await shot(page, '08-wizard-frites.png');
  const wiz = page.locator('#pos-wizard-root');
  for (const sauce of ['Ketchup', 'Mayonnaise', 'Samouraï']) {
    await wiz.locator('.sauce-chip', { hasText: sauce }).first().click();
    await page.waitForTimeout(300);
  }
  await shot(page, '09-wizard-frites-3-sauces.png');
  await wiz.getByRole('button', { name: /Ajouter au panier/ }).click();
  await settle(page, 1500);
  await shot(page, '10-panier-frites-3-sauces.png');
  // Bouton « Offert » sur la sauce supplémentaire
  const offer = page.locator('[data-testid="pos-cart-offer-extra"]').first();
  await expect(offer).toBeVisible({ timeout: 15_000 });
  await offer.scrollIntoViewIfNeeded();
  await shot(page, '11-panier-bouton-offert.png');
  await offer.click();
  await page.waitForTimeout(600);
  await shot(page, '12-panier-sauce-offerte.png');
  // Bout-en-bout : le devis serveur (PricingService) doit confirmer le total affiché, sans prix client.
  const quoteReq = page.waitForRequest((r) => r.url().includes('admin/pos/quote') && r.method() === 'POST');
  const quoteRes = page.waitForResponse((r) => r.url().includes('admin/pos/quote') && r.request().method() === 'POST');
  await page.getByRole('button', { name: /^\W*commande\s*·/i }).first().click();
  const body = (await quoteReq).postDataJSON();
  const items = JSON.parse(body.items);
  const res = await (await quoteRes).json();
  console.log('WIRE items =', JSON.stringify(items.map((i) => ({ item_id: i.item_id, item_extras: i.item_extras, offered: i.item_extras_offered }))));
  console.log('QUOTE total_ttc =', res.data.total_ttc);
  expect(items[0].item_extras_offered.length).toBe(1);
  expect(Object.keys(items[0].item_extras_offered[0]).sort()).toEqual(['id', 'quantity']);
  expect(items[0].item_extras.some((e) => e.id === items[0].item_extras_offered[0].id)).toBe(false);
  expect(Number(res.data.total_ttc)).toBeCloseTo(2.5, 2);
  await expect(page.locator('[data-testid="pos-payment-mode-ticket"]')).toBeVisible({ timeout: 20_000 });
  await shot(page, '13-paiement-total-2-50-offert.png');
});

test('04 encaissement : jour courant par défaut, jours précédents, purge confirmée', async ({ page }) => {
  await loginAsPosOperator(page);
  await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await settle(page, 2500);
  await shot(page, '14-encaissement-aujourdhui-defaut.png');
  const badge = await page.locator('[data-testid="enc-previous-count"]').innerText().catch(() => '0');
  console.log('PREVIOUS_COUNT badge =', badge);
  await page.locator('[data-testid="enc-scope-previous"]').click();
  await settle(page, 2500);
  await shot(page, '15-encaissement-jours-precedents.png');
  await page.locator('[data-testid="enc-purge-all"]').click();
  await expect(page.locator('[data-testid="enc-purge-confirm"]')).toBeVisible();
  await shot(page, '16-purge-tout-confirmation.png');
  await page.locator('[data-testid="enc-purge-cancel"]').click();          // on ne purge PAS tout
  // Une seule commande : confirmation puis purge réelle
  const first = page.locator('button[data-testid^="enc-purge-"]').filter({ hasText: /^Purger$/ }).first();
  const testid = await first.getAttribute('data-testid');
  console.log('PURGE_ONE target =', testid);
  await first.click();
  await expect(page.locator('[data-testid="enc-purge-confirm"]')).toBeVisible();
  await shot(page, '17-purge-une-commande-confirmation.png');
  await page.locator('[data-testid="enc-purge-ok"]').click();
  await settle(page, 2500);
  await shot(page, '18-apres-purge-une-commande.png');
  const after = await page.locator('[data-testid="enc-previous-count"]').innerText().catch(() => '0');
  console.log('PREVIOUS_COUNT apres =', after);
});

test('05 KDS : 3 sauces nommées, suppléments blanc sur noir, # gras, fond blanc', async ({ page }) => {
  await loginAsChefOperator(page);
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await settle(page, 4000);
  await shot(page, '19-kds-cartes.png');
  const card = page.locator('.kds-card', { hasText: 'K911' }).first();
  await expect(card).toBeVisible({ timeout: 20_000 });
  await card.scrollIntoViewIfNeeded();
  await card.screenshot({ path: path.join(SHOTS, '20-kds-carte-tacos-zoom.png') });
  const info = await card.evaluate((el) => {
    const bg = getComputedStyle(el).backgroundColor;
    const supp = el.querySelector('.kds-line__supplement');
    const hash = el.querySelector('.kds-line__hash');
    return { cardBg: bg, supp: supp && { text: supp.textContent.trim(), color: getComputedStyle(supp).color, bg: getComputedStyle(supp).backgroundColor, weight: getComputedStyle(supp).fontWeight }, hash: hash && hash.textContent, main: el.querySelector('.kds-line__symbolic-text')?.textContent };
  });
  console.log('KDS_CARD', JSON.stringify(info));
});

test('06 encaissement : modale à encaisser — 4 moyens, CB partielle, reste en Titres-resto', async ({ page }) => {
  await loginAsPosOperator(page);
  await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await settle(page, 2500);
  await page.locator('[data-testid^="enc-collect-"]').first().click();
  const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
  await expect(modal).toBeVisible();
  await settle(page, 800);
  await shot(page, '21-modale-encaisser-4-moyens.png');
  await modal.locator('[data-testid="pos-counter-collect-mode-CARD"]').click();
  for (const k of ['5', ',', '0', '0']) await modal.getByRole('button', { name: k, exact: true }).first().click();
  await page.waitForTimeout(600);
  await expect(modal.locator('[data-testid="pos-counter-collect-mixte-block"]')).toBeVisible();
  await shot(page, '22-modale-cb-partielle-bascule-multi.png');
  await modal.locator('[data-testid="cc-mixte-second-ticket"]').click();
  await page.waitForTimeout(400);
  await shot(page, '23-modale-reste-titres-resto.png');
  await modal.locator('[data-testid="pos-counter-collect-close"]').click(); // on n'encaisse pas
});
