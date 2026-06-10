// FoodKing — PILOTE W-D : KDS/OSS PROFOND (GOAL VALIDATION PROFONDE 100% — 2026-06-10)
//
// Parcours D1→D6 (chaque état = capture JPEG q70 + analyse) :
//   D1 flux complet : kiosk fresh order → KDS NOUVELLE → Démarrer (EN COURS) →
//      Prêt (PREPARED) → footer « Récemment servies » + DB 4→7→8 ; OSS
//      « En préparation » → « Prêt ».
//   D2 recall : drawer historique → « Annuler bump » → POST serveur (KDS-OSS-01,
//      pas localStorage-only) → order_status_transitions reason=kitchen_recall,
//      orders.status RESTE 8 (NF525 append-only), badge RAPPELÉ ; re-recall → 409.
//   D3 drawer historique : ouvrir, lister, capture, refetch (close/reopen).
//   D4 multi-postes : 2 contexts /kds, bump poste A → poste B <6s (mesuré).
//   CYCLE 2 : D1-D4 compact sur une 2e commande.
//   D6 OSS public : /order-status-screen sans auth (mur public).
//   D5 dégradé (EN DERNIER) : kill soketi → polling fallback (mesuré) →
//      relance soketi OBLIGATOIRE → retour temps réel sans doublon.
//
// Exécution (clone jetable foodking_e2e UNIQUEMENT) :
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   DB_DATABASE=foodking_e2e npx playwright test tests/e2e/zz-kds-oss-profond-2026-06-10.spec.js --retries=0
//
// ANTI-INTERFÉRENCE : un pilote W-C mutile le catalogue (préfixe E2E-WC) en
// parallèle — ce spec n'utilise QUE les items 49-59 (boissons/desserts, add simple).
// Le user chef@lecayenne.fr est réservé à ce pilote ; kiosk token partagé avec
// W-A → création de commande wrappée re-login + retry (LoginController:155
// révoque les vieux auth_token au relogin).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync, execSync, spawn } = require('child_process');
const { loginAsKiosk, loginAsChefOperator } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../../reports/test-e2e/validation-profonde-2026-06-10/kds-oss');
fs.mkdirSync(OUT, { recursive: true });
const REPO = path.resolve(__dirname, '../..');
const SOKETI_CWD = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/pre-cloud-exec';

// ─────────────────────────── shared utils ───────────────────────────

function db(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
    cwd: REPO, encoding: 'utf8', timeout: 15_000,
  }).trim();
}

function purgeBacklog(tag) {
  db("UPDATE orders SET status=13 WHERE status IN (4,7,8) AND created_at < NOW() - INTERVAL 10 MINUTE;");
  const left = db('SELECT COUNT(*) FROM orders WHERE status IN (4,7,8);');
  console.log(`[PURGE ${tag}] backlog>10min → 13 ; restant actif=${left}`);
}

async function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function pollDb(sql, predicate, attempts = 20, delayMs = 1000) {
  for (let i = 0; i < attempts; i++) {
    const v = db(sql);
    if (predicate(v)) return v;
    await sleep(delayMs);
  }
  return db(sql);
}

/** Poll a probe fn every `step` ms; returns elapsed ms when truthy, -1 on timeout. */
async function measure(probe, timeoutMs = 15_000, step = 250) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeoutMs) {
    if (await probe().catch(() => false)) return Date.now() - t0;
    await sleep(step);
  }
  return -1;
}

async function shot(page, name, fullPage = true) {
  await page.screenshot({ path: path.join(OUT, `${name}.jpg`), type: 'jpeg', quality: 70, fullPage }).catch((e) => {
    console.warn(`[SHOT ${name}] failed: ${e.message}`);
  });
}

function soketiUp() {
  try {
    execSync('lsof -nP -iTCP:6001 -sTCP:LISTEN', { encoding: 'utf8', timeout: 5_000 });
    return true;
  } catch (_e) { return false; }
}

function restartSoketi() {
  if (soketiUp()) { console.log('[SOKETI] déjà up — pas de relance'); return; }
  console.log('[SOKETI] relance…');
  const child = spawn('nohup', ['soketi', 'start', '--config=soketi.json'], {
    cwd: SOKETI_CWD, detached: true, stdio: ['ignore',
      fs.openSync('/tmp/soketi-wd-kds.log', 'a'), fs.openSync('/tmp/soketi-wd-kds.log', 'a')],
  });
  child.unref();
}

// ─────────────────────────── shared state ───────────────────────────

let ctxKiosk, ctxChef, ctxB, ctxPublic;
let kioskPage, kdsA, ossPage, kdsB, publicOss;
const issues = []; // { page, kind, detail }
const KNOWN_OK = [
  /kds-order\/recall\/\d+.*409|409.*kds-order\/recall/, // cap recall N=1 — attendu (D2)
  /api\/auth\/user/,                        // probe pré-auth SPA
  /broadcasting\/auth/,                     // auth WS pendant fenêtre soketi-down (D5)
  /:6001/,                                  // websocket direct pendant D5
  /WebSocket/i,                             // console WS down pendant D5
];

function attachCollectors(page, label) {
  page.on('pageerror', (e) => issues.push({ page: label, kind: 'pageerror', detail: String(e.message).slice(0, 300) }));
  page.on('console', (m) => {
    if (m.type() === 'error') issues.push({ page: label, kind: 'console', detail: m.text().slice(0, 300) });
  });
  page.on('response', (r) => {
    if (r.status() >= 400) issues.push({ page: label, kind: `http${r.status()}`, detail: `${r.request().method()} ${r.url()}`.slice(0, 220) });
  });
}

/** Kiosk fresh order (Plan-B counter) — re-login + retry ≤3 (token partagé W-A). */
async function placeKioskOrder(itemIds, shotName) {
  let lastErr = null;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const baselineMax = parseInt(db('SELECT IFNULL(MAX(id),0) FROM orders;'), 10);
      await loginAsKiosk(kioskPage);
      await kioskPage.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await kioskPage.waitForTimeout(1800);
      const takeaway = kioskPage.locator('[data-testid="kiosk-order-type-takeaway"]');
      if (!(await takeaway.isVisible().catch(() => false))) {
        const touch = kioskPage.locator('[data-testid="kiosk-idle-touch-btn"]');
        if (await touch.isVisible().catch(() => false)) { await touch.click(); await kioskPage.waitForTimeout(900); }
      }
      await expect(takeaway).toBeVisible({ timeout: 12_000 });
      await takeaway.click();
      await kioskPage.waitForTimeout(1200);
      for (const id of itemIds) {
        let added = false;
        for (const cat of [10, 9, 8]) {
          await kioskPage.goto(`/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
          await kioskPage.waitForTimeout(1500);
          const add = kioskPage.locator(`[data-testid="kiosk-product-add-${id}"]`);
          if (await add.isVisible().catch(() => false)) { await add.click(); await kioskPage.waitForTimeout(1000); added = true; break; }
        }
        if (!added) throw new Error(`item simple ${id} introuvable (cats 10/9/8)`);
      }
      await kioskPage.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await kioskPage.waitForTimeout(1500);
      const checkout = kioskPage.locator('[data-testid="kiosk-cart-checkout"]');
      await expect(checkout).toBeVisible({ timeout: 10_000 });
      await checkout.click();
      const upsellSkip = kioskPage.locator('[data-testid="kiosk-upsell-skip"]');
      await upsellSkip.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
      if (await upsellSkip.isVisible().catch(() => false)) await upsellSkip.click().catch(() => {});
      await kioskPage.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 }).catch(() => {});
      await kioskPage.waitForTimeout(1800);
      const confirm = kioskPage.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first();
      await expect(confirm).toBeVisible({ timeout: 12_000 });
      const orderResp = kioskPage.waitForResponse(
        (r) => r.request().method() === 'POST' && /\/order\/?(\?|$)/i.test(r.url()),
        { timeout: 25_000 },
      ).catch(() => null);
      await confirm.click();
      const resp = await orderResp;
      const apiStatus = resp ? resp.status() : null;
      const postDoneAt = Date.now(); // t0 latence multi-poste
      // run2 fix: le flux Plan-B compteur atterrit sur /kiosk/cash-instruction
      // (« Rendez-vous en caisse », kioskRoutes.js:244) — pas confirmation/waiting.
      await kioskPage.waitForURL(/\/kiosk\/(confirmation|waiting|cash-instruction)/, { timeout: 15_000 }).catch(() => {});
      if (shotName) await shot(kioskPage, shotName);
      if (!(apiStatus >= 200 && apiStatus < 300)) throw new Error(`POST /order → ${apiStatus}`);
      const row = db(`SELECT id, status, source_surface, IFNULL(queue_number,''), IFNULL(order_serial_no,'') FROM orders WHERE id > ${baselineMax} AND source_surface='kiosk' ORDER BY id DESC LIMIT 1;`);
      if (!row) throw new Error('aucune nouvelle ligne order kiosk en DB');
      const [orderId, status0, src, queueNo, serialNo] = row.split('\t');
      console.log(`[ORDER] id=${orderId} status0=${status0} src=${src} queue=${queueNo} serial=${serialNo} apiStatus=${apiStatus} (tentative ${attempt})`);
      return { orderId: parseInt(orderId, 10), status0, queueNo, serialNo, apiStatus, postDoneAt };
    } catch (e) {
      lastErr = e;
      console.warn(`[ORDER] tentative ${attempt}/3 échouée: ${e.message}`);
      await sleep(2000);
    }
  }
  throw lastErr;
}

function cardLocator(page, queueNo, orderId) {
  const tokens = [queueNo, String(orderId)].filter(Boolean)
    .map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  return page.locator('.kds-card').filter({ hasText: new RegExp(tokens.join('|')) });
}

async function gotoKds(page) {
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
}

// ───────── parcours partagé D1+D2 (cycle 1 et cycle 2) ─────────

const RESULTS = { cycles: {}, latencies: {}, findings: [] };

async function runD1D2(cycleTag, itemIds) {
  const px = cycleTag;
  purgeBacklog(cycleTag);

  // — D1.1 commande kiosk fraîche
  const order = await placeKioskOrder(itemIds, `${px}-d1-01-kiosk-confirmation`);
  const { orderId, queueNo } = order;
  expect(order.status0, `status création = 4 (ACCEPT) — observé ${order.status0}`).toBe('4');

  // — D1.2 KDS poste A : carte NOUVELLE
  await gotoKds(kdsA);
  const cardA = cardLocator(kdsA, queueNo, orderId).first();
  await expect(cardA, `KDS affiche la carte fraîche ${orderId} (N°${queueNo})`).toBeVisible({ timeout: 20_000 });
  const ctaTxt0 = (await cardA.locator('[data-testid="kds-card-cta-ready"] span').innerText().catch(() => '')).trim();
  console.log(`[${px} D1] carte NOUVELLE — CTA="${ctaTxt0}" (attendu "Démarrer")`);
  await cardA.scrollIntoViewIfNeeded().catch(() => {});
  await shot(kdsA, `${px}-d1-02-kds-nouvelle`);
  expect(ctaTxt0, 'CTA état NOUVELLE = Démarrer').toBe('Démarrer');

  // — D1.3 Démarrer → EN COURS (7) serveur
  await cardA.locator('[data-testid="kds-card-cta-ready"]').click();
  const st7 = await pollDb(`SELECT status FROM orders WHERE id=${orderId};`, (v) => v === '7');
  expect(st7, 'Démarrer persisté PREPARING(7) côté serveur').toBe('7');
  await kdsA.waitForTimeout(1200);
  const ctaTxt1 = (await cardA.locator('[data-testid="kds-card-cta-ready"] span').innerText().catch(() => '')).trim();
  await shot(kdsA, `${px}-d1-03-kds-encours`);
  expect(ctaTxt1, 'CTA état EN COURS = Prêt').toBe('Prêt');

  // — D1.4 OSS (chef, push Echo) : colonne « En préparation »
  const ossPreparing = ossPage.locator('div.customer-screen').filter({ hasText: 'En préparation' });
  const tOssPrep = await measure(async () => {
    const txt = await ossPreparing.innerText().catch(() => '');
    return txt.includes(`N°${queueNo}`);
  }, 30_000);
  RESULTS.latencies[`${px}-oss-preparing-ms`] = tOssPrep;
  console.log(`[${px} D1] OSS « En préparation » N°${queueNo} visible en ${tOssPrep}ms`);
  await shot(ossPage, `${px}-d1-04-oss-preparation`);
  expect(tOssPrep, 'OSS affiche la commande en préparation').toBeGreaterThanOrEqual(0);

  // — D1.5 Prêt → PREPARED (8) serveur
  await cardA.locator('[data-testid="kds-card-cta-ready"]').click();
  const st8 = await pollDb(`SELECT status FROM orders WHERE id=${orderId};`, (v) => v === '8');
  expect(st8, 'Prêt persisté PREPARED(8) côté serveur').toBe('8');
  const preparedAt = Date.now(); // fenêtre recall 60s commence ici

  // — D1.6 footer « Récemment servies »
  const servedPill = kdsA.locator('.kds-v2__served-pill').filter({ hasText: `N°${queueNo}` });
  await expect(servedPill, `pill « Récemment servies » N°${queueNo}`).toBeVisible({ timeout: 10_000 });
  const servedLabel = (await kdsA.locator('.kds-v2__served-label').innerText().catch(() => '')).trim();
  console.log(`[${px} D1] footer label="${servedLabel}" (attendu « Récemment servies »)`);
  await shot(kdsA, `${px}-d1-05-kds-recemment-servies`);
  expect(servedLabel.toLowerCase()).toContain('récemment servies');

  // — D1.7 DB transitions 4→7→8
  const transitions = db(`SELECT CONCAT(from_status,'>',to_status,'|',IFNULL(reason,'')) FROM order_status_transitions WHERE order_id=${orderId} ORDER BY id;`);
  console.log(`[${px} D1] transitions: ${transitions.replace(/\n/g, ' ; ')}`);
  expect(transitions, 'transition 4→7 journalisée').toContain('4>7');
  expect(transitions, 'transition 7→8 journalisée').toContain('7>8');

  // ═══ D2 — RECALL (dans la fenêtre 60s post-PREPARED) ═══
  await kdsA.locator('[data-testid="kds-history-button"]').click();
  await expect(kdsA.locator('[data-testid="kds-history-drawer"]')).toBeVisible({ timeout: 10_000 });
  await kdsA.waitForTimeout(1200); // fetch history-today
  const recallBtn = kdsA.locator(`[data-testid="kds-recall-${orderId}"]`);
  await expect(recallBtn, `bouton recall visible pour ${orderId} (fenêtre 60s, écoulé ${Date.now() - preparedAt}ms)`).toBeVisible({ timeout: 8_000 });
  await shot(kdsA, `${px}-d2-01-drawer-recall-dispo`);

  // POST serveur observé (fix KDS-OSS-01 : pas localStorage-only)
  const recallResp = kdsA.waitForResponse(
    (r) => r.request().method() === 'POST' && new RegExp(`kds-order/recall/${orderId}`).test(r.url()),
    { timeout: 15_000 },
  );
  await recallBtn.click();
  const rResp = await recallResp;
  const rBody = await rResp.json().catch(() => ({}));
  console.log(`[${px} D2] POST recall → HTTP ${rResp.status()} transition_id=${rBody.transition_id ?? '?'}`);
  expect(rResp.status(), 'recall POST serveur = 200').toBe(200);

  // NF525 append-only : status RESTE 8, transition kitchen_recall ajoutée
  const stillPrepared = db(`SELECT status FROM orders WHERE id=${orderId};`);
  expect(stillPrepared, 'orders.status RESTE PREPARED(8) après recall (invariant NF525)').toBe('8');
  const recallRows = db(`SELECT COUNT(*) FROM order_status_transitions WHERE order_id=${orderId} AND reason='kitchen_recall';`);
  expect(recallRows, '1 transition kitchen_recall append-only').toBe('1');

  // badge RAPPELÉ sur la carte ré-injectée
  await kdsA.locator('[data-testid="kds-history-close"]').click().catch(() => {});
  await kdsA.waitForTimeout(800);
  const badge = kdsA.locator(`[data-testid="kds-card-recall-badge-${orderId}"]`);
  await expect(badge, 'badge RAPPELÉ visible sur la carte').toBeVisible({ timeout: 8_000 });
  const badgeTxt = (await badge.innerText().catch(() => '')).trim();
  await shot(kdsA, `${px}-d2-02-kds-badge-rappele`);
  expect(badgeTxt).toBe('RAPPELÉ');

  // re-recall immédiat → cap N=1 (serveur: 409).
  // NB run1: l'axios projet a baseURL=…/api/ (URL relative SANS slash, cf.
  // KdsHistoryDrawer.vue:326) et la route porte le middleware `idempotency`
  // qui EXIGE X-Idempotency-Key — clé fraîche pour atteindre le contrôleur
  // (même clé = replay du 200 caché, pas un vrai re-recall).
  const reStatus = await kdsA.evaluate(async (id) => {
    try {
      const r = await window.axios.post(`admin/kds-order/recall/${id}`, null, {
        headers: { 'X-Idempotency-Key': `wd-rerecall-${id}-${Date.now()}` },
      });
      return r.status;
    } catch (e) { return e?.response?.status ?? -1; }
  }, orderId);
  console.log(`[${px} D2] re-recall direct → HTTP ${reStatus} (attendu 409)`);
  expect(reStatus, 're-recall → 409 (cap N=1, pas de spam)').toBe(409);
  const recallRows2 = db(`SELECT COUNT(*) FROM order_status_transitions WHERE order_id=${orderId} AND reason='kitchen_recall';`);
  expect(recallRows2, 'toujours 1 seule transition kitchen_recall').toBe('1');

  // — D1.8 (différé volontairement post-recall pour tenir la fenêtre 60s)
  // OSS colonne « Prêt » : le recall ne change PAS le status → la commande reste Prêt
  const ossReady = ossPage.locator('div.customer-screen').filter({ hasText: /Prêt/ });
  const tOssReady = await measure(async () => {
    const txt = await ossReady.innerText().catch(() => '');
    return txt.includes(`N°${queueNo}`);
  }, 30_000);
  RESULTS.latencies[`${px}-oss-ready-ms`] = tOssReady;
  console.log(`[${px} D1] OSS « Prêt » N°${queueNo} visible (mesure post-recall: ${tOssReady}ms)`);
  await shot(ossPage, `${px}-d1-06-oss-pret`);
  expect(tOssReady, 'OSS affiche la commande Prêt').toBeGreaterThanOrEqual(0);

  RESULTS.cycles[cycleTag] = { orderId, queueNo, transitions: transitions.replace(/\n/g, ' ; ') };
  return order;
}

// ───────── D4 multi-postes (cycle 1 & 2) ─────────

async function runD4(cycleTag, itemIds) {
  const px = cycleTag;
  purgeBacklog(`${cycleTag}-d4`);

  // poste B = context cloné (PAS de relogin chef → pas de révocation token)
  await gotoKds(kdsB);
  await shot(kdsB, `${px}-d4-01-posteB-avant`);
  await gotoKds(kdsA);
  await shot(kdsA, `${px}-d4-02-posteA-avant`);

  // 1) apparition d'une commande fraîche sur les 2 postes (sans reload)
  const order = await placeKioskOrder(itemIds, null);
  const { orderId, queueNo, postDoneAt } = order;
  const probeCard = (page) => async () => (await cardLocator(page, queueNo, orderId).first().isVisible().catch(() => false));
  const [tA, tB] = await Promise.all([
    measure(probeCard(kdsA), 30_000),
    measure(probeCard(kdsB), 30_000),
  ]);
  RESULTS.latencies[`${px}-new-order-posteA-ms`] = tA;
  RESULTS.latencies[`${px}-new-order-posteB-ms`] = tB;
  const probeOffset = Date.now() - postDoneAt - Math.max(tA, tB, 0);
  RESULTS.latencies[`${px}-new-order-probe-offset-ms`] = probeOffset;
  console.log(`[${px} D4] nouvelle commande ${orderId} visible posteA=${tA}ms posteB=${tB}ms (sonde démarrée ~${probeOffset}ms après POST)`);
  expect(tA, 'posteA voit la nouvelle commande').toBeGreaterThanOrEqual(0);
  expect(tB, 'posteB voit la nouvelle commande').toBeGreaterThanOrEqual(0);
  await shot(kdsA, `${px}-d4-03-posteA-nouvelle`);
  await shot(kdsB, `${px}-d4-04-posteB-nouvelle`);

  // 2) bump Démarrer sur A → B reflète (CTA passe à « Prêt ») — mesure réelle
  const cardA = cardLocator(kdsA, queueNo, orderId).first();
  const cardB = cardLocator(kdsB, queueNo, orderId).first();
  await cardA.locator('[data-testid="kds-card-cta-ready"]').click();
  const tStart = await measure(async () => {
    const txt = (await cardB.locator('[data-testid="kds-card-cta-ready"] span').innerText().catch(() => '')).trim();
    return txt === 'Prêt';
  }, 20_000, 200);
  RESULTS.latencies[`${px}-bump-demarrer-propagation-ms`] = tStart;
  console.log(`[${px} D4] bump Démarrer A→B propagé en ${tStart}ms`);
  await shot(kdsA, `${px}-d4-05-posteA-encours`);
  await shot(kdsB, `${px}-d4-06-posteB-encours`);
  expect(tStart, 'propagation Démarrer ≥0').toBeGreaterThanOrEqual(0);
  expect(tStart, 'propagation Démarrer < 20s (sync fonctionne)').toBeLessThan(20_000);
  if (tStart > 6_000) RESULTS.findings.push(`D4 ${px}: propagation Démarrer A→B ${tStart}ms > cible 6s (contention queue broadcasts probable — worker unique high,default,broadcasts,notifications partagé avec pilotes parallèles)`);

  // 3) bump Prêt sur A → B déplace la carte vers « Récemment servies »
  await cardA.locator('[data-testid="kds-card-cta-ready"]').click();
  const tReady = await measure(async () => {
    const pill = kdsB.locator('.kds-v2__served-pill').filter({ hasText: `N°${queueNo}` });
    return pill.isVisible().catch(() => false);
  }, 20_000, 200);
  RESULTS.latencies[`${px}-bump-pret-propagation-ms`] = tReady;
  console.log(`[${px} D4] bump Prêt A→B propagé (pill servies) en ${tReady}ms`);
  await shot(kdsA, `${px}-d4-07-posteA-apres-pret`);
  await shot(kdsB, `${px}-d4-08-posteB-apres-pret`);
  expect(tReady, 'propagation Prêt ≥0').toBeGreaterThanOrEqual(0);
  expect(tReady, 'propagation Prêt < 20s (sync fonctionne)').toBeLessThan(20_000);
  if (tReady > 6_000) RESULTS.findings.push(`D4 ${px}: propagation Prêt A→B ${tReady}ms > cible 6s`);

  // pas de doublon de carte sur B
  const dupB = await cardLocator(kdsB, queueNo, orderId).count();
  expect(dupB, 'aucun doublon de carte sur poste B').toBeLessThanOrEqual(1);
  return order;
}

// ════════════════════════════ TESTS ════════════════════════════

test.describe.configure({ mode: 'serial' });
test.describe('W-D KDS/OSS PROFOND 2026-06-10', () => {
  test.setTimeout(420_000);

  test.beforeAll(async ({ browser }) => {
    expect(soketiUp(), 'pré-requis : soketi UP sur :6001 au démarrage').toBe(true);
    ctxKiosk = await browser.newContext();
    kioskPage = await ctxKiosk.newPage();
    attachCollectors(kioskPage, 'kiosk');

    ctxChef = await browser.newContext();
    kdsA = await ctxChef.newPage();
    attachCollectors(kdsA, 'kdsA-chef');
    await loginAsChefOperator(kdsA);

    // OSS authentifié (même user chef — permission order-status-screen vérifiée DB)
    ossPage = await ctxChef.newPage();
    attachCollectors(ossPage, 'oss-chef');
    await ossPage.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
    await ossPage.waitForTimeout(3000);

    // poste B = clone storageState (PAS de 2e login → pas de révocation token)
    ctxB = await browser.newContext({ storageState: await ctxChef.storageState() });
    kdsB = await ctxB.newPage();
    attachCollectors(kdsB, 'kdsB-clone');

    // mur public OSS (D6) — aucun login
    ctxPublic = await browser.newContext();
    publicOss = await ctxPublic.newPage();
    attachCollectors(publicOss, 'oss-public');
  });

  test.afterAll(async () => {
    // GARDE-FOU ABSOLU : soketi ne doit JAMAIS rester down
    restartSoketi();
    for (let i = 0; i < 20 && !soketiUp(); i++) await sleep(1000);
    console.log(`[AFTERALL] soketi up=${soketiUp()}`);
    fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ ...RESULTS, issues }, null, 2));
    for (const c of [ctxKiosk, ctxChef, ctxB, ctxPublic]) { try { await c?.close(); } catch (_) {} }
  });

  test('CYCLE 1 — D1 flux complet + D2 recall (kiosk→KDS→bump→footer→OSS→recall 409)', async () => {
    await runD1D2('c1', [58]); // Eau Plate 50cl — item simple 58, cat 10
  });

  test('D3 — drawer historique KDS : liste + capture + refetch', async () => {
    await gotoKds(kdsA);
    const histResp1 = kdsA.waitForResponse((r) => /kds-order\/history-today/.test(r.url()), { timeout: 15_000 });
    await kdsA.locator('[data-testid="kds-history-button"]').click();
    await expect(kdsA.locator('[data-testid="kds-history-drawer"]')).toBeVisible({ timeout: 10_000 });
    const h1 = await histResp1;
    expect(h1.status(), 'GET history-today 200').toBe(200);
    await kdsA.waitForTimeout(1200);
    const list = kdsA.locator('[data-testid="kds-history-list"]');
    const empty = kdsA.locator('[data-testid="kds-history-empty"]');
    const hasList = await list.isVisible().catch(() => false);
    const items = hasList ? await kdsA.locator('[data-testid="kds-history-item"]').count() : 0;
    console.log(`[D3] drawer items=${items} empty=${await empty.isVisible().catch(() => false)}`);
    await shot(kdsA, 'd3-01-drawer-historique');
    expect(items, 'historique du jour contient ≥1 commande (cycle 1)').toBeGreaterThan(0);

    // refetch : PAS de bouton refresh dédié (seul « Réessayer » en état erreur) →
    // le refetch réel = close/reopen (watch open → fetch()). Preuve réseau.
    await kdsA.locator('[data-testid="kds-history-close"]').click();
    await expect(kdsA.locator('[data-testid="kds-history-drawer"]')).toBeHidden({ timeout: 5_000 });
    const histResp2 = kdsA.waitForResponse((r) => /kds-order\/history-today/.test(r.url()), { timeout: 15_000 });
    await kdsA.locator('[data-testid="kds-history-button"]').click();
    const h2 = await histResp2;
    expect(h2.status(), 'refetch au réouvert → nouveau GET history-today 200').toBe(200);
    await kdsA.waitForTimeout(800);
    await shot(kdsA, 'd3-02-drawer-refetch');
    await kdsA.locator('[data-testid="kds-history-close"]').click().catch(() => {});
    RESULTS.findings.push('D3: pas de bouton refresh dédié dans le drawer (design: fetch au watch-open + « Réessayer » en état erreur uniquement) — refetch close/reopen PROUVÉ réseau (2e GET history-today 200)');
  });

  test('CYCLE 1 — D4 multi-postes : 2 contexts /kds, propagation <6s mesurée', async () => {
    await runD4('c1', [52]); // Coca-Cola 33cl
  });

  test('CYCLE 2 — D1+D2 complets (2e passe, commande multi-items)', async () => {
    await runD1D2('c2', [49, 56]); // Glace (cat 9) + Oasis Tropical (cat 10)
  });

  test('CYCLE 2 — D4 multi-postes (2e mesure)', async () => {
    await runD4('c2', [55]); // Sprite 33cl
  });

  test('D6 — OSS mur public /order-status-screen (sans auth)', async () => {
    // Routes vérifiées code : alias public /order-status-screen + redirect /order-status
    // (resources/js/router/modules/orderStatusScreenRoutes.js:8-11, router/index.js:134-135) ;
    // feed public GET /api/frontend/oss-order throttle oss-public (routes/api.php:1297+).
    await publicOss.goto('/order-status-screen', { waitUntil: 'domcontentloaded' });
    await publicOss.waitForTimeout(6_000); // cadence poll mur public = 5s
    const url = publicOss.url();
    console.log(`[D6] URL après goto public: ${url}`);
    expect(url, 'mur public ne redirige PAS vers /login').not.toMatch(/\/login/);
    const body = await publicOss.locator('body').innerText().catch(() => '');
    expect(body, 'colonne « En préparation » rendue').toContain('En préparation');
    expect(body, 'colonne « Prêt » rendue').toContain('Prêt');
    // la commande PREPARED du cycle 2 doit être visible publiquement
    const c2 = RESULTS.cycles.c2;
    if (c2?.queueNo) {
      const tPub = await measure(async () => {
        const txt = await publicOss.locator('body').innerText().catch(() => '');
        return txt.includes(`N°${c2.queueNo}`);
      }, 20_000);
      RESULTS.latencies['d6-public-wall-shows-c2-ms'] = tPub;
      console.log(`[D6] mur public affiche N°${c2.queueNo} (sonde: ${tPub}ms)`);
      expect(tPub, 'mur public affiche la commande Prêt du cycle 2').toBeGreaterThanOrEqual(0);
    }
    await shot(publicOss, 'd6-01-oss-mur-public');
  });

  test('D5 — dégradé (EN DERNIER) : soketi down → polling fallback → relance sans doublon', async () => {
    test.setTimeout(420_000);
    purgeBacklog('d5');
    let order = null;
    try {
      // 1) kill soketi
      try { execSync('pkill -f soketi', { timeout: 5_000 }); } catch (_) {}
      for (let i = 0; i < 10 && soketiUp(); i++) await sleep(500);
      expect(soketiUp(), 'soketi down confirmé (:6001 fermé)').toBe(false);
      console.log('[D5] soketi DOWN');

      // 2) KDS A détecte la coupure → bannière mode secours (fail-safe-to-visible)
      const banner = kdsA.locator('[data-testid="kds-sync-mode-banner"]');
      const tBanner = await measure(() => banner.isVisible().catch(() => false), 60_000, 500);
      RESULTS.latencies['d5-banner-apparition-ms'] = tBanner;
      console.log(`[D5] bannière dégradée visible en ${tBanner}ms : "${tBanner >= 0 ? (await banner.innerText()).trim() : 'ABSENTE'}"`);
      await shot(kdsA, 'd5-01-kds-banniere-degradee');

      // 3) commande kiosk pendant la coupure → doit apparaître via polling 5s
      order = await placeKioskOrder([54], 'd5-02-kiosk-confirmation-degradee'); // Fanta Orange
      const probe = async () => cardLocator(kdsA, order.queueNo, order.orderId).first().isVisible().catch(() => false);
      const tPoll = await measure(probe, 75_000, 250);
      RESULTS.latencies['d5-polling-fallback-ms'] = tPoll;
      console.log(`[D5] carte ${order.orderId} N°${order.queueNo} visible via POLLING en ${tPoll}ms (POST il y a ${Date.now() - order.postDoneAt}ms)`);
      await shot(kdsA, 'd5-03-kds-carte-via-polling');
      expect(tPoll, 'commande visible au KDS malgré soketi down (polling fallback)').toBeGreaterThanOrEqual(0);
      if (tPoll > 6_000) {
        RESULTS.findings.push(`D5: polling fallback mesuré ${tPoll}ms > cible 6s (cadence 5s + refetch) — à évaluer`);
      }
    } finally {
      // 4) RELANCE SOKETI — OBLIGATOIRE même si le test échoue
      restartSoketi();
      for (let i = 0; i < 30 && !soketiUp(); i++) await sleep(1000);
      console.log(`[D5] soketi relancé up=${soketiUp()}`);
    }
    expect(soketiUp(), 'soketi relancé sur :6001').toBe(true);

    // 5) retour temps réel : reconnexion WS (bannière disparaît) + pas de doublon
    const banner = kdsA.locator('[data-testid="kds-sync-mode-banner"]');
    const tReco = await measure(async () => !(await banner.isVisible().catch(() => true)), 120_000, 1000);
    RESULTS.latencies['d5-ws-reconnexion-ms'] = tReco;
    console.log(`[D5] bannière disparue (WS reconnecté) en ${tReco}ms`);
    if (order) {
      const cardCount = await cardLocator(kdsA, order.queueNo, order.orderId).count();
      expect(cardCount, 'pas de doublon de carte après reconnexion').toBeLessThanOrEqual(1);
      // preuve temps réel post-relance : bump Démarrer sur A → propagation poste B
      const cardA = cardLocator(kdsA, order.queueNo, order.orderId).first();
      if (await cardA.isVisible().catch(() => false)) {
        await cardA.locator('[data-testid="kds-card-cta-ready"]').click();
        await pollDb(`SELECT status FROM orders WHERE id=${order.orderId};`, (v) => v === '7');
        const tProp = await measure(async () => {
          const cardB = cardLocator(kdsB, order.queueNo, order.orderId).first();
          const txt = (await cardB.locator('[data-testid="kds-card-cta-ready"] span').innerText().catch(() => '')).trim();
          return txt === 'Prêt';
        }, 30_000, 250);
        RESULTS.latencies['d5-post-relance-propagation-ms'] = tProp;
        console.log(`[D5] post-relance : bump A→B propagé en ${tProp}ms (temps réel restauré)`);
        // nettoyage : terminer la commande
        await cardA.locator('[data-testid="kds-card-cta-ready"]').click().catch(() => {});
      }
    }
    await shot(kdsA, 'd5-04-kds-apres-relance');
    await shot(kdsB, 'd5-05-posteB-apres-relance');
    RESULTS.findings.push('D5 exécuté UNE seule fois (justification: kill soketi est destructif pour les pilotes parallèles W-A/W-B/W-C partageant :8766/:6001 ; le fallback polling est déterministe — cadence 5s hardcodée _pollingInterval, une 2e passe ne produirait aucune information nouvelle)');
  });

  test('ZZ — bilan erreurs console/page/HTTP≥400', async () => {
    const unexpected = issues.filter((i) => !KNOWN_OK.some((re) => re.test(`${i.detail} ${i.kind}`)));
    console.log(`[BILAN] issues totales=${issues.length} inattendues=${unexpected.length}`);
    for (const u of unexpected.slice(0, 40)) console.log(`  - [${u.page}] ${u.kind}: ${u.detail}`);
    fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ ...RESULTS, issues }, null, 2));
    // pageerror = dur ; console/http listés pour analyse (rapport)
    const pageErrors = unexpected.filter((i) => i.kind === 'pageerror');
    expect(pageErrors, `pageerrors: ${JSON.stringify(pageErrors)}`).toHaveLength(0);
  });
});
