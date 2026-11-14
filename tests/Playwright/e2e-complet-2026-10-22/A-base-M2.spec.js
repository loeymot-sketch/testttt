// [VERIF GLOBALE 2026-10-21 · M2] Site web : tunnel livraison (calcul dynamique) et compte client.
// Tour 1 = constater, pas corriger. Base LOCALE seulement. Données préfixées E2E-SITE-M2.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/verif-globale-2026-10-21/M2-site-livraison-compte.spec.js --workers=1 --retries=0 --reporter=line
//
// Surfaces : site local :8011 (copie du dépôt déployé, api-base-url → :8766), caisse :8766/admin/pos (pos@).
// État de la livraison (`order_setup_delivery`) : lu AVANT, restauré APRÈS, quoi qu'il arrive (afterAll).
const { test, expect, request: pwRequest } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BACKEND = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const SITE = process.env.VG_SITE_URL || 'http://127.0.0.1:8011';
const SHOTS = path.join(ROOT, 'tests/e2e/__screenshots__/e2e-complet-A/base-M2');
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const uuid = () => require('crypto').randomUUID();

/** PHP dans l'application (tinker) → dernière ligne imprimée. Aucun shell : les apostrophes sont sûres. */
function php(code) {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 90_000 });
  return out.trim().split('\n').filter(Boolean).pop();
}

// Adresses RÉELLES (Base Adresse Nationale), distances vérifiées par le géocodeur serveur avant la spec :
const ADRESSES = {
  henin:    { q: '437 rue Elie Gruyelle 62110 Hénin-Beaumont', fee: 3.9, city: 'Hénin-Beaumont', tier: 'home' },
  noyelles: { q: '12 rue Pasteur 62950 Noyelles-Godault',      fee: 4.9, city: 'Noyelles-Godault', tier: 'radius_1' }, // 2,70 km
  carvin:   { q: '12 rue Pasteur 62220 Carvin',                fee: 5.9, city: 'Carvin', tier: 'radius_2' },           // 7,51 km
  lens:     { q: '10 rue de la Paix 62300 Lens',               fee: 6.9, city: 'Lens', tier: 'radius_3' },             // 8,96 km
  douai:    { q: '12 rue de Bellain 59500 Douai',              fee: 0,   city: 'Douai', tier: 'outside' },             // 10,79 km → refus
  oignies:  { q: '1 rue Jean Jaurès 62590 Oignies',            fee: 5.9, city: 'Oignies', tier: 'radius_2' },          // BAN = rue seule → numéro conservé
};
const LIGNE_EAU = { id: 1007, slug: 'eau-plate', name: 'Eau Plate 50cl', price: 1.0, qty: 1, state: {} };
const ITEM_EAU_DB = 58;

// ─── état partagé entre les tests (describe serial) ───────────────────────────────────────────────────────
const S = {
  initialEnabled: null, restored: null,
  api: null, posHeaders: null, siteKey: API_KEY,
  stamp: Date.now(), email: '', phone: '', devCode: '', customerToken: '',
  orders: [], timings: [], findings: [], notes: [],
  ctxSite: null, pageSite: null, snapSite: null,
};
S.email = `e2e-site-m2-${S.stamp}@lecayenne-test.fr`;
S.phone = '06' + String(S.stamp).slice(-8);

function enregistreTiming(page, tag) {
  page.on('response', (r) => {
    try {
      const u = r.url();
      if (!/\/api\//.test(u)) return;
      const t = r.request().timing();
      const ms = t && t.responseEnd >= 0 ? Math.round(t.responseEnd) : null;
      S.timings.push({ tag, method: r.request().method(), url: u.replace(BACKEND, '').slice(0, 140), status: r.status(), ms });
    } catch (_) { /* ignore */ }
  });
}
const note = (s) => { S.notes.push(s); console.log('[M2] ' + s); };
const finding = (f) => { S.findings.push(f); console.log('[M2 DÉFAUT] ' + JSON.stringify(f)); };

/** Nouveau contexte « site » : clé d'API locale injectée dans index.html (la copie porte la clé du VPS, refusée 400 ici). */
async function contexteSite(browser, mobile) {
  const ctx = await browser.newContext(mobile
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, locale: 'fr-FR', timezoneId: 'Europe/Paris' }
    : { viewport: { width: 1280, height: 800 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  await ctx.route((url) => url.origin === SITE && (url.pathname === '/' || url.pathname === '/index.html'), async (route) => {
    const res = await route.fetch();
    let body = await res.text();
    body = body.replace(/(name="api-key" content=")[^"]*(")/, `$1${API_KEY}$2`).replace(/(name="api-base-url" content=")[^"]*(")/, `$1${BACKEND}$2`);
    await route.fulfill({ response: res, body });
  });
  return ctx;
}

async function ouvrirSite(page) {
  await page.goto(SITE + '/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1200);
}

/** Accueil → panier semé (Eau plate) → « Passer commande » → (upsell) → page unique de commande. */
async function ouvrirTunnel(page) {
  await ouvrirSite(page);
  await page.evaluate((l) => { localStorage.setItem('lc.cart.v1', JSON.stringify({ lines: [l], savedAt: Date.now() })); }, LIGNE_EAU);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1500);
  await page.locator('.lc-nav-btn-cart').first().click();
  await page.waitForTimeout(600);
  const go = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Passer commande/.test(x.textContent) && x.offsetParent); if (b) { b.click(); return true; } return false; });
  expect(go, 'bouton « Passer commande » présent dans le panier').toBe(true);
  for (let i = 0; i < 6; i++) {
    await page.waitForTimeout(900);
    if (await page.locator('.lcf-page').count()) break;
    await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /Régler ma commande|Non merci|[Cc]ontinuer/.test(x.textContent) && x.offsetParent); if (b) b.click(); });
  }
  await expect(page.locator('.lcf-page')).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(800);
}

/** Saisit une adresse, clique « Calculer », renvoie la réponse serveur du devis + sa durée. */
async function devis(page, texte, _retry = false) {
  const champ = page.getByLabel('Adresse de livraison');
  await champ.fill('');
  await champ.fill(texte);
  const echecs = [];
  const onFail = (req) => { if (/delivery\/quote/.test(req.url())) echecs.push(req.failure() && req.failure().errorText); };
  page.on('requestfailed', onFail);
  let r = null;
  const t0 = Date.now();
  for (let essai = 1; essai <= 2 && !r; essai++) {
    const attente = page.waitForResponse((x) => x.request().method() === 'POST' && /\/api\/frontend\/delivery\/quote/.test(x.url()), { timeout: 20_000 }).catch(() => null);
    await page.getByRole('button', { name: /^Calculer$/ }).click();
    r = await attente;
    if (!r) note(`devis « ${texte} » : aucune réponse en 20 s (essai ${essai}) ; requêtes échouées : ${JSON.stringify(echecs)} ; bouton = ${await page.getByRole('button', { name: /^(Calculer|…)$/ }).innerText().catch(() => '?')}`);
  }
  page.off('requestfailed', onFail);
  expect(r, `réponse au devis « ${texte} »`).toBeTruthy();
  const ms = Date.now() - t0;
  const json = await r.json().catch(() => null);
  await page.waitForTimeout(500);
  if (json && json.enabled === false && !_retry) {
    note('INTERFÉRENCE : devis répondu « suspendue » (bascule par un tiers) → remise + nouvel essai');
    await assurerLivraisonActive();
    return devis(page, texte, true);
  }
  return { status: r.status(), json, ms };
}

async function etatLivraisonServeur() {
  const r = await S.api.get('/api/frontend/delivery/status', { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
  const j = await r.json();
  return { status: r.status(), enabled: !!j.enabled };
}
/** Jeton API pos@ sur un APPAREIL distinct (X-Device-Id) : la connexion navigateur de pos@ ne le révoque pas. */
async function loginPosApi() {
  clearFoodKingRateLimits();
  const r = await S.api.post('/api/auth/login', { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', 'X-Device-Id': `e2e-site-m2-api-${S.stamp}` }, data: { email: 'pos@lecayenne.fr', password: '123456' } });
  const login = await r.json().catch(() => ({}));
  expect(login.token, `connexion API pos@ (HTTP ${r.status()})`).toBeTruthy();
  S.posHeaders = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${login.token}`, Accept: 'application/json' };
}
async function basculerLivraisonParApi(enabled) {
  let r = await S.api.post('/api/admin/pos-delivery/toggle', { headers: S.posHeaders, data: { enabled } });
  if (r.status() === 401) { note('jeton API pos@ révoqué (401) → reconnexion'); await loginPosApi(); r = await S.api.post('/api/admin/pos-delivery/toggle', { headers: S.posHeaders, data: { enabled } }); }
  const j = await r.json().catch(() => ({}));
  return { status: r.status(), enabled: j.enabled };
}
/** 10 missions tournent en même temps (M4 bascule la livraison) : on s'assure de l'état voulu juste avant d'agir. */
async function assurerLivraisonActive() {
  const e = await etatLivraisonServeur();
  if (!e.enabled) {
    note('INTERFÉRENCE : la livraison a été suspendue par un tiers (M4 ?) → remise par l\'API avant de continuer');
    const r = await basculerLivraisonParApi(true);
    expect(r.enabled).toBe(true);
  }
}

/** Saisie fiable sous charge : on relit la valeur (un rendu React tardif peut vider le champ). */
async function remplir(loc, valeur) {
  for (let i = 0; i < 3; i++) {
    await loc.fill(valeur);
    await loc.page().waitForTimeout(300);
    if ((await loc.inputValue()) === valeur) return;
  }
  await expect(loc).toHaveValue(valeur);
}
/** Code OTP le plus récent pour une clé (téléphone du compte). */
function codeEnBase(cle) {
  return php(`echo \\DB::table('otps')->where('phone', '${cle}')->latest('created_at')->value('token');`);
}
/** `throttle:3,5` nu sur /guest-signup/verify : clé sha1('|'.ip), partagée par toutes les missions de 127.0.0.1. */
function viderLimiteurVerify() {
  php(`$l = app(\\Illuminate\\Cache\\RateLimiter::class); foreach (['127.0.0.1', '::1'] as $ip) { $l->clear(sha1('|' . $ip)); } echo 'ok';`);
}

test.describe.configure({ mode: 'serial' });
test.use({ launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] } });

test.beforeAll(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  expect(process.version, 'node v22 obligatoire').toMatch(/^v22\./);
  S.api = await pwRequest.newContext({ baseURL: BACKEND });
  for (const [url, label] of [[BACKEND + '/login', 'backend'], [SITE + '/index.html', 'site']]) {
    const r = await S.api.get(url);
    expect(r.status(), `${label} répond`).toBe(200);
  }
  // État INITIAL de la livraison, lu en base (pas en cache)
  S.initialEnabled = php(`echo app(\\App\\Services\\Delivery\\DeliveryAvailability::class)->isEnabled() ? 'ENABLED' : 'DISABLED';`);
  note(`order_setup_delivery INITIAL = ${S.initialEnabled}`);
  await loginPosApi();
});

test.afterAll(async () => {
  // Restauration INCONDITIONNELLE de l'état initial de la livraison.
  try {
    if (S.api) {
      await loginPosApi();
      const wanted = S.initialEnabled === 'ENABLED';
      const r = await basculerLivraisonParApi(wanted);
      S.restored = php(`echo app(\\App\\Services\\Delivery\\DeliveryAvailability::class)->isEnabled() ? 'ENABLED' : 'DISABLED';`);
      note(`restauration livraison → demandé ${wanted ? 'ENABLED' : 'DISABLED'} (HTTP ${r.status}) ; relu en base = ${S.restored}`);
    }
  } catch (e) { note('ÉCHEC restauration : ' + (e && e.message)); }
  try { if (S.ctxSite) await S.ctxSite.close(); } catch (_) { /* ignore */ }
  try { if (S.api) await S.api.dispose(); } catch (_) { /* ignore */ }
  fs.writeFileSync(path.join(SHOTS, '_m2-summary.json'), JSON.stringify({
    initialEnabled: S.initialEnabled, restored: S.restored, email: S.email, phone: S.phone, orders: S.orders,
    slow: S.timings.filter((t) => t.ms !== null && t.ms > 1000), timings: S.timings, findings: S.findings, notes: S.notes,
  }, null, 2));
});

// ─── 1. Livraison SUSPENDUE → message du site (mobile 390) ───────────────────────────────────────────────
test('1. livraison suspendue : le tunnel renvoie vers Uber Eats, le devis public répond « suspendue »', async ({ browser }) => {
  test.setTimeout(240_000);
  const off = await basculerLivraisonParApi(false);
  expect(off.status).toBe(200);
  expect(off.enabled).toBe(false);
  expect((await etatLivraisonServeur()).enabled).toBe(false);
  const q = await (await S.api.post('/api/frontend/delivery/quote', { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { q: ADRESSES.henin.q } })).json();
  expect(q).toMatchObject({ enabled: false, allowed: false, reason: 'suspended', suggest_uber_eats: true });

  S.ctxSite = await contexteSite(browser, true);
  S.pageSite = await S.ctxSite.newPage();
  enregistreTiming(S.pageSite, 'mobile');
  S.snapSite = attachMegaAuditRecorder(S.pageSite, SHOTS);
  const page = S.pageSite;
  await page.emulateMedia({ reducedMotion: 'reduce' });
  // Le site relit l'état au montage du tunnel : on juge l'écran d'après la réponse qu'il a RÉELLEMENT reçue.
  // Un tiers (M4) bascule la livraison en parallèle : si la page a reçu « active », on suspend de nouveau et on recommence.
  let recu = null;
  const onStatus = async (r) => { if (/\/api\/frontend\/delivery\/status/.test(r.url())) recu = await r.json().catch(() => null); };
  page.on('response', onStatus);
  for (let essai = 1; essai <= 4; essai++) {
    recu = null;
    await ouvrirTunnel(page);
    await page.waitForTimeout(800);
    if (recu && recu.enabled === false) break;
    note(`INTERFÉRENCE (essai ${essai}) : la page a reçu status.enabled=${recu && recu.enabled} alors que nous venions de suspendre → nouvelle suspension`);
    expect((await basculerLivraisonParApi(false)).enabled).toBe(false);
  }
  page.off('response', onStatus);
  expect(recu && recu.enabled, 'la page a reçu « suspendue » du serveur').toBe(false);
  const modes = page.getByRole('radiogroup', { name: 'Mode de retrait' });
  await expect(modes).toBeVisible();
  const lienUber = modes.getByRole('link', { name: /Uber Eats/ });
  await expect(lienUber, 'livraison suspendue → tuile « Livraison · Sur Uber Eats → »').toBeVisible({ timeout: 15_000 });
  expect(await lienUber.getAttribute('href')).toMatch(/ubereats\.com/);
  await expect(modes.getByRole('radio', { name: /Livraison/ })).toHaveCount(0);
  await lienUber.evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(400);
  await S.snapSite.snap('01-mobile-tunnel-livraison-suspendue-uber-eats');
});

// ─── 2. Remise depuis la CAISSE (pos@, deux appuis) → le site relit l'état auprès du serveur ─────────────
test('2. remise depuis la caisse (2 appuis) : le site voit la livraison active, état lu auprès du serveur', async ({ browser }) => {
  test.setTimeout(240_000);
  const ctxPos = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  const pos = await ctxPos.newPage();
  enregistreTiming(pos, 'caisse');
  const rec = attachMegaAuditRecorder(pos, SHOTS);
  try {
    await loginAsPosOperator(pos);
    await pos.goto('/admin/pos');
    await pos.waitForLoadState('networkidle');
    await pos.waitForTimeout(2500);
    const toggle = pos.locator('[data-testid="pos-delivery-toggle"]');
    await expect(toggle).toBeVisible({ timeout: 20_000 });
    if (/active/.test(await toggle.innerText())) {
      // Un tiers (M4) a remis la livraison entre le test 1 et celui-ci : on exerce d'abord la SUSPENSION (2 appuis).
      note('INTERFÉRENCE : la caisse affiche « active » (remise par un tiers depuis le test 1) → suspension par la caisse d\'abord');
      await toggle.click();
      await expect(toggle).toContainText('Confirmer : suspendre ?');
      const att = pos.waitForResponse((r) => r.request().method() === 'POST' && /pos-delivery\/toggle/.test(r.url()), { timeout: 20_000 });
      await toggle.click();
      expect((await att).status()).toBe(200);
      await expect(toggle).toContainText('suspendue', { timeout: 15_000 });
      expect((await etatLivraisonServeur()).enabled).toBe(false);
      await rec.snap('02b-caisse-suspension-par-la-caisse');
    }
    await expect(toggle).toContainText('suspendue', { timeout: 15_000 });
    // Preuve « deux appuis » = AUCUNE requête de bascule émise par la caisse après le 1er appui (l'état global, lui,
    // peut être changé par un tiers en parallèle : on mesure ce que CETTE caisse envoie).
    let postsToggle = 0;
    const onReqToggle = (req) => { if (req.method() === 'POST' && /pos-delivery\/toggle/.test(req.url())) postsToggle++; };
    pos.on('request', onReqToggle);
    await toggle.click();
    await expect(toggle, '1er appui = armement, rien n\'est envoyé').toContainText('Confirmer : remettre ?');
    await pos.waitForTimeout(700);
    expect(postsToggle, 'après 1 appui, la caisse n\'a envoyé aucune bascule').toBe(0);
    await rec.snap('02-caisse-toggle-arme-premier-appui');
    const attente = pos.waitForResponse((r) => r.request().method() === 'POST' && /pos-delivery\/toggle/.test(r.url()), { timeout: 20_000 });
    await toggle.click();
    const r = await attente;
    pos.off('request', onReqToggle);
    expect(postsToggle, '2e appui = une seule bascule envoyée').toBe(1);
    expect(r.status()).toBe(200);
    await expect(toggle).toContainText('active', { timeout: 15_000 });
    await expect(pos.locator('[data-testid="pos-delivery-board-open"]')).toBeVisible();
    await rec.snap('03-caisse-livraison-active-apres-deuxieme-appui');
    expect((await etatLivraisonServeur()).enabled).toBe(true);
  } finally {
    rec.dispose();
    await ctxPos.close();
  }

  // Le site : rechargé, il doit INTERROGER le serveur (pas un cache) et montrer la tuile Livraison.
  const page = S.pageSite;
  const statusCalls = [];
  const onResp = async (resp) => { if (/\/api\/frontend\/delivery\/status/.test(resp.url())) statusCalls.push({ status: resp.status(), body: await resp.json().catch(() => null) }); };
  page.on('response', onResp);
  for (let essai = 1; essai <= 4; essai++) {
    await assurerLivraisonActive();
    statusCalls.length = 0;
    await ouvrirTunnel(page);
    await page.waitForTimeout(800);
    if (statusCalls.some((c) => c.body && c.body.enabled === true)) break;
    note(`INTERFÉRENCE (essai ${essai}) : la page a reçu ${JSON.stringify(statusCalls.map((c) => c.body && c.body.enabled))} après la remise → nouvel essai`);
  }
  const modes = page.getByRole('radiogroup', { name: 'Mode de retrait' });
  const radioLivraison = modes.getByRole('radio', { name: /Livraison/ });
  await expect(radioLivraison, 'tuile Livraison (radio) après remise').toBeVisible({ timeout: 20_000 });
  await expect(modes.getByRole('link', { name: /Uber Eats/ })).toHaveCount(0);
  page.off('response', onResp);
  expect(statusCalls.length, 'GET /api/frontend/delivery/status appelé par le site après rechargement').toBeGreaterThan(0);
  expect(statusCalls.some((c) => c.status === 200 && c.body && c.body.enabled === true)).toBe(true);
  await radioLivraison.evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(400);
  await S.snapSite.snap('04-mobile-tunnel-livraison-active-tuile');
});

// ─── 3. Devis dynamique : 5 paliers, refus > 10 km, numéro conservé, complément d'adresse ─────────────────
test('3. adresses réelles → 3,90 / 4,90 / 5,90 / 6,90 €, > 10 km refusé → Uber Eats, numéro de rue conservé', async () => {
  test.setTimeout(300_000);
  const page = S.pageSite;
  await assurerLivraisonActive();
  await page.getByRole('radio', { name: /Livraison/ }).click();
  await expect(page.getByLabel('Adresse de livraison')).toBeVisible({ timeout: 10_000 });

  // Suggestions BAN via le serveur pendant la frappe (datalist) ; le numéro tapé doit figurer dans la suggestion.
  const champ = page.getByLabel('Adresse de livraison');
  const attenteSuggest = page.waitForResponse((r) => /\/api\/frontend\/delivery\/suggest/.test(r.url()), { timeout: 20_000 });
  await champ.fill('1 rue jean jaures oignies');
  const sr = await attenteSuggest;
  const sj = await sr.json().catch(() => ({}));
  expect(sr.status()).toBe(200);
  await page.waitForTimeout(600);
  const options = await page.locator('#lcf-delivery-suggest option').evaluateAll((els) => els.map((e) => e.value));
  note('suggestions pour « 1 rue jean jaures oignies » : ' + JSON.stringify(options.slice(0, 3)));
  expect(options.length, 'des suggestions sont proposées').toBeGreaterThan(0);
  if (!/^1 Rue Jean Jaur/.test(options[0] || '')) finding({ id: 'M2-SUGG', severite: 'P2', categorie: 'fonctionnel', preuve: 'suggestion sans le numéro tapé : ' + options[0], repro: 'GET /api/frontend/delivery/suggest?q=1 rue jean jaures oignies' });
  expect(Array.isArray(sj.data)).toBe(true);

  const resultats = {};
  const cas = [
    ['henin', '05-mobile-devis-henin-beaumont-3-90'],
    ['noyelles', '06-mobile-devis-noyelles-godault-4-90'],
    ['carvin', null],
    ['lens', '08-mobile-devis-lens-6-90'],
  ];
  for (const [cle, snapName] of cas) {
    const a = ADRESSES[cle];
    const d = await devis(page, a.q);
    resultats[cle] = { status: d.status, fee: d.json && d.json.fee, km: d.json && d.json.distance_km, label: d.json && d.json.address && d.json.address.label, ms: d.ms, waves: d.json && d.json.waves ? d.json.waves.length : null };
    expect(d.status, `devis ${a.q}`).toBe(200);
    expect(d.json.allowed, `${a.q} livrable`).toBe(true);
    expect(d.json.fee, `palier ${a.q}`).toBe(a.fee);
    expect(d.json.tier).toBe(a.tier);
    expect(d.json.address.city).toBe(a.city);
    const statut = page.getByRole('status').filter({ hasText: /Livraison/ }).first();
    await expect(statut).toBeVisible({ timeout: 10_000 });
    await expect(statut).toContainText(a.fee.toFixed(2).replace('.', ',') + ' €');
    await expect(statut).toContainText(a.city);
    await statut.scrollIntoViewIfNeeded();
    if (snapName) await S.snapSite.snap(snapName);
  }
  // Monotonie des distances/paliers
  expect(resultats.noyelles.km).toBeLessThanOrEqual(5);
  expect(resultats.carvin.km).toBeGreaterThan(5);
  expect(resultats.carvin.km).toBeLessThanOrEqual(8);
  expect(resultats.lens.km).toBeGreaterThan(8);
  expect(resultats.lens.km).toBeLessThanOrEqual(10);

  // > 10 km : refus + proposition Uber Eats
  const dd = await devis(page, ADRESSES.douai.q);
  resultats.douai = { status: dd.status, allowed: dd.json && dd.json.allowed, km: dd.json && dd.json.distance_km, ms: dd.ms };
  expect(dd.status).toBe(200);
  expect(dd.json.allowed).toBe(false);
  expect(dd.json.suggest_uber_eats).toBe(true);
  expect(dd.json.fee).toBe(0);
  const alerte = page.getByRole('alert').filter({ hasText: /trop loin/ });
  await expect(alerte).toBeVisible({ timeout: 10_000 });
  await expect(alerte.getByRole('link', { name: /Uber Eats/ })).toBeVisible();
  await alerte.scrollIntoViewIfNeeded();
  await S.snapSite.snap('09-mobile-refus-douai-plus-de-10-km-uber-eats');
  // Le bouton de commande est bloqué tant que l'adresse n'est pas livrable
  await expect(page.locator('.lcf-cta-bar-next')).toBeDisabled();

  // Numéro de rue conservé quand la BAN ne connaît que la rue
  const doi = await devis(page, ADRESSES.oignies.q);
  resultats.oignies = { status: doi.status, label: doi.json && doi.json.address && doi.json.address.label, fee: doi.json && doi.json.fee, ms: doi.ms };
  expect(doi.status).toBe(200);
  expect(doi.json.fee).toBe(5.9);
  expect(doi.json.address.label, 'numéro conservé dans le libellé compris').toMatch(/^1 Rue Jean Jaur/);
  await expect(page.getByRole('status').filter({ hasText: /Adresse comprise/ })).toContainText('1 Rue Jean Jaur');
  await S.snapSite.snap('10-mobile-numero-conserve-oignies');

  // Complément d'adresse (étage, interphone) : existe-t-il un champ sur le site ?
  const complement = await page.locator('input[autocomplete="address-line2"], input[placeholder*="ompl"], input[aria-label*="ompl"], textarea[placeholder*="ompl"], input[name*="apartment"]').count();
  if (complement === 0) {
    finding({ id: 'M2-001', severite: 'P2', categorie: 'fonctionnel', preuve: 'tunnel livraison : aucun champ complément d\'adresse (étage, interphone) — funnel.jsx:548-598 ne rend que l\'adresse ; funnel.jsx:1181 saveAddress({label, address}) sans apartment', repro: 'site → Livraison → aucune case « complément » ; POST /api/frontend/address envoyé sans apartment' });
  }
  // (défaut M2-001 enregistré ci-dessus ; pas d'assertion bloquante : la chaîne sérielle doit continuer)

  // Géocodeur en panne = refus, jamais un prix inventé (le serveur appelle la BAN ; on simule l'injoignable par une adresse introuvable)
  const dvague = await devis(page, 'Hénin-Beaumont');
  resultats.communeSeule = { status: dvague.status, body: dvague.json };
  expect(dvague.status, 'commune seule = pas une adresse → refus serveur (422/4xx), pas un prix').toBeGreaterThanOrEqual(400);
  await expect(page.getByRole('alert').first()).toBeVisible({ timeout: 10_000 });
  note('devis résultats : ' + JSON.stringify(resultats));
  S.devis = resultats;
});

// ─── 4. Créneaux :00/:30 + commande LIVRAISON depuis le site (inscription invité inline) ──────────────────
test('4. créneaux et commande de livraison depuis le site (inscription invité, paiement sur place)', async () => {
  test.setTimeout(300_000);
  const page = S.pageSite;
  await assurerLivraisonActive();
  const d = await devis(page, ADRESSES.henin.q);
  expect(d.status).toBe(200);
  expect(d.json.fee).toBe(3.9);
  const waves = d.json.waves || [];
  note(`waves renvoyées par le devis : ${waves.length}, libres : ${waves.filter((w) => !w.full).length} ; premières : ${JSON.stringify(waves.slice(0, 4))}`);
  for (const w of waves) expect(w.time, 'grille :00/:30').toMatch(/:(00|30)$/);
  const groupe = page.getByRole('radiogroup', { name: 'Heure de départ de la livraison' });
  if (waves.length) {
    await expect(groupe).toBeVisible({ timeout: 10_000 });
    const radios = groupe.getByRole('radio');
    const libelles = await radios.allInnerTexts();
    note('créneaux affichés : ' + JSON.stringify(libelles));
    const creneauxChoisissables = libelles.length - 1; // moins « Au plus tôt »
    if (creneauxChoisissables === 0 || /vers —/.test(libelles[0] || '')) {
      finding({ id: 'M2-002', severite: 'P1', categorie: 'fonctionnel/timing', preuve: `devis Hénin : ${waves.length} tournées renvoyées, TOUTES full=true avec stops=0 (fermées par un départ livreur antérieur) ; le site affiche « Au plus tôt vers — » et aucun créneau, mais laisse commander`, repro: 'GET /api/frontend/delivery/waves → full:true, stops:0 sur chaque créneau du jour ; DeliveryWaveService::waveHasLeft ferme une tournée dès qu\'UN arrêt porte left_at, sans borne de temps' });
    }
    // (défaut M2-002 enregistré ci-dessus)
    await groupe.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(400);
  } else {
    finding({ id: 'M2-002', severite: 'P1', categorie: 'fonctionnel/timing', preuve: 'le devis ne renvoie aucune tournée (waves=[])', repro: 'POST /api/frontend/delivery/quote {q: "437 rue Elie Gruyelle 62110 Hénin-Beaumont"}' });
  }
  await S.snapSite.snap('11-mobile-creneaux-livraison');

  // Coordonnées (inscription invité « e-mail d'abord », inline)
  await remplir(page.locator('#auth-email'), S.email);
  const r1 = page.waitForResponse((r) => r.request().method() === 'POST' && /guest-signup\/email-login/.test(r.url()), { timeout: 30_000 });
  await page.getByRole('button', { name: /Recevoir le code par email/ }).click();
  const j1 = await (await r1).json();
  expect(j1.known, 'e-mail inconnu → le serveur demande prénom + téléphone').toBe(false);
  await expect(page.locator('#auth-first')).toBeVisible({ timeout: 10_000 });
  await remplir(page.locator('#auth-first'), 'E2E-SITE-M2');
  await remplir(page.locator('#auth-phone'), S.phone);
  await page.locator('#lc-contact-block').scrollIntoViewIfNeeded();
  await S.snapSite.snap('12-mobile-coordonnees-inscription-depliee');
  const r2 = page.waitForResponse((r) => r.request().method() === 'POST' && /guest-signup\/email-login/.test(r.url()), { timeout: 30_000 });
  await page.getByRole('button', { name: /Créer mon compte et recevoir le code/ }).click();
  const resp2 = await r2;
  const j2 = await resp2.json().catch(() => ({}));
  S.signupHttp = resp2.status();
  if (resp2.status() === 200) {
    S.devCode = String(j2.dev_code || '') || codeEnBase(S.phone);
    expect(S.devCode, 'code OTP (dev_code en local, sinon otps.token)').toMatch(/^\d{4}$/);
    await expect(page.locator('#auth-otp')).toBeVisible({ timeout: 10_000 });
    await page.locator('#auth-otp').fill(S.devCode);
    await S.snapSite.snap('13-mobile-coordonnees-code-saisi');
    viderLimiteurVerify();
    const r3 = page.waitForResponse((r) => r.request().method() === 'POST' && /guest-signup\/verify/.test(r.url()), { timeout: 30_000 });
    await page.getByRole('button', { name: /Valider mes coordonnées/ }).click();
    const resp3 = await r3;
    expect(resp3.status(), 'verify OTP').toBe(200);
    await expect(page.locator('#lc-contact-block')).toContainText('Coordonnées confirmées', { timeout: 15_000 });
    await S.snapSite.snap('14-mobile-coordonnees-confirmees');
  } else {
    // SMTP factice du .env local (smtp.example.com) : l'envoi du code échoue APRÈS l'écriture de la ligne `otps`.
    // On constate ce que voit le client, puis on valide le code lu en base par la VRAIE fonction du site.
    const msg = String(j2.message || '');
    note(`inscription → HTTP ${resp2.status()} : ${msg.slice(0, 160)}`);
    await page.waitForTimeout(800);
    const texteBloc = (await page.locator('#lc-contact-block').innerText()).replace(/\s+/g, ' ');
    const brutAffiche = /smtp|stream_socket|getaddrinfo|Connection could not/i.test(texteBloc);
    note('bloc coordonnées après échec : ' + texteBloc.slice(0, 300));
    await page.locator('#lc-contact-block').scrollIntoViewIfNeeded();
    await S.snapSite.snap('13-mobile-inscription-echec-envoi-code');
    if (brutAffiche) {
      finding({ id: 'M2-007', severite: 'P2', categorie: 'erreur-muette/honnêteté', preuve: 'échec d\'envoi du code : le client lit le message technique brut en anglais (« ' + texteBloc.match(/Connection could not[^.]*|stream_socket[^.]*/i)?.[0]?.slice(0, 120) + ' »)', fichier: 'app/Http/Controllers/Auth/GuestSignupController.php:226 (renvoie $exception->getMessage() en 422) ; funnel.jsx:1780 (affiche e.message tel quel)', repro: 'MAIL_HOST injoignable → site → Livraison → e-mail inconnu → prénom + téléphone → « Créer mon compte » → message SMTP brut' });
    }
    S.devCode = codeEnBase(S.phone);
    expect(S.devCode, 'ligne otps écrite avant l\'échec SMTP').toMatch(/^\d{4}$/);
    viderLimiteurVerify();
    const ver = await page.evaluate(async ([phone, code, email]) => {
      try { const r = await window.LC.api.guestVerify(phone, code, undefined, email, 'E2E-SITE-M2'); return { ok: true, token: !!(r && r.token), name: r && r.user && r.user.name }; }
      catch (e) { return { ok: false, status: e && e.status, message: e && e.message }; }
    }, [S.phone, S.devCode, S.email]);
    note('verify via api.guestVerify (code lu en base) → ' + JSON.stringify(ver));
    expect(ver.ok && ver.token, 'compte créé au verify').toBe(true);
    // Le bloc coordonnées ne se met pas à jour (état React local) : rechargement, puis on refait mode + devis.
    await ouvrirTunnel(page);
    await page.getByRole('radio', { name: /Livraison/ }).click();
    const d2 = await devis(page, ADRESSES.henin.q);
    expect(d2.json.fee).toBe(3.9);
    await S.snapSite.snap('14-mobile-connecte-tunnel-livraison-recalcule');
  }
  S.customerToken = await page.evaluate(() => localStorage.getItem('lecayenne.authToken'));
  expect(S.customerToken).toBeTruthy();

  // Paiement sur place → « Confirmer ma commande 4,90 € »
  const surPlace = page.locator('button[role="radio"]', { hasText: 'Payer sur place' });
  if (await surPlace.count()) await surPlace.first().click();
  const cta = page.locator('.lcf-cta-bar-next');
  await cta.scrollIntoViewIfNeeded();
  await expect(cta).toBeEnabled({ timeout: 10_000 });
  await expect(cta).toContainText('4,90');
  await page.getByRole('radiogroup', { name: 'Mode de paiement' }).evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(400);
  await S.snapSite.snap('15-mobile-avant-confirmation-total-4-90');
  let bodyEnvoye = null;
  const onReq = (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { bodyEnvoye = JSON.parse(req.postData() || '{}'); } catch (_) { bodyEnvoye = { raw: req.postData() }; } } };
  page.on('request', onReq);
  const rOrder = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 60_000 });
  await cta.click();
  const respOrder = await rOrder;
  page.off('request', onReq);
  const jo = await respOrder.json().catch(() => ({}));
  expect(respOrder.status(), 'POST /api/frontend/order : ' + JSON.stringify(jo).slice(0, 300)).toBeLessThan(300);
  const o = jo.data || jo;
  S.orders.push({ via: 'site-ui', id: o.id, serial: o.order_serial_no, total: o.total });
  note(`commande site créée #${o.id} ${o.order_serial_no} total=${o.total} ; body envoyé: ${JSON.stringify(bodyEnvoye && { address_id: bodyEnvoye.address_id, delivery_charge: bodyEnvoye.delivery_charge, delivery_distance_km: bodyEnvoye.delivery_distance_km, delivery_slot_at: bodyEnvoye.delivery_slot_at, expected_total: bodyEnvoye.expected_total, payment_method: bodyEnvoye.payment_method })}`);
  await page.waitForTimeout(2500);
  await S.snapSite.snap('16-mobile-confirmation-commande');
  const texteConfirm = (await page.locator('#root').innerText()).replace(/\s+/g, ' ');
  const confirmMentionneLivraison = /livraison/i.test(texteConfirm);
  const confirmMentionneAdresse = /Gruyelle/i.test(texteConfirm);
  const confirmMentionneCreneau = /\d{1,2}[:h]\d{2}/.test(texteConfirm);
  note(`confirmation : mention livraison=${confirmMentionneLivraison}, adresse=${confirmMentionneAdresse}, heure=${confirmMentionneCreneau}`);
  if (!confirmMentionneAdresse) {
    finding({ id: 'M2-003', severite: 'P2', categorie: 'fonctionnel', preuve: 'page de confirmation sans adresse de livraison ni heure de départ (funnel.jsx ConfirmationPage:2324+ ne lit ni deliveryQuote ni deliverySlotAt)', repro: 'commande livraison site → confirmation : texte = ' + texteConfirm.slice(0, 300) });
  }
  // (défaut M2-003 enregistré ci-dessus)

  // Base : orders.delivery_*, delivery_stops, addresses
  const base = JSON.parse(php(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${o.id});
    $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${o.id})->first();
    $a = \\App\\Models\\Address::withoutGlobalScopes()->where('user_id', $o->user_id)->orderByDesc('id')->first();
    $oa = $o->address;
    echo json_encode(['charge' => (float) $o->delivery_charge, 'total' => (float) $o->total, 'tier' => $o->delivery_tier, 'km' => (float) $o->delivery_distance_km, 'slot' => (string) $o->delivery_slot_at, 'type' => (int) $o->order_type, 'surface' => $o->source_surface, 'status' => (int) $o->status, 'pm' => (int) $o->payment_method,
      'stop' => $s ? ['wave' => (string) $s->wave_at, 'collect' => $s->collect_method, 'due' => (int) $s->amount_due_cents, 'fixed' => (bool) $s->slot_fixed, 'boy' => $s->delivery_boy_id] : null,
      'addr' => $a ? ['id' => $a->id, 'address' => $a->address, 'apartment' => $a->apartment, 'city' => $a->city, 'cp' => $a->postal_code, 'geo' => $a->geocode_status, 'lat' => (float) $a->latitude] : null,
      'snapshot' => $oa ? ['address' => $oa->address, 'apartment' => $oa->apartment, 'city' => $oa->city, 'cp' => $oa->postal_code, 'lat' => (float) $oa->latitude] : null,
      'nb_adresses_client' => \\App\\Models\\Address::withoutGlobalScopes()->where('user_id', $o->user_id)->count()]);`));
  note('base après commande site : ' + JSON.stringify(base));
  S.baseSite = base;
  expect(base).toMatchObject({ charge: 3.9, total: 4.9, tier: 'home', type: 5, pm: 1 });
  expect(base.stop).not.toBeNull();
  expect(base.stop).toMatchObject({ collect: 'cash', due: 490 });
  expect(base.addr).toMatchObject({ city: 'Hénin-Beaumont', cp: '62110', geo: 'OK' });
  expect(base.addr.address).toMatch(/437 Rue Elie Gruyelle/);
  expect(base.snapshot, 'adresse figée sur la commande (order_addresses)').toMatchObject({ city: 'Hénin-Beaumont', cp: '62110' });
  expect(base.snapshot.address).toBe(base.addr.address);
  expect(base.nb_adresses_client, 'adresse enregistrée une seule fois').toBe(1);
  expect(base.stop.wave, 'tournée sur la grille :00/:30').toMatch(/:(00|30):00$/);
  const slotJour = String(base.stop.wave).slice(0, 10);
  const aujourdhui = new Date().toISOString().slice(0, 10);
  if (slotJour !== aujourdhui) {
    finding({ id: 'M2-004', severite: 'P1', categorie: 'fonctionnel/timing', preuve: `commande ASAP passée le ${aujourdhui} ${new Date().toTimeString().slice(0, 5)} → delivery_slot_at = ${base.stop.wave} (LENDEMAIN), sans aucun message au client (confirmation muette sur l'heure)`, repro: 'toutes les tournées du jour fermées (left_at) → DeliveryWaveService::pickAsapWave boucle 48 crans et retient le premier créneau libre, même J+1' });
  }
  // (défaut M2-004 enregistré ci-dessus)
});

// ─── 5. API : fraude sur le prix, paliers, créneau validé AVANT paiement, hors rayon ─────────────────────
test('5. API : delivery_charge forgé ignoré, palier réel facturé, créneau validé avant paiement, hors rayon refusé', async () => {
  test.setTimeout(240_000);
  await assurerLivraisonActive();
  const h = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${S.customerToken}`, Accept: 'application/json' };
  const items = JSON.stringify([{ item_id: ITEM_EAU_DB, item_price: 1, quantity: 1, discount: 0, total_price: 1, item_variations: [], item_extras: [], instruction: '' }]);
  const commande = (extra) => S.api.post('/api/frontend/order', { headers: { ...h, 'X-Idempotency-Key': uuid() }, data: { branch_id: 1, order_type: 5, source: 5, is_advance_order: 10, payment_method: 1, delivery_time: 'ASAP', items, ...extra } });
  const lireOrdre = (id) => JSON.parse(php(`$o = \\App\\Models\\Order::withoutGlobalScopes()->find(${id}); $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${id})->first(); echo json_encode(['delivery_time' => $o->delivery_time, 'charge' => (float) $o->delivery_charge, 'total' => (float) $o->total, 'tier' => $o->delivery_tier, 'km' => (float) $o->delivery_distance_km, 'slot' => (string) $o->delivery_slot_at, 'fixed' => $s ? (bool) $s->slot_fixed : null, 'wave' => $s ? (string) $s->wave_at : null]);`));

  // Adresse Lens (6,90 €) avec complément — enregistrée par l'API comme le ferait le site s'il avait le champ
  const t0 = Date.now();
  const ra = await S.api.post('/api/frontend/address', { headers: h, data: { label: 'E2E-SITE-M2 Lens ' + S.stamp.toString(36), address: ADRESSES.lens.q, apartment: 'Étage 3, interphone E2E-SITE-M2' } });
  S.timings.push({ tag: 'api', method: 'POST', url: '/api/frontend/address (géocodage BAN)', status: ra.status(), ms: Date.now() - t0 });
  const ja = await ra.json();
  expect(ra.status(), JSON.stringify(ja).slice(0, 200)).toBeLessThan(300);
  const addrLens = ja.data || ja;
  expect(addrLens.id).toBeGreaterThan(0);
  const liste = await (await S.api.get('/api/frontend/address', { headers: h })).json();
  const mienne = (liste.data || []).find((x) => x.id === addrLens.id);
  expect(mienne, 'adresse listée pour le client').toBeTruthy();
  expect(mienne.apartment).toBe('Étage 3, interphone E2E-SITE-M2');
  expect(String(mienne.address)).toMatch(/10 Rue de la Paix 62300 Lens/);

  // FRAUDE : delivery_charge 0 + distance 0 → le serveur doit facturer 6,90 €
  const rf = await commande({ address_id: addrLens.id, delivery_charge: 0, delivery_distance_km: 0 });
  const jf = await rf.json().catch(() => ({}));
  expect(rf.status(), 'commande Lens forgée : ' + JSON.stringify(jf).slice(0, 300)).toBeLessThan(300);
  const of = jf.data || jf;
  S.orders.push({ via: 'api-fraude-lens', id: of.id, serial: of.order_serial_no, total: of.total });
  const bf = lireOrdre(of.id);
  note('fraude delivery_charge=0 → base : ' + JSON.stringify(bf));
  if (bf.charge !== 6.9) finding({ id: 'M2-P0-FRAUDE', severite: 'P0', categorie: 'sécurité/prix', preuve: `delivery_charge envoyé 0 accepté : base charge=${bf.charge}`, repro: 'POST /api/frontend/order {order_type:5, address_id Lens, delivery_charge:0, delivery_distance_km:0}' });
  expect(bf).toMatchObject({ charge: 6.9, total: 7.9, tier: 'radius_3' });
  expect(bf.km).toBeGreaterThan(8);
  expect(Number(of.total)).toBe(7.9);

  // FRAUDE bis : expected_total forgé à 1,01 (frais 0,01) → garde WEB-TOTAL-GUARD = 422
  const rg = await commande({ address_id: addrLens.id, delivery_charge: 0.01, delivery_distance_km: 0.1, expected_total: 1.01 });
  note(`expected_total forgé 1,01 → HTTP ${rg.status()} ${(await rg.text()).slice(0, 160)}`);
  expect(rg.status()).toBe(422);

  // Autres paliers par l'API (adresses enregistrées, serveur recalcule) : Noyelles 4,90 ; Carvin 5,90 ; Hénin 3,90 (déjà en 4.)
  for (const [cle, snap] of [['noyelles', 4.9], ['carvin', 5.9]]) {
    const r = await S.api.post('/api/frontend/address', { headers: h, data: { label: `E2E-SITE-M2 ${cle} ${S.stamp.toString(36)}`, address: ADRESSES[cle].q } });
    const j = await r.json();
    expect(r.status(), cle).toBeLessThan(300);
    const id = (j.data || j).id;
    const rc = await commande({ address_id: id, delivery_charge: 0, delivery_distance_km: 0 });
    const jc = await rc.json().catch(() => ({}));
    expect(rc.status(), `commande ${cle} : ` + JSON.stringify(jc).slice(0, 200)).toBeLessThan(300);
    const oc = jc.data || jc;
    S.orders.push({ via: 'api-' + cle, id: oc.id, serial: oc.order_serial_no, total: oc.total });
    const bc = lireOrdre(oc.id);
    note(`palier ${cle} → base ${JSON.stringify(bc)}`);
    expect(bc.charge).toBe(snap);
    expect(bc.total).toBe(1 + snap);
  }

  // HORS RAYON : Douai (10,79 km) → l'adresse s'enregistre ? la commande doit être refusée.
  const rd = await S.api.post('/api/frontend/address', { headers: h, data: { label: 'E2E-SITE-M2 Douai ' + S.stamp.toString(36), address: ADRESSES.douai.q } });
  const jd = await rd.json().catch(() => ({}));
  note(`adresse Douai → HTTP ${rd.status()} ${JSON.stringify(jd).slice(0, 160)}`);
  if (rd.status() < 300) {
    const rdo = await commande({ address_id: (jd.data || jd).id, delivery_charge: 0, delivery_distance_km: 0 });
    const txt = await rdo.text();
    note(`commande Douai → HTTP ${rdo.status()} ${txt.slice(0, 200)}`);
    expect(rdo.status(), 'hors rayon refusé').toBe(422);
    if (!/Uber|rayon|km|zone|livr/i.test(txt)) finding({ id: 'M2-HORS-RAYON-MSG', severite: 'P3', categorie: 'fonctionnel', preuve: 'refus hors rayon sans message compréhensible : ' + txt.slice(0, 160), repro: 'POST /api/frontend/order avec address_id Douai' });
  }

  // CRÉNEAU choisi, validé AVANT la création (OrderRequest + ValidatesDeliverySlot)
  const heninId = S.baseSite.addr.id; // orders.address_id reste vide : l'adresse est figée dans order_addresses
  const premierFerme = JSON.parse(php(`$w = app(\\App\\Services\\Delivery\\DeliveryWaveService::class)->availableWaves(1, null, 40); $f = array_values(array_filter($w, fn ($x) => $x['full'])); $l = array_values(array_filter($w, fn ($x) => ! $x['full'])); echo json_encode(['ferme' => $f[0]['wave_at'] ?? null, 'libre' => $l[0]['wave_at'] ?? null]);`));
  note('créneaux : ' + JSON.stringify(premierFerme));
  // grille : :15 → 422
  const base15 = (premierFerme.libre || premierFerme.ferme || '2026-10-22 20:00:00').replace(/:00:00$/, ':15:00').replace(/:30:00$/, ':45:00');
  const r15 = await commande({ address_id: heninId, delivery_charge: 0, delivery_distance_km: 0, delivery_slot_at: base15 });
  const j_r15 = await r15.json().catch(() => ({}));
  note(`créneau hors grille ${base15} → HTTP ${r15.status()} erreurs=${JSON.stringify(j_r15.errors || j_r15.message).slice(0, 200)}`);
  expect(Object.keys(j_r15.errors || {}), 'le refus porte UNIQUEMENT sur le créneau').toEqual(['delivery_slot_at']);
  expect(r15.status(), 'créneau hors grille refusé avant paiement').toBe(422);
  S.slotRefus = S.slotRefus || {};
  // tournée partie / complète → 422
  if (premierFerme.ferme) {
    const rfm = await commande({ address_id: heninId, delivery_charge: 0, delivery_distance_km: 0, delivery_slot_at: premierFerme.ferme });
    const j_rfm = await rfm.json().catch(() => ({}));
    note(`créneau fermé ${premierFerme.ferme} → HTTP ${rfm.status()} erreurs=${JSON.stringify(j_rfm.errors || j_rfm.message).slice(0, 200)}`);
    expect(Object.keys(j_rfm.errors || {}), 'le refus porte UNIQUEMENT sur le créneau').toEqual(['delivery_slot_at']);
    expect(rfm.status(), 'tournée partie/complète refusée avant paiement').toBe(422);
  }
  // trop proche (maintenant) → 422
  const now = new Date(); now.setSeconds(0, 0); now.setMinutes(now.getMinutes() < 30 ? 0 : 30);
  const pad = (n) => String(n).padStart(2, '0');
  const tropProche = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:00`;
  const rp = await commande({ address_id: heninId, delivery_charge: 0, delivery_distance_km: 0, delivery_slot_at: tropProche });
  const j_rp = await rp.json().catch(() => ({}));
  note(`créneau trop proche ${tropProche} → HTTP ${rp.status()} erreurs=${JSON.stringify(j_rp.errors || j_rp.message).slice(0, 200)}`);
  expect(Object.keys(j_rp.errors || {}), 'le refus porte UNIQUEMENT sur le créneau').toEqual(['delivery_slot_at']);
  expect(rp.status()).toBe(422);
  // créneau libre valide → 201, slot_fixed, delivery_slot_at = choisi
  if (premierFerme.libre) {
    const rl = await commande({ address_id: heninId, delivery_charge: 0, delivery_distance_km: 0, delivery_slot_at: premierFerme.libre });
    const jl = await rl.json().catch(() => ({}));
    expect(rl.status(), 'créneau libre accepté : ' + JSON.stringify(jl).slice(0, 200)).toBeLessThan(300);
    const ol = jl.data || jl;
    S.orders.push({ via: 'api-creneau', id: ol.id, serial: ol.order_serial_no, total: ol.total });
    const bl = lireOrdre(ol.id);
    note('créneau choisi → base ' + JSON.stringify(bl));
    expect(bl.slot).toBe(premierFerme.libre);
    expect(bl.fixed).toBe(true);
    expect(bl.charge).toBe(3.9);
  }
  // Paiement titre-restaurant (5) sur une livraison : ACCEPTÉ depuis la décision du propriétaire du 22/10 (« accepter partout »,
  // grappe L2 c6cae96ea/03d8cd801/c5b669eaa) — payé et scellé à la REMISE, jamais au départ ; prix toujours calculé par le serveur.
  const rt = await commande({ address_id: heninId, delivery_charge: 0, delivery_distance_km: 0, payment_method: 5 });
  const jt = await rt.json().catch(() => ({}));
  expect(rt.status(), 'titre-restaurant accepté en livraison : ' + JSON.stringify(jt).slice(0, 200)).toBeLessThan(300);
  const ot = jt.data || jt;
  S.orders.push({ via: 'api-titre-restaurant', id: ot.id, serial: ot.order_serial_no, total: ot.total });
  const btr = lireOrdre(ot.id);
  note('titre-restaurant → base ' + JSON.stringify(btr));
  expect(btr.charge, 'frais forgés à 0 ignorés : palier réel').toBe(3.9);

  // M6-001 (déjà connu, P0) : une commande livraison 'ASAP' fait-elle tomber le KDS ? Le créneau choisi l'évite-t-il ?
  const dtSite = php(`echo \\App\\Models\\Order::withoutGlobalScopes()->find(${S.orders[0].id})->delivery_time;`);
  const dtCreneau = S.orders.find((x) => x.via === 'api-creneau');
  const dtCreneauVal = dtCreneau ? php(`echo \\App\\Models\\Order::withoutGlobalScopes()->find(${dtCreneau.id})->delivery_time;`) : null;
  clearFoodKingRateLimits();
  const rc = await S.api.post('/api/auth/login', { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', 'X-Device-Id': `e2e-site-m2-chef-${S.stamp}` }, data: { email: 'chef@lecayenne.fr', password: '123456' } });
  const chef = await rc.json().catch(() => ({}));
  const hc = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${chef.token}`, Accept: 'application/json' };
  const kds = [];
  for (const u of ['/api/admin/kds-order', '/api/admin/kds-order/history-today']) {
    const t0k = Date.now();
    const r = await S.api.get(u, { headers: hc });
    const txt = await r.text();
    kds.push({ url: u, status: r.status(), ms: Date.now() - t0k, extrait: txt.slice(0, 160) });
  }
  S.kds = { delivery_time_site: dtSite, delivery_time_creneau: dtCreneauVal, kds };
  note('M6-001 (confirmation) : ' + JSON.stringify(S.kds));
});

// ─── 6. Compte : connexion e-mail (modale), fidélité, mes commandes, suppression (mobile) ─────────────────
test('6. compte : connexion e-mail, fidélité, mes commandes, suppression de compte', async () => {
  test.setTimeout(300_000);
  const page = S.pageSite;
  // Déconnexion locale (jeton purgé) puis connexion par e-mail dans la modale
  await ouvrirSite(page);
  await page.evaluate(() => { localStorage.removeItem('lecayenne.authToken'); });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1000);
  await page.locator('.lc-nav-btn-account').first().click();
  await expect(page.locator('#acc-email')).toBeVisible({ timeout: 10_000 });
  await remplir(page.locator('#acc-email'), S.email);
  const r1 = page.waitForResponse((r) => r.request().method() === 'POST' && /guest-signup\/email-login/.test(r.url()), { timeout: 30_000 });
  await page.locator('[data-testid="acc-cta"]').click();
  const resp1 = await r1;
  const j1 = await resp1.json().catch(() => ({}));
  S.loginHttp = resp1.status();
  const clePhone = php(`echo \\App\\Models\\User::withoutGlobalScopes()->where('email', '${S.email}')->value('phone');`) || S.phone;
  if (resp1.status() === 200) {
    expect(j1.known, 'compte retrouvé par e-mail').toBe(true);
    const code = String(j1.dev_code || '') || codeEnBase(clePhone);
    expect(code).toMatch(/^\d{4}$/);
    await expect(page.locator('.lc-otp-cell').first()).toBeVisible({ timeout: 10_000 });
    await S.snapSite.snap('18-mobile-connexion-code');
    viderLimiteurVerify();
    const r2 = page.waitForResponse((r) => r.request().method() === 'POST' && /guest-signup\/verify/.test(r.url()), { timeout: 30_000 });
    await page.locator('.lc-otp-cell').first().click();
    await page.keyboard.type(code, { delay: 80 });
    const resp2 = await r2;
    expect(resp2.status(), 'verify (modale)').toBe(200);
    await expect(page.getByText(/Te\s+voilà/)).toBeVisible({ timeout: 15_000 });
    await S.snapSite.snap('19-mobile-connexion-succes');
    await page.getByRole('button', { name: /Commencer à commander/ }).click();
    await page.waitForTimeout(600);
  } else {
    note(`connexion e-mail (compte connu) → HTTP ${resp1.status()} : ${String(j1.message || '').slice(0, 160)}`);
    await page.waitForTimeout(800);
    const texteModale = (await page.locator('.lc-acc-form').first().innerText()).replace(/\s+/g, ' ');
    note('modale après échec : ' + texteModale.slice(0, 300));
    await S.snapSite.snap('18-mobile-connexion-echec-envoi-code');
    const code = codeEnBase(clePhone);
    expect(code, 'ligne otps écrite avant l\'échec SMTP').toMatch(/^\d{4}$/);
    viderLimiteurVerify();
    const ver = await page.evaluate(async ([code, email]) => {
      try { const r = await window.LC.api.guestVerify('', code, undefined, email); return { ok: true, token: !!(r && r.token), pts: r && r.user && r.user.loyalty_points }; }
      catch (e) { return { ok: false, status: e && e.status, message: e && e.message }; }
    }, [code, S.email]);
    note('connexion via api.guestVerify (compte connu, sans téléphone) → ' + JSON.stringify(ver));
    expect(ver.ok && ver.token, 'compte connu retrouvé au verify par l\'e-mail').toBe(true);
    await page.keyboard.press('Escape');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
    await expect(page.locator('.lc-nav-btn-account').first()).toHaveAttribute('aria-label', 'Mon compte', { timeout: 15_000 });
    await S.snapSite.snap('19-mobile-connecte-apres-verify');
  }
  expect(await page.evaluate(() => localStorage.getItem('lecayenne.authToken'))).toBeTruthy();

  // Fidélité
  await page.goto(SITE + '/#loyalty', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  const rProfile = await page.waitForResponse((r) => /\/api\/profile/.test(r.url()), { timeout: 20_000 }).catch(() => null);
  await page.waitForTimeout(1500);
  const texteFid = (await page.locator('#root').innerText()).replace(/\s+/g, ' ');
  note('fidélité : profile HTTP ' + (rProfile ? rProfile.status() : 'n/a') + ' ; extrait = ' + texteFid.slice(0, 200));
  expect(rProfile && rProfile.status()).toBe(200);
  expect(/pts|points/i.test(texteFid)).toBe(true);
  await S.snapSite.snap('20-mobile-fidelite');

  // Mes commandes
  const rHist = page.waitForResponse((r) => r.request().method() === 'GET' && /\/api\/frontend\/order(\?|$)/.test(r.url()), { timeout: 20_000 });
  await page.goto(SITE + '/#orders', { waitUntil: 'domcontentloaded' });
  const respHist = await rHist;
  expect(respHist.status()).toBe(200);
  const cartes = page.locator('.lcx-order-card');
  await expect(cartes.first()).toBeVisible({ timeout: 20_000 });
  const n = await cartes.count();
  note(`mes commandes : ${n} cartes ; attendues ≥ ${S.orders.length}`);
  expect(n).toBeGreaterThanOrEqual(S.orders.length);
  const serialSite = S.orders[0].serial;
  await expect(page.locator('#root')).toContainText(String(serialSite));
  await page.waitForTimeout(1500);
  await S.snapSite.snap('21-mobile-mes-commandes');
  const carteSite = cartes.filter({ hasText: String(serialSite) }).first();
  await carteSite.getByRole('button', { name: /Voir tout le détail/ }).click();
  await expect(carteSite).toContainText('Eau Plate', { timeout: 15_000 });
  const texteCarte = await carteSite.innerText();
  note('carte commande site : ' + texteCarte.replace(/\s+/g, ' ').slice(0, 300));
  if (/Hénin-Beaumont/.test(texteCarte) && !/livraison/i.test(texteCarte)) {
    finding({ id: 'M2-005', severite: 'P3', categorie: 'fonctionnel', preuve: 'mes commandes : chaque carte affiche « · Hénin-Beaumont » et « Points fidélité en attente du retrait » même pour une livraison (orders.jsx:142,144 : libellés codés en dur, aucune mention du mode livraison ni de l\'adresse)', repro: 'commande livraison Lens → #orders → carte « Hénin-Beaumont »' });
  }

  // Suppression de compte (zone dangereuse, deux temps)
  await page.goto(SITE + '/#loyalty', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1200);
  await page.getByRole('tab', { name: 'Mon compte' }).click();
  const btnSuppr = page.getByRole('button', { name: /^Supprimer mon compte$/ });
  await btnSuppr.scrollIntoViewIfNeeded();
  await btnSuppr.click();
  const confirmer = page.getByRole('button', { name: /Oui, supprimer mon compte/ });
  await expect(confirmer).toBeVisible();
  await confirmer.scrollIntoViewIfNeeded();
  await S.snapSite.snap('23-mobile-suppression-confirmation');
  const rDel = page.waitForResponse((r) => r.request().method() === 'POST' && /delete-account/.test(r.url()), { timeout: 30_000 });
  await confirmer.click();
  const respDel = await rDel;
  const jDel = await respDel.json().catch(() => ({}));
  note(`delete-account → HTTP ${respDel.status()} ${JSON.stringify(jDel).slice(0, 200)}`);
  if (respDel.status() !== 200) {
    await expect(page.getByRole('alert')).toBeVisible({ timeout: 5_000 });
    await S.snapSite.snap('24-mobile-suppression-refusee');
  } else {
    await page.waitForTimeout(2500);
    await S.snapSite.snap('24-mobile-apres-suppression');
    expect(await page.evaluate(() => localStorage.getItem('lecayenne.authToken'))).toBeNull();
    await expect(page.locator('.lc-nav-btn-account')).toContainText('Se connecter');
  }
  expect(respDel.status(), 'suppression acceptée (commandes non commencées annulées d\'abord)').toBe(200);
  const apres = JSON.parse(php(`
    $u = \\App\\Models\\User::withoutGlobalScopes()->withTrashed()->where('phone', '${S.phone}')->orWhere('email', '${S.email}')->first();
    $ids = [${S.orders.map((x) => x.id).join(',')}];
    echo json_encode(['user_encore_identifiable' => (bool) $u, 'orders' => \\App\\Models\\Order::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'status', 'user_id'])->map(fn ($o) => ['id' => $o->id, 'status' => (int) $o->status, 'user' => \\App\\Models\\User::withoutGlobalScopes()->withTrashed()->find($o->user_id)?->name])->all(),
      'addresses_restantes' => \\App\\Models\\Address::withoutGlobalScopes()->whereIn('user_id', \\App\\Models\\Order::withoutGlobalScopes()->whereIn('id', $ids)->pluck('user_id'))->count(),
      'stops_restants' => \\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereIn('order_id', $ids)->count()]);`));
  note('base après suppression : ' + JSON.stringify(apres));
  expect(apres.user_encore_identifiable, 'ni e-mail ni téléphone ne retrouvent le compte').toBe(false);
  for (const o of apres.orders) { expect(o.status, `commande ${o.id} annulée (CANCELED=16)`).toBe(16); expect(o.user).toBe('Compte supprimé'); }
  expect(apres.addresses_restantes, 'adresses du client effacées').toBe(0);
  if (apres.stops_restants > 0) finding({ id: 'M2-006', severite: 'P2', categorie: 'données', preuve: `${apres.stops_restants} arrêt(s) delivery_stops subsistent pour des commandes annulées à la suppression du compte (docs/LIVRAISON.md §3bis : une annulation libère sa place)`, repro: 'suppression de compte avec commandes de livraison PENDING → SELECT * FROM delivery_stops WHERE order_id IN (...)' });
  // (défaut M2-006 enregistré ci-dessus le cas échéant)
});

// ─── 7. Desktop 1280 : tunnel livraison et compte ────────────────────────────────────────────────────────
test('7. desktop 1280 : tunnel livraison (devis, refus) et écrans compte', async ({ browser }) => {
  test.setTimeout(240_000);
  if (S.ctxSite) { await S.ctxSite.close(); S.ctxSite = null; }
  const ctx = await contexteSite(browser, false);
  const page = await ctx.newPage();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  enregistreTiming(page, 'desktop');
  const rec = attachMegaAuditRecorder(page, SHOTS);
  try {
    await assurerLivraisonActive();
    await ouvrirTunnel(page);
    await page.getByRole('radio', { name: /Livraison/ }).click();
    const d = await devis(page, ADRESSES.noyelles.q);
    expect(d.status).toBe(200);
    expect(d.json.fee).toBe(4.9);
    await expect(page.getByRole('status').filter({ hasText: /Livraison/ }).first()).toContainText('4,90 €');
    await rec.snap('25-desktop-tunnel-livraison-devis-noyelles');
    const dd = await devis(page, ADRESSES.douai.q);
    expect(dd.json.allowed).toBe(false);
    await expect(page.getByRole('alert').filter({ hasText: /trop loin/ })).toBeVisible();
    await rec.snap('26-desktop-refus-douai-uber-eats');
    await page.locator('.lc-nav-btn-account').first().click();
    await expect(page.locator('#acc-email')).toBeVisible({ timeout: 10_000 });
    await page.keyboard.press('Escape');
    await page.goto(SITE + '/#orders', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Connecte-toi/);
    await page.waitForTimeout(1500);
    await rec.snap('28-desktop-mes-commandes-visiteur');
  } finally {
    rec.dispose();
    await ctx.close();
  }
});
