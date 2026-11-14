// [ÉTAT DES LIEUX 2026-10-23 · VAGUE S · SITE] Ce que le site (tunnel de commande) fait AUJOURD'HUI face aux 7 exigences R1..R7
// du propriétaire. LECTURE SEULE : aucune correction, aucun fichier de produit modifié. Base locale foodking_e2e uniquement.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/etat-des-lieux-2026-10-23/S-site-livraison.spec.js --workers=1 --retries=0 --reporter=line
//
// Instrument (CLAUDE.md §3ter) : miroir :8011 = dépôt du site ; sa meta `api-key` (production) est réécrite à la volée vers
// MIX_API_KEY. Le réglage « livraison active » du serveur N'EST JAMAIS modifié (état d'origine : actif) ; la variante
// « livraison suspendue » est simulée dans le NAVIGATEUR par interception de /delivery/status (preuve de l'instrument).
// Chaque état = quatuor PNG + DOM + console + réseau (mega-audit-snap) ; PNG sous budget (disque).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder: brut } = require('../../e2e/helpers/mega-audit-snap');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = 'http://127.0.0.1:8011';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const OUT = path.join(REPO, 'reports', 'test-e2e', 'etat-des-lieux-2026-10-23', 'S-captures');
fs.mkdirSync(OUT, { recursive: true });
const SITE_SRC = process.env.S_SITE_SRC || '/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne';
const SITE_MIRROR = process.env.S_SITE_DIR || '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/15fca3a9-ef91-4f69-8ff0-680ff8c89dfd/scratchpad/site-local';

function envValue(key) {
  const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
  return m ? m[1].trim().replace(/^"|"$/g, '') : '';
}
const API_KEY = envValue('MIX_API_KEY');
const NOW = Date.now();
const TAG = `E2E-SITE-S-${String(NOW).slice(-6)}`;
const PNG_CAP = Number(process.env.S_PNG_CAP || 24);
const PNG_ALLOW = process.env.S_PNG_ALLOW ? new RegExp(process.env.S_PNG_ALLOW) : /INSTR|^M-(01|04|06-adresse-(henin|oignies|lens|lille|inconnue)|07|10|11)|^D-(01|05|06-adresse-(henin|lens|lille|inconnue)|07|10)/;
let pngUsed = 0;

test.describe.configure({ mode: 'serial' });
test.setTimeout(480_000);
test.use({
  video: 'off', trace: 'off', screenshot: 'off',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] },
});

// ---------------------------------------------------------------- registre
const L = { tag: TAG, startedAt: new Date().toISOString(), etatOrigine: {}, states: [], checks: [], quotes: [], orders: [], notes: [], textes: {}, tutoiement: {} };
const note = (s) => { L.notes.push(s); console.log('[S] ' + s); };
function writeLedger() { fs.writeFileSync(path.join(OUT, process.env.S_LEDGER_NAME || 'S-ledger.json'), JSON.stringify(L, null, 2)); }
function check(id, ok, detail) { L.checks.push({ id, ok: !!ok, detail }); console.log(`[S CHECK] ${ok ? 'OK ' : 'KO '} ${id} ${detail !== undefined ? JSON.stringify(detail).slice(0, 360) : ''}`); }

function mysql(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim();
}
const lastEuro = (s) => { const all = [...String(s || '').replace(/[  ]/g, ' ').matchAll(/(\d+)[.,](\d{2})\s*€/g)]; return all.length ? Number(`${all[all.length - 1][1]}.${all[all.length - 1][2]}`) : NaN; };
function haversine(lat1, lon1, lat2, lon2) {
  const R = 6371; const r = (d) => d * Math.PI / 180;
  const a = Math.sin(r(lat2 - lat1) / 2) ** 2 + Math.cos(r(lat1)) * Math.cos(r(lat2)) * Math.sin(r(lon2 - lon1) / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// ---------------------------------------------------------------- tutoiement (R5) : détecteur partagé navigateur + sources
// Les \b de JavaScript ne connaissent pas les lettres accentuées (« complète » contiendrait « te ») : bornes Unicode explicites.
const NB = '(?<![\\p{L}\\p{N}_])'; const NA = '(?![\\p{L}\\p{N}_])';
const RX_PRONOM = `${NB}(?:tu|toi|ton|ta|tes|te)${NA}|${NB}t['’]`;
const RX_IMPERATIF = `${NB}(?:choisis|précise|réessaie|vérifie|repasse|viens|reviens|saisis|renseigne|patiente|essaie|entre ton|entre tes)${NA}`;
const TUTOIE_SRC = `${RX_PRONOM}|${RX_IMPERATIF}`;
const TUTOIE = new RegExp(TUTOIE_SRC, 'iu');

// ---------------------------------------------------------------- instruments
function enveloppe(page, dir) {
  const r = brut(page, dir);
  return { ...r, snap: async (name) => {
    const ok = PNG_ALLOW.test(String(name)) && pngUsed < PNG_CAP;
    if (ok) { pngUsed += 1; return r.snap(name); }
    const orig = page.screenshot.bind(page);
    page.screenshot = async () => Buffer.alloc(0);
    try { return await r.snap(name); } finally { page.screenshot = orig; }
  } };
}
async function installApiKeyRewrite(ctx) {
  await ctx.route((url) => url.origin === SITE && (url.pathname === '/' || url.pathname === '/index.html'), async (route) => {
    if (route.request().resourceType() !== 'document') return route.continue();
    const res = await route.fetch();
    let body = await res.text();
    body = body.replace(/(name="api-key" content=")[^"]*(")/, `$1${API_KEY}$2`);
    await route.fulfill({ response: res, body });
  });
}
async function newCtx(browser, mobile) {
  const ctx = await browser.newContext(mobile
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, locale: 'fr-FR', timezoneId: 'Europe/Paris' }
    : { viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  await installApiKeyRewrite(ctx);
  return ctx;
}
async function gotoSite(page, hash = '') {
  await page.goto(`${SITE}/?dev${hash}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1500);
}
async function visibleText(page) {
  return page.evaluate(() => String(document.body.innerText || '').replace(/[  ]/g, ' '));
}
function phrasesTutoyees(text) {
  const out = new Set();
  String(text).split(/\n+/).forEach((ligne) => {
    ligne.split(/(?<=[.!?…])\s+/).forEach((ph) => { const t = ph.trim(); if (t.length > 3 && TUTOIE.test(t)) out.add(t.slice(0, 220)); });
  });
  return [...out];
}

/** Un état = texte visible + tutoiement + quatuor + lecture du réseau/console. */
async function state(page, rec, mobile, name, extra = {}) {
  const vp = mobile ? 'M' : 'D';
  const id = `${vp}-${name}`;
  const text = await visibleText(page);
  const tu = phrasesTutoyees(text);
  await rec.snap(id);
  let net = []; let con = [];
  try { net = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.network.json`), 'utf8')); } catch (_e) { /* ok */ }
  try { con = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.console.json`), 'utf8')); } catch (_e) { /* ok */ }
  const http4xx = net.filter((x) => x.status >= 400).map((x) => `${x.method} ${x.url.replace(BASE, '').replace(SITE, '').slice(0, 90)} -> ${x.status}`);
  const conErr = con.filter((c) => /error|pageerror/.test(c.level)).map((c) => c.text.slice(0, 160));
  const alerts = await page.evaluate(() => Array.from(document.querySelectorAll('[role=alert]')).filter((e) => e.offsetParent).map((e) => (e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 200)));
  const entry = { id, url: page.url().replace(SITE, ''), ...extra, nbCaracteres: text.length, tutoiement: tu, http4xx, consoleErrors: conErr, alertsVisibles: alerts,
    png: fs.existsSync(path.join(OUT, `${id}.png`)) && fs.statSync(path.join(OUT, `${id}.png`)).size > 0 };
  L.states.push(entry);
  L.textes[id] = text.slice(0, 6000);
  (L.tutoiement[id] = tu);
  writeLedger();
  console.log(`[S STATE] ${id} tu=${tu.length} 4xx=${http4xx.length} conErr=${conErr.length} png=${entry.png}`);
  return { entry, text };
}

// ---------------------------------------------------------------- pilotage du tunnel (repris de A-etats-r2, vague A)
async function readStep(page) {
  const r = await page.evaluate(() => {
    const t = (sel) => { const e = document.querySelector(sel); return e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : ''; };
    const next = document.querySelector('.lc-wiz-foot-next');
    return { title: t('.lc-wiz-title'), next: next ? String(next.innerText || '').replace(/\s+/g, ' ') : '', disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null };
  });
  return { ...r, total: lastEuro(r.next) };
}
async function marcherWizard(page) {
  for (let s = 0; s < 16; s++) {
    const step = await readStep(page);
    if (/Ajouter au panier/i.test(step.next)) return step;
    for (let k = 0; k < 3 && (await readStep(page)).disabled; k++) {
      await page.locator('.lc-wiz-choice:not(.is-on)').first().click();
      await page.waitForTimeout(300);
    }
    if ((await readStep(page)).title !== step.title) continue;
    await page.locator('.lc-wiz-foot-next').click({ timeout: 8_000 });
    await page.waitForTimeout(450);
  }
  return null;
}
async function ajouterAuPanier(page) {
  for (let essai = 1; essai <= 2; essai++) {
    await page.locator('.lc-wiz-foot-next').click({ timeout: 8_000 });
    const t0 = Date.now();
    while (Date.now() - t0 < 20_000) {
      if (await page.locator('.lc-cart-drawer.is-open').count()) return true;
      if (!(await page.locator('.lc-wiz').count())) return true;
      await page.waitForTimeout(250);
    }
    await page.waitForTimeout(2000);
  }
  return false;
}
async function versPageCommande(page) {
  for (let i = 0; i < 5 && !(await page.locator('.lcf-page').count()); i++) {
    const b = page.getByRole('button', { name: /Non merci|Continuer|Régler ma commande/ }).first();
    if (await b.isVisible({ timeout: 1500 }).catch(() => false)) await b.click();
    await page.waitForTimeout(1200);
  }
  await expect(page.locator('.lcf-page')).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(900);
}
async function authentifier(page, rq, tag, email, phone) {
  await page.locator('#auth-email').fill(email);
  await page.getByRole('button', { name: /Recevoir le code par email/ }).click();
  await expect(page.locator('[data-testid="funnel-identite"]')).toBeVisible({ timeout: 15_000 });
  await page.locator('#auth-first').fill(`${tag} Client`);
  await page.locator('#auth-phone').fill(phone);
  const r = page.waitForResponse((x) => x.request().method() === 'POST' && /guest-signup\/email-login/.test(x.url()), { timeout: 30_000 });
  await page.getByRole('button', { name: /Créer mon compte et recevoir le code/ }).click();
  await r;
  const token = mysql(`SELECT token FROM otps WHERE phone='${phone}' ORDER BY created_at DESC LIMIT 1`);
  expect(token).toMatch(/^\d{4}$/);
  execFileSync('php', ['artisan', 'tinker', '--execute', 'app(\\Illuminate\\Cache\\RateLimiter::class)->clear(sha1("|127.0.0.1")); echo "ok";'], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
  const v = await rq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' }, data: { token, phone, code: '+33', email, first_name: `${tag} Client` } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  await page.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [vj.token, phone]);
}


async function cheminJusquaCommande(page) {
  await gotoSite(page, '#menu');
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
  await page.waitForTimeout(1500);
  await page.locator('[aria-label="Voir Cheese Burger"]').first().click();
  await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 10_000 });
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
  expect(await marcherWizard(page), 'récap du wizard atteint').toBeTruthy();
  expect(await ajouterAuPanier(page)).toBeTruthy();
  const drawer = page.locator('.lc-cart-drawer.is-open');
  if (!(await drawer.count())) { await page.waitForTimeout(800); await page.locator('.lc-nav-btn-cart').first().click(); }
  await expect(drawer).toBeVisible({ timeout: 10_000 });
  await drawer.getByRole('button', { name: /Passer commande/ }).click();
  await page.waitForTimeout(1200);
  await versPageCommande(page);
}

// ---------------------------------------------------------------- adresses de l'état des lieux
const ADRESSES = [
  { id: 'inconnue', saisie: 'zzzz qqq 99999 Nullepart', attendu: 'refus de géocodage (aucune suggestion)' },
  { id: 'lille-gt12', saisie: '10 rue Nationale 59000 Lille', attendu: '> 12 km : refus + Uber Eats' },
  { id: 'lens-9km', saisie: '12 rue de la Gare 62300 Lens', attendu: '~9 km : barème actuel 6,90 € (aucun devis Uber)' },
  { id: 'oignies-6km', saisie: '1 rue Jean Jaures 62590 Oignies', attendu: '~6 km : barème actuel 5,90 € (nos livreurs)' },
  { id: 'henin', saisie: '12 rue Elie Gruyelle 62110 Henin-Beaumont', attendu: 'Hénin-Beaumont : 3,90 €' },
];

async function saisirAdresse(page, adr, branche) {
  resetLimiteurs();
  const compteur = { suggest: 0, suggest429: 0 };
  const reponses = [];
  const onR = (r) => { if (/\/delivery\/suggest/.test(r.url())) { compteur.suggest += 1; if (r.status() === 429) compteur.suggest429 += 1; r.json().then((j) => reponses.push(j)).catch(() => {}); } };
  page.on('response', onR);
  const input = page.locator('input[aria-label="Adresse de livraison"]');
  await input.scrollIntoViewIfNeeded();
  await input.fill('');
  await page.waitForTimeout(250);
  const sugg = page.waitForResponse((r) => /\/delivery\/suggest/.test(r.url()), { timeout: 20_000 }).catch(() => null);
  await input.pressSequentially(adr.saisie, { delay: 25 });
  const sr = await sugg;
  await page.waitForTimeout(1800);
  page.off('response', onR);
  const options = await page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
  // « choisir dans la liste » : un <datalist> natif ne se pilote pas au clic ; on pose la valeur EXACTE d'une option, ce que fait le navigateur.
  const choisi = options.length ? options[0] : adr.saisie;
  await input.fill(choisi);
  await page.waitForTimeout(900);
  const quoteP = page.waitForResponse((r) => /\/delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 25_000 }).catch(() => null);
  await page.getByRole('button', { name: /^Calculer$/ }).click();
  const qr = await quoteP;
  let qj = null; let status = null;
  if (qr) { status = qr.status(); qj = await qr.json().catch(() => null); }
  await page.waitForTimeout(1500);
  const section = page.locator('.lcf-section', { has: page.locator('input[aria-label="Adresse de livraison"]') });
  const sectionTxt = ((await section.innerText().catch(() => '')) || '').replace(/\s+/g, ' ');
  const liens = await section.locator('a[href]').evaluateAll((as) => as.map((a) => ({ txt: a.innerText.trim().slice(0, 60), href: a.getAttribute('href') })));
  const slotsLabels = await section.locator('[aria-label="Heure de départ de la livraison"] button').allInnerTexts().catch(() => []);
  // distance à vol d'oiseau recalculée depuis la suggestion choisie (2e moyen)
  const suggJson = reponses.slice().reverse().find((j) => j && j.data && j.data.some((x) => x.label === choisi)) || (sr ? await sr.json().catch(() => null) : null);
  const sg0 = suggJson && suggJson.data && suggJson.data.find((x) => x.label === choisi);
  const vol = sg0 ? haversine(branche.lat, branche.lon, sg0.latitude, sg0.longitude) : null;
  const rec = { adresse: adr.id, saisie: adr.saisie, attendu: adr.attendu, appelsSuggest: compteur, nbSuggestions: options.length, premieresSuggestions: options.slice(0, 4), choisi,
    quoteHttp: status, quote: qj && { status: qj.status, allowed: qj.allowed, fee: qj.fee, distance_km: qj.distance_km, suggest_uber_eats: qj.suggest_uber_eats, message: qj.message, address: qj.address && { label: qj.address.label, city: qj.address.city }, nbWaves: (qj.waves || []).length, clefs: Object.keys(qj) },
    distanceVolOiseauRecalculee: vol && Math.round(vol * 1000) / 1000, texteSection: sectionTxt.slice(0, 700), liens, creneaux: slotsLabels.map((s) => s.replace(/\s+/g, ' ')) };
  L.quotes.push(rec);
  writeLedger();
  return rec;
}

async function osrm(from, to) {
  try {
    const r = await fetch(`https://router.project-osrm.org/route/v1/driving/${from.lon},${from.lat};${to.lon},${to.lat}?overview=false`, { signal: AbortSignal.timeout(9000) });
    const j = await r.json();
    return j && j.routes && j.routes[0] ? Math.round(j.routes[0].distance / 10) / 100 : null;
  } catch (_e) { return null; }
}

// ============================================================================
const S = { branche: null };
/** Remet à zéro les seaux de débit de la livraison (instrument : 40 suggestions/min/IP seraient dépassées par 2 saisies d'affilée). */
function resetLimiteurs() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `$l = app(\\Illuminate\\Cache\\RateLimiter::class); foreach (['delivery-suggest','delivery-quote','delivery-status','delivery-waves'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } } echo 'ok';`], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
}

test.describe('Vague S — site : livraison, paiement, confirmation (R1..R7)', () => {
  let rq;
  test.beforeAll(async ({ playwright }) => {
    const [lat, lon] = mysql('SELECT latitude, longitude FROM branches WHERE id=1').split('\t').map(Number);
    S.branche = { lat, lon };
    rq = await playwright.request.newContext();
    const st = await rq.get(`${BASE}/api/frontend/delivery/status`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
    L.etatOrigine = { deliveryStatus: await st.json().catch(() => null), httpStatus: st.status(), branche: S.branche,
      siteIndexJsHash: execFileSync('shasum', ['-a', '256', path.join(SITE_MIRROR, 'compiled', 'funnel.js')], { encoding: 'utf8' }).split(' ')[0],
      depotIndexJsHash: execFileSync('shasum', ['-a', '256', path.join(SITE_SRC, 'compiled', 'funnel.js')], { encoding: 'utf8' }).split(' ')[0],
      balise: (fs.readFileSync(path.join(SITE_MIRROR, 'index.html'), 'utf8').match(/compiled\/funnel\.js\?v=[^"']+/) || [null])[0] };
    note(`état d'origine livraison : ${JSON.stringify(L.etatOrigine.deliveryStatus && L.etatOrigine.deliveryStatus.enabled)} ; miroir == dépôt (funnel.js) : ${L.etatOrigine.siteIndexJsHash === L.etatOrigine.depotIndexJsHash} ; balise : ${L.etatOrigine.balise}`);
    writeLedger();
  });
  test.afterAll(async () => { writeLedger(); if (rq) await rq.dispose().catch(() => {}); });

  // ------------------------------------------------------------------------
  test('0. instrument : le sélecteur « Livraison » voit la tuile active ET le lien Uber Eats quand la livraison est suspendue', async ({ browser }) => {
    let ctx = await newCtx(browser, false);
    let page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    let rec = enveloppe(page, OUT);
    // (a) état réel du serveur : actif
    expect(L.etatOrigine.deliveryStatus && L.etatOrigine.deliveryStatus.enabled, 'la livraison doit être active côté serveur local (sinon activer via la caisse et le noter)').toBe(true);
    const aller = async (suspendue) => {
      if (suspendue) await page.route('**/api/frontend/delivery/status', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: true, enabled: false, grid: null }) }));
      await gotoSite(page, '#menu');
    };
    // il faut un panier pour atteindre la page de commande : on passe par le wizard (même chemin que le parcours)
    async function jusqueCommande() {
      await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
      await page.waitForTimeout(1500);
      await page.locator('[aria-label="Voir Cheese Burger"]').first().click();
      await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 10_000 });
      await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
      await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
      expect(await marcherWizard(page), 'récap du wizard atteint').toBeTruthy();
      expect(await ajouterAuPanier(page)).toBeTruthy();
      await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click();
      await page.waitForTimeout(1200);
      await versPageCommande(page);
    }
    await aller(false);
    await jusqueCommande();
    const radiogroup = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
    await expect(radiogroup).toBeVisible();
    const radiosActif = await radiogroup.locator('[role="radio"]').allInnerTexts();
    check('INSTRUMENT livraison ACTIVE : le sélecteur trouve les 2 tuiles À emporter + Livraison', radiosActif.length === 2 && /Livraison/.test(radiosActif.join('|')) && /À emporter/.test(radiosActif.join('|')), radiosActif);
    expect(radiosActif.length).toBe(2);
    await state(page, rec, false, 'INSTR-livraison-active-tuiles');
    // (b) même page, livraison suspendue (réponse /status simulée dans le navigateur seulement)
    rec.dispose(); await ctx.close();
    ctx = await newCtx(browser, false); page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    rec = enveloppe(page, OUT);
    await aller(true);
    await jusqueCommande();
    const rg2 = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
    const radiosOff = await rg2.locator('[role="radio"]').allInnerTexts();
    const lienUber = await page.locator('a[aria-label*="Uber Eats"]').evaluateAll((as) => as.map((a) => ({ txt: a.innerText.replace(/\s+/g, ' ').trim(), href: a.getAttribute('href') })));
    check('INSTRUMENT livraison SUSPENDUE : plus de radio Livraison, un lien « Sur Uber Eats » à la place', radiosOff.length === 1 && lienUber.length === 1, { radiosOff, lienUber });
    expect(lienUber.length).toBe(1);
    await state(page, rec, false, 'INSTR-livraison-suspendue-uber', { lienUber });
    rec.dispose(); await ctx.close();
  });

  // ------------------------------------------------------------------------
  for (const mobile of [true, false]) {
    test(`${mobile ? '1' : '2'}. ${mobile ? 'mobile 390x844' : 'desktop 1440x900'} : accueil -> carte -> panier -> mode -> adresses -> paiement -> confirmation -> suivi`, async ({ browser }) => {
      const vp = mobile ? 'M' : 'D';
      const ctx = await newCtx(browser, mobile);
      const page = await ctx.newPage();
      await page.emulateMedia({ reducedMotion: 'reduce' });
      const rec = enveloppe(page, OUT);
      const email = `${TAG.toLowerCase()}-${vp.toLowerCase()}@lecayenne-test.fr`;
      const phone = `0699${vp === 'M' ? '1' : '2'}${String(NOW).slice(-5)}`;

      // ---- accueil (R4, R1)
      await gotoSite(page);
      const home = await state(page, rec, mobile, '01-accueil');
      const homeBoutons = await page.evaluate(() => Array.from(document.querySelectorAll('button, a[href]')).filter((e) => e.offsetParent).map((e) => (e.innerText || e.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim()).filter(Boolean));
      const homeLiv = home.text.split('\n').filter((l) => /livraison|livrer|livré|nouveau|nouveauté|uber/i.test(l)).map((l) => l.trim());
      L[`accueil${vp}`] = { boutons: homeBoutons.slice(0, 40), lignesLivraisonNouveau: homeLiv, mentionEmporter: /à emporter/i.test(home.text) };
      check(`R4 ${vp} accueil : annonce « Nouveau : la livraison ! »`, /nouveau\s*:?\s*la livraison/i.test(home.text), homeLiv);
      check(`R1 ${vp} accueil : choix À emporter / Livraison au tout début`, homeBoutons.some((b) => /^livraison$/i.test(b)) && homeBoutons.some((b) => /^à emporter$/i.test(b)), homeBoutons.filter((b) => /emporter|livr|uber/i.test(b)));
      expect(home.text.length).toBeGreaterThan(200);
      // que fait le bouton « COMMANDER » de l'accueil ? (R1 : premier écran de la commande)
      const cta = page.getByRole('button', { name: /^Commander/i }).first();
      if (await cta.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cta.click(); await page.waitForTimeout(1500);
        const t1 = await visibleText(page);
        L[`ctaCommander${vp}`] = { hash: await page.evaluate(() => location.hash), choixModeVisible: /à emporter|livraison/i.test(t1.split('\n').slice(0, 60).join(' ')), premieresLignes: t1.split('\n').filter(Boolean).slice(0, 14) };
        check(`R1 ${vp} après « Commander » : choix du mode proposé d'emblée`, /^(à emporter|livraison)$/im.test(t1), L[`ctaCommander${vp}`]);
      }

      // ---- carte
      await page.evaluate(() => { window.location.hash = '#menu'; });
      await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
      await page.waitForTimeout(1500);
      await state(page, rec, mobile, '02-carte');
      await page.locator('[aria-label="Voir Cheese Burger"]').first().click();
      await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 10_000 });
      await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
      await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
      const wizTxt = await visibleText(page);
      L[`wizard${vp}`] = { choixModeDansWizard: /emporter|livraison/i.test(wizTxt) };
      const recap = await marcherWizard(page);
      expect(recap, 'récap atteint').toBeTruthy();
      expect(await ajouterAuPanier(page)).toBeTruthy();
      const drawer = page.locator('.lc-cart-drawer.is-open');
      if (!(await drawer.count())) { L[`panier${vp}`] = { ouvertAutomatiquement: false }; await page.waitForTimeout(800); await page.locator('.lc-nav-btn-cart').first().click(); }
      await expect(drawer).toBeVisible({ timeout: 10_000 });
      await page.waitForTimeout(500);
      const pan = await state(page, rec, mobile, '03-panier');
      check(`R1 ${vp} panier : choix du mode (emporter/livraison) proposé AVANT la page de commande`, /à emporter|livraison/i.test(await drawer.innerText()), (await drawer.innerText()).replace(/\s+/g, ' ').slice(0, 300));
      await drawer.getByRole('button', { name: /Passer commande/ }).click();
      await page.waitForTimeout(1200);
      await versPageCommande(page);

      // ---- page de commande : mode (R1)
      const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
      await expect(rg).toBeVisible();
      const radios = await rg.locator('[role="radio"]').allInnerTexts();
      const cmd0 = await state(page, rec, mobile, '04-commande-mode-retrait', { radios });
      check(`R1 ${vp} page de commande : tuiles du mode`, radios.length === 2, radios);
      L[`modeEtape${vp}`] = { etapeFilAriane: await page.locator('.lcf-steps, [class*=steps]').first().innerText().catch(() => ''), radios };
      expect(radios.length).toBe(2);
      // pas de choix par défaut sur « Livraison » : le mode préselectionné est À emporter
      const preselection = await rg.locator('[role="radio"][aria-checked="true"]').allInnerTexts();
      check(`R1 ${vp} mode préselectionné`, true, preselection);

      await rg.getByRole('radio', { name: /Livraison/ }).click();
      await page.waitForTimeout(600);
      const liv0 = await state(page, rec, mobile, '05-livraison-choisie');
      check(`R2 ${vp} champ « Adresse de livraison » apparaît`, await page.locator('input[aria-label="Adresse de livraison"]').count() === 1);
      expect(await page.locator('input[aria-label="Adresse de livraison"]').count()).toBe(1);

      // ---- adresses (R2, R3)
      for (const adr of ADRESSES) {
        const q = await saisirAdresse(page, adr, S.branche);
        const sect = page.locator('.lcf-section', { has: page.locator('input[aria-label="Adresse de livraison"]') });
        await sect.scrollIntoViewIfNeeded();
        const txt = (q.texteSection || '');
        const st = await state(page, rec, mobile, `06-adresse-${adr.id}`, { quoteHttp: q.quoteHttp, fee: q.quote && q.quote.fee, distance: q.quote && q.quote.distance_km, liens: q.liens });
        const fraisAffiches = [...txt.matchAll(/(\d+[.,]\d{2})\s*€/g)].map((m) => m[1]);
        check(`R2 ${vp} ${adr.id} : liste de suggestions (nb)`, adr.id === 'inconnue' ? q.nbSuggestions === 0 : q.nbSuggestions > 0, { nb: q.nbSuggestions, premieres: q.premieresSuggestions });
        if (q.quote && q.quote.distance_km != null && q.distanceVolOiseauRecalculee != null) {
          check(`R3 ${vp} ${adr.id} : distance du devis == vol d'oiseau recalculé (pas le trajet routier)`, Math.abs(q.quote.distance_km - q.distanceVolOiseauRecalculee) < 0.05, { devis: q.quote.distance_km, volOiseau: q.distanceVolOiseauRecalculee });
        }
        check(`R3 ${vp} ${adr.id} : libellé « à partir de 7 km — tarif calculé par Uber »`, /à partir de 7 km|calculé par Uber/i.test(txt + ' ' + st.text), null);
        expect(txt.length + st.text.length).toBeGreaterThan(0);
      }
      // l'adresse finale (Hénin-Beaumont) a un devis valide : créneaux
      const dernier = L.quotes[L.quotes.length - 1];
      L[`dernierDevis${vp}`] = dernier;
      check(`R3 ${vp} Hénin-Beaumont : devis autorisé 3,90 €`, dernier.quote && dernier.quote.allowed === true && dernier.quote.fee === 3.9, dernier.quote);

      // ---- paiement (R6) : où est le choix du mode de paiement ?
      const payBloc = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
      await payBloc.scrollIntoViewIfNeeded().catch(() => {});
      const modesPaiement = await payBloc.locator('[role="radio"]').allInnerTexts().catch(() => []);
      const pageTxtAvant = await visibleText(page);
      const pay0 = await state(page, rec, mobile, '07-paiement-avant-confirmation', { modesPaiement });
      check(`R6 ${vp} choix du paiement présent AVANT la confirmation (état actuel du site)`, modesPaiement.length > 0, modesPaiement);
      check(`R6 ${vp} options espèces / carte / titre-restaurant / plusieurs paiements proposées`, /titre/i.test(pageTxtAvant) && /esp[èe]ces/i.test(pageTxtAvant) , modesPaiement);
      L[`paiement${vp}`] = { modesPaiement, mentionTitreRestaurant: /titre/i.test(pageTxtAvant), mentionEspeces: /esp[èe]ces/i.test(pageTxtAvant), mentionPlusieursPaiements: /plusieurs paiements|payer en plusieurs|diviser|partager/i.test(pageTxtAvant) };
      expect(pageTxtAvant.length).toBeGreaterThan(200);

      // ---- authentification + commande
      await authentifier(page, rq, `${TAG}-${vp}`, email, phone);
      const sink = {};
      page.on('request', (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { sink.body = JSON.parse(req.postData() || '{}'); } catch (_e) { sink.body = null; } } });
      const orderResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 60_000 });
      await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded().catch(() => {});
      await state(page, rec, mobile, '08-avant-confirmer');
      await page.locator('.lcf-cta-bar-next').click();
      const resp = await orderResp;
      const j = await resp.json().catch(() => ({}));
      const d = (j && j.data) || j || {};
      const o = { vp, http: resp.status(), id: d.id, serial: d.order_serial_no, total: Number(d.total), corps: resp.status() >= 400 ? JSON.stringify(j).slice(0, 500) : undefined, envoye: sink.body && { clefs: Object.keys(sink.body), payment_method: sink.body.payment_method, order_type: sink.body.order_type, delivery_charge: sink.body.delivery_charge, delivery_slot_at: sink.body.delivery_slot_at } };
      L.orders.push(o);
      note(`POST /api/frontend/order (${vp}) -> HTTP ${o.http} id=${o.id} total=${o.total} envoyé=${JSON.stringify(o.envoye)}`);
      if (o.http >= 400) {
        await page.waitForTimeout(1500);
        await state(page, rec, mobile, '09-commande-refusee', { corps: o.corps });
      }
      expect(o.http, `commande : ${o.corps || ''}`).toBeLessThan(300);
      if (o.id) {
        let row = '';
        try { row = mysql(`SELECT id, order_type, payment_method, pos_payment_method, payment_status, status, delivery_charge, total, source_surface, delivery_tier, delivery_distance_km, delivery_slot_at, scheduled_at FROM orders WHERE id=${Number(o.id)}`); } catch (e) { row = 'lecture SQL impossible : ' + String(e.message).slice(0, 120); }
        o.baseOrders = row;
        note(`ligne orders ${o.id} : ${row}`);
      }
      await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 30_000 });
      await page.waitForTimeout(800);
      const conf = await state(page, rec, mobile, '10-confirmation');
      const confInfo = await page.evaluate(() => {
        const q = document.querySelector('.lcf-ticket-qr');
        return {
          qr: q ? { aria: Array.from(q.querySelectorAll('[aria-label]')).map((e) => e.getAttribute('aria-label')), nbImg: q.querySelectorAll('img, svg, canvas').length } : null,
          liens: Array.from(document.querySelectorAll('.lcf-confirm a[href]')).map((a) => ({ txt: a.innerText.trim().slice(0, 60), href: a.getAttribute('href') })),
          boutons: Array.from(document.querySelectorAll('.lcf-confirm button')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()),
          radios: Array.from(document.querySelectorAll('.lcf-confirm [role=radio]')).map((b) => b.innerText.replace(/\s+/g, ' ').trim()),
        };
      });
      L[`confirmation${vp}`] = confInfo;
      check(`R7 ${vp} confirmation : QR code d'AVIS`, /laisser un avis|donnez votre avis|votre avis|notez/i.test(conf.text) || confInfo.liens.some((l) => /g\.page|writereview|search\.google\.com\/local/i.test(l.href || '')), { qr: confInfo.qr, liens: confInfo.liens });
      check(`R6 ${vp} confirmation : choix espèces / carte / titre-restaurant APRÈS confirmation`, confInfo.radios.length > 0 || /titre.restaurant/i.test(conf.text), { radios: confInfo.radios, boutons: confInfo.boutons });
      check(`R5 ${vp} confirmation : vouvoiement`, phrasesTutoyees(conf.text).length === 0, phrasesTutoyees(conf.text).slice(0, 6));
      expect(conf.text.length).toBeGreaterThan(100);

      // ---- suivi
      const suivi = page.getByRole('button', { name: /Suivre|suivi|Voir le QR|Voir ma commande/i }).first();
      if (await suivi.isVisible({ timeout: 3000 }).catch(() => false)) {
        await suivi.click();
        await page.waitForTimeout(2500);
        const sv = await state(page, rec, mobile, '11-suivi');
        const svLiens = await page.evaluate(() => Array.from(document.querySelectorAll('a[href]')).filter((a) => a.offsetParent).map((a) => a.getAttribute('href')));
        check(`R7 ${vp} suivi : lien/QR d'avis Google`, svLiens.some((h) => /g\.page|writereview|search\.google\.com\/local|maps\.app\.goo|google\.[a-z.]+\/maps\/place/i.test(h || '')) || /laisser un avis|donnez votre avis|votre avis/i.test(sv.text), { liens: svLiens.slice(0, 12) });
        check(`R5 ${vp} suivi : vouvoiement`, phrasesTutoyees(sv.text).length === 0, phrasesTutoyees(sv.text).slice(0, 6));
      } else {
        note(`${vp} : aucun bouton de suivi visible depuis la confirmation`);
      }
      rec.dispose(); await ctx.close();
      expect(L.states.filter((s) => s.id.startsWith(vp + '-')).length).toBeGreaterThan(8);
    });
  }

  // ------------------------------------------------------------------------
  test('3. R3 2e moyen : distance routière réelle (OSRM) vs vol d’oiseau du devis, et R4/R7 dans les sources', async () => {
    // distances routières pour les adresses déjà géocodées
    resetLimiteurs();
    for (const q of L.quotes.filter((x, i, a) => a.findIndex((y) => y.adresse === x.adresse) === i && x.quote && x.quote.distance_km != null)) {
      await new Promise((r) => setTimeout(r, 600));
      const sg = await rq.get(`${BASE}/api/frontend/delivery/suggest`, { params: { q: q.choisi }, headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } }).then((r) => r.json()).catch(() => null);
      const p = sg && sg.data && sg.data.find((x) => x.label === q.choisi);
      if (p) {
        const route = await osrm(S.branche, { lat: p.latitude, lon: p.longitude });
        q.distanceRoutiereOSRM = route; q.distanceVolOiseauRecalculee = Math.round(haversine(S.branche.lat, S.branche.lon, p.latitude, p.longitude) * 1000) / 1000;
        check(`R3 distance routière OSRM ${q.adresse}`, true, { devisVolOiseau: q.quote && q.quote.distance_km, routier: route, ecartPct: route && q.quote && q.quote.distance_km ? Math.round((route / q.quote.distance_km - 1) * 100) : null });
      }
    }
    // R4 / R7 dans les sources du site
    const lire = (f) => fs.readFileSync(path.join(SITE_SRC, f), 'utf8');
    const jsxs = fs.readdirSync(SITE_SRC).filter((f) => /\.(jsx|js|html)$/.test(f));
    const grepSrc = (rx) => { const hits = []; jsxs.forEach((f) => lire(f).split('\n').forEach((l, i) => { if (rx.test(l)) hits.push(`${f}:${i + 1}: ${l.trim().slice(0, 140)}`); })); return hits; };
    L.sources = {
      nouveauLaLivraison: grepSrc(/nouveau\s*:?\s*la livraison/i),
      aPartirDe7km: grepSrc(/à partir de 7 km|calculé par Uber/i),
      avisQr: grepSrc(/g\.page|search\.google\.com\/local\/writereview|writereview|qr.*avis|avis.*qr/i),
      seuil7km: grepSrc(/\b7 ?km\b|max_km.*7\b/i),
    };
    check('R4 sources : « Nouveau : la livraison ! » existe dans les sources du site', L.sources.nouveauLaLivraison.length > 0, L.sources.nouveauLaLivraison);
    check('R3 sources : libellé Uber « à partir de 7 km » existe', L.sources.aPartirDe7km.length > 0, L.sources.aPartirDe7km);
    check('R7 sources : QR/lien d’avis Google existe', L.sources.avisQr.length > 0, L.sources.avisQr);
    // R7 côté ticket imprimé (backend) : qui appelle EscPosCommandBuilder::qrCode ?
    const ticket = execFileSync('grep', ['-rn', 'qrCode(', path.join(REPO, 'app')], { encoding: 'utf8' }).split('\n').filter(Boolean);
    L.ticketQrAppelants = ticket;
    check('R7 ticket imprimé : seul le ticket promo (flyer) appelle qrCode(), pas le reçu de caisse', ticket.some((l) => /PromoFlyerEscPosRenderer/.test(l)) && !ticket.some((l) => /OrderReceiptEscPosRenderer/.test(l)), ticket);
    expect(ticket.length).toBeGreaterThan(0);
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('4. R5 inventaire statique des chaînes tutoyées dans les sources du site (affiché vs commentaire)', async () => {
    const fichiers = fs.readdirSync(SITE_SRC).filter((f) => /\.(jsx|js|html)$/.test(f)).concat(['compiled/funnel.js', 'compiled/racine.js', 'compiled/screens.js', 'compiled/screens-v3.js', 'compiled/components.js', 'compiled/flows.js', 'compiled/wizard-v2.js', 'compiled/upsell.js', 'compiled/orders.js', 'compiled/account-v2.js', 'compiled/loyalty-v2.js']);
    const rxPron = new RegExp(RX_PRONOM, 'iu'); const rxImp = new RegExp(RX_IMPERATIF, 'iu');
    const inventaire = {};
    for (const f of fichiers) {
      const p = path.join(SITE_SRC, f);
      if (!fs.existsSync(p) || fs.statSync(p).size > 4_000_000) continue;
      const brutTxt = fs.readFileSync(p, 'utf8');
      // retire les commentaires en conservant les numéros de ligne
      let sans = brutTxt.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
      sans = sans.replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));
      sans = sans.split('\n').map((l) => l.replace(/(^|\s)\/\/.*$/, '$1')).join('\n');
      const lignesBrut = brutTxt.split('\n'); const lignesSans = sans.split('\n');
      const affiches = []; const commentairesSeuls = [];
      lignesSans.forEach((l, i) => {
        // chaînes entre guillemets/backticks + texte JSX
        const chaines = [...l.matchAll(/'((?:[^'\\\n]|\\.)*)'|"((?:[^"\\\n]|\\.)*)"|`((?:[^`\\\n]|\\.)*)`/g)].map((m) => m[1] || m[2] || m[3] || '');
        const jsx = [...l.matchAll(/>([^<>{}]{4,})</g)].map((m) => m[1]);
        const brutsHtml = /\.html$/.test(f) ? [l.replace(/<[^>]+>/g, ' ')] : [];
        const cand = chaines.concat(jsx, brutsHtml).filter((s) => /[a-zàâçéèêëîïôûùü]{3,}/i.test(s) && /\s/.test(s.trim()));
        const hit = cand.find((s) => rxPron.test(s) || rxImp.test(s));
        if (hit) affiches.push({ ligne: i + 1, texte: hit.trim().slice(0, 160) });
      });
      lignesBrut.forEach((l, i) => { if (TUTOIE.test(l) && !affiches.some((a) => a.ligne === i + 1) && !lignesSans[i].match(TUTOIE)) commentairesSeuls.push(i + 1); });
      if (affiches.length || commentairesSeuls.length) inventaire[f] = { afficheesAuClient: affiches.length, lignesCommentairesSeulement: commentairesSeuls.length, exemples: affiches.slice(0, 400) };
    }
    L.tutoiementSources = inventaire;
    const sourcesJsx = Object.entries(inventaire).filter(([f]) => !/^compiled\//.test(f));
    const totalSources = sourcesJsx.reduce((n, [, v]) => n + v.afficheesAuClient, 0);
    const totalCompile = Object.entries(inventaire).filter(([f]) => /^compiled\//.test(f)).reduce((n, [, v]) => n + v.afficheesAuClient, 0);
    L.tutoiementSourcesTotaux = { sourcesJsxJsHtml: totalSources, compiles: totalCompile, parFichier: Object.fromEntries(Object.entries(inventaire).map(([f, v]) => [f, v.afficheesAuClient])) };
    note(`R5 sources : ${totalSources} lignes à chaîne tutoyée affichée (sources), ${totalCompile} (compiled/, doublon généré)`);
    check('R5 sources : AUCUNE chaîne tutoyée affichée au client', totalSources === 0, L.tutoiementSourcesTotaux);
    expect(Object.keys(inventaire).length).toBeGreaterThan(0);
    // total navigateur
    const toutes = new Set(); Object.values(L.tutoiement).forEach((a) => a.forEach((s) => toutes.add(s)));
    L.tutoiementNavigateurUniques = [...toutes];
    note(`R5 navigateur : ${toutes.size} phrases tutoyées distinctes vues sur les écrans traversés`);
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('5. débit : deux saisies d’adresse d’affilée (sans remise à zéro du limiteur) — les suggestions disparaissent-elles en silence ?', async ({ browser }) => {
    const ctx = await newCtx(browser, true);
    const page = await ctx.newPage();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rec = enveloppe(page, OUT);
    await cheminJusquaCommande(page);
    await page.locator('[role="radiogroup"][aria-label="Mode de retrait"]').getByRole('radio', { name: /Livraison/ }).click();
    await page.waitForTimeout(500);
    resetLimiteurs();
    const cpt = { appels: 0, c200: 0, c429: 0 };
    page.on('response', (r) => { if (/\/delivery\/suggest/.test(r.url())) { cpt.appels += 1; if (r.status() === 429) cpt.c429 += 1; else if (r.status() === 200) cpt.c200 += 1; } });
    const input = page.locator('input[aria-label="Adresse de livraison"]');
    const options = async () => page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
    await input.pressSequentially('12 rue de la Gare 62300 Lens', { delay: 25 });
    await page.waitForTimeout(1500);
    const apresA = { ...cpt, options: (await options()).length };
    await input.fill('');
    await input.pressSequentially('1 rue Jean Jaures 62590 Oignies', { delay: 25 });
    await page.waitForTimeout(1500);
    const apresB = { ...cpt, options: (await options()).length };
    const alertes = await page.evaluate(() => Array.from(document.querySelectorAll('[role=alert]')).filter((e) => e.offsetParent).map((e) => e.innerText.replace(/\s+/g, ' ').slice(0, 160)));
    L.debit = { apresAdresseA: apresA, apresAdresseB: apresB, alertesVisibles: alertes, limiteSuggestParMinuteParIp: 40 };
    check('DÉBIT : 2 saisies d’adresse en < 1 min dépassent 40 suggestions/min/IP -> 429 silencieux (liste vide, aucune alerte)', apresB.c429 > 0 && apresB.options === 0 && !alertes.some((a) => /trop de|requ[êe]tes|r[ée]essay|temporairement|suggestion/i.test(a)), L.debit);
    await state(page, rec, true, '12-debit-suggestions-vides');
    expect(apresA.appels).toBeGreaterThan(15);
    rec.dispose(); await ctx.close();
    resetLimiteurs();
  });
});
