// ============================================================================
// AUDIT A — MONEY-PATH LIVE (Vercel site-lecayenne.vercel.app → VPS backend)
// GStack capture + adversarial. Exploratoire : on CAPTURE l'état réel du DOM à
// chaque étape (choix/prix/badges/is-on/total/erreur) + screenshots + console +
// network, on écrit les observations en JSON, PUIS on juge (findings faits hors-spec).
// Rien n'est réparé. Assertions surtout .soft pour laisser toutes les captures finir.
// Run : PLAYWRIGHT_BASE_URL=https://site-lecayenne.vercel.app \
//         npx playwright test tests/e2e/audit-A-moneypath-2026-07-21.spec.js --project=chromium
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT = path.join(__dirname, '__screenshots__', 'audit-A-moneypath');
fs.mkdirSync(SHOT, { recursive: true });
const shot = (n) => path.join(SHOT, n);
const saveObs = (name, data) => fs.writeFileSync(path.join(SHOT, `obs-${name}.json`), JSON.stringify(data, null, 2));

test.describe.configure({ retries: 0 });
test.setTimeout(160_000);

const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };
const AUDIT_PHONE = '0699000721';

// --- console + network trackers attachables ------------------------------
function track(page) {
  const consoleErrors = [];
  const netProblems = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 240)); });
  page.on('response', (r) => { const s = r.status(); if (s >= 400) netProblems.push({ status: s, url: r.url().slice(0, 160) }); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.message).slice(0, 200)));
  return { consoleErrors, netProblems };
}

async function gotoDev(page) {
  let lastErr = null;
  for (let i = 0; i < 3; i++) {
    try { await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); lastErr = null; break; }
    catch (e) { lastErr = e; await page.waitForTimeout(3_000); }
  }
  if (lastErr) throw lastErr;
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(600);
}
async function openMenu(page) {
  await page.keyboard.press('Escape').catch(() => {});
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible({ timeout: 3_000 }).catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click().catch(() => {}); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click().catch(() => {}); }
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(400);
}
// Ouvre le wizard du 1er produit dont le nom matche nameRe (carte→détail→Personnaliser).
async function openWizard(page, nameRe) {
  const cards = page.locator('[aria-label^="Voir "]');
  const n = await cards.count();
  for (let i = 0; i < n; i++) {
    const label = (await cards.nth(i).getAttribute('aria-label').catch(() => '')) || '';
    const name = label.replace(/^Voir\s+/, '').trim();
    if (!nameRe.test(name)) continue;
    await cards.nth(i).scrollIntoViewIfNeeded().catch(() => {});
    await cards.nth(i).click().catch(() => {});
    const detail = page.locator('.lc-detail');
    if (!(await detail.isVisible({ timeout: 3_000 }).catch(() => false))) continue;
    const perso = detail.getByRole('button', { name: /Personnaliser/ });
    if (await perso.isVisible({ timeout: 1_500 }).catch(() => false)) {
      await perso.click();
      if (await page.locator('.lc-wiz').isVisible({ timeout: 6_000 }).catch(() => false)) return name;
    } else {
      // pas de perso → produit simple, on referme
      await page.keyboard.press('Escape').catch(() => {});
    }
    await page.waitForTimeout(200);
  }
  return null;
}
// Lit l'état COMPLET de l'étape wizard courante — un SEUL page.evaluate (jamais de
// hang sur élément absent, contrairement à locator.innerText avec actionTimeout=0).
async function readStep(page) {
  const r = await page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    const t = (e) => e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : '';
    const errEl = q('.lc-wiz-body [role="alert"]') || q('.lc-wiz [role="alert"]');
    const nextBtn = q('.lc-wiz-foot-next');
    const choices = Array.from(document.querySelectorAll('.lc-wiz-choice')).map((c, i) => {
      const full = String(c.innerText || '').replace(/\s+/g, ' ').trim();
      const nameEl = c.querySelector('.lc-wiz-choice-name');
      const priceEl = c.querySelector('.lc-wiz-choice-price');
      return { i, name: nameEl ? nameEl.innerText.trim() : full.slice(0, 40),
        priceBadge: priceEl ? priceEl.innerText.trim() : '',
        on: /is-on/.test(c.className), hasInclus: /inclus/i.test(full), full: full.slice(0, 90) };
    });
    return { title: t(q('.lc-wiz-title')), eyebrow: t(q('.lc-wiz-eyebrow')), sub: t(q('.lc-wiz-sub')),
      totalTxt: t(q('.lc-wiz-foot-next-total')), nextTxt: t(nextBtn), nextDisabled: nextBtn ? !!nextBtn.disabled : false,
      err: errEl ? t(errEl) : '', choices };
  }).catch(() => ({ title: '', eyebrow: '', sub: '', totalTxt: '', nextTxt: '', nextDisabled: false, err: '', choices: [] }));
  return { ...r, total: euro(r.totalTxt) };
}
async function advance(page) {
  // s'assure d'une sélection suffisante puis clique Continuer
  for (let t = 0; t < 5; t++) {
    if (!(await page.locator('.lc-wiz-foot-next').isDisabled().catch(() => false))) break;
    const cs = page.locator('.lc-wiz-choice');
    const cn = await cs.count();
    let clicked = false;
    for (let i = 0; i < cn; i++) {
      const cls = (await cs.nth(i).getAttribute('class').catch(() => '')) || '';
      if (!/is-on/.test(cls)) { await cs.nth(i).click().catch(() => {}); clicked = true; break; }
    }
    if (!clicked) break;
    await page.waitForTimeout(200);
  }
  await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
  await page.waitForTimeout(350);
}
function isRecap(step) { return /Ajouter au panier/i.test(step.nextTxt || ''); }

// ========================================================================
// SCÉNARIO 1 + walk complet — Cayenne : sauce (Fromagère 1re/Incluse/pré-sél + 2e sauce +0,50)
// ========================================================================
test('A1 — Cayenne wizard : walk complet + étape SAUCE (Incluse + 2e sauce +0,50)', async ({ page }) => {
  const t = track(page);
  await gotoDev(page); await openMenu(page);
  const prod = await openWizard(page, /Cayenne/i);
  const obs = { prod, steps: [], sauce: null, consoleErrors: t.consoleErrors, netProblems: t.netProblems };
  expect.soft(prod, 'produit Cayenne ouvert en wizard').toBeTruthy();
  if (!prod) { saveObs('A1', obs); await page.screenshot({ path: shot('A1-00-no-cayenne.png'), fullPage: true }); return; }

  for (let s = 0; s < 14; s++) {
    const step = await readStep(page);
    obs.steps.push({ s, title: step.title, total: step.total, choices: step.choices.map(c => ({ name: c.name, price: c.priceBadge, on: c.on, inclus: c.hasInclus })) });
    await page.screenshot({ path: shot(`A1-step${String(s).padStart(2, '0')}-${(step.title || 'x').replace(/[^a-z0-9]+/gi, '-').slice(0, 24)}.png`), fullPage: true });

    // ---- étape SAUCE : le cœur du scénario 1 ----
    if (/sauce/i.test(step.title) && !obs.sauce) {
      const T0 = step.total;
      const first = step.choices[0] || {};
      // pré-état
      const preSelectedIdx = step.choices.findIndex(c => c.on);
      // tenter d'ajouter une 2e sauce : cliquer la 1re option NON sélectionnée
      const addIdx = step.choices.findIndex(c => !c.on);
      let after = step;
      if (addIdx >= 0) {
        await page.locator('.lc-wiz-choice').nth(addIdx).click().catch(() => {});
        await page.waitForTimeout(400);
        after = await readStep(page);
      }
      await page.screenshot({ path: shot('A1-sauce-after-2nd.png'), fullPage: true });
      const addedChoice = after.choices[addIdx] || {};
      obs.sauce = {
        firstOptionName: first.name, firstIsFromagere: /fromag/i.test(first.name || ''),
        firstPreSelected: !!first.on, firstHasInclusBadge: !!first.hasInclus,
        preSelectedIdx, sauceStepTitle: step.title, sauceSub: step.sub,
        T0, addedIdx: addIdx, addedName: addedChoice.name, addedPriceBadge: addedChoice.priceBadge,
        addedNowOn: !!addedChoice.on, Tafter: after.total, delta: (after.total - T0),
        errorShown: after.err, secondSaucePossible: (addIdx >= 0 && !!addedChoice.on),
        counterOrChoicesCount: after.choices.length,
      };
      // remettre l'état (déselect 2e) pour un walk propre
      if (addedChoice.on && addIdx >= 0) { await page.locator('.lc-wiz-choice').nth(addIdx).click().catch(() => {}); await page.waitForTimeout(250); }
    }

    if (isRecap(step)) break;
    await advance(page);
  }
  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('A1', obs);
  console.log('[A1] sauce=', JSON.stringify(obs.sauce));
});

// ========================================================================
// SCÉNARIO 2 — Cayenne « Viande en plus » : +2,50 chacune, max 3, 4e bloquée « Maximum »
// ========================================================================
test('A2 — Cayenne : viande en plus +2,50 chacune, plafond', async ({ page }) => {
  const t = track(page);
  await gotoDev(page); await openMenu(page);
  const prod = await openWizard(page, /Cayenne/i);
  const obs = { prod, extraMeatStep: null, adds: [], consoleErrors: t.consoleErrors, netProblems: t.netProblems };
  if (!prod) { saveObs('A2', obs); return; }

  for (let s = 0; s < 14; s++) {
    const step = await readStep(page);
    const looksExtraMeat = /(viande|steak|meat).*(suppl|plus)|suppl.*viande|viande en plus|viande suppl/i.test(step.title)
      || (/(viande|suppl)/i.test(step.title) && step.choices.some(c => /2[.,]50/.test(c.priceBadge)));
    if (looksExtraMeat && !obs.extraMeatStep) {
      await page.screenshot({ path: shot('A2-extrameat-00-initial.png'), fullPage: true });
      let T = step.total;
      obs.extraMeatStep = { title: step.title, sub: step.sub, T0: T, nOptions: step.choices.length, optionPrices: step.choices.map(c => c.priceBadge) };
      // cliquer jusqu'à 4 viandes distinctes, mesurer delta + erreur après chaque
      const clickable = step.choices.map((c, i) => i); // indices
      let selectedCount = 0;
      for (let a = 0; a < 4 && a < clickable.length + 1; a++) {
        const idx = clickable[a] !== undefined ? clickable[a] : (clickable.length - 1);
        const before = (await readStep(page)).total;
        await page.locator('.lc-wiz-choice').nth(idx).click().catch(() => {});
        await page.waitForTimeout(400);
        const st = await readStep(page);
        const onCount = st.choices.filter(c => c.on).length;
        obs.adds.push({ attempt: a + 1, clickedIdx: idx, before, after: st.total, delta: +(st.total - before).toFixed(2), onCount, error: st.err });
        await page.screenshot({ path: shot(`A2-extrameat-${a + 1}meat.png`), fullPage: true });
        selectedCount = onCount;
      }
      obs.maxSelected = selectedCount;
      break;
    }
    if (isRecap(step)) { obs.reachedRecapWithoutExtraMeat = true; break; }
    await advance(page);
  }
  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('A2', obs);
  console.log('[A2] extraMeat=', JSON.stringify(obs.extraMeatStep), 'adds=', JSON.stringify(obs.adds));
});

// ========================================================================
// SCÉNARIO 3 — Méga « Choisis 2 viandes » : 2 incluses = base (Inclus), 3e = +2,50
// ========================================================================
test('A3 — Méga : 2 viandes incluses (base) + 3e = +2,50', async ({ page }) => {
  const t = track(page);
  await gotoDev(page); await openMenu(page);
  const prod = await openWizard(page, /M[ée]ga/i);
  const obs = { prod, viandeStep: null, seq: [], consoleErrors: t.consoleErrors, netProblems: t.netProblems };
  expect.soft(prod, 'produit Méga ouvert en wizard').toBeTruthy();
  if (!prod) { saveObs('A3', obs); await page.screenshot({ path: shot('A3-00-no-mega.png'), fullPage: true }); return; }

  for (let s = 0; s < 14; s++) {
    const step = await readStep(page);
    const looksIncludedMeat = /choisis?\s*\d?\s*viande|viande/i.test(step.title) && !/suppl|plus/i.test(step.title)
      && step.choices.length >= 2 && step.choices.every(c => !c.priceBadge || c.priceBadge === '');
    if (looksIncludedMeat && !obs.viandeStep) {
      await page.screenshot({ path: shot('A3-viandes-00-initial.png'), fullPage: true });
      const T0 = step.total;
      obs.viandeStep = { title: step.title, sub: step.sub, T0, nOptions: step.choices.length, firstThreePrices: step.choices.slice(0, 3).map(c => c.priceBadge || 'Inclus') };
      // sélectionner 2 viandes incluses
      for (let k = 0; k < 2; k++) { await page.locator('.lc-wiz-choice').nth(k).click().catch(() => {}); await page.waitForTimeout(300); }
      let st = await readStep(page);
      const on2 = st.choices.filter(c => c.on).length;
      obs.seq.push({ phase: '2 viandes', total: st.total, onCount: on2, base: T0, deltaFromBase: +(st.total - T0).toFixed(2), error: st.err });
      await page.screenshot({ path: shot('A3-viandes-2selected.png'), fullPage: true });
      // 3e viande
      const before3 = st.total;
      await page.locator('.lc-wiz-choice').nth(2).click().catch(() => {});
      await page.waitForTimeout(400);
      st = await readStep(page);
      const on3 = st.choices.filter(c => c.on).length;
      obs.seq.push({ phase: '3e viande', total: st.total, onCount: on3, delta3: +(st.total - before3).toFixed(2), thirdPriceBadge: (st.choices[2] || {}).priceBadge, error: st.err });
      await page.screenshot({ path: shot('A3-viandes-3selected.png'), fullPage: true });
      break;
    }
    if (isRecap(step)) { obs.reachedRecapWithoutViandeStep = true; break; }
    await advance(page);
  }
  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('A3', obs);
  console.log('[A3] viandeStep=', JSON.stringify(obs.viandeStep), 'seq=', JSON.stringify(obs.seq));
});

// ========================================================================
// SCÉNARIO 4 — MONEY-PATH : Cayenne chargé (extra meat + 2e sauce si possible +
// supplément + menu) → total affiché → checkout dev-OTP → COMMANDE RÉELLE →
// confirmation total == affiché. Fallback : recap == base + somme extras.
// ========================================================================
test('A4 — MONEY-PATH réel : total affiché == scellé (ou fallback recap=Σ)', async ({ page }) => {
  const t = track(page);
  let devCode = null;
  page.on('response', async (r) => {
    if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) devCode = String(j.dev_code); }
  });
  await gotoDev(page); await openMenu(page);
  const prod = await openWizard(page, /Cayenne/i);
  const obs = { prod, chargedExtras: [], base: null, recapTotal: null, cartTotal: null, payTotal: null,
    confTotal: null, serial: null, realOrderCompleted: false, sumExtras: 0, expectedFromSum: null,
    devCodeCaptured: false, consoleErrors: t.consoleErrors, netProblems: t.netProblems, notes: [] };
  expect.soft(prod, 'Cayenne ouvert').toBeTruthy();
  if (!prod) { saveObs('A4', obs); return; }

  // STACKER un maximum d'extras : sur CHAQUE étape, ajouter UNE option non sélectionnée
  // (préf. une payante à badge ; sinon la 1re non-on — capte la viande en plus qui n'a PAS
  // de badge par-option). Mesurer le delta RÉEL ; ne garder au compteur que si delta>0.
  // → construit Cayenne = base + 2e sauce + 1 viande en plus + supplément + menu.
  let base = null;
  for (let s = 0; s < 14; s++) {
    const step = await readStep(page);
    if (base === null) base = step.total;
    if (isRecap(step)) break;
    const before = step.total;
    let targetIdx = step.choices.findIndex(c => c.priceBadge && !c.on);
    if (targetIdx < 0) targetIdx = step.choices.findIndex(c => !c.on);
    if (targetIdx >= 0) {
      const c = step.choices[targetIdx];
      await page.locator('.lc-wiz-choice').nth(targetIdx).click().catch(() => {});
      await page.waitForTimeout(400);
      const st2 = await readStep(page);
      const d = +(st2.total - before).toFixed(2);
      if (d > 0) obs.chargedExtras.push({ step: step.title, name: c.name, badge: c.priceBadge || '(prix au sous-titre)', deltaMeasured: d });
    }
    await advance(page);
  }
  obs.base = base;
  obs.sumExtras = +obs.chargedExtras.reduce((a, e) => a + (e.deltaMeasured || 0), 0).toFixed(2);
  obs.expectedFromSum = +((base || 0) + obs.sumExtras).toFixed(2);

  // recap
  const recap = await readStep(page);
  obs.recapTotal = recap.total;
  await page.screenshot({ path: shot('A4-01-recap.png'), fullPage: true });
  // ajouter au panier
  await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
  const cartOpen = await page.locator('.lc-cart-drawer.is-open').isVisible({ timeout: 10_000 }).catch(() => false);
  if (cartOpen) {
    obs.cartTotal = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText().catch(() => ''));
    await page.screenshot({ path: shot('A4-02-panier.png'), fullPage: true });
  } else { obs.notes.push('panier non ouvert après recap'); }

  // checkout → paiement
  try {
    await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click({ timeout: 8_000 });
    const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
    for (let i = 0; i < 10; i++) {
      if (await cta.isVisible({ timeout: 1_500 }).catch(() => false)) break;
      const skip = page.getByRole('button', { name: 'Non merci' });
      if (await skip.isVisible({ timeout: 800 }).catch(() => false)) await skip.click().catch(() => {});
      await page.waitForTimeout(300);
    }
    await cta.click({ timeout: 8_000 });
    await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 12_000 });
    obs.payTotal = euro(await page.locator('.lcf-cta-bar-next').innerText());
    await page.screenshot({ path: shot('A4-03-paiement.png'), fullPage: true });
  } catch (e) { obs.notes.push('checkout→paiement bloqué: ' + String(e.message).split('\n')[0].slice(0, 100)); }

  // COMMANDE RÉELLE (dev-OTP) — 2 tentatives puis fallback
  let attempt = 0;
  while (attempt < 2 && !obs.realOrderCompleted) {
    attempt++;
    try {
      await page.locator('.lcf-cta-bar-next').click({ timeout: 8_000 });
      if (await page.locator('#auth-phone').isVisible({ timeout: 4_000 }).catch(() => false)) {
        await page.locator('#auth-phone').fill(AUDIT_PHONE);
        await page.getByRole('button', { name: /Recevoir le code/ }).click({ timeout: 8_000 });
        await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 12_000 });
        await expect.poll(() => devCode, { timeout: 12_000 }).toBeTruthy().catch(() => {});
        obs.devCodeCaptured = !!devCode;
        if (devCode) {
          await page.locator('#auth-otp').fill(devCode);
          await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click({ timeout: 8_000 });
        } else { obs.notes.push('dev_code absent de la réponse /guest-signup/otp (attempt ' + attempt + ')'); break; }
      }
      await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 30_000 });
      obs.serial = (await page.locator('.lcf-ticket-code').innerText().catch(() => '')).trim();
      obs.confTotal = euro(await page.locator('.lcf-ticket-cell-val').last().innerText().catch(() => ''));
      obs.realOrderCompleted = true;
      await page.screenshot({ path: shot('A4-04-confirmation.png'), fullPage: true });
    } catch (e) {
      obs.notes.push('order attempt ' + attempt + ' échec: ' + String(e.message).split('\n')[0].slice(0, 120));
      await page.screenshot({ path: shot(`A4-04-order-fail-${attempt}.png`), fullPage: true }).catch(() => {});
      if (attempt < 2) { await gotoDev(page).catch(() => {}); await openMenu(page).catch(() => {}); /* re-run trop lourd : on tente juste de revalider */ }
    }
  }

  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('A4', obs);
  console.log('[A4]', JSON.stringify({ base: obs.base, extras: obs.chargedExtras, sum: obs.sumExtras, recap: obs.recapTotal, cart: obs.cartTotal, pay: obs.payTotal, conf: obs.confTotal, serial: obs.serial, real: obs.realOrderCompleted }));

  // signaux (non-bloquants) pour analyse
  if (obs.recapTotal != null && obs.expectedFromSum != null) expect.soft(obs.recapTotal, 'recap == base + Σ extras').toBeCloseTo(obs.expectedFromSum, 2);
  if (obs.cartTotal != null && obs.recapTotal != null) expect.soft(obs.cartTotal, 'panier == recap').toBeCloseTo(obs.recapTotal, 2);
  if (obs.payTotal != null && obs.cartTotal != null) expect.soft(obs.payTotal, 'paiement == panier').toBeCloseTo(obs.cartTotal, 2);
  if (obs.realOrderCompleted && obs.confTotal != null && obs.payTotal != null) expect.soft(obs.confTotal, 'confirmation == paiement (SCELLÉ)').toBeCloseTo(obs.payTotal, 2);
});

// ========================================================================
// SCÉNARIO 5 — Boissons groupées : canettes HORS grille plats, section dédiée
// (5 + Voir toutes) ; desserts restent inline.
// ========================================================================
test('A5 — Boissons regroupées (section dédiée) + desserts inline', async ({ page }) => {
  const t = track(page);
  await page.setViewportSize({ width: 1280, height: 1000 });
  await gotoDev(page); await openMenu(page);
  const obs = { consoleErrors: t.consoleErrors, netProblems: t.netProblems };

  const drinksHeading = page.getByRole('heading', { name: /Boissons/ });
  obs.drinksSectionVisible = await drinksHeading.isVisible({ timeout: 8_000 }).catch(() => false);
  const seeAll = page.getByRole('button', { name: /Voir toutes/i });
  obs.seeAllVisible = await seeAll.isVisible().catch(() => false);
  if (obs.drinksSectionVisible) await drinksHeading.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  await page.screenshot({ path: shot('A5-01-tout-boissons.png'), fullPage: true });

  const grids = page.locator('.lc-menu-grid');
  obs.nGrids = await grids.count();
  obs.drinkPreviewCards = obs.nGrids ? await grids.nth(obs.nGrids - 1).locator(':scope > *').count().catch(() => 0) : 0;
  obs.cocaInFoodGrid = await grids.first().getByText('Coca-Cola 33cl', { exact: true }).count().catch(() => 0);

  // desserts inline ?
  const DESS = ['Glace', 'Tiramisu', 'Tarte'];
  obs.dessertsInTout = {};
  for (const d of DESS) obs.dessertsInTout[d] = await page.getByText(new RegExp(d, 'i')).first().isVisible().catch(() => false);

  if (obs.seeAllVisible) {
    await seeAll.click().catch(() => {});
    await page.waitForTimeout(700);
    await page.screenshot({ path: shot('A5-02-page-boissons.png'), fullPage: true });
    obs.allDrinkCards = await page.locator('.lc-menu-grid').first().locator(':scope > *').count().catch(() => 0);
  }
  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('A5', obs);
  console.log('[A5]', JSON.stringify(obs));
  expect.soft(obs.drinksSectionVisible, 'section Boissons présente en vue Tout').toBeTruthy();
  expect.soft(obs.cocaInFoodGrid, 'aucune canette dans la grille plats').toBe(0);
});
