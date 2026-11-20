/**
 * [E2E-COMPLET 2026-10-22 · VAGUE B · tour 1] Rapport X / clôture Z : « une vente espèces apparaît au Z », puis le suivi des commandes
 * (plafond d'affichage) vu d'un caissier. Tour 1 = capture + constat, AUCUNE correction.
 *   Z1  X avant → vente ESPÈCES par l'UI (E2E-CAISSE-Z) → X après (delta = la vente) → ouverture Z → clôture Z → la vente est dans le Z
 *   Z2  écrans admin : liste des rapports Z + modale du rapport X
 *   Z3  `/admin/pos-orders-tracker` : combien de lignes, la vente neuve y est-elle (plafond 24 connu : décision propriétaire en attente)
 * La clôture Z est irréversible (chaîne HMAC) : base LOCALE foodking_e2e seulement ; chaîne vérifiée avant/après.
 */
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { login, loginAsPosOperator } = require('../../e2e/helpers/login');
const { attachR2, shot } = require('./r2-lib');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const SHOTS = path.join(ROOT, 'tests/e2e/__screenshots__/e2e-complet-B-r2');
fs.mkdirSync(SHOTS, { recursive: true });
const RES = path.join(SHOTS, '_resultats-z.json');
const R = { notes: [] };
const flush = () => fs.writeFileSync(RES, JSON.stringify(R, null, 2));
const note = (s) => { R.notes.push(s); console.log('[B-Z] ' + s); flush(); };
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const pub = { 'X-API-KEY': env('MIX_API_KEY') || env('API_KEY'), Accept: 'application/json', 'Content-Type': 'application/json' };
function sql(q) { return execFileSync('mysql', ['-uroot', '-h', 'localhost', 'foodking_e2e', '-N', '-B', '-e', q], { env: { ...process.env, MYSQL_PWD: env('DB_PASSWORD') }, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t')); }
function artisan(args) { try { return execFileSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', timeout: 180_000 }); } catch (e) { return 'EXIT ' + e.status + '\n' + (e.stdout || '') + (e.stderr || ''); } }
const eur = (txt) => { const m = String(txt || '').replace(/\s/g, ' ').match(/(\d[\d ]*)[,.](\d{1,2})/); return m ? Number(m[1].replace(/ /g, '') + '.' + m[2].padEnd(2, '0')) : null; };
const settle = async (page, ms = 1200) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };

async function apiAs(email) {
  clearFoodKingRateLimits();
  const l = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, 'X-Device-Id': 'e2e-caisse-b-z-' + email.split('@')[0] + '-' + Date.now() } });
  const r = await l.post('/api/auth/login', { data: { email, password: '123456' } });
  expect(r.status(), 'login ' + email).toBe(201);
  const t = (await r.json()).token; await l.dispose();
  return request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${t}` } });
}
async function openCategory(page, name) {
  const tile = page.locator('[data-testid="pos-category-tile"]', { hasText: name }).first();
  await expect(tile).toBeVisible({ timeout: 20_000 }); await tile.click(); await settle(page, 900);
}
async function addSimpleItem(page, cat, ariaPrefix) {
  await openCategory(page, cat);
  const before = await page.locator('.pos-v5-cart-item').count();
  await page.locator(`button.pos-item-tile[aria-label^="Ajouter ${ariaPrefix}"]`).first().click();
  await page.waitForFunction((n) => document.querySelectorAll('.pos-v5-cart-item').length > n, before, { timeout: 15_000 });
  await page.waitForTimeout(500);
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);
test.use({ actionTimeout: 20_000, viewport: { width: 1440, height: 900 } });

test.describe('B · X / Z / tracker', () => {
  let admin;
  test.beforeAll(async () => {
    admin = await apiAs('admin@lecayenne.fr');
    R.chaineAvant = artisan(['fiscal:verify-chain', '--all']).split('\n').slice(-3).join(' | ');
    note('chaîne avant : ' + R.chaineAvant);
  });

  test('B-Z1 vente espèces → X → Z : la vente est dans le Z', async ({ page }) => {
    test.setTimeout(420_000);
    const { snap } = attachR2(page, SHOTS);
    const xr = async () => { const r = await admin.get('/api/admin/fiscal/x-report'); return { http: r.status(), j: await r.json().catch(() => null) }; };
    const x0 = await xr();
    R.x_avant = x0;
    note('X avant : http ' + x0.http + ' ' + JSON.stringify(x0.j).slice(0, 500));

    // vente espèces par l'UI de la caisse (pos@)
    await loginAsPosOperator(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    const ov = page.locator('[data-testid="cash-session-overlay"]');
    if (await ov.isVisible().catch(() => false)) { await page.locator('[data-testid="cash-session-close"]').click(); await page.waitForTimeout(400); }
    if ((await page.locator('.pos-v5-cart-item').count()) > 0) {
      await page.evaluate(() => { const v = [...document.querySelectorAll('*')].find((e) => e.__vue_app__)?.__vue_app__; v?.config.globalProperties.$store.dispatch('posCart/resetCart'); });
      await page.waitForTimeout(600);
    }
    await addSimpleItem(page, 'Boissons', 'Eau Plate');
    await addSimpleItem(page, 'Desserts', 'Tiramisu');
    await page.locator('[data-testid="pos-customer-name"]').fill('E2E-CAISSE-Z');
    const panier = eur(await page.locator('[data-testid="pos-v5-pay"]').innerText());
    expect(panier, '1,00 + 3,50').toBeCloseTo(4.5, 2);
    await page.locator('[data-testid="pos-v5-pay"]').click();
    await expect(page.locator('[data-testid="pos-payment-mode-cash"]')).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(800);
    await page.locator('[data-testid="pos-payment-mode-cash"]').click();
    await page.locator('#cashInput').fill('5');
    await page.waitForTimeout(500);
    const wait = page.waitForResponse((r) => /\/admin\/pos(\?|$)/.test(r.url()) && r.request().method() === 'POST', { timeout: 40_000 });
    await page.locator('[data-testid="pos-payment-confirm"]').click();
    const resp = await wait;
    const body = await resp.json().catch(() => ({}));
    expect([200, 201]).toContain(resp.status());
    const id = body.data.id;
    await expect(page.locator('#receiptModal')).toBeVisible({ timeout: 20_000 });
    await page.waitForTimeout(800);
    const ticket = (await page.locator('#receiptModal').innerText()).replace(/\s+/g, ' ');
    const tt = [...ticket.matchAll(/(?<![-\w])Total\s*:?\s*(\d[\d ]*,\d{2})/gi)].map((m) => m[1]);
    const no = page.locator('[data-testid="receipt-print-decision-no"]'); if (await no.isVisible().catch(() => false)) await no.click();
    const o = sql(`select id,order_serial_no,total,payment_status,fiscal_sequence_no,pos_payment_method,created_at,fiscal_dated_at from orders where id=${id}`)[0];
    R.vente = { id, panier, ticket: tt.length ? eur(tt[tt.length - 1]) : null, base: o };
    note('vente espèces : ' + JSON.stringify(R.vente));
    expect(+o[2], 'base = panier').toBeCloseTo(panier, 2);
    expect(R.vente.ticket, 'ticket = panier').toBeCloseTo(panier, 2);

    const x1 = await xr();
    R.x_apres = x1;
    note('X après : ' + JSON.stringify(x1.j).slice(0, 500));
    const d = (a, b) => Math.round((Number(a) - Number(b)) * 100) / 100;
    const T = (x) => x.j.data.totals;
    R.x_delta = { total_ttc: d(T(x1).total_ttc, T(x0).total_ttc), order_count: T(x1).order_count - T(x0).order_count, especes_methode_1: d(T(x1).total_by_method['1'], T(x0).total_by_method['1']), cles_methodes: Object.keys(T(x1).total_by_method || {}) };
    note('delta X : ' + JSON.stringify(R.x_delta));
    expect(R.x_delta.total_ttc, 'le X voit la vente (delta = 4,50)').toBeCloseTo(4.5, 2);
    expect(R.x_delta.order_count, 'X : +1 vente').toBeGreaterThanOrEqual(1);
    expect(R.x_delta.especes_methode_1, 'X : espèces +4,50').toBeCloseTo(4.5, 2);

    // Z : ouvrir (si besoin) puis clôturer
    const avantZ = sql('select id,sequence_no,status,closed_at from z_reports where branch_id=1 order by id desc limit 1')[0];
    R.z_avant = avantZ;
    let open = null;
    if (!avantZ || avantZ[2] !== 'open') {
      const ro = await admin.post('/api/admin/fiscal/z-report/open');
      open = { http: ro.status(), corps: (await ro.text()).slice(0, 300) };
      note('Z open : ' + JSON.stringify(open));
      expect(ro.status()).toBe(201);
    }
    R.z_open = open;
    const t0 = Date.now();
    const rc = await admin.post('/api/admin/fiscal/z-report/close');
    const zj = await rc.json().catch(() => ({}));
    R.z_close = { http: rc.status(), ms: Date.now() - t0, data: zj.data };
    note('Z close : http ' + rc.status() + ' ' + JSON.stringify(zj).slice(0, 700));
    expect(rc.status(), JSON.stringify(zj).slice(0, 300)).toBe(200);
    const z = zj.data;
    const mz = z.total_by_method || {};
    // oracle indépendant simple : espèces encaissées de la fenêtre (depuis la clôture précédente) par SQL
    const prev = avantZ && avantZ[2] === 'closed' ? avantZ[3] : sql("select closed_at from z_reports where branch_id=1 and status='closed' and id<" + z.id + ' order by id desc limit 1')[0][0];
    R.z_oracle = { fenetre_depuis: prev, vente_dans_fenetre: o[6] > prev, espece_sql: sql(`select ifnull(sum(total),0), count(*) from orders where branch_id=1 and payment_status=5 and fiscal_sequence_no is not null and pos_payment_method=1 and fiscal_dated_at > '${prev}'`)[0] };
    note('Z : ' + JSON.stringify({ seq: z.sequence_no, ttc: z.total_ttc, par_methode: mz, nb: z.order_count, sig: String(z.signature).slice(0, 12), prev_hash: String(z.prev_hash).slice(0, 12) }) + ' oracle ' + JSON.stringify(R.z_oracle));
    R.z_inclut_vente = { order_count: z.order_count, especes_1: mz['1'] ?? null };
    expect(z.order_count, 'le Z compte des ventes').toBeGreaterThan(0);
    expect(Number(z.total_ttc), 'le Z contient au moins ma vente').toBeGreaterThanOrEqual(4.5);
    // la vente est-elle dans CE Z ? (vérificateur officiel du dépôt, lecture seule)
    R.z_membership = artisan(['fiscal:verify-z-membership']).split('\n').slice(-12).join(' | ');
    note('fiscal:verify-z-membership : ' + R.z_membership);
    R.seq_continuite = artisan(['fiscal:verify-sequence-continuity']).split('\n').slice(-8).join(' | ');
    note('fiscal:verify-sequence-continuity : ' + R.seq_continuite);
    flush();
  });

  test('B-Z2 écrans admin : liste des Z et rapport X', async ({ page }) => {
    const { snap } = attachR2(page, SHOTS);
    await login(page, 'admin@lecayenne.fr', '123456');
    await page.waitForTimeout(1500);
    await page.goto('/admin/settings/z-reports', { waitUntil: 'domcontentloaded' });
    await settle(page, 2500);
    R.z_ecran = { url: page.url(), texte: (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 700) };
    await snap('30-liste-rapports-z');
    const xb = page.locator('[data-testid="fiscal-x-report-btn"]');
    if (await xb.isVisible().catch(() => false)) {
      await xb.click();
      await page.waitForTimeout(1500);
      R.x_ecran = (await page.locator('[data-testid="fiscal-x-report-body"]').innerText().catch(() => '')).slice(0, 900);
      await snap('31-rapport-x-modale');
    }
    R.z_ecran.alertes = await page.evaluate(() => [...document.querySelectorAll('[role="alert"], .Vue-Toastification__toast')].filter((e) => e.getBoundingClientRect().width > 0).map((e) => e.innerText.trim().slice(0, 150)));
    note('écran Z : ' + JSON.stringify(R.z_ecran).slice(0, 500));
    note('écran X : ' + JSON.stringify(R.x_ecran || null).slice(0, 500));
    flush();
  });

  test('B-Z3 suivi des commandes (caissier) : plafond d\'affichage et vente neuve', async ({ page }) => {
    const { snap } = attachR2(page, SHOTS);
    await loginAsPosOperator(page);
    await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
    await settle(page, 3000);
    const rows = await page.locator('[data-testid^="tracker-order-"]').count();
    const venteId = R.vente && R.vente.id;
    const presente = venteId ? await page.locator(`[data-testid="tracker-order-${venteId}"]`).count() : null;
    const actives = sql("select count(*) from orders where branch_id=1 and status in (4,7,8) and deleted_at is null")[0][0];
    const statuts = sql('select status, count(*) from orders where branch_id=1 and deleted_at is null and created_at > now() - interval 3 day group by status');
    R.tracker = { lignes_affichees: rows, vente_neuve_id: venteId, vente_neuve_presente: presente, commandes_statut_actif_en_base: actives, statuts_3j: statuts, vente_statut: venteId ? sql(`select status from orders where id=${venteId}`)[0] : null };
    note('tracker : ' + JSON.stringify(R.tracker));
    await snap('32-suivi-commandes');
    flush();
  });

  test.afterAll(() => {
    R.chaineApres = artisan(['fiscal:verify-chain', '--all']).split('\n').slice(-3).join(' | ');
    note('chaîne après : ' + R.chaineApres);
    flush();
  });
});
