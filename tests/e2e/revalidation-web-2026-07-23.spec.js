// =============================================================================
// REVALIDATION E2E LIVE 2026-07-23 — WEB desktop (Vercel → VPS backend)
// READ-ONLY probe : capture + prouve, ne corrige rien.
// R1 = formule 2,50/1,90/1,90 + COMMANDE RÉELLE (Cayenne + Menu complet + 2 sauces)
//      → total affiché == confirmation == backend scellé (hook POST /api/frontend/order).
// R2 = boissons regroupées (5 aperçus + Voir toutes, 0 canette dans la grille plats).
// Run: PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/revalidation-web-2026-07-23.spec.js --project=chromium
// =============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE = 'https://site-lecayenne.vercel.app';
const SHOT = path.join(__dirname, '__screenshots__', 'revalidation-2026-07-23');
fs.mkdirSync(SHOT, { recursive: true });
const shot = (n) => path.join(SHOT, n);
const saveObs = (name, data) => fs.writeFileSync(path.join(SHOT, `obs-${name}.json`), JSON.stringify(data, null, 2));
const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };
const AUDIT_PHONE = '0699000723';

test.describe.configure({ retries: 0, mode: 'serial' });
test.setTimeout(300_000);

function track(page) {
  const consoleErrors = []; const netProblems = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 240)); });
  page.on('response', (r) => { const s = r.status(); if (s >= 400) netProblems.push({ status: s, url: r.url().slice(0, 160) }); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.message).slice(0, 200)));
  return { consoleErrors, netProblems };
}
async function gotoDev(page) {
  let lastErr = null;
  for (let i = 0; i < 3; i++) {
    try { await page.goto(BASE + '/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 }); lastErr = null; break; }
    catch (e) { lastErr = e; await page.waitForTimeout(3_000); }
  }
  if (lastErr) throw lastErr;
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(800);
}
async function openMenu(page) {
  await page.keyboard.press('Escape').catch(() => {});
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible({ timeout: 3_000 }).catch(() => false)) await direct.click();
  else { await page.locator('.lc-nav-burger').click().catch(() => {}); await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click().catch(() => {}); }
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(400);
}
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
    } else { await page.keyboard.press('Escape').catch(() => {}); }
    await page.waitForTimeout(200);
  }
  return null;
}
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
        on: /is-on/.test(c.className), full: full.slice(0, 90) };
    });
    return { title: t(q('.lc-wiz-title')), totalTxt: t(q('.lc-wiz-foot-next-total')),
      nextTxt: t(nextBtn), nextDisabled: nextBtn ? !!nextBtn.disabled : false,
      err: errEl ? t(errEl) : '', choices };
  }).catch(() => ({ title: '', totalTxt: '', nextTxt: '', nextDisabled: false, err: '', choices: [] }));
  return { ...r, total: euro(r.totalTxt) };
}
async function advance(page) {
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
    await page.waitForTimeout(250);
  }
  await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
  await page.waitForTimeout(400);
}
const isRecap = (step) => /Ajouter au panier/i.test(step.nextTxt || '');

// ============================================================================
// R1 — Formule 2,50/1,90/1,90 + commande réelle money-path
// ============================================================================
test('R1 — formule 1,90 + commande réelle Cayenne (menu complet + 2 sauces)', async ({ page }) => {
  const t = track(page);
  let devCode = null; let orderApi = null;
  page.on('response', async (r) => {
    if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) devCode = String(j.dev_code); }
    if (r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url())) {
      const j = await r.json().catch(() => null);
      orderApi = { status: r.status(), id: j && j.id, serial: j && j.order_serial_no, total: j && j.total, queue: j && j.queue_number };
    }
  });
  await gotoDev(page); await openMenu(page);
  const prod = await openWizard(page, /Cayenne/i);
  const obs = { prod, formule: null, sauce: null, base: null, recapTotal: null, cartTotal: null,
    payTotal: null, confTotal: null, serial: null, orderApi: null, realOrderCompleted: false,
    steps: [], notes: [] };
  expect(prod, 'produit Cayenne ouvert en wizard').toBeTruthy();

  // Walk : 2 sauces sur l'étape Sauce (dflt incluse + 1 payante), Menu complet sur « Faire un menu ? »
  for (let s = 0; s < 16; s++) {
    const step = await readStep(page);
    if (obs.base === null) obs.base = step.total;
    obs.steps.push({ s, title: step.title, total: step.total, on: step.choices.filter(c => c.on).map(c => c.name) });
    if (isRecap(step)) break;

    if (/^Sauce$/i.test(step.title) && !obs.sauce) {
      const T0 = step.total;
      const preOnIdx = step.choices.findIndex(c => c.on);
      const addIdx = step.choices.findIndex(c => !c.on);
      await page.screenshot({ path: shot('R1-01-sauce-avant.png'), fullPage: false });
      if (addIdx >= 0) { await page.locator('.lc-wiz-choice').nth(addIdx).click().catch(() => {}); await page.waitForTimeout(450); }
      const after = await readStep(page);
      await page.screenshot({ path: shot('R1-02-sauce-2selectionnees.png'), fullPage: false });
      obs.sauce = { T0, preSelected: (step.choices[preOnIdx] || {}).name, preBadge: (step.choices[preOnIdx] || {}).priceBadge,
        added: (after.choices[addIdx] || {}).name, addedBadge: (after.choices[addIdx] || {}).priceBadge,
        onCount: after.choices.filter(c => c.on).length, Tafter: after.total, delta: +(after.total - T0).toFixed(2) };
      await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
      await page.waitForTimeout(400);
      continue;
    }
    if (/Faire un menu/i.test(step.title) && !obs.formule) {
      await page.screenshot({ path: shot('R1-03-etape-formule.png'), fullPage: false });
      obs.formule = { title: step.title, T0: step.total,
        options: step.choices.map(c => ({ name: c.name, badge: c.priceBadge })) };
      const menuIdx = step.choices.findIndex(c => /Menu complet/i.test(c.name));
      expect(menuIdx, 'option Menu complet présente').toBeGreaterThanOrEqual(0);
      await page.locator('.lc-wiz-choice').nth(menuIdx).click().catch(() => {});
      await page.waitForTimeout(450);
      const after = await readStep(page);
      obs.formule.Tafter = after.total; obs.formule.deltaMenu = +(after.total - step.total).toFixed(2);
      await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
      await page.waitForTimeout(400);
      continue;
    }
    await advance(page);
  }

  // Recap
  const recap = await readStep(page);
  obs.recapTotal = recap.total;
  await page.screenshot({ path: shot('R1-04-recap.png'), fullPage: true });
  await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {});
  const cartOpen = await page.locator('.lc-cart-drawer.is-open').isVisible({ timeout: 10_000 }).catch(() => false);
  if (cartOpen) {
    obs.cartTotal = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText().catch(() => ''));
    await page.screenshot({ path: shot('R1-05-panier.png'), fullPage: false });
  } else obs.notes.push('panier non ouvert apres recap');

  // Checkout → paiement
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click({ timeout: 8_000 });
  const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 12; i++) {
    if (await cta.isVisible({ timeout: 1_500 }).catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible({ timeout: 800 }).catch(() => false)) await skip.click().catch(() => {});
    await page.waitForTimeout(300);
  }
  await cta.click({ timeout: 8_000 });
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 12_000 });
  obs.payTotal = euro(await page.locator('.lcf-cta-bar-next').innerText());
  await page.screenshot({ path: shot('R1-06-paiement.png'), fullPage: false });

  // COMMANDE RÉELLE via dev-OTP
  await page.locator('.lcf-cta-bar-next').click({ timeout: 8_000 });
  if (await page.locator('#auth-phone').isVisible({ timeout: 5_000 }).catch(() => false)) {
    await page.locator('#auth-phone').fill(AUDIT_PHONE);
    await page.getByRole('button', { name: /Recevoir le code/ }).click({ timeout: 8_000 });
    await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 12_000 });
    await expect.poll(() => devCode, { timeout: 15_000 }).toBeTruthy();
    await page.locator('#auth-otp').fill(devCode);
    await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click({ timeout: 8_000 });
  }
  await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 30_000 });
  obs.serial = (await page.locator('.lcf-ticket-code').innerText().catch(() => '')).trim();
  obs.confTotal = euro(await page.locator('.lcf-ticket-cell-val').last().innerText().catch(() => ''));
  obs.realOrderCompleted = true;
  obs.orderApi = orderApi;
  await page.screenshot({ path: shot('R1-07-confirmation.png'), fullPage: true });

  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('R1', obs);
  console.log('[R1]', JSON.stringify({ formule: obs.formule, sauce: obs.sauce, recap: obs.recapTotal, cart: obs.cartTotal, pay: obs.payTotal, conf: obs.confTotal, serial: obs.serial, api: obs.orderApi }));

  // ------- assertions dures -------
  const badge = (re) => (obs.formule.options.find(o => re.test(o.name)) || {}).badge || '';
  expect(badge(/Menu complet/i), 'Menu complet affiché +2,50').toMatch(/2[.,]50/);
  expect(badge(/Ajouter Frites/i), 'Frites seules affiché +1,90').toMatch(/1[.,]90/);
  expect(badge(/Ajouter Boisson/i), 'Boisson seule affiché +1,90').toMatch(/1[.,]90/);
  expect(obs.sauce && obs.sauce.onCount, '2 sauces sélectionnées').toBe(2);
  expect(obs.sauce.delta, '2e sauce facturée +0,50').toBeCloseTo(0.5, 2);
  expect(obs.formule.deltaMenu, 'Menu complet facturé +2,50').toBeCloseTo(2.5, 2);
  expect(obs.cartTotal, 'panier == recap').toBeCloseTo(obs.recapTotal, 2);
  expect(obs.payTotal, 'paiement == panier').toBeCloseTo(obs.cartTotal, 2);
  expect(obs.confTotal, 'confirmation (scellée backend) == paiement affiché').toBeCloseTo(obs.payTotal, 2);
  if (orderApi && orderApi.total != null) expect(Number(orderApi.total), 'API backend total == affiché').toBeCloseTo(obs.payTotal, 2);
});

// ============================================================================
// R2 — Boissons regroupées : 5 aperçus + Voir toutes, 0 canette grille plats
// ============================================================================
test('R2 — boissons regroupées home menu', async ({ page }) => {
  const t = track(page);
  await page.setViewportSize({ width: 1280, height: 1000 });
  await gotoDev(page); await openMenu(page);
  const obs = {};
  const drinksHeading = page.getByRole('heading', { name: /Boissons/ });
  obs.drinksSectionVisible = await drinksHeading.isVisible({ timeout: 8_000 }).catch(() => false);
  const seeAll = page.getByRole('button', { name: /Voir toutes/i });
  obs.seeAllVisible = await seeAll.isVisible().catch(() => false);
  if (obs.drinksSectionVisible) await drinksHeading.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  await page.screenshot({ path: shot('R2-01-section-boissons.png'), fullPage: false });

  const grids = page.locator('.lc-menu-grid');
  obs.nGrids = await grids.count();
  obs.drinkPreviewCards = obs.nGrids ? await grids.nth(obs.nGrids - 1).locator(':scope > *').count().catch(() => 0) : 0;
  const CANS = ['Coca-Cola 33cl', 'Coca-Cola Zéro 33cl', 'Fanta Orange 33cl', 'Sprite 33cl', 'Oasis Tropical 33cl', 'Orangina 33cl', 'Perrier'];
  obs.cansInFoodGrid = 0;
  for (const c of CANS) obs.cansInFoodGrid += await grids.first().getByText(c, { exact: false }).count().catch(() => 0);
  if (obs.seeAllVisible) {
    await seeAll.click().catch(() => {});
    await page.waitForTimeout(800);
    await page.screenshot({ path: shot('R2-02-toutes-boissons.png'), fullPage: true });
    obs.allDrinkCards = await page.locator('.lc-menu-grid').first().locator(':scope > *').count().catch(() => 0);
  }
  obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
  saveObs('R2', obs);
  console.log('[R2]', JSON.stringify(obs));
  expect(obs.drinksSectionVisible, 'section Boissons présente').toBeTruthy();
  expect(obs.seeAllVisible, 'bouton Voir toutes présent').toBeTruthy();
  expect(obs.drinkPreviewCards, '5 aperçus boissons').toBe(5);
  expect(obs.cansInFoodGrid, '0 canette dans la grille plats').toBe(0);
});
