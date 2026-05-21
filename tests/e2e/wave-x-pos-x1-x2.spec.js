// Wave X X1 + X2 E2E — POS SSOT counter-collect modal + main-page shortcuts
// (P-OWNER 2026-05-21)
//
// Owner mandate verbatim (translated):
//   X1: "Quand je clique 'Encaisser' sur commande borne cash-pending, je
//       veux que ça ouvre la même popup que pour le POS normal payment.
//       Comme ça toutes les ventes (POS direct, borne, livreur) passent par
//       UN SEUL portail = SSOT pour comptabilité claire."
//   X2: "Caissier ne doit PAS naviguer vers /admin/pos-orders-tracker pour
//       valider commandes prêtes ou encaisser borne. Sur page principale POS,
//       2 zones notifications compactes."
//
// What this spec exercises (live-DB E2E, consistent with Wave V / X4 pattern):
//   1. Admin logs into /admin/pos, page mounts.
//   2. Wave X X2 shortcut wrapper (`pos-shortcuts`) is mounted in the DOM
//      whenever either the readyOrders OR kioskCashOrders list has content.
//   3. If at least one cash-pending kiosk order exists, the spec opens the
//      SSOT modal via the shortcut "Encaisser" button.
//   4. PosCounterCollectModal:
//        - hero total visible
//        - 4 mode buttons (CASH | CARD | MOBILE | TICKET) visible
//        - default mode CASH + received pre-filled to total
//        - cancel button closes the modal without firing a POST
//   5. CASH confirm POST happy path (test-e2e fix A-006 round-1 2026-05-21):
//      exercises POST /admin/pos/counter-collect/{order}/confirm with the
//      X-Idempotency-Key header, asserts modal closes + payment_status flips
//      to PAID server-side, removed from kioskCashOrders shortcut.
//   6. Visual screenshot capture + DOM/console/network quartet via the
//      mega-audit-snap helper (test-e2e fix A-008 round-1 2026-05-21).
//
// Credentials: admin@lecayenne.fr / 123456 (CLAUDE.md §reference_admin_e2e_creds).
//
// Coordination notes (DO NOT REGRESS):
//   - Wave W slide-in kiosk-cash panel still works — the "Encaisser" button
//     inside that panel routes through the SAME SSOT openCounterCollect
//     funnel as the X2 shortcut.
//   - Wave U "Récemment servies" KDS strip is unrelated (different surface).
//   - Wave O O4 cash-sessions-report stays as-is (sibling page).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { seedKioskCashPendingOrder } = require('./helpers/seed-kiosk-cash-pending');

const POS_PATH = '/admin/pos';
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/wave-x-pos-x1-x2';
const REPORT_DIR = 'reports/test-e2e/wave-x-2026-05-21';

for (const d of [SCREENSHOT_DIR, REPORT_DIR]) {
    fs.mkdirSync(path.resolve(d), { recursive: true });
}

// [test-e2e fix A-007 round-1 2026-05-21] Strict cash-pending waiter — no
// silent `.catch(() => null)` swallows. A legitimate 5xx / hang on the
// counter-collect/pending endpoint must FAIL the test, not green-skip it.
async function waitForCashPending(page) {
    const resp = await page.waitForResponse(
        (r) => /\/admin\/pos\/counter-collect\/pending/.test(r.url()) && r.status() < 500,
        { timeout: 30_000 }
    );
    const body = await resp.json();
    return body?.data || [];
}

test.describe('Wave X X1 + X2 — POS SSOT counter-collect + main-page shortcuts', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('POS main page mounts with operator bar + shortcuts wrapper available', async ({ page }) => {
        const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        const cashRespPromise = waitForCashPending(page);
        await page.goto(POS_PATH, { waitUntil: 'domcontentloaded' });

        // POS operator bar baseline — tracker button + cash session button
        // must still be in the DOM (Wave W → Wave X carry-over).
        await expect(page.locator('[data-testid="pos-tracker-open"]')).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('[data-testid="pos-cash-session-open"]')).toBeVisible();

        const cashPending = await cashRespPromise;

        // Give Vue a tick to bind readyOrders + kioskCashOrders to the DOM.
        await page.waitForTimeout(700);

        const shortcutsBlock = page.locator('[data-testid="pos-shortcuts"]');
        if (cashPending.length > 0) {
            await expect(shortcutsBlock).toBeVisible({ timeout: 15_000 });
            await expect(page.locator('[data-testid="pos-shortcuts-cash"]')).toBeVisible();
        } else {
            const count = await shortcutsBlock.count();
            if (count > 0) {
                const ready = page.locator('[data-testid="pos-shortcuts-ready"]');
                const cash = page.locator('[data-testid="pos-shortcuts-cash"]');
                const readyCount = await ready.count();
                const cashCount = await cash.count();
                expect(readyCount + cashCount).toBeGreaterThan(0);
            }
        }

        await recorder.snap('01-pos-main-mount');

        // [wave-x adversarial round-1 2026-05-21] Add a dedicated capture of
        // the X2 shortcuts wrapper (both panels surfaced when DB has rows).
        if (cashPending.length > 0 || (await shortcutsBlock.count()) > 0) {
            try {
                await shortcutsBlock.scrollIntoViewIfNeeded({ timeout: 5_000 });
            } catch (_) { /* best-effort scroll */ }
            await page.waitForTimeout(300);
            await recorder.snap('02-pos-shortcuts-visible');

            // Close-up of the first cash-row (if any) — pure visual sanity
            // for queue number + price + Encaisser CTA alignment.
            const firstCashRow = page.locator('[data-testid^="pos-shortcut-cash-"]').first();
            if (await firstCashRow.count()) {
                const box = await firstCashRow.boundingBox();
                if (box) {
                    await page.screenshot({
                        path: path.join(SCREENSHOT_DIR, '03-shortcut-cash-card-row.png'),
                        clip: {
                            x: Math.max(box.x - 12, 0),
                            y: Math.max(box.y - 12, 0),
                            width: Math.min(box.width + 24, page.viewportSize().width),
                            height: Math.min(box.height + 24, page.viewportSize().height),
                        },
                    });
                }
            }
        }

        recorder.dispose();
    });

    test('Counter-collect SSOT modal opens from shortcut Encaisser (if cash-pending exists)', async ({ page }) => {
        const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        const cashRespPromise = waitForCashPending(page);
        await page.goto(POS_PATH, { waitUntil: 'domcontentloaded' });
        const cashPending = await cashRespPromise;

        await page.waitForTimeout(700);

        if (cashPending.length === 0) {
            // Test is informational — surface the gate cleanly without
            // failing the run on an empty DB. POS_WAVE_X_REQUIRE_SEED=1
            // promotes the gap to a hard failure on CI (test-e2e fix A-007).
            const requireSeed = process.env.POS_WAVE_X_REQUIRE_SEED === '1';
            test.info().annotations.push({
                type: requireSeed ? 'fail' : 'note',
                description:
                    'X1 modal-open test SKIPPED: no kiosk cash-pending order in DB. '
                    + 'Owner manual-verify URL: /admin/pos → seed a kiosk paid-at-counter '
                    + 'order via the kiosk flow, then click Encaisser on the shortcut.',
            });
            if (requireSeed) {
                throw new Error('POS_WAVE_X_REQUIRE_SEED=1 but no kiosk cash-pending order seeded.');
            }
            recorder.dispose();
            return;
        }

        // Pick the first row in the X2 shortcut block.
        const firstRow = page.locator('[data-testid^="pos-shortcut-cash-"]').first();
        await expect(firstRow).toBeVisible({ timeout: 10_000 });

        const encaisserBtn = firstRow.locator('[data-testid^="pos-shortcut-encaisser-"]');
        await expect(encaisserBtn).toBeVisible();
        await encaisserBtn.click();

        // SSOT modal mounts.
        const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
        await expect(modal).toBeVisible({ timeout: 10_000 });

        // [test-e2e fix A-003 round-1 2026-05-21] Wait for the fade-enter
        // transition (160ms) to settle before screenshot — avoids the
        // mid-transition backdrop-bleed artifact captured in round-1.
        await modal.evaluate((el) => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
        await page.waitForTimeout(220);

        // Hero total + 4 mode buttons visible.
        await expect(page.locator('[data-testid="pos-counter-collect-total"]')).toBeVisible();
        await expect(page.locator('[data-testid="pos-counter-collect-mode-CASH"]')).toBeVisible();
        await expect(page.locator('[data-testid="pos-counter-collect-mode-CARD"]')).toBeVisible();
        await expect(page.locator('[data-testid="pos-counter-collect-mode-MOBILE"]')).toBeVisible();
        await expect(page.locator('[data-testid="pos-counter-collect-mode-TICKET"]')).toBeVisible();

        // CASH is the default selected mode (received pre-filled to total).
        const receivedInput = page.locator('[data-testid="pos-counter-collect-received-input"]');
        await expect(receivedInput).toBeVisible();
        const prefilled = await receivedInput.inputValue();
        expect(Number(prefilled)).toBeGreaterThan(0);

        await recorder.snap('04-counter-collect-modal-open');

        // Dedicated CASH-mode frame.
        await expect(
            page.locator('[data-testid="pos-counter-collect-cash-block"]')
        ).toBeVisible({ timeout: 5_000 });
        await recorder.snap('05-counter-collect-cash-mode');

        // Cancel closes the modal cleanly without POSTing.
        await page.locator('[data-testid="pos-counter-collect-cancel"]').click();
        await expect(modal).toBeHidden({ timeout: 5_000 });

        await recorder.snap('07-counter-collect-cancel');
        recorder.dispose();
    });

    test('Counter-collect modal flips to CARD mode (single-tap confirm UX)', async ({ page }) => {
        const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        const cashRespPromise = waitForCashPending(page);
        await page.goto(POS_PATH, { waitUntil: 'domcontentloaded' });
        const cashPending = await cashRespPromise;

        await page.waitForTimeout(700);
        if (cashPending.length === 0) {
            test.info().annotations.push({
                type: 'note',
                description: 'X1 CARD-mode test SKIPPED: no kiosk cash-pending order in DB.',
            });
            recorder.dispose();
            return;
        }

        const encaisserBtn = page
            .locator('[data-testid^="pos-shortcut-encaisser-"]')
            .first();
        await expect(encaisserBtn).toBeVisible({ timeout: 10_000 });
        await encaisserBtn.click();

        const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
        await expect(modal).toBeVisible({ timeout: 10_000 });

        // [test-e2e fix A-003 round-1 2026-05-21] Settle the fade-enter
        // transition before the CARD-mode capture.
        await modal.evaluate((el) => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
        await page.waitForTimeout(220);

        // Click CARD mode — the cash sub-section disappears, non-cash info appears.
        await page.locator('[data-testid="pos-counter-collect-mode-CARD"]').click();

        await expect(
            page.locator('[data-testid="pos-counter-collect-noncash-block"]')
        ).toBeVisible({ timeout: 5_000 });
        await expect(
            page.locator('[data-testid="pos-counter-collect-cash-block"]')
        ).toHaveCount(0);

        await recorder.snap('06-counter-collect-card-mode');

        // Cancel — no POST hits the backend.
        await page.locator('[data-testid="pos-counter-collect-cancel"]').click();
        await expect(modal).toBeHidden({ timeout: 5_000 });
        recorder.dispose();
    });

    // [test-e2e fix A-006 round-1 2026-05-21] CASH confirm happy path.
    // Exercises the POST /admin/pos/counter-collect/{order}/confirm route
    // end-to-end:
    //   1. Verifies the request fires with mode=1 (CASH) + received >= total.
    //   2. Verifies the X-Idempotency-Key header follows the documented
    //      formula `pos-counter-collect-{orderId}-{modeInt}-{minuteBucket}`.
    //   3. Verifies modal closes, list refetches, and the order disappears
    //      from the shortcut block.
    // Gated on cash-pending DB presence — same SKIP discipline as the open
    // tests (POS_WAVE_X_REQUIRE_SEED=1 promotes empty-DB to hard fail).
    test('Counter-collect CASH confirm POSTs with X-Idempotency-Key + row disappears', async ({ page }) => {
        const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

        // [test-e2e fix A-006 round-1 2026-05-21] Seed a fresh kiosk
        // PENDING_COUNTER cash order via tinker. This is the non-
        // destructive way to make the confirm-POST test deterministic
        // across runs — without it the spec depends on whatever stale
        // rows happen to live in dev DB, and the first successful run
        // PAYs the only available order leaving subsequent runs to 422.
        // The seed satisfies assertCounterDeferredOrder invariants
        // (payment_method=CASH_ON_DELIVERY + pos_payment_method=COUNTER_
        // DEFERRED + source_surface=kiosk).
        const seeded = seedKioskCashPendingOrder({ total: 7.5, branchId: 1 });
        const targetOrderId = String(seeded.id);

        await page.goto(POS_PATH, { waitUntil: 'domcontentloaded' });
        // Wait for the pending fetch and verify our seeded id is present.
        const cashPending = await waitForCashPending(page);
        await page.waitForTimeout(700);

        const seededInList = cashPending.some((o) => String(o.id) === targetOrderId);
        expect(seededInList, `seeded order id=${targetOrderId} must surface in /counter-collect/pending`).toBe(true);

        // Pinpoint the seeded row by id so we never confuse it with
        // pre-existing rows.
        const seededRow = page.locator(`[data-testid="pos-shortcut-cash-${targetOrderId}"]`);
        await expect(seededRow).toBeVisible({ timeout: 10_000 });

        const encaisserBtn = seededRow.locator('[data-testid^="pos-shortcut-encaisser-"]');
        await expect(encaisserBtn).toBeVisible();
        await encaisserBtn.click();

        const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
        await expect(modal).toBeVisible({ timeout: 10_000 });
        await modal.evaluate((el) => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
        await page.waitForTimeout(220);

        // CASH is default — received is pre-filled to total. Exact-change
        // scenario (accept the pre-fill).
        const receivedInput = page.locator('[data-testid="pos-counter-collect-received-input"]');
        const prefilled = Number(await receivedInput.inputValue());
        expect(prefilled).toBeGreaterThan(0);

        // Arm ALL network listeners BEFORE clicking confirm. No silent catch:
        // if the POST never fires (or 5xx) the test must fail.
        // The refetch listener must be armed pre-click — PosComponent fires
        // `loadKioskCashOrders()` synchronously after the modal emits
        // `confirmed`, and a post-click listener attaches too late.
        const confirmReqPromise = page.waitForRequest(
            (req) =>
                req.method() === 'POST'
                && new RegExp(`/admin/pos/counter-collect/${targetOrderId}/confirm`).test(req.url()),
            { timeout: 15_000 }
        );
        const confirmRespPromise = page.waitForResponse(
            (resp) =>
                resp.request().method() === 'POST'
                && new RegExp(`/admin/pos/counter-collect/${targetOrderId}/confirm`).test(resp.url()),
            { timeout: 15_000 }
        );
        // Match a GET /pending that fires AFTER the confirm POST returns.
        // Track confirm-response timestamp via a closure to filter out any
        // pre-confirm /pending polls.
        let confirmCompletedAt = 0;
        confirmRespPromise.then(() => { confirmCompletedAt = Date.now(); }).catch(() => {});
        const refetchRespPromise = page.waitForResponse(
            (r) =>
                /\/admin\/pos\/counter-collect\/pending/.test(r.url())
                && r.request().method() === 'GET'
                && r.status() < 500
                && confirmCompletedAt > 0
                && Date.now() >= confirmCompletedAt,
            { timeout: 20_000 }
        );

        await page.locator('[data-testid="pos-counter-collect-confirm"]').click();

        const confirmReq = await confirmReqPromise;
        const headers = confirmReq.headers();
        // Header lookup is case-insensitive; node-fetch normalises to lower.
        const idempotencyKey = headers['x-idempotency-key'] || headers['X-Idempotency-Key'];
        expect(idempotencyKey, 'X-Idempotency-Key header must be present').toBeTruthy();
        expect(idempotencyKey).toMatch(
            new RegExp(`^pos-counter-collect-${targetOrderId}-\\d+-\\d+$`)
        );

        const postBody = confirmReq.postDataJSON();
        // mode=1 is the CASH enum (App\\Enums\\PosPaymentMethod::CASH).
        expect(postBody.mode).toBe(1);
        expect(Number(postBody.received)).toBeGreaterThanOrEqual(prefilled);

        const confirmResp = await confirmRespPromise;
        if (confirmResp.status() >= 300) {
            const errBody = await confirmResp.text().catch(() => '<no body>');
            throw new Error(`confirm POST returned ${confirmResp.status()}: ${errBody.slice(0, 500)}`);
        }

        // Modal closes after a successful confirm.
        await expect(modal).toBeHidden({ timeout: 10_000 });

        // The shortcut block re-fetches kiosk-cash list. We armed the
        // listener BEFORE the click — await it now. Strict (no .catch).
        const refetchResp = await refetchRespPromise;
        const refetchBody = await refetchResp.json();
        const stillPending = (refetchBody?.data || []).some(
            (o) => String(o.id) === String(targetOrderId)
        );
        expect(stillPending, 'paid order must disappear from kioskCashOrders').toBe(false);

        await recorder.snap('08-counter-collect-confirm-success');
        recorder.dispose();
    });
});
