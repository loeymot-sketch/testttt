// [GOAL owner 2026-07-17] Gate visuelle des 4 corrections :
//  C1 borne+caisse : Menu Enfant Nuggets → étape SAUCE ; Menu Enfant Chicken Burger
//     → CRUDITÉS (Salade/Tomate/Oignon) puis SUPPLÉMENTS standard.
//  C2 borne : tuile Menu Enfant Chicken Burger = visuel POULET (chicken_burger.png).
//  C4 borne : Bol Frites → « Option Gratiné » @2,00 ; Galette Normale → plus de gratiné.
// Screenshots → /tmp/foodking-goal-kids-2026-07-17/ (Read + analyse par Claude ensuite).
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk, loginAsPosOperator } = require('./helpers/login');

const SHOT_DIR = '/tmp/foodking-goal-kids-2026-07-17';
const CAT_KIDS = 11;
const CAT_BOLS = 6;
const CAT_GALETTE = 2;
const ID_NUGGETS = 40;
const ID_KIDS_BURGER = 106;
const ID_BOL_FRITES = 41;
const ID_GALETTE_NORMALE = 23;

async function shot(page, name) {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  await page.screenshot({ path: path.join(SHOT_DIR, name + '.png'), fullPage: false }).catch(() => {});
}

async function closeWizard(page) {
  // Ferme le wizard borne sans ajouter au panier. Regex « abandonner l » :
  // matche « ABANDONNER L'ARTICLE » quelle que soit l'apostrophe (' vs ’) et ne
  // matche JAMAIS « Abandonner ma commande » (qui renvoie à l'idle).
  const btn = page.locator('button').filter({ hasText: /abandonner l/i }).first();
  if (await btn.isVisible({ timeout: 1500 }).catch(() => false)) {
    await btn.click().catch(() => {});
    // Un overlay de CONFIRMATION s'ouvre (kiosk-wizard-abandon-overlay) et
    // intercepte tous les clics tant qu'on n'a pas confirmé → cliquer « oui ».
    const yes = page.locator('.kiosk-wizard-abandon-yes').first();
    if (await yes.isVisible({ timeout: 2500 }).catch(() => false)) {
      await yes.click().catch(() => {});
    }
    await page.waitForTimeout(700);
  }
}

/**
 * Avance dans le wizard borne en satisfaisant les min-select (bouton CHOISIR ou
 * 1ère tuile) et retourne le texte de CHAQUE étape (scopé à l'overlay wizard).
 * S'arrête au RÉCAP (pas de SUIVANT) sans jamais ajouter au panier.
 */
async function walkWizardSteps(page, maxSteps, shotPrefix, stopWhen = null) {
  const wiz = page.locator('.kiosk-wizard-overlay');
  const texts = [];
  for (let i = 0; i < maxSteps; i++) {
    if (!(await wiz.isVisible({ timeout: 1500 }).catch(() => false))) break;
    await page.waitForTimeout(400);
    let txt = (await wiz.innerText().catch(() => '')) || '';
    // satisfaire le min-select de l'étape si besoin
    const choisir = wiz.locator('button').filter({ hasText: /^choisir$/i }).first();
    if (await choisir.isVisible({ timeout: 500 }).catch(() => false)) {
      await choisir.click().catch(() => {});
      await page.waitForTimeout(400);
    } else if (/Sélectionnez au moins une sauce/i.test(txt)) {
      await wiz.getByText('Mayonnaise').first().click().catch(() => {});
      await page.waitForTimeout(400);
    } else if (/Sélectionnez au moins/i.test(txt)) {
      await wiz.locator('[class*="card"], [class*="tile"], [class*="choice"]').first().click().catch(() => {});
      await page.waitForTimeout(400);
    }
    txt = (await wiz.innerText().catch(() => '')) || txt;
    texts.push(txt);
    await shot(page, `${shotPrefix}-step${i + 1}`);
    if (stopWhen && stopWhen.test(txt)) break; // cible trouvée — inutile d'aller plus loin
    const next = wiz.locator('button').filter({ hasText: /^suivant$/i }).first();
    if (!(await next.isVisible({ timeout: 700 }).catch(() => false))) break;
    await next.click().catch(() => {});
    await page.waitForTimeout(700);
  }
  return texts;
}

async function ensureOrdering(page) {
  // Si un timeout/action a renvoyé la borne à l'idle, re-rentre en mode commande.
  if (await page.getByTestId('kiosk-idle-root').isVisible({ timeout: 1000 }).catch(() => false)) {
    await page.getByTestId('kiosk-order-type-takeaway').click().catch(() => {});
    await page.waitForTimeout(900);
  }
}

test.describe('GOAL kids-menu + gratiné + image — gate visuelle', () => {
  test.describe.configure({ timeout: 300_000, retries: 0 });

  test('BORNE — tuiles, wizard Nuggets(sauce), Kids Burger(crudités→suppléments), Bol gratiné, Galette sans gratiné', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await loginAsKiosk(page);
    await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
    await page.getByTestId('kiosk-order-type-takeaway').click();
    await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });

    // ── C2 : grille Menu enfant (image tuile chicken burger)
    await page.goto(`/kiosk/categories?cat=${CAT_KIDS}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId(`kiosk-product-card-${ID_KIDS_BURGER}`)).toBeVisible({ timeout: 25_000 });
    const imgSrc = await page
      .getByTestId(`kiosk-product-card-${ID_KIDS_BURGER}`)
      .locator('img')
      .first()
      .getAttribute('src')
      .catch(() => null);
    expect(String(imgSrc)).toContain('chicken_burger');
    await shot(page, '01-borne-grille-menu-enfant');

    // ── C1a : wizard Nuggets → étape sauce
    await page.getByTestId(`kiosk-product-add-${ID_NUGGETS}`).click();
    await page.waitForTimeout(1500);
    await shot(page, '02-borne-wizard-nuggets-sauce');
    const bodyNuggets = await page.evaluate(() => document.body.innerText);
    expect(bodyNuggets).toMatch(/Choisis ta sauce|SAUCE/i);
    expect(bodyNuggets).toMatch(/Mayonnaise/i);
    expect(bodyNuggets).toMatch(/Harissa/i);
    await closeWizard(page);

    // ── C1b : wizard Kids Burger → crudités puis suppléments
    await page.getByTestId(`kiosk-product-add-${ID_KIDS_BURGER}`).click();
    await page.waitForTimeout(1500);
    await shot(page, '03-borne-wizard-kidsburger-crudites');
    let body = await page.evaluate(() => document.body.innerText);
    expect(body).toMatch(/garniture|crudit/i);
    expect(body).toMatch(/Salade/i);
    expect(body).toMatch(/Tomate/i);
    expect(body).toMatch(/Oignon/i);

    // étape suivante → suppléments
    const next = page.locator('.kiosk-wizard .kiosk-btn-next, .kiosk-wizard button:has-text("Continuer"), button:has-text("Suivant")').last();
    if (await next.isVisible({ timeout: 2000 }).catch(() => false)) {
      await next.click().catch(() => {});
      await page.waitForTimeout(1200);
    }
    await shot(page, '04-borne-wizard-kidsburger-supplements');
    body = await page.evaluate(() => document.body.innerText);
    expect(body).toMatch(/Suppl/i);
    expect(body).toMatch(/Cheddar/i);
    await closeWizard(page);

    expect(errors, `pageerror borne: ${errors.join(' | ')}`).toHaveLength(0);
    await ctx.close();
  });

  test('BORNE-2 — Bol Frites : Option Gratiné @2,00 à l\'étape SUPPLÉMENT', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    await loginAsKiosk(page);
    await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
    await page.getByTestId('kiosk-order-type-takeaway').click();
    await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
    await page.goto(`/kiosk/categories?cat=${CAT_BOLS}`, { waitUntil: 'domcontentloaded' });
    await ensureOrdering(page);
    await expect(page.getByTestId(`kiosk-product-card-${ID_BOL_FRITES}`)).toBeVisible({ timeout: 25_000 });
    await page.getByTestId(`kiosk-product-add-${ID_BOL_FRITES}`).click();
    await page.waitForTimeout(1200);
    const bolSteps = await walkWizardSteps(page, 6, `05-borne-wizard-bolfrites`, /Gratin/i);
    const gratineStep = bolSteps.find((t) => /Gratin/i.test(t));
    expect(gratineStep, `Option Gratiné absente des étapes bol: ${bolSteps.map((t) => t.slice(0, 50)).join(' // ')}`).toBeTruthy();
    expect(gratineStep).toMatch(/2[,.]00|2\s?€/);
    await ctx.close();
  });

  test('BORNE-3 — Galette Normale : AUCUN gratiné dans le wizard', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    await loginAsKiosk(page);
    await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
    await page.getByTestId('kiosk-order-type-takeaway').click();
    await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
    await page.goto(`/kiosk/categories?cat=${CAT_GALETTE}`, { waitUntil: 'domcontentloaded' });
    await ensureOrdering(page);
    await expect(page.getByTestId(`kiosk-product-card-${ID_GALETTE_NORMALE}`)).toBeVisible({ timeout: 25_000 });
    await page.getByTestId(`kiosk-product-add-${ID_GALETTE_NORMALE}`).click();
    await page.waitForTimeout(1200);
    const galetteSteps = await walkWizardSteps(page, 6, `06-borne-wizard-galette`);
    expect(galetteSteps.length, 'le wizard galette doit s\'ouvrir').toBeGreaterThan(0);
    for (const t of galetteSteps) {
      expect(t, 'gratiné interdit hors bols (galette)').not.toMatch(/Gratin/i);
    }
    await ctx.close();
  });

  test('CAISSE — popup wizard Nuggets(sauce) + Kids Burger(garnitures→suppléments)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    await shot(page, '07-caisse-pos-home');

    // Catégorie Menu enfant
    const catBtn = page.locator('button, .pos-category, [class*="category"]').filter({ hasText: /Menu enfant/i }).first();
    await expect(catBtn).toBeVisible({ timeout: 20_000 });
    await catBtn.click();
    await page.waitForTimeout(1500);
    await shot(page, '08-caisse-grille-menu-enfant');

    // Nuggets → popup wizard → sauce (clic sur l'image de la carte produit)
    await page.locator('img[src*="nuggets"]').first().click();
    await page.waitForTimeout(2000);
    await shot(page, '09-caisse-wizard-nuggets-sauce');
    let body = await page.evaluate(() => document.body.innerText);
    expect(body).toMatch(/sauce/i);
    expect(body).toMatch(/Mayonnaise/i);
    // fermer la popup — scoper AU MODAL (#item-variation-modal) sinon on clique
    // un « Annuler » hors modal et la popup reste ouverte (intercepte les clics).
    const modal = page.locator('#item-variation-modal');
    const closeBtn = modal.locator('button').filter({ hasText: /annuler/i }).first();
    if (await closeBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await closeBtn.click().catch(() => {});
    } else {
      await page.keyboard.press('Escape').catch(() => {});
    }
    await modal.waitFor({ state: 'hidden', timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(500);

    // Kids burger → garnitures puis suppléments (clic sur le titre de la carte)
    const kbTitle = page.getByText('Menu Enfant Chicken Burger', { exact: true }).first();
    if (await kbTitle.isVisible({ timeout: 3000 }).catch(() => false)) {
      await kbTitle.click();
    } else {
      await page.locator('img[src*="chicken_burger"], img[src*="chicken-burger"]').first().click();
    }
    await page.waitForTimeout(2000);
    await shot(page, '10-caisse-wizard-kidsburger-step1');
    body = await page.evaluate(() => document.body.innerText);
    expect(body).toMatch(/Salade/i);
    expect(body).toMatch(/Tomate/i);
    // étape suivante si bouton
    const nextPos = page.locator('button:has-text("Suivant"), button:has-text("Continuer"), [class*="wizard"] [class*="next"]').last();
    if (await nextPos.isVisible({ timeout: 2000 }).catch(() => false)) {
      await nextPos.click().catch(() => {});
      await page.waitForTimeout(1200);
    }
    await shot(page, '11-caisse-wizard-kidsburger-step2');
    body = await page.evaluate(() => document.body.innerText);
    expect(body).toMatch(/Cheddar|Suppl/i);

    expect(errors, `pageerror caisse: ${errors.join(' | ')}`).toHaveLength(0);
    await ctx.close();
  });
});
