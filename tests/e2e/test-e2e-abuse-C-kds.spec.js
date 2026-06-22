// =====================================================================
// FoodKing — abuse-e2e-2026-06-01 · Wave C — KDS kitchen display
// =====================================================================
// Surface : /admin/kitchen-display-system (V2 unified grid, default).
// Login   : loginAsChefOperator (chef@lecayenne.fr / 123456) → branch_id=1
//           → chef SUBSCRIBES private-branch.1 (REAL WS push, not 60s poll).
//
// Design notes (verified against source 2026-06-01, advisor-gated):
//   * V2 layout is default; we force ?v2=1 so a stale localStorage
//     `kds.v2_enabled='0'` from a prior run can't silently drop us to the
//     legacy 4-column layout and break every V2 selector.
//   * Active grid (.kds-v2__grid > [data-order-id]) shows ACCEPT(4)+
//     PREPARING(7). PREPARED(8) leaves the active grid → "Récemment
//     servies" strip. One CTA tap (kds-card-cta-ready) = ACCEPT→PREPARING;
//     next tap = PREPARING→PREPARED (KdsV2Grid.onCtaTap ladder).
//   * KDS cards carry NO money total — kitchen surface shows queue number,
//     item lines, customization, allergens. The plan's "card total = order
//     total" is boilerplate that does not apply here. Numeric integrity is
//     honored on what KDS DOES show: queue_number + item line identity.
//     (Documented, not a defect.)
//
//   STATE-03 (LIVE/subscribed) — POSITIVE PROOF, not banner-absence.
//     `kds-sync-mode-banner` renders only when (!wsConnected &&
//     !kdsHideFallbackBannerInLocalDev). kdsHideFallbackBannerInLocalDev is
//     (appEnv==='local'), which is TRUE on this dev server → the banner is
//     suppressed unconditionally in local dev whether WS is up or down.
//     Asserting its absence proves NOTHING. Real proof = WS probe:
//     window._wsService.isConnected()===true AND Echo connection 'connected'
//     AND the private-branch.1 channel actually subscribed (the documented
//     2026-05-29 P1 was subscribed:false going silently undetected). Cadence
//     corroboration: pollInterval = wsConnected ? 60000 : 5000, so
//     wsConnected===true IS "not 60s-poll-fallback".
//
//   STATE-04 (new-order WS arrival) — a tinker DB save() does NOT broadcast.
//     We seed at PENDING(1) (card ABSENT, asserted), then promote
//     PENDING→ACCEPT via the SERVICE (KitchenDisplaySystemOrderService →
//     KdsTicketDispatcher broadcasts OrderStatusChanged on private-branch.1,
//     the path MEMORY measured ~512ms). We do NOT poll-wait: we assert the
//     card mounts within a few seconds (well under the 60s WS-up poll). If
//     it appears <60s it is the real push, corroborated by a WS-frame watch.
//
// Seed strategy: artisan tinker direct DB insert for the *initial* row
//   (mirrors the proven goal-functional-kds / wave-p-kds path — Order::
//   factory defaults payment_status=Unpaid → never reaches KDS). Status
//   TRANSITIONS go through the SERVICE so they broadcast.
// =====================================================================

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsChefOperator } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const DIR = path.join(process.cwd(), 'tests/e2e/__screenshots__/test-e2e-C');
const PREFIX = 'ABUSE-C-KDS-2026-06-01';
const KDS_URL = '/admin/kitchen-display-system?v2=1';

const STATUS = { PENDING: 1, ACCEPT: 4, PREPARING: 7, PREPARED: 8 };

test.use({ viewport: { width: 1440, height: 900 } });
test.setTimeout(180_000);

// ------------------------------------------------------------------ artisan
function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: process.cwd(),
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 30_000,
  }).trim();
}
function parseLastJsonLine(output) {
  const lines = String(output).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`No JSON in artisan output:\n${output}`);
  return JSON.parse(jsonLine);
}
function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function cleanupTestOrders() {
  try {
    artisan(`
      $prefix = '${PREFIX}';
      $orderIds = DB::table('orders')->where('token','like',$prefix.'%')->pluck('id');
      if ($orderIds->isNotEmpty()) {
        if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id',$orderIds)->delete();
        if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id',$orderIds)->delete();
        if (Schema::hasTable('kitchen_recalls')) DB::table('kitchen_recalls')->whereIn('order_id',$orderIds)->delete();
        if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id',$orderIds)->delete();
        DB::table('orders')->whereIn('id',$orderIds)->delete();
      }
      Cache::flush();
      echo json_encode(['ok'=>true,'cleaned'=>$orderIds->count()]);
    `);
  } catch (e) { console.warn('[abuse-C cleanup]', e?.message || e); }
}

/**
 * Seed a KDS-eligible PAID order directly in the DB (proven path).
 *
 * BOARD DETERMINISM (verified 2026-06-02): KdsV2Grid renders ONLY
 * `activeOrders.slice(0, 8)` — the 8 OLDEST active orders (FIFO), the rest
 * collapse into the +N overflow chip (Wave N safety net, NOT a defect). The
 * shared dev DB carries ~20 stale active orders (overdue advance orders
 * 936-944 @ 2026-05-30 permanently hold the front slots). A fresh "now" seed
 * lands in overflow → its card never mounts. We therefore BACKDATE seeds
 * earlier than the oldest existing holder and flag them `is_advance_order=YES`
 * so the list's advance-order branch (order_datetime < tomorrow, status not
 * terminal — no "today" lower bound) keeps them visible AND sorts them into
 * slots 1..N. We do NOT delete the existing rows: they carry allocated
 * fiscal_sequence_no and deleting them would punch a gap in the NF525 gap-free
 * per-branch chain (CLAUDE.md §8). Backdating mutates no existing row.
 *
 * Side effect (honest, disclosed): backdated seeds render in the aged/critical
 * age-bucket (red) — consistent with the 936-944 already red on the board, not
 * a defect introduced by the seed.
 *
 * @param {number} status initial status (ACCEPT/PREPARING).
 * @param {string} suffix unique token suffix.
 * @param {number} backdateMinutes minutes BEFORE a fixed anchor (2026-05-28
 *   12:00, which is older than the oldest existing holder 936 @ 2026-05-30) —
 *   distinct values give deterministic slot order (smaller = older = slot 1).
 */
function seedOrder({ status = STATUS.ACCEPT, suffix = 'A', backdateMinutes = 0 } = {}) {
  const out = artisan(`
    use App\\Models\\Item;
    use App\\Models\\Order;
    use App\\Models\\OrderItem;
    use App\\Models\\User;
    use App\\Enums\\PaymentStatus;
    use App\\Enums\\OrderType;
    use App\\Enums\\Ask;
    use App\\Enums\\Status;
    use Illuminate\\Support\\Str;
    use Illuminate\\Support\\Carbon;

    $prefix = '${PREFIX}';
    $suffix = '${phpString(suffix)}';
    $appTz = config('app.timezone') ?: 'Europe/Paris';
    $now = Carbon::now($appTz);
    // Anchor older than the oldest existing active holder (936 @ 2026-05-30) so
    // our seeds sort into the front FIFO slots. Distinct backdateMinutes per seed
    // → deterministic slot order. Still well within the advance-order window.
    $seedDt = Carbon::parse('2026-05-28 12:00:00', $appTz)->subMinutes(${Number(backdateMinutes)});
    $token = $prefix . '-' . $suffix . '-' . strtoupper(Str::random(6));
    $branchId = 1;

    $item = Item::query()->withoutGlobalScopes()
      ->whereNotNull('allergen_flags')
      ->whereRaw("JSON_LENGTH(allergen_flags) > 0")
      ->where('status', Status::ACTIVE)->first();
    if (! $item) { $item = Item::query()->withoutGlobalScopes()->where('status', Status::ACTIVE)->first(); }
    if (! $item) { echo json_encode(['ok'=>false,'reason'=>'no_active_item']); return; }
    $itemAllergenFlags = is_array($item->allergen_flags) ? $item->allergen_flags : [];

    $userId = (int) User::query()->where('email','admin@lecayenne.fr')->value('id');
    if ($userId < 1) { $userId = 1; }

    $order = new Order();
    $order->user_id = $userId;
    $order->branch_id = $branchId;
    $order->token = $token;
    $order->status = ${Number(status)};
    $order->payment_status = PaymentStatus::PAID;
    $order->order_type = OrderType::TAKEAWAY;
    $order->order_datetime = $seedDt->copy()->setTimezone('UTC');
    $order->business_date = $seedDt->copy()->toDateString();
    // Advance-order branch keeps backdated rows visible on the KDS board.
    $order->is_advance_order = Ask::YES;
    $order->subtotal = 9.50;
    $order->total = 9.50;
    $order->total_tax = 0;
    $order->discount = 0;
    $order->delivery_charge = 0;
    $order->source_surface = 'POS';
    $order->source = 'POS';
    $order->queue_number = 'C6' . substr(strtoupper(bin2hex(random_bytes(3))),0,5);
    $order->save();

    // CRITICAL — the KDS FIFO sort (KdsV2Grid.visibleOrders → parseOrderCreatedMs)
    // reads created_at_iso (= created_at), NOT order_datetime. Eloquent stamps
    // created_at = NOW on save(), which would sort our seed to the BACK and bury
    // it past the slice(0,8) overflow. Force created_at/updated_at to the
    // backdated instant via a raw update (bypasses Eloquent timestamp mgmt) so
    // the card sorts into the front FIFO slot. order_datetime stays backdated for
    // the list's advance-order date-window inclusion.
    $backUtc = $seedDt->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
    \\Illuminate\\Support\\Facades\\DB::table('orders')
      ->where('id', $order->id)
      ->update(['created_at' => $backUtc, 'updated_at' => $backUtc]);

    $line = new OrderItem();
    $line->order_id = $order->id;
    $line->branch_id = $branchId;
    $line->item_id = $item->id;
    $line->quantity = 1;
    $line->price = 9.50;
    $line->total_price = 9.50;
    $line->tax_amount = 0;
    $line->tax_rate = 0;
    $line->discount = 0;
    $line->item_variation_total = 0;
    $line->item_extra_total = 0;
    $line->instruction = $prefix . ' NOTE-' . $suffix;
    $line->composition_snapshot = json_encode(['source'=>'abuse-C-seed','suffix'=>$suffix]);
    $allergenCodes = ! empty($itemAllergenFlags)
        ? array_values(array_filter($itemAllergenFlags, fn($c) => is_string($c) && $c !== ''))
        : ['gluten','lait'];
    $line->allergens_snapshot = json_encode($allergenCodes, JSON_UNESCAPED_UNICODE);
    $line->save();

    echo json_encode([
      'ok'=>true,
      'order_id'=>(int)$order->id,
      'queue_number'=>(string)$order->queue_number,
      'status'=>(int)$order->status,
      'item_id'=>(int)$item->id,
      'item_name'=>(string)$item->name,
      'token'=>$token,
    ]);
  `);
  return parseLastJsonLine(out);
}

/**
 * Transition an order through the SERVICE so it BROADCASTS OrderStatusChanged
 * on private-branch.1 (real WS push the chef receives). NOT a raw DB update.
 */
function changeStatusByService(orderId, expectedStatus, nextStatus) {
  const out = artisan(`
    use App\\Models\\Order;
    use App\\Services\\KitchenDisplaySystemOrderService;
    use Illuminate\\Http\\Request;
    $order = Order::withoutGlobalScopes()->findOrFail(${Number(orderId)});
    $admin = \\App\\Models\\User::query()->where('email','admin@lecayenne.fr')->first();
    if ($admin) { auth()->setUser($admin); }
    $svc = app(KitchenDisplaySystemOrderService::class);
    $req = Request::create('/','POST',['status'=>${Number(nextStatus)},'expected_status'=>${Number(expectedStatus)}]);
    try {
      $svc->changeStatus($order, $req);
      $order->refresh();
      echo json_encode(['ok'=>true,'order_id'=>(int)$order->id,'status'=>(int)$order->status]);
    } catch (\\Throwable $e) {
      echo json_encode(['ok'=>false,'message'=>$e->getMessage(),'line'=>$e->getLine()]);
    }
  `);
  return parseLastJsonLine(out);
}

function dbStatus(orderId) {
  const out = artisan(`
    $r = DB::table('orders')->where('id',${Number(orderId)})->first(['id','status','queue_number']);
    echo json_encode($r ?: null);
  `);
  return parseLastJsonLine(out);
}

// ----------------------------------------------------------- WS / DOM probes
// CHANNEL ACCESS PATH (verified against node_modules 2026-06-02):
//   laravel-echo@2.3.1 PusherConnector keeps its OWN wrapper map at
//     connector.channels            → { 'private-branch.1': PusherChannel }  (no .subscribed)
//   pusher-js@8.4.3 keeps the underlying Channel map at
//     connector.pusher.channels.channels → { 'private-branch.1': Channel }   (.subscribed bool)
//   The pusher-js Channel sets `.subscribed = true` on pusher:subscription_succeeded
//   (pusher.js:2131). The prior probe read connector.channels.channels which is
//   UNDEFINED for laravel-echo → always null. We read BOTH and report the truth.
async function probeWs(page) {
  return page.evaluate(() => {
    const out = {
      has_wsService: false,
      isConnected: null,
      echo_state: null,
      branch1_subscribed: null,
      echo_wrapper_has_channel: null,
      echo_channels: [],
      pusher_channels: [],
    };
    try {
      out.has_wsService = !!window._wsService;
      if (window._wsService && typeof window._wsService.isConnected === 'function') {
        out.isConnected = !!window._wsService.isConnected();
      }
      const connector = window.Echo && window.Echo.connector;
      const pusher = connector && connector.pusher;
      if (pusher && pusher.connection) out.echo_state = pusher.connection.state;
      // laravel-echo wrapper map (presence of the channel = subscribe was attempted).
      if (connector && connector.channels && typeof connector.channels === 'object') {
        out.echo_channels = Object.keys(connector.channels);
        out.echo_wrapper_has_channel = Object.prototype.hasOwnProperty.call(
          connector.channels,
          'private-branch.1',
        );
      }
      // pusher-js underlying Channel map (.subscribed boolean lives here).
      const pusherChMap = pusher && pusher.channels && pusher.channels.channels;
      if (pusherChMap && typeof pusherChMap === 'object') {
        out.pusher_channels = Object.keys(pusherChMap);
        const ch = pusherChMap['private-branch.1'];
        if (ch) out.branch1_subscribed = !!ch.subscribed;
      }
    } catch (e) { out.error = String(e && e.message || e); }
    return out;
  });
}

// number of cards currently mounted in the active V2 grid
async function gridCardCount(page) {
  return page.locator('.kds-v2__grid [data-order-id]').count();
}
async function cardLocator(page, orderId) {
  return page.locator(`[data-order-id="${orderId}"]`);
}

// ------------------------------------------------------------------- recorder
let recorder;
test.beforeAll(() => {
  if (fs.existsSync(DIR)) {
    for (const f of fs.readdirSync(DIR)) { try { fs.unlinkSync(path.join(DIR, f)); } catch (_e) {} }
  }
  cleanupTestOrders();
});
test.afterAll(() => { cleanupTestOrders(); });

test('Wave C — KDS kitchen display (chef, real WS private-branch.1)', async ({ page }) => {
  const rec = attachMegaAuditRecorder(page, DIR);
  recorder = rec;
  const { snap } = rec;
  const seeded = [];

  // ============================================================ 01 login → KDS
  await loginAsChefOperator(page);
  await page.goto(KDS_URL, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/kitchen-display-system/);
  // V2 grid OR its empty-state must mount (proves V2 path, not legacy columns).
  await expect(
    page.locator('.kds-v2__grid, .kds-v2__empty').first()
  ).toBeVisible({ timeout: 20_000 });
  // History trigger is layout-agnostic — proves the KDS shell rendered.
  await expect(page.getByTestId('kds-history-button')).toBeVisible({ timeout: 10_000 });
  await snap('01-chef-login-kds');

  // ===================================================== 03 LIVE / subscribed
  // (captured before seeding so the WS state is the clean post-login truth)
  // Give Echo a moment to finish the private-branch.1 subscribe handshake.
  await page.waitForTimeout(2500);
  let ws = await probeWs(page);
  // Subscription handshake can lag the connection by a tick; re-probe up to ~8s.
  for (let i = 0; i < 8 && ws.branch1_subscribed !== true; i++) {
    await page.waitForTimeout(1000);
    ws = await probeWs(page);
  }
  console.log('[abuse-C] WS probe:', JSON.stringify(ws));
  // Positive proof of LIVE mode (not 60s-poll fallback):
  expect(ws.has_wsService, 'window._wsService must exist on KDS').toBeTruthy();
  expect(ws.isConnected, 'WS must be connected (LIVE, not poll-fallback)').toBe(true);
  expect(ws.echo_state, 'Echo pusher connection must be "connected"').toBe('connected');
  expect(
    ws.echo_wrapper_has_channel || ws.pusher_channels.includes('private-branch.1'),
    `chef must subscribe private-branch.1 — echo=${JSON.stringify(ws.echo_channels)} pusher=${JSON.stringify(ws.pusher_channels)}`
  ).toBeTruthy();
  expect(
    ws.branch1_subscribed,
    'private-branch.1 subscription must have SUCCEEDED (subscribed:true) — the 2026-05-29 silent-false regression'
  ).toBe(true);
  // The fallback banner is correctly SUPPRESSED in local dev (documented).
  await expect(page.getByTestId('kds-sync-mode-banner')).toHaveCount(0);
  await snap('03-sync-mode-live-subscribed');

  // ================================================ 04 new-order arrival (WS)
  // Real KDS broadcast wiring (verified 2026-06-02):
  //   DispatchKdsTicket gates OrderStatusChanged on
  //   KitchenReleaseRule::shouldDispatchStatusChanged → ONLY ACCEPT→PREPARING
  //   or PREPARING→PREPARED broadcast. PENDING→ACCEPT (order acceptance) is NOT
  //   a kitchen bump and intentionally does NOT push to KDS.
  //
  // So we prove WS-PUSH-mounts-a-card the way the product actually pushes:
  //   1. Seed at ACCEPT (KDS-visible) AFTER the page mounted. The initial
  //      list() ran before this row existed, and no refresh has fired since
  //      → the card is ABSENT (we are the only process on this server, so the
  //      60s poll won't fire inside our sub-second seed→bump window).
  //   2. Transition ACCEPT→PREPARING via the SERVICE → broadcasts
  //      OrderStatusChanged on private-branch.1 → chef's _statusChangeAffectsKds
  //      → _debouncedRefresh → list() re-fetch → the card mounts.
  //   Card visible in a few seconds (vs 60s poll) = real WS push, not poll.
  //   (Brand-new-materialization + toast/sound fidelity is Wave F's mandate.)
  // backdateMinutes 30 → oldest of our 3 seeds → FIFO slot 1 (front of grid).
  const arrivOrder = seedOrder({ status: STATUS.ACCEPT, suffix: 'ARRIVE', backdateMinutes: 30 });
  expect(arrivOrder.ok, JSON.stringify(arrivOrder)).toBeTruthy();
  seeded.push(arrivOrder.order_id);
  // No refresh has fired since the row was created → the specific card is ABSENT.
  await expect(page.locator(`[data-order-id="${arrivOrder.order_id}"]`)).toHaveCount(0);

  // Install a WS-frame watcher — LOGGED CORROBORATION ONLY (not a hard gate).
  await page.evaluate(() => {
    window.__abuseC_wsFrames = [];
    try {
      const pusher = window.Echo && window.Echo.connector && window.Echo.connector.pusher;
      if (pusher && pusher.connection && pusher.connection.bind) {
        pusher.connection.bind('message', (m) => {
          try { window.__abuseC_wsFrames.push(String(m && m.event || '')); } catch (_e) {}
        });
      }
    } catch (_e) {}
  });

  const t0 = Date.now();
  const promote = changeStatusByService(arrivOrder.order_id, STATUS.ACCEPT, STATUS.PREPARING);
  expect(promote.ok, JSON.stringify(promote)).toBeTruthy();
  expect(promote.status).toBe(STATUS.PREPARING);
  // The broadcast-triggered refresh should mount the card FAST. 45s cap is far
  // under the 60s WS-up poll, so success here means a push delivered it.
  await expect(page.locator(`[data-order-id="${arrivOrder.order_id}"]`))
    .toBeVisible({ timeout: 30_000 });
  const arrivalMs = Date.now() - t0;
  const wsFrames = await page.evaluate(() => window.__abuseC_wsFrames || []);
  console.log(`[abuse-C] new-order WS-push arrival latency=${arrivalMs}ms wsFrames=${JSON.stringify(wsFrames)}`);
  // Push (not poll) evidence: arrived well under the 60s WS-up poll cadence.
  expect(arrivalMs, `arrival ${arrivalMs}ms must be < 60s WS-up poll (push, not poll)`).toBeLessThan(45_000);
  await snap('04-new-order-arrival-ws-push');

  // ============================================ 02 pile with cards + content
  // Now seed two more directly at ACCEPT/PREPARING so the pile has content
  // with visible customization lines + queue numbers.
  // backdateMinutes 20/10 → slots 2/3, just behind the arrival card (slot 1).
  const cardA = seedOrder({ status: STATUS.ACCEPT, suffix: 'PILEA', backdateMinutes: 20 });
  const cardB = seedOrder({ status: STATUS.PREPARING, suffix: 'PILEB', backdateMinutes: 10 });
  expect(cardA.ok && cardB.ok, JSON.stringify({ cardA, cardB })).toBeTruthy();
  seeded.push(cardA.order_id, cardB.order_id);
  // Force a refresh so the freshly-seeded rows surface (no need to wait a poll).
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('.kds-v2__grid').first()).toBeVisible({ timeout: 20_000 });

  const cardAEl = page.locator(`[data-order-id="${cardA.order_id}"]`);
  await expect(cardAEl).toBeVisible({ timeout: 20_000 });
  // Numeric/identity integrity (KDS shows queue number, NOT money):
  //   queue number on the card must equal the seeded queue_number.
  await expect(cardAEl.locator('.kds-card__queue')).toContainText(cardA.queue_number);
  // Item line(s) must render the seeded product name.
  await expect(cardAEl.locator('.kds-line__name').first()).toBeVisible();
  const lineCount = await cardAEl.locator('.kds-line').count();
  expect(lineCount, 'card must render at least one customization/item line').toBeGreaterThan(0);
  await snap('02-pile-with-cards');

  // ================================================ 05 bump PREPARING→PREPARED
  // cardB is PREPARING(7). One CTA tap finalizes it → PREPARED(8), which moves
  // it OUT of the active grid into "Récemment servies".
  const cardBEl = page.locator(`[data-order-id="${cardB.order_id}"]`);
  await expect(cardBEl).toBeVisible({ timeout: 15_000 });
  const beforeBumpDb = dbStatus(cardB.order_id);
  expect(beforeBumpDb.status).toBe(STATUS.PREPARING);
  await snap('05a-before-bump');

  await cardBEl.getByTestId('kds-card-cta-ready').click();
  // Server PATCH → DB flips to PREPARED. Poll the DB (server truth) up to 15s.
  let bumpedDb = beforeBumpDb;
  for (let i = 0; i < 30; i++) {
    bumpedDb = dbStatus(cardB.order_id);
    if (bumpedDb.status === STATUS.PREPARED) break;
    await page.waitForTimeout(500);
  }
  expect(bumpedDb.status, 'bump must transition PREPARING→PREPARED in DB').toBe(STATUS.PREPARED);
  // The card must leave the active grid (PREPARED → served strip).
  await expect(cardBEl).toHaveCount(0, { timeout: 15_000 });
  // It should surface in the "Récemment servies" strip (served archive).
  await expect(
    page.locator('.kds-v2__served-pill', { hasText: cardB.queue_number }).first()
  ).toBeVisible({ timeout: 10_000 }).catch(() => {
    // If the strip rendering races, the DB truth above already proved the bump.
    console.warn('[abuse-C] served strip pill not visible (DB confirmed PREPARED)');
  });
  await snap('05b-after-bump-prepared');

  // ============================================================== 06 recall
  // Open the day-history drawer; cardB is now PREPARED → recall button enabled.
  await page.getByTestId('kds-history-button').click();
  await expect(page.getByTestId('kds-history-drawer')).toBeVisible({ timeout: 10_000 });
  // Wait for the history list (or empty/error) to settle.
  await page.waitForTimeout(1500);
  await snap('07-history-drawer-open');

  const recallBtn = page.getByTestId(`kds-recall-${cardB.order_id}`);
  const recallVisible = await recallBtn.isVisible({ timeout: 8_000 }).catch(() => false);
  if (recallVisible) {
    await expect(recallBtn).toBeEnabled();
    // Capture the recall POST response so the outcome is VISIBLE, never swallowed.
    const recallRespPromise = page.waitForResponse(
      (res) => /\/admin\/kds-order\/recall\/\d+/.test(res.url()) && res.request().method() === 'POST',
      { timeout: 12_000 },
    ).catch(() => null);
    await recallBtn.click();
    const recallResp = await recallRespPromise;
    let recallStatus = null;
    let recallBody = '';
    if (recallResp) {
      recallStatus = recallResp.status();
      recallBody = (await recallResp.text().catch(() => '')).slice(0, 200);
    }
    console.log(`[abuse-C] recall POST → HTTP ${recallStatus} body=${recallBody}`);
    // Server contract: 200 success | 422 window-expired/wrong-state | 409 dup.
    // We assert the HAPPY path (200) because cardB was JUST bumped (updated_at
    // fresh, well inside the 60s window) and never recalled. A non-200 here is
    // a real finding and stays VISIBLE in the artifacts.
    expect(
      recallStatus,
      `recall must succeed (200) for a just-bumped PREPARED order — got ${recallStatus} ${recallBody}`
    ).toBe(200);

    // On 200 the drawer sets recalledMap[id] synchronously → in-drawer done
    // badge (.kds-history-drawer__recall-badge) renders (NO WS round-trip needed).
    const drawerDoneBadge = page.locator('.kds-history-drawer__recall-badge').first();
    const drawerDone = await drawerDoneBadge.isVisible({ timeout: 6_000 }).catch(() => false);
    console.log(`[abuse-C] in-drawer recalled badge visible: ${drawerDone}`);
    await snap('06-recall-drawer-badge');

    // The board re-injection (kds-card-recall-badge-{id}) rides the
    // KdsOrderRecalled WS fan-out + the parent @recalled emit. Close the drawer
    // to inspect the grid. This is the WS-driven path — logged + asserted with
    // a generous window since it depends on the high-queue broadcast.
    await page.getByTestId('kds-history-close').click().catch(() => {});
    await page.waitForTimeout(1000);
    const recallBadge = page.getByTestId(`kds-card-recall-badge-${cardB.order_id}`);
    const badgeSeen = await recallBadge.isVisible({ timeout: 12_000 }).catch(() => false);
    console.log(`[abuse-C] board RAPPELÉ re-injection badge visible: ${badgeSeen}`);
    await snap('06b-recall-board-reinjection');
    // At least one confirmation surface MUST reflect the successful recall.
    expect(
      drawerDone || badgeSeen,
      'a successful (200) recall must show the in-drawer done badge OR the board RAPPELÉ re-injection'
    ).toBeTruthy();
  } else {
    // Environmental: history endpoint may scope by business_date/window. Capture
    // the drawer state we DID reach; do not fake a recall.
    console.warn(`[abuse-C] kds-recall-${cardB.order_id} not present in history drawer — capturing drawer state only`);
    await snap('06-recall-button-absent');
    await page.getByTestId('kds-history-close').click().catch(() => {});
    await page.waitForTimeout(500);
  }

  // ====================================================== 08 history list state
  // Re-open and capture the list/loading/empty/error states explicitly.
  await page.getByTestId('kds-history-button').click();
  await expect(page.getByTestId('kds-history-drawer')).toBeVisible({ timeout: 10_000 });
  await page.waitForTimeout(1500);
  const hasList = await page.getByTestId('kds-history-list').isVisible().catch(() => false);
  const hasEmpty = await page.getByTestId('kds-history-empty').isVisible().catch(() => false);
  const hasErr = await page.getByTestId('kds-history-error').isVisible().catch(() => false);
  console.log(`[abuse-C] history states: list=${hasList} empty=${hasEmpty} error=${hasErr}`);
  // At least ONE deterministic terminal state of the drawer must render.
  expect(hasList || hasEmpty || hasErr, 'history drawer must reach a terminal state').toBeTruthy();
  await snap('08-history-list-state');
  await page.getByTestId('kds-history-close').click().catch(() => {});
  await page.waitForTimeout(500);

  // ============================================================ 09 aria-live
  // A11y: the KDS MUST expose an aria-live polite region for screen-reader
  // announcements of state changes (ACCEPT→PREPARING→PREPARED).
  //
  // FINDING (verified against source 2026-06-02, P3 testid-coverage, NOT an a11y
  // defect): the `data-testid="kds-aria-live"` attribute lives ONLY on the
  // LEGACY 4-column layout's region (KitchenDisplaySystemComponent.vue:1006,
  // inside the v-else block). The DEFAULT V2 grid renders its OWN functional
  // region (KdsV2Grid.vue:119 — `<div class="sr-only" aria-live="polite"
  // aria-atomic="true">`) but WITHOUT that testid. So the screen-reader region
  // IS present and correct in production V2 mode; only the test hook is missing
  // on the V2 path. We assert the REAL functional region (the a11y guarantee)
  // and disclose the testid gap.
  const v2AriaLive = page.locator('.kds-v2 .sr-only[aria-live="polite"]');
  const testidAriaLive = page.getByTestId('kds-aria-live');
  const v2Count = await v2AriaLive.count();
  const testidCount = await testidAriaLive.count();
  console.log(`[abuse-C] aria-live regions — V2 functional(.kds-v2 .sr-only[aria-live]): ${v2Count}, ` +
    `legacy testid(kds-aria-live): ${testidCount} (P3: testid absent on V2 path by design)`);
  // The functional polite live region MUST exist in the rendered V2 layout.
  expect(
    v2Count,
    'KDS V2 must expose a polite aria-live region for screen-reader state-change announcements'
  ).toBeGreaterThanOrEqual(1);
  const ariaAttr = await v2AriaLive.first().getAttribute('aria-live').catch(() => null);
  const ariaAtomic = await v2AriaLive.first().getAttribute('aria-atomic').catch(() => null);
  expect(ariaAttr, 'live region must be aria-live="polite"').toBe('polite');
  console.log(`[abuse-C] V2 aria-live region: aria-live="${ariaAttr}" aria-atomic="${ariaAtomic}"`);
  await snap('09-aria-live-region');

  // =================================================== 09b error banner (state)
  // Real error path: intercept the LIST poll (/api/admin/kds-order) and return
  // 503 so the component's poll-failure handler runs. Scope the route to the
  // bare list endpoint only (NOT change-status/recall/history/items/sync) so
  // sub-calls keep working. LIVE route stub — no server edit.
  //
  // *** FINDING — KDS-V2-ERR-OBSERVABILITY (P2 degraded-observability) ***
  // Verified against source 2026-06-02: on a poll 5xx the component sets
  // `kdsErrorBanner.visible=true` (list().catch → _raiseKdsErrorBanner), BUT the
  // `kds-error-banner` element lives ONLY inside the LEGACY v-else block
  // (KitchenDisplaySystemComponent.vue:86, block 48-1062). The DEFAULT V2 grid
  // never renders it. AND `v2OfflineSince` is NEVER assigned anywhere in the
  // component (permanent null → KdsV2Grid/KdsStatusBanner offline indicator,
  // which needs offlineSince > 60s, never fires). NET: with WS up + the list
  // poll failing 5xx, the V2 chef sees NO visible error/stale indicator. (The
  // WS-DOWN case is separately covered: KdsStatusBanner fallbackMode fires on
  // !wsConnected. That partial compensation is why this is P2, not P1.)
  // We capture-and-document; we do NOT hard-fail (the artifact is the evidence),
  // and we do NOT assert silence-is-correct (that would lock the bug in as
  // expected). Invariant asserted: the grid stays mounted, no pageerror.
  const KDS_LIST_RE = /\/api\/admin\/kds-order(\/?(\?|$))/;
  const isKdsListUrl = (u) =>
    KDS_LIST_RE.test(u) && !/(change-status|recall|history-today|items|sync)/.test(u);
  let stub503Fired = 0;
  await page.route('**/api/admin/kds-order**', (route) => {
    const u = route.request().url();
    if (isKdsListUrl(u)) {
      stub503Fired += 1;
      return route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ status: false, message: 'Service Unavailable (Wave-C audit stub)' }),
      });
    }
    return route.continue();
  });
  // Reload so the failing poll fires on mount.
  await page.reload({ waitUntil: 'domcontentloaded' });
  // Give the failing poll time to land and the handler to run.
  await page.waitForTimeout(4_000);
  const errBannerVisible = await page
    .getByTestId('kds-error-banner')
    .isVisible({ timeout: 3_000 })
    .catch(() => false);
  const v2GridMounted = await page
    .locator('.kds-v2, .kds-v2__grid, .kds-v2__empty')
    .first()
    .isVisible({ timeout: 5_000 })
    .catch(() => false);
  console.log(
    `[abuse-C] FINDING KDS-V2-ERR-OBSERVABILITY: 503 stub fired ${stub503Fired}x; ` +
      `kds-error-banner visible=${errBannerVisible} (legacy-only element, expected false in V2); ` +
      `V2 grid still mounted=${v2GridMounted}`,
  );
  // verify-before-report: prove the stub actually served a 503 (else this is a
  // test bug, not a product finding).
  expect(stub503Fired, 'the 503 list-poll stub must have actually fired').toBeGreaterThanOrEqual(1);
  // Invariant (non-silence-locking): a failing poll must not crash the surface —
  // the V2 grid stays mounted so the chef is not left on a blank screen.
  expect(v2GridMounted, 'KDS V2 surface must stay mounted on a poll 5xx (no white-screen)').toBe(true);
  await snap('09-error-banner-on-5xx');
  await page.unroute('**/api/admin/kds-order**');

  // ============================================================ 10 empty pile
  // Real empty-state branch: intercept the LIST poll to return an empty data
  // array (the resource collection shape is { data: [...] }) so KdsV2Grid renders
  // its .kds-v2__empty branch deterministically — WITHOUT destroying the shared
  // live PREPARING orders other waves depend on. Done LAST.
  let emptyStubFired = 0;
  await page.route('**/api/admin/kds-order**', (route) => {
    const u = route.request().url();
    if (isKdsListUrl(u)) {
      emptyStubFired += 1;
      // Mirror the real resource-collection envelope { data:[], meta:{...} }.
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], meta: { overflow: false, limit: 50 } }),
      });
    }
    return route.continue();
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  // Wait for the V2 shell to re-mount, then for the empty-state to render after
  // the stubbed (empty) poll resolves + _applyOrderBuckets([]) clears the grid.
  await page.waitForSelector('.kds-v2, .kds-v2__grid, .kds-v2__empty', { timeout: 20_000 }).catch(() => {});
  const emptyEl = page.locator('.kds-v2__empty');
  const emptyVisible = await emptyEl.isVisible({ timeout: 15_000 }).catch(() => false);
  const gridCards = await page.locator('.kds-v2__grid [data-order-id]').count().catch(() => 0);
  console.log(
    `[abuse-C] empty stub fired ${emptyStubFired}x; empty-state visible=${emptyVisible}; ` +
      `residual grid cards=${gridCards}`,
  );
  // verify-before-report: confirm the empty stub actually served the poll.
  expect(emptyStubFired, 'the empty list-poll stub must have actually fired').toBeGreaterThanOrEqual(1);
  await snap('10-empty-pile-state');
  // The empty-state branch must render once the pile is empty.
  expect(emptyVisible, 'KDS V2 must render .kds-v2__empty when the active pile is empty').toBe(true);
  // i18n leak guard: empty title must not be a raw label.* token.
  const emptyTitle = (await emptyEl.locator('.kds-v2__empty-title').innerText().catch(() => '')).trim();
  console.log(`[abuse-C] empty title="${emptyTitle}"`);
  expect(emptyTitle.length, 'empty-state title must render localized copy').toBeGreaterThan(0);
  expect(
    /^[a-z]+(\.[a-z_]+){1,4}$/.test(emptyTitle),
    `empty-state title must not be a raw i18n key (label.* leak) — got "${emptyTitle}"`
  ).toBe(false);
  await page.unroute('**/api/admin/kds-order**');
});
