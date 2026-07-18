// FoodKing E2E — Wave S-4 Suivi commandes "À ENCAISSER" lane (2026-05-20)
//
// Owner mandate Wave S-4 (validated):
//   - Keep 4 columns in the POS tracker (Suivi commandes).
//   - Rename CONFIRMÉE → À ENCAISSER, restrict to kiosk cash-pending only.
//   - With Wave S-1 auto-PREPA active, every paid order skips ACCEPT and
//     lands in EN PRÉPARATION. The À ENCAISSER lane MUST surface ONLY
//     orders flagged PENDING_COUNTER (payment_status=15) +
//     COUNTER_DEFERRED (pos_payment_method=6).
//   - Visual badge: 🔔 + "À encaisser :" prefix + amount + "Encaisser" CTA.
//   - Empty state visible when 0 cash-pending orders.
//
// Wired:
//   - Backend SimpleOrderResource exposes `is_cash_pending` + `cash_pending_amount`.
//   - Frontend PosOrdersTrackerComponent filters bucket=accept by isCashPending.
//   - i18n fr.json/en.json renamed col_accept → "À encaisser" + subtitle.
//
// Frozen-zone STRICT: zero touch on PaymentComponent / pos-wizard / NF525 services.

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPORT_ROOT = path.join(__dirname, '..', '..', 'reports', 'test-e2e', 'wave-s4-2026-05-20', 'suivi');
const SHOTS_DIR = path.join(REPORT_ROOT, 'screenshots');
const REPO_ROOT = path.resolve(__dirname, '..', '..');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const TRACKER_URL = '/admin/pos-orders-tracker';

const FATAL_ERR_FILTER = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

function php(snippet) {
  const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
    cwd: REPO_ROOT, encoding: 'utf8', timeout: 60_000,
  });
  return (res.stdout || '') + (res.stderr || '');
}

// Seed a single order with explicit payment + status flags.
//
//   typeInt:           OrderType — 15=POS, 25=KIOSK, 10=TAKEAWAY
//   statusInt:         Status — 4=ACCEPT, 7=PREPARING, 8=PREPARED
//   paymentStatusInt:  5=PAID, 10=UNPAID, 15=PENDING_COUNTER
//   posPaymentMethod:  null | 1=CASH | 2=CARD | 6=COUNTER_DEFERRED
//
// Returns the seeded order id (int) so the spec can transition/cleanup.
function seedOrder({ suffix, queueNumber, typeInt, statusInt, paymentStatusInt, posPaymentMethod }) {
  const qn = queueNumber ? `"${queueNumber}"` : 'null';
  const ppm = posPaymentMethod === null || posPaymentMethod === undefined ? 'null' : posPaymentMethod;
  const snippet =
    `$o = new App\\Models\\Order; ` +
    `$o->order_serial_no = 'WS4-${suffix}'; ` +
    `$o->queue_number = ${qn}; ` +
    `$o->user_id = 1; ` +
    `$o->branch_id = 1; ` +
    `$o->subtotal = 12.50; ` +
    `$o->total = 12.50; ` +
    `$o->order_type = ${typeInt}; ` +
    `$o->order_datetime = now('UTC'); ` +
    `$o->preparation_time = 5; ` +
    `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
    `$o->payment_method = 1; ` +
    `$o->payment_status = ${paymentStatusInt}; ` +
    `$o->pos_payment_method = ${ppm}; ` +
    `$o->status = ${statusInt}; ` +
    `$o->business_date = now()->toDateString(); ` +
    `$o->save(); ` +
    `echo $o->id;`;
  const out = php(snippet);
  return parseInt((out.match(/\d+\s*$/) || ['0'])[0], 10);
}

function cleanupWaveS4orders() {
  php(`DB::table('orders')->where('order_serial_no', 'like', 'WS4-%')->update(['fiscal_sequence_no' => null]); App\\Models\\Order::where('order_serial_no', 'like', 'WS4-%')->withoutGlobalScopes()->forceDelete();`);
}

test.use({ viewport: { width: 1440, height: 900 } });

test.describe('Wave S-4 — Suivi commandes À ENCAISSER lane', () => {
  test.setTimeout(180_000);

  test.beforeAll(() => {
    cleanupWaveS4orders();
  });

  test.afterAll(() => {
    cleanupWaveS4orders();
  });

  test('S-4.1 — kiosk cash-pending lands in À ENCAISSER; paid orders skip to PRÉPARATION', async ({ page }) => {
    const findings = { errors: [], assertions: [] };
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        const text = msg.text();
        if (!FATAL_ERR_FILTER.test(text)) findings.errors.push({ kind: 'console', text });
      }
    });
    page.on('pageerror', (err) => findings.errors.push({ kind: 'page', text: err.message }));

    // ----- Seed 3 orders -----
    // 1) POS cash paid → status PREPARING (S-1 auto-promote already done)
    //    Should appear in EN PRÉPARATION.
    const posPaidId = seedOrder({
      suffix: 'S41-POS-PAID',
      queueNumber: 'P101',
      typeInt: 15, statusInt: 7,
      paymentStatusInt: 5, posPaymentMethod: 1,
    });
    expect(posPaidId).toBeGreaterThan(0);

    // 2) Kiosk TPE paid → status PREPARING (auto-promote).
    //    Should appear in EN PRÉPARATION, NOT in À ENCAISSER.
    const kioskTpeId = seedOrder({
      suffix: 'S41-K-TPE',
      queueNumber: 'K201',
      typeInt: 25, statusInt: 7,
      paymentStatusInt: 5, posPaymentMethod: 2,
    });
    expect(kioskTpeId).toBeGreaterThan(0);

    // 3) Kiosk cash-at-counter → status ACCEPT + PENDING_COUNTER + COUNTER_DEFERRED.
    //    Should appear in À ENCAISSER with bell badge + Encaisser CTA.
    const kioskCashId = seedOrder({
      suffix: 'S41-K-CASH',
      queueNumber: 'K301',
      typeInt: 25, statusInt: 4,
      paymentStatusInt: 15, posPaymentMethod: 6,
    });
    expect(kioskCashId).toBeGreaterThan(0);

    // ----- Login + navigate to tracker -----
    await loginAsAdmin(page);
    await page.waitForTimeout(600);

    await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
    // Tracker fetches today's orders on mount; allow the first poll cycle.
    await page.waitForSelector('.pos-tracker-grid', { timeout: 15_000 });
    await page.waitForTimeout(2500);

    await page.screenshot({
      path: path.join(SHOTS_DIR, 's4-1-tracker-baseline.png'),
      fullPage: false,
    });

    // ----- Assert column headers -----
    const columnLabels = await page.$$eval('.pos-tracker-col-head h2', (els) =>
      els.map((el) => (el.textContent || '').replace(/\s+/g, ' ').trim())
    );
    findings.assertions.push({ key: 'column_labels', value: columnLabels });

    // The renamed lane must read "À encaisser" (icon emoji prefix tolerated).
    expect(columnLabels.some((l) => /à\s+encaisser/i.test(l))).toBe(true);

    // Subtitle present under À encaisser.
    const subtitles = await page.$$eval('.pos-tracker-col-subtitle', (els) =>
      els.map((el) => (el.textContent || '').trim())
    );
    expect(subtitles.some((s) => /borne.*paiement.*comptoir/i.test(s))).toBe(true);

    // ----- Assert lane membership (target our seeded orders only — other
    // dev/staging rows may coexist, so we check the SEEDED orders land in
    // the RIGHT lane, never the total card counts which are non-isolated) -----
    const acceptLane = page.locator('.pos-tracker-col').nth(0);
    const preparingLane = page.locator('.pos-tracker-col').nth(1);

    // K301 (kiosk cash-pending) MUST be in À encaisser.
    await expect(acceptLane.locator(`[data-testid="tracker-order-${kioskCashId}"]`)).toBeVisible();
    // K301 MUST NOT be in EN PRÉPARATION.
    await expect(preparingLane.locator(`[data-testid="tracker-order-${kioskCashId}"]`)).toHaveCount(0);

    // P101 (POS paid) MUST be in EN PRÉPARATION, NOT in À encaisser.
    await expect(preparingLane.locator(`[data-testid="tracker-order-${posPaidId}"]`)).toBeVisible();
    await expect(acceptLane.locator(`[data-testid="tracker-order-${posPaidId}"]`)).toHaveCount(0);

    // K201 (Kiosk TPE paid) MUST be in EN PRÉPARATION, NOT in À encaisser.
    await expect(preparingLane.locator(`[data-testid="tracker-order-${kioskTpeId}"]`)).toBeVisible();
    await expect(acceptLane.locator(`[data-testid="tracker-order-${kioskTpeId}"]`)).toHaveCount(0);

    // The cash-pending order shows the bell badge + amount prefix.
    const cashBadgeLocator = page.locator(`[data-testid="tracker-cash-badge-${kioskCashId}"]`);
    await expect(cashBadgeLocator).toBeVisible();

    const encaisserBtn = page.locator(`[data-testid="tracker-encaisser-${kioskCashId}"]`);
    await expect(encaisserBtn).toBeVisible();

    // Amount surface uses the cash-prefixed total.
    const amountSpan = page.locator(`[data-testid="tracker-amount-${kioskCashId}"]`);
    await expect(amountSpan).toBeVisible();
    const amountText = (await amountSpan.textContent()) || '';
    expect(/à\s+encaisser/i.test(amountText)).toBe(true);
    expect(/12[.,]50/.test(amountText)).toBe(true);

    // ----- Negative: paid orders MUST NOT carry the cash bell -----
    const posPaidBadge = page.locator(`[data-testid="tracker-cash-badge-${posPaidId}"]`);
    await expect(posPaidBadge).toHaveCount(0);
    const kioskTpeBadge = page.locator(`[data-testid="tracker-cash-badge-${kioskTpeId}"]`);
    await expect(kioskTpeBadge).toHaveCount(0);

    // ----- Encaisser CTA dispatches the agreed CustomEvent (Wave S-5 wiring) -----
    await page.evaluate(() => {
      window.__waveS4Event = null;
      window.addEventListener('foodking:pos:open-encaissement', (ev) => {
        window.__waveS4Event = ev?.detail || null;
      }, { once: true });
    });
    await encaisserBtn.click();
    await page.waitForTimeout(200);
    const dispatched = await page.evaluate(() => window.__waveS4Event);
    expect(dispatched).not.toBeNull();
    expect(dispatched?.orderId).toBe(kioskCashId);

    await page.screenshot({
      path: path.join(SHOTS_DIR, 's4-1-tracker-cash-card.png'),
      fullPage: false,
    });

    expect(findings.errors).toEqual([]);

    fs.writeFileSync(
      path.join(REPORT_ROOT, 's4-1-findings.json'),
      JSON.stringify({ ...findings, columnLabels, subtitles, seeded: { kioskCashId, posPaidId, kioskTpeId }, dispatched }, null, 2)
    );
  });

  test('S-4.2 — empty À ENCAISSER lane renders empty state, lane stays visible', async ({ page }) => {
    const findings = { errors: [], assertions: [] };
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        const text = msg.text();
        if (!FATAL_ERR_FILTER.test(text)) findings.errors.push({ kind: 'console', text });
      }
    });

    // Sweep stray cash-pending today-orders so the empty-state assertion is
    // deterministic (other waves may have parked PENDING_COUNTER fixtures).
    php(`DB::table('orders')->where('payment_status', 15)->where('pos_payment_method', 6)->whereDate('order_datetime', now()->toDateString())->update(['fiscal_sequence_no' => null]); App\\Models\\Order::where('payment_status', 15)->where('pos_payment_method', 6)->whereDate('order_datetime', now()->toDateString())->withoutGlobalScopes()->forceDelete();`);

    // Seed only paid orders → À encaisser must be empty but visible.
    seedOrder({
      suffix: 'S42-POS-PAID-A',
      queueNumber: 'P401', typeInt: 15, statusInt: 7,
      paymentStatusInt: 5, posPaymentMethod: 1,
    });
    seedOrder({
      suffix: 'S42-K-TPE-A',
      queueNumber: 'K401', typeInt: 25, statusInt: 7,
      paymentStatusInt: 5, posPaymentMethod: 2,
    });

    await loginAsAdmin(page);
    await page.waitForTimeout(600);

    await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.pos-tracker-grid', { timeout: 15_000 });
    await page.waitForTimeout(2500);

    // The 4-column layout MUST remain visible (owner mandate: lane never hidden).
    const colCount = await page.locator('.pos-tracker-col').count();
    expect(colCount).toBe(4);

    // Lane 1 (À encaisser) → 0 cards + empty-state placeholder rendered.
    const acceptCards = await page.locator('.pos-tracker-col:nth-child(1) .pos-tracker-card').count();
    expect(acceptCards).toBe(0);

    const emptyEl = page.locator('.pos-tracker-col:nth-child(1) .pos-tracker-col-empty');
    await expect(emptyEl).toBeVisible();
    const emptyText = (await emptyEl.textContent()) || '';
    expect(/aucune\s+commande\s+à\s+encaisser/i.test(emptyText)).toBe(true);

    await page.screenshot({
      path: path.join(SHOTS_DIR, 's4-2-empty-state.png'),
      fullPage: false,
    });

    expect(findings.errors).toEqual([]);

    fs.writeFileSync(
      path.join(REPORT_ROOT, 's4-2-findings.json'),
      JSON.stringify({ ...findings, colCount, acceptCards, emptyText: emptyText.trim() }, null, 2)
    );
  });
});
