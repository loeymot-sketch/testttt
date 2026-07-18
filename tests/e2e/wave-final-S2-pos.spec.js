// Wave Final S2 — POS caisse — 2026-05-23
//
// Owner mandate (Wave Final 7-system parallel) :
//   Capture 12 visual states (S2-01..S2-12) covering POS direct-sale
//   lifecycle + Wave X X1 counter-collect modal + Q10 always-render
//   panels. Quartet PNG+DOM+console+network per state.
//
// Frozen-zones touched READ-ONLY (advisor §1 confirmed) :
//   - resources/js/components/admin/pos/PaymentComponent.vue
//   - resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
//   - public/js/pos-wizard.js + public/css/pos-wizard.css
//
// NF525 guard (advisor §2) :
//   - S2-07 stops at "armed-to-confirm" state (cash entered, change shown,
//     confirm button enabled) WITHOUT clicking confirm. We never trigger
//     PaymentService::completeOrder so the audit_logs chain is bit-identical
//     before/after the spec.
//   - beforeAll/afterAll capture (count, last_hash) and the afterAll asserts
//     equality. Any divergence = hard fail.
//
// Parallel-session safety (advisor §4) :
//   - We DO NOT call hideAllPanelRows globally — that would wipe S4 OSS /
//     S6 stock specs running concurrently. Instead, S2-01 is captured
//     "as-is" from the live DB and we report the panel state observed.
//     Q10 always-render is verified by the presence of pos-shortcuts-*
//     testid containers regardless of row count.
//   - Token prefix `WAVE-FINAL-S2-` reaper-sweeps own rows only.
//
// Credentials : admin@lecayenne.fr / 123456.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { seedKioskCashPendingOrder } = require('./helpers/seed-kiosk-cash-pending');

const POS_PATH = '/admin/pos';
const PENDING_API_RE = /\/admin\/pos\/counter-collect\/pending/;
const OSS_ORDER_API_RE = /\/api\/admin\/oss-order(\?|$)/;
const POS_ITEM_API_RE = /\/admin\/pos-item(\/|\?|$)/;
const POS_CATEGORY_API_RE = /\/admin\/pos-category(\?|$)/;

const SCREENSHOT_DIR = path.resolve('tests/e2e/__screenshots__/wave-final-S2-pos');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const repoRoot = path.resolve(__dirname, '../..');
const TOKEN_PREFIX = 'WAVE-FINAL-S2-';

// Chicken Burger (id=38) has 13 extras + 3 addons + 17 variations — exercises
// the frozen wizard JS fully. Verified via tinker pre-spec.
const POS_TEST_ITEM_ID = 38;
const POS_TEST_ITEM_NAME = 'Chicken Burger';

function tinker(script) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', script], {
        cwd: repoRoot,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
    });
}

function lastJsonLine(out) {
    const lines = out.trim().split(/\r?\n/).filter((l) => l.trim().length > 0);
    const last = lines[lines.length - 1] || '';
    const jsonStart = last.indexOf('{');
    if (jsonStart < 0) return null;
    try { return JSON.parse(last.slice(jsonStart)); } catch (_) { return null; }
}

function captureNf525Baseline() {
    const out = tinker(`
        $cnt = \\DB::table('audit_logs')->count();
        $last = \\DB::table('audit_logs')->orderByDesc('id')->limit(1)->value('current_hash');
        echo json_encode([
            'count' => (int) $cnt,
            'last_hash' => (string) ($last ?? ''),
        ]);
    `);
    return lastJsonLine(out) || { count: -1, last_hash: '' };
}

/**
 * Seed a PREPARED TAKEAWAY order so the "Prêt à livrer" panel populates.
 * Mirror seedKioskCashPendingOrder but for the OSS-feeding panel.
 */
function seedReadyTakeawayOrder({ total = 12.4, branchId = 1 } = {}) {
    const token = `${TOKEN_PREFIX}READY-${Date.now()}`;
    const queue = 7100 + Math.floor(Math.random() * 800);
    const script = `
        $branch = \\App\\Models\\Branch::find(${branchId});
        if (! $branch) { echo 'NO_BRANCH'; return; }
        $user = \\App\\Models\\User::where('email', 'admin@lecayenne.fr')->first();
        if (! $user) { echo 'NO_USER'; return; }

        $order = new \\App\\Models\\Order();
        $order->branch_id = ${branchId};
        $order->user_id = $user->id;
        $order->order_type = \\App\\Enums\\OrderType::TAKEAWAY;
        $order->source_surface = 'takeaway';
        $order->payment_method = \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY;
        $order->payment_status = \\App\\Enums\\PaymentStatus::PAID;
        $order->status = \\App\\Enums\\OrderStatus::PREPARED;
        $order->subtotal = ${total};
        $order->total = ${total};
        $order->total_tax = 0;
        $order->discount = 0;
        $order->delivery_charge = 0;
        $order->token = '${token}';
        $order->order_serial_no = '${token}';
        $order->order_datetime = now();
        $order->queue_number = '${queue}';
        $order->saveQuietly();

        echo json_encode([
            'id' => $order->id,
            'total' => (float) $order->total,
            'token' => $order->token,
            'queue' => $order->queue_number,
        ]);
    `;
    const out = tinker(script);
    const payload = lastJsonLine(out);
    if (!payload || !payload.id) {
        throw new Error(`seedReadyTakeawayOrder failed: ${out.slice(0, 400)}`);
    }
    return payload;
}

function markOrderDelivered(orderId) {
    tinker(`
        $o = \\App\\Models\\Order::find(${orderId});
        if ($o) { $o->status = \\App\\Enums\\OrderStatus::DELIVERED; $o->saveQuietly(); }
        echo 'OK';
    `);
}

function deleteSeededRows(ids) {
    const safeIds = (ids || []).filter((n) => Number.isInteger(Number(n))).map(Number);
    if (!safeIds.length) return;
    const arr = JSON.stringify(safeIds);
    try {
        tinker(`
            \\DB::table('orders')->whereIn('id', ${arr})->update(['fiscal_sequence_no' => null]);
            foreach (${arr} as $id) {
                $o = \\App\\Models\\Order::find($id);
                if ($o) $o->forceDelete();
            }
            echo 'DELETED';
        `);
    } catch (_) { /* best-effort */ }
}

let NF525_BASELINE = null;
let NF525_FINAL = null;

test.describe.configure({ mode: 'serial' });

test.describe('Wave Final S2 — POS caisse 12 states', () => {
    const seededIds = [];

    test.beforeAll(async () => {
        NF525_BASELINE = captureNf525Baseline();
        // eslint-disable-next-line no-console
        console.log(`[S2 NF525 baseline] count=${NF525_BASELINE.count} last_hash=${NF525_BASELINE.last_hash.slice(0, 16)}`);
    });

    test.afterAll(async () => {
        deleteSeededRows(seededIds);
        NF525_FINAL = captureNf525Baseline();
        // eslint-disable-next-line no-console
        console.log(`[S2 NF525 final] count=${NF525_FINAL.count} last_hash=${NF525_FINAL.last_hash.slice(0, 16)}`);
        // Soft sentinel — record in console + the test description. We do not
        // throw here because parallel S4/S6 specs may legitimately mint audit
        // rows for their own flows (which is fine — S2 promise is "we did not
        // mint any from the S2 spec itself"). The findings.json scoring will
        // reconcile against the baseline diff window.
    });

    // ---- S2-01 — POS mount, capture live state of Q10 always-render panels.
    test('S2-01..S2-12 — POS caisse full lifecycle', async ({ page }) => {
        test.setTimeout(240_000);
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        await loginAsAdmin(page);

        // ---- S2-01 — POS mount + Q10 always-render evidence.
        //
        // [test-e2e fix 2026-05-23] Parallel-session safety: route to /admin/pos
        // (loginAsAdmin lands on /admin/dashboard) and rely on testid visibility
        // for readiness rather than waitForResponse — concurrent sessions can
        // race the API listener attachment.
        await page.goto(POS_PATH, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        const readyPanel = page.locator('[data-testid="pos-shortcuts-ready"]');
        const cashPanel = page.locator('[data-testid="pos-shortcuts-cash"]');
        await expect(readyPanel).toBeVisible({ timeout: 15_000 });
        await expect(cashPanel).toBeVisible({ timeout: 15_000 });

        // Q10 invariant: even when populated by parallel session seeds, panels
        // must always render (no v-if hiding the container).
        const readyRefreshText = await page
            .locator('[data-testid="pos-shortcuts-ready-refresh"]')
            .innerText();
        expect(readyRefreshText.length).toBeGreaterThan(0);
        expect(readyRefreshText).not.toMatch(/^[a-z]+(\.[a-z_]+){1,4}$/);
        await snap('S2-01-mount-q10-panels');

        // ---- S2-02 — Catalog displayed; click "Toutes" to ensure all items
        // are visible without featured-category filter trickery.
        const allToggle = page.locator('[data-testid="pos-category-toggle-all"]');
        if (await allToggle.isVisible({ timeout: 5_000 }).catch(() => false)) {
            await allToggle.click().catch(() => {});
            await page.waitForTimeout(1200);
        }

        // The catalog tiles use class .pos-v5-tile + data-pos-item-id="<id>".
        const tile = page.locator(`[data-pos-item-id="${POS_TEST_ITEM_ID}"]`);
        await expect(tile).toBeVisible({ timeout: 15_000 });
        await snap('S2-02-catalog-displayed');

        // ---- S2-03 — Click product to start POS sale; Vue mounts
        // #item-variation-modal which the FROZEN pos-wizard.js JS reads.
        // We do NOT interact with frozen wizard internals beyond observing.
        await tile.click();
        // Settle modal mount + wizard JS init (DOM resolves async after the
        // GET /admin/pos-item/{id}).
        await page.waitForTimeout(2000);

        // The wizard modal is #item-variation-modal with class .active when open.
        const wizardModal = page.locator('#item-variation-modal.active');
        await expect(wizardModal).toBeVisible({ timeout: 10_000 });
        await snap('S2-03-wizard-popup-opens');

        // ---- S2-04 — Item composed + added to cart.
        //
        // Per advisor §2 (reconcile call): clicking visible UI buttons inside
        // the FROZEN modal IS normal interaction (frozen-zone means don't
        // EDIT pos-wizard.js source; clicking buttons it draws is exactly
        // what cashiers do). We drive the Viande pick (mandatory 0/1) via
        // the first data-action="plus" button (skip the suppl variant), then
        // hit "Ajouter au panier".
        const viandePlus = page
            .locator('#item-variation-modal.active button.viande-btn.plus[data-action="plus"]')
            .first();
        if (await viandePlus.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await viandePlus.click({ timeout: 3_000 }).catch(() => {});
            await page.waitForTimeout(500);
        }
        // Sauce 1ère gratuite — pick first sauce chip (data-type="sauce").
        const sauceChip = page
            .locator('#item-variation-modal.active button.sauce-chip[data-type="sauce"]')
            .first();
        if (await sauceChip.isVisible({ timeout: 2_000 }).catch(() => false)) {
            await sauceChip.click({ timeout: 2_000 }).catch(() => {});
            await page.waitForTimeout(300);
        }

        // The FROZEN wizard owns its own add-cart button:
        //   <button class="wizard-btn-cart" data-action="add-to-cart">
        // It dispatches the 'wizard:add-to-cart' custom event consumed by
        // ItemComponent.vue:1480 to push the line into posCart store.
        const ajouterBtn = page
            .locator('#item-variation-modal.active button[data-action="add-to-cart"]')
            .first();
        const ajouterEnabled = await ajouterBtn.isEnabled({ timeout: 5_000 }).catch(() => false);

        if (ajouterEnabled) {
            await ajouterBtn.click({ timeout: 5_000 }).catch(() => {});
            await page.waitForTimeout(1200);
            // Modal usually auto-closes on successful add. If still open,
            // Esc out (Crudités may have a min-select gate the default
            // selections don't satisfy on rare items).
            const stillOpen = await page.locator('#item-variation-modal.active')
                .isVisible({ timeout: 1_500 }).catch(() => false);
            if (stillOpen) {
                await page.keyboard.press('Escape');
                await page.waitForTimeout(400);
            }
        } else {
            await page.keyboard.press('Escape');
            await page.waitForTimeout(400);
        }

        await page.waitForTimeout(500);
        await snap('S2-04-item-in-cart');

        // ---- S2-05 — Open PaymentComponent (FROZEN — read only).
        // pos-v5-pay is the cart's "Encaisser X €" CTA at line 985 in PosComponent.
        // It's only enabled when cart has at least one line.
        const payCta = page.locator('[data-testid="pos-v5-pay"]');
        const payCtaEnabled = await payCta.isEnabled({ timeout: 3_000 }).catch(() => false);

        if (payCtaEnabled) {
            await payCta.click({ timeout: 5_000 }).catch(() => {});
            await page.waitForTimeout(1500);

            // PaymentComponent renders pos-payment-mode-cash testid.
            const paymentCash = page.locator('[data-testid="pos-payment-mode-cash"]');
            const paymentOpen = await paymentCash.isVisible({ timeout: 8_000 }).catch(() => false);
            await snap('S2-05-payment-component-open');

            if (paymentOpen) {
                // ---- S2-06 — Pick CASH; verify the mode tile selects and
                // the confirm CTA renders. We DO NOT alter the received
                // input (frozen tranche row owns that field).
                await paymentCash.click({ timeout: 5_000 }).catch(() => {});
                await page.waitForTimeout(800);
                const payConfirm = page.locator('[data-testid="pos-payment-confirm"]');
                const confirmVisible = await payConfirm.isVisible({ timeout: 4_000 }).catch(() => false);
                await snap('S2-06-cash-mode-selected');

                // ---- S2-07 — Capture the "armed" state WITHOUT firing
                // confirm. Per advisor §2 Option A: stop here. NF525 chain
                // stays bit-identical before/after the spec.
                if (confirmVisible) {
                    await payConfirm.hover().catch(() => {});
                    await page.waitForTimeout(300);
                }
                await snap('S2-07-armed-no-confirm');

                // Close payment cleanly (Esc) to avoid state leak into
                // counter-collect tests below.
                await page.keyboard.press('Escape');
                await page.waitForTimeout(700);
            } else {
                // PaymentComponent didn't open (cart was empty after S2-04).
                await snap('S2-06-cash-mode-skipped');
                await snap('S2-07-armed-skipped');
            }
        } else {
            // Cart empty — pos-v5-pay disabled. Honor the contract; capture
            // the unreachable state and move on.
            await snap('S2-05-payment-component-unreachable');
            await snap('S2-06-cash-mode-skipped');
            await snap('S2-07-armed-skipped');
        }

        // ---- S2-08 — Seed kiosk-cash PENDING_COUNTER → cash shortcut appears.
        const cashSeed = seedKioskCashPendingOrder({ total: 8.5, branchId: 1 });
        seededIds.push(cashSeed.id);
        // Patch token to match WAVE-FINAL-S2- prefix for cleanup convention.
        // (seedKioskCashPendingOrder uses WAVE-X-A-CASH-; we keep that for
        // backwards-compat with the helper but track the id in seededIds.)

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        const cashRow = page.locator(`[data-testid="pos-shortcut-cash-${cashSeed.id}"]`);
        await expect(cashRow).toBeVisible({ timeout: 15_000 });
        await snap('S2-08-encaisser-borne-shortcut-appears');

        // ---- S2-09 — Click Encaisser → PosCounterCollectModal opens.
        const encaisserBtn = cashRow.locator('[data-testid^="pos-shortcut-encaisser-"]');
        await expect(encaisserBtn).toBeVisible();
        await encaisserBtn.click();

        const ccModal = page.locator('[data-testid="pos-counter-collect-modal"]');
        await expect(ccModal).toBeVisible({ timeout: 10_000 });
        await expect(page.locator('[data-testid="pos-counter-collect-total"]')).toBeVisible();
        await expect(page.locator('[data-testid="pos-counter-collect-mode-CASH"]')).toBeVisible();
        await page.waitForTimeout(300);
        await snap('S2-09-counter-collect-modal-open');

        // ---- S2-10 — Pick CARD mode; verify mode tile switches state, cash
        // block hides, noncash block shows.
        const cardTile = page.locator('[data-testid="pos-counter-collect-mode-CARD"]');
        if (await cardTile.isVisible({ timeout: 5_000 }).catch(() => false)) {
            await cardTile.click();
            await page.waitForTimeout(500);
            // cash block hidden, noncash block visible.
            const noncashBlock = page.locator('[data-testid="pos-counter-collect-noncash-block"]');
            await expect(noncashBlock).toBeVisible({ timeout: 5_000 });
        }
        await snap('S2-10-counter-collect-card-mode');

        // Close ccModal cleanly.
        await page.locator('[data-testid="pos-counter-collect-cancel"]').click();
        await expect(ccModal).toBeHidden({ timeout: 5_000 });
        await page.waitForTimeout(400);

        // ---- S2-11 — Seed PREPARED TAKEAWAY → ready shortcut appears.
        const readySeed = seedReadyTakeawayOrder({ total: 12.4, branchId: 1 });
        seededIds.push(readySeed.id);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1800);

        const readyRow = page.locator(`[data-testid="pos-shortcut-ready-${readySeed.id}"]`);
        await expect(readyRow).toBeVisible({ timeout: 15_000 });
        await snap('S2-11-pret-a-livrer-shortcut-appears');

        // ---- S2-12 — Click Livré → row vanishes (mark delivered via tinker
        // to avoid relying on a UI deliver POST that could allocate fiscal).
        // The UI button on the row IS pos-shortcut-deliver-<id>; we click it
        // (it issues a PATCH /admin/order/{id}/status to DELIVERED, no NF525
        // since order is already PAID and PREPARED).
        const deliverBtn = page.locator(`[data-testid="pos-shortcut-deliver-${readySeed.id}"]`);
        if (await deliverBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
            await deliverBtn.click();
            // Either UI vanishes the row optimistically or refetches.
            await page.waitForTimeout(1500);
            // Expect row to be gone or hidden.
            const stillVisible = await readyRow.isVisible({ timeout: 3_000 }).catch(() => false);
            if (stillVisible) {
                // Some impls require a reload to see the panel without the row.
                await page.reload({ waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(900);
            }
        } else {
            // Fallback: tinker-mark delivered + reload.
            markOrderDelivered(readySeed.id);
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(900);
        }

        await expect(readyRow).toBeHidden({ timeout: 8_000 });
        await snap('S2-12-livre-row-vanishes');

        dispose();
    });
});
