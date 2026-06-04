// FoodKing E2E — Wave Q-1 Suivi Commandes items summary (2026-05-20)
//
// P-OWNER directive (verbatim translated 2026-05-20):
//   « Dans suivi commandes je ne vois pas ce qu'il y a dans la commande.
//     Je vois juste le numéro + 'Client passage' + quel système
//     (caisse/borne/en ligne). Je ne vois pas ce qu'il y a dans la commande
//     — type Cayenne, burger, etc. Je veux voir au minimum un résumé de ce
//     que le client a commandé pour un service dynamique et fluide. »
//
// Root cause:
//   `SimpleOrderResource::toArray()` (feed of `/admin/pos-order` index)
//   did NOT expose any `order_items` payload. The Vue tracker
//   (`PosOrdersTrackerComponent.vue`) already renders a
//   `<ul class="pos-tracker-card-items">` and calls `itemsPreview(order)` /
//   `extraItemsCount(order)` reading `o.order_items`, but the field was
//   always undefined → the items <ul> always rendered 0 <li>.
//
// Heal:
//   Add a lean `order_items` array (item_id + item_name + quantity) to
//   `SimpleOrderResource`, mirroring the
//   `SimpleDeliveryBoyOrderResource::resolveItemsForDriver()` pattern
//   (N+1 guard via `relationLoaded('orderItems')` — `OrderService::list()`
//   already eager-loads `orderItems.orderItem.media/category` so no extra
//   SELECT).
//
// What this spec proves (rejects regression):
//   1. After seeding two orders (Sandwich Cayenne ×2 + Tacos ×1) under
//      today's business date, both cards on /admin/pos-orders-tracker
//      render at least one `.pos-tracker-card-items li`.
//   2. The card containing the Cayenne order surfaces the text
//      "Sandwich Cayenne" (and the qty marker "2×").
//   3. The card containing the Tacos order surfaces the text "Tacos".
//   4. The 4-column layout is intact (no overflow / no broken rendering).
//
// Reports dir: reports/test-e2e/wave-q-2026-05-20/suivi-commandes/screenshots/

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const REPORT_ROOT = path.join(
    __dirname,
    '..',
    '..',
    'reports',
    'test-e2e',
    'wave-q-2026-05-20',
    'suivi-commandes',
);
const SHOTS_DIR = path.join(REPORT_ROOT, 'screenshots');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const TRACKER_URL = '/admin/pos-orders-tracker';
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const FATAL_ERR_FILTER =
    /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

function php(snippet) {
    const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: 60_000,
    });
    return (res.stdout || '') + (res.stderr || '');
}

// Seed a tracker-shaped order with one explicit order_items line.
//   itemId    — App\Models\Item primary key
//   itemQty   — integer quantity rendered as "qty×" by the tracker
//   statusInt — 4 ACCEPT, 7 PREPARING, 8 PREPARED, 13 DELIVERED
//   typeInt   — 15 POS, 17/18 KIOSK, 20 POS dine-in
function seedOrderWithItem({ suffix, itemId, itemQty, statusInt, typeInt }) {
    const snippet =
        `$o = new App\\Models\\Order; ` +
        `$o->order_serial_no = 'WQ1-${suffix}'; ` +
        `$o->user_id = 1; ` +
        `$o->branch_id = 1; ` +
        `$o->subtotal = ${(itemQty * 6.5).toFixed(2)}; ` +
        `$o->total = ${(itemQty * 6.5).toFixed(2)}; ` +
        `$o->order_type = ${typeInt}; ` +
        `$o->order_datetime = now('UTC'); ` +
        `$o->preparation_time = 5; ` +
        `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
        `$o->payment_method = 1; ` +
        `$o->payment_status = 10; ` +
        `$o->status = ${statusInt}; ` +
        `$o->business_date = now()->toDateString(); ` +
        `$o->save(); ` +
        `$i = new App\\Models\\OrderItem; ` +
        `$i->order_id = $o->id; ` +
        `$i->branch_id = 1; ` +
        `$i->item_id = ${itemId}; ` +
        `$i->quantity = ${itemQty}; ` +
        `$i->price = 6.50; ` +
        `$i->total_price = ${(itemQty * 6.5).toFixed(2)}; ` +
        `$i->discount = 0; ` +
        `$i->tax_rate = '0'; ` +
        `$i->tax_amount = 0; ` +
        `$i->tax_type = 1; ` +
        `$i->item_variation_total = 0; ` +
        `$i->item_extra_total = 0; ` +
        `$i->save(); ` +
        `echo $o->id;`;
    const out = php(snippet);
    const m = out.match(/(\d+)\s*$/);
    return m ? parseInt(m[1], 10) : null;
}

function cleanup(suffixes) {
    const snippet =
        `App\\Models\\Order::whereIn('order_serial_no', [` +
        suffixes.map((s) => `'WQ1-${s}'`).join(',') +
        `])->withoutGlobalScopes()->each(function ($o) { ` +
        `App\\Models\\OrderItem::where('order_id', $o->id)->withoutGlobalScopes()->forceDelete(); ` +
        `$o->forceDelete(); }); echo 'ok';`;
    php(snippet);
}

test.describe('Wave Q-1 — Suivi commandes shows order items summary', () => {
    test.setTimeout(120_000);

    test('tracker cards render order_items (Cayenne + Tacos summary)', async ({ page }) => {
        const consoleErrors = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                const text = msg.text();
                if (!FATAL_ERR_FILTER.test(text)) consoleErrors.push(text);
            }
        });

        // Pre-clean any leftover from a previous run, then seed fresh.
        cleanup(['CAYENNE', 'TACOS']);
        const cayenneOrderId = seedOrderWithItem({
            suffix: 'CAYENNE',
            itemId: 22, // Sandwich Cayenne
            itemQty: 2,
            statusInt: 4, // ACCEPT — appears in the 1st column "Confirmées"
            typeInt: 15, // POS source
        });
        const tacosOrderId = seedOrderWithItem({
            suffix: 'TACOS',
            itemId: 26, // Tacos
            itemQty: 1,
            statusInt: 7, // PREPARING — 2nd column "En préparation"
            typeInt: 17, // KIOSK source
        });
        expect(cayenneOrderId, 'cayenne order seeded').toBeTruthy();
        expect(tacosOrderId, 'tacos order seeded').toBeTruthy();

        await loginAsAdmin(page);
        await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });

        // Wait for the tracker shell + at least one of our cards.
        await expect(page.locator('[data-pos-tracker-shell]')).toBeVisible({ timeout: 20_000 });
        const cayenneCard = page.locator(`[data-testid="tracker-order-${cayenneOrderId}"]`);
        const tacosCard = page.locator(`[data-testid="tracker-order-${tacosOrderId}"]`);
        await expect(cayenneCard).toBeVisible({ timeout: 20_000 });
        await expect(tacosCard).toBeVisible({ timeout: 20_000 });

        // BEFORE the heal, the <ul.pos-tracker-card-items> rendered 0 children
        // because `o.order_items` was undefined. After the heal it MUST contain
        // ≥1 <li> with the item name + qty marker.
        const cayenneItems = cayenneCard.locator('.pos-tracker-card-items li');
        const tacosItems = tacosCard.locator('.pos-tracker-card-items li');

        await expect(cayenneItems).toHaveCount(1, { timeout: 10_000 });
        await expect(tacosItems).toHaveCount(1, { timeout: 10_000 });

        // The name + qty must surface exactly.
        await expect(cayenneCard).toContainText('Sandwich Cayenne');
        await expect(cayenneCard.locator('.pos-tracker-card-qty')).toContainText('2×');
        await expect(tacosCard).toContainText('Tacos');
        await expect(tacosCard.locator('.pos-tracker-card-qty')).toContainText('1×');

        // 4-column layout intact.
        await expect(page.locator('.pos-tracker-col')).toHaveCount(4);

        // Visual evidence.
        await page.screenshot({
            path: path.join(SHOTS_DIR, 'wave-q1-tracker-with-items.png'),
            fullPage: true,
        });

        // Per-card close-up for adversarial review.
        await cayenneCard.screenshot({
            path: path.join(SHOTS_DIR, 'wave-q1-card-cayenne.png'),
        });
        await tacosCard.screenshot({ path: path.join(SHOTS_DIR, 'wave-q1-card-tacos.png') });

        // Hygiene: no fresh JS errors in console for this surface.
        expect(consoleErrors, `clean console: ${consoleErrors.join(' || ')}`).toHaveLength(0);

        // Persist a small findings JSON for the wave-q rollup.
        fs.writeFileSync(
            path.join(REPORT_ROOT, 'wave-q1-suivi-commandes-findings.json'),
            JSON.stringify(
                {
                    spec: 'wave-q1-suivi-commandes',
                    cayenneOrderId,
                    tacosOrderId,
                    cards_with_items: 2,
                    columns: 4,
                    timestamp: new Date().toISOString(),
                },
                null,
                2,
            ),
        );

        // Cleanup so the live tracker stays uncluttered for follow-on waves.
        cleanup(['CAYENNE', 'TACOS']);
    });
});
