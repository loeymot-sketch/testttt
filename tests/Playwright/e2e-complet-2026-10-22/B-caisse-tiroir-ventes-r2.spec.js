/**
 * [E2E-COMPLET 2026-10-22 · VAGUE B · tour 1] Caisse : tiroir (ouverture / attendu / clôture), vente à emporter ESPÈCES,
 * vente CARTE, vente avec REMISE, tailles d'écran 1280x720 / 1440x900 / 1920x1080 (preuve elementFromPoint).
 * Tour 1 = capture + constat, AUCUNE correction. Le wizard caisse (pos-wizard.js) est GELÉ : on n'y touche pas (ventes sur
 * produits SANS wizard : boissons / desserts). Données préfixées « E2E-CAISSE- ».
 *
 *   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
 *   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
 *     npx playwright test tests/Playwright/e2e-complet-2026-10-22/B-caisse-tiroir-ventes.spec.js --workers=1 --retries=0 --reporter=line
 */
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { login, loginAsChefOperator } = require('../../e2e/helpers/login');
const { attachR2, shot } = require('./r2-lib');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const SHOTS = path.join(ROOT, 'tests/e2e/__screenshots__/e2e-complet-B-r2');
fs.mkdirSync(SHOTS, { recursive: true });
const RES = path.join(SHOTS, '_resultats-tiroir-ventes.json');
const R = { notes: [], sales: {}, drawer: {}, layout: [], i18n: [] };
const flush = () => fs.writeFileSync(RES, JSON.stringify(R, null, 2));
const note = (s) => { R.notes.push(s); console.log('[B-TIROIR] ' + s); flush(); };

const CAISSIER = { email: 'e2e-caisse.caissier@lecayenne.local', password: 'E2E-Caisse-2026!', name: 'E2E-CAISSE-Caissier' };

// ── base locale (vérité) ────────────────────────────────────────────────────────────────────────────
const dbPass = () => (fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '';
function sql(query) {
  const out = execFileSync('mysql', ['-uroot', '-h', 'localhost', 'foodking_e2e', '-N', '-B', '-e', query], { env: { ...process.env, MYSQL_PWD: dbPass() }, encoding: 'utf8', timeout: 30_000 });
  return out.trim().split('\n').filter(Boolean).map((l) => l.split('\t'));
}
function dbOrder(id) {
  const o = sql(`select id,order_serial_no,total,subtotal,discount,payment_status,status,fiscal_sequence_no,pos_payment_method,pos_received_amount,pos_customer_name,queue_number,source_surface from orders where id=${Number(id)}`)[0];
  const items = sql(`select item_id,quantity,total_price,instruction from order_items where order_id=${Number(id)} and deleted_at is null`);
  if (!o) return null;
  return { id: +o[0], serial: o[1], total: +o[2], subtotal: +o[3], discount: +o[4], payment_status: +o[5], status: +o[6], fiscal: o[7] === 'NULL' ? null : +o[7], pos_method: o[8], received: o[9], customer: o[10], queue: o[11], surface: o[12], items: items.map((i) => ({ item_id: +i[0], qty: +i[1], total: +i[2], note: i[3] })) };
}
function chain() { return execFileSync('php', ['artisan', 'fiscal:verify-chain', '--all'], { cwd: ROOT, encoding: 'utf8', timeout: 120_000 }); }
function php(code) { return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 90_000 }).trim().split('\n').filter(Boolean).pop(); }

const settle = async (page, ms = 1200) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
const eur = (txt) => { const m = String(txt || '').replace(/\s/g, ' ').match(/(\d[\d ]*)[,.](\d{1,2})/); return m ? Number(m[1].replace(/ /g, '') + '.' + m[2].padEnd(2, '0')) : null; };
const grandTotal = async (page) => eur(await page.locator('[data-testid="pos-v5-pay"]').innerText());

async function openCategory(page, name) {
  const tile = page.locator('[data-testid="pos-category-tile"]', { hasText: name }).first();
  await expect(tile, `catégorie « ${name} »`).toBeVisible({ timeout: 20_000 });
  await tile.click();
  await settle(page, 900);
}
async function addSimpleItem(page, cat, ariaPrefix) {
  await openCategory(page, cat);
  const before = await page.locator('.pos-v5-cart-item').count();
  await page.locator(`button.pos-item-tile[aria-label^="Ajouter ${ariaPrefix}"]`).first().click();
  await page.waitForFunction((n) => document.querySelectorAll('.pos-v5-cart-item').length > n || !!document.querySelector('#pos-wizard-root button.wizard-btn-cart'), before, { timeout: 15_000 });
  if (await page.locator('#pos-wizard-root button.wizard-btn-cart').isVisible().catch(() => false)) {
    await page.locator('#pos-wizard-root button.wizard-btn-cart').click();
    await page.waitForFunction((n) => document.querySelectorAll('.pos-v5-cart-item').length > n, before, { timeout: 15_000 });
  }
  await page.waitForTimeout(500);
}
async function viderPanier(page) {
  if ((await page.locator('.pos-v5-cart-item').count()) > 0) {
    await page.evaluate(() => { const v = [...document.querySelectorAll('*')].find((e) => e.__vue_app__)?.__vue_app__; v?.config.globalProperties.$store.dispatch('posCart/resetCart'); });
    await page.waitForTimeout(600);
  }
}
/** Fuites i18n (clé brute visible) + toasts + alertes visibles. */
async function lectureEcran(page) {
  return page.evaluate(() => {
    const rx = /(^|\s)((?:label|button|menu|pos|kiosk|message|validation|error|title|common|order)\.[a-z_][a-z0-9_.]{2,})(\s|$)/i;
    const brut = [];
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = w.nextNode())) {
      const t = n.textContent.trim();
      if (!t || t.length > 80) continue;
      const p = n.parentElement; if (!p || /SCRIPT|STYLE|CODE|PRE|NOSCRIPT/.test(p.tagName)) continue;
      const r = p.getBoundingClientRect(); if (r.width === 0 || r.height === 0) continue;
      if (rx.test(t) && !/\s{2,}/.test(t) && !/[A-ZÀ-ÿ]/.test(t[0] === t[0].toLowerCase() ? '' : t[0])) brut.push(t);
    }
    const alertes = [...document.querySelectorAll('[role="alert"], .Vue-Toastification__toast, .alert-danger')].filter((e) => e.getBoundingClientRect().width > 0).map((e) => e.innerText.trim().slice(0, 200));
    return { brut: [...new Set(brut)].slice(0, 10), alertes };
  });
}
function net(page) {
  const all = [];
  page.on('response', async (resp) => {
    try {
      const u = new URL(resp.url()); if (!/^\/api\//.test(u.pathname)) return;
      const req = resp.request();
      const e = { method: req.method(), url: u.pathname, status: resp.status() };
      if (/admin\/pos(\/quote)?$|cash-sessions|cash-drawer/.test(u.pathname)) { try { e.body = (await resp.text()).slice(0, 1500); } catch (_) {} }
      all.push(e);
    } catch (_) {}
  });
  return { all, erreurs: () => all.filter((e) => e.status >= 400) };
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);
test.use({ actionTimeout: 20_000, viewport: { width: 1440, height: 900 } });

test.describe('B · tiroir + ventes', () => {
  test.beforeAll(() => {
    R.chaineAvant = chain().split('\n').slice(-3).join(' | ');
    note('chaîne fiscale avant : ' + R.chaineAvant);
    // Caissier jetable (rôle « POS Operator »), jamais supprimé : sert à voir le formulaire d'OUVERTURE de caisse.
    const id = php(`
      $r = \\Spatie\\Permission\\Models\\Role::where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
      $u = \\App\\Models\\User::withoutGlobalScopes()->where('email', '${CAISSIER.email}')->first()
        ?? \\App\\Models\\User::create(['name' => '${CAISSIER.name}', 'email' => '${CAISSIER.email}', 'phone' => '0600000505', 'country_code' => '+33', 'username' => 'e2ecaissecaissier', 'password' => bcrypt('${CAISSIER.password}'), 'branch_id' => 1, 'status' => 5, 'is_guest' => 10]);
      $u->syncRoles([$r]); $u->forceFill(['password' => bcrypt('${CAISSIER.password}'), 'status' => 5, 'branch_id' => 1, 'name' => '${CAISSIER.name}'])->save();
      \\App\\Models\\CashDrawerSession::withoutGlobalScopes()->where('opened_by_user_id', $u->id)->where('status', 'open')->count() ? print('SESSION_OUVERTE_' . $u->id) : print($u->id);`);
    note('caissier jetable : ' + id);
    R.caissierId = id;
    flush();
  });

  test('B-T1 tiroir de pos@ (session héritée) : ce que voit le caissier', async ({ page }) => {
    clearFoodKingRateLimits();
    const n = net(page);
    const { snap } = attachR2(page, SHOTS);
    await login(page, 'pos@lecayenne.fr', '123456');
    await page.waitForTimeout(1200);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    const btn = page.locator('[data-testid="pos-cash-session-open"]');
    await expect(btn).toBeVisible({ timeout: 20_000 });
    R.drawer.pos_btn_text = (await btn.innerText()).replace(/\s+/g, ' ').trim();
    R.drawer.pos_btn_title = await btn.getAttribute('title');
    await btn.click();
    await expect(page.locator('[data-testid="cash-session-overlay"]')).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(900);
    R.drawer.pos_dialog = (await page.locator('[data-testid="cash-session-overlay"]').innerText()).replace(/\s+/g, ' ').slice(0, 700);
    R.drawer.pos_mode_actif = await page.locator('[data-testid="cash-session-active-view"]').isVisible().catch(() => false);
    R.drawer.pos_mode_ouverture = await page.locator('[data-testid="cash-session-open-form"]').isVisible().catch(() => false);
    R.drawer.pos_stale_badge = await page.locator('[data-testid="cash-session-stale-badge"]').isVisible().catch(() => false);
    R.drawer.pos_db = sql("select id,status,opened_at,opening_amount from cash_drawer_sessions where opened_by_user_id=3 and status='open'");
    note('tiroir pos@ : ' + JSON.stringify(R.drawer));
    await snap('01-tiroir-pos-session-heritee');
    R.drawer.pos_ecran = await lectureEcran(page);
    R.drawer.pos_erreurs_reseau = n.erreurs();
    flush();
  });

  test('B-T2 ouverture de caisse (nouveau caissier) : fond de caisse', async ({ page }) => {
    clearFoodKingRateLimits();
    const n = net(page);
    const { snap } = attachR2(page, SHOTS);
    await login(page, CAISSIER.email, CAISSIER.password);
    await page.waitForTimeout(1200);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    // La caisse peut s'ouvrir toute seule sur le dialogue d'ouverture
    let overlay = page.locator('[data-testid="cash-session-overlay"]');
    if (!(await overlay.isVisible().catch(() => false))) {
      R.drawer.ouverture_auto = false;
      await page.locator('[data-testid="pos-cash-session-open"]').click();
      await expect(overlay).toBeVisible({ timeout: 15_000 });
    } else R.drawer.ouverture_auto = true;
    await page.waitForTimeout(800);
    await expect(page.locator('[data-testid="cash-session-open-form"]'), 'formulaire d\'ouverture').toBeVisible();
    await snap('02-ouverture-formulaire');
    const incs = await page.locator('[data-testid^="cash-session-open-inc-"]').evaluateAll((els) => els.map((e) => e.dataset.testid.replace('cash-session-open-inc-', '')));
    R.drawer.incrementations = incs;
    R.drawer.fond_par_defaut = (await page.locator('[data-testid="cash-session-opening-display"]').innerText()).replace(/\s+/g, ' ');
    await page.locator('[data-testid="cash-session-open-reset"]').click();
    // 100 € de fond : on cumule avec les incréments proposés
    const gros = incs.map(Number).sort((a, b) => b - a);
    let reste = 100;
    for (const v of gros) { while (reste >= v && reste > 0) { await page.locator(`[data-testid="cash-session-open-inc-${v}"]`).click(); reste -= v; if (reste <= 0) break; } }
    const aff = (await page.locator('[data-testid="cash-session-opening-display"]').innerText()).replace(/\s+/g, ' ');
    R.drawer.fond_affiche = aff;
    expect(eur(aff), 'fond affiché = 100').toBeCloseTo(100, 2);
    const resp = page.waitForResponse((r) => r.request().method() === 'POST' && /cash/.test(r.url()), { timeout: 20_000 });
    await page.locator('[data-testid="cash-session-open-submit"]').click();
    const r = await resp;
    R.drawer.open_http = r.status();
    R.drawer.open_url = new URL(r.url()).pathname;
    await page.waitForTimeout(1500);
    await snap('03-apres-ouverture');
    const row = sql(`select id,status,opening_amount,opened_by_user_id from cash_drawer_sessions where opened_by_user_id=${R.caissierId} order by id desc limit 1`)[0];
    R.drawer.session_db = row;
    note('ouverture : HTTP ' + r.status() + ' base=' + JSON.stringify(row));
    expect([200, 201]).toContain(r.status());
    expect(row[1]).toBe('open');
    expect(+row[2], 'fond en base = 100').toBeCloseTo(100, 2);
    R.drawer.session_id = +row[0];
    R.drawer.ecran_apres = await lectureEcran(page);
    flush();
  });

  test('B-T3 ventes à emporter : espèces, carte, remise ; ticket = panier = base = cuisine', async ({ browser }) => {
    test.setTimeout(420_000);
    clearFoodKingRateLimits();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    const n = net(page);
    const { snap } = attachR2(page, SHOTS);
    await login(page, CAISSIER.email, CAISSIER.password);
    await page.waitForTimeout(1200);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    // fermer un éventuel dialogue de tiroir
    const ov = page.locator('[data-testid="cash-session-overlay"]');
    if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(500); }

    // cuisine : un 2e écran (chef) regarde arriver chaque vente
    const chefCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const kds = await chefCtx.newPage();
    const kdsRec = attachR2(kds, SHOTS);
    await loginAsChefOperator(kds);
    await settle(kds, 2000);
    // Vérité cuisine : l'API du KDS (le même flux que l'écran). Le DOM du KDS plafonne à 24 cartes (décision propriétaire connue) :
    // sur une base à 80+ commandes en cuisine, une vente neuve peut être hors des 24 premières — on le MESURE, on ne le prend pas pour un défaut.
    const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
    const pubH = { 'X-API-KEY': env('MIX_API_KEY') || env('API_KEY'), Accept: 'application/json', 'Content-Type': 'application/json', 'X-Device-Id': 'e2e-caisse-b-chef-' + Date.now() };
    clearFoodKingRateLimits();
    const lg = await request.newContext({ baseURL: BASE, extraHTTPHeaders: pubH });
    const lr = await lg.post('/api/auth/login', { data: { email: 'chef@lecayenne.fr', password: '123456' } });
    const chefTok = (await lr.json()).token;
    const chefApi = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pubH, Authorization: `Bearer ${chefTok}` } });
    const carteCuisine = async (orderId, label, attendu) => {
      const t0 = Date.now();
      let ligne = null;
      for (let i = 0; i < 12 && !ligne; i++) {
        const r = await chefApi.get('/api/admin/kds-order?paginate=0&order_column=id&order_by=desc');
        const j = await r.json().catch(() => ({}));
        const arr = j.data || j;
        ligne = (Array.isArray(arr) ? arr : []).find((o) => Number(o.id) === Number(orderId)) || null;
        if (!ligne) await new Promise((res) => setTimeout(res, 1500));
      }
      const ms = Date.now() - t0;
      const items = ligne ? (ligne.order_items || ligne.items || []).map((it) => ({ nom: it.item_name || (it.item && it.item.name) || it.name, qty: Number(it.quantity) })) : null;
      const cartesDom = await kds.locator('[data-order-id]').count();
      const dom = await kds.locator(`[data-order-id="${orderId}"]`).count();
      R.sales[label].cuisine = { api_trouve: !!ligne, ms, items, attendu, cartes_dom_kds: cartesDom, carte_dom_presente: dom > 0, cles: ligne ? Object.keys(ligne).slice(0, 40) : null };
      note(`cuisine ${label} #${orderId} : API=${!!ligne} (${ms} ms) items=${JSON.stringify(items)} DOM cartes=${cartesDom} présente=${dom > 0}`);
    };

    const ticketTotal = async () => {
      await expect(page.locator('#receiptModal'), 'ticket affiché').toBeVisible({ timeout: 20_000 });
      await page.waitForTimeout(1000);
      const t = (await page.locator('#receiptModal').innerText()).replace(/\s+/g, ' ');
      const totals = [...t.matchAll(/(?<![-\w])Total\s*:?\s*(\d[\d ]*,\d{2})/gi)].map((m) => m[1]);
      const rem = (t.match(/Remise\s*:?\s*(\d[\d ]*,\d{2})/i) || [])[1];
      return { texte: t, total: totals.length ? eur(totals[totals.length - 1]) : null, remise: rem ? eur(rem) : null };
    };
    const fermerTicket = async () => {
      const no = page.locator('[data-testid="receipt-print-decision-no"]');
      if (await no.isVisible().catch(() => false)) await no.click();
      await page.waitForTimeout(800);
      const close = page.locator('#receiptModal .modal-close').first();
      if (await close.isVisible().catch(() => false)) { await close.click(); await page.waitForTimeout(600); }
    };
    const payer = async (mode, dejaClique = false) => {
      if (!dejaClique) await page.locator('[data-testid="pos-v5-pay"]').click();
      await expect(page.locator(`[data-testid="pos-payment-mode-${mode}"]`)).toBeVisible({ timeout: 20_000 });
      await page.waitForTimeout(800);
      await page.locator(`[data-testid="pos-payment-mode-${mode}"]`).click();
      await page.waitForTimeout(700);
    };
    const valider = async () => {
      const wait = page.waitForResponse((r) => /\/admin\/pos(\?|$)/.test(r.url()) && r.request().method() === 'POST', { timeout: 40_000 });
      await page.locator('[data-testid="pos-payment-confirm"]').click();
      const r = await wait;
      const body = await r.json().catch(() => ({}));
      return { status: r.status(), id: body?.data?.id || body?.id, body };
    };

    // ── 1. ESPÈCES : Coca-Cola Zero + Tiramisu ───────────────────────────────────────────────────────
    await viderPanier(page);
    await addSimpleItem(page, 'Boissons', 'Coca-Cola Zero');
    await addSimpleItem(page, 'Desserts', 'Tiramisu');
    await page.locator('[data-testid="pos-customer-name"]').fill('E2E-CAISSE-Espèces');
    const panierEsp = await grandTotal(page);
    R.sales.especes = { panier: panierEsp };
    expect(panierEsp, '1,90 + 3,50').toBeCloseTo(5.4, 2);
    await payer('cash');
    await page.locator('#cashInput').fill('10');
    await page.waitForTimeout(600);
    const rendu = (await page.locator('.pos-v5-payment-change').innerText().catch(() => '')).replace(/\s+/g, ' ');
    R.sales.especes.rendu_affiche = rendu;
    expect(eur(rendu), 'rendu = 10 − 5,40').toBeCloseTo(4.6, 2);
    await snap('04-paiement-especes-rendu');
    const re = await valider();
    R.sales.especes.http = re.status;
    expect([200, 201]).toContain(re.status);
    const tkE = await ticketTotal();
    R.sales.especes.ticket = tkE.total;
    await snap('05-ticket-especes');
    await fermerTicket();
    const dbE = dbOrder(re.id);
    R.sales.especes.base = dbE; R.sales.especes.id = re.id;
    note('espèces : ' + JSON.stringify({ panier: panierEsp, ticket: tkE.total, base: dbE.total, id: re.id }));
    expect(tkE.total, 'ticket = panier').toBeCloseTo(panierEsp, 2);
    expect(dbE.total, 'base = panier').toBeCloseTo(panierEsp, 2);
    expect(dbE.payment_status, 'PAID').toBe(5);
    expect(dbE.fiscal, 'n° fiscal').not.toBeNull();
    expect(dbE.items.reduce((s, i) => s + i.total, 0), 'Σ lignes (TTC) = total').toBeCloseTo(dbE.total, 2);
    await carteCuisine(re.id, 'especes', dbE.items);

    // ── 2. CARTE : 2 × Sprite + Glace ────────────────────────────────────────────────────────────────
    await viderPanier(page);
    await addSimpleItem(page, 'Boissons', 'Sprite');
    await addSimpleItem(page, 'Boissons', 'Oasis');
    await addSimpleItem(page, 'Desserts', 'Glace');
    await page.locator('[data-testid="pos-customer-name"]').fill('E2E-CAISSE-Carte');
    const panierCb = await grandTotal(page);
    R.sales.carte = { panier: panierCb };
    await payer('card');
    const term = page.locator('[data-testid="pos-payment-terminal-select"]');
    await expect(term, 'sélecteur TPE').toBeVisible();
    R.sales.carte.tpe = { valeur: await term.inputValue(), options: await term.locator('option').allInnerTexts() };
    await snap('06-paiement-carte-tpe');
    const rc = await valider();
    R.sales.carte.http = rc.status; R.sales.carte.id = rc.id;
    expect([200, 201]).toContain(rc.status);
    const tkC = await ticketTotal();
    R.sales.carte.ticket = tkC.total;
    await snap('07-ticket-carte');
    await fermerTicket();
    const dbC = dbOrder(rc.id); R.sales.carte.base = dbC;
    note('carte : ' + JSON.stringify({ panier: panierCb, ticket: tkC.total, base: dbC.total, id: rc.id }));
    expect(tkC.total).toBeCloseTo(panierCb, 2);
    expect(dbC.total).toBeCloseTo(panierCb, 2);
    expect(dbC.payment_status).toBe(5);
    expect(dbC.items.reduce((s, i) => s + i.total, 0)).toBeCloseTo(dbC.total, 2);
    await carteCuisine(rc.id, 'carte', dbC.items);

    // ── 3. REMISE : Oasis + Tiramisu, remise fixe 1 € motivée ───────────────────────────────────────
    await viderPanier(page);
    await addSimpleItem(page, 'Boissons', 'Oasis');
    await addSimpleItem(page, 'Desserts', 'Tiramisu');
    await page.locator('[data-testid="pos-customer-name"]').fill('E2E-CAISSE-Remise');
    const avantRemise = await grandTotal(page);
    await page.locator('[data-testid="pos-discount-toggle"]').click();
    await page.waitForTimeout(400);
    // type « Fixe » si le sélecteur (propre au bloc remise) est sur « Pourcentage »
    const groupe = page.locator('#pos-discount-input').locator('xpath=preceding-sibling::div[contains(@class,"dropdown-group")]');
    const typeBtn = groupe.locator('button').first();
    const typeTxt = (await typeBtn.innerText().catch(() => '')).trim();
    R.sales.remise = { avantRemise, type_par_defaut: typeTxt };
    if (/pourcent|percent/i.test(typeTxt)) {
      await typeBtn.hover();
      await groupe.locator('li', { hasText: /fixe/i }).first().evaluate((el) => el.click());
      await page.waitForTimeout(400);
    }
    await page.locator('[data-testid="pos-discount-input"]').fill('1');
    await page.locator('[data-testid="pos-discount-reason"]').fill('E2E-CAISSE geste commercial');
    await page.waitForTimeout(300);
    await page.locator('[data-testid="pos-discount-apply"]').click();
    await page.waitForTimeout(1500);
    const apresRemise = await grandTotal(page);
    R.sales.remise.apresRemise = apresRemise;
    R.sales.remise.type_utilise = (await typeBtn.innerText().catch(() => '')).trim();
    await snap('08-remise-appliquee-panier');
    note('remise : ' + JSON.stringify(R.sales.remise));
    const avantPay = n.all.length;
    await page.locator('[data-testid="pos-v5-pay"]').click();
    await page.waitForTimeout(2500);
    R.sales.remise.apres_clic_pay = { modale_visible: await page.locator('[data-testid="pos-payment-mode-cash"]').isVisible().catch(() => false), reseau: n.all.slice(avantPay).map((e) => `${e.method} ${e.url} ${e.status}`), ecran: await lectureEcran(page) };
    note('remise après clic Commande : ' + JSON.stringify(R.sales.remise.apres_clic_pay));
    await snap('09-remise-apres-clic-commande');
    const refusee = !R.sales.remise.apres_clic_pay.modale_visible && R.sales.remise.apres_clic_pay.reseau.some((x) => /pos\/quote 422/.test(x));
    R.sales.remise.refusee_par_le_serveur = refusee;
    R.sales.remise.message_visible = R.sales.remise.apres_clic_pay.ecran.alertes.some((a) => /remises manuelles/i.test(a));
    R.sales.remise.commandes_creees_en_base = sql("select id from orders where pos_customer_name='E2E-CAISSE-Remise'").length;
    if (refusee) {
      note('REMISE : refusée par le serveur (kill-switch POS_MANUAL_DISCOUNT_ENABLED=false, décision propriétaire 2026-07-18) ; message visible=' + R.sales.remise.message_visible + ' ; commandes créées=' + R.sales.remise.commandes_creees_en_base);
      expect(R.sales.remise.message_visible, 'le refus est expliqué au caissier (pas d\'erreur muette)').toBe(true);
      expect(R.sales.remise.commandes_creees_en_base, 'aucune vente créée').toBe(0);
    } else {
      await payer('cash', true);
      await page.locator('#cashInput').fill('10');
      await page.waitForTimeout(500);
      await snap('09b-remise-paiement-especes');
      const rr = await valider();
      R.sales.remise.http = rr.status; R.sales.remise.id = rr.id;
      expect([200, 201], JSON.stringify(rr.body).slice(0, 300)).toContain(rr.status);
      const tkR = await ticketTotal();
      R.sales.remise.ticket = tkR.total; R.sales.remise.ticket_remise = tkR.remise;
      await snap('10-ticket-remise');
      await fermerTicket();
      const dbR = dbOrder(rr.id); R.sales.remise.base = dbR;
      expect(tkR.total, 'ticket = panier après remise').toBeCloseTo(apresRemise, 2);
      expect(dbR.total, 'base = panier après remise').toBeCloseTo(apresRemise, 2);
      await carteCuisine(rr.id, 'remise', dbR.items);
    }
    await kdsRec.snap('11-cuisine-apres-ventes');

    R.sales.erreursReseau = n.erreurs();
    R.sales.ecran = await lectureEcran(page);
    flush();
    await chefCtx.close();
    await ctx.close();
  });

  test('B-T4 tiroir après ventes : attendu = fond + espèces ; clôture', async ({ page }) => {
    clearFoodKingRateLimits();
    const n = net(page);
    const { snap } = attachR2(page, SHOTS);
    await login(page, CAISSIER.email, CAISSIER.password);
    await page.waitForTimeout(1200);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    const ov = page.locator('[data-testid="cash-session-overlay"]');
    if (!(await ov.isVisible().catch(() => false))) await page.locator('[data-testid="pos-cash-session-open"]').click();
    await expect(page.locator('[data-testid="cash-session-active-view"]')).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(800);
    const attenduAffiche = eur(await page.locator('[data-testid="cash-session-stat-expected"]').innerText());
    const mvts = (await page.locator('[data-testid="cash-session-stat-mvt-count"]').innerText()).trim();
    const especes = (R.sales.especes && R.sales.especes.base ? R.sales.especes.base.total : 0) + (R.sales.remise && R.sales.remise.base ? R.sales.remise.base.total : 0);
    R.drawer.attendu_affiche = attenduAffiche; R.drawer.mvts_affiches = mvts; R.drawer.especes_attendues_ventes = especes;
    R.drawer.mouvements_db = sql(`select id,order_id,type,amount,direction from cash_movements where cash_drawer_session_id=${R.drawer.session_id} order by id`);
    note('tiroir après ventes : ' + JSON.stringify({ attenduAffiche, mvts, especes, mouvements: R.drawer.mouvements_db }));
    await snap('12-tiroir-actif-apres-ventes');
    expect(attenduAffiche, 'attendu = fond 100 + espèces encaissées (rendu déduit)').toBeCloseTo(100 + especes, 2);
    // mouvements
    await page.locator('[data-testid="cash-session-view-movements"]').click();
    await page.waitForTimeout(800);
    R.drawer.mouvements_vus = await page.locator('[data-testid="cash-session-movement-row"]').count();
    await snap('13-tiroir-mouvements');
    await page.locator('[data-testid="cash-session-overlay"] button', { hasText: /retour|back/i }).first().click().catch(() => {});
    await page.waitForTimeout(500);
    if (!(await page.locator('[data-testid="cash-session-go-close"]').isVisible().catch(() => false))) {
      await page.keyboard.press('Escape'); await page.waitForTimeout(400);
      await page.locator('[data-testid="pos-cash-session-open"]').click(); await page.waitForTimeout(800);
    }
    await page.locator('[data-testid="cash-session-go-close"]').click();
    await expect(page.locator('[data-testid="cash-session-close-form"]')).toBeVisible();
    // comptage exact : on tape le montant attendu
    const cible = Math.round(attenduAffiche * 100) / 100;
    const champ = page.locator('[data-testid="cash-session-closing-input"]');
    if (await champ.isVisible().catch(() => false)) await champ.fill(String(cible)); else R.drawer.sans_champ_cloture = true;
    await page.waitForTimeout(500);
    R.drawer.cloture_attendu = (await page.locator('[data-testid="cash-session-close-expected"]').innerText()).replace(/\s+/g, ' ');
    R.drawer.cloture_ecart = (await page.locator('[data-testid="cash-session-close-variance"]').innerText()).replace(/\s+/g, ' ');
    R.drawer.cloture_affiche = (await page.locator('[data-testid="cash-session-closing-display"]').innerText().catch(() => '')).replace(/\s+/g, ' ');
    await snap('14-tiroir-cloture-formulaire');
    const resp = page.waitForResponse((r) => r.request().method() === 'POST' && /cash/.test(r.url()), { timeout: 20_000 });
    await page.locator('[data-testid="cash-session-close-submit"]').click();
    const r = await resp;
    R.drawer.close_http = r.status(); R.drawer.close_url = new URL(r.url()).pathname;
    await page.waitForTimeout(1500);
    await snap('15-tiroir-apres-cloture');
    R.drawer.session_apres = sql(`select id,status,opening_amount,closing_amount,expected_closing_amount,variance from cash_drawer_sessions where id=${R.drawer.session_id}`)[0];
    note('clôture tiroir : ' + JSON.stringify({ http: r.status(), base: R.drawer.session_apres, ecart: R.drawer.cloture_ecart }));
    R.drawer.erreurs_reseau = n.erreurs();
    R.drawer.ecran = await lectureEcran(page);
    flush();
    expect([200, 201]).toContain(r.status());
    expect(['closed', 'reconciled']).toContain(R.drawer.session_apres[1]);
  });

  test('B-T5 tailles d\'écran : boutons touchables (elementFromPoint) et pas de débordement', async ({ browser }) => {
    test.setTimeout(300_000);
    for (const [w, h] of [[1280, 720], [1440, 900], [1920, 1080]]) {
      clearFoodKingRateLimits();
      const ctx = await browser.newContext({ viewport: { width: w, height: h } });
      const page = await ctx.newPage();
      const { snap } = attachR2(page, SHOTS);
      await login(page, 'pos@lecayenne.fr', '123456');
      await page.waitForTimeout(1200);
      await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await settle(page, 2500);
      const ov = page.locator('[data-testid="cash-session-overlay"]');
      if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(500); }
      await viderPanier(page);
      await addSimpleItem(page, 'Boissons', 'Sprite');
      await page.waitForTimeout(500);
      const cibles = ['pos-cash-session-open', 'pos-delivery-toggle', 'pos-delivery-board-open', 'pos-v5-pay', 'pos-discount-toggle', 'pos-customer-name'];
      const res = await page.evaluate((ids) => {
        const out = [];
        for (const id of ids) {
          const el = document.querySelector(`[data-testid="${id}"]`);
          if (!el) { out.push({ id, present: false }); continue; }
          el.scrollIntoView({ block: 'center' });
          const r = el.getBoundingClientRect();
          const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
          const dans = cx >= 0 && cy >= 0 && cx <= innerWidth && cy <= innerHeight;
          const top = document.elementFromPoint(cx, cy);
          out.push({ id, present: true, dans_fenetre: dans, touchable: !!top && (top === el || el.contains(top) || top.contains(el)), recouvert_par: top && !(top === el || el.contains(top) || top.contains(el)) ? (top.tagName + '.' + String(top.className).slice(0, 40)) : null, box: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)] });
        }
        const de = document.documentElement;
        return { out, scrollX: de.scrollWidth - de.clientWidth, innerW: innerWidth, innerH: innerHeight, bodyScrollH: document.body.scrollHeight };
      }, cibles);
      R.layout.push({ w, h, ...res });
      note(`écran ${w}x${h} : ` + JSON.stringify(res.out.map((o) => `${o.id}:${o.present ? (o.touchable ? 'ok' : 'RECOUVERT ' + o.recouvert_par) : 'absent'}${o.dans_fenetre === false ? ' HORS-FENETRE' : ''}`)) + ` débordement X=${res.scrollX}`);
      await snap(`16-caisse-${w}x${h}`);
      await ctx.close();
    }
    flush();
  });

  test.afterAll(() => {
    R.chaineApres = chain().split('\n').slice(-3).join(' | ');
    note('chaîne fiscale après : ' + R.chaineApres);
    flush();
  });
});
