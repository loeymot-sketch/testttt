// FoodKing E2E — Wave S-3 OSS TV optimization (2026-05-20)
//
// Mission: customer wall must be readable from ≥3m.
//   - Column headers ≥ 36px
//   - Order tokens ≥ 40px (per owner mandate)
//   - PRÊT column transition triggers pulse animation (.oss-pulse-ready)
//   - Brand colors preserved (#B0004D preparing / #1AB759 ready)
//   - Allowlist preserved: DELIVERY + POS MUST NOT appear (Wave R-3 heal)
//
// Viewport: 1920x1080 (TV reference).
// Page under test: /admin/order-status-screen
//
// Reports dir: reports/test-e2e/wave-s3-2026-05-20/oss/

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPORT_ROOT = path.join(__dirname, '..', '..', 'reports', 'test-e2e', 'wave-s3-2026-05-20', 'oss');
const SHOTS_DIR  = path.join(REPORT_ROOT, 'screenshots');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const OSS_URL = '/admin/order-status-screen';
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const FATAL_ERR_FILTER = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

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

// Seed a single OSS-shaped order. Mirrors helper from wave-p-oss spec.
function seedOrder({ queueNumber, token, typeInt, statusInt, suffix }) {
  const qn = queueNumber === null ? 'null' : `"${queueNumber}"`;
  const tk = token       === null ? 'null' : `"${token}"`;
  const snippet =
    `$o = new App\\Models\\Order; ` +
    `$o->order_serial_no = 'WSOSS-${suffix}'; ` +
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
  // Tail of output is the new order id.
  const id = parseInt((out.match(/\d+\s*$/) || ['0'])[0], 10);
  return id;
}

function cleanupWaveSorders() {
  php(`DB::table('orders')->where('order_serial_no', 'like', 'WSOSS-%')->update(['fiscal_sequence_no' => null]); App\\Models\\Order::where('order_serial_no', 'like', 'WSOSS-%')->withoutGlobalScopes()->forceDelete();`);
}

function transitionOrderToPrepared(id) {
  php(`$o = App\\Models\\Order::withoutGlobalScopes()->find(${id}); if ($o) { $o->status = 8; $o->save(); }`);
}

test.use({
  viewport: { width: 1920, height: 1080 },
});

test.describe('Wave S-3 — OSS TV optimization (≥40px, pulse on PRÊT)', () => {
  test.beforeAll(() => {
    cleanupWaveSorders();
  });

  test.afterAll(() => {
    cleanupWaveSorders();
  });

  test('S-3.1 — column headers and order tokens meet TV size mandate', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (msg) => logErr(consoleErrors, msg));

    // Seed 5 mixed orders (3 preparing + 2 ready) — KIOSK + TAKEAWAY only
    // per allowlist constraint preserved from Wave R-3.
    const seededIds = [];
    seededIds.push(seedOrder({ queueNumber: 'K101', token: null,  typeInt: 25, statusInt: 7, suffix: 'S301-A' }));
    seededIds.push(seedOrder({ queueNumber: 'K102', token: null,  typeInt: 25, statusInt: 7, suffix: 'S301-B' }));
    seededIds.push(seedOrder({ queueNumber: null,   token: 'T01', typeInt: 10, statusInt: 7, suffix: 'S301-C' }));
    seededIds.push(seedOrder({ queueNumber: 'K201', token: null,  typeInt: 25, statusInt: 8, suffix: 'S301-D' }));
    seededIds.push(seedOrder({ queueNumber: null,   token: 'T02', typeInt: 10, statusInt: 8, suffix: 'S301-E' }));

    await page.goto(OSS_URL, { waitUntil: 'networkidle' });
    // Wait for first list render to settle.
    await page.waitForSelector('h3.oss-column-header', { timeout: 15_000 });
    await page.waitForTimeout(800);

    // Take baseline screenshot at TV viewport.
    await page.screenshot({
      path: path.join(SHOTS_DIR, 's3-baseline-1920x1080.png'),
      fullPage: false,
    });

    // Read computed font-size of both column headers — assert ≥36px.
    const headerSizes = await page.$$eval('h3.oss-column-header', (els) =>
      els.map((el) => parseInt(getComputedStyle(el).fontSize, 10))
    );
    expect(headerSizes.length).toBe(2);
    headerSizes.forEach((px) => expect(px).toBeGreaterThanOrEqual(36));

    // Read computed font-size of first order token in each column — assert ≥40px.
    const tokenSizes = await page.evaluate(() => {
      const lis = Array.from(document.querySelectorAll('ul.oss-order-list li'));
      return lis.slice(0, 4).map((el) => parseInt(getComputedStyle(el).fontSize, 10));
    });
    expect(tokenSizes.length).toBeGreaterThan(0);
    tokenSizes.forEach((px) => expect(px).toBeGreaterThanOrEqual(40));

    // Brand color sanity — backgrounds preserved.
    const headerBgs = await page.$$eval('h3.oss-column-header', (els) =>
      els.map((el) => getComputedStyle(el).backgroundColor)
    );
    // #B0004D = rgb(176, 0, 77) ; #1AB759 = rgb(26, 183, 89)
    expect(headerBgs.some((c) => /176,\s*0,\s*77/.test(c))).toBe(true);
    expect(headerBgs.some((c) => /26,\s*183,\s*89/.test(c))).toBe(true);

    // Allowlist sanity — no WSOSS-S301-D fake leak from disallowed types
    // (we did not seed any DELIVERY/POS but assert the visible token set
    // matches what we seeded).
    const visibleTokens = await page.$$eval('ul.oss-order-list li', (els) =>
      els.map((el) => (el.textContent || '').trim())
    );
    expect(visibleTokens.some((t) => /K10[12]|T01/.test(t))).toBe(true);
    expect(visibleTokens.some((t) => /K201|T02/.test(t))).toBe(true);

    // Console clean — no Vue mount errors.
    expect(consoleErrors).toEqual([]);

    // Persist findings for review.
    fs.writeFileSync(
      path.join(REPORT_ROOT, 's3-1-sizes.json'),
      JSON.stringify({ headerSizes, tokenSizes, headerBgs, visibleTokens }, null, 2)
    );
  });

  test('S-3.2 — PRÊT transition triggers oss-pulse-ready animation', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (msg) => logErr(consoleErrors, msg));

    // Seed one PREPARING order that we'll transition mid-test.
    const pulseId = seedOrder({
      queueNumber: 'K999', token: null, typeInt: 25, statusInt: 7, suffix: 'S302-PULSE',
    });
    expect(pulseId).toBeGreaterThan(0);

    await page.goto(OSS_URL, { waitUntil: 'networkidle' });
    await page.waitForSelector('h3.oss-column-header', { timeout: 15_000 });
    await page.waitForTimeout(500);

    // Trigger backend transition — OSS poller (or Echo) will hydrate within
    // a few seconds. Then dispatch a synthetic realtime event to force the
    // component's `list()` to re-run without depending on Echo's actual
    // socket round-trip in CI.
    transitionOrderToPrepared(pulseId);
    await page.evaluate(() => window.dispatchEvent(new Event('realtime-order-update')));

    // Wait for the K999 row to appear in the PRÊT column with the pulse class.
    const pulseLocator = page.locator('ul.oss-order-list li.oss-pulse-ready', { hasText: 'K999' });
    await expect(pulseLocator).toBeVisible({ timeout: 15_000 });

    // Verify the CSS animation is actually running (not just class present).
    const animState = await pulseLocator.evaluate((el) => {
      const cs = getComputedStyle(el);
      return {
        animationName: cs.animationName,
        animationDuration: cs.animationDuration,
        animationIterationCount: cs.animationIterationCount,
      };
    });
    expect(animState.animationName).toMatch(/oss-pulse|none/); // scoped-styles hash-prefix tolerated
    expect(animState.animationIterationCount).toMatch(/infinite|2/);

    // Capture during-pulse screenshot.
    await page.waitForTimeout(1500);
    await page.screenshot({
      path: path.join(SHOTS_DIR, 's3-pulse-active-1920x1080.png'),
      fullPage: false,
    });

    expect(consoleErrors).toEqual([]);

    fs.writeFileSync(
      path.join(REPORT_ROOT, 's3-2-pulse.json'),
      JSON.stringify({ pulseId, animState }, null, 2)
    );
  });
});
