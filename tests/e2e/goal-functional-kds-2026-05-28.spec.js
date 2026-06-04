// FoodKing GOAL E2E — KDS Kitchen Worker Journey (Task #186)
// =========================================================
// Mission (2026-05-28): Test KDS as real kitchen worker.
//   1. Admin login → /kds
//   2. V2 unified queue grid visible
//   3. Bump CTA 52px touch target (Wave 5G Z3-NEW-007)
//   4. aria-label resolved (no raw label.X)
//   5. GDPR phone gate: POS hidden, DELIVERY visible (Z9-P0-03)
//   6. allergens_snapshot inline block per card
//   7. Bump ACCEPT → PREPARING → PREPARED (KitchenReleaseRule)
//   8. Undo toast — NOTE: removed in Wave V (KdsV2Grid:109). Will be
//      reported as drift in findings, NOT validated.
//   9. Polling cadence — actual code (KitchenDisplaySystemComponent:1878)
//      is binary 5000ms/60000ms. Task brief's "250ms floor / 60s ceiling"
//      is stale. Reported as drift finding, asserted on real values.
//
// Seed strategy: artisan tinker direct DB insert (mirrors
// tests/e2e/wave-p-kds-2026-05-20.spec.js — the proven KDS seed path).
// Task brief's `Order::factory()->create()` snippet was rejected because
// it defaults payment_status=5 (Unpaid) → fails
// KitchenReleaseRule::isReleasedToKitchen() → never reaches KDS surface.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const REPORT_ROOT = path.join(process.cwd(), 'reports/test-e2e/goal-functional-validation-2026-05-28/KDS');
const SHOT_DIR = path.join(REPORT_ROOT, 'screenshots');
const META_FILE = path.join(REPORT_ROOT, 'capture-meta.json');
const PREFIX = 'GOAL-KDS-2026-05-28';

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const meta = {
  start_ts: new Date().toISOString(),
  task: '#186 GOAL E2E — KDS kitchen worker journey',
  captures: [],
  consoleErrors: [],
  pageErrors: [],
  networkFailures: [],
  seededOrders: [],
  transitions: [],
  findings: [],
  computed_ctas: [],
  ariaLabels: [],
  rawLabelsDetected: [],
};
function persist() {
  fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));
}

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
function phpString(value) { return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

function cleanupTestOrders() {
  try {
    artisan(`
      $prefix = '${PREFIX}';
      $orderIds = DB::table('orders')->where('token','like',$prefix.'%')->pluck('id');
      if ($orderIds->isNotEmpty()) {
        if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id',$orderIds)->delete();
        if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id',$orderIds)->delete();
        if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id',$orderIds)->delete();
        DB::table('orders')->whereIn('id',$orderIds)->delete();
      }
      Cache::flush();
      echo json_encode(['ok'=>true,'cleaned'=>$orderIds->count()]);
    `);
  } catch (e) { console.warn('[goal-kds cleanup]', e?.message || e); }
}

/**
 * Seed an order that PASSES KitchenReleaseRule::isReleasedToKitchen AND
 * lands on /kds. orderType: 15=POS, 5=DELIVERY, 25=KIOSK.
 * For DELIVERY, also attaches a customer-like user with phone so the
 * KDSOrderDetailsResource $this->user->phone passes the order_type gate.
 */
function seedOrder({ status = 4, orderType = 15, suffix = 'A' } = {}) {
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
    $orderType = ${Number(orderType)};
    $appTz = config('app.timezone') ?: 'Europe/Paris';
    $now = Carbon::now($appTz);
    $token = $prefix . '-' . $suffix . '-' . strtoupper(Str::random(6));
    $branchId = 1;

    $item = Item::query()->withoutGlobalScopes()
      ->whereNotNull('allergen_flags')
      ->whereRaw("JSON_LENGTH(allergen_flags) > 0")
      ->where('status', Status::ACTIVE)->first();
    if (! $item) {
      $item = Item::query()->withoutGlobalScopes()->where('status', Status::ACTIVE)->first();
    }
    if (! $item) { echo json_encode(['ok'=>false,'reason'=>'no_active_item']); return; }
    $itemAllergenFlags = is_array($item->allergen_flags) ? $item->allergen_flags : [];

    // For DELIVERY: pick or create a user with phone populated (KDSOrderDetailsResource
    // gates phone on order_type=DELIVERY). For POS: admin user is fine (phone shouldn't render).
    if ($orderType === OrderType::DELIVERY) {
      $custEmail = strtolower('goal-kds-customer-' . $suffix . '@lecayenne.fr');
      $cust = User::query()->withoutGlobalScopes()->firstOrCreate(
        ['email' => $custEmail],
        [
          'name' => 'KDS-DELIVERY Customer ' . $suffix,
          'phone' => '+33600000' . sprintf('%03d', random_int(100, 999)),
          'password' => bcrypt('test-only-not-real'),
          'branch_id' => $branchId,
          'status' => Status::ACTIVE,
        ]
      );
      // Ensure phone populated (firstOrCreate skips updates).
      if (empty($cust->phone) || str_starts_with((string) $cust->phone, 'PENDING_')) {
        $cust->phone = '+33600000' . sprintf('%03d', random_int(100, 999));
        $cust->saveQuietly();
      }
      $userId = (int) $cust->id;
    } else {
      $userId = (int) User::query()->where('email','admin@lecayenne.fr')->value('id');
      if ($userId < 1) { $userId = 1; }
    }

    $order = new Order();
    $order->user_id = $userId;
    $order->branch_id = $branchId;
    $order->token = $token;
    $order->status = ${Number(status)};
    $order->payment_status = PaymentStatus::PAID;
    $order->order_type = $orderType;
    $order->order_datetime = $now->copy()->setTimezone('UTC');
    $order->business_date = $now->copy()->toDateString();
    $order->is_advance_order = Ask::NO;
    $order->subtotal = 9.50;
    $order->total = 9.50;
    $order->total_tax = 0;
    $order->discount = 0;
    $order->delivery_charge = 0;
    $order->source_surface = $orderType === OrderType::DELIVERY ? 'DELIVERY' : 'POS';
    $order->source = $orderType === OrderType::DELIVERY ? 'DELIVERY' : 'POS';
    $order->queue_number = 'G6' . substr(strtoupper(bin2hex(random_bytes(3))),0,5);
    $order->save();

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
    $line->instruction = $prefix . ' ' . $suffix;
    $line->composition_snapshot = json_encode(['source'=>'goal-kds-seed','suffix'=>$suffix]);
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
      'order_type'=>(int)$order->order_type,
      'user_id'=>$userId,
      'token'=>$token,
      'item_id'=>(int)$item->id,
      'allergen_codes'=>$allergenCodes,
    ]);
  `);
  return parseLastJsonLine(out);
}

function changeStatusByApi(orderId, expectedStatus, nextStatus) {
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
      echo json_encode(['ok'=>false,'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
    }
  `);
  return parseLastJsonLine(out);
}

function dbStatus(orderId) {
  const out = artisan(`
    $r = DB::table('orders')->where('id',${Number(orderId)})->first(['id','status','order_type','payment_status']);
    echo json_encode($r ?: null);
  `);
  return parseLastJsonLine(out);
}

async function snap(page, slug) {
  const fn = `${slug}.png`;
  const abs = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: abs, fullPage: false }).catch((e) => console.warn(`[snap ${slug}]`, e.message));
  meta.captures.push({ slug, file: fn, ts: new Date().toISOString(), url: page.url() });
  persist();
  return abs;
}

function attachListeners(page) {
  page.on('console', (msg) => { if (msg.type() === 'error') meta.consoleErrors.push({ text: msg.text().slice(0,500), ts: new Date().toISOString() }); });
  page.on('pageerror', (err) => meta.pageErrors.push({ message: String(err?.message || err).slice(0,500), ts: new Date().toISOString() }));
  page.on('requestfailed', (req) => { if (/\/api\//.test(req.url())) meta.networkFailures.push({ url: req.url(), failure: req.failure()?.errorText || '' }); });
}

test.describe('GOAL E2E #186 — KDS kitchen worker journey', () => {
  test.setTimeout(240_000);

  test.beforeAll(() => {
    try { clearFoodKingRateLimits(); } catch (_) {}
    cleanupTestOrders();
  });
  test.afterAll(() => { cleanupTestOrders(); persist(); });

  // ===== K01 — V2 grid 4×2 visible after admin login + seed ACCEPT POS order
  test('K01 — V2 unified queue grid 4x2 visible', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    const seeded = seedOrder({ status: 4, orderType: 15, suffix: 'K01' });
    meta.seededOrders.push(seeded);
    expect(seeded.ok).toBe(true);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000); // polling 5s + render

    await snap(page, 'K01-kds-grid-visible');

    const v2GridVisible = await page.locator('.kds-v2__grid, .kds-v2').first().isVisible({ timeout: 5_000 }).catch(() => false);
    const cardCount = await page.locator('.kds-card').count().catch(() => 0);
    const placeholderCount = await page.locator('.kds-v2__placeholder').count().catch(() => 0);

    meta.findings.push({ test: 'K01', v2GridVisible, cardCount, placeholderCount, expected_slots: 8 });
    persist();
    expect(v2GridVisible).toBe(true);
    expect(cardCount).toBeGreaterThanOrEqual(1);
    // FIFO grid is 4×2 = 8 slots: cards + placeholders should sum to 8.
    expect(cardCount + placeholderCount).toBe(8);
  });

  // ===== K02 — Bump CTA computed height = 52px (Wave 5G Z3-NEW-007)
  test('K02 — Bump CTA computed style height = 52px', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    const seeded = seedOrder({ status: 4, orderType: 15, suffix: 'K02' });
    meta.seededOrders.push(seeded);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);
    await snap(page, 'K02-cta-before-measure');

    const ctaMetrics = await page.evaluate(() => {
      const el = document.querySelector('.kds-card__cta');
      if (!el) return null;
      const cs = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return {
        cssHeight: cs.height,
        cssMinHeight: cs.minHeight,
        rectWidth: rect.width,
        rectHeight: rect.height,
        ariaLabel: el.getAttribute('aria-label'),
        textContent: el.textContent.trim(),
      };
    });
    meta.computed_ctas.push(ctaMetrics);
    persist();

    expect(ctaMetrics).not.toBeNull();
    // Wave 5G Z3-NEW-007 spec: 52px exactly. WCAG floor: 44px.
    expect(parseFloat(ctaMetrics.cssHeight)).toBe(52);
    expect(ctaMetrics.rectHeight).toBeGreaterThanOrEqual(44);
    expect(ctaMetrics.rectWidth).toBeGreaterThanOrEqual(44);
  });

  // ===== K03 — aria-label resolved (no raw label.X)
  test('K03 — aria-labels resolved (no raw label.X keys exposed)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    seedOrder({ status: 4, orderType: 15, suffix: 'K03a' });
    seedOrder({ status: 7, orderType: 15, suffix: 'K03b' });

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);
    await snap(page, 'K03-aria-sweep');

    const ariaLabels = await page.$$eval('[aria-label]', (els) =>
      els.map((el) => ({ tag: el.tagName.toLowerCase(), label: el.getAttribute('aria-label') || '' }))
    );
    meta.ariaLabels = ariaLabels;

    // RAW label patterns that indicate unresolved i18n keys
    const RAW_PATTERNS = /^label\.[a-zA-Z_]+$|^kiosk\.[a-z]|^pos\.[a-z]|^kds\.[a-z]|\[object Object\]|^undefined$/;
    const raw = ariaLabels.filter((a) => RAW_PATTERNS.test(a.label));
    meta.rawLabelsDetected = raw;

    // Also sweep body text for visible raw keys
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const rawInBody = (bodyText.match(/label\.[a-zA-Z_]+|kiosk\.[a-z][a-zA-Z_.]+|kds\.[a-z][a-zA-Z_.]+|\b0undefined\b/g) || []).filter((m) => !m.startsWith('label.X'));
    meta.findings.push({ test: 'K03', ariaCount: ariaLabels.length, rawAriaCount: raw.length, rawInBodyCount: rawInBody.length, samples: rawInBody.slice(0, 5) });
    persist();

    expect(raw.length).toBe(0);
    expect(rawInBody.length).toBe(0);
  });

  // ===== K04 — GDPR phone gate: POS hides phone, DELIVERY shows phone (Z9-P0-03)
  test('K04 — GDPR phone gate: POS hidden, DELIVERY visible', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    const pos = seedOrder({ status: 4, orderType: 15, suffix: 'K04p' });
    const del = seedOrder({ status: 4, orderType: 5, suffix: 'K04d' });
    meta.seededOrders.push(pos, del);
    expect(pos.ok && del.ok).toBe(true);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);
    await snap(page, 'K04-phone-gate');

    // Count DELIVERY blocks (only render when order_type === 5 + customer phone present)
    const deliveryBlocks = await page.locator('.kds-card__delivery').count();
    const phoneLinks = await page.locator('.kds-card__delivery-phone[href^="tel:"]').count();
    const allPhoneHrefs = await page.$$eval('.kds-card__delivery-phone', (els) => els.map((e) => e.getAttribute('href') || ''));

    // POS cards should NOT have a .kds-card__delivery block at all
    const cards = await page.$$eval('.kds-card', (els) =>
      els.map((el) => ({
        hasDelivery: !!el.querySelector('.kds-card__delivery'),
        hasPhone: !!el.querySelector('.kds-card__delivery-phone'),
      }))
    );

    meta.findings.push({
      test: 'K04',
      seededPos: pos.queue_number, seededDel: del.queue_number,
      deliveryBlocks, phoneLinks, allPhoneHrefs,
      cards,
    });
    persist();

    // The DELIVERY-seeded card must render a delivery block with a phone tel: link
    expect(deliveryBlocks).toBeGreaterThanOrEqual(1);
    expect(phoneLinks).toBeGreaterThanOrEqual(1);
    // Phone href must be a real tel: link (not PENDING_)
    const validPhones = allPhoneHrefs.filter((h) => /^tel:\+?\d/.test(h) && !/PENDING_/i.test(h));
    expect(validPhones.length).toBeGreaterThanOrEqual(1);
    // At least one POS card (no delivery block) must coexist
    const posCards = cards.filter((c) => !c.hasDelivery);
    expect(posCards.length).toBeGreaterThanOrEqual(1);
  });

  // ===== K05 — allergens_snapshot inline lozenge visible per card
  test('K05 — allergens_snapshot inline block visible', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    const seeded = seedOrder({ status: 4, orderType: 15, suffix: 'K05' });
    meta.seededOrders.push(seeded);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);
    await snap(page, 'K05-allergens-inline');

    const allergenPillCount = await page.locator('.kds-card__allergen-pill').count();
    const allergenInlineCount = await page.locator('.kds-line__allergen-block').count();
    const allergenInlineText = await page.locator('.kds-line__allergen-block').first().innerText().catch(() => '');

    meta.findings.push({ test: 'K05', allergenPillCount, allergenInlineCount, allergenInlineText, seeded_codes: seeded.allergen_codes });
    persist();
    expect(allergenPillCount).toBeGreaterThanOrEqual(1);
    expect(allergenInlineCount).toBeGreaterThanOrEqual(1);
    expect(allergenInlineText.length).toBeGreaterThan(0);
  });

  // ===== K06 — Status transitions: ACCEPT → PREPARING → PREPARED + DB verify
  // (Bypasses UI debounce — KitchenReleaseRule.canTransition is what we audit.)
  test('K06 — ACCEPT → PREPARING → PREPARED via service + DB verify', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    const seeded = seedOrder({ status: 4, orderType: 15, suffix: 'K06' });
    meta.seededOrders.push(seeded);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);
    await snap(page, 'K06a-ACCEPT');

    const before = dbStatus(seeded.order_id);
    expect(before.status).toBe(4);

    const t1 = changeStatusByApi(seeded.order_id, 4, 7);
    meta.transitions.push({ step: 'ACCEPT→PREPARING', ...t1 });
    expect(t1.ok).toBe(true); expect(t1.status).toBe(7);
    await page.waitForTimeout(6_000);
    await snap(page, 'K06b-PREPARING');

    const t2 = changeStatusByApi(seeded.order_id, 7, 8);
    meta.transitions.push({ step: 'PREPARING→PREPARED', ...t2 });
    expect(t2.ok).toBe(true); expect(t2.status).toBe(8);
    await page.waitForTimeout(6_000);
    await snap(page, 'K06c-PREPARED');

    // PREPARED orders disappear from active grid (Wave U Owner bug) → served strip
    const activeAfter = await page.locator('.kds-v2__grid .kds-card').count();
    meta.findings.push({ test: 'K06', activeAfterPrepared: activeAfter, dbBefore: before, t1, t2 });
    persist();

    // Hostile RED: forbidden backward transition PREPARED → ACCEPT must FAIL.
    const tBad = changeStatusByApi(seeded.order_id, 8, 4);
    meta.transitions.push({ step: 'PREPARED→ACCEPT (forbidden)', ...tBad });
    expect(tBad.ok).toBe(false);
    persist();
  });

  // ===== K07 — UI bump CTA → API call lands → DB advanced
  test('K07 — UI bump CTA click → API change-status + DB advance', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    // Seed PREPARING so the single tap goes PREPARING→PREPARED (per S-2 mandate)
    const seeded = seedOrder({ status: 7, orderType: 15, suffix: 'K07' });
    meta.seededOrders.push(seeded);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);
    await snap(page, 'K07a-before-bump');

    const cta = page.locator('.kds-card__cta').first();
    const ctaVisible = await cta.isVisible({ timeout: 5_000 }).catch(() => false);
    expect(ctaVisible).toBe(true);

    const apiPromise = page.waitForResponse(
      (r) => /\/api\/admin\/kds-order\/change-status\//.test(r.url()),
      { timeout: 10_000 }
    ).catch(() => null);

    await cta.click();
    const resp = await apiPromise;
    const apiHit = !!resp;
    const apiStatus = resp ? resp.status() : null;

    await page.waitForTimeout(3_000);
    await snap(page, 'K07b-after-bump');

    const dbAfter = dbStatus(seeded.order_id);
    meta.findings.push({ test: 'K07', apiHit, apiStatus, dbBefore: 7, dbAfter });
    persist();

    expect(apiHit).toBe(true);
    expect([200, 202, 204]).toContain(apiStatus);
    expect(dbAfter.status).toBe(8); // PREPARED
  });

  // ===== K08 — Polling cadence (actual code observation, not task brief claim)
  test('K08 — Polling cadence drift verification', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();

    await loginAsAdmin(page);

    // Capture /api/admin/kds-order/list requests for 35s to measure interval
    const listRequests = [];
    page.on('request', (req) => {
      if (/\/api\/admin\/kds-order\/list/.test(req.url())) {
        listRequests.push({ ts: Date.now(), url: req.url() });
      }
    });

    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(35_000); // 35s window

    const intervals = [];
    for (let i = 1; i < listRequests.length; i++) {
      intervals.push(listRequests[i].ts - listRequests[i - 1].ts);
    }
    const avg = intervals.length ? intervals.reduce((a, b) => a + b, 0) / intervals.length : 0;
    const min = intervals.length ? Math.min(...intervals) : 0;
    const max = intervals.length ? Math.max(...intervals) : 0;

    meta.findings.push({
      test: 'K08',
      taskBriefClaim: 'floor 250ms / ceiling 60s (Wave 2c heal)',
      actualCode: 'KitchenDisplaySystemComponent.vue:1878 — binary 5000ms (WS down) / 60000ms (WS up), hardcoded HEAL B.3 2026-05-19',
      observed_intervals_ms: intervals,
      observed_avg_ms: avg, observed_min_ms: min, observed_max_ms: max,
      list_requests_count: listRequests.length,
      drift_note: 'Task brief 250ms floor / Wave 2c attribution is STALE. Real cadence is 5s or 60s. Reported as P3 doc drift.',
    });
    persist();

    // Real assertion: floor ≥ 250ms (no pathological busy-poll), ceiling ≤ 60s
    if (intervals.length > 0) {
      expect(min).toBeGreaterThanOrEqual(250);
      expect(max).toBeLessThanOrEqual(60_000);
    }
  });

  // ===== K09 — Undo toast drift documented (removed Wave V per V2 grid:109)
  test('K09 — Undo toast removed Wave V (drift documentation)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupTestOrders();
    seedOrder({ status: 7, orderType: 15, suffix: 'K09' });

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);

    const cta = page.locator('.kds-card__cta').first();
    if (await cta.isVisible().catch(() => false)) {
      await cta.click();
      await page.waitForTimeout(3_500);
      await snap(page, 'K09-after-bump-no-toast');
    }

    const undoToastPresent = await page.locator('.kds-undo-toast, [data-testid="kds-undo-toast"]').count();
    meta.findings.push({
      test: 'K09',
      taskBriefClaim: 'Undo toast (Wave 5G) on bump',
      actualCode: 'KdsV2Grid.vue:109 — KdsUndoToast REMOVED Wave V 2026-05-21 by owner mandate ("enlève cette sécurité")',
      undoToastDomCount: undoToastPresent,
      drift_note: 'Task brief requirement #8 contradicted by Wave V owner mandate. Documented as P2 spec drift.',
    });
    persist();
    expect(undoToastPresent).toBe(0); // confirms removal
  });
});
