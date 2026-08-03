// [test-e2e goal4-predeploy-2026-07-17] Wave A — BORNE (Round 1, GStack capture).
// États A01→A10 du plan reports/test-e2e/goal4-predeploy-2026-07-17/PLAN.md.
// Quartet d'artefacts (png + dom.html + console.json + network.json) par état
// via helpers/mega-audit-snap.js → tests/e2e/__screenshots__/test-e2e-A/.
// Viewport borne 1080×1920. AUCUN paiement finalisé (cart localStorage only).
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/_teste2e-goal4-waveA.spec.js --reporter=line
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'test-e2e-A');
const VIEWPORT = { width: 1080, height: 1920 };

const CAT_KIDS = 11;
const CAT_BOLS = 6;
const ID_NUGGETS = 40;
const ID_KIDS_BURGER = 106;
const ID_BOL_FRITES = 41;

/** Contexte borne + recorder quartet. */
async function openKiosk(browser) {
  const ctx = await browser.newContext({ viewport: VIEWPORT });
  const page = await ctx.newPage();
  const recorder = attachMegaAuditRecorder(page, SHOT_DIR);
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(String(e && e.message ? e.message : e)));
  await loginAsKiosk(page);
  // CRITIQUE anti-flaky : loginAsKiosk retourne AVANT la fin de l'auto-login
  // (KioskLoginComponent.startAutoLogin = spinner, pas de formulaire). Naviguer
  // tout de suite interrompt l'auth en vol → aucun token stocké → 401
  // « Session expirée » au premier appel API. L'idle-root est LE signal
  // « login terminé » — on l'attend toujours ici.
  await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
  return { ctx, page, snap: recorder.snap, dispose: recorder.dispose, pageErrors };
}

/**
 * Désarme l'overlay d'inactivité borne s'il est apparu (les attentes longues
 * sous serveur dev chargé par les vagues parallèles déclenchent le countdown
 * « Êtes-vous toujours là ? » → auto-retour idle qui tue le parcours).
 */
async function dismissInactivityIfShown(page) {
  const stay = page.getByTestId('kiosk-inactivity-stay');
  if (await stay.isVisible().catch(() => false)) {
    console.log('[ANOMALIE] overlay inactivité borne apparu pendant la capture — clic « Je suis là »');
    await stay.click().catch(() => {});
    await page.waitForTimeout(400);
  }
}

/** Si la bannière « Session expirée » est affichée, clique « Se reconnecter ». */
async function reconnectIfExpired(page) {
  const btn = page.locator('button, a').filter({ hasText: /se reconnecter/i }).first();
  if (await btn.isVisible({ timeout: 800 }).catch(() => false)) {
    console.log('[ANOMALIE] bannière « Session expirée » vue sur la borne — clic Se reconnecter');
    await btn.click().catch(() => {});
    await page.getByTestId('kiosk-idle-root').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(600);
  }
}

/**
 * Idle → mode commande (à emporter) si la borne est sur l'écran d'accueil.
 * ⚠️ locator.isVisible({timeout}) N'ATTEND PAS (check instantané, timeout
 * ignoré par Playwright) — utiliser waitFor pour les attentes réelles.
 */
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

/**
 * Navigue vers une catégorie et attend une carte produit sentinelle.
 * Tolère un bounce idle/« Session expirée » (token kiosk révoqué par un
 * relogin concurrent — agents parallèles sur le même compte borne) : se
 * reconnecte et retente une fois.
 */
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
    console.log(`[gotoCategory] cat=${catId} tentative ${attempt + 1} — carte ${mustSeeCardId} absente après 15s, retry`);
  }
  await expect(page.getByTestId(`kiosk-product-card-${mustSeeCardId}`)).toBeVisible({ timeout: 10_000 });
}

/**
 * Ferme le wizard borne SANS ajouter au panier — séquence documentée au plan :
 * « ABANDONNER L'ARTICLE » (regex /abandonner l/i, robuste apostrophe ' vs ’,
 * ne matche JAMAIS « Abandonner ma commande ») PUIS confirmation
 * .kiosk-wizard-abandon-yes (l'overlay de confirmation intercepte tout clic).
 */
async function closeWizard(page) {
  const btn = page.locator('button').filter({ hasText: /abandonner l/i }).first();
  const btnSeen = await btn
    .waitFor({ state: 'visible', timeout: 2_000 })
    .then(() => true)
    .catch(() => false);
  if (btnSeen) {
    await btn.click().catch(() => {});
    // L'overlay de confirmation ANIME son apparition — vrai waitFor obligatoire.
    const yes = page.locator('.kiosk-wizard-abandon-yes').first();
    const yesSeen = await yes
      .waitFor({ state: 'visible', timeout: 4_000 })
      .then(() => true)
      .catch(() => false);
    if (yesSeen) await yes.click().catch(() => {});
  }
  await page.locator('.kiosk-wizard-overlay').waitFor({ state: 'hidden', timeout: 8_000 }).catch(() => {});
  await page.waitForTimeout(300);
}

/** Ouvre le wizard d'un produit et attend l'overlay. Retourne le locator overlay. */
async function openWizard(page, productId) {
  await dismissInactivityIfShown(page);
  await page.getByTestId(`kiosk-product-add-${productId}`).click();
  const wiz = page.locator('.kiosk-wizard-overlay');
  await expect(wiz).toBeVisible({ timeout: 15_000 });
  // Fidélité de capture : l'overlay wizard ANIME un slide-up (~500ms). Sans
  // settle, le snap fige le wizard à moitié émergé en bas d'écran (les
  // assertions DOM passent mais le PNG ne montre pas l'étape — payé sur
  // A04/A06 au round 1). Attendre le montage d'un step PUIS la fin d'anim.
  await wiz.locator(STEP_ROOTS).first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
  await page.waitForTimeout(750);
  return wiz;
}

/** Clique SUIVANT (jamais le bouton AJOUTER AU PANIER) après attente enabled. */
async function clickSuivant(page, wiz) {
  await dismissInactivityIfShown(page);
  const next = wiz.locator('button').filter({ hasText: /^suivant$/i }).first();
  await expect(next).toBeVisible({ timeout: 8_000 });
  await expect(next).toBeEnabled({ timeout: 8_000 });
  await next.click();
}

/**
 * Avance dans le wizard jusqu'à ce que stopSelector (racine STRUCTURELLE du
 * step cible, ex. `.kiosk-step-supplements`) soit visible dans l'overlay.
 * ⚠️ Ne JAMAIS matcher le texte de l'overlay entier pour détecter une étape :
 * le strip de progression du wizard affiche les prompts de TOUTES les étapes
 * (kiosk.wizard.prompt.<type>) → « QUEL SUPPLÉMENT » y est lisible dès le step
 * viande (faux positif payé au round 1).
 * Min-select satisfaits en route : carte viande sélectionnable (les cartes
 * div.kiosk-viande-card sont cliquables — PAS de bouton CHOISIR sur ce step),
 * tuile sauce Mayonnaise, sinon bouton CHOISIR générique.
 */
const STEP_ROOTS = [
  '.kiosk-step-viande',
  '.kiosk-step-sauce',
  '.kiosk-step-garnitures',
  '.kiosk-step-supplements',
  '.kiosk-step-pain',
  '.kiosk-step-taille',
  '.kiosk-step-menu',
  '[data-testid="kiosk-order-summary-root"]',
].join(', ');

async function advanceToStep(page, wiz, stopSelector, maxSteps = 8) {
  const atStop = () => wiz.locator(stopSelector).first().isVisible().catch(() => false);
  for (let i = 0; i < maxSteps; i++) {
    await dismissInactivityIfShown(page);
    await expect(wiz).toBeVisible({ timeout: 8_000 });
    // Synchro : attendre qu'UNE racine de step soit montée (transition out-in
    // finie) — évite de cliquer SUIVANT pendant l'animation et de sauter un step.
    await wiz.locator(STEP_ROOTS).first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
    await page.waitForTimeout(300); // fin d'animation
    if (await atStop()) return;
    const viandeCard = wiz.locator('.kiosk-step-viande .kiosk-viande-card.is-selectable').first();
    // ⚠️ La liste des sauces varie par produit : Nuggets = 12 classiques (dont
    // Mayonnaise), Bol Frites = 2 spécifiques (Fromagère maison / Spicy).
    // Mayonnaise si présente, SINON la 1re tuile sauce disponible.
    const anySauce = wiz.locator('.kiosk-step-sauce .kiosk-option-card').first();
    const mayo = wiz.locator('.kiosk-step-sauce .kiosk-option-card').filter({ hasText: /mayonnaise/i }).first();
    if (await viandeCard.isVisible({ timeout: 500 }).catch(() => false)) {
      await viandeCard.click().catch(() => {});
      await page.waitForTimeout(400);
    } else if (await anySauce.isVisible({ timeout: 500 }).catch(() => false)) {
      const target = (await mayo.isVisible({ timeout: 300 }).catch(() => false)) ? mayo : anySauce;
      await target.click().catch(() => {});
      await page.waitForTimeout(400);
    } else {
      const choisir = wiz.locator('button').filter({ hasText: /^choisir$/i }).first();
      if (await choisir.isVisible({ timeout: 400 }).catch(() => false)) {
        await choisir.click().catch(() => {});
        await page.waitForTimeout(400);
      }
    }
    if (await atStop()) return;
    const next = wiz.locator('button').filter({ hasText: /^suivant$/i }).first();
    if (!(await next.isVisible({ timeout: 800 }).catch(() => false))) break;
    await expect(next).toBeEnabled({ timeout: 8_000 });
    await next.click();
  }
  if (await wiz.locator(stopSelector).first().isVisible({ timeout: 1_500 }).catch(() => false)) return;
  const finalTxt = (await wiz.innerText().catch(() => '')) || '';
  throw new Error(`Étape ${stopSelector} jamais atteinte — dernier texte overlay: ${finalTxt.replace(/\s+/g, ' ').slice(0, 400)}`);
}

test.describe('test-e2e goal4 Wave A — BORNE A01→A10', () => {
  test.describe.configure({ timeout: 300_000, retries: 0 });

  test('A01→A05 — idle, catégories, grille enfant, wizard Nuggets sauce → récap 4,90', async ({ browser }) => {
    const { ctx, page, snap, dispose, pageErrors } = await openKiosk(browser);
    try {
      // ── A01 idle
      await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
      await expect(page.getByTestId('kiosk-order-type-takeaway')).toBeVisible({ timeout: 10_000 });
      // Stabilité capture : une ré-auth concurrente (agents parallèles sur le
      // MÊME compte borne → révocation token → re-login auto) peut renvoyer
      // l'app au spinner « Démarrage en cours... » juste après l'idle. Settle
      // + re-check avant snap, sinon le PNG fige le spinner (payé au round 2).
      await page.waitForTimeout(1_200);
      if (!(await page.getByTestId('kiosk-idle-root').isVisible().catch(() => false))) {
        console.log('[ANOMALIE] idle perdu juste après login (ré-auth concurrente kiosk) — attente retour idle');
        await reconnectIfExpired(page);
        await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });
        await page.waitForTimeout(800);
      }
      await snap('A01-idle');

      // ── A02 catégories (tuiles sidebar = vignettes webp)
      await page.getByTestId('kiosk-order-type-takeaway').click();
      await expect(page.getByTestId('kiosk-categories-root')).toBeVisible({ timeout: 25_000 });
      await expect(page.getByTestId('kiosk-categories-sidebar')).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('.kiosk-sidebar-item').first()).toBeVisible({ timeout: 15_000 });
      const sidebarCount = await page.locator('.kiosk-sidebar-item').count();
      expect(sidebarCount, 'sidebar catégories doit lister plusieurs tuiles').toBeGreaterThanOrEqual(5);
      // vignettes : au moins une image webp chargée (mandat images détourées)
      await page.waitForTimeout(600); // laisser les thumbs lazy arriver
      const webpCount = await page.locator('img.kiosk-sidebar-thumb[src*=".webp"]').count();
      const thumbCount = await page.locator('img.kiosk-sidebar-thumb').count();
      console.log(`[A02] tuiles sidebar=${sidebarCount} thumbs img=${thumbCount} dont webp=${webpCount}`);
      expect(webpCount, 'tuiles catégories = vignettes webp attendues').toBeGreaterThanOrEqual(1);
      await snap('A02-categories');

      // ── A03 grille menu-enfant (image poulet sur la tuile 106 + prix 4,90)
      await gotoCategory(page, CAT_KIDS, ID_KIDS_BURGER);
      const imgSrc = await page
        .getByTestId(`kiosk-product-card-${ID_KIDS_BURGER}`)
        .locator('img').first().getAttribute('src').catch(() => null);
      expect(String(imgSrc), 'tuile kids-burger doit porter le visuel poulet').toContain('chicken_burger');
      await expect(page.getByTestId(`kiosk-product-price-${ID_NUGGETS}`)).toContainText('4,90', { timeout: 10_000 });
      await expect(page.getByTestId(`kiosk-product-price-${ID_KIDS_BURGER}`)).toContainText('4,90', { timeout: 10_000 });
      await snap('A03-grille-menu-enfant');

      // ── A04 wizard Nuggets — étape sauce (12 tuiles)
      const wiz = await openWizard(page, ID_NUGGETS);
      await expect(wiz.locator('.kiosk-step-sauce').first()).toBeVisible({ timeout: 10_000 });
      await expect(wiz.locator('.kiosk-sauce-grid .kiosk-option-card').first()).toBeVisible({ timeout: 10_000 });
      const sauceCount = await wiz.locator('.kiosk-sauce-grid .kiosk-option-card').count();
      console.log(`[A04] tuiles sauce=${sauceCount}`);
      expect(sauceCount, 'étape sauce Nuggets doit proposer 12 tuiles').toBe(12);
      await snap('A04-wizard-nuggets-sauce');

      // ── A05 sauce sélectionnée + SUIVANT → récap 4,90
      await wiz.locator('.kiosk-sauce-grid .kiosk-option-card').filter({ hasText: /mayonnaise/i }).first().click();
      await clickSuivant(page, wiz);
      await expect(wiz.getByTestId('kiosk-order-summary-root')).toBeVisible({ timeout: 10_000 });
      await expect(wiz.getByTestId('kiosk-order-summary-total-price')).toContainText('4,90', { timeout: 10_000 });
      await snap('A05-nuggets-recap-490');

      await closeWizard(page);
      if (pageErrors.length) console.log(`[A01-A05][ANOMALIE pageerror] ${pageErrors.join(' | ')}`);
    } finally {
      dispose();
      await ctx.close();
    }
  });

  test('A06→A08 — wizard kids-burger crudités → suppléments 0,90, grille bols', async ({ browser }) => {
    const { ctx, page, snap, dispose, pageErrors } = await openKiosk(browser);
    try {
      await gotoCategory(page, CAT_KIDS, ID_KIDS_BURGER);

      // ── A06 wizard kids-burger — crudités (Salade/Tomate/Oignon)
      const wiz = await openWizard(page, ID_KIDS_BURGER);
      const garnStep = wiz.locator('.kiosk-step-garnitures').first();
      await expect(garnStep).toBeVisible({ timeout: 10_000 });
      await expect(garnStep.getByText(/salade/i).first()).toBeVisible({ timeout: 10_000 });
      const cruditesTxt = (await garnStep.innerText().catch(() => '')) || '';
      expect(cruditesTxt).toMatch(/Salade/i);
      expect(cruditesTxt).toMatch(/Tomate/i);
      expect(cruditesTxt).toMatch(/Oignon/i);
      await snap('A06-wizard-kidsburger-crudites');

      // ── A07 étape suppléments (0,90)
      await clickSuivant(page, wiz);
      const suppStep = wiz.locator('.kiosk-step-supplements').first();
      await expect(suppStep).toBeVisible({ timeout: 10_000 });
      await expect(suppStep.locator('.kiosk-supplement-row').first()).toBeVisible({ timeout: 10_000 });
      const suppTxt = (await suppStep.innerText().catch(() => '')) || '';
      expect(suppTxt, 'suppléments kids-burger à 0,90 attendus').toMatch(/0,90/);
      await snap('A07-kidsburger-supplements-090');
      await closeWizard(page);

      // ── A08 grille bols
      await gotoCategory(page, CAT_BOLS, ID_BOL_FRITES);
      await expect(page.getByTestId(`kiosk-product-card-${ID_BOL_FRITES}`)).toBeVisible({ timeout: 15_000 });
      await snap('A08-grille-bols');
      if (pageErrors.length) console.log(`[A06-A08][ANOMALIE pageerror] ${pageErrors.join(' | ')}`);
    } finally {
      dispose();
      await ctx.close();
    }
  });

  test('A09 — wizard Bol Frites jusqu\'à QUEL SUPPLÉMENT (Option Gratiné 2,00)', async ({ browser }) => {
    const { ctx, page, snap, dispose, pageErrors } = await openKiosk(browser);
    try {
      await gotoCategory(page, CAT_BOLS, ID_BOL_FRITES);
      const wiz = await openWizard(page, ID_BOL_FRITES);
      // viande (carte cliquable) → sauce Mayonnaise → step suppléments (structurel)
      await advanceToStep(page, wiz, '.kiosk-step-supplements', 8);
      const suppStep = wiz.locator('.kiosk-step-supplements').first();
      const gratine = suppStep.getByText(/option gratin/i).first();
      // toBeVisible ≠ dans le viewport : la tuile gratiné est sous la ligne de
      // flottaison en 1080×1920 → scroller pour que le PNG la montre vraiment,
      // et laisser finir le fade-in du step (capture mi-fade payée au round 1).
      await expect(gratine).toBeVisible({ timeout: 8_000 });
      await gratine.scrollIntoViewIfNeeded().catch(() => {});
      await page.waitForTimeout(600);
      const suppTxt = (await suppStep.innerText().catch(() => '')) || '';
      expect(suppTxt, 'Option Gratiné attendue à l\'étape suppléments du Bol Frites').toMatch(/Option Gratin/i);
      expect(suppTxt, 'prix gratiné 2,00 attendu').toMatch(/2,00/);
      await snap('A09-bolfrites-quel-supplement-gratine-200');
      await closeWizard(page);
      if (pageErrors.length) console.log(`[A09][ANOMALIE pageerror] ${pageErrors.join(' | ')}`);
    } finally {
      dispose();
      await ctx.close();
    }
  });

  test('A10 — ajout Nuggets au panier → indicateur panier 4,90 (AUCUN paiement)', async ({ browser }) => {
    const { ctx, page, snap, dispose, pageErrors } = await openKiosk(browser);
    try {
      await gotoCategory(page, CAT_KIDS, ID_NUGGETS);
      const wiz = await openWizard(page, ID_NUGGETS);
      // sauce → SUIVANT → récap → AJOUTER AU PANIER
      await expect(wiz.locator('.kiosk-step-sauce').first()).toBeVisible({ timeout: 10_000 });
      await wiz.locator('.kiosk-sauce-grid .kiosk-option-card').filter({ hasText: /mayonnaise/i }).first().click();
      await clickSuivant(page, wiz);
      await expect(wiz.getByTestId('kiosk-order-summary-root')).toBeVisible({ timeout: 10_000 });
      const addBtn = wiz.locator('button').filter({ hasText: /ajouter au panier/i }).first();
      await expect(addBtn).toBeVisible({ timeout: 8_000 });
      await expect(addBtn).toBeEnabled({ timeout: 8_000 });
      await addBtn.click();
      await page.locator('.kiosk-wizard-overlay').waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
      // Si l'upsell post-cart s'affiche, le passer (kiosk-upsell-skip) — on veut le panier.
      if (await page.getByTestId('kiosk-upsell-root').isVisible({ timeout: 1_500 }).catch(() => false)) {
        await page.getByTestId('kiosk-upsell-skip').click().catch(() => {});
        await page.waitForTimeout(600);
      }
      await expect(page.getByTestId('kiosk-categories-cart-indicator')).toBeVisible({ timeout: 10_000 });
      await expect(page.getByTestId('kiosk-categories-cart-total')).toContainText('4,90', { timeout: 10_000 });
      await snap('A10-panier-nuggets-490');
      // NE PAS payer — le panier reste localStorage, aucun ordre fiscal créé.
      if (pageErrors.length) console.log(`[A10][ANOMALIE pageerror] ${pageErrors.join(' | ')}`);
    } finally {
      dispose();
      await ctx.close();
    }
  });
});
