// test-e2e goal4-predeploy-2026-07-17 — Wave C (CŒUR smoke), Round 1.
// GStack capture agent. Spec de smoke NON-mutant : rendu + raw-labels + quartet.
//
// États (PLAN.md Wave C, viewport 1440×900) :
//   C01 /login                      (page rendue, #formEmail)
//   C02 /admin/pos                  (loginAsPosOperator → panneau ticket pos-grand-total)
//   C03 /kds                        (loginAsChefOperator → surface KDS ; KdsV2Grid est
//                                    v-if=useV2Layout → ancre = .kds-history-trigger-row OU .kds-v2)
//   C04 /admin/order-status-screen  (admin → role=main + colonnes En préparation / Prêt)
//   C05 /admin/items                (admin → data-testid=admin-items-list)
//   C06 /admin/stock/rupture        (admin → data-testid=stock-management-v2)
//
// Checks par état :
//   - snap quartet AVANT les asserts (capture même en échec) via mega-audit-snap
//   - élément structurant présent
//   - raw labels : kiosk\.[a-z] | Label\.[A-Za-z] | {{ | \bundefined\b sur body.innerText
//     ('undefined' : contexte ±60 chars capturé dans le message pour vérif humaine —
//      word-boundary évite les matches intra-mot ; UI FR ne contient pas ce mot légitimement)
//   - erreurs console hors vendor + réponses 4xx/5xx : NOTÉES (JSON + stdout), PAS d'assert
//
// Interdits respectés : aucun fichier app modifié, aucun paiement finalisé, aucune mutation.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const {
  loginAsPosOperator,
  loginAsChefOperator,
  loginAsAdmin,
} = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-C');
const SETTLE_MS = 2500;

test.use({ viewport: { width: 1440, height: 900 } });
test.describe.configure({ retries: 0 });

// ---------------------------------------------------------------------------
// Anomalies (console hors vendor + HTTP>=400) — notées, jamais bloquantes.
// ---------------------------------------------------------------------------
const anomalies = []; // { state, kind, detail }

// Même filtre de bruit bénin que mega-audit-snap (WS/Pusher retry sans broker).
const NOISE_TEXT_PATTERNS = [
  /WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i,
  /^Pusher\s*:\s*/i,
];
const VENDOR_URL_RE = /\/vendor\/|node_modules|cdn\.|googleapis|gstatic/i;

function attachAnomalyListeners(page, stateRef) {
  page.on('console', (msg) => {
    try {
      if (msg.type() !== 'error') return;
      const text = msg.text();
      if (NOISE_TEXT_PATTERNS.some((rx) => rx.test(text))) return;
      const loc = msg.location() || {};
      if (VENDOR_URL_RE.test(String(loc.url || ''))) return; // vendor → hors périmètre
      anomalies.push({
        state: stateRef.current,
        kind: 'console-error',
        detail: `${text.slice(0, 300)} @ ${String(loc.url || '').slice(0, 120)}`,
      });
    } catch (_e) { /* noop */ }
  });
  page.on('pageerror', (err) => {
    anomalies.push({
      state: stateRef.current,
      kind: 'pageerror',
      detail: String(err && err.message ? err.message : err).slice(0, 300),
    });
  });
  page.on('response', (resp) => {
    try {
      const s = resp.status();
      if (s >= 400) {
        anomalies.push({
          state: stateRef.current,
          kind: `http-${s}`,
          detail: `${resp.request().method()} ${resp.url().slice(0, 200)}`,
        });
      }
    } catch (_e) { /* noop */ }
  });
}

function flushAnomalies() {
  try {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_waveC-anomalies.json'),
      JSON.stringify(anomalies, null, 2),
    );
  } catch (_e) { /* noop */ }
  if (anomalies.length) {
    console.log(`[waveC][NOTE] ${anomalies.length} anomalie(s) non-bloquante(s) :`);
    for (const a of anomalies) {
      console.log(`  [${a.state}] ${a.kind} — ${a.detail}`);
    }
  } else {
    console.log('[waveC][NOTE] 0 anomalie console/réseau hors vendor.');
  }
}

// ---------------------------------------------------------------------------
// Raw-label audit sur body.innerText.
// ---------------------------------------------------------------------------
const RAW_LABEL_PATTERNS = [
  { name: 'kiosk.<key>', rx: /kiosk\.[a-z]/ },
  { name: 'Label.<Key>', rx: /Label\.[A-Za-z]/ },
  { name: 'mustache {{', rx: /\{\{/ },
  { name: 'undefined', rx: /\bundefined\b/i },
];

async function assertNoRawLabels(page, stateName) {
  const text = await page.evaluate(() => document.body.innerText || '');
  const hits = [];
  for (const p of RAW_LABEL_PATTERNS) {
    const m = text.match(p.rx);
    if (m) {
      const idx = typeof m.index === 'number' ? m.index : text.indexOf(m[0]);
      const ctx = text
        .slice(Math.max(0, idx - 60), idx + m[0].length + 60)
        .replace(/\s+/g, ' ')
        .trim();
      hits.push(`${p.name} → "${m[0]}" dans « …${ctx}… »`);
    }
  }
  // expect.soft : l'échec est enregistré, les états suivants capturent quand même.
  expect
    .soft(hits, `[${stateName}] raw label(s) détecté(s) : ${hits.join(' | ')}`)
    .toEqual([]);
}

// Structural presence : snap d'abord (dans les tests), assert ensuite.
async function waitStructural(page, selector, timeout = 25_000) {
  await page
    .waitForSelector(selector, { state: 'attached', timeout })
    .catch(() => {}); // le count assert ci-après porte l'échec avec un message clair
  return page.locator(selector).count();
}

test.describe('Wave C — CŒUR smoke goal4-predeploy (C01→C06)', () => {
  test.setTimeout(300_000);

  test.beforeAll(() => {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  });

  test.afterAll(() => {
    flushAnomalies();
  });

  // -------------------------------------------------------------------------
  // C01 — /login rendu (sans auth)
  // -------------------------------------------------------------------------
  test('C01 — /login page rendue', async ({ page }) => {
    const stateRef = { current: 'C01' };
    attachAnomalyListeners(page, stateRef);
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    const count = await waitStructural(page, '#formEmail');
    await page.waitForTimeout(800); // settle court : page légère
    await snap('C01-login');

    expect(count, 'C01 : champ #formEmail (LoginComponent) absent').toBeGreaterThan(0);
    await expect(page.locator('#formPassword')).toBeVisible();
    await assertNoRawLabels(page, 'C01');
  });

  // -------------------------------------------------------------------------
  // C02 — /admin/pos après loginAsPosOperator
  // -------------------------------------------------------------------------
  test('C02 — /admin/pos chargé (POS operator)', async ({ page }) => {
    const stateRef = { current: 'C02' };
    attachAnomalyListeners(page, stateRef);
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    await loginAsPosOperator(page); // finit assert URL /admin/pos
    // Ancre : panneau ticket (toujours dans le DOM une fois le POS monté) ;
    // le modal « Ouvrir la caisse » peut s'auto-ouvrir par-dessus — état valide.
    const count = await waitStructural(
      page,
      '[data-testid="pos-grand-total"], [role="dialog"]',
    );
    await page.waitForTimeout(SETTLE_MS);
    await snap('C02-pos');

    await expect(page).toHaveURL(/\/admin\/pos/);
    expect(
      count,
      'C02 : ni panneau ticket (pos-grand-total) ni modal caisse — POS non monté',
    ).toBeGreaterThan(0);
    await assertNoRawLabels(page, 'C02');
  });

  // -------------------------------------------------------------------------
  // C03 — /kds après loginAsChefOperator
  // -------------------------------------------------------------------------
  test('C03 — /kds (chef)', async ({ page }) => {
    const stateRef = { current: 'C03' };
    attachAnomalyListeners(page, stateRef);
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    await loginAsChefOperator(page); // finit sur une surface KDS
    // Honore le PLAN : passer par /kds (redirige vers admin.kitchen-display-system).
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    const count = await waitStructural(page, '.kds-history-trigger-row, .kds-v2');
    await page.waitForTimeout(SETTLE_MS);
    await snap('C03-kds');

    await expect(page).toHaveURL(/\/(kds|admin\/kitchen-display-system)(\/|$|\?)/i);
    expect(
      count,
      'C03 : ni .kds-history-trigger-row ni .kds-v2 — board KDS non monté',
    ).toBeGreaterThan(0);
    await assertNoRawLabels(page, 'C03');
  });

  // -------------------------------------------------------------------------
  // C04 + C05 + C06 — surfaces admin (une seule session loginAsAdmin).
  // expect.soft sur chaque état pour que les 3 capturent même si l'un échoue.
  // -------------------------------------------------------------------------
  test('C04-C06 — OSS + items + stock/rupture (admin)', async ({ page }) => {
    const stateRef = { current: 'C04' };
    attachAnomalyListeners(page, stateRef);
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    await loginAsAdmin(page);

    // --- C04 /admin/order-status-screen ------------------------------------
    stateRef.current = 'C04';
    await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
    const ossCount = await waitStructural(page, 'div[role="main"]');
    await page.waitForTimeout(SETTLE_MS);
    await snap('C04-oss');
    expect
      .soft(ossCount, 'C04 : conteneur role="main" OSS absent')
      .toBeGreaterThan(0);
    // Colonnes client : « En préparation » (label.preparing fr.json:692) + « Prêt ».
    const ossText = await page.evaluate(() => document.body.innerText || '');
    expect
      .soft(
        /en préparation|prêt/i.test(ossText),
        'C04 : colonnes "En préparation"/"Prêt" introuvables dans le texte OSS',
      )
      .toBe(true);
    await assertNoRawLabels(page, 'C04');

    // --- C05 /admin/items ---------------------------------------------------
    stateRef.current = 'C05';
    await page.goto('/admin/items', { waitUntil: 'domcontentloaded' });
    const itemsCount = await waitStructural(page, '[data-testid="admin-items-list"]');
    await page.waitForTimeout(SETTLE_MS);
    await snap('C05-items');
    expect
      .soft(itemsCount, 'C05 : data-testid="admin-items-list" absent — catalogue non rendu')
      .toBeGreaterThan(0);
    await assertNoRawLabels(page, 'C05');

    // --- C06 /admin/stock/rupture -------------------------------------------
    stateRef.current = 'C06';
    await page.goto('/admin/stock/rupture', { waitUntil: 'domcontentloaded' });
    const stockCount = await waitStructural(page, '[data-testid="stock-management-v2"]');
    await page.waitForTimeout(SETTLE_MS);
    await snap('C06-stock-rupture');
    expect
      .soft(stockCount, 'C06 : data-testid="stock-management-v2" absent — dashboard rupture non rendu')
      .toBeGreaterThan(0);
    await assertNoRawLabels(page, 'C06');
  });
});
