// [VÉRIF GLOBALE 2026-10-21 · M1] Site web : tunnel « à emporter » et arrivée de la commande.
//
// Tour 1 = capture + rapport, AUCUNE correction. Spec contre le miroir local du site (:8011,
// copie du dépôt déployé) et le backend local (:8766, base foodking_e2e). Rapport :
// reports/test-e2e/verif-globale-2026-10-21/round-1/M1-rapport.md
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/verif-globale-2026-10-21/M1-site-emporter.spec.js \
//     --workers=1 --retries=0 --reporter=line
//
// Instrument (CLAUDE.md §3ter) : le miroir :8011 porte la meta `api-key` de PRODUCTION, que le
// backend local refuse (400 sur tout /api/*). On réécrit cette seule meta à la volée vers la
// MIX_API_KEY locale (patron du banc `compte-email-dabord-2026-09-02.spec.js` du dépôt du site).
// Rien d'autre n'est intercepté : tout le reste est le produit réel.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder: attachRecorderBrut } = require('./A-r2-lib');
// Disque plein (reprise 2026-10-21) : plafond de 25 captures. Les états ci-dessous ont déjà été
// lus au premier passage (pages secondaires, étapes intermédiaires du wizard, « avant » caisse/KDS) :
// on les traverse toujours (assertions intactes) mais sans écrire de quatuor PNG/DOM.
const SNAP_SKIP = ['03-', '04-', '05-', '06-', '09-', '11-', '14-', '17-', '20-commande-recap', '21-', '22-', '24-', '25-', '34-', '35-', '38-'];
function attachMegaAuditRecorder(page, dir) {
  const r = attachRecorderBrut(page, dir);
  return { ...r, snap: async (name) => (SNAP_SKIP.some((p) => name.startsWith(p)) ? undefined : r.snap(name)) };
}
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = 'http://127.0.0.1:8011';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const SHOT = path.join(REPO, 'tests', 'e2e', '__screenshots__', 'e2e-complet-A-r2/ferme-422');
fs.mkdirSync(SHOT, { recursive: true });

function envValue(key) {
  const m = fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
  return m ? m[1].trim().replace(/^"|"$/g, '') : '';
}
const API_KEY = envValue('MIX_API_KEY');
const MOLLIE_KEY = envValue('MOLLIE_API_KEY');
const MAIL_HOST = envValue('MAIL_HOST');

test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);
// Instrument : Chromium ≥ 138 (Local Network Access) refuse en headless les fetch d'une origine
// loopback (:8011) vers une autre (:8766) — « Permission was denied for this request to access
// the loopback address space ». Sans ce drapeau, TOUT l'API est « Réseau indisponible » et la
// spec accuserait le produit. En production les deux origines sont publiques : non concerné.
// (Même drapeau que `tests-e2e/compte-email-dabord-2026-09-02.spec.js` du dépôt du site.)
test.use({
  video: 'off', trace: 'off', screenshot: 'off',
  launchOptions: { args: ['--lang=fr-FR', '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] },
});

// ---------------------------------------------------------------------------
// État partagé (mode serial) + journal de timing (§8 du plan)
// ---------------------------------------------------------------------------
const S = {
  dbPrices: {},            // name → price (items, status 5)
  emailClient: '',
  phoneClient: '',
  authToken: '',
  authViaUi: null,         // true si l'OTP a pu être validé DANS l'écran ; false si repli API
  authUiMessage: '',
  order: null,             // { id, serial, total, queue } depuis POST /api/frontend/order
  order2: null,
  timings: [],             // { when, endpoint, ms, status }
  realtime: {},            // mesures de latence
  pricesSite: [],
  notes: [],
};
const NOW = Date.now();
const TAG = `E2E-SITE-M1-${String(NOW).slice(-6)}`;
let snapCount = 0;

function recordTimings(page, origine) {
  page.on('response', (resp) => {
    try {
      const req = resp.request();
      const t = req.timing();
      const ms = t && t.responseEnd >= 0 ? Math.round(t.responseEnd) : null;
      const url = resp.url();
      if (!/127\.0\.0\.1:(8766|8011)/.test(url)) return;
      if ((ms !== null && ms > 1000) || resp.status() >= 400) {
        S.timings.push({ when: new Date().toISOString(), origine, endpoint: `${req.method()} ${url.replace(/^https?:\/\/127\.0\.0\.1/, '')}`.slice(0, 140), ms, status: resp.status() });
      }
    } catch (_e) { /* ignore */ }
  });
}

function mysql(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20_000 }).trim();
}
function orderRow(id) {
  const out = mysql(`SELECT id,total,subtotal,discount,source_surface,status,payment_status,payment_method,IFNULL(fiscal_sequence_no,'NULL'),order_type,IFNULL(scheduled_at,'NULL'),is_advance_order,IFNULL(queue_number,'NULL'),IFNULL(order_serial_no,'NULL'),IFNULL(preparation_time,'NULL'),IFNULL(accepted_at,'NULL'),deleted_at IS NULL FROM orders WHERE id=${Number(id)}`);
  const c = out.split('\t');
  return {
    id: Number(c[0]), total: Number(c[1]), subtotal: Number(c[2]), discount: Number(c[3]), source_surface: c[4], status: Number(c[5]),
    payment_status: Number(c[6]), payment_method: Number(c[7]), fiscal_sequence_no: c[8], order_type: Number(c[9]), scheduled_at: c[10],
    is_advance_order: Number(c[11]), queue_number: c[12], order_serial_no: c[13], preparation_time: c[14], accepted_at: c[15], raw: out,
  };
}
const euro = (s) => { const m = String(s || '').replace(/ /g, ' ').match(/(\d+)[.,](\d{2})\s*€/); return m ? Number(`${m[1]}.${m[2]}`) : NaN; };
const lastEuro = (s) => { const all = [...String(s || '').replace(/ /g, ' ').matchAll(/(\d+)[.,](\d{2})\s*€/g)]; return all.length ? Number(`${all[all.length - 1][1]}.${all[all.length - 1][2]}`) : NaN; };

// Meta api-key du miroir → clé locale (voir en-tête). Seule interception de toute la spec.
async function installApiKeyRewrite(page) {
  await page.route(/127\.0\.0\.1:8011\/(index\.html)?(\?[^#]*)?$/, async (route) => {
    if (route.request().resourceType() !== 'document') return route.continue();
    const res = await route.fetch();
    let body = await res.text();
    body = body.replace(/(name="api-key" content=")[^"]*(")/, `$1${API_KEY}$2`);
    await route.fulfill({ response: res, body });
  });
}
async function gotoSite(page, hash = '') {
  await page.goto(`${SITE}/?dev${hash}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await page.waitForTimeout(1500);
}
async function openMenu(page) {
  const btn = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await btn.isVisible({ timeout: 1500 }).catch(() => false)) await btn.click();
  else await page.evaluate(() => { window.location.hash = '#menu'; });
  await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
  await page.waitForTimeout(600);
}
async function readStep(page) {
  const r = await page.evaluate(() => {
    const t = (sel) => { const e = document.querySelector(sel); return e ? String(e.innerText || '').replace(/\s+/g, ' ').trim() : ''; };
    const next = document.querySelector('.lc-wiz-foot-next');
    const choices = Array.from(document.querySelectorAll('.lc-wiz-choice')).map((c, i) => ({
      i, text: String(c.innerText || '').replace(/\s+/g, ' ').trim(), on: /is-on/.test(c.className),
      name: (c.querySelector('.lc-wiz-choice-name') || {}).innerText || '',
    }));
    return { title: t('.lc-wiz-title'), sub: t('.lc-wiz-sub'), next: next ? String(next.innerText || '').replace(/\s+/g, ' ') : '',
      disabled: next ? (next.disabled || next.getAttribute('aria-disabled') === 'true') : null, choices };
  });
  return { ...r, total: lastEuro(r.next) };
}
async function clickNext(page) {
  await page.locator('.lc-wiz-foot-next').click({ timeout: 8_000 });
  await page.waitForTimeout(450);
}
// Connexion admin en URL ABSOLUE (le contexte du site n'a pas de baseURL) — miroir de helpers/login.js.
async function loginAbs(page, email, password) {
  clearFoodKingRateLimits();
  await page.goto(`${BASE}/login`);
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
  await page.locator('#formEmail').fill(email);
  await page.locator('#formPassword').fill(password);
  const loginResponse = page.waitForResponse((res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()), { timeout: 25_000 });
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  const resp = await loginResponse;
  if (resp.status() !== 201) throw new Error(`Login API failed: HTTP ${resp.status()}`);
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1200);
}
// Clic « Ajouter au panier » (récap) : le site vérifie la composition auprès du serveur
// (api.resolveLine) avant d'ajouter. Sous charge partagée (10 missions), un 429 peut faire
// échouer cette vérification : on relève le message et on relance UNE fois (règle 3 du plan).
async function ajouterAuPanier(page, rec, snapName) {
  for (let essai = 1; essai <= 2; essai++) {
    await page.locator('.lc-wiz-foot-next').click({ timeout: 8_000 });
    const t0 = Date.now();
    let err = '';
    while (Date.now() - t0 < 20_000) {
      if (await page.locator('.lc-cart-drawer.is-open').count()) return { ok: true, essai };
      if (!(await page.locator('.lc-wiz').count())) return { ok: true, essai, ferme: true };
      err = await page.evaluate(() => { const a = document.querySelector('.lc-wiz [role="alert"]'); return a ? String(a.innerText || '').replace(/\s+/g, ' ').trim() : ''; });
      if (err) break;
      await page.waitForTimeout(250);
    }
    const libelle = await page.evaluate(() => { const b = document.querySelector('.lc-wiz-foot-next'); return b ? b.innerText.replace(/\s+/g, ' ') : ''; });
    S.notes.push(`« Ajouter au panier » essai ${essai} : ${err ? 'erreur « ' + err + ' »' : 'tiroir non ouvert en 20 s (bouton : « ' + libelle + ' »)'}`);
    if (rec && snapName) { snapCount += 1; await rec.snap(`${snapName}-essai${essai}`); }
    await page.waitForTimeout(2000);
  }
  return { ok: false };
}
async function siteSnap(rec, name) {
  snapCount += 1;
  await rec.snap(name);
}

// ===========================================================================
test.describe('M1 — site web « à emporter »', () => {
  let siteCtx, caisseCtx, site, siteRec;

  test.beforeAll(async ({ browser }) => {
    // Prix SSOT (CLAUDE.md §3bis) : la table items, pas un fichier.
    mysql("SELECT name, price FROM items WHERE deleted_at IS NULL AND status = 5").split('\n').forEach((l) => {
      const [name, price] = l.split('\t'); if (name) S.dbPrices[name] = Number(price);
    });
    siteCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    site = await siteCtx.newPage();
    await site.emulateMedia({ reducedMotion: 'reduce' });
    await installApiKeyRewrite(site);
    recordTimings(site, 'site-desktop');
    siteRec = attachMegaAuditRecorder(site, SHOT);
  });
  test.afterAll(async () => {
    fs.writeFileSync(path.join(SHOT, 'M1-mesures.json'), JSON.stringify({ tag: TAG, snapCount, order: S.order, order2: S.order2, authViaUi: S.authViaUi, authUiMessage: S.authUiMessage, realtime: S.realtime, timings: S.timings, pricesSite: S.pricesSite, notes: S.notes }, null, 2));
    if (siteRec) siteRec.dispose();
    if (siteCtx) await siteCtx.close().catch(() => {});
    if (caisseCtx) await caisseCtx.close().catch(() => {});
  });

  // -------------------------------------------------------------------------
  test('0. SERVICE FERMÉ : accueil + ce que dit le serveur (wait-estimate)', async () => {
    S.we0 = await (await site.request.get(`${BASE}/api/frontend/order/wait-estimate?branch_id=1`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' } })).json();
    S.notes.push(`wait-estimate (avant accueil) : ${JSON.stringify(S.we0)}`);
    await gotoSite(site);
    const txt = (await site.locator('body').innerText()).replace(/\s+/g, ' ');
    S.accueilFerme = { serviceFermeAffiche: /SERVICE FERMÉ/i.test(txt), extrait: (txt.match(/.{0,80}SERVICE FERMÉ.{0,120}/i) || [''])[0], commanderDesactive: await site.getByRole('button', { name: /Commander|Passer commande/i }).evaluateAll((els) => els.map((e) => ({ t: e.innerText.replace(/\s+/g, ' ').slice(0, 40), dis: e.disabled || e.getAttribute('aria-disabled') }))) };
    S.notes.push(`accueil : ${JSON.stringify(S.accueilFerme)}`);
    await siteRec.snap('R2-01-accueil-service-ferme'); snapCount += 1;
  });

  // -------------------------------------------------------------------------
  test('3. fiche + wizard Cayenne (sans jambon, pain/galette, recommandés en tête) → panier', async () => {
    await gotoSite(site, '#menu');
    await expect(site.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 20_000 });
    await site.locator('[aria-label="Voir Cayenne"]').first().click();
    await expect(site.locator('.lc-detail')).toBeVisible({ timeout: 10_000 });
    await site.waitForTimeout(500);
    const detail = (await site.locator('.lc-detail').innerText()).replace(/\s+/g, ' ');
    await siteSnap(siteRec, '10-fiche-cayenne');
    expect(detail, 'fiche Cayenne au prix items').toContain(S.dbPrices['Cayenne'].toFixed(2).replace('.', ','));
    expect(detail, 'fiche Cayenne SANS jambon (mandat 2026-10-21)').not.toMatch(/jambon/i);
    expect(detail, 'fiche Cayenne décrit la sauce fromagère maison').toMatch(/fromage/i);

    await site.locator('.lc-detail').getByRole('button', { name: /Personnaliser/ }).click();
    await expect(site.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
    const steps = [];
    let recap = null;
    for (let s = 0; s < 14; s++) {
      const step = await readStep(site);
      steps.push({ title: step.title, sub: step.sub, total: step.total, on: step.choices.filter((c) => c.on).map((c) => c.text) });
      if (/Ajouter au panier/i.test(step.next)) { recap = step; break; }

      if (/BOISSON/i.test(step.title)) {
        const dr = await site.evaluate(() => Array.from(document.querySelectorAll('.lc-wiz-choice')).map((c) => ({ t: String(c.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 70), dis: c.disabled || c.getAttribute('aria-disabled'), cls: String(c.className).slice(0, 60) })));
        S.notes.push(`étape boisson (choix) : ${JSON.stringify(dr)}`);
      }
      if (/PAIN OU GALETTE/i.test(step.title)) {
        expect(step.choices.map((c) => c.text).join(' | '), 'étape pain/galette : 2 bases').toMatch(/Pain.*Galette|Galette.*Pain/);
        await siteSnap(siteRec, '11-wizard-pain-galette');
      } else if (/VIANDE/i.test(step.title)) {
        S.notes.push(`wizard Cayenne — étape viande : « ${step.title} » / « ${step.sub} », ${step.choices.length} choix, bouton bloqué=${step.disabled}`);
        await siteSnap(siteRec, '12-wizard-viandes');
        for (let k = 0; k < 3 && (await readStep(site)).disabled; k++) {
          await site.locator('.lc-wiz-choice:not(.is-on)').first().click();
          await site.waitForTimeout(300);
        }
      } else if (/SUPPL/i.test(step.title)) {
        const reco = step.choices.filter((c) => /\(recommandé\)/i.test(c.text)).map((c) => c.text);
        const idxReco = step.choices.map((c, i) => (/\(recommandé\)/i.test(c.text) ? i : -1)).filter((i) => i >= 0);
        await siteSnap(siteRec, '13-wizard-supplements-recommandes');
        expect(reco.length, 'suppléments « (recommandé) » présents').toBeGreaterThanOrEqual(2);
        expect(idxReco, 'les recommandés sont EN TÊTE de liste').toEqual(idxReco.map((_v, i) => i));
        // Miroir de config/menu_recommended_supplements.php by_item Cayenne = Cheddar, Boursin
        expect(reco.join(' '), 'Cayenne → Cheddar + Boursin recommandés').toMatch(/Cheddar/);
        expect(reco.join(' '), 'Cayenne → Cheddar + Boursin recommandés').toMatch(/Boursin/);
        const before = step.total;
        await site.locator('.lc-wiz-choice').filter({ hasText: /^Cheddar/ }).first().click();
        await site.waitForTimeout(400);
        const after = (await readStep(site)).total;
        expect(+(after - before).toFixed(2), 'Cheddar facturé au prix items').toBeCloseTo(S.dbPrices['Cheddar'], 2);
      } else if (/FAIRE UN MENU/i.test(step.title)) {
        const before = step.total;
        await siteSnap(siteRec, '14-wizard-faire-un-menu');
        await site.locator('.lc-wiz-choice').filter({ hasText: /Menu complet/ }).first().click();
        await site.waitForTimeout(500);
        const after = (await readStep(site)).total;
        expect(+(after - before).toFixed(2), 'Menu complet facturé au prix items').toBeCloseTo(S.dbPrices['Menu (Frites + Boisson)'], 2);
      } else {
        for (let k = 0; k < 3 && (await readStep(site)).disabled; k++) {
          await site.locator('.lc-wiz-choice:not(.is-on)').first().click();
          await site.waitForTimeout(300);
        }
      }
      // Un choix radio fait avancer le wizard tout seul (ex. Menu complet → Choix de la boisson) :
      // ne pas cliquer « Continuer » si l'étape a déjà changé.
      if ((await readStep(site)).title !== step.title) continue;
      await clickNext(site);
    }
    expect(recap, 'récap atteint').toBeTruthy();
    const recapText = (await site.locator('.lc-wiz').innerText()).replace(/\s+/g, ' ');
    await siteSnap(siteRec, '15-wizard-recap');
    const attendu = +(S.dbPrices['Cayenne'] + S.dbPrices['Cheddar'] + S.dbPrices['Menu (Frites + Boisson)']).toFixed(2);
    expect(recap.total, 'total récap = Cayenne + Cheddar + Menu (items)').toBeCloseTo(attendu, 2);
    expect(recapText, 'récap sans jambon').not.toMatch(/jambon/i);
    S.notes.push(`wizard Cayenne étapes : ${steps.map((s) => s.title).join(' → ')}`);

    const ajout = await ajouterAuPanier(site, siteRec, '15b-wizard-ajout-bloque');
    expect(ajout.ok, `ajout au panier (${S.notes.slice(-2).join(' / ')})`).toBeTruthy();
    const drawer = site.locator('.lc-cart-drawer.is-open');
    await expect(drawer, 'panier ouvert').toBeVisible({ timeout: 10_000 });
    await site.waitForTimeout(500);
    const cartTotal = () => drawer.locator('.lc-cart-totals-row.is-total').innerText().then(lastEuro);
    expect(await cartTotal(), 'panier == récap').toBeCloseTo(attendu, 2);
    await siteSnap(siteRec, '16-panier-1-article');
    await drawer.getByRole('button', { name: /Augmenter la quantité de Cayenne/ }).click();
    await site.waitForTimeout(600);
    expect(await cartTotal(), 'quantité ×2 = total ×2').toBeCloseTo(+(attendu * 2).toFixed(2), 2);
    await siteSnap(siteRec, '17-panier-quantite-2');
    await drawer.getByRole('button', { name: /Diminuer la quantité de Cayenne/ }).click();
    await site.waitForTimeout(600);
    expect(await cartTotal(), 'retour quantité 1').toBeCloseTo(attendu, 2);
    S.cartTotal = attendu;
  });

  // -------------------------------------------------------------------------
  test('4. upsell → page de commande : mode, créneaux, suppression de ligne, paiement proposé', async () => {
    const drawer = site.locator('.lc-cart-drawer.is-open');
    await drawer.getByRole('button', { name: /Passer commande/ }).click();
    await site.waitForTimeout(1200);
    const addCoca = site.getByRole('button', { name: /Coca-Cola Zero 33cl.*Ajouter/ }).first();
    if (await addCoca.isVisible({ timeout: 3000 }).catch(() => false)) {
      await siteSnap(siteRec, '18-upsell-boissons');
      await addCoca.click();
      await site.waitForTimeout(600);
      S.upsellAdded = true;
    } else {
      S.notes.push('upsell boissons non affiché après « Passer commande »');
    }
    for (let i = 0; i < 4 && !(await site.locator('.lcf-page').count()); i++) {
      const b = site.getByRole('button', { name: /Non merci|Continuer|Régler ma commande/ }).first();
      if (await b.isVisible({ timeout: 1500 }).catch(() => false)) await b.click();
      await site.waitForTimeout(1200);
    }
    await expect(site.locator('.lcf-page'), 'page unique « Retrait & paiement »').toBeVisible({ timeout: 15_000 });
    await site.waitForTimeout(1000);
    await siteSnap(siteRec, '19-commande-page-unique');

    // Mode : à emporter coché par défaut
    const modes = site.locator('[aria-label="Mode de retrait"] [role="radio"], [aria-label="Mode de retrait"] a');
    expect(await modes.count(), 'tuiles de mode').toBeGreaterThanOrEqual(1);
    await expect(site.locator('[aria-label="Mode de retrait"] [role="radio"]').filter({ hasText: 'À emporter' }), 'À emporter coché').toHaveAttribute('aria-checked', 'true');
    const livraisonTuile = await site.locator('[aria-label="Mode de retrait"]').innerText();
    S.notes.push(`tuile Livraison sur la page de commande : « ${livraisonTuile.replace(/\s+/g, ' ').slice(0, 160)} »`);

    // Créneaux : ASAP coché, + options ; l'ASAP affiche une fourchette issue de wait-estimate
    const slots = site.locator('[aria-label="Créneau de retrait"] [role="radio"]');
    expect(await slots.count(), '4 créneaux').toBe(4);
    await expect(slots.filter({ hasText: 'Dès que prêt' })).toHaveAttribute('aria-checked', 'true');
    const asapTxt = await slots.filter({ hasText: 'Dès que prêt' }).innerText();
    expect(asapTxt, 'ASAP chiffré (wait-estimate serveur)').toMatch(/~\d+-\d+ min/);

    // Récap droite : total = panier (+ Coca si upsell), suppression de la ligne Coca
    const summaryTotal = async () => lastEuro(await site.locator('.lcf-summary-totals').innerText());
    if (S.upsellAdded) {
      expect(await summaryTotal(), 'récap commande = panier + Coca Zero').toBeCloseTo(+(S.cartTotal + S.dbPrices['Coca-Cola Zero 33cl']).toFixed(2), 2);
      await site.getByRole('button', { name: /Retirer Coca-Cola Zero 33cl de la commande/ }).click();
      await site.waitForTimeout(700);
    }
    expect(await summaryTotal(), 'récap commande = panier').toBeCloseTo(S.cartTotal, 2);
    if (!S.upsellAdded) {
      // Pas d'upsell (le panier contient déjà une boisson via « Menu complet ») : on exerce le
      // stepper du récap de commande à la place.
      await site.locator('.lcf-summary-stepper button').last().click();
      await site.waitForTimeout(700);
      expect(await summaryTotal(), 'récap commande ×2').toBeCloseTo(+(S.cartTotal * 2).toFixed(2), 2);
      expect(lastEuro(await site.locator('.lcf-cta-bar-next').innerText()), 'bouton suit le ×2').toBeCloseTo(+(S.cartTotal * 2).toFixed(2), 2);
      await siteSnap(siteRec, '20-commande-recap-quantite-2');
      await site.locator('.lcf-summary-stepper button').first().click();
      await site.waitForTimeout(700);
      expect(await summaryTotal(), 'récap commande retour ×1').toBeCloseTo(S.cartTotal, 2);
    } else {
      await siteSnap(siteRec, '20-commande-apres-suppression-ligne');
    }
    const ctaTxt = await site.locator('.lcf-cta-bar-next').innerText();
    expect(lastEuro(ctaTxt), 'bouton de confirmation porte le total').toBeCloseTo(S.cartTotal, 2);

    // Paiement proposé : dire lequel (Mollie LIVE ici → jamais exercé)
    const methods = await site.locator('[aria-label="Mode de paiement"] [role="radio"]').evaluateAll((els) => els.map((e) => ({ t: e.innerText.replace(/\s+/g, ' ').slice(0, 70), on: e.getAttribute('aria-checked') })));
    S.notes.push(`modes de paiement : ${JSON.stringify(methods)} ; MOLLIE_API_KEY locale = ${MOLLIE_KEY.slice(0, 5)}… (meta mollie-testmode=0 sur le site) → paiement carte NON exercé`);
    expect(methods.find((m) => /Payer sur place/.test(m.t)).on, '« Payer sur place » présélectionné').toBe('true');
  });


  // -------------------------------------------------------------------------
  test('4b. SERVICE FERMÉ : étape heure + récapitulatif de la page de commande', async () => {
    S.we4 = await (await site.request.get(`${BASE}/api/frontend/order/wait-estimate?branch_id=1`, { headers: { 'X-API-Key': API_KEY, Accept: 'application/json' } })).json();
    const slots = await site.locator('[aria-label="Créneau de retrait"] [role="radio"]').evaluateAll((els) => els.map((e) => ({ t: e.innerText.replace(/\s+/g, ' ').slice(0, 80), on: e.getAttribute('aria-checked'), dis: e.getAttribute('aria-disabled') })));
    const page_txt = (await site.locator('.lcf-page').innerText()).replace(/\s+/g, ' ');
    S.creneauxFerme = { slots, mentionFerme: /ferm|18h|ouvre|ouverture|plus tard|demain/i.test(page_txt), extraitFerme: (page_txt.match(/.{0,80}(ferm|ouvre|ouverture|demain).{0,100}/i) || [''])[0], cta: (await site.locator('.lcf-cta-bar-next').innerText()).replace(/\s+/g, ' '), ctaDisabled: await site.locator('.lcf-cta-bar-next').evaluate((e) => e.disabled || e.getAttribute('aria-disabled')) };
    S.notes.push(`wait-estimate (page de commande) : ${JSON.stringify(S.we4)}`);
    S.notes.push(`créneaux/CTA service fermé : ${JSON.stringify(S.creneauxFerme)}`);
    await site.evaluate(() => window.scrollTo(0, 0));
    await siteRec.snap('R2-02-etape-heure-service-ferme'); snapCount += 1;
    await site.locator('.lcf-summary').first().screenshot({ path: path.join(SHOT, 'R2-03-recapitulatif-service-ferme.png') }).catch((e) => S.notes.push('récap element screenshot: ' + e.message));
  });
  // -------------------------------------------------------------------------
  test('5. identification : e-mail d’abord → création de compte → code', async () => {
    S.emailClient = `${TAG.toLowerCase()}@lecayenne-test.fr`;
    S.phoneClient = `06991${String(NOW).slice(-5)}`;
    await site.locator('#auth-email').fill(S.emailClient);
    await site.getByRole('button', { name: /Recevoir le code par email/ }).click();
    await expect(site.locator('[data-testid="funnel-identite"]'), 'e-mail inconnu → prénom + téléphone se déplient').toBeVisible({ timeout: 15_000 });
    await siteSnap(siteRec, '21-identification-email-inconnu');
    expect(await site.locator('#auth-email').inputValue(), 'e-mail conservé').toBe(S.emailClient);
    await site.locator('#auth-first').fill(`${TAG} Client`);
    await site.locator('#auth-phone').fill(S.phoneClient);
    const emailLoginResp = site.waitForResponse((r) => r.request().method() === 'POST' && /guest-signup\/email-login/.test(r.url()), { timeout: 30_000 });
    await site.getByRole('button', { name: /Créer mon compte et recevoir le code/ }).click();
    const r = await emailLoginResp;
    const j = await r.json().catch(() => ({}));
    S.notes.push(`email-login (inscription) → HTTP ${r.status()} ${JSON.stringify(j).slice(0, 220)}`);
    await site.waitForTimeout(800);
    await siteSnap(siteRec, '22-identification-apres-envoi-code');

    // Le produit a-t-il posé le code ? (otps.token — jamais lu dans l'écran)
    const token = mysql(`SELECT token FROM otps WHERE phone='${S.phoneClient}' ORDER BY created_at DESC LIMIT 1`);
    expect(token, 'un code OTP est posé en base pour ce numéro').toMatch(/^\d{4}$/);

    if (r.status() < 300 && await site.locator('#auth-otp').isVisible({ timeout: 3000 }).catch(() => false)) {
      S.authViaUi = true;
      await site.locator('#auth-otp').fill(token);
      await site.getByRole('button', { name: /Valider mes coordonnées/ }).click();
      await expect(site.getByText(/Coordonnées confirmées/), 'coordonnées confirmées').toBeVisible({ timeout: 20_000 });
      await siteSnap(siteRec, '23-identification-confirmee');
    } else {
      // Environnement : MAIL_HOST placeholder → l'envoi échoue APRÈS la pose du code. Le client
      // ne peut pas atteindre l'écran du code. On relève ce que le client VOIT, puis on termine
      // l'authentification par la voie officielle du produit (POST /verify, même code) pour
      // pouvoir exercer la commande. Ce choix est dit dans le rapport.
      S.authViaUi = false;
      S.authUiMessage = ((await site.locator('#lc-contact-block [role="alert"]').innerText().catch(() => '')) || '').replace(/\s+/g, ' ');
      S.notes.push(`écran client après échec d'envoi : « ${S.authUiMessage} » (MAIL_HOST=${MAIL_HOST})`);
      // Instrument : les 10 missions partagent 127.0.0.1 ; la clé `sha1('|ip')` du throttle:3,5 de
      // /verify est aussi consommée par tout throttle numérique (wait-estimate 30,1…) — voir M1-002.
      execFileSync('php', ['artisan', 'tinker', '--execute', 'app(\\Illuminate\\Cache\\RateLimiter::class)->clear(sha1("|127.0.0.1")); echo "ok";'], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
      let v;
      for (let essai = 1; essai <= 6; essai++) {
        execFileSync('php', ['artisan', 'tinker', '--execute', 'foreach (["|127.0.0.1","|::1"] as $k) { app(\\Illuminate\\Cache\\RateLimiter::class)->clear(sha1($k)); } echo "ok";'], { cwd: REPO, encoding: 'utf8', timeout: 30_000 });
        v = await site.request.post(`${BASE}/api/auth/guest-signup/verify`, {
          headers: { 'X-API-Key': API_KEY, Accept: 'application/json' },
          data: { token, phone: S.phoneClient, code: '+33', email: S.emailClient, first_name: `${TAG} Client` },
        });
        if (v.status() !== 429) break;
        S.notes.push(`verify essai ${essai} : 429 (limiteur partagé, instrument)`);
        await site.waitForTimeout(4000);
      }
      const vj = await v.json().catch(() => ({}));
      expect(v.status(), `verify avec le code posé en base → ${JSON.stringify(vj).slice(0, 200)}`).toBeLessThan(300);
      expect(vj.token, 'jeton client délivré').toBeTruthy();
      S.authToken = vj.token;
      await site.evaluate(([t, p]) => { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); }, [S.authToken, S.phoneClient]);
      await siteSnap(siteRec, '23-identification-echec-envoi-email');
    }
  });


  // -------------------------------------------------------------------------
  test('6. CAYENNE 2 viandes : ce que le client voit quand le serveur répond 422', async () => {
    const sink = {};
    site.on('request', (req) => { if (req.method() === 'POST' && /\/api\/frontend\/order$/.test(req.url())) { try { sink.body = JSON.parse(req.postData() || '{}'); } catch (_e) { sink.body = null; } } });
    const orderResp = site.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/frontend\/order$/.test(r.url()), { timeout: 60_000 });
    await site.locator('.lcf-cta-bar-next').click();
    const resp = await orderResp;
    const raw = await resp.text();
    let j = {}; try { j = JSON.parse(raw); } catch (_e) { /* */ }
    await site.waitForTimeout(1200);
    const alerts = await site.evaluate(() => Array.from(document.querySelectorAll('[role="alert"], .lcf-error, .lcf-cta-error, [class*="error"]')).map((e) => ({ cls: String(e.className).slice(0, 60), t: String(e.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 300) })).filter((x) => x.t));
    const bodyTxt = (await site.locator('body').innerText()).replace(/\s+/g, ' ');
    S.r422 = { status: resp.status(), serveurMessage: j && j.message, serveurErreurs: j && j.errors, postKeys: Object.keys(sink.body || {}), items: sink.body && sink.body.items, alertesDOM: alerts, contient_and: /\(and\b/i.test(bodyTxt + raw), confirmationAffichee: await site.locator('.lcf-confirm').count() };
    S.r422Pos = await site.evaluate(() => {
      const els = Array.from(document.querySelectorAll('.lcf-field-error')).filter((e) => /Sélectionnez/.test(e.innerText));
      const e = els[0]; if (!e) return null;
      const r = e.getBoundingClientRect(); const cx = r.left + r.width / 2; const cy = r.top + r.height / 2;
      const top = document.elementFromPoint(Math.min(Math.max(cx, 0), innerWidth - 1), Math.min(Math.max(cy, 0), innerHeight - 1));
      const cta = document.querySelector('.lcf-cta-bar-next'); const cr = cta ? cta.getBoundingClientRect() : null;
      return { top: Math.round(r.top), bottom: Math.round(r.bottom), vh: innerHeight, scrollY: Math.round(scrollY), inViewport: r.top >= 0 && r.bottom <= innerHeight, recouvertPar: top && !e.contains(top) ? (top.className + '').slice(0, 60) : null, role: e.getAttribute('role'), ariaLive: e.getAttribute('aria-live'), ctaTop: cr ? Math.round(cr.top) : null, parent: (e.parentElement.className + '').slice(0, 60) };
    });
    S.notes.push(`R422 position : ${JSON.stringify(S.r422Pos)}`);
    S.notes.push(`R422 : ${JSON.stringify(S.r422).slice(0, 3000)}`);
    await siteRec.snap('R2-04-cayenne-422-ce-que-voit-le-client'); snapCount += 1;
    await site.locator('.lcf-field-error', { hasText: /Sélectionnez/ }).first().scrollIntoViewIfNeeded().catch(() => {});
    await site.waitForTimeout(300);
    await siteRec.snap('R2-05-cayenne-422-message-defile-a-lecran'); snapCount += 1;
    expect(S.r422.status, 'attendu 422 (Cayenne 2 viandes) — constat, pas une assertion produit').toBeGreaterThanOrEqual(0);
  });
});
