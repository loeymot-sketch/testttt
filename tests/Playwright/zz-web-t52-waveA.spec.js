// zz-web-t52-waveA.spec.js — Wave A capture LECTURE SEULE du site web Le Cayenne
// Site statique servi sur http://127.0.0.1:8899 (repo lecayenne-web-deploy/Site lecayenne).
// AUCUNE commande créée, AUCUN paiement, AUCUN ajout panier — le wizard est parcouru
// jusqu'au récap mais « Ajouter au panier » n'est JAMAIS cliqué.
// Run : PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8899 npx playwright test tests/Playwright/zz-web-t52-waveA.spec.js

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/web-t52-2026-08-05/round-ACTIVE/waveA';
const BASE = 'http://127.0.0.1:8899/';

test.use({ viewport: { width: 1366, height: 768 } });
test.describe.configure({ timeout: 300_000 });

// ---- collecte console + réseau ----
const NOISE = [
  /unpkg\.com/i, /fonts\.g(oogleapis|static)\.com/i, /favicon/i,
  /in-browser Babel transformer/i, /babel/i, /React DevTools/i,
  /net::ERR_BLOCKED_BY_CLIENT/i,
];
function isNoise(txt) { return NOISE.some(r => r.test(txt)); }

const manifest = { generated: new Date().toISOString(), base: BASE, states: [], keyObservations: {} };

function makeCollectors(page) {
  const consoleErrors = [];
  const badResponses = [];
  page.on('console', msg => {
    const t = msg.type();
    if (t !== 'error' && t !== 'warning') return;
    const text = msg.text();
    if (isNoise(text)) return;
    consoleErrors.push({ type: t, text: text.slice(0, 500) });
  });
  page.on('pageerror', err => consoleErrors.push({ type: 'pageerror', text: String(err).slice(0, 500) }));
  page.on('response', resp => {
    if (resp.status() >= 400 && !isNoise(resp.url())) {
      badResponses.push({ status: resp.status(), url: resp.url() });
    }
  });
  page.on('requestfailed', req => {
    if (isNoise(req.url())) return;
    badResponses.push({ status: 'FAILED:' + (req.failure() || {}).errorText, url: req.url() });
  });
  return { consoleErrors, badResponses };
}

let drained = { consoleErrors: [], badResponses: [] };
function drain(coll) {
  const snap = { consoleErrors: coll.consoleErrors.splice(0), badResponses: coll.badResponses.splice(0) };
  return snap;
}

async function capture(page, coll, name, notes) {
  const png = path.join(OUT, name + '.png');
  await page.screenshot({ path: png }); // viewport only
  const snap = drain(coll);
  const state = { state: name, url: page.url(), consoleErrors: snap.consoleErrors, badResponses: snap.badResponses, notes };
  fs.writeFileSync(path.join(OUT, name + '.json'), JSON.stringify(state, null, 2));
  manifest.states.push({ state: name, notes, consoleErrorCount: snap.consoleErrors.length, badResponseCount: snap.badResponses.length });
  return state;
}

// Avance un wizard ouvert (.lc-wiz) en capturant chaque étape ; N'AJOUTE JAMAIS au panier.
async function walkWizard(page, coll, prefix, obsBucket) {
  const wiz = page.locator('.lc-wiz');
  await expect(wiz).toBeVisible({ timeout: 15000 });
  const steps = [];
  for (let stepNo = 1; stepNo <= 15; stepNo++) {
    await page.waitForTimeout(600);
    const title = (await wiz.locator('.lc-wiz-title').first().innerText().catch(() => '')).trim();
    const text = (await wiz.innerText().catch(() => '')).replace(/\n{3,}/g, '\n\n');
    const nextBtn = wiz.locator('.lc-wiz-foot-next');
    const nextLabel = (await nextBtn.innerText().catch(() => '')).trim();
    const isRecap = /Ajouter au panier/i.test(nextLabel);
    const name = `${prefix}-step${stepNo}`;
    const st = await capture(page, coll, name, `Étape ${stepNo} — titre: "${title}" — bouton: "${nextLabel.split('\n')[0]}"`);
    st.stepTitle = title;
    st.stepText = text;
    st.isRecap = isRecap;
    fs.writeFileSync(path.join(OUT, name + '.json'), JSON.stringify(st, null, 2));
    steps.push({ stepNo, title, isRecap, text });
    if (isRecap) break; // récap atteint — on N'AJOUTE PAS au panier
    // si le bouton est désactivé, sélectionner des choix jusqu'à débloquer
    for (let tries = 0; tries < 6; tries++) {
      const disabled = await nextBtn.isDisabled().catch(() => false);
      if (!disabled) break;
      const choice = wiz.locator('.lc-wiz-choice:not(.is-on)').first();
      if (!(await choice.count())) break;
      await choice.click().catch(() => {});
      await page.waitForTimeout(250);
    }
    if (await nextBtn.isDisabled().catch(() => false)) {
      steps.push({ stepNo: stepNo + 1, title: 'BLOQUÉ — bouton Continuer resté désactivé', isRecap: false, text: '' });
      break;
    }
    await nextBtn.click();
  }
  // fermeture SANS ajout : Escape puis bouton close éventuel
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(400);
  if (await wiz.isVisible().catch(() => false)) {
    // remonter au step 1 puis flèche retour = fermeture
    const back = wiz.locator('.lc-wiz-foot-back');
    for (let i = 0; i < 16 && (await wiz.isVisible().catch(() => false)); i++) {
      await back.click().catch(() => {});
      await page.waitForTimeout(200);
    }
  }
  obsBucket.steps = steps.map(s => ({ stepNo: s.stepNo, title: s.title, isRecap: s.isRecap }));
  return steps;
}

async function openItemWizard(page, coll, catLabel, itemNameRe, prefix) {
  // catégorie (sidebar desktop)
  const catBtn = page.locator('.lc-menu-side-link', { hasText: catLabel }).first();
  if (await catBtn.count()) { await catBtn.click(); await page.waitForTimeout(600); }
  const card = page.locator('.lc-card-item:not(.is-soldout)', { hasText: itemNameRe }).first();
  await expect(card).toBeVisible({ timeout: 10000 });
  const cardName = (await card.innerText()).split('\n')[0];
  await card.click();
  // fiche détail
  const detail = page.locator('.lc-detail');
  await expect(detail).toBeVisible({ timeout: 10000 });
  await page.waitForTimeout(500);
  await capture(page, coll, `${prefix}-detail`, `Fiche produit ouverte (${cardName})`);
  const custom = detail.locator('button', { hasText: 'Personnaliser' }).first();
  await expect(custom).toBeVisible({ timeout: 5000 });
  await custom.click();
  return cardName;
}

test('Wave A — vitrine, menu, wizard tacos, wizard crudités (lecture seule)', async ({ page }) => {
  fs.mkdirSync(OUT, { recursive: true });
  const coll = makeCollectors(page);

  // 1. Accueil / vitrine
  await page.goto(BASE, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.lc-nav')).toBeVisible({ timeout: 45000 });
  await page.waitForTimeout(2500);
  await capture(page, coll, 'A01-accueil', 'Accueil / vitrine — route par défaut');

  // 2. Menu / catégories
  await page.locator('.lc-nav-link', { hasText: 'Menu' }).first().click();
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(1200);
  const sideCats = await page.locator('.lc-menu-side-link').allInnerTexts().catch(() => []);
  const tabCats = await page.locator('.lc-cat-tab').allInnerTexts().catch(() => []);
  const cats = [...new Set([...sideCats, ...tabCats].map(s => s.trim()).filter(Boolean))];
  const menuState = await capture(page, coll, 'A02-menu', 'Menu — catégories visibles listées dans .categories');
  menuState.categories = cats;
  fs.writeFileSync(path.join(OUT, 'A02-menu.json'), JSON.stringify(menuState, null, 2));
  manifest.keyObservations.categories = cats;

  // 3. Wizard TACOS
  const tacosObs = {};
  const tacosName = await openItemWizard(page, coll, 'Tacos', /Tacos/i, 'A03-tacos');
  const tacosSteps = await walkWizard(page, coll, 'A03-tacos', tacosObs);
  const tacosAll = tacosSteps.map(s => (s.title + '\n' + s.text)).join('\n---\n');
  manifest.keyObservations.tacos = {
    item: tacosName,
    steps: tacosObs.steps,
    cruditesStepPresent: /crudit/i.test(tacosAll),
    galetteMentioned: /galette/i.test(tacosAll),
  };

  // 4. Wizard produit à crudités (Cayenne)
  const crudObs = {};
  const crudName = await openItemWizard(page, coll, 'Sandwich', /Cayenne/i, 'A04-crudites');
  const crudSteps = await walkWizard(page, coll, 'A04-crudites', crudObs);
  const crudStep = crudSteps.find(s => /crudit/i.test(s.title) || /crudit/i.test(s.text.split('\n').slice(0, 3).join(' ')));
  const crudText = crudStep ? crudStep.text : crudSteps.map(s => s.text).join('\n');
  manifest.keyObservations.crudites = {
    item: crudName,
    steps: crudObs.steps,
    cruditesStepTitle: crudStep ? crudStep.title : null,
    hasPoivronsCuits: /poivron/i.test(crudText),
    hasMais: /ma[iï]s/i.test(crudText),
    hasOlives: /olive/i.test(crudText),
    hasSansCrudites: /sans crudit/i.test(crudText),
    cruditesRawText: crudStep ? crudStep.text.slice(0, 2000) : null,
  };

  fs.writeFileSync(path.join(OUT, 'waveA-manifest.json'), JSON.stringify(manifest, null, 2));
});
