// [CAPTUREUR-ADVERSAIRE W8 borne 2026-07-20] Preuve visuelle PAR CATÉGORIE composable.
// Pour chaque catégorie : wizard borne → sélection d'AU MOINS 1 supplément PAYANT
// (quand le profil publié le permet) → capture wizard avec TOTAL live (footer
// .kiosk-nav-total = runningTotalLocal) → assert total == base + suppléments →
// AJOUTER AU PANIER → capture panier (kiosk-cart-total) au même total → CLEAR panier.
// AUCUNE commande créée : panier localStorage only, jamais de checkout/paiement.
//
// Vérité DB relevée avant écriture (items table + item_wizard_profiles publiés) :
//   - item 45 = « Bol Riz » 7,90 (le « Bol Frites » est l'item 41) — mission dit
//     « Bol Frites 45 » : l'ID prime, on teste 45/Bol Riz et on documente l'écart.
//   - item 40 (Menu Enfant Nuggets) : profil publié 2026-07-17 = UNE étape sauce
//     min=1 max=1 — AUCUN supplément payant offert par le wizard borne (l'ItemExtra
//     « Sauce supplémentaire » 0,50 existe en DB group=sauce mais n'est PAS surfacé).
//   - toutes les étapes sauce des profils publiés sont max=1 → l'axe « sauce supp
//     0,50 » est inatteignable sur borne (couvert web/POS uniquement). Documenté.
//   - « Viande supplémentaire » 2,50 et « Option Gratiné » 2,00 sont des LIGNES de
//     l'étape suppléments (group=supplement / supplement_bol) → sélection uniforme.
// Patterns anti-flaky repris de _teste2e-goal4-waveA.spec.js (idle-root = signal
// login fini, waitFor ≠ isVisible, settle anim 750ms wizard, overlay inactivité).
//
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/borne-wizard-7cats-captures-2026-07-20.spec.js
const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'borne-7cats-2026-07-20');
const VIEWPORT = { width: 1080, height: 1920 };
const fmt = (n) => n.toFixed(2).replace('.', ',');

// pick = ligne PAYANTE cliquée à l'étape suppléments (null = catégorie ne le permet pas).
const CATS = [
  { n: '01', cat: 1,  id: 22,  slug: 'sandwich-cayenne', label: 'Sandwich Cayenne',            base: 7.40, pick: { re: /viande suppl/i,   name: 'Viande supplémentaire', price: 2.50 }, expected: 9.90 },
  { n: '02', cat: 2,  id: 23,  slug: 'galette-normale',  label: 'Galette Normale',             base: 6.50, pick: { re: /cheddar/i,      name: 'Cheddar',               price: 0.90 }, expected: 7.40 },
  { n: '03', cat: 4,  id: 98,  slug: 'cheese-burger',    label: 'Cheese Burger',               base: 6.00, pick: { re: /raclette/i,     name: 'Raclette',              price: 0.90 }, expected: 6.90 },
  { n: '04', cat: 5,  id: 26,  slug: 'tacos-m',          label: 'Tacos M',                     base: 6.90, pick: { re: /cheddar/i,      name: 'Cheddar',               price: 0.90 }, expected: 7.80 },
  { n: '05', cat: 6,  id: 45,  slug: 'bol-riz',          label: 'Bol Riz (item 45)',           base: 7.90, pick: { re: /option gratin/i,  name: 'Option Gratiné',        price: 2.00 }, expected: 9.90 },
  { n: '06', cat: 11, id: 40,  slug: 'menu-enf-nuggets', label: 'Menu Enfant Nuggets',         base: 4.90, pick: null,                                                                  expected: 4.90 },
  { n: '07', cat: 11, id: 106, slug: 'menu-enf-chicken', label: 'Menu Enfant Chicken Burger',  base: 4.90, pick: { re: /cheddar/i,      name: 'Cheddar',               price: 0.90 }, expected: 5.80 },
];

const STEP_ROOTS = [
  '.kiosk-step-taille',
  '.kiosk-step-pain',
  '.kiosk-step-viande',
  '.kiosk-step-sauce',
  '.kiosk-step-garnitures',
  '.kiosk-step-supplements',
  '.kiosk-step-menu',
  '.kiosk-menu-grid',
  '[data-testid="kiosk-order-summary-root"]',
].join(', ');

function extractEuro(text) {
  const m = String(text || '').match(/(\d+[.,]\d{2})/);
  return m ? parseFloat(m[1].replace(',', '.')) : NaN;
}

async function snap(page, name) {
  await page.screenshot({ path: path.join(SHOT_DIR, `${name}.png`) }).catch((e) => {
    console.log(`[snap] échec capture ${name}: ${e.message}`);
  });
}

async function dismissInactivityIfShown(page) {
  const stay = page.getByTestId('kiosk-inactivity-stay');
  if (await stay.isVisible().catch(() => false)) {
    console.log('[ANOMALIE] overlay inactivité apparu — clic « Je suis là »');
    await stay.click().catch(() => {});
    await page.waitForTimeout(400);
  }
}

async function reconnectIfExpired(page) {
  const btn = page.locator('button, a').filter({ hasText: /se reconnecter/i }).first();
  if (await btn.isVisible().catch(() => false)) {
    console.log('[ANOMALIE] bannière « Session expirée » — clic Se reconnecter');
    await btn.click().catch(() => {});
    await page.getByTestId('kiosk-idle-root').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(600);
  }
}

async function enterOrdering(page) {
  const onIdle = await page
    .getByTestId('kiosk-idle-root')
    .waitFor({ state: 'visible', timeout: 2_000 })
    .then(() => true)
    .catch(() => false);
  if (onIdle) {
    await page.getByTestId('kiosk-order-type-takeaway').click();
    await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
  }
}

async function gotoCategory(page, catId, mustSeeCardId) {
  for (let attempt = 0; attempt < 3; attempt++) {
    await dismissInactivityIfShown(page);
    await reconnectIfExpired(page);
    await enterOrdering(page);
    await page.goto(`/kiosk/categories?cat=${catId}`, { waitUntil: 'domcontentloaded' });
    const ok = await page
      .getByTestId(`kiosk-product-card-${mustSeeCardId}`)
      .waitFor({ state: 'visible', timeout: 15_000 })
      .then(() => true)
      .catch(() => false);
    if (ok) return;
    console.log(`[gotoCategory] cat=${catId} tentative ${attempt + 1} — carte ${mustSeeCardId} absente, retry`);
  }
  await expect(page.getByTestId(`kiosk-product-card-${mustSeeCardId}`)).toBeVisible({ timeout: 10_000 });
}

async function openWizard(page, productId) {
  await dismissInactivityIfShown(page);
  await page.getByTestId(`kiosk-product-add-${productId}`).click();
  const wiz = page.locator('.kiosk-wizard-overlay');
  await expect(wiz).toBeVisible({ timeout: 15_000 });
  await wiz.locator(STEP_ROOTS).first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
  await page.waitForTimeout(750); // fin slide-up (capture mi-anim payée au goal4 R1)
  return wiz;
}

async function closeWizardIfOpen(page) {
  const overlay = page.locator('.kiosk-wizard-overlay');
  if (!(await overlay.isVisible().catch(() => false))) return;
  const btn = page.locator('button').filter({ hasText: /abandonner l/i }).first();
  if (await btn.isVisible().catch(() => false)) {
    await btn.click().catch(() => {});
    const yes = page.locator('.kiosk-wizard-abandon-yes').first();
    if (await yes.waitFor({ state: 'visible', timeout: 4_000 }).then(() => true).catch(() => false)) {
      await yes.click().catch(() => {});
    }
  }
  await overlay.waitFor({ state: 'hidden', timeout: 8_000 }).catch(() => {});
}

/** Total live du footer wizard (.kiosk-nav-total = « Total X,XX € », runningTotalLocal). */
async function readWizardFooterTotal(wiz) {
  const el = wiz.locator('.kiosk-nav-total').first();
  await el.waitFor({ state: 'visible', timeout: 8_000 });
  return extractEuro(await el.innerText());
}

/**
 * Fait les sélections de l'étape courante puis SUIVANT, jusqu'au récap.
 * Retourne { wizardShotTaken, footerShown } — la capture wizard est prise juste
 * APRÈS la sélection décisive (le pick payant, sinon la sauce pour l'item 40).
 */
async function walkWizardToRecap(page, wiz, cfg, anomalies) {
  let pickDone = false;
  let wizardShotTaken = false;
  let footerShown = NaN;

  for (let i = 0; i < 14; i++) {
    await dismissInactivityIfShown(page);
    await expect(wiz).toBeVisible({ timeout: 8_000 });
    await wiz.locator(STEP_ROOTS).first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
    await page.waitForTimeout(350);

    if (await wiz.getByTestId('kiosk-order-summary-root').isVisible().catch(() => false)) {
      if (cfg.pick && !pickDone) throw new Error(`récap atteint SANS avoir trouvé la ligne payante ${cfg.pick.name}`);
      return { wizardShotTaken, footerShown };
    }

    const tailleCard = wiz.locator('.kiosk-taille-card').first();
    const painCard = wiz.locator('.kiosk-step-pain .kiosk-option-card').first();
    const viandeStep = wiz.locator('.kiosk-step-viande').first();
    const sauceStep = wiz.locator('.kiosk-step-sauce').first();
    const suppStep = wiz.locator('.kiosk-step-supplements').first();
    const menuCards = wiz.locator('.kiosk-menu-card');

    if (await tailleCard.isVisible().catch(() => false)) {
      // Tacos : préférer la taille « M » (= prix base de l'item 26) ; sinon 1re carte.
      const mCard = wiz.locator('.kiosk-taille-card').filter({ hasText: /\bM\b/ }).filter({ hasNotText: /\bL\b|XL/ }).first();
      const target = (await mCard.isVisible().catch(() => false)) ? mCard : tailleCard;
      console.log(`[${cfg.slug}] étape taille — carte choisie: ${(await target.innerText().catch(() => '?')).replace(/\s+/g, ' ').slice(0, 60)}`);
      await target.click().catch(() => {});
      await page.waitForTimeout(400);
    } else if (await painCard.isVisible().catch(() => false)) {
      await painCard.click().catch(() => {});
      await page.waitForTimeout(400);
    } else if (await viandeStep.isVisible().catch(() => false)) {
      // Quota inclus : ne cliquer QUE si pas complet (un clic au-delà = viande payante non voulue).
      const complete = await viandeStep.locator('.kiosk-complete-badge').isVisible().catch(() => false);
      if (!complete) {
        const card = viandeStep.locator('.kiosk-viande-card.is-selectable').first();
        if (await card.isVisible().catch(() => false)) {
          await card.click().catch(() => {});
          await page.waitForTimeout(400);
        }
      }
    } else if (await sauceStep.isVisible().catch(() => false)) {
      const tiles = sauceStep.locator('.kiosk-option-card');
      const mayo = tiles.filter({ hasText: /mayonnaise/i }).first();
      const target = (await mayo.isVisible().catch(() => false)) ? mayo : tiles.first();
      const sauceName = (await target.innerText().catch(() => '?')).replace(/\s+/g, ' ').trim();
      await target.click().catch(() => {});
      cfg._sauceChosen = sauceName;
      await page.waitForTimeout(400);
      if (!cfg.pick && !wizardShotTaken) {
        // Item 40 : pas de supplément payant possible (profil publié = sauce seule) —
        // la capture wizard-total se prend ici, après LA sélection existante.
        footerShown = await readWizardFooterTotal(wiz);
        await snap(page, `${cfg.n}-${cfg.slug}-wizard`);
        wizardShotTaken = true;
      }
    } else if (await suppStep.isVisible().catch(() => false)) {
      if (cfg.pick && !pickDone) {
        const row = suppStep.locator('.kiosk-supplement-row').filter({ hasText: cfg.pick.re }).first();
        await expect(row, `ligne supplément « ${cfg.pick.name} » attendue (item ${cfg.id})`).toBeVisible({ timeout: 8_000 });
        await row.scrollIntoViewIfNeeded().catch(() => {});
        await row.click();
        await page.waitForTimeout(500);
        const selectedOk = await row.evaluate((el) => el.classList.contains('selected')).catch(() => false);
        if (!selectedOk) anomalies.push(`${cfg.slug}: la ligne ${cfg.pick.name} ne porte pas .selected après clic`);
        pickDone = true;
        footerShown = await readWizardFooterTotal(wiz);
        await snap(page, `${cfg.n}-${cfg.slug}-wizard`);
        wizardShotTaken = true;
      }
    } else if ((await menuCards.count().catch(() => 0)) > 0 && (await menuCards.first().isVisible().catch(() => false))) {
      // Étape formule/boisson : choisir « Sans menu » (🚫, dernière carte) — jamais de boisson.
      const sans = menuCards.filter({ hasText: /sans (menu|boisson)/i }).first();
      const target = (await sans.isVisible().catch(() => false)) ? sans : menuCards.last();
      await target.click().catch(() => {});
      await page.waitForTimeout(400);
    }

    const next = wiz.locator('button').filter({ hasText: /^suivant$/i }).first();
    if (!(await next.isVisible().catch(() => false))) {
      if (await wiz.getByTestId('kiosk-order-summary-root').isVisible().catch(() => false)) continue;
      await page.waitForTimeout(600);
      continue;
    }
    await expect(next).toBeEnabled({ timeout: 8_000 });
    await next.click();
  }
  throw new Error('récap jamais atteint en 14 itérations');
}

/** Recovery inter-catégorie : ferme wizard, vide le panier via l'UI, revient aux catégories. */
async function recoverCleanState(page) {
  await dismissInactivityIfShown(page);
  await closeWizardIfOpen(page);
  await page.goto('/kiosk/categories?cat=1', { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(800);
  await reconnectIfExpired(page);
  await enterOrdering(page).catch(() => {});
  const indicator = page.getByTestId('kiosk-categories-cart-indicator');
  const hasCart = await indicator.isVisible().catch(() => false) && await indicator.isEnabled().catch(() => false);
  if (hasCart) {
    await indicator.click().catch(() => {});
    if (await page.getByTestId('kiosk-cart-root').waitFor({ state: 'visible', timeout: 8_000 }).then(() => true).catch(() => false)) {
      await clearCartFromCartPage(page);
    }
  }
}

/** Sur la page panier : bouton vider → confirmation → retour catégories. */
async function clearCartFromCartPage(page) {
  const clearBtn = page.getByTestId('kiosk-cart-clear');
  if (await clearBtn.isVisible().catch(() => false)) {
    await clearBtn.click().catch(() => {});
    const yes = page.getByTestId('kiosk-cart-clear-yes');
    if (await yes.waitFor({ state: 'visible', timeout: 5_000 }).then(() => true).catch(() => false)) {
      await yes.click().catch(() => {});
    }
    await page.getByTestId('kiosk-cart-empty').waitFor({ state: 'visible', timeout: 8_000 }).catch(() => {});
  }
  const backToMenu = page.getByTestId('kiosk-cart-empty-cta');
  if (await backToMenu.isVisible().catch(() => false)) {
    await backToMenu.click().catch(() => {});
  } else {
    const back = page.getByTestId('kiosk-cart-back');
    if (await back.isVisible().catch(() => false)) await back.click().catch(() => {});
  }
  await page.waitForTimeout(600);
}

test.describe('W8 borne — 7 catégories composables : wizard total + panier (captures)', () => {
  test.describe.configure({ retries: 0 });

  test('7 catégories : total wizard == base + supplément payant, panier identique', async ({ browser }) => {
    test.setTimeout(600_000);
    fs.mkdirSync(SHOT_DIR, { recursive: true });

    const ctx = await browser.newContext({ viewport: VIEWPORT });
    const page = await ctx.newPage();
    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(String(e && e.message ? e.message : e)));

    const results = [];
    const anomalies = [];

    try {
      await loginAsKiosk(page);
      // idle-root = LE signal « auto-login terminé » (naviguer avant = 401 token en vol).
      await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });

      for (const cfg of CATS) {
        const r = { label: cfg.label, id: cfg.id, expected: cfg.expected, wizard: NaN, recap: NaN, cart: NaN, ok: false, error: null };
        results.push(r);
        try {
          await gotoCategory(page, cfg.cat, cfg.id);

          // — Prix carte produit (info, non bloquant)
          const cardPrice = extractEuro(await page.getByTestId(`kiosk-product-price-${cfg.id}`).innerText().catch(() => ''));
          if (!Number.isNaN(cardPrice) && Math.abs(cardPrice - cfg.base) > 0.005) {
            anomalies.push(`${cfg.slug}: prix carte ${fmt(cardPrice)} ≠ base DB ${fmt(cfg.base)}`);
          }

          // — Wizard : sélections + pick payant + capture wizard-total
          const wiz = await openWizard(page, cfg.id);
          const { wizardShotTaken, footerShown } = await walkWizardToRecap(page, wiz, cfg, anomalies);
          r.wizard = footerShown;
          if (!wizardShotTaken) await snap(page, `${cfg.n}-${cfg.slug}-wizard`);

          // — Récap : total officiel du wizard
          await expect(wiz.getByTestId('kiosk-order-summary-root')).toBeVisible({ timeout: 10_000 });
          await page.waitForTimeout(400);
          r.recap = extractEuro(await wiz.getByTestId('kiosk-order-summary-total-price').innerText());

          // — AJOUTER AU PANIER (jamais de paiement)
          const addBtn = wiz.locator('button').filter({ hasText: /ajouter au panier/i }).first();
          await expect(addBtn).toBeEnabled({ timeout: 8_000 });
          await addBtn.click();
          await page.locator('.kiosk-wizard-overlay').waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
          if (await page.getByTestId('kiosk-upsell-root').isVisible({ timeout: 1_500 }).catch(() => false)) {
            await page.getByTestId('kiosk-upsell-skip').click().catch(() => {});
            await page.waitForTimeout(600);
          }

          // — Panier : ouvrir la vraie page panier, total + options nommées, capture
          await expect(page.getByTestId('kiosk-categories-cart-indicator')).toBeVisible({ timeout: 10_000 });
          await page.getByTestId('kiosk-categories-cart-indicator').click();
          await expect(page.getByTestId('kiosk-cart-root')).toBeVisible({ timeout: 10_000 });
          await page.waitForTimeout(700);
          r.cart = extractEuro(await page.getByTestId('kiosk-cart-total').innerText());
          const optsTxt = (await page.getByTestId('kiosk-cart-item-options-0').innerText().catch(() => '')) || '';
          const wantName = cfg.pick ? cfg.pick.name : (cfg._sauceChosen || '');
          if (wantName && !optsTxt.toLowerCase().includes(wantName.toLowerCase().slice(0, 8))) {
            anomalies.push(`${cfg.slug}: option « ${wantName} » NON lisible dans les options panier (« ${optsTxt.replace(/\s+/g, ' ').slice(0, 120)} »)`);
          }
          await snap(page, `${cfg.n}-${cfg.slug}-panier`);

          // — Asserts numériques (footer wizard + récap + panier == attendu)
          const near = (a, b) => !Number.isNaN(a) && Math.abs(a - b) < 0.005;
          r.ok = near(r.wizard, cfg.expected) && near(r.recap, cfg.expected) && near(r.cart, cfg.expected);
          if (!r.ok) r.error = `totaux wizard=${r.wizard} recap=${r.recap} panier=${r.cart} ≠ attendu ${cfg.expected}`;
          console.log(`[${cfg.n}] ${cfg.label} → wizard=${fmt(r.wizard)} recap=${fmt(r.recap)} panier=${fmt(r.cart)} attendu=${fmt(cfg.expected)} ${r.ok ? 'OK' : 'ÉCART'}`);

          // — RESET : vider le panier (aucune commande, aucun cumul inter-catégorie)
          await clearCartFromCartPage(page);
        } catch (err) {
          r.error = String(err && err.message ? err.message : err).slice(0, 300);
          console.log(`[${cfg.n}] ${cfg.label} ÉCHEC: ${r.error}`);
          await snap(page, `${cfg.n}-${cfg.slug}-ERROR`);
          await recoverCleanState(page);
        }
      }

      // Sécurité finale : panier vide, rien en vol.
      await recoverCleanState(page);

      if (pageErrors.length) console.log(`[pageerror] ${pageErrors.join(' | ')}`);
      if (anomalies.length) console.log(`[ANOMALIES]\n - ${anomalies.join('\n - ')}`);
      const table = results
        .map((r) => `${r.ok ? '✓' : '✗'} ${r.label} (item ${r.id}) attendu=${fmt(r.expected)} wizard=${Number.isNaN(r.wizard) ? '—' : fmt(r.wizard)} recap=${Number.isNaN(r.recap) ? '—' : fmt(r.recap)} panier=${Number.isNaN(r.cart) ? '—' : fmt(r.cart)}${r.error ? ` [${r.error}]` : ''}`)
        .join('\n');
      console.log(`\n=== TABLEAU FINAL ===\n${table}`);

      const failed = results.filter((r) => !r.ok);
      expect(failed, `catégories en échec:\n${table}`).toHaveLength(0);
    } finally {
      await ctx.close();
    }
  });
});
