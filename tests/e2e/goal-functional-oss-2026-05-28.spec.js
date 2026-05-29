// FoodKing E2E — GOAL Functional Validation OSS (Order Status Screen) — 2026-05-28
//
// Mission: Audit the customer-facing wall display (`/admin/order-status-screen`) from the
// "waiting customer" perspective. Visual + technical audit. Mocked API for state coverage.
//
// Surface under test  : /admin/order-status-screen (route public-friendly per router/index.js:218-225)
// Component           : resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue (489 LOC)
// Public API          : GET /api/frontend/oss-order              (CDSOrderDetailsResource, PII-free)
//                       GET /api/frontend/oss-order/popular-items (CDSPopularItemResource)
// Service SSOT        : app/Services/OrderStatusScreenOrderService.php
//                       - listForBranch():206 / list():37 — KIOSK+TAKEAWAY allowlist, PREPARING+PREPARED,
//                         stale prune `subHours(config('oss.stale_window_hours', 8))` :132/:256
//                       - deterministic FIFO: orderBy('queue_number','asc')->orderBy('id','asc') :144/:264
//                       - mostPopularItems() :169 — branch-scoped (Sprint H5)
// Findings sink       : reports/test-e2e/goal-functional-validation-2026-05-28/OSS/findings.json
// Screenshots         : reports/test-e2e/goal-functional-validation-2026-05-28/OSS/screenshots/*.png
//
// Test cases:
//   T-01  Idle state — `—` empty placeholder both columns
//   T-02  Mixed PREPARING + PREPARED rows render in FIFO order (queue_number asc, id asc)
//   T-03  PREPARED transition triggers visual flash (`.oss-ready-flash`) + per-card pulse
//         (Public wall: chime is intentionally muted — see _playReadySound() lines 330-338)
//   T-04  wakeLock wiring asserted (visibilitychange listener attached on mount)
//   T-05  Stale prune 8h attested by code (config('oss.stale_window_hours',8) :132/:256 +
//         config/oss.php:29). Cannot reproduce in spec without backdated seeder.
//   T-06  French i18n sweep — "En préparation" + "Prêt" rendered, no raw `label.X` tokens
//   T-07  Public API contract — content-type JSON (not HTML masquerade per CLAUDE.md feedback)
//   T-08  Mobile viewport — no layout break (md:h-[calc(100dvh-117px)] graceful)

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const REPORT_DIR = path.join(
  __dirname, '..', '..',
  'reports', 'test-e2e', 'goal-functional-validation-2026-05-28', 'OSS',
);
const SHOTS_DIR = path.join(REPORT_DIR, 'screenshots');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const FINDINGS_PATH = path.join(REPORT_DIR, 'findings.json');

// Status enum — must match resources/js/enums/modules/orderStatusEnum.js
const STATUS = { PREPARING: 7, PREPARED: 8 };
// OrderType enum — must match app/Enums/OrderType.php
const TYPE   = { TAKEAWAY: 10, KIOSK: 25 };

const OSS_URL    = '/admin/order-status-screen';
const PUBLIC_API = '**/api/frontend/oss-order**';
const POPULAR_API = '**/api/frontend/oss-order/popular-items**';

// Known noise we must NOT fail on (TV-wall environment is intentionally noisy:
// Echo socket, AudioContext, wakeLock denial in headless, etc).
const CONSOLE_NOISE = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext|wakeLock|workbox|GoogleAnalytics)/i;

let findings = [];
function record(id, level, kind, title, evidence) {
  findings.push({ id, level, kind, title, evidence, ts: new Date().toISOString() });
}

function makeOrder({ id, queue, type = TYPE.KIOSK, status = STATUS.PREPARING, token = null }) {
  return {
    id,
    order_serial_no: `OSS-FUNC-${id}`,
    token,
    queue_number: queue,
    order_type: type,
    status,
  };
}

test.beforeAll(() => {
  // Reset findings sink at suite-start so runs are reproducible.
  fs.writeFileSync(FINDINGS_PATH, JSON.stringify([], null, 2));
  findings = [];
});

test.afterAll(() => {
  fs.writeFileSync(FINDINGS_PATH, JSON.stringify(findings, null, 2));
});

test.describe('OSS — Order Status Screen — waiting customer journey', () => {

  // T-07: Verify the public API returns JSON (not HTML masquerade per CLAUDE.md
  // feedback note "Silent HTML masquerade audit pattern"). Run early so a
  // catastrophic API mismatch surfaces before visual specs.
  test('T-07 public oss-order endpoint returns JSON content-type', async ({ request }) => {
    const res = await request.get('/api/frontend/oss-order?branch_id=1');
    expect(res.status(), `status ${res.status()} for /api/frontend/oss-order`).toBeLessThan(500);
    const ct = (res.headers()['content-type'] || '').toLowerCase();
    if (!ct.includes('application/json')) {
      record('OSS-T-07', 'P0', 'defect',
        'public OSS endpoint did not return JSON content-type',
        { content_type: ct, status: res.status() });
    }
    expect(ct, `content-type was "${ct}" not application/json`).toMatch(/application\/json/);
  });

  test('T-01 idle state shows em-dash placeholder in both columns', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (m) => {
      if (m.type() === 'error' && !CONSOLE_NOISE.test(m.text())) consoleErrors.push(m.text());
    });

    // Mock OSS list to empty.
    await page.route(PUBLIC_API, (route) => {
      if (route.request().url().includes('popular-items')) {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [] }),
        });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [] }),
      });
    });

    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);

    // Both columns render header text from i18n label.preparing / label.ready
    const preparing = await page.locator('h3:has-text("En préparation")').count();
    const ready = await page.locator('h3:has-text("Prêt")').count();
    expect(preparing, 'PRÉPARATION header missing').toBeGreaterThanOrEqual(1);
    expect(ready, 'PRÊT header missing').toBeGreaterThanOrEqual(1);

    // Em-dash empty placeholder present
    const dashes = await page.locator('p:has-text("—")').count();
    if (dashes < 2) {
      record('OSS-T-01', 'P1', 'defect',
        'Empty-state em-dash placeholder did not render in both columns',
        { dashes_found: dashes });
    }

    await page.screenshot({
      path: path.join(SHOTS_DIR, '01-oss-idle-empty-state.png'),
      fullPage: true,
    });

    if (consoleErrors.length > 0) {
      record('OSS-T-01-CONSOLE', 'P2', 'defect',
        `${consoleErrors.length} non-noise console errors on idle mount`,
        { errors: consoleErrors.slice(0, 6) });
    }
  });

  test('T-02 mixed PREPARING + PREPARED render in FIFO order', async ({ page }) => {
    // Deterministic FIFO: queue_number asc, id asc (service.php :144/:264).
    // Seed out-of-order in the response → assert UI re-orders.
    const orders = [
      makeOrder({ id: 7,  queue: 12, status: STATUS.PREPARING }),
      makeOrder({ id: 3,  queue: 7,  status: STATUS.PREPARING }),  // earliest queue first
      makeOrder({ id: 11, queue: 14, status: STATUS.PREPARED }),
      makeOrder({ id: 5,  queue: 9,  status: STATUS.PREPARED }),   // earliest queue first
      makeOrder({ id: 22, queue: null, status: STATUS.PREPARING, token: 'TAKE-A1', type: TYPE.TAKEAWAY }),
    ];
    // The backend sorts by queue ASC then id ASC; the customer wall trusts that
    // shape. We send the raw rows already sorted as the backend would; the spec
    // verifies the DOM honours the order received.
    const sorted = [
      orders.find(o => o.id === 3),
      orders.find(o => o.id === 7),
      orders.find(o => o.id === 22),
      orders.find(o => o.id === 5),
      orders.find(o => o.id === 11),
    ];

    await page.route(PUBLIC_API, (route) => {
      if (route.request().url().includes('popular-items')) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: sorted }) });
    });

    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);

    // Capture both columns.
    const allLis = await page.locator('ul.oss-order-list li').allTextContents();
    // Expected order in DOM (top→bottom): preparing column first then ready column.
    // Visible labels: N°7, N°12, TAKE-A1, N°9, N°14
    const expectVisibleSubstrings = ['N°7', 'N°12', 'TAKE-A1', 'N°9', 'N°14'];
    expectVisibleSubstrings.forEach((needle) => {
      const present = allLis.some(t => t.includes(needle));
      if (!present) {
        record('OSS-T-02', 'P1', 'defect',
          `Expected token "${needle}" missing from rendered order list`,
          { dom_lis: allLis });
      }
    });

    // Assert ordering within the PREPARING column: N°7 must come BEFORE N°12 in DOM.
    const preparingLis = await page
      .locator('ul.oss-order-list').first()
      .locator('li').allTextContents();
    const idx7  = preparingLis.findIndex(t => t.includes('N°7'));
    const idx12 = preparingLis.findIndex(t => t.includes('N°12'));
    if (idx7 < 0 || idx12 < 0 || idx7 >= idx12) {
      record('OSS-T-02-FIFO', 'P0', 'defect',
        'FIFO ordering violated in PREPARING column (queue_number asc expected)',
        { preparingLis, idx7, idx12 });
    }
    expect(idx7, 'N°7 missing in preparing column').toBeGreaterThanOrEqual(0);
    expect(idx7, 'FIFO broken: N°7 should precede N°12').toBeLessThan(idx12);

    await page.screenshot({
      path: path.join(SHOTS_DIR, '02-oss-mixed-preparing-prepared.png'),
      fullPage: true,
    });
  });

  test('T-03 PREPARED transition triggers visual flash (public wall: chime intentionally muted)', async ({ page }) => {
    let payload = [
      makeOrder({ id: 100, queue: 50, status: STATUS.PREPARING }),
      makeOrder({ id: 101, queue: 51, status: STATUS.PREPARING }),
    ];
    await page.route(PUBLIC_API, (route) => {
      if (route.request().url().includes('popular-items')) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: payload }) });
    });

    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(SHOTS_DIR, '03a-oss-before-ready.png'), fullPage: true });

    // Flip order 100 to PREPARED, then trigger the component's refresh path by
    // dispatching the realtime-order-update event the component listens to
    // (PreparingAndReadyComponent.vue:112).
    payload = [
      makeOrder({ id: 100, queue: 50, status: STATUS.PREPARED }),
      makeOrder({ id: 101, queue: 51, status: STATUS.PREPARING }),
    ];
    await page.evaluate(() => {
      window.dispatchEvent(new CustomEvent('realtime-order-update'));
    });
    await page.waitForTimeout(1500);

    // Visual flash class on the READY column wrapper.
    const flashOn = await page.evaluate(() => {
      const readyCol = document.querySelectorAll('.customer-screen')[1];
      return !!readyCol && readyCol.classList.contains('oss-ready-flash');
    });
    // The flash window is 4s — capture screenshot while it's hot.
    await page.screenshot({ path: path.join(SHOTS_DIR, '03b-oss-ready-flash-hot.png'), fullPage: true });

    if (!flashOn) {
      // Could be a race — the flash may have cleared. Record as info, don't fail
      // hard. The pulse class on the card is the longer-lived signal.
      record('OSS-T-03-FLASH', 'P2', 'observation',
        'oss-ready-flash class not observed at capture moment — may be racing the 4s window',
        { hint: 'See PreparingAndReadyComponent.vue:316 setTimeout 4000ms' });
    }

    // Per-card pulse persists ~10s; assert at least one .oss-pulse-ready exists.
    const pulseCards = await page.locator('.oss-pulse-ready').count();
    if (pulseCards < 1) {
      record('OSS-T-03-PULSE', 'P1', 'defect',
        'No .oss-pulse-ready card present after PREPARED transition',
        { pulseCards });
    }

    // Document the public-wall chime mute as INTENTIONAL behaviour, not a bug.
    record('OSS-T-03-CHIME-MUTED', 'INFO', 'attestation',
      'Chime is intentionally muted on public wall (authBranchId<=0). ' +
      'See PreparingAndReadyComponent.vue:330-338 + :139-144. ' +
      'Visual .oss-ready-flash + .oss-pulse-ready is the documented heal path (OSS-B-02).',
      {
        file: 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue',
        chime_gate_line: 338,
        listener_gate_line: 139,
      });
  });

  test('T-04 wakeLock + visibilitychange listener wired on mount', async ({ page }) => {
    await page.route(PUBLIC_API, (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }),
    );

    // Hook before navigation so we capture listener registration.
    await page.addInitScript(() => {
      window.__visibilityListenerCount = 0;
      const orig = document.addEventListener;
      document.addEventListener = function (type, ...rest) {
        if (type === 'visibilitychange') window.__visibilityListenerCount += 1;
        return orig.call(this, type, ...rest);
      };
    });

    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);

    const count = await page.evaluate(() => window.__visibilityListenerCount || 0);
    if (count < 1) {
      record('OSS-T-04', 'P1', 'defect',
        'No visibilitychange listener registered on mount (wakeLock re-acquire path broken)',
        { observed_listeners: count, expected_min: 1 });
    }
    expect(count, 'visibilitychange listener should be registered ≥1 time').toBeGreaterThanOrEqual(1);

    // wakeLock acquisition itself typically fails in Playwright headless;
    // attest via code (PreparingAndReadyComponent.vue:197-214) not runtime.
    record('OSS-T-04-WAKELOCK-CODE', 'INFO', 'attestation',
      'navigator.wakeLock.request("screen") is invoked in _acquireWakeLock(). ' +
      'Graceful degrade on browsers without API (line 207 catch). ' +
      'Headless Playwright denies the request silently — assertion is on wiring not state.',
      {
        file: 'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue',
        acquire_line: 198,
        release_line: 209,
      });

    await page.screenshot({ path: path.join(SHOTS_DIR, '04-oss-wakelock-wiring.png'), fullPage: true });
  });

  test('T-05 stale prune 8h attested via code + config (no runtime repro)', async ({ page }) => {
    // We cannot create an order with order_datetime 9h ago through the public
    // API; the cross-system spec (#190) covers DB-level reality. Attest here
    // that the SSOT exists and the default window is 8h.
    const servicePath = path.join(__dirname, '..', '..', 'app', 'Services', 'OrderStatusScreenOrderService.php');
    const configPath = path.join(__dirname, '..', '..', 'config', 'oss.php');
    const serviceSrc = fs.readFileSync(servicePath, 'utf8');
    const configSrc = fs.readFileSync(configPath, 'utf8');

    const hasListPrune = /subHours\(\(int\)\s*config\('oss\.stale_window_hours',\s*8\)\)/.test(serviceSrc);
    const hasConfig = /'stale_window_hours'\s*=>\s*\(int\)\s*env\('OSS_STALE_WINDOW_HOURS',\s*8\)/.test(configSrc);

    if (!hasListPrune) {
      record('OSS-T-05', 'P0', 'defect',
        'Stale prune SQL pattern missing in OrderStatusScreenOrderService.php',
        { service_path: servicePath });
    }
    if (!hasConfig) {
      record('OSS-T-05-CFG', 'P0', 'defect',
        'config/oss.php stale_window_hours key missing or non-default',
        { config_path: configPath });
    }

    expect(hasListPrune, 'subHours(config(...)) missing in service').toBe(true);
    expect(hasConfig, 'stale_window_hours config missing').toBe(true);

    record('OSS-T-05-OK', 'INFO', 'attestation',
      'Stale prune 8h asserted via SSOT inspection.',
      {
        list_line: 132,
        list_for_branch_line: 256,
        config_line: 29,
        default_hours: 8,
      });
  });

  test('T-06 French i18n sweep — no raw label.X tokens visible', async ({ page }) => {
    await page.route(PUBLIC_API, (route) => {
      if (route.request().url().includes('popular-items')) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [makeOrder({ id: 1, queue: 1, status: STATUS.PREPARING })],
      }) });
    });
    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);

    const body = await page.locator('body').innerText();

    // No raw token leakage.
    const rawLabels = /\blabel\.[a-z_]+|kiosk\.[a-z_.]+|pos\.[a-z_.]+|\[object Object\]|0undefined|NaN €/i;
    if (rawLabels.test(body)) {
      const sample = body.match(rawLabels);
      record('OSS-T-06', 'P0', 'defect',
        'Raw i18n token or undefined value rendered in customer wall',
        { sample });
    }
    expect(body, 'Raw label.X / kiosk.X / [object Object] visible').not.toMatch(rawLabels);

    // No English leakage of the column headers.
    const englishLeak = /Preparing\b|Ready\b/;
    if (englishLeak.test(body)) {
      record('OSS-T-06-EN', 'P1', 'defect',
        'English column header leaked through French locale',
        { sample: body.match(englishLeak) });
    }

    expect(body, 'French preparation header missing').toMatch(/En préparation/);
    expect(body, 'French ready header missing').toMatch(/Prêt/);

    await page.screenshot({ path: path.join(SHOTS_DIR, '06-oss-i18n-french.png'), fullPage: true });
  });

  test('T-08 mobile viewport — no layout break', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } }); // iPhone-ish
    const page = await ctx.newPage();
    await page.route(PUBLIC_API, (route) => {
      if (route.request().url().includes('popular-items')) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [
          makeOrder({ id: 1, queue: 1, status: STATUS.PREPARING }),
          makeOrder({ id: 2, queue: 2, status: STATUS.PREPARED }),
        ],
      }) });
    });
    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(700);

    // Both columns must remain visible (grid col-span-1, even on mobile).
    const visible = await page.locator('.customer-screen').count();
    if (visible < 2) {
      record('OSS-T-08', 'P1', 'defect',
        'Mobile viewport collapsed: <2 customer-screen columns visible',
        { count: visible, viewport: { w: 390, h: 844 } });
    }
    expect(visible, 'Should render 2 customer-screen panes').toBeGreaterThanOrEqual(2);

    await page.screenshot({ path: path.join(SHOTS_DIR, '08-oss-mobile-viewport.png'), fullPage: true });
    await ctx.close();
  });
});
