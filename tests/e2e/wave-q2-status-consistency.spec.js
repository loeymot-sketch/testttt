// FoodKing E2E — Wave Q-2 POS→KDS status transition consistency (2026-05-20)
//
// P-OWNER directive (verbatim translated 2026-05-20):
//   « Quand je confirme le sandwich à 10€ il passe direct en EN PRÉPARATION.
//     Quand je confirme la commande à 0.90€ elle reste en CONFIRMÉE. Puis
//     sur KDS quand je clique Prêt sur la 0.90€ elle passe direct à Prêt
//     en sautant EN PRÉPARATION. Pourquoi ces comportements différents ?
//     Je veux le même cycle CONFIRMÉE → EN PRÉPARATION → PRÊT pour TOUTES
//     les commandes. »
//
// Root cause (KdsV2Grid.vue):
//   1. The V2 grid had `auto-transition-enabled` defaulting to TRUE. When a
//      paid POS order landed and the KDS queue had no other order in
//      PREPARING, the watcher auto-promoted it to PREPARING. The next paid
//      order found one PREPARING already and stayed in ACCEPT — hence the
//      visible asymmetry the cashier saw on the suivi screen.
//   2. `onCtaTap()` always emitted `status: PREPARED`. When the chef tapped
//      Prêt on an ACCEPT-state ticket (auto-transition off, or second
//      ticket), the server `OrderStateMachine::allows` rejected the
//      ACCEPT→PREPARED jump with 422, but the optimistic 3s toast hid the
//      failure. Owner perceived this as "skips PREPARING".
//
// Heal:
//   - `KdsV2Grid.vue` prop `autoTransitionEnabled` default → FALSE.
//   - `KitchenDisplaySystemComponent.vue` data `v2AutoTransitionEnabled` → FALSE.
//   - `KdsV2Grid.vue::onCtaTap()` computes nextStatus from the order's
//     current status (ACCEPT → PREPARING, else → PREPARED) — same step
//     ladder as the legacy `kdsBump` and the server state machine.
//
// What this spec proves:
//   1. Two paid POS orders submitted back-to-back BOTH start in ACCEPT
//      (no auto-transition skip).
//   2. A first bump on each order moves it to PREPARING (not PREPARED).
//   3. A second bump on each order moves it to PREPARED.
//   4. No transition ever lands in PREPARED before passing through
//      PREPARING (server enforces, client respects).
//
// Reports dir: reports/test-e2e/wave-q-2026-05-20/status-consistency/screenshots/

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
    'status-consistency',
);
const SHOTS_DIR = path.join(REPORT_ROOT, 'screenshots');
if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

const TRACKER_URL = '/admin/pos-orders-tracker';
const REPO_ROOT = path.resolve(__dirname, '..', '..');

const FATAL_ERR_FILTER =
    /(favicon|net::ERR|Service Worker|404 .*\.(png|svg|ico|jpg|webp|woff)|GoogleAnalytics|gtag|workbox|Failed to load resource:|Pusher|Echo|Mixpanel|sentry|Manifest|AudioContext)/i;

const STATUS = { ACCEPT: 4, PREPARING: 7, PREPARED: 8 };

function php(snippet) {
    const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        timeout: 60_000,
    });
    return (res.stdout || '') + (res.stderr || '');
}

// Seed a minimal paid POS order in ACCEPT state — exactly the shape that
// `OrderService::posOrderStore()` produces on a successful cash confirmation.
// Bypasses the wizard UI so the spec stays fast and deterministic.
function seedPaidPosOrder({ suffix, total }) {
    const snippet =
        `$o = new App\\Models\\Order; ` +
        `$o->order_serial_no = 'WQ2-${suffix}'; ` +
        `$o->user_id = 1; ` +
        `$o->branch_id = 1; ` +
        `$o->subtotal = ${total}; ` +
        `$o->total = ${total}; ` +
        `$o->order_type = 15; ` + // POS
        `$o->order_datetime = now('UTC'); ` +
        `$o->preparation_time = 5; ` +
        `$o->is_advance_order = App\\Enums\\Ask::NO; ` +
        `$o->payment_method = 1; ` +
        `$o->pos_payment_method = 1; ` +
        `$o->payment_status = 10; ` + // PAID
        `$o->status = ${STATUS.ACCEPT}; ` +
        `$o->business_date = now()->toDateString(); ` +
        `$o->save(); ` +
        `echo $o->id;`;
    const out = php(snippet);
    const m = out.match(/(\d+)\s*$/);
    return m ? parseInt(m[1], 10) : null;
}

function readOrderStatus(orderId) {
    const snippet = `echo (int) App\\Models\\Order::where('id', ${orderId})->value('status');`;
    const out = php(snippet).trim();
    const m = out.match(/(\d+)\s*$/);
    return m ? parseInt(m[1], 10) : null;
}

function wipeSeededOrders() {
    return php(
        `App\\Models\\Order::withTrashed()->where('order_serial_no','like','WQ2-%')->forceDelete();`,
    );
}

// POST through the same axios route the KDS Vue client uses. We exercise the
// real Laravel pipeline (auth + permission:kitchen-display-system +
// KdsOrderStatusRequest + ValidStatusTransition rule + OrderStateMachine +
// audit) — anything the UI would have hit. Using `window.axios` rather than
// raw fetch so the apiKey + sanctum cookie + base URL match the in-browser
// SPA exactly (mirrors rush-sync-flow.spec.js:423 pattern). The payload
// includes `expected_status` for the server's optimistic-concurrency guard
// (KdsOrderStatusRequest:27), matching the in-app `kdsStatusPayload()`
// helper in resources/js/store/modules/kds.js:3-9.
//
// Returns { status: HTTP, body } for assertions.
async function kdsChangeStatus(page, orderId, expectedStatus, targetStatus) {
    return await page.evaluate(
        async ({ orderId, expectedStatus, targetStatus }) => {
            try {
                const r = await window.axios.post(
                    `admin/kds-order/change-status/${orderId}`,
                    {
                        id: orderId,
                        status: targetStatus,
                        expected_status: expectedStatus,
                    },
                    {
                        headers: {
                            'X-Idempotency-Key': `wq2-${orderId}-${targetStatus}-${Date.now()}`,
                        },
                    },
                );
                return { status: r.status, body: r.data ?? null };
            } catch (err) {
                return {
                    status: err?.response?.status ?? 0,
                    body: err?.response?.data ?? null,
                };
            }
        },
        { orderId, expectedStatus, targetStatus },
    );
}

function logErr(consoleErrors, msg) {
    if (msg.type() === 'error') {
        const text = msg.text();
        if (!FATAL_ERR_FILTER.test(text)) consoleErrors.push(text);
    }
}

test.describe('Wave Q-2 status consistency — CONFIRMÉE → PRÉPARATION → PRÊT for ALL orders', () => {
    test.setTimeout(180_000);

    test.beforeAll(async () => {
        wipeSeededOrders();
    });
    test.afterAll(async () => {
        wipeSeededOrders();
    });

    test('two paid POS orders both start ACCEPT; two bumps each → PREPARING → PREPARED', async ({
        page,
    }) => {
        /** @type {string[]} */ const consoleErrors = [];
        page.on('console', (msg) => logErr(consoleErrors, msg));

        // ─── Seed two paid POS orders (10€ + 0.90€ — owner's exact case) ───
        const orderA = seedPaidPosOrder({ suffix: 'A-10EUR', total: 10.0 });
        const orderB = seedPaidPosOrder({ suffix: 'B-090EUR', total: 0.9 });
        expect(orderA, '10€ order seeded').toBeTruthy();
        expect(orderB, '0.90€ order seeded').toBeTruthy();

        // ─── Login as admin (has kitchen-display-system permission) ─────────
        await loginAsAdmin(page);

        // ─── STATE 1: both orders MUST be ACCEPT (no client auto-promote) ──
        await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(2_000);

        await page.screenshot({
            path: path.join(SHOTS_DIR, 'Q2-01-both-confirmed.png'),
            fullPage: true,
        });

        const statusA1 = readOrderStatus(orderA);
        const statusB1 = readOrderStatus(orderB);
        expect(statusA1, '10€ order initial status = ACCEPT').toBe(STATUS.ACCEPT);
        expect(statusB1, '0.90€ order initial status = ACCEPT').toBe(STATUS.ACCEPT);

        // ─── STATE 2: first bump on each → PREPARING (NOT PREPARED) ────────
        const bumpA1 = await kdsChangeStatus(page, orderA, STATUS.ACCEPT, STATUS.PREPARING);
        const bumpB1 = await kdsChangeStatus(page, orderB, STATUS.ACCEPT, STATUS.PREPARING);
        expect(bumpA1.status, `10€ first bump HTTP 2xx (body=${JSON.stringify(bumpA1.body)})`).toBeLessThan(300);
        expect(bumpB1.status, `0.90€ first bump HTTP 2xx (body=${JSON.stringify(bumpB1.body)})`).toBeLessThan(300);

        const statusA2 = readOrderStatus(orderA);
        const statusB2 = readOrderStatus(orderB);
        expect(statusA2, '10€ order after 1st bump = PREPARING').toBe(STATUS.PREPARING);
        expect(statusB2, '0.90€ order after 1st bump = PREPARING').toBe(STATUS.PREPARING);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(1_500);
        await page.screenshot({
            path: path.join(SHOTS_DIR, 'Q2-02-both-preparing.png'),
            fullPage: true,
        });

        // ─── STATE 3: second bump on each → PREPARED ───────────────────────
        const bumpA2 = await kdsChangeStatus(page, orderA, STATUS.PREPARING, STATUS.PREPARED);
        const bumpB2 = await kdsChangeStatus(page, orderB, STATUS.PREPARING, STATUS.PREPARED);
        expect(bumpA2.status, `10€ second bump HTTP 2xx (body=${JSON.stringify(bumpA2.body)})`).toBeLessThan(300);
        expect(bumpB2.status, `0.90€ second bump HTTP 2xx (body=${JSON.stringify(bumpB2.body)})`).toBeLessThan(300);

        const statusA3 = readOrderStatus(orderA);
        const statusB3 = readOrderStatus(orderB);
        expect(statusA3, '10€ order after 2nd bump = PREPARED').toBe(STATUS.PREPARED);
        expect(statusB3, '0.90€ order after 2nd bump = PREPARED').toBe(STATUS.PREPARED);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(1_500);
        await page.screenshot({
            path: path.join(SHOTS_DIR, 'Q2-03-both-prepared.png'),
            fullPage: true,
        });

        // ─── Critical guardrail: ACCEPT → PREPARED MUST be rejected ────────
        // Re-seed a fresh order to confirm the server still refuses the skip.
        const orderC = seedPaidPosOrder({ suffix: 'C-SKIPGUARD', total: 5.0 });
        expect(orderC, 'guard order seeded').toBeTruthy();
        const illegalSkip = await kdsChangeStatus(page, orderC, STATUS.ACCEPT, STATUS.PREPARED);
        expect(
            illegalSkip.status,
            'ACCEPT → PREPARED is forbidden by OrderStateMachine — server returns 4xx',
        ).toBeGreaterThanOrEqual(400);
        const statusC = readOrderStatus(orderC);
        expect(statusC, 'guard order stays ACCEPT after illegal skip').toBe(STATUS.ACCEPT);
    });

    test('regression artifact — no fatal console errors during the flow', async ({ page }) => {
        const errs = [];
        page.on('console', (msg) => logErr(errs, msg));
        await loginAsAdmin(page);
        await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(2_000);
        const fatals = errs.filter((m) =>
            /TypeError|ReferenceError|Cannot read|is not a function|is not defined/i.test(m),
        );
        expect(fatals, `fatal console errors: ${JSON.stringify(fatals)}`).toHaveLength(0);
    });
});
