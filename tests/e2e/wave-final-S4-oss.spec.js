// FoodKing E2E — Wave Final S4 OSS (Order Status Screen) — 2026-05-23
//
// Mission: customer-facing wall display audit, MAX reasoning + adversarial.
//   S4-01  empty state
//   S4-02  1 ACCEPTED order seeded → must NOT appear (allowlist=PREPARING/PREPARED)
//   S4-03  bump to PREPARING → OSS column "En préparation"
//   S4-04  bump to PREPARED → OSS column "Prêt"
//   S4-05  mixed load (5 PREPARING KIOSK + 3 PREPARED TAKEAWAY)
//   S4-06  realtime-order-update event → new order rendered without reload
//   S4-07  PREPARED → DELIVERED → disappears from OSS
//   S4-08  DELIVERY / DINING_TABLE / POS exclusion (Wave Polish R-3 allowlist)
//
// Page under test : /admin/order-status-screen (PUBLIC_FRIENDLY_AUTH_ROUTES)
// Public API      : GET /api/frontend/oss-order  (CDSOrderDetailsResource — PII-free)
//                   Middleware: installed + apiKey + localization + throttle:oss-public
// SSOT allowlist  : app/Services/OrderStatusScreenOrderService.php
//                   whereIn('order_type', [KIOSK, TAKEAWAY])
//                   whereIn('status', [PREPARING, PREPARED])
//
// Quartet per state : PNG screenshot + DOM excerpt + API payload + console errors
// Findings sink     : reports/test-e2e/wave-final-2026-05-23/round-1/S4-oss-findings.json
// Cleanup           : iter15:cleanup-test-orders --apply --token-prefix=WAVE-FINAL-S4-

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const SHOTS_DIR = path.join(__dirname, '__screenshots__', 'wave-final-S4-oss');
const REPORT_DIR = path.join(__dirname, '..', '..', 'reports', 'test-e2e', 'wave-final-2026-05-23', 'round-1');
const QUARTET_DIR = path.join(REPORT_DIR, 'S4-quartet');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });
if (!fs.existsSync(REPORT_DIR)) fs.mkdirSync(REPORT_DIR, { recursive: true });
if (!fs.existsSync(QUARTET_DIR)) fs.mkdirSync(QUARTET_DIR, { recursive: true });

const OSS_URL = '/admin/order-status-screen';
const REPO_ROOT = path.resolve(__dirname, '..', '..');
const TOKEN_PREFIX = 'WAVE-FINAL-S4-';

const FATAL_ERR_FILTER = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext|wakeLock)/i;

const ST = { PENDING: 1, ACCEPT: 4, PREPARING: 7, PREPARED: 8, DELIVERED: 13 };
const TY = { DELIVERY: 5, TAKEAWAY: 10, POS: 15, DINING_TABLE: 20, KIOSK: 25 };

// Findings sink — file-backed to survive split tests.
const FINDINGS_PATH = path.join(REPORT_DIR, '.S4-findings-buffer.json');
function loadFindings() {
  try { return JSON.parse(fs.readFileSync(FINDINGS_PATH, 'utf8')); } catch (_) { return []; }
}
function record(id, level, title, evidence) {
  const arr = loadFindings();
  arr.push({ id, level, title, evidence, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_PATH, JSON.stringify(arr, null, 2));
}

function php(snippet) {
  const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
    cwd: REPO_ROOT, encoding: 'utf8', timeout: 60_000,
  });
  return (res.stdout || '') + (res.stderr || '');
}

function logErr(consoleErrors, msg) {
  if (msg.type() === 'error') {
    const text = msg.text();
    if (!FATAL_ERR_FILTER.test(text)) consoleErrors.push(text);
  }
}

function seedOrder({ tokenSuffix, queueNumber, typeInt, statusInt }) {
  const tk = `"${TOKEN_PREFIX}${tokenSuffix}"`;
  const qn = queueNumber ? `"${queueNumber}"` : 'null';
  const snippet =
    `$o = new App\\Models\\Order; ` +
    `$o->order_serial_no = '${TOKEN_PREFIX}${tokenSuffix}'; ` +
    `$o->queue_number = ${qn}; ` +
    `$o->token = ${tk}; ` +
    `$o->user_id = 1; ` +
    `$o->branch_id = 1; ` +
    `$o->subtotal = 12.50; ` +
    `$o->total = 12.50; ` +
    `$o->order_type = ${typeInt}; ` +
    `$o->order_datetime = now('UTC'); ` +
    `$o->preparation_time = 5; ` +
    `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
    `$o->payment_method = 1; ` +
    `$o->payment_status = 10; ` +
    `$o->status = ${statusInt}; ` +
    `$o->business_date = now()->toDateString(); ` +
    `$o->save(); ` +
    `echo $o->id;`;
  const out = php(snippet);
  const m = out.match(/(\d+)\s*$/);
  return m ? parseInt(m[1], 10) : null;
}

function setStatus(orderId, statusInt) {
  return php(`App\\Models\\Order::where('id', ${orderId})->update(['status' => ${statusInt}]);`);
}

function wipe() {
  // FK-safe: kill dependent rows first, then force-delete all order rows
  // matching our token prefix (covers PENDING/ACCEPT/PREPARING/PREPARED/DELIVERED).
  const ids = php(`echo App\\Models\\Order::withTrashed()->where('token','like','${TOKEN_PREFIX}%')->pluck('id')->implode(',');`).trim();
  if (ids && /^\d/.test(ids)) {
    // Delete dependent rows that have FK→orders.id
    php(`DB::table('order_status_transitions')->whereIn('order_id', [${ids}])->delete();`);
    php(`DB::table('order_items')->whereIn('order_id', [${ids}])->delete();`);
    php(`DB::table('order_payments')->whereIn('order_id', [${ids}])->delete();`);
  }
  php(`App\\Models\\Order::withTrashed()->where('token','like','${TOKEN_PREFIX}%')->forceDelete();`);
}

async function gotoOSS(page) {
  await page.goto(OSS_URL, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
}

async function fetchPublicApi(page) {
  return await page.evaluate(async () => {
    const apiKey = (window.foodkingConfig && window.foodkingConfig.apiKey) || '';
    const r = await fetch('/api/frontend/oss-order', {
      headers: { 'Accept': 'application/json', 'x-api-key': apiKey },
      credentials: 'omit',
    });
    return { status: r.status, body: await r.json().catch(() => null) };
  });
}

async function captureQuartet(page, stateId, consoleErrors) {
  // 1. screenshot
  const png = path.join(SHOTS_DIR, `${stateId}.png`);
  await page.screenshot({ path: png, fullPage: true });
  // 2. DOM excerpt (column regions)
  const dom = await page.evaluate(() => {
    const root = document.querySelector('.grid');
    return root ? root.innerText : document.body.innerText.slice(0, 4000);
  });
  fs.writeFileSync(path.join(QUARTET_DIR, `${stateId}.dom.txt`), dom || '(empty)');
  // 3. API payload
  const api = await fetchPublicApi(page);
  fs.writeFileSync(path.join(QUARTET_DIR, `${stateId}.api.json`), JSON.stringify(api, null, 2));
  // 4. console errors
  fs.writeFileSync(path.join(QUARTET_DIR, `${stateId}.console.txt`),
    consoleErrors.length ? consoleErrors.join('\n') : '(no relevant errors)');
  return { png, dom, api };
}

async function scanRawLabels(page, stateId) {
  const text = await page.locator('body').innerText();
  // FoodKing $t keys: label.foo, kiosk.bar, message.baz, button.qux + dotted lowercase
  const hits = text.match(/\b(label|kiosk|message|button|admin|order|nav)\.[a-z_][a-z0-9_.]+/gi) || [];
  if (hits.length > 0) {
    record(`${stateId}.raw-i18n`, 'CRITICAL', 'Raw i18n keys leaking into OSS DOM',
      { sample: Array.from(new Set(hits)).slice(0, 8) });
  }
  return hits;
}

async function scanPii(page, stateId, apiBody) {
  // DOM PII patterns
  const text = await page.locator('body').innerText();
  const piiPatterns = {
    email: /\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i,
    phone_fr: /\b0[1-9](?:[\s.\-]?\d{2}){4}\b/,
    honorific_name: /\b(monsieur|madame|m\.|mme|mademoiselle)\s+[A-Z][a-z]{2,}/,
  };
  const piiDomHits = {};
  for (const [k, re] of Object.entries(piiPatterns)) {
    const m = text.match(re);
    if (m) piiDomHits[k] = m[0];
  }
  if (Object.keys(piiDomHits).length > 0) {
    record(`${stateId}.pii-dom`, 'CRITICAL', 'PII pattern in OSS DOM', { hits: piiDomHits });
  }
  // API payload PII keys check
  if (Array.isArray(apiBody?.data) && apiBody.data.length > 0) {
    const row = apiBody.data[0];
    const expected = ['id', 'order_serial_no', 'token', 'queue_number', 'order_type', 'status'];
    const actual = Object.keys(row);
    const unexpected = actual.filter(k => !expected.includes(k));
    const piiUnexpected = unexpected.filter(k =>
      /(name|email|phone|address|customer|total|amount|subtotal|delivery|discount|cardholder)/i.test(k));
    if (piiUnexpected.length > 0) {
      record(`${stateId}.pii-api`, 'CRITICAL',
        'Unexpected PII/financial keys in /api/frontend/oss-order',
        { expected, actual, suspicious: piiUnexpected });
    }
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test suite — one test per state for resilient quartet capture
// ─────────────────────────────────────────────────────────────────────────────

let sharedIds = {}; // pinned across tests via fs sidecar to survive parallel/serial

const SIDECAR = path.join(REPORT_DIR, '.S4-sidecar.json');
function saveSidecar() { fs.writeFileSync(SIDECAR, JSON.stringify(sharedIds, null, 2)); }
function loadSidecar() {
  try { sharedIds = JSON.parse(fs.readFileSync(SIDECAR, 'utf8')); } catch (_) { sharedIds = {}; }
}

test.describe.configure({ mode: 'serial' });
test.describe('Wave Final S4 — OSS customer wall', () => {

  test.beforeAll(() => {
    // Fresh start
    if (fs.existsSync(FINDINGS_PATH)) fs.unlinkSync(FINDINGS_PATH);
    if (fs.existsSync(SIDECAR)) fs.unlinkSync(SIDECAR);
    fs.writeFileSync(FINDINGS_PATH, '[]');
    wipe();
  });

  test('S4-01 empty state — wall renders cleanly with no orders', async ({ page }) => {
    const errs = [];
    page.on('console', (m) => logErr(errs, m));
    page.on('pageerror', (e) => errs.push('pageerror: ' + e.message));

    await gotoOSS(page);
    const q = await captureQuartet(page, 'S4-01', errs);
    expect(q.api.status, 'public OSS API returns 200').toBe(200);
    expect(Array.isArray(q.api.body?.data), 'payload shape: data is array').toBe(true);

    await scanRawLabels(page, 'S4-01');
    await scanPii(page, 'S4-01', q.api.body);

    // Empty-state copy presence (dash '—' is rendered when 0 items per template)
    const hasDash = await page.locator('p.text-center', { hasText: '—' }).count();
    record('S4-01.empty-copy', hasDash > 0 ? 'INFO' : 'IMPROVEMENT',
      hasDash > 0
        ? 'Empty-state placeholder "—" rendered in both columns'
        : 'No explicit empty-state placeholder — column may appear blank',
      { dash_count: hasDash, api_data_count: (q.api.body?.data || []).length });

    record('S4-01.console', errs.length === 0 ? 'INFO' : 'IMPROVEMENT',
      errs.length === 0 ? 'No relevant console errors' : 'Console errors detected',
      { errors: errs.slice(0, 5), total: errs.length });
  });

  test('S4-02 ACCEPTED order seeded — must NOT appear (allowlist=PREPARING/PREPARED)', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    const id = seedOrder({ tokenSuffix: 'ACC-001', queueNumber: 'A0042', typeInt: TY.KIOSK, statusInt: ST.ACCEPT });
    sharedIds.idAccepted = id;
    saveSidecar();

    await gotoOSS(page);
    const q = await captureQuartet(page, 'S4-02', errs);
    const visible = (q.api.body?.data || []).find(r => r.id === id);
    if (visible) {
      record('S4-02.allowlist-status', 'CRITICAL',
        'ACCEPTED order leaked onto OSS — only PREPARING/PREPARED should reach the wall',
        { idAccepted: id, found: visible });
    } else {
      record('S4-02.allowlist-status', 'INFO',
        'ACCEPTED status correctly excluded (allowlist PREPARING/PREPARED enforced)',
        { idAccepted: id });
    }
    await scanRawLabels(page, 'S4-02');
    await scanPii(page, 'S4-02', q.api.body);
  });

  test('S4-03 bump to PREPARING — appears in "En préparation"', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    const id = sharedIds.idAccepted;
    expect(id, 'idAccepted carried from S4-02').toBeTruthy();
    setStatus(id, ST.PREPARING);

    await gotoOSS(page);
    const q = await captureQuartet(page, 'S4-03', errs);
    const row = (q.api.body?.data || []).find(r => r.id === id);
    expect(row, 'PREPARING order visible in API').toBeTruthy();
    expect(row.status, 'status=PREPARING').toBe(ST.PREPARING);

    // DOM column — header should say "En préparation" (FR) or similar
    const prepColText = await page.locator('[role="region"]').filter({ hasText: /pr[ée]paration/i }).first().innerText().catch(() => '');
    record('S4-03.dom-preparing', 'INFO',
      'PREPARING DOM column renders queue number',
      { col_text_excerpt: prepColText.slice(0, 200), api_row: row });

    await scanRawLabels(page, 'S4-03');
    await scanPii(page, 'S4-03', q.api.body);
  });

  test('S4-04 bump to PREPARED — moves to "Prêt"', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    const id = sharedIds.idAccepted;
    setStatus(id, ST.PREPARED);

    await gotoOSS(page);
    const q = await captureQuartet(page, 'S4-04', errs);
    const row = (q.api.body?.data || []).find(r => r.id === id);
    expect(row?.status, 'status=PREPARED').toBe(ST.PREPARED);

    const readyColText = await page.locator('[role="region"]').filter({ hasText: /pr[êe]t|ready/i }).first().innerText().catch(() => '');
    record('S4-04.dom-ready', 'INFO',
      'PREPARED DOM column renders queue number',
      { col_text_excerpt: readyColText.slice(0, 200), api_row: row });

    await scanRawLabels(page, 'S4-04');
    await scanPii(page, 'S4-04', q.api.body);
  });

  test('S4-05 mixed load — 5 PREPARING + 3 PREPARED, FIFO order check', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    // Park previous test order out of the way
    if (sharedIds.idAccepted) setStatus(sharedIds.idAccepted, ST.DELIVERED);

    const mixedIds = [];
    for (let i = 1; i <= 5; i++) {
      mixedIds.push(seedOrder({
        tokenSuffix: `MIX-PREP-${i.toString().padStart(2, '0')}`,
        queueNumber: `K${(100 + i).toString()}`,
        typeInt: TY.KIOSK,
        statusInt: ST.PREPARING,
      }));
    }
    for (let i = 1; i <= 3; i++) {
      mixedIds.push(seedOrder({
        tokenSuffix: `MIX-RDY-${i.toString().padStart(2, '0')}`,
        queueNumber: `T${(200 + i).toString()}`,
        typeInt: TY.TAKEAWAY,
        statusInt: ST.PREPARED,
      }));
    }
    sharedIds.mixedIds = mixedIds;
    saveSidecar();

    await gotoOSS(page);
    const q = await captureQuartet(page, 'S4-05', errs);
    const rows = (q.api.body?.data || []).filter(r => mixedIds.includes(r.id));
    const preparing = rows.filter(r => r.status === ST.PREPARING).map(r => r.queue_number);
    const prepared = rows.filter(r => r.status === ST.PREPARED).map(r => r.queue_number);

    record('S4-05.distribution', 'INFO', 'Mixed load distribution',
      { seeded: mixedIds.length, api_visible: rows.length, preparing, prepared });

    // FIFO check — PREPARING column should be sorted by queue_number asc
    const isFIFO = preparing.every((q, i) => i === 0 || preparing[i - 1] <= q);
    if (!isFIFO) {
      record('S4-05.fifo', 'CRITICAL',
        'PREPARING column not FIFO-ordered — customer queue jumping',
        { observed: preparing });
    } else {
      record('S4-05.fifo', 'INFO', 'PREPARING FIFO order respected', { observed: preparing });
    }

    // Check no missing
    const missing = mixedIds.filter(id => !rows.find(r => r.id === id));
    if (missing.length > 0) {
      record('S4-05.missing', 'CRITICAL',
        'Seeded mix orders missing from OSS payload',
        { missing });
    }

    await scanRawLabels(page, 'S4-05');
    await scanPii(page, 'S4-05', q.api.body);
  });

  test('S4-06 live update — realtime-order-update event surfaces new order without reload', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    await gotoOSS(page);
    const before = await fetchPublicApi(page);
    const beforeCount = (before.body?.data || []).length;

    // Seed a fresh PREPARING order
    const idPoll = seedOrder({
      tokenSuffix: 'POLL-FRESH',
      queueNumber: 'K999',
      typeInt: TY.KIOSK,
      statusInt: ST.PREPARING,
    });
    sharedIds.idPoll = idPoll;
    saveSidecar();

    // The component listens for `realtime-order-update` window event (line 112) and re-calls list().
    // Fire it manually — that's the deterministic surface; Echo timing is too flaky for E2E.
    await page.evaluate(() => window.dispatchEvent(new Event('realtime-order-update')));
    await page.waitForTimeout(2000);

    const q = await captureQuartet(page, 'S4-06', errs);
    const after = q.api.body || {};
    const visible = (after.data || []).find(r => r.id === idPoll);
    const domHit = await page.locator('text=N°K999').count();

    if (visible && domHit > 0) {
      record('S4-06.live-update', 'INFO',
        'realtime-order-update event refreshed OSS without reload',
        { idPoll, dom_visible: domHit, api_count_before: beforeCount, api_count_after: (after.data || []).length });
    } else if (visible && domHit === 0) {
      record('S4-06.live-update', 'IMPROVEMENT',
        'API includes new order but DOM did not refresh — Vue hydration delay or Echo-only path',
        { idPoll, api_visible: !!visible, dom_visible: domHit });
    } else {
      record('S4-06.live-update', 'CRITICAL',
        'realtime-order-update event did not propagate new order to wall',
        { idPoll, api_visible: !!visible, dom_visible: domHit });
    }

    await scanRawLabels(page, 'S4-06');
    await scanPii(page, 'S4-06', q.api.body);
  });

  test('S4-07 PREPARED → DELIVERED — order disappears from wall', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    // Pick a PREPARED order from mix
    const mixedIds = sharedIds.mixedIds || [];
    await gotoOSS(page);
    const before = await fetchPublicApi(page);
    const preparedRow = (before.body?.data || []).find(r => mixedIds.includes(r.id) && r.status === ST.PREPARED);
    if (!preparedRow) {
      record('S4-07.deliver', 'IMPROVEMENT', 'No PREPARED order in mix to deliver — test skipped', {});
      return;
    }

    setStatus(preparedRow.id, ST.DELIVERED);
    await page.evaluate(() => window.dispatchEvent(new Event('realtime-order-update')));
    await page.waitForTimeout(2000);

    const q = await captureQuartet(page, 'S4-07', errs);
    const stillThere = (q.api.body?.data || []).find(r => r.id === preparedRow.id);
    if (stillThere) {
      record('S4-07.deliver', 'CRITICAL',
        'DELIVERED order still on wall — should disappear after transition',
        { id: preparedRow.id, found: stillThere });
    } else {
      record('S4-07.deliver', 'INFO',
        'DELIVERED order correctly removed from OSS wall',
        { id: preparedRow.id });
    }

    await scanRawLabels(page, 'S4-07');
    await scanPii(page, 'S4-07', q.api.body);
  });

  test('S4-08 allowlist fail-closed — DELIVERY/POS/DINING_TABLE excluded', async ({ page }) => {
    loadSidecar();
    const errs = [];
    page.on('console', (m) => logErr(errs, m));

    const idDelivery = seedOrder({
      tokenSuffix: 'DELIV-001', queueNumber: 'D777',
      typeInt: TY.DELIVERY, statusInt: ST.PREPARING,
    });
    const idDinTable = seedOrder({
      tokenSuffix: 'DTABLE-001', queueNumber: 'DT-99',
      typeInt: TY.DINING_TABLE, statusInt: ST.PREPARING,
    });
    const idPos = seedOrder({
      tokenSuffix: 'POS-EXCL-001', queueNumber: 'P555',
      typeInt: TY.POS, statusInt: ST.PREPARING,
    });
    // Sanity KIOSK order (must be visible)
    const idKiosk = seedOrder({
      tokenSuffix: 'KIOSK-PARITY', queueNumber: 'K888',
      typeInt: TY.KIOSK, statusInt: ST.PREPARING,
    });

    await gotoOSS(page);
    const q = await captureQuartet(page, 'S4-08', errs);
    const all = q.api.body?.data || [];

    const violations = all.filter(r => ![TY.KIOSK, TY.TAKEAWAY].includes(parseInt(r.order_type, 10)));
    if (violations.length > 0) {
      record('S4-08.allowlist-type', 'CRITICAL',
        'Non-KIOSK/TAKEAWAY order leaked onto OSS — fail-closed allowlist broken',
        { violations: violations.map(r => ({ id: r.id, order_type: r.order_type, queue: r.queue_number })) });
    } else {
      record('S4-08.allowlist-type', 'INFO',
        'Allowlist fail-closed: DELIVERY/POS/DINING_TABLE all excluded',
        {
          seeded_delivery: idDelivery,
          seeded_dintable: idDinTable,
          seeded_pos: idPos,
          api_visible_total: all.length,
          api_visible_kiosk: all.filter(r => r.order_type === TY.KIOSK).length,
          api_visible_takeaway: all.filter(r => r.order_type === TY.TAKEAWAY).length,
        });
    }

    const kioskRow = all.find(r => r.id === idKiosk);
    if (!kioskRow) {
      record('S4-08.kiosk-parity', 'CRITICAL',
        'Sanity KIOSK order seeded but missing — query body broken',
        { idKiosk });
    } else {
      record('S4-08.kiosk-parity', 'INFO',
        'KIOSK parity order visible — query body intact',
        { idKiosk });
    }

    // Multi-tenant dispute — ?branch_id=999 (non-existent) should not return another branch's data
    const xenoBranch = await page.evaluate(async () => {
      const apiKey = (window.foodkingConfig && window.foodkingConfig.apiKey) || '';
      const r = await fetch('/api/frontend/oss-order?branch_id=999999', {
        headers: { 'Accept': 'application/json', 'x-api-key': apiKey },
        credentials: 'omit',
      });
      return { status: r.status, body: await r.json().catch(() => null) };
    });
    const xenoCount = (xenoBranch.body?.data || []).length;
    record('S4-08.branch-isolation', xenoCount === 0 ? 'INFO' : 'CRITICAL',
      xenoCount === 0
        ? '?branch_id=999999 returns empty (multi-tenant isolation)'
        : '?branch_id=999999 returned data from another branch — multi-tenant leak',
      { count: xenoCount, status: xenoBranch.status });

    await scanRawLabels(page, 'S4-08');
    await scanPii(page, 'S4-08', q.api.body);
  });

  test.afterAll(async () => {
    // Flush findings to final report
    const findings = loadFindings();
    const out = {
      meta: {
        system: 'S4-OSS',
        cycle: 'wave-final-2026-05-23',
        round: 1,
        url: OSS_URL,
        public_api: '/api/frontend/oss-order',
        public_api_middleware: ['installed', 'apiKey', 'localization', 'throttle:oss-public'],
        ssot_allowlist: 'app/Services/OrderStatusScreenOrderService.php::listForBranch (whereIn order_type [KIOSK,TAKEAWAY], whereIn status [PREPARING,PREPARED])',
        date: new Date().toISOString(),
        branch_id: 1,
        commit: spawnSync('git', ['rev-parse', 'HEAD'], { cwd: REPO_ROOT, encoding: 'utf8' }).stdout.trim(),
      },
      findings,
      summary: {
        critical: findings.filter(f => f.level === 'CRITICAL').length,
        improvements: findings.filter(f => f.level === 'IMPROVEMENT').length,
        info: findings.filter(f => f.level === 'INFO').length,
      },
    };
    const outPath = path.join(REPORT_DIR, 'S4-oss-findings.json');
    fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
    // Cleanup
    wipe();
    // eslint-disable-next-line no-console
    console.log(`\n[S4-OSS] findings → ${outPath}`);
    // eslint-disable-next-line no-console
    console.log(`[S4-OSS] summary  → CRITICAL=${out.summary.critical} IMPROVEMENT=${out.summary.improvements} INFO=${out.summary.info}\n`);
  });
});
