// E2E FINAL sur le site EN LIGNE (https://www.lecayenne.fr) — LECTURE SEULE.
//
// Aucune commande n'est créée, aucun compte, aucune adresse enregistrée : un garde-fou réseau ABORT toute requête qui n'est pas un GET, à la seule
// exception du devis de livraison (POST /delivery/quote, un calcul sans écriture) ; le test final exige que le journal ne contienne aucune autre écriture.
// On teste donc tout le parcours jusqu'au bouton « Confirmer » EXCLU, et on valide les lignes de commande que le site construirait contre le
// catalogue RÉEL servi par la production (aucune requête de commande n'est envoyée).
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/site-en-ligne-final-2026-10-24/L-en-ligne.spec.js --workers=1 --retries=0 --reporter=line
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SITE = 'https://www.lecayenne.fr';
const OUT = path.join(__dirname, '..', '..', '..', 'reports', 'test-e2e', 'site-en-ligne-final-2026-10-24', 'captures');
fs.mkdirSync(OUT, { recursive: true });
const LEDGER = { checks: [], blockedWrites: [], allowedWrites: [], consoleErrors: [], pageErrors: [], observations: [] };
const check = (id, ok, detail) => { LEDGER.checks.push({ id, ok: !!ok, detail }); console.log(`[LIVE] ${ok ? 'OK ' : 'KO '} ${id}${detail ? ' — ' + JSON.stringify(detail).slice(0, 220) : ''}`); };
const norm = (s) => String(s || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' ').trim();

async function newCtx(browser, mobile) {
  const ctx = await browser.newContext(mobile
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'fr-FR', timezoneId: 'Europe/Paris' }
    : { viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  await ctx.route('**/*', async (route) => {
    const r = route.request(); const u = r.url(); const m = r.method();
    if (/js\.mollie\.com/.test(u)) return route.abort();
    if (m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS') {
      if (m === 'POST' && /\/api\/frontend\/delivery\/quote(\?|$)/.test(u)) { LEDGER.allowedWrites.push(`${m} ${new URL(u).pathname}`); return route.continue(); }
      LEDGER.blockedWrites.push(`${m} ${new URL(u).pathname}`); return route.abort();
    }
    return route.continue();
  });
  return ctx;
}
function watch(page) {
  page.on('pageerror', (e) => LEDGER.pageErrors.push(String(e.message).slice(0, 200)));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource.*(mollie|net::ERR_FAILED)/i.test(m.text())) LEDGER.consoleErrors.push(m.text().slice(0, 200)); });
}
async function accueil(page) {
  await page.goto(SITE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60000 });
  await page.waitForTimeout(3000);
}
async function versPagePaiement(page, produit = 'Sprite 33cl') {
  await page.goto(SITE + '/#menu', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30000 });
  await page.waitForTimeout(1200);
  await page.locator(`[aria-label="Voir ${produit}"]`).first().click();
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
  for (let i = 0; i < 8 && !(await page.locator('.lc-cart-drawer.is-open').count()); i++) {
    const n = page.locator('.lc-wiz-foot-next'); if (await n.isVisible({ timeout: 1500 }).catch(() => false)) await n.click(); else await page.waitForTimeout(400);
  }
  const drawer = page.locator('.lc-cart-drawer.is-open'); if (!(await drawer.count())) await page.locator('.lc-nav-btn-cart').first().click();
  await expect(drawer).toBeVisible({ timeout: 15000 });
  await drawer.getByRole('button', { name: /Passer commande/ }).click();
  for (let i = 0; i < 6 && !(await page.locator('.lcf-page').count()); i++) {
    const b = page.getByRole('button', { name: /Non merci|Continuer|Régler ma commande/ }).first(); if (await b.isVisible({ timeout: 1500 }).catch(() => false)) await b.click(); await page.waitForTimeout(1200);
  }
  await expect(page.locator('.lcf-page')).toBeVisible({ timeout: 20000 });
  await page.waitForTimeout(800);
}
const modesPaiement = (page) => page.locator('[role="radiogroup"][aria-label="Mode de paiement"] .lcf-paymethod-name').allInnerTexts();
async function choisirLivraison(page) {
  await page.locator('[role="radiogroup"][aria-label="Mode de retrait"]').getByRole('radio', { name: /Livraison/ }).click();
  await expect(page.locator('input[aria-label="Adresse de livraison"]')).toBeVisible({ timeout: 10000 });
}
async function devis(page, saisie, motif) {
  const input = page.locator('input[aria-label="Adresse de livraison"]');
  await input.fill(''); await page.waitForTimeout(200);
  const sugg = page.waitForResponse((r) => /\/delivery\/suggest/.test(r.url()), { timeout: 20000 });
  await input.pressSequentially(saisie, { delay: 25 }); await sugg; await page.waitForTimeout(700);
  const options = await page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
  expect(options.length, `suggestions « ${saisie} »`).toBeGreaterThan(0);
  await input.fill(options.find((o) => motif.test(o)) || options[0]);
  const qp = page.waitForResponse((r) => /\/delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 25000 });
  // INSTRUMENT : sur mobile l'en-tête fixe fait 129 px ; le défilement automatique de Playwright pouvait caler « Calculer » SOUS lui (clic sans effet,
  // prouvé : un défilement naturel + un vrai appui tactile envoient le devis, HTTP 200). On centre donc le bouton comme le ferait un client.
  const calc = page.getByRole('button', { name: /^Calculer$/ });
  await calc.evaluate((el) => el.scrollIntoView({ block: 'center' })); await page.waitForTimeout(400);
  await calc.click();
  const q = await (await qp).json(); await page.waitForTimeout(1000);
  return { q, options };
}

test.describe.configure({ mode: 'serial' });
test.describe('Site EN LIGNE — E2E final, lecture seule', () => {
  test.setTimeout(300000);

  test('L-0 la production sert les versions déployées (jetons ?v=) et la livraison est active', async ({ request }) => {
    const html = await (await request.get(SITE + '/')).text();
    const tok = (re) => (html.match(re) || [])[1];
    const t = { menu: tok(/data\/menu\.js\?v=([^"]+)/), api: tok(/api\.js\?v=([^"]+)/), wiz: tok(/compiled\/wizard-v2\.js\?v=([^"]+)/), funnel: tok(/compiled\/funnel\.js\?v=([^"]+)/) };
    check('L-0 jetons servis', t.menu === '20261024fix2' && t.api === '20261024via1' && t.wiz === '20261024via1' && t.funnel === '20261024pay2', t);
    const base = (html.match(/name="api-base-url" content="([^"]+)"/) || [])[1]; const key = (html.match(/name="api-key" content="([^"]+)"/) || [])[1];
    const st = await (await request.get(`${base}/api/frontend/delivery/status`, { headers: { 'X-API-KEY': key, Accept: 'application/json' } })).json();
    check('L-0 livraison active côté serveur + barème', st.enabled === true && st.grid && st.grid.home_fee === 3.9, { enabled: st.enabled, home_fee: st.grid && st.grid.home_fee, tiers: st.grid && st.grid.tiers });
    expect(t.menu).toBe('20261024fix2'); expect(st.enabled).toBe(true);
  });

  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`L-1${vp} annonce « la livraison est lancée » : visible, fermable, mémorisée, absente au paiement`, async ({ browser }) => {
      const ctx = await newCtx(browser, mobile); const page = await ctx.newPage(); watch(page);
      try {
        await accueil(page);
        const ann = page.locator('[data-testid="annonce-livraison"]');
        await expect(ann).toBeVisible({ timeout: 15000 });
        const txt = norm(await ann.innerText());
        check(`L-1${vp} texte de l'annonce`, /La livraison est lancée/.test(txt) && /dès 3,90 €/.test(txt), txt);
        const box = await ann.boundingBox(); const nav = await page.locator('.lc-nav').first().boundingBox();
        check(`L-1${vp} annonce tout en haut, au-dessus de la nav`, box.y <= 1 && nav.y >= box.y + box.height - 2, { annonceTop: box.y, hauteur: Math.round(box.height), navTop: Math.round(nav.y) });
        if (mobile) check('L-1M hauteur ≤ 100 px (15 % d\'écran)', box.height <= 100, Math.round(box.height));
        const x = await ann.getByRole('button', { name: /Fermer l'annonce/ }).boundingBox();
        check(`L-1${vp} croix ≥ 44 px`, x.width >= 44 && x.height >= 44, { w: x.width, h: x.height });
        await page.screenshot({ path: path.join(OUT, `L1${vp}-accueil-annonce.png`) });
        await ann.getByRole('button', { name: /Commander/ }).click(); await page.waitForTimeout(800);
        check(`L-1${vp} « Commander » mène à la carte`, /#menu/.test(page.url()), page.url());
        await ann.getByRole('button', { name: /Fermer l'annonce/ }).click();
        await expect(ann).toHaveCount(0);
        await page.reload({ waitUntil: 'domcontentloaded' }); await page.waitForTimeout(3000);
        check(`L-1${vp} fermée = reste fermée après rechargement`, (await ann.count()) === 0, null);
        await versPagePaiement(page);
        check(`L-1${vp} absente sur la page de paiement`, (await ann.count()) === 0, null);
        expect(await ann.count()).toBe(0);
      } finally { await ctx.close(); }
    });
  }

  test('L-2 accueil et pied de page disent « livraison » ; la page à emporter reste celle d\'avant', async ({ browser }) => {
    const ctx = await newCtx(browser, false); const page = await ctx.newPage(); watch(page);
    try {
      await accueil(page);
      const hero = norm(await page.locator('.lcx-hero-faits').innerText());
      const foot = norm(await page.locator('footer, .lc-footer').first().innerText());
      check('L-2 accueil : « À emporter ou en livraison »', /À emporter ou en livraison/.test(hero), hero);
      check('L-2 pied de page : « retrait sur place ou livraison »', /retrait sur place ou livraison/.test(foot), (foot.match(/Commande en ligne[^.]*\./) || [null])[0]);
      await versPagePaiement(page);
      const modes = await modesPaiement(page);
      const txt = norm(await page.locator('.lcf-page').innerText());
      check('L-2 à emporter : règlements = Payer sur place + carte en ligne', JSON.stringify(modes) === JSON.stringify(['Payer sur place', 'Carte bancaire (en ligne)']), modes);
      check('L-2 à emporter : aucun élément de livraison', (await page.locator('[data-testid="delivery-timing"]').count()) === 0 && (await page.locator('input[aria-label="Adresse de livraison"]').count()) === 0 && !/Carte à la livraison|Espèces à la livraison/.test(txt), null);
      await page.screenshot({ path: path.join(OUT, 'L2-emporter-paiement.png') });
      expect(modes).toEqual(['Payer sur place', 'Carte bancaire (en ligne)']);
    } finally { await ctx.close(); }
  });

  for (const mobile of [false, true]) {
    const vp = mobile ? 'M' : 'D';
    test(`L-3${vp} paiement EN LIVRAISON : adresse, frais, préparation 15–20 min, heure, trois règlements, refus hors zone`, async ({ browser }) => {
      const ctx = await newCtx(browser, mobile); const page = await ctx.newPage(); watch(page);
      try {
        await accueil(page); await versPagePaiement(page); await choisirLivraison(page);
        // Hénin-Beaumont → 3,90 €
        const h = await devis(page, '12 rue elie gruyelle', /H[ée]nin/i);
        const recap = norm(await page.locator('.lcf-summary, .lcf-recap').first().innerText().catch(() => ''));
        check(`L-3${vp} devis Hénin-Beaumont autorisé à 3,90 €`, h.q.allowed === true && Number(h.q.fee) === 3.9, { allowed: h.q.allowed, fee: h.q.fee, travel: h.q.travel_minutes });
        const page_ = norm(await page.locator('.lcf-page').innerText());
        check(`L-3${vp} frais affichés`, /Livraison 3,90 €|Livraison\s*3,90/.test(page_) || /3,90 €/.test(page_), (page_.match(/.{0,30}3,90 €.{0,20}/) || [null])[0]);
        const timing = norm(await page.locator('[data-testid="delivery-timing"]').innerText());
        check(`L-3${vp} préparation « 15 à 20 min » (= serveur) puis trajet`, new RegExp(`Préparation : ${h.q.prep_minutes_low} à ${h.q.prep_minutes_high} min`).test(timing) && /selon le trajet/.test(timing) && h.q.prep_minutes_low === 15 && h.q.prep_minutes_high === 20, timing);
        check(`L-3${vp} aucun « 30 à 45 » sur la page`, !/30\s*(à|-|–)\s*45/.test(norm(await page.locator('body').innerText())), null);
        const slots = await page.locator('.lcf-pickup-slot').count();
        check(`L-3${vp} heure de livraison : « Au plus tôt » + créneaux`, slots >= 2 && /Au plus tôt/.test(page_), { slots });
        const modes = await modesPaiement(page);
        check(`L-3${vp} trois règlements`, JSON.stringify(modes) === JSON.stringify(['Carte bancaire (en ligne)', 'Carte à la livraison', 'Espèces à la livraison']), modes);
        const cta = page.locator('.lcf-cta-bar-next');
        await page.getByRole('radio', { name: /Carte à la livraison/ }).click();
        check(`L-3${vp} « Carte à la livraison » : bouton « Confirmer ma commande » actif`, (await cta.isEnabled()) && /Confirmer ma commande/.test(norm(await cta.innerText())), norm(await cta.innerText()));
        await page.getByRole('radio', { name: /Espèces à la livraison/ }).click();
        check(`L-3${vp} « Espèces à la livraison » : bouton actif`, (await cta.isEnabled()), null);
        await page.getByRole('radio', { name: /Carte bancaire \(en ligne\)/ }).click(); await page.waitForTimeout(500);
        check(`L-3${vp} « Carte en ligne » : libellé « Payer par carte »`, /Payer par carte/.test(norm(await cta.innerText())), norm(await cta.innerText()));
        await page.screenshot({ path: path.join(OUT, `L3${vp}-livraison-paiement.png`), fullPage: false });
        if (mobile) {
          const w = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: window.innerWidth }));
          check('L-3M aucun débordement horizontal', w.sw <= w.vw + 1, w);
          const small = await page.evaluate(() => Array.from(document.querySelectorAll('.lcf-page [role="radio"]')).map((e) => Math.round(e.getBoundingClientRect().height)).filter((h) => h < 44));
          check('L-3M zones tactiles des règlements ≥ 44 px', small.length === 0, small);
        }
        // hors zone : Lille → refus, bouton bloqué, Uber Eats
        const l = await devis(page, '10 rue des stations 59000 lille', /Lille/i);
        await page.waitForTimeout(500);
        const refus = norm(await page.locator('.lcf-page').innerText());
        check(`L-3${vp} Lille : refusé + Uber Eats + bouton bloqué`, l.q.allowed === false && /trop loin/.test(refus) && /Uber Eats/.test(refus) && !(await page.locator('.lcf-cta-bar-next').isEnabled()), { allowed: l.q.allowed });
        // commune proche : le prix suit le barème du serveur
        const o = await devis(page, '1 rue jean jaures 62590 oignies', /Oignies/i);
        const tiers = [{ max: 5, fee: 4.9 }, { max: 8, fee: 5.9 }, { max: 10, fee: 6.9 }];
        const attendu = o.q.allowed ? (tiers.find((t) => o.q.distance_km <= t.max) || {}).fee : null;
        check(`L-3${vp} Oignies : frais = barème du serveur`, o.q.allowed ? Number(o.q.fee) === attendu : true, { allowed: o.q.allowed, km: o.q.distance_km, fee: o.q.fee, attendu });
        expect(h.q.allowed).toBe(true); expect(modes).toEqual(['Carte bancaire (en ligne)', 'Carte à la livraison', 'Espèces à la livraison']);
      } finally { await ctx.close(); }
    });
  }

  test('L-4 une seule viande obligatoire, poulet mariné par défaut, +2,50 € par viande en plus (Cayenne, Classique, Galettes)', async ({ browser }) => {
    const ctx = await newCtx(browser, false); const page = await ctx.newPage(); watch(page);
    try {
      for (const nom of ['Cayenne', 'Sandwich Classique', 'Galette Classique', 'Galette Cayenne']) {
        await page.goto(SITE + '/#menu', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30000 }); await page.waitForTimeout(1000);
        await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
        await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
        await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15000 }); await page.waitForTimeout(1500);
        for (let i = 0; i < 6 && !/CHOISIS\s+\d+\s+VIANDE/i.test(await page.locator('.lc-wiz-title').first().innerText()); i++) { await page.locator('.lc-wiz-foot-next').click(); await page.waitForTimeout(500); }
        const wiz = page.locator('.lc-wiz'); let t = norm(await wiz.innerText());
        const avant = (t.match(/Continuer\s+(\d+,\d{2}) €/) || [])[1];
        check(`L-4 ${nom} : « Choisis 1 viande », Poulet mariné pré-cochée (Inclus), suite possible`, /CHOISIS 1 VIANDE/i.test(t) && /1 sélectionné · 1 minimum/.test(t) && /Poulet mariné Inclus/.test(t) && (await page.locator('.lc-wiz-foot-next').isEnabled()), { extrait: t.slice(60, 230) });
        const offertes = (t.match(/ÉTAPE[^]*?Continuer/)[0].match(/(Viande Hachée|Poulet mariné|Mixte|Nuggets|Mexicanos|Tenders)/g) || []);
        LEDGER.observations.push({ produit: nom, viandesProposees: [...new Set(offertes)] });
        await wiz.getByText('Viande Hachée', { exact: true }).first().click(); await page.waitForTimeout(500);
        t = norm(await wiz.innerText()); const apres = (t.match(/Continuer\s+(\d+,\d{2}) €/) || [])[1];
        const d = Math.round((Number(apres.replace(',', '.')) - Number(avant.replace(',', '.'))) * 100);
        check(`L-4 ${nom} : 2ᵉ viande = +2,50 € (${avant} → ${apres})`, d === 250 && /2 sélectionné/.test(t), { d });
        if (nom === 'Cayenne') await page.screenshot({ path: path.join(OUT, 'L4-cayenne-viandes.png') });
      }
    } finally { await ctx.close(); }
  });

  test('L-4b compositions fixes (6 burgers, Suprême) : aucune étape « Choisis N viande(s) » — le serveur n\'en propose aucune', async ({ browser }) => {
    const ctx = await newCtx(browser, false); const page = await ctx.newPage(); watch(page);
    try {
      for (const nom of ['Cheese Burger', 'Chicken Burger', 'Double Cheese', 'Fish Burger', 'Big Burger', 'Grill Burger', 'Suprême']) {
        await page.goto(SITE + '/#menu', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30000 }); await page.waitForTimeout(900);
        await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
        await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
        await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15000 }); await page.waitForTimeout(1500);
        const titres = [];
        for (let i = 0; i < 8; i++) {
          titres.push(norm(await page.locator('.lc-wiz-title').first().innerText().catch(() => '')));
          const nx = page.locator('.lc-wiz-foot-next');
          if (!(await nx.isEnabled().catch(() => false)) || /RÉCAP/i.test(titres[titres.length - 1])) break;
          await nx.click(); await page.waitForTimeout(400);
        }
        check(`L-4b ${nom} : aucune étape viande`, !/CHOISIS\s+\d+\s+VIANDE/i.test(titres.join(' | ')), titres);
        expect(titres.join(' | ')).not.toMatch(/CHOISIS\s+\d+\s+VIANDE/i);
      }
    } finally { await ctx.close(); }
  });

  test('L-5 les 40 produits : la ligne de commande que le site construit est valide pour le catalogue de PRODUCTION (aucune commande envoyée)', async ({ browser }) => {
    const ctx = await newCtx(browser, false); const page = await ctx.newPage(); watch(page);
    const details = {};
    page.on('response', async (r) => { const m = r.url().match(/\/api\/frontend\/item\/details\/(\d+)/); if (m && r.status() === 200) { try { details[m[1]] = (await r.json()).data; } catch (e) { /* ignoré */ } } });
    try {
      await accueil(page);
      const res = await page.evaluate(async () => {
        const M = window.LC.menu; const out = [];
        for (const it of M.items) {
          const meats = M.meats.map((x) => x);
          let st = { viandes: [] };
          try {
            const ch = await window.LC.api.itemChoicesFor(it); const allowed = ch && ch.meats ? meats.filter((m) => ch.meats.indexOf(window.LC.api._norm(m.name)) !== -1) : meats;
            const pref = allowed.find((m) => /poulet mariné/i.test(m.name)) || allowed[0];
            const n = Number(it.viandes || 0);
            if (n > 0 && allowed.length) st = { viandes: (pref ? [pref] : []).concat(allowed.filter((m) => m !== pref)).slice(0, n).map((m) => m.id) };
          } catch (e) { /* choix inconnus → état vide */ }
          try {
            const r = await window.LC.api.resolveLine({ slug: it.slug, name: it.name, qty: 1, viande_count: it.viandes, viandes: it.viandes, state: st });
            out.push({ name: it.name, ok: true, item_id: r.item_id, vars: (r.item_variations || []).map((v) => v.id), extras: (r.item_extras || []).map((e) => ({ id: e.id, q: e.quantity })), n: Number(it.viandes || 0) });
          } catch (e) { out.push({ name: it.name, ok: false, err: (e && e.message) || String(e) }); }
        }
        return out;
      });
      // INSTRUMENT : un détail déjà servi par le cache de la page n'est pas re-téléchargé (donc pas capturé) — on le relit en GET, lecture seule.
      const meta = await page.evaluate(() => ({ base: document.querySelector('meta[name="api-base-url"]').content, key: document.querySelector('meta[name="api-key"]').content }));
      for (const l of res) {
        if (l.ok && !details[String(l.item_id)]) {
          const r = await page.request.get(`${meta.base}/api/frontend/item/details/${l.item_id}`, { headers: { 'X-API-KEY': meta.key, Accept: 'application/json' } });
          if (r.ok()) details[String(l.item_id)] = (await r.json()).data;
        }
      }
      const problemes = [];
      for (const l of res) {
        if (!l.ok) { problemes.push(`${l.name} : refusé par le site — ${l.err}`); continue; }
        const d = details[String(l.item_id)]; if (!d) { problemes.push(`${l.name} : détail de production non capturé (${l.item_id})`); continue; }
        const attrOf = {}; const meta = {};
        Object.keys(d.variations || {}).forEach((a) => { const arr = Array.isArray(d.variations[a]) ? d.variations[a] : [d.variations[a]]; arr.forEach((x) => { attrOf[x.id] = a; }); const ia = (arr[0] && arr[0].item_attribute) || {}; meta[a] = { min: ia.min_select || 0, max: ia.max_select || 0, nom: ia.name }; });
        const cnt = {}; l.vars.forEach((id) => { if (attrOf[id] == null) problemes.push(`${l.name} : variation ${id} inconnue de la production`); else cnt[attrOf[id]] = (cnt[attrOf[id]] || 0) + 1; });
        Object.keys(meta).forEach((a) => { const c = cnt[a] || 0; if (c < meta[a].min) problemes.push(`${l.name} : « ${meta[a].nom} » manque (${c}<${meta[a].min})`); if (meta[a].max && c > meta[a].max) problemes.push(`${l.name} : « ${meta[a].nom} » en trop (${c}>${meta[a].max})`); });
        const exById = {}; (d.extras || []).forEach((e) => { exById[e.id] = e; }); l.extras.forEach((e) => { if (!exById[e.id]) problemes.push(`${l.name} : extra ${e.id} inconnu de la production`); });
      }
      check(`L-5 ${res.length} produits : lignes valides (min/max, ids connus)`, problemes.length === 0, problemes.slice(0, 6));
      expect(res.length).toBeGreaterThanOrEqual(38);
      expect(problemes, problemes.join('\n')).toEqual([]);
    } finally { await ctx.close(); }
  });

  test('L-6 pages d\'information : livraison annoncée, plus de « pas de livraison », sitemap', async ({ request }) => {
    const pages = ['livraison-henin-beaumont.html', 'commander.html', 'a-propos.html', 'horaires.html', 'carte.html', 'manger-tard-henin-beaumont.html'];
    const faux = [];
    for (const p of pages) {
      const r = await request.get(`${SITE}/${p}`); const t = await r.text();
      if (r.status() !== 200) faux.push(`${p} : HTTP ${r.status()}`);
      if (/pas de livraison en propre|100\s?% à emporter/i.test(t)) faux.push(`${p} : dit encore « pas de livraison »`);
    }
    const liv = await (await request.get(`${SITE}/livraison-henin-beaumont.html`)).text();
    check('L-6 page livraison : 10 km, dès 3,90 €, Uber Eats en alternative', /10 km/.test(liv) && /3,90/.test(liv) && /Uber Eats/.test(liv), null);
    const sm = await (await request.get(`${SITE}/sitemap.xml`)).text();
    check('L-6 sitemap contient la page livraison', /livraison-henin-beaumont/.test(sm), null);
    check('L-6 pages 200 et sans « pas de livraison »', faux.length === 0, faux);
    const cgv = await (await request.get(`${SITE}/legal/cgv.html`)).text();
    LEDGER.observations.push({ cgv_dit_pas_de_livraison: /pas de livraison en propre/i.test(cgv) });
    expect(faux).toEqual([]);
  });

  test('L-7 garde-fou : aucune écriture envoyée à la production, console propre', async () => {
    const ecritures = LEDGER.blockedWrites.concat(LEDGER.allowedWrites.filter((w) => !/delivery\/quote/.test(w)));
    check('L-7 aucune écriture (hors devis) tentée ni passée', ecritures.length === 0, { bloquees: LEDGER.blockedWrites, devisPostes: LEDGER.allowedWrites.length });
    check('L-7 aucune erreur de page', LEDGER.pageErrors.length === 0, LEDGER.pageErrors.slice(0, 3));
    check('L-7 aucune erreur console', LEDGER.consoleErrors.length === 0, LEDGER.consoleErrors.slice(0, 3));
    fs.writeFileSync(path.join(OUT, '..', 'ledger.json'), JSON.stringify(LEDGER, null, 1));
    expect(ecritures).toEqual([]); expect(LEDGER.pageErrors).toEqual([]);
  });
});
