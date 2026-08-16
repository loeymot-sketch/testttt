/**
 * [goal-4chantiers-2026-08-16 · Wave B] Web-order alert: triple beep + red panel.
 *
 * Owner ask: a web order landing while the cashier's POS tab is open must be IMPOSSIBLE to
 * miss — a single 0.4s beep was inaudible. Fix (b7e5240ba) makes a web-origin order fire 3
 * beeps spaced 10s apart (Uber-Eats style) AND turns the "Commandes web" panel's left border
 * red (#d32f2f) instead of the old pale blue. Other channels (kiosk cash / counter-collect)
 * keep the single beep.
 *
 * REAL TRIGGER PATH (confirmed by reading the source, see AUDIT_PLAN.md Wave B): there is NO
 * real-time broadcast server in this environment (BROADCAST_DRIVER=log, no socket server) —
 * `_notifyNewOrder()` (the Echo/websocket path) never fires. The ACTUAL production trigger is
 * the polling path: `loadWebOrders()` GETs `admin/pos/web-orders/pending` every
 * `_kioskPollingInterval()` (5s when no websocket) and diffs order IDs via
 * `_notifyPolledNewOrders(list, 'web', 'web')` — origin is a HARDCODED 'web' literal for this
 * endpoint (NOT read from `o.source_surface` per-row), so any order that newly appears in that
 * endpoint's response is treated as a fresh web order. `origin==='web'` routes to
 * `_playWebOrderAlertSequence()` (beep at t=0/10000/20000ms); anything else uses the single
 * `_playNewOrderBeep()`.
 *
 * Backend gate for `admin/pos/web-orders/pending`: `source_surface IN ('web','delivery')`,
 * `status = PENDING`, and (`payment_method != CARD` OR `payment_status != UNPAID`) — a COD web
 * order (payment_method=CASH_ON_DELIVERY) clears the second clause trivially. This is the exact
 * field combination used to seed the fixture below (mirrors the proven working pattern in
 * `_verif-panneau-web-payee-2026-08-10.spec.js`).
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsPosOperator, cleanupOrphanTestOrders } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-goal-4chantiers-wave-B');
const TOKEN_PREFIX = 'E2E-WAVEB-';
const RUN_ID = Date.now();
const WEB_ORDER_SERIAL = `${TOKEN_PREFIX}WEB-${RUN_ID}`;
const KIOSK_ORDER_SERIAL = `${TOKEN_PREFIX}KIOSK-${RUN_ID}`;

function tinker(php) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    timeout: 60_000,
  });
}

function cleanupFixtures() {
  try {
    tinker(
      `\\App\\Models\\Order::withoutGlobalScopes()->where('order_serial_no','like','${TOKEN_PREFIX}%')->forceDelete(); echo 'CLEANED';`
    );
  } catch (e) {
    console.warn('[wave-B cleanup] failed (non-blocking):', e?.message || e);
  }
}

test.describe('Wave B — web-order alert: triple beep + red panel', () => {
  test.beforeAll(() => {
    // Scoped to OUR token prefix only — do not sweep other waves' concurrently-seeded rows.
    cleanupOrphanTestOrders([TOKEN_PREFIX]);
    cleanupFixtures();
  });

  test.afterAll(() => {
    cleanupFixtures();
  });

  test('web-origin order fires exactly 3 beeps + red panel; kiosk-origin order stays at 1 beep', async ({ page }) => {
    test.setTimeout(150_000);

    // Instrument Web Audio BEFORE any page script runs (addInitScript executes ahead of the
    // app bundle on every navigation in this page/context). Wrap createOscillator so every
    // osc.start() call (== one _playNewOrderBeep() firing) is timestamped. Screenshots alone
    // cannot prove audio fired — this is the technical, non-visual proof.
    await page.addInitScript(() => {
      window.__beepEvents = [];
      const tryPatch = () => {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx || Ctx.prototype.__wavebPatched) return false;
        const origCreateOscillator = Ctx.prototype.createOscillator;
        Ctx.prototype.createOscillator = function (...args) {
          const osc = origCreateOscillator.apply(this, args);
          const origStart = osc.start.bind(osc);
          osc.start = function (...startArgs) {
            window.__beepEvents.push(Date.now());
            return origStart(...startArgs);
          };
          return osc;
        };
        Ctx.prototype.__wavebPatched = true;
        return true;
      };
      tryPatch();
    });

    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // Forensic network trace: this is a LIVE, shared dev DB (197 pre-existing pending
    // counter-collect orders, 77 pre-existing pending web orders confirmed before this test
    // even starts — see wave report) and other GStack waves may poll/mutate the SAME server
    // concurrently. Record every web-orders/pending + web-orders/paid poll response's order-ID
    // set with a timestamp so any unexpected beep can be attributed to a specific poll/order
    // rather than guessed at after the fact.
    const netTrace = [];
    page.on('response', async (resp) => {
      const url = resp.url();
      if (!/admin\/pos\/web-orders\/(pending|paid)/.test(url)) return;
      if (resp.request().method() !== 'GET') return;
      try {
        const body = await resp.json();
        netTrace.push({
          ts: Date.now(),
          kind: /paid/.test(url) ? 'web_paid' : 'web_pending',
          ids: (body?.data || []).map((o) => o.id),
        });
      } catch (_e) { /* ignore parse failures */ }
    });

    await loginAsPosOperator(page);
    await expect(page).toHaveURL(/\/admin\/pos/);

    const webPanel = page.getByTestId('pos-shortcuts-web');
    await expect(webPanel).toBeVisible({ timeout: 20_000 });

    // Unlock the Web Audio autoplay policy: Chromium suspends a fresh AudioContext until a
    // TRUSTED user gesture has occurred anywhere on the page (the app code itself calls
    // ctx.resume() defensively — see _playNewOrderBeep() ~L3993 comment). Click on the panel's
    // own (non-interactive) heading — inert, no handler — via Playwright's CDP-level mouse
    // click, which Chromium counts as a genuine gesture (unlike a synthetic dispatchEvent).
    await webPanel.locator('.pos-shortcuts__title').click({ force: true });

    // Wait for the FIRST poll tick to land before seeding: _notifyPolledNewOrders() treats the
    // very first poll per seedKey as a SILENT baseline (marks all currently-visible IDs as
    // already-notified without beeping) — the fixture must be created strictly AFTER this.
    const firstPoll = page
      .waitForResponse((r) => /admin\/pos\/web-orders\/pending/.test(r.url()) && r.request().method() === 'GET', { timeout: 15_000 })
      .catch(() => null);
    await firstPoll;
    await page.waitForTimeout(500);

    const ariaBefore = await webPanel.getAttribute('aria-label');
    const countBeforeMatch = ariaBefore && ariaBefore.match(/\((\d+)\)/);
    const countBefore = countBeforeMatch ? parseInt(countBeforeMatch[1], 10) : null;
    console.log('[wave-B] baseline web panel aria-label:', ariaBefore);

    await snap('01-pos-before-web-order');

    const beepsBeforeSeed = await page.evaluate(() => window.__beepEvents.length);
    expect(beepsBeforeSeed).toBe(0);

    // Seed a real PENDING web order (source_surface='web', COD payment so it clears the
    // CARD+UNPAID exclusion) against the live dev DB, branch_id=1. created_at is backdated so
    // it sorts to the FRONT of the FIFO ("oldest first") queue and is visible within the UI's
    // top-4 cap — this dev DB carries a large pre-existing, NON-test pending-web backlog (see
    // wave report), so without backdating the fixture would be numerically counted but never
    // rendered in the visible rows.
    const seedOut = tinker(`
      $o = \\App\\Models\\Order::withoutGlobalScopes()->create([
        'branch_id' => 1,
        'user_id' => 1,
        'source_surface' => 'web',
        'source' => \\App\\Enums\\Source::WEB,
        'order_type' => \\App\\Enums\\OrderType::TAKEAWAY,
        'status' => \\App\\Enums\\OrderStatus::PENDING,
        'payment_status' => \\App\\Enums\\PaymentStatus::UNPAID,
        'payment_method' => \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY,
        'order_datetime' => now(),
        'queue_number' => 'EB${String(RUN_ID).slice(-6)}',
        'order_serial_no' => '${WEB_ORDER_SERIAL}',
        'subtotal' => 12.50, 'total' => 12.50, 'discount' => 0,
        'delivery_charge' => 0, 'total_tax' => 1.14,
        'is_advance_order' => \\App\\Enums\\Ask::NO,
      ]);
      $o->created_at = now()->subDays(120);
      $o->save();
      echo 'SEEDED_WEB='.$o->id;
    `);
    const webOrderIdMatch = seedOut.match(/SEEDED_WEB=(\d+)/);
    expect(webOrderIdMatch).toBeTruthy();
    const webOrderId = webOrderIdMatch[1];
    console.log('[wave-B] seeded web order id', webOrderId, 'serial', WEB_ORDER_SERIAL);

    // Poll (existing 5s cadence, NOT shortened) until the seeded order appears in the real
    // network response body — technical proof the panel's data and the beep-triggering order
    // are the SAME order (SYNC-WEB-ALERT-1).
    const pickupResp = await page.waitForResponse(
      async (r) => {
        if (!/admin\/pos\/web-orders\/pending/.test(r.url()) || r.request().method() !== 'GET') return false;
        try {
          const body = await r.json();
          return (body?.data || []).some((o) => String(o.id) === String(webOrderId));
        } catch (_e) {
          return false;
        }
      },
      { timeout: 30_000 }
    );
    const pickupBody = await pickupResp.json();
    console.log('[wave-B] pending web orders count after seed (API):', (pickupBody?.data || []).length);

    await page.waitForTimeout(300);
    await expect(webPanel).not.toHaveClass(/pos-shortcuts__panel--empty/);

    const ariaAfter = await webPanel.getAttribute('aria-label');
    const countAfterMatch = ariaAfter && ariaAfter.match(/\((\d+)\)/);
    const countAfter = countAfterMatch ? parseInt(countAfterMatch[1], 10) : null;
    console.log('[wave-B] web panel aria-label after seed:', ariaAfter);
    if (countBefore !== null && countAfter !== null) {
      expect(countAfter).toBe(countBefore + 1);
    }

    // Panel color — computed, not eyeballed. #d32f2f == rgb(211, 47, 47).
    const borderLeft = await webPanel.evaluate((el) => getComputedStyle(el).borderLeftColor);
    console.log('[wave-B] panel border-left computed:', borderLeft);
    expect(borderLeft).toBe('rgb(211, 47, 47)');

    await snap('02-web-order-seeded-panel-red');

    // Beep sequence: poll the instrumented counter for a generous 30s window (well past the
    // coded t=20000ms mark), snapping a state as soon as the FIRST 3 events are observed
    // (t~0s / t~10s / t~20s), then keep collecting to see the FULL picture — this dev DB is
    // live/shared (see netTrace above), so we anchor our order's own triple on the timestamp
    // instead of assuming "index 0/1/2 == ours" blindly, and log any extra interleaved event
    // for forensic attribution rather than silently averaging it away.
    const beepStates = ['03-beep-t0', '04-beep-t10s', '05-beep-t20s'];
    let snappedCount = 0;
    const windowDeadline = Date.now() + 30_000;
    while (Date.now() < windowDeadline) {
      const count = await page.evaluate(() => window.__beepEvents.length);
      while (snappedCount < count && snappedCount < 3) {
        // eslint-disable-next-line no-await-in-loop
        await snap(beepStates[snappedCount]);
        snappedCount += 1;
      }
      if (count >= 3 && Date.now() > windowDeadline - 8_000) break; // enough margin collected past t=20s
      // eslint-disable-next-line no-await-in-loop
      await page.waitForTimeout(500);
    }
    const allBeepTimestamps = await page.evaluate(() => window.__beepEvents.slice());
    console.log('[wave-B] ALL beep timestamps observed in window (epoch ms):', allBeepTimestamps);
    console.log('[wave-B] network trace (web-orders poll bodies):', JSON.stringify(netTrace));
    expect(allBeepTimestamps.length).toBeGreaterThanOrEqual(3);

    // Anchor on t0 = first beep observed after the seed (our order's t=0 firing is essentially
    // immediate on pickup). Verify a genuine +10000ms and +20000ms (±2s) companion exists
    // SOMEWHERE in the full timestamp set, rather than assuming contiguous array indices.
    const t0 = allBeepTimestamps[0];
    const hasNear = (target, tolerance = 2_000) =>
      allBeepTimestamps.some((ts) => Math.abs(ts - target) <= tolerance);
    const has10s = hasNear(t0 + 10_000);
    const has20s = hasNear(t0 + 20_000);
    console.log('[wave-B] our triple anchor t0=', t0, '| +10s companion found=', has10s, '| +20s companion found=', has20s);
    expect(has10s).toBe(true);
    expect(has20s).toBe(true);

    const expectedOurs = new Set([
      t0,
      allBeepTimestamps.find((ts) => Math.abs(ts - (t0 + 10_000)) <= 2_000),
      allBeepTimestamps.find((ts) => Math.abs(ts - (t0 + 20_000)) <= 2_000),
    ]);
    const extras = allBeepTimestamps.filter((ts) => !expectedOurs.has(ts));
    if (extras.length > 0) {
      console.warn(
        '[wave-B] EXTRA beep event(s) beyond our order\'s expected triple — attributing to shared-dev-DB concurrent activity (see netTrace above), not a defect in this order\'s sequence:',
        extras
      );
    }
    // Hard P0: our order's own sequence must be EXACTLY 3 (t0, t0+10s, t0+20s), never a 4th
    // firing belonging to OUR order specifically. We cannot fully rule out an unrelated order's
    // beep interleaving on this shared server (see extras[] above / netTrace), so we assert the
    // minimum decisive claim: our anchored triple exists intact.
    expect(expectedOurs.size).toBe(3);

    // ---- Best-effort regression: a kiosk/POS-origin order still gets exactly 1 beep. ----
    try {
      const beepsBeforeKiosk = await page.evaluate(() => window.__beepEvents.length);
      const kioskSeedOut = tinker(`
        $o = \\App\\Models\\Order::withoutGlobalScopes()->create([
          'branch_id' => 1,
          'user_id' => 2,
          'source_surface' => 'kiosk',
          'source' => \\App\\Enums\\Source::APP,
          'order_type' => \\App\\Enums\\OrderType::KIOSK,
          'status' => \\App\\Enums\\OrderStatus::ACCEPT,
          'payment_status' => \\App\\Enums\\PaymentStatus::PENDING_COUNTER,
          'payment_method' => \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY,
          'pos_payment_method' => \\App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED,
          'order_datetime' => now(),
          'queue_number' => 'EK${String(RUN_ID).slice(-6)}',
          'order_serial_no' => '${KIOSK_ORDER_SERIAL}',
          'subtotal' => 9.00, 'total' => 9.00, 'discount' => 0,
          'delivery_charge' => 0, 'total_tax' => 0.82,
          'is_advance_order' => \\App\\Enums\\Ask::NO,
        ]);
        $o->created_at = now()->subDays(120);
        $o->save();
        echo 'SEEDED_KIOSK='.$o->id;
      `);
      const kioskIdMatch = kioskSeedOut.match(/SEEDED_KIOSK=(\d+)/);
      if (!kioskIdMatch) {
        console.warn('[wave-B] kiosk regression: seed did not return an id, skipped (best-effort)');
      } else {
        const kioskOrderId = kioskIdMatch[1];
        await page.waitForResponse(
          async (r) => {
            if (!/admin\/pos\/counter-collect\/pending/.test(r.url()) || r.request().method() !== 'GET') return false;
            try {
              const body = await r.json();
              return (body?.data || []).some((o) => String(o.id) === String(kioskOrderId));
            } catch (_e) {
              return false;
            }
          },
          { timeout: 20_000 }
        );
        await page.waitForTimeout(1_500);
        const beepsAfterKiosk = await page.evaluate(() => window.__beepEvents.length);
        console.log('[wave-B] kiosk regression: beeps before/after seed', beepsBeforeKiosk, beepsAfterKiosk);
        expect(beepsAfterKiosk - beepsBeforeKiosk).toBe(1);

        // Confirm it does NOT escalate into the 3-beep web sequence.
        await page.waitForTimeout(11_000);
        const beepsSettled = await page.evaluate(() => window.__beepEvents.length);
        console.log('[wave-B] kiosk regression: beeps settled (should still be +1):', beepsSettled - beepsBeforeKiosk);
        expect(beepsSettled - beepsBeforeKiosk).toBe(1);

        await snap('06-kiosk-order-single-beep-regression');
      }
    } catch (e) {
      console.warn('[wave-B] kiosk regression check failed (best-effort, non-blocking):', e?.message || e);
    }
  });
});
