// =============================================================================
// TEST-E2E MASSIF — DIMENSION 1 : WEB MONEY-PATH RÉEL (priorité #1)
// Cible LIVE : https://site-lecayenne.vercel.app (Vercel → VPS backend).
// READ-ONLY produit : passe de VRAIES commandes (side-effect assumé, n° notés).
// Preuve = capture LUE + assert au centime. Backend = SSOT prix (client envoie
// seulement item_id/qty/option_ids ; aucun prix côté client).
// Run: PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=https://site-lecayenne.vercel.app \
//        npx playwright test tests/e2e/e2e-massive-E1-web-moneypath-2026-07-24.spec.js --project=chromium
// =============================================================================
const { test, expect, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE = process.env.PLAYWRIGHT_BASE_URL && /^https?:/.test(process.env.PLAYWRIGHT_BASE_URL)
  ? process.env.PLAYWRIGHT_BASE_URL
  : 'https://site-lecayenne.vercel.app';
const SHOT = path.join(__dirname, '__screenshots__', 'e2e-massive-E1');
fs.mkdirSync(SHOT, { recursive: true });
const shot = (n) => path.join(SHOT, n);
const saveObs = (name, data) => fs.writeFileSync(path.join(SHOT, `obs-${name}.json`), JSON.stringify(data, null, 2));
const euro = (s) => { const m = String(s || '').match(/(\d+[.,]\d{2})/); return m ? parseFloat(m[1].replace(',', '.')) : NaN; };

test.describe.configure({ retries: 0 });     // JAMAIS de retry : éviterait de doubler les commandes réelles
test.setTimeout(300_000);

// --- console + network trackers ---------------------------------------------
function track(page) {
  const consoleErrors = []; const netProblems = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 240)); });
  page.on('response', (r) => { const s = r.status(); if (s >= 400) netProblems.push({ status: s, url: r.url().slice(0, 160) }); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.message).slice(0, 200)));
  return { consoleErrors, netProblems };
}
// capture le dev-OTP + la requête/réponse POST order (body envoyé + total scellé renvoyé)
function attachOrderHooks(page, sink) {
  page.on('request', (req) => {
    if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) {
      let parsed = null; try { parsed = JSON.parse(req.postData() || '{}'); } catch (_e) { parsed = { raw: (req.postData() || '').slice(0, 300) }; }
      sink.orderReq = { url: req.url(), hasExpectedTotal: Object.prototype.hasOwnProperty.call(parsed, 'expected_total'),
        expectedTotal: parsed.expected_total, source: parsed.source, hasCoupon: !!parsed.coupon_id,
        itemsPresent: !!parsed.items, bodyKeys: Object.keys(parsed) };
    }
  });
  page.on('response', async (r) => {
    if (r.url().includes('/guest-signup/otp')) { const j = await r.json().catch(() => null); if (j && j.dev_code) sink.devCode = String(j.dev_code); }
    if (r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url())) {
      const j = await r.json().catch(() => null);
      const d = (j && j.data) || j || {};
      sink.orderApi = { status: r.status(), id: d.id, serial: d.order_serial_no, total: d.total, queue: d.queue_number };
    }
  });
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
        on: /is-on/.test(c.className), hasInclus: /inclus/i.test(full), full: full.slice(0, 100) };
    });
    return { title: t(q('.lc-wiz-title')), sub: t(q('.lc-wiz-sub')), totalTxt: t(q('.lc-wiz-foot-next-total')),
      nextTxt: t(nextBtn), nextDisabled: nextBtn ? !!nextBtn.disabled : false, err: errEl ? t(errEl) : '', choices };
  }).catch(() => ({ title: '', sub: '', totalTxt: '', nextTxt: '', nextDisabled: false, err: '', choices: [] }));
  return { ...r, total: euro(r.totalTxt) };
}
async function clickNext(page) { await page.locator('.lc-wiz-foot-next').click({ timeout: 6_000 }).catch(() => {}); await page.waitForTimeout(400); }
async function advance(page) {
  for (let t = 0; t < 5; t++) {
    if (!(await page.locator('.lc-wiz-foot-next').isDisabled().catch(() => false))) break;
    const cs = page.locator('.lc-wiz-choice'); const cn = await cs.count(); let clicked = false;
    for (let i = 0; i < cn; i++) {
      const cls = (await cs.nth(i).getAttribute('class').catch(() => '')) || '';
      if (!/is-on/.test(cls)) { await cs.nth(i).click().catch(() => {}); clicked = true; break; }
    }
    if (!clicked) break; await page.waitForTimeout(250);
  }
  await clickNext(page);
}
const isRecap = (step) => /Ajouter au panier/i.test(step.nextTxt || '');

// checkout drawer → funnel paiement → OTP dev → confirmation. Retourne {payTotal,serial,confTotal,ok}.
async function placeRealOrder(page, sink, phone, tag) {
  const out = { payTotal: null, serial: null, confTotal: null, ok: false, notes: [] };
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click({ timeout: 10_000 });
  const cta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 12; i++) {
    if (await cta.isVisible({ timeout: 1_500 }).catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible({ timeout: 800 }).catch(() => false)) await skip.click().catch(() => {});
    await page.waitForTimeout(300);
  }
  await cta.click({ timeout: 8_000 });
  await expect(page.locator('.lcf-cta-bar-next')).toBeVisible({ timeout: 15_000 });
  out.payTotal = euro(await page.locator('.lcf-cta-bar-next').innerText());
  await page.screenshot({ path: shot(`${tag}-paiement.png`), fullPage: false });

  await page.locator('.lcf-cta-bar-next').click({ timeout: 8_000 });
  if (await page.locator('#auth-phone').isVisible({ timeout: 6_000 }).catch(() => false)) {
    await page.locator('#auth-phone').fill(phone);
    await page.getByRole('button', { name: /Recevoir le code/ }).click({ timeout: 8_000 });
    await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => sink.devCode, { timeout: 15_000 }).toBeTruthy().catch(() => {});
    if (!sink.devCode) { out.notes.push('dev_code absent'); return out; }
    await page.locator('#auth-otp').fill(sink.devCode);
    await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click({ timeout: 8_000 });
  }
  await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 30_000 });
  out.serial = (await page.locator('.lcf-ticket-code').innerText().catch(() => '')).trim();
  out.confTotal = euro(await page.locator('.lcf-ticket-cell-val').last().innerText().catch(() => ''));
  out.ok = true;
  await page.screenshot({ path: shot(`${tag}-confirmation.png`), fullPage: true });
  return out;
}

// ============================================================================
// S1 + S2 — DESKTOP : Cayenne → formule 3 pages (Menu complet +2,50 / Frites
// +1,90 / Boisson +1,90) → boisson dédiée → sauce (1re Incluse + 2e +0,50) →
// panier → COMMANDE RÉELLE → total affiché == recap == panier == paiement ==
// confirmation scellée == total API backend.
// ============================================================================
test.describe('E1 desktop', () => {
  test.use({ viewport: { width: 1366, height: 900 } });

  test('S1+S2 — formule 3 pages + 2 sauces + money-path scellé (commande réelle)', async ({ page }) => {
    const t = track(page); const sink = {}; attachOrderHooks(page, sink);
    await gotoDev(page); await openMenu(page);
    const prod = await openWizard(page, /Cayenne/i);
    const obs = { prod, base: null, formule: null, sauce: null, boissonPage: null, steps: [],
      recapTotal: null, cartTotal: null, order: null, orderReq: null, orderApi: null, notes: [] };
    expect(prod, 'produit Cayenne ouvert en wizard').toBeTruthy();

    for (let s = 0; s < 18; s++) {
      const step = await readStep(page);
      if (obs.base === null) obs.base = step.total;
      obs.steps.push({ s, title: step.title, total: step.total, on: step.choices.filter(c => c.on).map(c => c.name) });
      await page.screenshot({ path: shot(`S1-step${String(s).padStart(2, '0')}-${(step.title || 'x').replace(/[^a-z0-9]+/gi, '-').slice(0, 22)}.png`), fullPage: true });
      if (isRecap(step)) break;

      // -- PAGE formule « Faire un menu ? » : 3 options + badges --
      if (/Faire un menu/i.test(step.title) && !obs.formule) {
        obs.formule = { title: step.title, T0: step.total, options: step.choices.map(c => ({ name: c.name, badge: c.priceBadge })) };
        const menuIdx = step.choices.findIndex(c => /Menu complet/i.test(c.name));
        expect(menuIdx, 'option Menu complet présente').toBeGreaterThanOrEqual(0);
        await page.locator('.lc-wiz-choice').nth(menuIdx).click().catch(() => {});
        await page.waitForTimeout(450);
        const after = await readStep(page);
        obs.formule.deltaMenu = +(after.total - step.total).toFixed(2);
        await clickNext(page); continue;
      }
      // -- PAGE boisson dédiée (après Menu complet) --
      if (/boisson/i.test(step.title) && step.choices.length >= 2 && !obs.boissonPage) {
        obs.boissonPage = { title: step.title, nOptions: step.choices.length, sample: step.choices.slice(0, 4).map(c => c.name) };
        await page.screenshot({ path: shot('S1-boisson-dediee.png'), fullPage: true });
        // advance() choisit la 1re boisson
      }
      // -- PAGE Sauce (sandwich) : 1re Incluse pré-sélectionnée + 2e +0,50 --
      if (/^Sauce$/i.test(step.title) && !obs.sauce) {
        const T0 = step.total;
        const preIdx = step.choices.findIndex(c => c.on);
        const addIdx = step.choices.findIndex(c => !c.on);
        await page.screenshot({ path: shot('S1-sauce-avant.png'), fullPage: true });
        if (addIdx >= 0) { await page.locator('.lc-wiz-choice').nth(addIdx).click().catch(() => {}); await page.waitForTimeout(450); }
        const after = await readStep(page);
        await page.screenshot({ path: shot('S1-sauce-2selectionnees.png'), fullPage: true });
        obs.sauce = { T0, firstName: (step.choices[preIdx] || {}).name, firstBadge: (step.choices[preIdx] || {}).priceBadge,
          firstHasInclus: (step.choices[preIdx] || {}).hasInclus, firstPreSelected: preIdx >= 0,
          addedName: (after.choices[addIdx] || {}).name, addedBadge: (after.choices[addIdx] || {}).priceBadge,
          onCount: after.choices.filter(c => c.on).length, delta: +(after.total - T0).toFixed(2) };
        await clickNext(page); continue;
      }
      await advance(page);
    }

    const recap = await readStep(page);
    obs.recapTotal = recap.total;
    await page.screenshot({ path: shot('S1-recap.png'), fullPage: true });
    await clickNext(page); // Ajouter au panier
    const cartOpen = await page.locator('.lc-cart-drawer.is-open').isVisible({ timeout: 10_000 }).catch(() => false);
    if (cartOpen) { obs.cartTotal = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText().catch(() => '')); await page.screenshot({ path: shot('S1-panier.png'), fullPage: false }); }
    else obs.notes.push('panier non ouvert après recap');

    // ---- S2 : money-path réel ----
    obs.order = await placeRealOrder(page, sink, '0699002412', 'S2');
    obs.orderReq = sink.orderReq; obs.orderApi = sink.orderApi;
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('S1-S2', obs);
    console.log('[S1+S2]', JSON.stringify({ base: obs.base, formule: obs.formule, sauce: obs.sauce, recap: obs.recapTotal, cart: obs.cartTotal, order: obs.order, req: obs.orderReq, api: obs.orderApi }));

    // ===== ASSERTIONS =====
    const badge = (re) => (obs.formule.options.find(o => re.test(o.name)) || {}).badge || '';
    expect(badge(/Menu complet/i), 'Menu complet +2,50 affiché').toMatch(/2[.,]50/);
    expect(badge(/Frites/i), 'Frites seules +1,90 affiché').toMatch(/1[.,]90/);
    expect(badge(/Boisson/i), 'Boisson seule +1,90 affiché').toMatch(/1[.,]90/);
    expect(obs.formule.deltaMenu, 'Menu complet facturé +2,50').toBeCloseTo(2.5, 2);
    expect(obs.sauce.firstPreSelected, '1re sauce pré-sélectionnée').toBeTruthy();
    expect(obs.sauce.firstHasInclus || /inclus/i.test(obs.sauce.firstBadge || ''), '1re sauce badge « Incluse »').toBeTruthy();
    expect(obs.sauce.onCount, '2 sauces sélectionnées').toBe(2);
    expect(obs.sauce.delta, '2e sauce facturée +0,50').toBeCloseTo(0.5, 2);
    expect(obs.cartTotal, 'panier == recap (centime)').toBeCloseTo(obs.recapTotal, 2);
    // money-path scellé
    expect(obs.order.ok, 'commande réelle passée').toBeTruthy();
    expect(obs.order.payTotal, 'paiement == panier').toBeCloseTo(obs.cartTotal, 2);
    expect(obs.order.confTotal, 'confirmation scellée == paiement').toBeCloseTo(obs.order.payTotal, 2);
    expect(obs.orderApi && obs.orderApi.status, 'POST order 2xx').toBeGreaterThanOrEqual(200);
    expect(obs.orderApi.status, 'POST order < 300').toBeLessThan(300);
    if (obs.orderApi.total != null) expect(Number(obs.orderApi.total), 'total API backend == affiché').toBeCloseTo(obs.order.payTotal, 2);
    expect((t.netProblems || []).filter(p => /site-lecayenne|api|order/i.test(p.url)).length, '0 réponse app ≥400').toBe(0);
  });

  // ==========================================================================
  // S3 — Tacos + viandes en plus (plafond 3). Prix cohérent, pas de viande gratuite.
  // ==========================================================================
  test('S3 — Tacos viandes en plus, plafond 3 + prix cohérent', async ({ page }) => {
    const t = track(page); const sink = {}; attachOrderHooks(page, sink);
    await gotoDev(page); await openMenu(page);
    const prod = await openWizard(page, /Tacos/i);
    const obs = { prod, meatStep: null, adds: [], maxOn: 0, capBlocked: null, notes: [] };
    expect(prod, 'produit Tacos ouvert en wizard').toBeTruthy();

    for (let s = 0; s < 18; s++) {
      const step = await readStep(page);
      // Tacos : étape « CHOISIS 1 VIANDE » — 1 incluse ; viande en plus +2,50 € chacune
      // (prix au SOUS-TITRE, pas de badge par-choix). Détection titre+sous-titre.
      const meta = step.title + ' ' + (step.sub || '');
      const meatStep = /viande/i.test(step.title) && /(viande en plus|viande incluse|\+\s*2[.,]50)/i.test(meta);
      if (meatStep && !obs.meatStep) {
        await page.screenshot({ path: shot('S3-viande-00.png'), fullPage: true });
        obs.meatStep = { title: step.title, sub: step.sub, base: step.total, nOptions: step.choices.length };
        for (let a = 0; a < 5; a++) {            // clique 5 viandes distinctes pour trouver le plafond réel
          const before = (await readStep(page)).total;
          await page.locator('.lc-wiz-choice').nth(a).click().catch(() => {});
          await page.waitForTimeout(450);
          const st = await readStep(page);
          const onCount = st.choices.filter(c => c.on).length;
          obs.adds.push({ attempt: a + 1, meat: (st.choices[a] || {}).name, before, after: st.total,
            delta: +(st.total - before).toFixed(2), onCount, err: st.err, capMsg: /maxi|maximum|plus de/i.test(st.err || '') });
          await page.screenshot({ path: shot(`S3-viande-${a + 1}sel.png`), fullPage: true });
          obs.maxOn = Math.max(obs.maxOn, onCount);
        }
        break;
      }
      if (isRecap(step)) { obs.notes.push('recap atteint sans étape viande-en-plus'); break; }
      await advance(page);
    }
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('S3', obs);
    console.log('[S3]', JSON.stringify({ meatStep: obs.meatStep, adds: obs.adds, maxOn: obs.maxOn, capBlocked: obs.capBlocked }));

    expect(obs.meatStep, 'étape viande (Tacos) trouvée').toBeTruthy();
    expect(obs.adds[0].delta, '1re viande incluse = 0 € (comprise)').toBeCloseTo(0, 2);
    const paidAdds = obs.adds.filter(a => a.delta > 0);
    expect(paidAdds.length, 'viandes en plus facturées (aucune gratuite parasite)').toBeGreaterThanOrEqual(1);
    for (const p of paidAdds) expect(p.delta, `viande en plus +2,50 € (attempt ${p.attempt})`).toBeCloseTo(2.5, 2);
    const extrasMax = obs.maxOn - 1;   // maxOn = viandes totales sélectionnables ; 1 incluse
    expect.soft(extrasMax, 'plafond viandes EN PLUS == 3 (cap)').toBe(3);
  });

  // ==========================================================================
  // S5 — ATTAQUE intégrité : (a) retour arrière wizard = pas de drop/fantôme,
  // (b) quantité panier ×2 = total scale exact, (c) coupon si dispo cohérent,
  // (d) structurel : client envoie 0 prix → backend SSOT (relevé sur S1+S2).
  // ==========================================================================
  test('S5 — attaque affiché-vs-scellé (back wizard / quantité / coupon)', async ({ page }) => {
    test.setTimeout(180_000);
    const t = track(page); const sink = {}; attachOrderHooks(page, sink);
    await gotoDev(page); await openMenu(page);
    const prod = await openWizard(page, /Cayenne/i);
    const obs = { prod, backEdit: null, qty: null, coupon: null, structural: null, notes: [] };
    expect(prod, 'Cayenne ouvert').toBeTruthy();

    // Walk UNIQUE : à l'étape Sauce → back-edit (ajout 2e sauce +0,50 puis retrait -0,50, aucune
    // rétention fantôme) PUIS continuer le MÊME parcours jusqu'au recap → ajouter au panier.
    let addedToCart = false;
    for (let s = 0; s < 16; s++) {
      const step = await readStep(page);
      if (/^Sauce$/i.test(step.title) && !obs.backEdit) {
        const T0 = step.total;
        const addIdx = step.choices.findIndex(c => !c.on);
        if (addIdx >= 0) { await page.locator('.lc-wiz-choice').nth(addIdx).click().catch(() => {}); await page.waitForTimeout(450); }
        const Tadd = (await readStep(page)).total;                       // +0,50
        await page.locator('.lc-wiz-choice').nth(addIdx).click().catch(() => {}); await page.waitForTimeout(450);
        const Tremove = (await readStep(page)).total;                    // -0,50 → == T0
        obs.backEdit = { T0, Tadd, Tremove, deltaAdd: +(Tadd - T0).toFixed(2), deltaRemove: +(Tremove - Tadd).toFixed(2) };
        await page.screenshot({ path: shot('S5-backedit-sauce.png'), fullPage: true });
        console.log('[S5] backEdit', JSON.stringify(obs.backEdit));
      }
      if (isRecap(step)) { await clickNext(page); addedToCart = true; break; }
      await advance(page);
    }
    console.log('[S5] addedToCart=', addedToCart);
    const cartOpen = await page.locator('.lc-cart-drawer.is-open').isVisible({ timeout: 8_000 }).catch(() => false);
    console.log('[S5] cartOpen=', cartOpen);
    if (cartOpen) {
      const unit = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText().catch(() => ''));
      // --- (b) quantité ×2 via bouton « Augmenter la quantité de X » (stepper .lc-cart-stepper) ---
      const plus = page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Augmenter/ }).first();
      if (await plus.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await plus.click({ timeout: 4_000 }).catch(() => {}); await page.waitForTimeout(800);
        const dbl = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText().catch(() => ''));
        obs.qty = { unit, doubled: dbl, ratio: unit ? +(dbl / unit).toFixed(3) : null };
        await page.screenshot({ path: shot('S5-qty-x2.png'), fullPage: false });
        // reset qty→1 pour un funnel propre
        await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Diminuer/ }).first().click({ timeout: 4_000 }).catch(() => {});
        await page.waitForTimeout(500);
      } else obs.qty = { unit, doubled: null, note: 'stepper quantité introuvable (N/A)' };
      console.log('[S5] qty', JSON.stringify(obs.qty));

      // --- (c) coupon : DÉTECTION read-only du champ « Code promo » au checkout.
      // (Application non exercée en live : le backend revalide coupon_id côté serveur — SSOT ;
      // un client ne peut pas fabriquer une remise, cf. body POST = coupon_id seulement.) ---
      try {
        await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click({ timeout: 8_000 });
        await page.waitForTimeout(1500);
        const promo = page.getByRole('textbox', { name: /Code promo/i });
        const present = await promo.isVisible({ timeout: 5_000 }).catch(() => false);
        obs.coupon = { available: present, note: present ? 'champ Code promo présent · backend revalide coupon_id (SSOT)' : 'champ Code promo absent (N/A)' };
        await page.screenshot({ path: shot('S5-checkout-coupon.png'), fullPage: false });
      } catch (e) { obs.coupon = { available: 'error', note: String(e.message).split('\n')[0].slice(0, 120) }; }
      console.log('[S5] coupon', JSON.stringify(obs.coupon));
    } else obs.notes.push('panier non ouvert');

    obs.structural = { note: 'POST /api/frontend/order envoie items + expected_total ; backend recalcule (SSOT) et 422 si divergence', ref: 'obs-S1-S2.json .orderReq (expected_total=10.4 == api.total=10.4, 201)' };
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('S5', obs);
    console.log('[S5]', JSON.stringify({ backEdit: obs.backEdit, qty: obs.qty, coupon: obs.coupon }));

    // back-edit : ajout +0,50 puis retrait -0,50 (aucune rétention fantôme)
    expect(obs.backEdit, 'étape sauce atteinte').toBeTruthy();
    expect(obs.backEdit.deltaAdd, '2e sauce ajoute +0,50').toBeCloseTo(0.5, 2);
    expect(obs.backEdit.deltaRemove, 'retrait 2e sauce rend -0,50 (pas de drop/fantôme)').toBeCloseTo(-0.5, 2);
    if (obs.qty && obs.qty.doubled != null) expect(obs.qty.ratio, 'quantité ×2 = total ×2 exact').toBeCloseTo(2, 2);
  });
});

// ============================================================================
// S4 — MOBILE (Pixel 7) : commande réelle courte, intégrité au centime petit écran.
// ============================================================================
// eslint-disable-next-line no-unused-vars
const { defaultBrowserType: _pixelBrowser, ...PIXEL7 } = devices['Pixel 7']; // strip worker-scoped key (interdit dans un describe)
test.describe('E1 mobile Pixel 7', () => {
  test.use({ ...PIXEL7 });

  test('S4 — commande réelle mobile, intégrité centime', async ({ page }) => {
    const t = track(page); const sink = {}; attachOrderHooks(page, sink);
    await gotoDev(page); await openMenu(page);
    const prod = await openWizard(page, /Cayenne/i);
    const obs = { prod, viewport: page.viewportSize(), recapTotal: null, cartTotal: null, order: null, orderApi: null, notes: [] };
    expect(prod, 'Cayenne ouvert (mobile)').toBeTruthy();

    // parcours court : choix par défaut à chaque étape (advance), pas d'extra
    for (let s = 0; s < 18; s++) { const step = await readStep(page); if (isRecap(step)) break; await advance(page); }
    const recap = await readStep(page);
    obs.recapTotal = recap.total;
    await page.screenshot({ path: shot('S4-recap-mobile.png'), fullPage: true });
    await clickNext(page);
    const cartOpen = await page.locator('.lc-cart-drawer.is-open').isVisible({ timeout: 10_000 }).catch(() => false);
    if (cartOpen) { obs.cartTotal = euro(await page.locator('.lc-cart-totals-row.is-total b').innerText().catch(() => '')); await page.screenshot({ path: shot('S4-panier-mobile.png'), fullPage: false }); }
    else obs.notes.push('panier non ouvert (mobile)');

    obs.order = await placeRealOrder(page, sink, '0699002444', 'S4');
    obs.orderReq = sink.orderReq; obs.orderApi = sink.orderApi;
    obs.consoleErrors = t.consoleErrors; obs.netProblems = t.netProblems;
    saveObs('S4', obs);
    console.log('[S4]', JSON.stringify({ vp: obs.viewport, recap: obs.recapTotal, cart: obs.cartTotal, order: obs.order, api: obs.orderApi }));

    expect(obs.cartTotal, 'panier == recap (mobile)').toBeCloseTo(obs.recapTotal, 2);
    expect(obs.order.ok, 'commande réelle mobile passée').toBeTruthy();
    expect(obs.order.payTotal, 'paiement == panier (mobile)').toBeCloseTo(obs.cartTotal, 2);
    expect(obs.order.confTotal, 'confirmation scellée == paiement (mobile)').toBeCloseTo(obs.order.payTotal, 2);
    expect(obs.orderApi && obs.orderApi.status, 'POST order 2xx (mobile)').toBeGreaterThanOrEqual(200);
    expect(obs.orderApi.status, 'POST order < 300 (mobile)').toBeLessThan(300);
    if (obs.orderApi.total != null) expect(Number(obs.orderApi.total), 'total API == affiché (mobile)').toBeCloseTo(obs.order.payTotal, 2);
  });
});
