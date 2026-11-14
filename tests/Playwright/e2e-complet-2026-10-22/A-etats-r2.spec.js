// [E2E COMPLET 2026-10-22 · VAGUE A · SITE] États non couverts par M1/M2, desktop 1440x900 ET mobile 390x844.
// TOUR 1 = capture + constat. AUCUNE correction. Données de test préfixées « E2E-SITE- ».
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/e2e-complet-2026-10-22/A-etats.spec.js --workers=1 --retries=0 --reporter=line
//
// Instrument (CLAUDE.md §3ter) : miroir :8011 = état de travail du dépôt du site ; sa meta `api-key` est celle de la
// production, refusée par le backend local -> réécrite à la volée vers MIX_API_KEY (seule interception).
// Chaque état = quatuor PNG + DOM + console + réseau (mega-audit-snap) + un audit DOM/axe consigné dans le registre
// `A-ledger.json`. Aucun constat n'est conclu ici : le registre sert de base au rapport, qui exige un 2e moyen.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('./A-r2-lib');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = 'http://127.0.0.1:8011';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const OUT = path.join(REPO, 'tests', 'e2e', '__screenshots__', 'e2e-complet-A-r2');
fs.mkdirSync(OUT, { recursive: true });
const SITE_DIR = process.env.A_SITE_DIR || '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/15fca3a9-ef91-4f69-8ff0-680ff8c89dfd/scratchpad/site-local';

function envValue(key) {
  const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
  return m ? m[1].trim().replace(/^"|"$/g, '') : '';
}
const API_KEY = envValue('MIX_API_KEY');
const NOW = Date.now();
const TAG = `E2E-SITE-A-${String(NOW).slice(-6)}`;
const EMAIL = `${TAG.toLowerCase()}@lecayenne-test.fr`;
const PHONE = `06992${String(NOW).slice(-5)}`;

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);
test.use({
  video: 'off', trace: 'off', screenshot: 'off',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] },
});

// ---------------------------------------------------------------- registre
const L = { tag: TAG, states: [], checks: [], orders: [], notes: [], pageSeo: [], links: [] };
const note = (s) => { L.notes.push(s); console.log('[A] ' + s); };
function writeLedger() { fs.writeFileSync(path.join(OUT, 'A-ledger.json'), JSON.stringify(L, null, 2)); }
function check(id, ok, detail) { L.checks.push({ id, ok: !!ok, detail }); console.log(`[A CHECK] ${ok ? 'OK ' : 'KO '} ${id} ${detail !== undefined ? JSON.stringify(detail).slice(0, 300) : ''}`); }

function mysql(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim();
}
const euro = (s) => { const m = String(s || '').replace(/[  ]/g, ' ').match(/(\d+)[.,](\d{2})\s*€/); return m ? Number(`${m[1]}.${m[2]}`) : NaN; };
const lastEuro = (s) => { const all = [...String(s || '').replace(/[  ]/g, ' ').matchAll(/(\d+)[.,](\d{2})\s*€/g)]; return all.length ? Number(`${all[all.length - 1][1]}.${all[all.length - 1][2]}`) : NaN; };
const near = (a, b) => Math.abs(Number(a) - Number(b)) < 0.005;

// ---------------------------------------------------------------- instruments
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

/** Audit DOM déterministe d'un état (catégories 1,2,3,5,8 du REVIEWER_PROTOCOL) + axe (contraste, noms). */
async function auditDom(page) {
  const r = await page.evaluate(() => {
    const vis = (e) => { const s = getComputedStyle(e); const b = e.getBoundingClientRect(); return s.visibility !== 'hidden' && s.display !== 'none' && +s.opacity > 0 && b.width > 0 && b.height > 0; };
    const out = { i18n: [], rawWords: [], truncated: [], overflowX: null, brokenImgs: [], unnamed: [], overlaps: [], alerts: [], emptyHeadings: 0 };
    // 1. fuite de clé i18n / valeur brute dans le texte visible
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = w.nextNode())) {
      const p = n.parentElement; if (!p || /^(SCRIPT|STYLE|CODE|PRE|NOSCRIPT|TEXTAREA)$/.test(p.tagName) || !vis(p)) continue;
      const t = n.textContent.trim(); if (!t) continue;
      if (/^[a-z]+(\.[a-z_]+){1,4}$/.test(t) && !/\.(fr|com|html|js|css|png|jpg|svg|webp|json)$/i.test(t) && !/^\d/.test(t)) out.i18n.push(t.slice(0, 80));
      if (/(^|[\s(])(undefined|NaN|null)([\s).,]|$)|\[object Object\]|\{\{|Label\.[a-z]|0undefined/.test(t)) out.rawWords.push(t.slice(0, 80));
    }
    // 2. troncature sans info-bulle
    document.querySelectorAll('*').forEach((e) => {
      const s = getComputedStyle(e);
      if (s.textOverflow === 'ellipsis' && e.scrollWidth > e.clientWidth + 2 && vis(e) && !e.getAttribute('title') && !(e.parentElement && e.parentElement.getAttribute('title'))) out.truncated.push({ tag: e.tagName, cls: String(e.className).slice(0, 40), text: (e.textContent || '').trim().slice(0, 50) });
    });
    // débordement horizontal
    out.overflowX = { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth };
    // images cassées
    document.querySelectorAll('img').forEach((i) => { if (vis(i) && i.complete && i.naturalWidth === 0) out.brokenImgs.push((i.currentSrc || i.src).slice(0, 120)); });
    // 8. boutons / liens sans nom accessible
    document.querySelectorAll('button, a[href], [role=button]').forEach((e) => {
      if (!vis(e)) return;
      const name = (e.innerText || '').trim() || e.getAttribute('aria-label') || e.getAttribute('title') || (e.querySelector('img[alt]') || {}).alt || e.getAttribute('aria-labelledby');
      if (!name) out.unnamed.push({ tag: e.tagName, cls: String(e.className).slice(0, 50), href: (e.getAttribute('href') || '').slice(0, 50) });
    });
    // 3. superposition de cibles cliquables (>50 % de la plus petite, hors parent/enfant)
    const cl = [...document.querySelectorAll('button, a[href], [role=button]')].filter(vis).slice(0, 220);
    const rects = cl.map((e) => ({ e, b: e.getBoundingClientRect() }));
    for (let i = 0; i < rects.length; i++) for (let j = i + 1; j < rects.length; j++) {
      const a = rects[i]; const b = rects[j];
      if (a.e.contains(b.e) || b.e.contains(a.e)) continue;
      const x = Math.max(0, Math.min(a.b.right, b.b.right) - Math.max(a.b.left, b.b.left));
      const y = Math.max(0, Math.min(a.b.bottom, b.b.bottom) - Math.max(a.b.top, b.b.top));
      const inter = x * y; const small = Math.min(a.b.width * a.b.height, b.b.width * b.b.height);
      if (small > 0 && inter / small > 0.5) {
        const cx = Math.max(a.b.left, b.b.left) + x / 2; const cy = Math.max(a.b.top, b.b.top) + y / 2;
        const top = document.elementFromPoint(cx, cy);
        if (!top || !(a.e.contains(top) || b.e.contains(top) || top.contains(a.e) || top.contains(b.e))) continue; // couvert par une surcouche (modale) ou vide : pas un chevauchement réel
        if (top.contains(a.e) && top.contains(b.e) && top !== a.e && top !== b.e && !(a.e.contains(top) || b.e.contains(top))) continue; // simple conteneur commun (ex. lignes d'un lien multi-ligne)
      }
      if (small > 0 && inter / small > 0.5) out.overlaps.push([(a.e.innerText || a.e.getAttribute('aria-label') || a.e.className).toString().trim().slice(0, 30), (b.e.innerText || b.e.getAttribute('aria-label') || b.e.className).toString().trim().slice(0, 30)]);
    }
    // alertes visibles
    document.querySelectorAll('[role=alert], [role=status], .toast, [class*=toast], [class*=alert], [class*=error]').forEach((e) => { if (vis(e) && (e.innerText || '').trim()) out.alerts.push((e.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 140)); });
    return out;
  }).catch((e) => ({ erreur: String(e).slice(0, 200) }));
  // axe : contraste + noms accessibles. Peut être refusé par une CSP : l'état est alors consigné « axe indisponible ».
  let axe = null;
  try {
    const has = await page.evaluate(() => typeof window.axe !== 'undefined').catch(() => false);
    if (!has) await page.addScriptTag({ path: require.resolve('axe-core/axe.min.js') });
    axe = await page.evaluate(async () => {
      if (typeof window.axe === 'undefined') return { indisponible: true };
      const res = await window.axe.run(document, { runOnly: { type: 'rule', values: ['color-contrast', 'button-name', 'link-name', 'image-alt', 'label', 'aria-valid-attr-value', 'document-title', 'html-has-lang', 'meta-viewport', 'heading-order'] } });
      return { violations: res.violations.map((v) => ({ id: v.id, impact: v.impact, n: v.nodes.length, ex: v.nodes.slice(0, 2).map((x) => ({ t: (x.html || '').slice(0, 110), s: (x.any[0] && x.any[0].message || x.failureSummary || '').slice(0, 140) })) })) };
    });
  } catch (e) { axe = { indisponible: String(e.message || e).slice(0, 120) }; }
  return { ...r, axe };
}

/** Un état = audit + quatuor + lecture du réseau/console consignés (4xx/5xx sans alerte visible = silent_error). */
async function state(page, rec, mobile, name, extra = {}) {
  const vp = mobile ? 'M' : 'D';
  const id = `${vp}-${name}`;
  const a = await auditDom(page);
  await rec.snap(id);
  let net = []; let con = [];
  try { net = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.network.json`), 'utf8')); } catch (_e) { /* ok */ }
  try { con = JSON.parse(fs.readFileSync(path.join(OUT, `${id}.console.json`), 'utf8')); } catch (_e) { /* ok */ }
  const erreurs4xx = net.filter((x) => x.status >= 400).map((x) => `${x.method} ${x.url.replace(BASE, '').replace(SITE, '').slice(0, 90)} -> ${x.status}`);
  const conErr = con.filter((c) => /error|pageerror/.test(c.level)).map((c) => c.text.slice(0, 160));
  const entry = {
    id, url: page.url().replace(SITE, ''), ...extra,
    i18n: a.i18n, rawWords: a.rawWords, truncated: (a.truncated || []).slice(0, 6), overflowX: a.overflowX && a.overflowX.sw > a.overflowX.cw + 1 ? a.overflowX : null,
    brokenImgs: a.brokenImgs, unnamed: (a.unnamed || []).slice(0, 8), overlaps: (a.overlaps || []).slice(0, 6), alertsVisibles: (a.alerts || []).slice(0, 4),
    http4xx5xx: erreurs4xx, consoleErrors: conErr, axe: a.axe && (a.axe.violations || a.axe),
    silent_error: erreurs4xx.length > 0 && !(a.alerts || []).length,
  };
  L.states.push(entry);
  writeLedger();
  console.log(`[A STATE] ${id} i18n=${a.i18n && a.i18n.length} raw=${a.rawWords && a.rawWords.length} 4xx=${erreurs4xx.length} conErr=${conErr.length} silent=${entry.silent_error} overflow=${!!entry.overflowX}`);
  return entry;
}

// ---------------------------------------------------------------- pilotage du tunnel
async function readStep(page) {
  const r = await page.evaluate(() => {
    const t = (sel) => { const e = document.querySelector(sel); return e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : ''; };
    const next = document.querySelector('.lc-wiz-foot-next');
    const choices = Array.from(document.querySelectorAll('.lc-wiz-choice')).map((c, i) => ({ i, text: String(c.innerText || '').replace(/\s+/g, ' ').trim(), on: /is-on/.test(c.className) }));
    return { title: t('.lc-wiz-title'), sub: t('.lc-wiz-sub'), next: next ? String(next.innerText || '').replace(/\s+/g, ' ') : '', disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null, choices };
  });
  return { ...r, total: lastEuro(r.next) };
}
async function clickNext(page) { await page.locator('.lc-wiz-foot-next').click({ timeout: 8_000 }); await page.waitForTimeout(450); }

/** Marche le wizard jusqu'au récap en prenant le minimum exigé. Renvoie les étapes vues + le récap. */
async function marcherWizard(page, rec, mobile, nom, snaps) {
  const steps = []; let recap = null; let snapped = 0;
  for (let s = 0; s < 16; s++) {
    const step = await readStep(page);
    steps.push({ title: step.title, sub: step.sub, total: step.total, choix: step.choices.length });
    if (/Ajouter au panier/i.test(step.next)) { recap = step; break; }
    if (snaps && snapped < snaps.length && (s === 0 || s === 2)) { await state(page, rec, mobile, `${nom}-etape-${snaps[snapped++]}`, { etape: step.title }); }
    for (let k = 0; k < 3 && (await readStep(page)).disabled; k++) {
      await page.locator('.lc-wiz-choice:not(.is-on)').first().click();
      await page.waitForTimeout(300);
    }
    if ((await readStep(page)).title !== step.title) continue;
    await clickNext(page);
  }
  return { steps, recap };
}
async function ajouterAuPanier(page) {
  for (let essai = 1; essai <= 2; essai++) {
    await page.locator('.lc-wiz-foot-next').click({ timeout: 8_000 });
    const t0 = Date.now(); let err = '';
    while (Date.now() - t0 < 20_000) {
      if (await page.locator('.lc-cart-drawer.is-open').count()) return { ok: true, essai };
      if (!(await page.locator('.lc-wiz').count())) return { ok: true, essai, ferme: true };
      err = await page.evaluate(() => { const a = document.querySelector('.lc-wiz [role="alert"]'); return a ? String(a.innerText || '').replace(/\s+/g, ' ').trim() : ''; });
      if (err) break;
      await page.waitForTimeout(250);
    }
    note(`ajout au panier essai ${essai} : ${err || 'tiroir non ouvert en 20 s'}`);
    await page.waitForTimeout(2000);
  }
  return { ok: false };
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

const S = { dbPrices: {}, token: '', apiCatalog: [], posHeaders: null, orders: {} };

async function authentifier(page, ctxReq) {
  // 1) l'écran : e-mail inconnu -> prénom + téléphone -> code (l'envoi SMTP échoue en local : environnement, connu M1)
  await page.locator('#auth-email').fill(EMAIL);
  await page.getByRole('button', { name: /Recevoir le code par email/ }).click();
  await expect(page.locator('[data-testid="funnel-identite"]')).toBeVisible({ timeout: 15_000 });
  await page.locator('#auth-first').fill(`${TAG} Client`);
  await page.locator('#auth-phone').fill(PHONE);
  const r = page.waitForResponse((x) => x.request().method() === 'POST' && /guest-signup\/email-login/.test(x.url()), { timeout: 30_000 });
  await page.getByRole('button', { name: /Créer mon compte et recevoir le code/ }).click();
  const resp = await r;
  const msg = await page.locator('#lc-contact-block [role="alert"]').innerText().catch(() => '');
  note(`email-login -> HTTP ${resp.status()} ; message client : « ${msg.replace(/\s+/g, ' ')} »`);
  const token = mysql(`SELECT token FROM otps WHERE phone='${PHONE}' ORDER BY created_at DESC LIMIT 1`);
  expect(token).toMatch(/^\d{4}$/);
  execFileSync('php', ['artisan', 'tinker', '--execute', 'app(\\Illuminate\\Cache\\RateLimiter::class)->clear(sha1("|127.0.0.1")); echo "ok";'], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
  const v = await ctxReq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' }, data: { token, phone: PHONE, code: '+33', email: EMAIL, first_name: `${TAG} Client` } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  S.token = vj.token;
  await page.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [S.token, PHONE]);
}

async function chaineTotaux(page, mobile, label, produit, totaux) {
  // Passe la commande ASAP « payer sur place » et relève TOUS les totaux.
  const sink = {};
  page.on('request', (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { sink.body = JSON.parse(req.postData() || '{}'); } catch (_e) { sink.body = null; } } });
  const orderResp = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 60_000 });
  await page.locator('.lcf-cta-bar-next').click();
  const resp = await orderResp;
  const j = await resp.json().catch(() => ({}));
  const d = (j && j.data) || j || {};
  const o = { label, produit, http: resp.status(), id: d.id, serial: d.order_serial_no, queue: d.queue_number, apiTotal: Number(d.total), expected_total: sink.body && sink.body.expected_total, bodyKeys: sink.body && Object.keys(sink.body), corps422: resp.status() >= 400 ? JSON.stringify(j).slice(0, 400) : undefined, ...totaux };
  S.orders[label] = o; L.orders.push(o);
  return { o, j };
}

// ============================================================================
test.describe('Vague A — site : états non couverts', () => {
  let ctxD, ctxM, pageD, pageM, recD, recM, rq;

  test.beforeAll(async ({ browser, playwright }) => {
    mysql('SELECT name, price FROM items WHERE deleted_at IS NULL AND status = 5').split('\n').forEach((l) => { const [n, p] = l.split('\t'); if (n) S.dbPrices[n] = Number(p); });
    ctxD = await newCtx(browser, false); ctxM = await newCtx(browser, true);
    pageD = await ctxD.newPage(); pageM = await ctxM.newPage();
    await pageD.emulateMedia({ reducedMotion: 'reduce' }); await pageM.emulateMedia({ reducedMotion: 'reduce' });
    recD = attachMegaAuditRecorder(pageD, OUT); recM = attachMegaAuditRecorder(pageM, OUT);
    rq = await playwright.request.newContext();
    const c = await rq.get(`${BASE}/api/frontend/item?branch_id=1`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
    S.apiCatalog = ((await c.json()).data) || [];
  });
  test.afterAll(async () => {
    writeLedger();
    if (recD) recD.dispose(); if (recM) recM.dispose();
    if (ctxD) await ctxD.close().catch(() => {}); if (ctxM) await ctxM.close().catch(() => {});
    if (rq) await rq.dispose().catch(() => {});
  });

  // ------------------------------------------------------------------------
  test('1. pages SEO / légales / horaires / livraison — DOM, SEO, liens, desktop + mobile', async () => {
    const pages = ['a-propos', 'bols-et-frites', 'burgers', 'carte', 'commander', 'horaires', 'livraison-henin-beaumont', 'manger-pas-cher-henin-beaumont', 'manger-tard-henin-beaumont', 'roue', 'sandwichs-galettes', 'tacos', 'legal/allergens', 'legal/cgv', 'legal/cookies', 'legal/mentions', 'legal/privacy', '404'];
    const snapD = new Set(['carte', 'horaires', 'livraison-henin-beaumont', 'legal/mentions', 'legal/allergens', '404', 'roue']);
    const snapM = new Set(['horaires', 'livraison-henin-beaumont', 'legal/mentions', 'roue']);
    const vercel = JSON.parse(fs.readFileSync(path.join(SITE_DIR, 'vercel.json'), 'utf8'));
    const redirSources = (vercel.redirects || []).map((r) => r.source);
    const dejaVerifies = new Map();
    for (const mobile of [false, true]) {
      const page = mobile ? pageM : pageD; const rec = mobile ? recM : recD;
      for (const p of pages) {
        const r4 = [];
        const onR = (rr) => { if (rr.status() >= 400) r4.push(`${rr.request().method()} ${rr.url().slice(0, 110)} -> ${rr.status()}`); };
        page.on('response', onR);
        const resp = await page.goto(`${SITE}/${p}.html`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
        await page.waitForTimeout(1200);
        page.off('response', onR);
        const status = resp && resp.status();
        const seo = await page.evaluate(() => {
          const q = (s) => document.querySelector(s);
          const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => { try { JSON.parse(s.textContent); return 'ok'; } catch (e) { return 'INVALIDE'; } });
          return {
            title: document.title, desc: (q('meta[name=description]') || {}).content || '', canonical: (q('link[rel=canonical]') || {}).href || '',
            h1: document.querySelectorAll('h1').length, lang: document.documentElement.lang, og: !!q('meta[property="og:title"]'), viewport: !!q('meta[name=viewport]'),
            ld, robots: (q('meta[name=robots]') || {}).content || '',
            hrefs: [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')).filter((h) => h && !/^(mailto:|tel:|#|javascript:)/.test(h)),
            imgSansAlt: [...document.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).length,
            texte: document.body.innerText.length,
          };
        });
        L.pageSeo.push({ page: p, viewport: mobile ? 'M' : 'D', status, ...seo, hrefs: undefined, nbLiens: seo.hrefs.length, http4xx: r4 });
        const body = await page.locator('body').innerText();
        check(`PAGE ${mobile ? 'M' : 'D'} ${p}.html : HTTP 200 (404.html servie en 200 par le miroir)`, status === 200, status);
        check(`PAGE ${mobile ? 'M' : 'D'} ${p} : pas de valeur brute / à compléter`, !/\bundefined\b|\bNaN\b|Label\.[a-z]|À COMPLÉTER|\[.*PROPRIÉTAIRE/i.test(body), body.match(/\bundefined\b|\bNaN\b|Label\.[a-z]|À COMPLÉTER|\[.*PROPRIÉTAIRE/i));
        if (p !== '404') {
          check(`PAGE ${p} : <title>, description, canonical, 1 h1, lang fr`, !!seo.title && seo.desc.length > 40 && !!seo.canonical && seo.h1 === 1 && /^fr/.test(seo.lang), { title: seo.title.slice(0, 60), descLen: seo.desc.length, canonical: seo.canonical, h1: seo.h1, lang: seo.lang });
          check(`PAGE ${p} : JSON-LD valide`, seo.ld.every((x) => x === 'ok'), seo.ld);
        }
        // liens internes : résolus une seule fois
        if (!mobile) {
          for (const h of seo.hrefs) {
            if (/^https?:\/\//.test(h) && !h.startsWith(SITE)) continue;
            const abs = new URL(h, `${SITE}/${p}.html`); abs.hash = '';
            const key = abs.pathname + abs.search;
            if (dejaVerifies.has(key)) continue;
            const redir = redirSources.some((s) => !s.includes(':') && s === abs.pathname);
            const r = await rq.get(abs.toString(), { maxRedirects: 0, timeout: 15_000 }).catch(() => null);
            dejaVerifies.set(key, { status: r && r.status(), depuis: p, redirigeVercel: redir });
          }
        }
        if ((mobile ? snapM : snapD).has(p)) await state(page, rec, mobile, `page-${p.replace('/', '-')}`, { status });
        else {
          const a = await auditDom(page);
          L.states.push({ id: `${mobile ? 'M' : 'D'}-page-${p.replace('/', '-')}-AUDIT-SANS-PNG`, url: `/${p}.html`, i18n: a.i18n, rawWords: a.rawWords, overflowX: a.overflowX && a.overflowX.sw > a.overflowX.cw + 1 ? a.overflowX : null, brokenImgs: a.brokenImgs, unnamed: (a.unnamed || []).slice(0, 5), overlaps: (a.overlaps || []).slice(0, 4), axe: a.axe && (a.axe.violations || a.axe) });
        }
        if (mobile) {
          const w = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
          check(`PAGE M ${p} : pas de défilement horizontal`, w.sw <= w.cw + 1, w);
        }
      }
    }
    L.links = [...dejaVerifies.entries()].map(([k, v]) => ({ href: k, ...v }));
    const casses = L.links.filter((l) => l.status === undefined || l.status >= 400 || l.status === null).filter((l) => !l.redirigeVercel);
    check('LIENS internes des pages SEO : aucun 4xx (hors redirections vercel.json absentes du miroir python)', casses.length === 0, casses.slice(0, 12));
    // sitemap : chaque URL du sitemap correspond à un fichier du dépôt
    const sm = fs.readFileSync(path.join(SITE_DIR, 'sitemap.xml'), 'utf8');
    const locs = [...sm.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
    const manquants = [];
    for (const u of locs) { const pth = new URL(u).pathname; if (pth === '/') continue; const r = await rq.get(SITE + pth, { maxRedirects: 0 }).catch(() => null); if (!r || r.status() >= 400) manquants.push(pth); }
    check('SITEMAP : chaque URL répond < 400 sur le miroir', manquants.length === 0, { total: locs.length, manquants });
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('2. desktop : accueil, carte (catégories, prix = items), fiche produit', async () => {
    const page = pageD; const rec = recD;
    await gotoSite(page);
    await state(page, rec, false, 'accueil');
    await page.evaluate(() => { window.location.hash = '#menu'; });
    await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1800);
    await state(page, rec, false, 'carte-tout');
    {
      const avant = await page.locator('button.lc-card-item-name').count();
      for (let k = 0; k < 14; k++) { await page.mouse.wheel(0, 1500); await page.waitForTimeout(350); }
      await page.waitForTimeout(600);
      const apres = await page.locator('button.lc-card-item-name').count();
      const entete = (await page.locator('body').innerText()).match(/(\d+)\s+produits/);
      L.carteScroll = { avantScroll: avant, apresScroll: apres, enteteProduits: entete && Number(entete[1]) };
      check('CARTE « Tout » : après défilement, autant de cartes que « N produits » annoncé', apres === (entete && Number(entete[1])), L.carteScroll);
      await page.evaluate(() => window.scrollTo(0, 0)); await page.waitForTimeout(300);
    }
    const cards = await page.evaluate(() => Array.from(document.querySelectorAll('button.lc-card-item-name')).map((b) => {
      const card = b.closest('.lc-card, article, li, .lc-menu-card') || b.parentElement.parentElement;
      const pr = card ? card.querySelector('.lc-card-item-price') : null;
      return { name: b.textContent.trim(), price: pr ? pr.textContent.trim() : '', soldOut: b.disabled, visible: !!b.offsetParent };
    }));
    // 1er moyen : prix lus à l'écran vs table items (SQL). 2e moyen : API publique /api/frontend/item.
    const ecartsSql = []; const ecartsApi = []; const inconnus = [];
    for (const c of cards) {
      const p = euro(c.price);
      if (!(c.name in S.dbPrices)) { inconnus.push(c.name); continue; }
      if (!near(p, S.dbPrices[c.name])) ecartsSql.push({ name: c.name, site: p, db: S.dbPrices[c.name] });
      const a = S.apiCatalog.find((x) => x.name === c.name);
      if (!a) ecartsApi.push({ name: c.name, api: 'absent' }); else if (!near(p, Number(a.price))) ecartsApi.push({ name: c.name, site: p, api: Number(a.price) });
    }
    check(`CARTE : ${cards.length} cartes ; prix == table items (SQL)`, ecartsSql.length === 0, ecartsSql);
    check('CARTE : prix == API publique /api/frontend/item (2e moyen)', ecartsApi.length === 0, ecartsApi);
    check('CARTE : aucun produit inventé (tout nom de carte existe dans items)', inconnus.length === 0, inconnus);
    // produits vendables au web dans l'API mais absents de la carte, et réciproquement (hors catégories techniques)
    const apiWeb = S.apiCatalog.filter((x) => (x.channels || []).includes('web') && !/Technique|Suppléments|Sauces suppl/.test(x.category_name || ''));
    const nomsCarte = new Set(cards.map((c) => c.name));
    const absentsCarte = apiWeb.filter((x) => !nomsCarte.has(x.name)).map((x) => `${x.name} (${x.category_name}, dispo=${x.is_available})`);
    note(`API : ${S.apiCatalog.length} items, ${apiWeb.length} vendables web hors technique ; carte : ${cards.length} ; absents de la carte : ${absentsCarte.join(' | ') || '—'}`);
    check('CARTE : aucun produit de test (E2E_PLAYWRIGHT) exposé au client', !cards.some((c) => /E2E|PLAYWRIGHT|^test/i.test(c.name)), cards.filter((c) => /E2E|PLAYWRIGHT/i.test(c.name)));
    L.carteDesktop = { nbCartes: cards.length, soldOut: cards.filter((c) => c.soldOut).map((c) => c.name), absentsCarte };
    // en rupture côté API vs désactivé côté carte
    const ruptureApi = S.apiCatalog.filter((x) => x.is_available === false).map((x) => x.name);
    const ruptureCarte = cards.filter((c) => c.soldOut).map((c) => c.name);
    check('CARTE : produits en rupture (API is_available=false) == boutons désactivés de la carte', ruptureApi.filter((n) => nomsCarte.has(n)).sort().join('|') === ruptureCarte.sort().join('|'), { ruptureApi, ruptureCarte });
    // onglets de catégories
    const onglets = await page.evaluate(() => Array.from(document.querySelectorAll('button')).map((b) => b.textContent.trim()).filter((t) => /^.?[A-Za-zÀ-ÿ' ]+\s*\d+$/.test(t)));
    note(`onglets de catégorie : ${onglets.join(' | ')}`);
    const catsDb = mysql('SELECT name FROM item_categories WHERE deleted_at IS NULL AND status = 5').split('\n');
    for (const o of onglets) { let nm = o.replace(/\s*\d+$/, '').trim(); if (nm.length > 1 && nm[0].toLowerCase() === nm[1].toLowerCase()) nm = nm.slice(1); if (nm && nm !== 'Tout') check(`CARTE : onglet « ${nm} » existe en base`, catsDb.includes(nm), nm); }
    // Burgers
    await page.getByRole('button', { name: /^.?Burgers\s*\d+/ }).first().click();
    await page.waitForTimeout(700);
    await state(page, rec, false, 'carte-burgers');
    const vis = await page.evaluate(() => Array.from(document.querySelectorAll('button.lc-card-item-name')).filter((b) => b.offsetParent).map((b) => b.textContent.trim()));
    const burgersDb = mysql("SELECT i.name FROM items i JOIN item_categories c ON c.id=i.item_category_id WHERE i.deleted_at IS NULL AND i.status=5 AND c.name='Burgers'").split('\n');
    check('CARTE filtre Burgers : == catégorie Burgers de la base', [...vis].sort().join('|') === [...burgersDb].sort().join('|'), { vis, burgersDb });
    // fiche
    await page.locator('[aria-label="Voir Cheese Burger"]').first().click();
    await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 10_000 });
    await page.waitForTimeout(600);
    const detail = (await page.locator('.lc-detail').innerText()).replace(/\s+/g, ' ');
    await state(page, rec, false, 'fiche-cheese-burger');
    check('FICHE Cheese Burger : prix affiché == items', detail.includes(S.dbPrices['Cheese Burger'].toFixed(2).replace('.', ',')), detail.slice(0, 200));
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('3. desktop : wizard, panier, commande, confirmation, suivi (chaîne des totaux)', async () => {
    const page = pageD; const rec = recD;
    const wizBtn = page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first();
    await wizBtn.click();
    await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
    const { steps, recap } = await marcherWizard(page, rec, false, 'wizard-cheese-burger', ['1', '3']);
    note(`wizard Cheese Burger : ${steps.map((s) => `${s.title} [${s.sub}]`).join(' -> ')}`);
    expect(recap, 'récap atteint').toBeTruthy();
    await state(page, rec, false, 'wizard-recap');
    const tRecap = recap.total;
    check('WIZARD récap >= prix items du Cheese Burger', tRecap >= S.dbPrices['Cheese Burger'] - 0.005, { tRecap, prix: S.dbPrices['Cheese Burger'] });
    expect((await ajouterAuPanier(page)).ok).toBeTruthy();
    const drawer = page.locator('.lc-cart-drawer.is-open');
    await expect(drawer).toBeVisible({ timeout: 10_000 });
    await page.waitForTimeout(500);
    const tCart = lastEuro(await drawer.locator('.lc-cart-totals-row.is-total').innerText());
    await state(page, rec, false, 'panier');
    check('TOTAL panier == récap du wizard', near(tCart, tRecap), { tCart, tRecap });
    await drawer.getByRole('button', { name: /Passer commande/ }).click();
    await page.waitForTimeout(1200);
    // upsell éventuel
    if (await page.getByRole('button', { name: /Ajouter/ }).first().isVisible({ timeout: 2500 }).catch(() => false) && !(await page.locator('.lcf-page').count())) {
      await state(page, rec, false, 'upsell');
    }
    await versPageCommande(page);
    const tSummary = lastEuro(await page.locator('.lcf-summary-totals').innerText());
    const tCta = lastEuro(await page.locator('.lcf-cta-bar-next').innerText());
    await state(page, rec, false, 'commande-retrait-paiement');
    check('TOTAL récap de commande == panier', near(tSummary, tCart), { tSummary, tCart });
    check('TOTAL bouton de confirmation == récap de commande', near(tCta, tSummary), { tCta, tSummary });
    // total HT/TVA affichés ?
    const sumTxt = (await page.locator('.lcf-summary-totals').innerText()).replace(/\s+/g, ' ');
    note(`récap de commande (texte) : « ${sumTxt.slice(0, 200)} »`);
    await authentifier(page, rq);
    const { o, j } = await chaineTotaux(page, false, 'desktop-cheese-burger', 'Cheese Burger', { tRecap, tCart, tSummary, tCta });
    note(`POST /api/frontend/order -> HTTP ${o.http} id=${o.id} serial=${o.serial} total=${o.apiTotal} expected_total=${o.expected_total}`);
    if (o.http >= 400) {
      // l'écran dit-il quelque chose ?
      await page.waitForTimeout(1200);
      const msg = await page.evaluate(() => Array.from(document.querySelectorAll('[role=alert]')).map((e) => e.innerText.replace(/\s+/g, ' ')).join(' | '));
      note(`commande refusée : corps ${o.corps422} ; message visible : « ${msg} »`);
      await state(page, rec, false, 'commande-refusee', { corps: o.corps422 });
    }
    expect(o.http, `commande : ${o.corps422 || ''}`).toBeLessThan(300);
    await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(700);
    await state(page, rec, false, 'confirmation');
    const confTxt = (await page.locator('.lcf-confirm').innerText()).replace(/\s+/g, ' ');
    o.tConfirm = lastEuro(await page.locator('.lcf-ticket-cell-val').last().innerText());
    o.confirmTexte = confTxt.slice(0, 400);
    // base
    const row = mysql(`SELECT total,subtotal,discount,delivery_charge,total_tax,status,payment_status,source_surface,order_type,IFNULL(queue_number,''),IFNULL(order_serial_no,''),IFNULL(tracking_token,'') FROM orders WHERE id=${Number(o.id)}`).split('\t');
    o.db = { total: Number(row[0]), subtotal: Number(row[1]), discount: Number(row[2]), delivery: Number(row[3]), tva: Number(row[4]), status: Number(row[5]), pay: Number(row[6]), source: row[7], type: Number(row[8]), queue: row[9], serial: row[10], tracking: row[11] };
    const lignes = mysql(`SELECT price, quantity, item_variation_total, item_extra_total, total_price, IFNULL(tax_amount,0) FROM order_items WHERE order_id=${Number(o.id)} AND deleted_at IS NULL`).split('\n').map((l) => l.split('\t').map(Number));
    o.dbLignesTotal = +lignes.reduce((s, l) => s + l[4], 0).toFixed(2);
    check('TOTAL confirmation == total POST == orders.total == panier', near(o.tConfirm, tCart) && near(o.apiTotal, tCart) && near(o.db.total, tCart), { tConfirm: o.tConfirm, api: o.apiTotal, db: o.db.total, tCart });
    check('TOTAL expected_total envoyé == total facturé', near(o.expected_total, o.db.total), { exp: o.expected_total, db: o.db.total });
    check('TOTAL orders.total == Σ order_items.total_price (+ livraison - remise)', near(o.db.total, o.dbLignesTotal + o.db.delivery - o.db.discount), { total: o.db.total, lignes: o.dbLignesTotal, deliv: o.db.delivery, remise: o.db.discount });
    check('COMMANDE web : status PENDING(1), non payée, source web, à emporter(10), pas de n° fiscal avant paiement', o.db.status === 1 && o.db.source === 'web' && o.db.type === 10, o.db);
    check('CLIENT envoie id/quantité/options, jamais de prix unitaire', !(o.bodyKeys || []).includes('price'), o.bodyKeys);
    check('CONFIRMATION porte le n° de commande', confTxt.includes(o.db.queue) || confTxt.includes(o.db.serial) || confTxt.includes(String(o.id)), { queue: o.db.queue, serial: o.db.serial, id: o.id });
    check('CONFIRMATION : texte dit « comptoir/sur place »', /comptoir|sur place/i.test(confTxt), confTxt.slice(0, 160));
    check('CLIENT voit son numéro de retrait (queue) sur la confirmation [M1-104 connu si KO]', o.db.queue ? confTxt.includes(o.db.queue) : false, { queue: o.db.queue });

    // --- côté caisse (lecture API) puis cuisine ---
    clearFoodKingRateLimits();
    const lg = await rq.post(`${BASE}/api/auth/login`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json', 'X-Device-Id': `e2e-site-a-${NOW}` }, data: { email: 'pos@lecayenne.fr', password: '123456' } });
    const lj = await lg.json().catch(() => ({}));
    expect(lj.token, `connexion API pos@ HTTP ${lg.status()}`).toBeTruthy();
    S.posHeaders = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${lj.token}`, Accept: 'application/json' };
    const pend = await rq.get(`${BASE}/api/admin/pos/web-orders/pending`, { headers: S.posHeaders });
    const pj = await pend.json().catch(() => ({}));
    const hit = ((pj && pj.data) || []).find((x) => Number(x.id) === Number(o.id));
    o.caisse = { http: pend.status(), present: !!hit, meta: pj.meta };
    check('CAISSE : la commande web est dans la file « à accepter » (API admin/pos/web-orders/pending)', !!hit, { http: pend.status(), meta: pj.meta });
    if (hit) {
      const tot = Object.entries(hit).filter(([k, v]) => /total/i.test(k) && v !== null && typeof v !== 'object').map(([k, v]) => `${k}=${v}`);
      o.caisse.totalFields = tot;
      const numerique = Object.entries(hit).filter(([k]) => /^(total|total_amount_price|total_currency_price)$/i.test(k)).map(([k, v]) => [k, v]);
      note(`caisse : champs total de la commande : ${tot.join(', ')}`);
      const nameBlob = JSON.stringify(hit);
      check('CAISSE : nom du client + produit visibles dans la ressource', nameBlob.includes(TAG) && /Cheese Burger/.test(nameBlob), { client: nameBlob.includes(TAG), produit: /Cheese Burger/.test(nameBlob) });
      const t = Number(hit.total ?? hit.total_amount_price ?? NaN);
      if (!Number.isNaN(t)) check('CAISSE : total de la ressource == orders.total', near(t, o.db.total), { t, db: o.db.total });
      void numerique;
    }
    const kdsAvant = await rq.get(`${BASE}/api/admin/kds-order`, { headers: S.posHeaders });
    const kdsAvantJ = await kdsAvant.json().catch(() => ({}));
    o.kdsAvantAcceptation = ((kdsAvantJ.data) || []).some((x) => Number(x.id) === Number(o.id));
    note(`cuisine AVANT acceptation : commande présente=${o.kdsAvantAcceptation} (HTTP ${kdsAvant.status()}) — règle produit : une web PENDING n'est pas en cuisine`);
    const acc = await rq.post(`${BASE}/api/admin/online-order/change-status/${o.id}`, { headers: { ...S.posHeaders, 'X-Idempotency-Key': `e2e-site-a-accept-${o.id}` }, data: { status: 4, preparation_time: 17 } });
    o.accept = acc.status();
    note(`acceptation par la caisse (API, commande E2E-SITE) -> HTTP ${acc.status()}`);
    const kdsApres = await rq.get(`${BASE}/api/admin/kds-order`, { headers: S.posHeaders });
    const kdsApresJ = await kdsApres.json().catch(() => ({}));
    const kHit = ((kdsApresJ.data) || []).find((x) => Number(x.id) === Number(o.id));
    o.kdsApres = !!kHit;
    check('CUISINE : après acceptation, la commande est dans admin/kds-order (lecture API)', !!kHit, { accept: acc.status(), http: kdsApres.status(), meta: kdsApresJ.meta });
    if (kHit) check('CUISINE : ligne de commande (Cheese Burger) portée', /Cheese Burger|CHEESE/i.test(JSON.stringify(kHit.order_items || kHit)), (JSON.stringify(kHit.order_items || []).slice(0, 200)));

    // --- suivi côté site ---
    await page.getByRole('button', { name: /Suivre ma commande/ }).click();
    await expect(page.locator('.lcf-track-status')).toBeVisible({ timeout: 20_000 });
    let suivi = '';
    await expect.poll(async () => { suivi = (await page.locator('.lcf-track-status').innerText()).replace(/\s+/g, ' '); return suivi; }, { timeout: 50_000, intervals: [1000] }).not.toMatch(/SUIVI EN COURS|EN ATTENTE|PAIEMENT NON/i);
    await page.waitForTimeout(900);
    await state(page, rec, false, 'suivi');
    const suiviPage = (await page.locator('.lcf-page, body').first().innerText()).replace(/\s+/g, ' ');
    o.suiviStatut = suivi.slice(0, 120);
    check('SUIVI : total affiché == orders.total', suiviPage.includes(o.db.total.toFixed(2).replace('.', ',')), { suivi: suiviPage.slice(0, 220) });
    if (o.db.tracking) {
      const tr = await rq.get(`${BASE}/api/frontend/order/track/${o.db.tracking}`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
      const trj = await tr.json().catch(() => ({}));
      o.trackApi = { http: tr.status(), keys: Object.keys(trj.data || trj).slice(0, 25), statut: (trj.data || trj).status_label || (trj.data || trj).status };
      const blob = JSON.stringify(trj);
      check('SUIVI API (order/track/{token}) : HTTP 200 et total == orders.total', tr.status() === 200 && blob.includes(o.db.total.toFixed(2)), { http: tr.status(), blob: blob.slice(0, 220) });
    }
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('4. desktop : espace client (commandes, fidélité) connecté', async () => {
    const page = pageD; const rec = recD;
    await page.goto(`${SITE}/?dev#orders`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
    await page.waitForTimeout(2500);
    await state(page, rec, false, 'mes-commandes');
    const txt = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    const o = S.orders['desktop-cheese-burger'];
    if (o && o.db) check('MES COMMANDES : la commande passée est listée avec son total', txt.includes(o.db.total.toFixed(2).replace('.', ',')) && /Cheese/i.test(txt), txt.slice(0, 260));
    await page.evaluate(() => { window.location.hash = '#loyalty'; });
    await page.waitForTimeout(2500);
    await state(page, rec, false, 'fidelite');
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('5. mobile : accueil, carte, fiche, wizard, panier, commande, confirmation, suivi, compte', async () => {
    const page = pageM; const rec = recM;
    await page.goto(`${SITE}/?dev`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); localStorage.removeItem('lc.cart.v1'); }, [S.token, PHONE]);
    await gotoSite(page);
    await state(page, rec, true, 'accueil');
    await page.evaluate(() => { window.location.hash = '#menu'; });
    await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1800);
    await state(page, rec, true, 'carte-tout');
    const prixMobile = await page.evaluate(() => Array.from(document.querySelectorAll('button.lc-card-item-name')).map((b) => { const c = b.closest('.lc-card, article, li, .lc-menu-card') || b.parentElement.parentElement; const p = c && c.querySelector('.lc-card-item-price'); return { name: b.textContent.trim(), price: p ? p.textContent.trim() : '' }; }));
    const ecarts = prixMobile.filter((c) => c.name in S.dbPrices && !near(euro(c.price), S.dbPrices[c.name])).map((c) => ({ n: c.name, site: c.price, db: S.dbPrices[c.name] }));
    check(`CARTE mobile : ${prixMobile.length} cartes, prix == items`, ecarts.length === 0, ecarts);
    check('CARTE mobile : même nombre de cartes que desktop', prixMobile.length === (L.carteDesktop || {}).nbCartes, { mobile: prixMobile.length, desktop: (L.carteDesktop || {}).nbCartes });
    await page.locator('[aria-label="Voir Chicken Burger"]').first().click();
    await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 10_000 });
    await page.waitForTimeout(600);
    await state(page, rec, true, 'fiche-chicken-burger');
    await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
    await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
    const { steps, recap } = await marcherWizard(page, rec, true, 'wizard-chicken-burger', ['1']);
    note(`wizard mobile Chicken Burger : ${steps.map((s) => `${s.title} [${s.sub}]`).join(' -> ')}`);
    expect(recap).toBeTruthy();
    await state(page, rec, true, 'wizard-recap');
    const tRecap = recap.total;
    expect((await ajouterAuPanier(page)).ok).toBeTruthy();
    await page.waitForTimeout(600);
    if (!(await page.locator('.lc-cart-drawer.is-open').count())) await page.getByRole('button', { name: /^Panier/ }).first().click();
    const drawer = page.locator('.lc-cart-drawer.is-open');
    await expect(drawer).toBeVisible({ timeout: 10_000 });
    await page.waitForTimeout(500);
    const tCart = lastEuro(await drawer.locator('.lc-cart-totals-row.is-total').innerText());
    await state(page, rec, true, 'panier');
    check('TOTAL mobile panier == récap wizard', near(tCart, tRecap), { tCart, tRecap });
    await drawer.getByRole('button', { name: /Passer commande/ }).click();
    await page.waitForTimeout(1200);
    await versPageCommande(page);
    const tSummary = lastEuro(await page.locator('.lcf-summary-totals').innerText());
    const tCta = lastEuro(await page.locator('.lcf-cta-bar-next').innerText());
    await state(page, rec, true, 'commande-haut');
    await page.locator('.lcf-cta-bar-next').scrollIntoViewIfNeeded();
    check('TOTAL mobile récap commande == panier == bouton', near(tSummary, tCart) && near(tCta, tCart), { tSummary, tCart, tCta });
    const { o } = await chaineTotaux(page, true, 'mobile-chicken-burger', 'Chicken Burger', { tRecap, tCart, tSummary, tCta });
    note(`mobile POST order -> HTTP ${o.http} id=${o.id} total=${o.apiTotal}`);
    if (o.http >= 400) { await page.waitForTimeout(1000); await state(page, rec, true, 'commande-refusee', { corps: o.corps422 }); }
    expect(o.http, o.corps422 || '').toBeLessThan(300);
    await expect(page.locator('.lcf-confirm')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(700);
    await state(page, rec, true, 'confirmation');
    o.tConfirm = lastEuro(await page.locator('.lcf-ticket-cell-val').last().innerText());
    const row = mysql(`SELECT total,status,source_surface,order_type,IFNULL(queue_number,'') FROM orders WHERE id=${Number(o.id)}`).split('\t');
    o.db = { total: Number(row[0]), status: Number(row[1]), source: row[2], type: Number(row[3]), queue: row[4] };
    check('TOTAL mobile confirmation == POST == orders.total == panier', near(o.tConfirm, tCart) && near(o.apiTotal, tCart) && near(o.db.total, tCart), { conf: o.tConfirm, api: o.apiTotal, db: o.db.total, tCart });
    await page.getByRole('button', { name: /Suivre ma commande/ }).click();
    await expect(page.locator('.lcf-track-status')).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(1500);
    await state(page, rec, true, 'suivi');
    const w = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check('MOBILE suivi : pas de défilement horizontal', w.sw <= w.cw + 1, w);
    await page.goto(`${SITE}/?dev#orders`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
    await page.waitForTimeout(2500);
    await state(page, rec, true, 'mes-commandes');
    const txt = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    check('MES COMMANDES mobile : listée avec son total', txt.includes(o.db.total.toFixed(2).replace('.', ',')) && /Chicken/i.test(txt), txt.slice(0, 240));
    await page.evaluate(() => { window.location.hash = '#loyalty'; });
    await page.waitForTimeout(2500);
    await state(page, rec, true, 'fidelite');
    writeLedger();
  });

  // ------------------------------------------------------------------------
  test('6. nettoyage : lister ce que cette vague a créé (aucune suppression de données produit)', async () => {
    const rows = mysql(`SELECT o.id, o.total, o.status, o.pos_customer_name FROM orders o WHERE o.user_id IN (SELECT id FROM users WHERE email='${EMAIL}')`);
    note(`commandes créées par la vague A (user ${EMAIL}) : ${rows.replace(/\n/g, ' ; ')}`);
    L.cleanup = { email: EMAIL, phone: PHONE, orders: rows };
    writeLedger();
  });
});
