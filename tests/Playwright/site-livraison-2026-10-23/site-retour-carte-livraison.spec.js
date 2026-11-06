// [Relecture adverse 2026-10-23 · P1] Une LIVRAISON payée par carte qui quitte la page (3-D Secure, portefeuille) revient par
// `?order=<id>` : la confirmation relisait `lc.mollie.pending`, qui ne portait pas le MODE de la commande, et affichait un ticket
// de RETRAIT (« Présente ce QR à la caisse »). Ce banc prouve les deux moitiés du correctif :
//  (1) le tunnel ÉCRIT le mode et les champs livraison dans le pending (lu dans le compilé servi) ;
//  (2) au retour, la confirmation d'une livraison payée dit « livrée à l'adresse indiquée », sans QR ; un pending ANCIEN (sans
//      mode) retombe sur le retrait, comme avant — c'est le témoin qui prouve que le test mord.
// La réponse « commande payée » est SYNTHÉTIQUE (route interceptée) : aucune commande créée, aucun appel vers la production.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/site-livraison-2026-10-23/site-retour-carte-livraison.spec.js --workers=1 --reporter=line
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '..', '..', '..');
const SITE = process.env.SITE_URL || 'http://127.0.0.1:8013';
const BASE = (process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const API_KEY = (fs.readFileSync(path.join(REPO, '.env'), 'utf8').match(/^MIX_API_KEY=(.*)$/m) || [])[1].trim().replace(/^"|"$/g, '');
const PROD_RX = /vps-418872ac|ovh\.net|(^|\.)lecayenne\.fr/i;
const NOW = Date.now();
const OID = 990000000 + (NOW % 1000000); // identifiant hors base : la réponse est interceptée, rien n'est créé

function mysql(sql) { return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], { encoding: 'utf8', timeout: 20000 }).trim(); }
function resetLimiteurs() {
  execFileSync('php', ['artisan', 'tinker', '--execute', `$l = app(\\Illuminate\\Cache\\RateLimiter::class); $l->clear(sha1("|127.0.0.1")); $l->clear("otp-verify".sha1("|127.0.0.1")); $l->clear("signup-register".sha1("|127.0.0.1")); foreach (['delivery-suggest','delivery-quote','delivery-status','delivery-waves'] as $n) { foreach (['127.0.0.1','::1'] as $ip) { $l->clear(md5($n.$ip)); } } echo 'ok';`], { cwd: REPO, encoding: 'utf8', timeout: 30000 });
}
/** Compte invité réel (le site exige un jeton pour relire une commande) — code OTP lu en base, comme les autres bancs du dossier. */
async function compteInvite(rq) {
  resetLimiteurs();
  const phone = `0696${String(NOW).slice(-6)}`;
  const email = `e2e-retour-${String(NOW).slice(-6)}@lecayenne-test.fr`;
  const h = { 'X-API-Key': API_KEY, Accept: 'application/json' };
  await rq.post(`${BASE}/api/auth/guest-signup/email-login`, { headers: h, data: { email, first_name: 'Retour', phone, code: '+33' } });
  const token = mysql(`SELECT token FROM otps WHERE phone='${phone}' ORDER BY created_at DESC LIMIT 1`);
  expect(token, 'code OTP en base').toMatch(/^\d{4}$/);
  resetLimiteurs();
  const v = await rq.post(`${BASE}/api/auth/guest-signup/verify`, { headers: h, data: { token, phone, code: '+33', email, first_name: 'Retour' } });
  const vj = await v.json().catch(() => ({}));
  expect(v.status(), JSON.stringify(vj).slice(0, 200)).toBeLessThan(300);
  return { token: vj.token, phone };
}
const pendingLivraison = {
  orderDbId: OID, orderId: String(OID), orderQueue: 'A0999', orderTotal: 11.8, earnedPoints: 0, cartLines: [],
  orderFulfillment: 'delivery', orderAddress: '12 Rue Elie Gruyelle 62110 Hénin-Beaumont', orderDeliveryFee: 3.9, orderPayMethod: 4,
  orderPrevuePour: '23:30', orderPrevueLe: null,
};
const commandePayee = { data: { id: OID, order_serial_no: String(OID), queue_number: 'A0999', status: 7, payment_status: 5, payment_method: 4, order_type: 5, total: '11.80', subtotal: '7.90', delivery_charge: '3.90', items: [] } };

async function retourBanque(browser, auth, pending) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  const bloquees = [];
  await ctx.route((u) => PROD_RX.test(u.href), (route) => { bloquees.push(route.request().url()); return route.abort('blockedbyclient'); });
  await ctx.route((u) => new RegExp(`/api/frontend/order/show/${OID}$`).test(u.href), (route) => route.fulfill({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(commandePayee) }));
  await ctx.addInitScript(([t, p, pend]) => {
    try { localStorage.setItem('lecayenne.authToken', t); localStorage.setItem('lecayenne.authPhone', p); sessionStorage.setItem('lc.mollie.pending', JSON.stringify(pend)); } catch (_) {}
  }, [auth.token, auth.phone, pending]);
  const page = await ctx.newPage();
  const erreurs = []; page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 200)));
  await page.goto(`${SITE}/?order=${OID}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('.lcf-confirm-sub')).toContainText(/Paiement confirmé/, { timeout: 30000 });
  await page.waitForTimeout(800);
  // Texte du TUNNEL seulement (fil d'étapes + confirmation) : le pied de page du site dit « retrait sur place » partout (D4, hors lot).
  const texte = await page.evaluate(() => String((document.querySelector('.lcf-page') || document.body).innerText || '').replace(/[  ]/g, ' ').replace(/\s+/g, ' '));
  const qr = await page.locator('.lcf-ticket-qr-real, .lcf-ticket-qr-mock').count();
  return { page, ctx, texte, qr, erreurs, bloquees };
}

test.describe('Site — retour bancaire (3-D Secure / portefeuille) d\'une livraison payée', () => {
  test.setTimeout(180000);
  let auth;
  test.beforeAll(async ({ request }) => { auth = await compteInvite(request); });

  test('(1) le tunnel emporte le mode de la commande dans lc.mollie.pending (compilé servi)', async ({ request }) => {
    const idx = await (await request.get(`${SITE}/index.html`)).text();
    const src = (idx.match(/compiled\/funnel\.js\?v=[^"']+/) || [])[0];
    expect(src, 'funnel.js versionné dans index.html').toBeTruthy();
    const js = await (await request.get(`${SITE}/${src}`)).text();
    // Plusieurs mentions de la clé (dont un commentaire) : on cherche l'ÉCRITURE, celle dont la fenêtre porte tous les champs.
    const champs = ['orderFulfillment', 'orderAddress', 'orderDeliveryFee', 'orderPayMethod', 'orderPrevuePour'];
    const fenetres = Array.from(js.matchAll(/lc\.mollie\.pending['"]/g)).map((m) => js.slice(m.index, m.index + 1600));
    expect(fenetres.length, 'mention du pending dans le compilé').toBeGreaterThan(0);
    const ecriture = fenetres.find((w) => champs.every((c) => w.includes(c)));
    expect(ecriture, `une écriture du pending emporte ${champs.join(', ')}`).toBeTruthy();
  });

  test('(2) au retour, une livraison payée est confirmée comme LIVRAISON : adresse, sans QR ni « Présente ce QR »', async ({ browser }) => {
    const r = await retourBanque(browser, auth, pendingLivraison);
    try {
      expect(r.bloquees, 'aucune requête vers la production').toEqual([]);
      expect(r.erreurs, 'aucune erreur de page').toEqual([]);
      expect(r.texte).toMatch(/livrée à l'adresse indiquée/i);
      expect(r.texte).toContain('12 Rue Elie Gruyelle');
      expect(r.texte).not.toMatch(/Présente ce QR|TICKET RETRAIT|Retrait & paiement/i);
      expect(r.qr, 'aucun QR de retrait sur une livraison').toBe(0);
      await r.page.screenshot({ path: path.join(REPO, 'reports', 'test-e2e', 'site-livraison-2026-10-23', 'captures', 'D-11-retour-banque-livraison.png') });
    } finally { await r.ctx.close(); }
  });

  test('(témoin) un pending ANCIEN, sans mode, retombe sur le retrait — le test mord sur la restauration', async ({ browser }) => {
    const ancien = { orderDbId: OID, orderId: String(OID), orderQueue: 'A0999', orderTotal: 11.8, earnedPoints: 0, cartLines: [] };
    const r = await retourBanque(browser, auth, ancien);
    try {
      expect(r.texte).toMatch(/Présente ce QR/);
      expect(r.qr).toBeGreaterThan(0);
    } finally { await r.ctx.close(); }
  });
});

// [Relecture adverse 2026-10-23 · P2] Deux culs-de-sac du bloc livraison : « Choisir une heure » laissé vide en RETRAIT bloquait
// le bouton après passage en LIVRAISON (consigne sur une section cachée) ; le refus « Saisissez votre adresse complète » restait
// affiché pendant la nouvelle frappe.
test.describe('Site — bascule retrait → livraison sans cul-de-sac', () => {
  test.setTimeout(240000);
  test.use({ actionTimeout: 20000 }); // une action qui n'aboutit pas échoue vite, au lieu d'attendre le délai du test
  test('« Choisir une heure » (vide) puis Livraison + devis valide → bouton ACTIF ; le refus d\'adresse s\'efface à la frappe', async ({ browser }) => {
    resetLimiteurs();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR', timezoneId: 'Europe/Paris' });
    const bloquees = [];
    await ctx.route((u) => PROD_RX.test(u.href), (route) => { bloquees.push(route.request().url()); return route.abort('blockedbyclient'); });
    const page = await ctx.newPage();
    try {
      await page.goto(`${SITE}/#menu`, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30000 });
      await page.locator('[aria-label="Voir Sprite 33cl"]').first().click();
      await expect(page.locator('.lc-detail')).toBeVisible({ timeout: 15000 });
      await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
      for (let i = 0; i < 8 && !(await page.locator('.lc-cart-drawer.is-open').count()); i++) {
        const next = page.locator('.lc-wiz-foot-next');
        if (await next.isVisible({ timeout: 1500 }).catch(() => false)) await next.click(); else await page.waitForTimeout(400);
      }
      const drawer = page.locator('.lc-cart-drawer.is-open');
      if (!(await drawer.count())) await page.locator('.lc-nav-btn-cart').first().click();
      await expect(drawer).toBeVisible({ timeout: 15000 });
      await drawer.getByRole('button', { name: /Passer commande/ }).click();
      for (let i = 0; i < 6 && !(await page.locator('.lcf-page').count()); i++) {
        const b = page.getByRole('button', { name: /Non merci|Continuer|Régler ma commande/ }).first();
        if (await b.isVisible({ timeout: 1500 }).catch(() => false)) await b.click();
        await page.waitForTimeout(1200);
      }
      await expect(page.locator('.lcf-page')).toBeVisible({ timeout: 20000 });
      // 1. retrait : « Choisir une heure » sans heure
      await page.locator('.lcf-pickup-slot', { hasText: 'Choisir une heure' }).first().click();
      await page.waitForTimeout(400);
      // 2. livraison : refus d'adresse trop courte, puis effacé à la frappe
      const rg = page.locator('[role="radiogroup"][aria-label="Mode de retrait"]');
      await rg.getByRole('radio', { name: /Livraison/ }).click();
      const input = page.locator('input[aria-label="Adresse de livraison"]');
      await expect(input).toBeVisible({ timeout: 10000 });
      await input.fill('12 r');
      await page.getByRole('button', { name: /^Calculer$/ }).click();
      await expect(page.getByText(/Saisissez votre adresse complète/)).toBeVisible({ timeout: 5000 });
      await input.pressSequentially('ue', { delay: 30 });
      await expect(page.getByText(/Saisissez votre adresse complète/)).toHaveCount(0, { timeout: 3000 });
      // 3. devis valide → bouton actif
      await input.fill('');
      const sugg = page.waitForResponse((r) => /\/delivery\/suggest/.test(r.url()), { timeout: 20000 });
      await input.pressSequentially('12 rue elie gruyelle', { delay: 25 });
      await sugg; await page.waitForTimeout(700);
      const options = await page.evaluate(() => Array.from(document.querySelectorAll('#lcf-delivery-suggest option')).map((o) => o.value));
      expect(options.length, 'suggestions').toBeGreaterThan(0);
      await input.fill(options.find((o) => /H[ée]nin/i.test(o)) || options[0]);
      const quoteP = page.waitForResponse((r) => /\/delivery\/quote/.test(r.url()) && r.request().method() === 'POST', { timeout: 25000 });
      await page.getByRole('button', { name: /^Calculer$/ }).click();
      const quote = await (await quoteP).json();
      expect(quote.allowed, 'devis Hénin autorisé').toBe(true);
      await page.waitForTimeout(900);
      const bouton = page.locator('.lcf-cta-bar-next');
      await expect(bouton, 'bouton « Confirmer » actif après devis valide malgré « Choisir une heure » vide en retrait').toBeEnabled({ timeout: 5000 });
      await expect(page.getByText(/Indique l'heure à laquelle tu passeras/)).toHaveCount(0);
      expect(bloquees).toEqual([]);
    } finally { await ctx.close(); }
  });
});
