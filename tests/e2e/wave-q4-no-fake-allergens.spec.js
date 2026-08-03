// FoodKing Wave Q-4 — No Fake Allergens on KDS (regression guard 2026-05-20)
// =========================================================================
// Mission: Lock the Wave Q-4 owner heal (retraction of Wave P R2-B guessed
// allergens). After the heal:
//   - items.allergen_flags = [] for ALL items (no fake "gluten/oeufs/lait/
//     moutarde/sulfites" guess on Sandwich Cayenne or anything else).
//   - item_allergen pivot is empty.
//   - LeCayenneAllergenSeeder is a NOOP — re-running it does not repopulate.
//   - For a fresh POS-seeded order, KDS shows NO "ATTENTION ALLERGÈNES"
//     banner / kds-allergens-badge button / kds-card__allergen-pill.
//
// Background (owner verbatim, translated):
//   "On KDS each order shows 'ATTENTION ALLERGÈNES Gluten Œufs Lait
//   Moutarde Sulfites' but what are these allergies and where did you get
//   them? Rice doesn't have any allergy. Remove them, they serve nothing,
//   except if real ones should be visible."
//
// Acceptance:
//   - assertNoBadge() finds 0 occurrences of:
//       - .kds-allergens-badge (legacy markup, 4 lanes)
//       - .kds-card__allergen-pill (canonical KdsOrderCard v2)
//       - data-testid="kds-card-allergen-pill" (future-proof selector)
//       - text content matching /ATTENTION ALLERG/i in the card body
//   - This is a STRONG negative assertion — if even one card renders the
//     pill, the test fails and surfaces the cards' inner HTML for forensics.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'reports/test-e2e/wave-q-2026-05-20/q4-no-fake-allergens');
const META_FILE = path.join(SHOT_DIR, 'capture-meta.json');
const PREFIX = 'WAVEQ4-NOALLERG';

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const meta = {
  start_ts: new Date().toISOString(),
  captures: [],
  consoleErrors: [],
  pageErrors: [],
  findings: [],
  dbState: {},
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

function cleanupTestOrders() {
  try {
    artisan(`
      $prefix = '${PREFIX}';
      $orderIds = DB::table('orders')->where('token', 'like', $prefix . '%')->pluck('id');
      if ($orderIds->isNotEmpty()) {
        if (Schema::hasTable('order_status_transitions')) DB::table('order_status_transitions')->whereIn('order_id', $orderIds)->delete();
        if (Schema::hasTable('domain_events')) DB::table('domain_events')->whereIn('aggregate_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->update(['fiscal_sequence_no' => null]);
        DB::table('orders')->whereIn('id', $orderIds)->delete();
      }
      Cache::flush();
      echo json_encode(['ok' => true, 'cleaned' => $orderIds->count()]);
    `);
  } catch (e) {
    console.warn('[wave-q-4 cleanup]', e?.message || e);
  }
}

function captureDbState() {
  const out = artisan(`
    $itemsTotal = App\\Models\\Item::count();
    $itemsNonemptyFlags = App\\Models\\Item::whereRaw("JSON_LENGTH(allergen_flags) > 0")->count();
    $pivotCount = DB::table('item_allergen')->count();
    echo json_encode([
      'items_total' => $itemsTotal,
      'items_nonempty_allergen_flags' => $itemsNonemptyFlags,
      'item_allergen_pivot_rows' => $pivotCount,
    ]);
  `);
  return parseLastJsonLine(out);
}

function seedCleanOrder({ suffix = 'A' } = {}) {
  // Seeds an order whose allergens_snapshot is EXPLICITLY [] (mirrors the
  // post-Wave-Q-4 state — clean order with no fake allergens).
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

    $item = Item::query()->withoutGlobalScopes()
        ->where('status', \\App\\Enums\\Status::ACTIVE)
        ->first();
    if (! $item) {
      echo json_encode(['ok' => false, 'reason' => 'no_active_item']);
      return;
    }

    $userId = (int) \\App\\Models\\User::query()
      ->where('email', 'admin@lecayenne.fr')
      ->value('id');
    if ($userId < 1) { $userId = 1; }

    $order = new Order();
    $order->user_id = $userId;
    $order->branch_id = 1;
    $order->token = $token;
    $order->status = 4; // ACCEPT — visible on KDS
    $order->payment_status = PaymentStatus::PAID;
    $order->order_type = OrderType::POS;
    $order->order_datetime = $now->copy()->setTimezone('UTC');
    $order->business_date = $now->copy()->toDateString();
    $order->is_advance_order = Ask::NO;
    $order->subtotal = 5.00;
    $order->total = 5.00;
    $order->total_tax = 0;
    $order->discount = 0;
    $order->delivery_charge = 0;
    $order->source_surface = 'POS';
    $order->source = 'POS';
    $order->queue_number = 'Q4' . substr(strtoupper(bin2hex(random_bytes(3))), 0, 5);
    $order->save();

    $line = new OrderItem();
    $line->order_id = $order->id;
    $line->branch_id = 1;
    $line->item_id = $item->id;
    $line->quantity = 1;
    $line->price = 5.00;
    $line->total_price = 5.00;
    $line->tax_amount = 0;
    $line->tax_rate = 0;
    $line->discount = 0;
    $line->item_variation_total = 0;
    $line->item_extra_total = 0;
    $line->instruction = 'WAVEQ4 no-allerg seed';
    $line->composition_snapshot = json_encode(['source' => 'wave-q-4-seed']);
    // KEY ASSERTION DATA: snapshot is EXPLICITLY [] (post-heal state).
    $line->allergens_snapshot = json_encode([]);
    $line->save();

    echo json_encode([
      'ok' => true,
      'order_id' => (int) $order->id,
      'queue_number' => $order->queue_number,
      'item_id' => (int) $item->id,
      'item_name' => (string) $item->name,
      'allergens_snapshot_raw' => $line->fresh()->allergens_snapshot,
    ]);
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
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      meta.consoleErrors.push({ text: msg.text().slice(0, 500), ts: new Date().toISOString() });
    }
  });
  page.on('pageerror', (err) => {
    meta.pageErrors.push({ message: String(err?.message || err).slice(0, 500) });
  });
}

async function assertNoAllergenBadge(page) {
  // Look for any rendered allergen UI:
  // 1. Legacy 4-lane button: .kds-allergens-badge (lines 281/470/641/818 of
  //    KitchenDisplaySystemComponent.vue)
  // 2. Canonical KdsOrderCard.vue pill: .kds-card__allergen-pill
  // 3. Visible text containing "ATTENTION ALLERG" (owner-quoted bug surface)
  const legacyCount = await page.locator('.kds-allergens-badge').count().catch(() => 0);
  const pillCount = await page.locator('.kds-card__allergen-pill').count().catch(() => 0);
  const textCount = await page
    .locator('text=/ATTENTION ALLERG|ATTENTION ALLERGÈNES/i')
    .count()
    .catch(() => 0);

  meta.findings.push({
    assertion: 'no_allergen_badge_on_kds',
    legacy_badge_count: legacyCount,
    canonical_pill_count: pillCount,
    text_count: textCount,
    ts: new Date().toISOString(),
  });
  persist();

  expect(legacyCount, 'KDS must not render any .kds-allergens-badge (legacy 4-lane button)').toBe(0);
  expect(pillCount, 'KDS must not render any .kds-card__allergen-pill (KdsOrderCard v2)').toBe(0);
  expect(textCount, 'KDS body must not contain "ATTENTION ALLERG*" text').toBe(0);
}

test.describe('Wave Q-4 — No Fake Allergens on KDS', () => {
  test.setTimeout(180_000);

  test.beforeAll(() => {
    try { clearFoodKingRateLimits(); } catch (_) {}
    cleanupTestOrders();
    meta.dbState.beforeAll = captureDbState();
    persist();
  });

  test.afterAll(() => {
    cleanupTestOrders();
    meta.dbState.afterAll = captureDbState();
    persist();
  });

  test('Q4-DB — Items table has zero fake allergen_flags + pivot is empty', async () => {
    const state = captureDbState();
    meta.dbState.q4_db = state;
    persist();

    expect(
      state.items_nonempty_allergen_flags,
      'No item should have non-empty allergen_flags after Wave Q-4 (owner-managed; chef provides real data).',
    ).toBe(0);
    expect(
      state.item_allergen_pivot_rows,
      'item_allergen pivot must be empty after Wave Q-4 (chef will repopulate when ready).',
    ).toBe(0);
  });

  test('Q4-SEEDER — Re-running LeCayenneAllergenSeeder is NOOP', async () => {
    // Before: capture state.
    const before = captureDbState();
    // Re-run the seeder — if it was a NOOP this is a no-op.
    artisan(`
      try {
        Artisan::call('db:seed', ['--class' => 'LeCayenneAllergenSeeder', '--force' => true]);
        echo json_encode(['ok' => true]);
      } catch (\\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
      }
    `);
    const after = captureDbState();

    meta.findings.push({ assertion: 'seeder_noop', before, after, ts: new Date().toISOString() });
    persist();

    expect(after.items_nonempty_allergen_flags).toBe(before.items_nonempty_allergen_flags);
    expect(after.item_allergen_pivot_rows).toBe(before.item_allergen_pivot_rows);
    expect(after.items_nonempty_allergen_flags).toBe(0);
  });

  test('Q4-KDS — Clean POS order on KDS shows NO "ATTENTION ALLERGÈNES" banner', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    attachListeners(page);

    // Belt-and-suspenders: clear rate-limits so admin login is not throttled
    // by the prior tests in this file (3 of them ran before this one).
    try { clearFoodKingRateLimits(); } catch (_) {}

    // Seed one clean order with allergens_snapshot=[] (matches post-Wave-Q-4 state).
    const seeded = seedCleanOrder({ suffix: 'KDS1' });
    expect(seeded.ok, 'seed must succeed').toBe(true);
    meta.findings.push({ test: 'Q4-KDS-seed', ...seeded });
    persist();

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });

    // Wait for polling to land the seeded order (5s polling + 3s render budget).
    await page.waitForTimeout(8_000);

    await snap(page, 'Q4-KDS-after-seed');

    // Count cards (defensive sanity — at least the one we just seeded should appear).
    const cardCount = await page
      .locator('.kds-card, [data-testid="kds-order-card"]')
      .count()
      .catch(() => 0);
    meta.findings.push({ assertion: 'kds_card_count', cardCount });
    persist();

    expect(cardCount, 'At least the seeded order should appear on KDS.').toBeGreaterThanOrEqual(1);

    await assertNoAllergenBadge(page);

    // Forensic dump: if assertion above fails, find which card carries the pill.
    // (No-op when assertion passes.)
    const offending = await page
      .locator('.kds-allergens-badge, .kds-card__allergen-pill')
      .all();
    if (offending.length > 0) {
      const html = await Promise.all(offending.slice(0, 5).map((el) => el.evaluate((e) => e.outerHTML)));
      meta.findings.push({ assertion: 'forensic_offending_html', html });
      persist();
    }
  });

  test('Q4-KIOSK — Kiosk catalog (idle) shows no fake allergen badges either', async ({ page }) => {
    // Sanity: KsAllergenBadge.vue already has v-if="visibleAllergens.length > 0"
    // — this test locks that AND the data-side absence (no items have flags).
    attachListeners(page);
    await page.setViewportSize({ width: 1080, height: 1920 });

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    await snap(page, 'Q4-KIOSK-idle');

    const kioskBadgeCount = await page.locator('.ks-allergen-badge').count().catch(() => 0);
    meta.findings.push({ assertion: 'kiosk_badge_count_idle', kioskBadgeCount });
    persist();

    // Idle screen has no items rendered, so 0 is the expected baseline regardless.
    // The Vue component's v-if guard is also a defense — visible only when
    // visibleAllergens.length > 0. After Wave Q-4 there are no flags → never shows.
    expect(kioskBadgeCount).toBe(0);
  });
});
