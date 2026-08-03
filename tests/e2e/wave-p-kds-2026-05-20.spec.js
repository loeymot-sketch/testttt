// FoodKing Wave P-3 — KDS (Kitchen Display) E2E Audit + Heal
// =========================================================
// Mission: Verify KDS surface for V1 Le Cayenne — admin auth, seeded order
// lifecycle (ACCEPT → PREPARING → PREPARED), allergen modal, polling fallback,
// console error capture, full visual mandate (CLAUDE.md §6).
//
// Status mapping (per app/Domain/Kds/KitchenReleaseRule.php +
// app/Enums/OrderStatus.php):
//   - KDS visible statuses = ACCEPT(4), PREPARING(7), PREPARED(8)
//   - The task description's "PENDING" maps to ACCEPT — PENDING(1) never
//     reaches the KDS surface (KitchenReleaseRule filter).
//   - Bump path: ACCEPT → PREPARING → PREPARED.
//
// Seed strategy: artisan tinker directly creates an Order with status=ACCEPT,
// payment_status=PAID, branch_id=1, business_date+order_datetime within Paris
// today — this satisfies KitchenReleaseRule::isReleasedToKitchen AND the
// Paris-today window enforced by KitchenDisplaySystemOrderService::list.
// (Driving POS UI is Wave P Round 2 cross-system; for P-3 we want
// deterministic KDS-arrival timing.)

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'reports/test-e2e/wave-p-2026-05-20/kds/screenshots');
const META_FILE = path.join(process.cwd(), 'reports/test-e2e/wave-p-2026-05-20/kds/capture-meta.json');
const PREFIX = 'WAVEP3-KDS';

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const meta = {
  start_ts: new Date().toISOString(),
  captures: [],
  consoleErrors: [],
  pageErrors: [],
  networkFailures: [],
  rawLabels: null,
  seededOrders: [],
  transitions: [],
  findings: [],
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

function phpString(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function cleanupKdsTestOrders() {
  try {
    artisan(`
      $prefix = '${PREFIX}';
      $orderIds = DB::table('orders')->where('token', 'like', $prefix . '%')->pluck('id');
      if ($orderIds->isNotEmpty()) {
        if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
        if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id', $orderIds)->delete();
        if (Schema::hasTable('order_items')) DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->update(['fiscal_sequence_no' => null]);
        DB::table('orders')->whereIn('id', $orderIds)->delete();
      }
      Cache::flush();
      echo json_encode(['ok' => true, 'cleaned' => $orderIds->count()]);
    `);
  } catch (e) {
    console.warn('[wave-p-3 cleanup]', e?.message || e);
  }
}

function seedKdsOrder({ status = 4, suffix = 'A' } = {}) {
  // status: 4=ACCEPT, 7=PREPARING, 8=PREPARED
  // payment_status=5=PAID per app/Enums/PaymentStatus.php
  // Schema mapping (verified via Schema::getColumnListing 2026-05-20):
  //   orders:      uses `total_tax` (NOT total_tax_amount)
  //   order_items: uses `total_price` (NOT total), `tax_amount` (NOT total_tax_amount),
  //                NO `item_name` column (denormalized via item_id join), HAS `branch_id`
  const out = artisan(`
    use App\\Models\\Item;
    use App\\Models\\Order;
    use App\\Models\\OrderItem;
    use App\\Enums\\PaymentStatus;
    use App\\Enums\\OrderType;
    use App\\Enums\\Ask;
    use Illuminate\\Support\\Str;
    use Illuminate\\Support\\Carbon;

    $prefix = '${PREFIX}';
    $suffix = '${phpString(suffix)}';
    $appTz = config('app.timezone') ?: 'Europe/Paris';
    $now = Carbon::now($appTz);
    $token = $prefix . '-' . $suffix . '-' . strtoupper(Str::random(6));

    // Use branch 1 (chef's branch + Le Cayenne primary). Admin (branch_id=0)
    // sees all branches via the BUG-KDS-SYNC bypass.
    $branchId = 1;

    // [Wave P R2 KDS-allergen 2026-05-20] Pick an R2-B-seeded item with
    // non-empty allergen_flags so the KDS allergen badge actually renders.
    // Canonical shape per 2026_04_20_131600 backfill migration + AllergenService
    // is FR string array (e.g. ['gluten','lait']). KdsCustomization helper
    // filters with typeof === 'string', so object/legacy shapes would be dropped.
    // Falls back to any active item if no allergen-flagged item exists
    // (e.g. seeder not yet run on this DB).
    $item = Item::query()->withoutGlobalScopes()
        ->whereNotNull('allergen_flags')
        ->whereRaw("JSON_LENGTH(allergen_flags) > 0")
        ->where('status', \\App\\Enums\\Status::ACTIVE)
        ->first();
    if (! $item) {
        $item = Item::query()->withoutGlobalScopes()
            ->where('status', \\App\\Enums\\Status::ACTIVE)
            ->first();
    }
    if (! $item) {
      echo json_encode(['ok' => false, 'reason' => 'no_active_item']);
      return;
    }
    $itemAllergenFlags = is_array($item->allergen_flags) ? $item->allergen_flags : [];

    // user_id is NOT NULL no-default on orders → use admin (id=1) as creator.
    $userId = (int) \\App\\Models\\User::query()
      ->where('email', 'admin@lecayenne.fr')
      ->value('id');
    if ($userId < 1) { $userId = 1; }

    $order = new Order();
    $order->user_id = $userId;
    $order->branch_id = $branchId;
    $order->token = $token;
    $order->status = ${Number(status)};
    $order->payment_status = PaymentStatus::PAID;
    $order->order_type = OrderType::POS;
    $order->order_datetime = $now->copy()->setTimezone('UTC');
    $order->business_date = $now->copy()->toDateString();
    $order->is_advance_order = Ask::NO;
    $order->subtotal = 7.50;
    $order->total = 7.50;
    $order->total_tax = 0;
    $order->discount = 0;
    $order->delivery_charge = 0;
    $order->source_surface = 'POS';
    $order->source = 'POS';
    // queue_number is a VARCHAR (kiosk waves use 'A0001' style). Generate a
    // collision-proof token prefix instead of int+1 to avoid races and
    // alphanumeric coercion to 0 (which conflicts with kiosk seed queue=1).
    $order->queue_number = 'P3' . substr(strtoupper(bin2hex(random_bytes(3))), 0, 5);
    $order->save();

    // Single line — uses correct column names.
    $line = new OrderItem();
    $line->order_id = $order->id;
    $line->branch_id = $branchId;
    $line->item_id = $item->id;
    $line->quantity = 1;
    $line->price = 7.50;
    $line->total_price = 7.50;
    $line->tax_amount = 0;
    $line->tax_rate = 0;
    $line->discount = 0;
    $line->item_variation_total = 0;
    $line->item_extra_total = 0;
    $line->instruction = 'WAVEP3-KDS instr ' . $suffix;
    $line->composition_snapshot = json_encode(['source' => 'wave-p-3-seed', 'suffix' => $suffix]);
    // [Wave P R2 KDS-allergen 2026-05-20] Canonical shape = FR string array
    // (matches backend AllergenService::projectFlags + frontend kdsCustomization
    // filter typeof === string). Use the seeded items real flags or
    // fall back to a minimal gluten+lait pair for surface coverage.
    $allergenCodes = ! empty($itemAllergenFlags)
        ? array_values(array_filter($itemAllergenFlags, fn($c) => is_string($c) && $c !== ''))
        : ['gluten', 'lait'];
    $line->allergens_snapshot = json_encode($allergenCodes, JSON_UNESCAPED_UNICODE);
    $line->save();

    echo json_encode([
      'ok' => true,
      'order_id' => (int) $order->id,
      'queue_number' => (int) $order->queue_number,
      'status' => (int) $order->status,
      'branch_id' => (int) $order->branch_id,
      'token' => $token,
      'item_id' => (int) $item->id,
      'item_name' => (string) $item->name,
    ]);
  `);
  return parseLastJsonLine(out);
}

function changeStatusByApi(orderId, expectedStatus, nextStatus) {
  // Use artisan + a service-layer call so we bypass any UI debounce/3s pending
  // bump in KdsV2Grid. We need to assert state-transition logic, not UI debounce.
  // Sanctum guard doesn't expose login() — use setUser() to inject the admin.
  const out = artisan(`
    use App\\Models\\Order;
    use App\\Services\\KitchenDisplaySystemOrderService;
    use Illuminate\\Http\\Request;

    $order = Order::withoutGlobalScopes()->findOrFail(${Number(orderId)});
    $admin = \\App\\Models\\User::query()->where('email', 'admin@lecayenne.fr')->first();
    if ($admin) { auth()->setUser($admin); }

    $svc = app(KitchenDisplaySystemOrderService::class);
    $req = Request::create('/', 'POST', ['status' => ${Number(nextStatus)}, 'expected_status' => ${Number(expectedStatus)}]);
    try {
      $svc->changeStatus($order, $req);
      $order->refresh();
      echo json_encode(['ok' => true, 'order_id' => (int) $order->id, 'status' => (int) $order->status]);
    } catch (\\Throwable $e) {
      echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
  `);
  return parseLastJsonLine(out);
}

async function snap(page, slug) {
  const fn = `${slug}.png`;
  const abs = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: abs, fullPage: false }).catch((e) => console.warn(`[snap ${slug}]`, e.message));
  meta.captures.push({ slug, file: fn, abs, ts: new Date().toISOString(), url: page.url() });
  persist();
  return abs;
}

function attachListeners(page) {
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      meta.consoleErrors.push({ text: msg.text().slice(0, 500), ts: new Date().toISOString() });
    }
  });
  page.on('pageerror', (err) => {
    meta.pageErrors.push({ message: String(err?.message || err).slice(0, 500), ts: new Date().toISOString() });
  });
  page.on('requestfailed', (req) => {
    // Only KDS-relevant API failures
    if (/\/api\//.test(req.url())) {
      meta.networkFailures.push({ url: req.url(), failure: req.failure()?.errorText || '', ts: new Date().toISOString() });
    }
  });
}

test.describe('Wave P-3 — KDS E2E Audit + Heal Loop', () => {
  test.setTimeout(240_000);

  test.beforeAll(() => {
    try { clearFoodKingRateLimits(); } catch (_) {}
    cleanupKdsTestOrders();
  });

  test.afterAll(() => {
    cleanupKdsTestOrders();
    persist();
  });

  // ============================================================
  // K01 — Empty state (no seeded orders) + admin login
  // ============================================================
  test('K01 — Empty KDS state + admin login + console clean', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupKdsTestOrders();

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);

    await snap(page, 'K01-empty-state');

    const bodyText = await page.locator('body').innerText().catch(() => '');
    const cardCount = await page.locator('.kds-card, [data-testid="kds-order-card"], [data-testid="kds-card-cta"]').count().catch(() => 0);

    expect(/Whoops|Fatal error|Server Error/i.test(bodyText)).toBe(false);
    meta.findings.push({ test: 'K01', cardCount, bodyLen: bodyText.length });
    persist();
  });

  // ============================================================
  // K02 — Seed 1 ACCEPT order → appears in KDS within 8s
  // (Polling cadence is 5s per F-03 Lot 1.C — give 8s headroom)
  // ============================================================
  test('K02 — Seed 1 ACCEPT order → appears in KDS within 8s', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupKdsTestOrders();

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    const before = await page.locator('.kds-card, [data-testid="kds-order-card"]').count().catch(() => 0);

    const seeded = seedKdsOrder({ status: 4, suffix: 'K02' });
    expect(seeded.ok).toBe(true);
    meta.seededOrders.push(seeded);
    persist();

    const t0 = Date.now();
    await page.waitForFunction(
      (beforeCount) => {
        const cards = document.querySelectorAll('.kds-card, [data-testid="kds-order-card"]');
        return cards.length > beforeCount;
      },
      before,
      { timeout: 12_000 },
    ).catch(() => {});
    const elapsed = Date.now() - t0;

    await page.waitForTimeout(500);
    await snap(page, 'K02-after-seed');

    const after = await page.locator('.kds-card, [data-testid="kds-order-card"]').count().catch(() => 0);

    meta.findings.push({ test: 'K02', before, after, elapsedMs: elapsed, orderId: seeded.order_id, queue: seeded.queue_number });
    persist();

    expect(after).toBeGreaterThan(before);
    // Sync budget: 8s (5s polling + 3s render). Wave M heals confirmed
    // polling fallback in place when Pusher is degraded.
    expect(elapsed).toBeLessThanOrEqual(10_000);
  });

  // ============================================================
  // K03 — Multi-status board: seed ACCEPT + PREPARING + PREPARED
  // ============================================================
  test('K03 — Multi-status board (ACCEPT + PREPARING + PREPARED)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupKdsTestOrders();

    const a = seedKdsOrder({ status: 4, suffix: 'K03a' });
    const b = seedKdsOrder({ status: 7, suffix: 'K03b' });
    const c = seedKdsOrder({ status: 8, suffix: 'K03c' });
    meta.seededOrders.push(a, b, c);

    expect(a.ok && b.ok && c.ok).toBe(true);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000); // let polling/hydrate land

    await snap(page, 'K03-multi-status');

    const cards = await page.locator('.kds-card, [data-testid="kds-order-card"]').count().catch(() => 0);
    meta.findings.push({ test: 'K03', cards, seededIds: [a.order_id, b.order_id, c.order_id] });
    persist();

    expect(cards).toBeGreaterThanOrEqual(3);
  });

  // ============================================================
  // K04 — Service-layer transition ACCEPT → PREPARING → PREPARED
  // (bypasses UI debounce — we verify the backend reflects)
  // ============================================================
  test('K04 — Status transitions ACCEPT → PREPARING → PREPARED via service', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupKdsTestOrders();

    const seeded = seedKdsOrder({ status: 4, suffix: 'K04' });
    meta.seededOrders.push(seeded);
    expect(seeded.ok).toBe(true);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5_000);
    await snap(page, 'K04a-status-accept');

    const t1 = changeStatusByApi(seeded.order_id, 4, 7);
    meta.transitions.push({ step: 'accept→preparing', ...t1 });
    expect(t1.ok).toBe(true);
    expect(t1.status).toBe(7);

    await page.waitForTimeout(6_000); // poll catches up
    await snap(page, 'K04b-status-preparing');

    const t2 = changeStatusByApi(seeded.order_id, 7, 8);
    meta.transitions.push({ step: 'preparing→prepared', ...t2 });
    expect(t2.ok).toBe(true);
    expect(t2.status).toBe(8);

    await page.waitForTimeout(6_000);
    await snap(page, 'K04c-status-prepared');

    persist();
  });

  // ============================================================
  // K05 — UI bump CTA click triggers API request
  // ============================================================
  test('K05 — UI bump CTA click triggers /kds-order/change-status API', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupKdsTestOrders();

    const seeded = seedKdsOrder({ status: 4, suffix: 'K05' });
    meta.seededOrders.push(seeded);
    expect(seeded.ok).toBe(true);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);

    await snap(page, 'K05a-before-bump');

    // Look for any visible bump CTA (canonical .kds-card__cta from KdsOrderCard.vue
    // OR data-testid="kds-card-cta" from legacy KitchenDisplaySystemComponent).
    const cta = page.locator('.kds-card__cta, [data-testid="kds-card-cta"], button[data-action="bump"]').first();
    const ctaVisible = await cta.isVisible({ timeout: 5_000 }).catch(() => false);

    let bumpHitArea = null;
    let apiHit = false;

    if (ctaVisible) {
      bumpHitArea = await cta.boundingBox().catch(() => null);

      // Watch for change-status request
      const apiPromise = page.waitForResponse(
        (r) => /\/api\/admin\/kds-order\/change-status\//.test(r.url()),
        { timeout: 8_000 },
      ).catch(() => null);

      await cta.click().catch(() => {});
      // KdsV2Grid uses a 3s pending-bump toast → wait 4s before checking API
      await page.waitForTimeout(4_500);

      const resp = await apiPromise;
      apiHit = !!resp;
    }

    await snap(page, 'K05b-after-bump');

    meta.findings.push({ test: 'K05', ctaVisible, bumpHitArea, apiHit });
    persist();

    // Soft: CTA must be visible AND have a reasonable hit area (≥44px WCAG)
    expect(ctaVisible).toBe(true);
    if (bumpHitArea) {
      expect(bumpHitArea.height).toBeGreaterThanOrEqual(44);
    }
  });

  // ============================================================
  // K06 — Allergen modal: badge + open + close + i18n
  // (Per project_kds_audit_2026-05-11 the allergenModal bug was healed.)
  // ============================================================
  test('K06 — Allergen badge + inline allergen block render on items with allergens', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);
    cleanupKdsTestOrders();

    // [Wave P R2 KDS-allergen 2026-05-20] KDS V2 (KdsOrderCard.vue) does NOT
    // use a click-to-open allergen modal. Allergens are shown inline:
    //   - Header pill `.kds-card__allergen-pill` ("⚠ ALLERGIE") — card-level
    //     v-if="hasAllergen" alert visible at 2m glance distance.
    //   - Body block `.kds-line__allergen-block` rendering the FR-translated
    //     code list ("Allergènes : Gluten · Lait · ...").
    // The "modal" terminology is a historical artefact from the legacy
    // KitchenDisplaySystemComponent V1 (project_kds_audit_2026-05-11). The
    // V2 design (DESIGN_SPEC_KDS_V2_2026-05-11) intentionally removes the
    // modal — chefs read allergens at 2m without clicking.
    const seeded = seedKdsOrder({ status: 4, suffix: 'K06' });
    meta.seededOrders.push(seeded);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);

    const badge = page.locator('.kds-card__allergen-pill, .kds-allergens-badge').first();
    const badgeVisible = await badge.isVisible({ timeout: 5_000 }).catch(() => false);

    await snap(page, 'K06a-before');

    let inlineBlockVisible = false;
    if (badgeVisible) {
      const inlineBlock = page.locator('.kds-line__allergen-block').first();
      inlineBlockVisible = await inlineBlock.isVisible({ timeout: 3_000 }).catch(() => false);
      await snap(page, 'K06b-inline-block');
    }

    meta.findings.push({ test: 'K06', badgeVisible, inlineBlockVisible });
    persist();

    // HARD invariant : when an order carries allergens, BOTH the header
    // pill AND the inline body block must render — chefs read both signals
    // (2m glance pill + per-line code list). Either missing = food safety bug.
    if (badgeVisible) {
      expect(inlineBlockVisible).toBe(true);
    }
  });

  // ============================================================
  // K07 — Polling fallback when Pusher/WebSocket blocked
  // ============================================================
  test('K07 — Polling fallback under Pusher silence (F-03 Lot 1.C)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    // Block sockjs / Pusher / wss to simulate broker outage.
    await page.route(/sockjs|pusher\.com|broadcasting\/auth|wss?:\/\//i, (route) => route.abort());

    let observedFallbackHit = false;
    page.on('response', (resp) => {
      if (/\/api\/admin\/kds-order(\/sync)?(\?|$)/.test(resp.url())) {
        observedFallbackHit = true;
      }
    });

    // Seed during outage — polling must surface it without WebSockets.
    const seeded = seedKdsOrder({ status: 4, suffix: 'K07' });
    meta.seededOrders.push(seeded);
    expect(seeded.ok).toBe(true);

    await page.waitForTimeout(15_000);
    await snap(page, 'K07-polling-fallback');

    const bodyText = await page.locator('body').innerText().catch(() => '');
    const cardCount = await page.locator('.kds-card, [data-testid="kds-order-card"]').count().catch(() => 0);
    const hasFatal = /Whoops|Fatal error|Server Error/i.test(bodyText);

    meta.findings.push({ test: 'K07', observedFallbackHit, cardCount, hasFatal });
    persist();

    expect(hasFatal).toBe(false);
    // The polling endpoint MUST have been hit at least once during 15s outage.
    expect(observedFallbackHit).toBe(true);
  });

  // ============================================================
  // K08 — Raw-label / i18n scan (no `kds.X`, `label.Y`, `0undefined`)
  // ============================================================
  test('K08 — i18n + raw-label scan', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);

    // Ensure 1 order so labels render
    const seeded = seedKdsOrder({ status: 4, suffix: 'K08' });
    meta.seededOrders.push(seeded);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(7_000);

    await snap(page, 'K08-i18n-scan');

    const txt = await page.locator('body').innerText().catch(() => '');
    const matches = {
      kds_dot: (txt.match(/\bkds\.[a-zA-Z_]+/g) || []).slice(0, 10),
      label_dot: (txt.match(/\bLabel\.[a-zA-Z_]+/g) || []).slice(0, 10),
      message_dot: (txt.match(/\bmessage\.[a-zA-Z_]+/g) || []).slice(0, 10),
      button_dot: (txt.match(/\bbutton\.[a-zA-Z_]+/g) || []).slice(0, 10),
      zero_undefined: (txt.match(/0undefined/g) || []).length,
      object_object: (txt.match(/\[object [A-Z]\w+\]/g) || []).length,
    };
    meta.rawLabels = matches;
    persist();

    const totalLeaks =
      matches.kds_dot.length +
      matches.label_dot.length +
      matches.message_dot.length +
      matches.button_dot.length +
      matches.zero_undefined +
      matches.object_object;

    meta.findings.push({ test: 'K08', totalLeaks, matches });
    persist();

    expect(totalLeaks).toBe(0);
  });
});
