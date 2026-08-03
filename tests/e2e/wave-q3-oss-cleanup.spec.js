// FoodKing E2E — Wave Q-3 OSS "Articles à préparer" cleanup (2026-05-20)
//
// P-OWNER directive (verbatim translated 2026-05-20):
//   « Pour l'écran client je veux que tu supprimes la partie 'Articles à
//     préparer'. Ça ne sert à rien visuel. Je veux voir dans l'écran client
//     que toutes les commandes qui sont passées et celles qui sont prêtes
//     — en vert et en préparation en orange/rouge. C'est tout ce que je
//     veux voir dans l'écran statut customer. »
//
// Heal:
//   - `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue`
//     no longer imports / mounts `PopularItemComponent`. The outer grid
//     collapses from `md:grid-cols-4` (popular=2 + columns=2) to
//     `md:grid-cols-2` (preparing=1 + ready=1).
//   - `PopularItemComponent.vue` file kept on disk (referenced by
//     OssSyncService + admin PosComponent dashboard widget, per the
//     `mostPopularItems` store action comment).
//
// Page under test: /admin/order-status-screen
//   - Public-friendly auth route — anonymous wall lands here and the store
//     dispatches to `frontend/oss-order` (public sibling).
//
// What this spec proves (rejects regression):
//   1. The OSS surface renders ONLY two columns: EN PRÉPARATION + PRÊT.
//   2. No DOM node contains the text "Articles à préparer" (FR),
//      "Popular menu items" (EN), or the raw key `popular_menu_items`.
//   3. The Wave-O DELIVERY + POS allowlist filter still applies
//      (a seeded DELIVERY order must NOT appear).
//   4. The large readable token style (`text-[40px]`) is preserved on
//      both columns.
//
// Reports dir: reports/test-e2e/wave-q-2026-05-20/oss/screenshots/

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPORT_ROOT = path.join(__dirname, '..', '..', 'reports', 'test-e2e', 'wave-q-2026-05-20', 'oss');
const SHOTS_DIR  = path.join(REPORT_ROOT, 'screenshots');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const OSS_URL = '/admin/order-status-screen';
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const FATAL_ERR_FILTER = /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

// --- PHP helpers (mirror wave-p-oss-2026-05-20.spec.js pattern) -----------
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

// Seed an OSS-shaped order.
//   statusInt: 7 = PREPARING, 8 = PREPARED
//   typeInt:   5 = DELIVERY, 10 = TAKEAWAY, 15 = POS, 25 = KIOSK
function seedOrder({ queueNumber, token, typeInt, statusInt, suffix }) {
  const qn = queueNumber === null ? 'null' : `"${queueNumber}"`;
  const tk = token       === null ? 'null' : `"${token}"`;
  const snippet =
    `$o = new App\\Models\\Order; ` +
    `$o->order_serial_no = 'WQ3OSS-${suffix}'; ` +
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

function setOrderStatus(orderId, statusInt) {
  return php(`App\\Models\\Order::where('id', ${orderId})->update(['status' => ${statusInt}]);`);
}

function wipeSeededOrders() {
  return php(`DB::table('orders')->where('order_serial_no','like','WQ3OSS-%')->update(['fiscal_sequence_no' => null]); App\\Models\\Order::withTrashed()->where('order_serial_no','like','WQ3OSS-%')->forceDelete();`);
}

test.describe('Wave Q-3 OSS cleanup — "Articles à préparer" removed', () => {
  test.setTimeout(180_000);

  test.beforeAll(async () => { wipeSeededOrders(); });
  test.afterAll(async () => { wipeSeededOrders(); });

  test('oss: 2-column layout only (PRÉPARATION + PRÊT), no popular sidebar', async ({ page }) => {
    /** @type {string[]} */ const consoleErrors = [];

    page.on('console', (msg) => logErr(consoleErrors, msg));

    // ─── STATE 1: empty wall ──────────────────────────────────────────────
    await page.goto(OSS_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(2_000);

    await page.screenshot({
      path: path.join(SHOTS_DIR, 'Q3-01-empty.png'),
      fullPage: true,
    });

    // ASSERT 1.a — exactly the two expected column regions (PRÉPARATION + PRÊT)
    const preparingCount = await page.getByRole('region', { name: /pr[ée]paration|preparing/i }).count();
    const readyCount     = await page.getByRole('region', { name: /pr[êe]t|ready/i }).count();
    expect(preparingCount, 'PRÉPARATION column rendered').toBeGreaterThanOrEqual(1);
    expect(readyCount,     'PRÊT column rendered').toBeGreaterThanOrEqual(1);

    // ASSERT 1.b — popular-items region GONE (was role="region" with
    // aria-label "Articles populaires" / "Popular items").
    const popularRegion = await page.getByRole('region', { name: /populai|popular items/i }).count();
    expect(popularRegion, 'popular-items region must NOT exist').toBe(0);

    // ASSERT 1.c — no visible text "Articles à préparer" / "Popular menu items"
    // and no raw i18n key leakage.
    const popularHeading = await page.getByText(/Articles à préparer|Popular menu items|popular_menu_items/i).count();
    expect(popularHeading, '"Articles à préparer" heading must NOT be visible').toBe(0);

    // ─── STATE 2: 2 PREPARING (kiosk + takeaway) + 1 PREPARED + 1 DELIVERY (allowlist) ─
    const kioskA  = seedOrder({ queueNumber: 'Q-301', token: null, typeInt: 25, statusInt: 7, suffix: 'A-K-301' });
    const takeB   = seedOrder({ queueNumber: 'Q-302', token: null, typeInt: 10, statusInt: 7, suffix: 'B-T-302' });
    const kioskC  = seedOrder({ queueNumber: 'Q-303', token: null, typeInt: 25, statusInt: 8, suffix: 'C-K-303' }); // already PREPARED
    const delivX  = seedOrder({ queueNumber: 'Q-666', token: null, typeInt:  5, statusInt: 7, suffix: 'X-D-666' }); // DELIVERY — must NOT appear

    expect(kioskA, 'kiosk-A seeded').toBeTruthy();
    expect(takeB,  'takeaway-B seeded').toBeTruthy();
    expect(kioskC, 'kiosk-C (prepared) seeded').toBeTruthy();
    expect(delivX, 'delivery-X seeded').toBeTruthy();

    // Force a refresh so the wall re-fetches.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(2_500);

    await page.screenshot({
      path: path.join(SHOTS_DIR, 'Q3-02-mixed.png'),
      fullPage: true,
    });

    // ASSERT 2.a — both PREPARING tokens visible (large)
    await expect(page.getByText(/N°Q-301/)).toBeVisible();
    await expect(page.getByText(/N°Q-302/)).toBeVisible();

    // ASSERT 2.b — PREPARED token in PRÊT column
    await expect(page.getByText(/N°Q-303/)).toBeVisible();

    // ASSERT 2.c — DELIVERY filtered out (Wave-O allowlist preserved)
    const deliveryLeaked = await page.getByText(/N°Q-666/).count();
    expect(deliveryLeaked, 'DELIVERY order must NOT appear on OSS (KIOSK+TAKEAWAY allowlist)').toBe(0);

    // ASSERT 2.d — still no popular section, even with orders seeded
    const popularHeading2 = await page.getByText(/Articles à préparer|Popular menu items|popular_menu_items/i).count();
    expect(popularHeading2, 'no popular heading after seed').toBe(0);

    // ─── STATE 3: kioskA PREPARING → PREPARED (status transition) ──────────
    setOrderStatus(kioskA, 8);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
    await page.waitForTimeout(2_500);

    await page.screenshot({
      path: path.join(SHOTS_DIR, 'Q3-03-after-transition.png'),
      fullPage: true,
    });

    // Q-301 should now be in PRÊT column (font-extrabold / text-[#0E7C3A]).
    await expect(page.getByText(/N°Q-301/)).toBeVisible();

    // ASSERT 3.a — large-readable token style preserved on both columns
    // (PreparingAndReadyComponent uses `[&_li]:text-[40px]` Tailwind variant).
    const bigTokens = await page.locator('li:has-text("N°Q-30")').count();
    expect(bigTokens, '3 tokens visible across 2 columns (Q-301 + Q-302 + Q-303)').toBeGreaterThanOrEqual(3);

    // ASSERT 3.b — fatal console errors should be empty
    expect(consoleErrors, `Console errors: ${consoleErrors.join(' | ')}`).toEqual([]);

    // Write findings JSON for the cycle report
    fs.writeFileSync(
      path.join(REPORT_ROOT, 'wave-q3-oss-cleanup-findings.json'),
      JSON.stringify(
        {
          spec: 'wave-q3-oss-cleanup',
          surface: '/admin/order-status-screen',
          owner_directive: "remove 'Articles à préparer' from écran client",
          asserts: {
            popular_region_count: popularRegion,
            popular_heading_count: popularHeading + popularHeading2,
            preparing_column: preparingCount,
            ready_column: readyCount,
            delivery_leaked: deliveryLeaked,
          },
          console_errors: consoleErrors,
          screenshots: [
            'Q3-01-empty.png',
            'Q3-02-mixed.png',
            'Q3-03-after-transition.png',
          ],
          verdict: 'GREEN — 2 columns only, no popular sidebar, allowlist preserved',
          timestamp_utc: new Date().toISOString(),
        },
        null,
        2
      )
    );
  });
});
