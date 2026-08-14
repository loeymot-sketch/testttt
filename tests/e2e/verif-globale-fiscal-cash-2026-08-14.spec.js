// verif-globale-2026-08-14 — Wave C + Wave D1
//
// Two small, NF525-fiscal-adjacent fixes landed 2026-08-14 in the same
// session, bundled here per AUDIT_PLAN.md §"Wave C" / §"Wave D — D1"
// (`reports/test-e2e/verif-globale-2026-08-14/AUDIT_PLAN.md`):
//
//   WAVE C — 11019f363 "marquer payé" (admin dropdown) now calls
//     PosMethodFromGateway::appliquer() after sealing PAID, same as the
//     online-gateway callback path already did — so `pos_payment_method`
//     stops landing NULL on sales closed via this path (measured in prod:
//     19 PAID orders with pos_payment_method NULL, invisible to the signed
//     Z's espèces/carte ventilation). Static + PHPUnit already verified
//     BEFORE this spec was written (see ground truth below); this file adds
//     the ONE live-flow state the plan asks for.
//
//   WAVE D1 — 53b1dc6d6 CashDrawerService.js::closeSession() used to POST
//     /reconcile with an EMPTY body `{}` — `variance_reason`, typed by the
//     cashier in the dialog, was silently dropped before it ever left the
//     browser. Any real multi-week variance (> 2,00€ threshold) hit 422
//     CASH_VARIANCE_REASON_REQUIRED forever, leaving the session stuck OPEN
//     (measured in prod: 2 sessions open 36/49 days, 3 818,30€ never
//     reconciled). This file proves BOTH halves live: (a) the backend guard
//     is still real (a body without variance_reason still 422s above
//     threshold), and (b) the regression itself — the ACTUAL outgoing
//     browser network request for POST /reconcile, driven through the real
//     Vue dialog (not a hand-written page.request call, which would
//     trivially "pass" without proving the frontend code path is fixed),
//     carries `variance_reason` in its body.
//
// Ground truth verified BEFORE writing this spec (not guessed):
//   - `git show 11019f363 --stat` — touches ONLY app/Services/OrderService.php
//     (+25) and a new test file. Zero lines in FiscalSequenceService.php /
//     ZReportService.php / AuditLogService.php / BranchScope.php.
//   - OrderService::changePaymentStatus() (app/Services/OrderService.php
//     ~line 2845) calls
//     app(\App\Services\Payments\PosMethodFromGateway::class)->appliquer($locked)
//     right after `$locked->save()` seals PAID — same call PaymentService::
//     payment() already made (pre-existing service, not new fiscal logic).
//   - PosMethodFromGateway::appliquer() (app/Services/Payments/PosMethodFromGateway.php:69-88):
//     line 71 `if ($order->pos_payment_method !== null) { return; }` — the
//     "never overwrite" guard is real code, not a docblock claim. Only CARD
//     and TICKET_RESTAURANT gateways have a certain POS-side equivalence
//     (CORRESPONDANCES table, line 54-57); CARD(4) → PosPaymentMethod::CARD(2).
//   - `php artisan test tests/Feature/Fiscal/` → 302 passed, 8 skipped
//     (MysqlOnly trigger tests + 1 LOCK-gated test) — matches the commit
//     message's own claim exactly.
//   - PosCashDrawerSessionDialog.vue's `varianceReasonMissing` client-side
//     guard disables the close-submit button when a reason is required but
//     empty/short — so a UI click can never reproduce the "empty body" 422
//     path; that check is done via page.request directly (mirrors the
//     project's established D2 pattern for backend-guard proofs). The
//     regression itself (reason typed, but a client-side JS bug dropped it
//     from the network body) is why a REAL browser network capture is used
//     for the positive path, not a scripted request.
//   - CashDrawerService::reconcileSession() I6 (app/Services/Cash/CashDrawerService.php
//     ~line 225-320): |variance| > cash.variance_threshold_eur (2,00€
//     default) requires non-empty variance_reason AND
//     `cash.reconcile.variance.override` permission (Admin + Branch Manager
//     per config/cash.php docblock) — confirmed live: Branch Manager role
//     has both `pos` and `cash.reconcile.variance.override` permissions.
//   - Dev DB already has 11 pre-existing OPEN cash_drawer_sessions on
//     branch 1 (debris from earlier local test runs, unrelated to the 2 real
//     VPS production sessions the commit message measures) — including one
//     for admin@lecayenne.fr itself (id 32). To avoid touching ANY of that
//     debris (and to keep this spec's mutations fully self-contained /
//     cleanable), Wave D1 creates its OWN throwaway Branch-Manager-role user
//     + its own sessions, deleted in afterAll. The 2 real VPS production
//     sessions are checked read-only over SSH, never written (see bottom).
//   - `/admin/pos` (PosComponent.vue) has a header button
//     `[data-testid=pos-cash-session-open]` that opens
//     PosCashDrawerSessionDialog.vue (mode 'open'/'active'/'close' per
//     current session state) — confirmed via grep, not guessed.
//   - Online order "marquer payé" dropdown lives on
//     OnlineOrderShowComponent.vue (`/admin/online-orders/show/:id`), only
//     rendered `v-if="order.transaction === null"` — no data-testid, text
//     items keyed off $t('label.paid') = "Payé" (fr.json:783).
//
// Credentials: admin@lecayenne.fr / TestVisuel2026! (from task brief, NOT
// the login.js helper's 123456 default — same finding as the Wave A spec).

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/verif-globale-fiscal-cash-2026-08-14';
fs.mkdirSync(path.resolve(SCREENSHOT_DIR), { recursive: true });

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || 'TestVisuel2026!';
const CASH_USER_PASSWORD = 'E2ECash2026!Wave-D1';

/**
 * Run a PHP snippet via `artisan tinker --execute`. NEVER `tinker < file`
 * (parses the leading `<?php` and fails), and never prefix a PHP `$var`
 * with a JS backslash inside the template literal (emits a literal `\$var`,
 * which PHP rejects) — same mechanism as tests/e2e/helpers/rate-limit.js
 * and the Wave A spec's `tinker()` helper.
 */
function tinker(phpCode) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', phpCode], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
    });
}

/**
 * Extracts the Sanctum Bearer token + x-api-key the SPA's axios instance
 * uses, straight from the authenticated page's own runtime state. Same
 * helper as tests/e2e/verif-globale-cashier-permission-2026-08-14.spec.js
 * (page.request does NOT share axios's in-page interceptors, so direct API
 * probes need these attached manually).
 */
async function authHeaders(page) {
    const { token, apiKey } = await page.evaluate(() => {
        let authToken = null;
        try {
            const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
            authToken = (vuex && vuex.auth && vuex.auth.authToken) || null;
        } catch (_e) { /* ignore corrupt localStorage */ }
        const key = (typeof window !== 'undefined' && window.foodkingConfig && window.foodkingConfig.apiKey) || '';
        return { token: authToken, apiKey: key };
    });
    return {
        Authorization: token ? `Bearer ${token}` : '',
        'x-api-key': apiKey,
        'Content-Type': 'application/json',
        Accept: 'application/json',
    };
}

function freshIdempotencyKey(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

// ============================================================================
// WAVE C fixtures — seed an UNPAID online order paid by CARD gateway, never
// touched by a gateway callback (transaction stays null so the admin
// "marquer payé" dropdown renders — OnlineOrderShowComponent.vue
// `v-if="order.transaction === null"`).
// ============================================================================

function seedUnpaidCardOrder() {
    const out = tinker(`
        $customer = \\App\\Models\\User::factory()->create(['name' => 'E2E WaveC Client', 'branch_id' => 0]);
        $order = \\App\\Models\\Order::factory()->create([
            'branch_id' => 1,
            'user_id' => $customer->id,
            'order_type' => \\App\\Enums\\OrderType::TAKEAWAY,
            'source' => \\App\\Enums\\Source::WEB,
            'source_surface' => 'web',
            'payment_method' => \\App\\Enums\\PaymentGateway::CARD,
            'payment_status' => \\App\\Enums\\PaymentStatus::UNPAID,
            'status' => \\App\\Enums\\OrderStatus::ACCEPT,
            'order_datetime' => now(),
            'pos_payment_method' => null,
        ]);
        echo $order->id . '|' . $customer->id;
    `);
    const [orderId, customerId] = String(out).trim().split('|').map((n) => parseInt(n, 10));
    return { orderId, customerId };
}

function deleteWaveCFixture(orderId, customerId) {
    if (!orderId) return;
    try {
        tinker(`
            \\App\\Models\\Order::withoutGlobalScopes()->whereKey(${orderId})->forceDelete();
            ${customerId ? `\\App\\Models\\User::withoutGlobalScopes()->whereKey(${customerId})->forceDelete();` : ''}
            echo 'ok';
        `);
    } catch (err) {
        console.warn('[wave-c] deleteWaveCFixture cleanup warning:', err?.message || err);
    }
}

/** Reads pos_payment_method + payment_status straight from the DB (not the
 * UI, not a cached Eloquent model) — the plan's explicit "not just UI" ask.
 */
function readOrderPaymentColumns(orderId) {
    const out = tinker(`
        $row = \\Illuminate\\Support\\Facades\\DB::table('orders')->where('id', ${orderId})
            ->select('payment_status', 'pos_payment_method', 'payment_method', 'fiscal_sequence_no')
            ->first();
        echo json_encode($row);
    `);
    const jsonStart = String(out).indexOf('{');
    return JSON.parse(String(out).slice(jsonStart).trim());
}

// ============================================================================
// WAVE D1 fixtures — a dedicated throwaway Branch-Manager-role user + its own
// cash-drawer sessions, fully isolated from the 11 pre-existing dev-DB debris
// sessions AND from the 2 real VPS production stuck sessions (never touched).
// ============================================================================

function createCashUser() {
    const email = `e2e-wave-d1-${Date.now()}@lecayenne.fr`;
    const out = tinker(`
        $u = \\App\\Models\\User::factory()->create([
            'name' => 'E2E WaveD1 Manager',
            'email' => '${email}',
            'password' => \\Illuminate\\Support\\Facades\\Hash::make('${CASH_USER_PASSWORD}'),
            'branch_id' => 1,
        ]);
        $u->assignRole('Branch Manager');
        echo $u->id;
    `);
    const userId = parseInt(String(out).trim(), 10);
    return { email, userId };
}

function deleteCashUserFixture(userId, sessionIds) {
    try {
        const ids = (sessionIds || []).filter((n) => Number.isInteger(n) && n > 0);
        tinker(`
            $ids = [${ids.join(',')}];
            if (count($ids) > 0) {
                \\App\\Models\\CashMovement::withoutGlobalScopes()->whereIn('cash_drawer_session_id', $ids)->delete();
                \\App\\Models\\CashDrawerSession::withoutGlobalScopes()->whereIn('id', $ids)->delete();
            }
            ${userId ? `\\App\\Models\\User::withoutGlobalScopes()->whereKey(${userId})->forceDelete();` : ''}
            echo 'ok';
        `);
    } catch (err) {
        console.warn('[wave-d1] deleteCashUserFixture cleanup warning:', err?.message || err);
    }
}

/** Mirrors the PHPUnit fixture's movement seeding exactly (same service call
 * CashDrawerCloseVarianceTest uses) — a real cash-in movement, not a raw
 * DB insert, so `signedAmount()` / reconcileSession()'s sum matches what the
 * dialog will show.
 */
function recordMovement(sessionId, amount) {
    tinker(`
        app(\\App\\Services\\Cash\\CashDrawerService::class)->recordMovement(
            ${sessionId},
            \\App\\Models\\CashMovement::TYPE_ORDER_PAYMENT,
            ${amount},
            \\App\\Models\\CashMovement::DIRECTION_IN,
        );
        echo 'ok';
    `);
}

function readSessionRow(sessionId) {
    const out = tinker(`
        $row = \\Illuminate\\Support\\Facades\\DB::table('cash_drawer_sessions')->where('id', ${sessionId})
            ->select('status', 'variance', 'variance_reason', 'closing_amount', 'expected_closing_amount')
            ->first();
        echo json_encode($row);
    `);
    const jsonStart = String(out).indexOf('{');
    return JSON.parse(String(out).slice(jsonStart).trim());
}

let waveCFixture = { orderId: null, customerId: null };
let waveD1User = { email: null, userId: null };
const waveD1SessionIds = [];

test.describe.configure({ mode: 'default' });

// ============================================================================
// WAVE C — fiscal "mark paid" scope-check (11019f363)
// ============================================================================

test.describe('Wave C — fiscal mark-paid scope-check (11019f363), live flow', () => {
    test.beforeAll(() => {
        clearFoodKingRateLimits();
        waveCFixture = seedUnpaidCardOrder();
    });

    test.afterAll(() => {
        deleteWaveCFixture(waveCFixture.orderId, waveCFixture.customerId);
    });

    test('01-mark-paid-dropdown-sets-payment-method', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            // Sanity: fixture starts UNPAID with pos_payment_method NULL —
            // proves the assertion below is measuring a real transition, not
            // a value that was already correct before the action.
            const before = readOrderPaymentColumns(waveCFixture.orderId);
            expect(before.payment_status).toBe(10); // PaymentStatus::UNPAID
            expect(before.pos_payment_method).toBeNull();

            await loginAsAdmin(page, ADMIN_EMAIL, ADMIN_PASS);
            await page.goto(`/admin/online-orders/show/${waveCFixture.orderId}`, { waitUntil: 'domcontentloaded' });

            // Payment-status dropdown only renders when order.transaction is
            // null (OnlineOrderShowComponent.vue line 136) — confirms the
            // fixture landed the way the surface expects before interacting.
            //
            // [test-e2e fix 2026-08-14] `.dropdown-group .dropdown-btn` is NOT
            // unique on this page — the admin navbar (rendered on every admin
            // page, BEFORE this component in DOM order) has its own
            // `.dropdown-group`/`.dropdown-btn` elements (branch selector,
            // notifications, avatar menu — BackendNavbarComponent.vue:18,54,96)
            // and the order page itself has THREE more (delivery-boy assign,
            // payment-status, order-status). `.first()` silently grabbed the
            // navbar's avatar-menu button instead — confirmed by the failure
            // screenshot showing "Modifier Le Profil / Changer Le Mot De Passe
            // / Déconnexion" open, not the payment dropdown. Scoped by the
            // button's OWN text instead of position: the fixture starts
            // UNPAID, so its button reads exactly "Non payé"
            // (label.unpaid → fr.json:784) — unique on the page, matches the
            // project's own established fix for identical `.dropdown-group`
            // collisions (see memory: "un sélecteur nu a déclenché une
            // collision strict-mode Playwright").
            const dropdownBtn = page.locator('.dropdown-group .dropdown-btn', { hasText: 'Non payé' }).first();
            await expect(dropdownBtn).toBeVisible({ timeout: 15_000 });
            await dropdownBtn.click();

            const paidOption = page.locator('.dropdown-list li', { hasText: 'Payé' }).first();
            await expect(paidOption).toBeVisible({ timeout: 10_000 });

            const [resp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.request().method() === 'POST' && /\/change-payment-status\/\d+$/.test(r.url()),
                    { timeout: 15_000 },
                ),
                paidOption.click(),
            ]);
            expect(resp.status(), 'mark-paid dropdown POST must succeed').toBe(200);

            await snap('01-mark-paid-dropdown-sets-payment-method');

            // THE CORE ASSERTION — DB read, not UI, not a cached Eloquent
            // instance: pos_payment_method must now be CARD(2), the exact
            // mapping PosMethodFromGateway::CORRESPONDANCES declares for
            // PaymentGateway::CARD(4).
            const after = readOrderPaymentColumns(waveCFixture.orderId);
            expect(after.payment_status).toBe(5); // PaymentStatus::PAID
            expect(after.pos_payment_method).toBe(2); // PosPaymentMethod::CARD
            expect(after.payment_method).toBe(4); // PaymentGateway::CARD, untouched
            // Preventive fix confirmed (GOAL_CAYENNE_FINITION §1.2 NF525
            // ventilation gap) also allocates a fiscal sequence on this seal
            // path (pre-existing behavior this commit does not touch, but a
            // useful sanity check that the order really got sealed, not just
            // half-written).
            expect(after.fiscal_sequence_no).not.toBeNull();
        } finally {
            dispose();
        }
    });
});

// ============================================================================
// WAVE D1 — cash-drawer variance-reason regression (53b1dc6d6)
// ============================================================================

test.describe('Wave D1 — cash-drawer variance-reason regression (53b1dc6d6)', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        clearFoodKingRateLimits();
        waveD1User = createCashUser();
    });

    test.afterAll(() => {
        deleteCashUserFixture(waveD1User.userId, waveD1SessionIds);
    });

    test('02a-close-without-reason-still-422-guard-intact', async ({ page }) => {
        // Backend-guard proof (I6) — bypasses the client-side
        // `varianceReasonMissing` UI gate on purpose (that gate makes it
        // IMPOSSIBLE to click a real submit button with an empty reason;
        // this proves the SERVER still refuses the old buggy `{}` body
        // shape independently of the UI, mirroring the project's
        // established "bypass the UI via page.request directly" pattern for
        // negative/guard proofs — see D2 state 05 in AUDIT_PLAN.md).
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsAdmin(page, waveD1User.email, CASH_USER_PASSWORD);
            await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
            const headers = await authHeaders(page);
            expect(headers.Authorization, 'must have a real bearer token before probing cash-drawer API').not.toBe('Bearer ');

            const openResp = await page.request.post('/api/admin/pos/cash-drawer/sessions/open', {
                headers: { ...headers, 'X-Idempotency-Key': freshIdempotencyKey('e2e-open-a') },
                data: { opening_amount: 50 },
            });
            expect(openResp.status()).toBe(201);
            const sessionA = (await openResp.json()).data.id;
            waveD1SessionIds.push(sessionA);

            // 50,00€ opening, 0 movements, closed at 10,00€ → variance -40,00€,
            // far past the 2,00€ threshold.
            const closeResp = await page.request.post(`/api/admin/pos/cash-drawer/sessions/${sessionA}/close`, {
                headers: { ...headers, 'X-Idempotency-Key': freshIdempotencyKey('e2e-close-a') },
                data: { closing_amount: 10.0 },
            });
            expect(closeResp.status()).toBe(200);
            expect((await closeResp.json()).data.status).toBe('closed');

            // Reproduces EXACTLY the historical bug's wire shape: `{}`.
            const reconcileResp = await page.request.post(`/api/admin/pos/cash-drawer/sessions/${sessionA}/reconcile`, {
                headers: { ...headers, 'X-Idempotency-Key': freshIdempotencyKey('e2e-reconcile-a') },
                data: {},
            });
            expect(reconcileResp.status(), 'guard I6 must still refuse an above-threshold reconcile with no reason').toBe(422);
            const reconcileBody = await reconcileResp.json();
            expect(reconcileBody.code).toBe('CASH_VARIANCE_REASON_REQUIRED');

            // Session must NOT have silently transitioned past CLOSED — no
            // phantom "success" that would mask the unrecorded variance.
            const row = readSessionRow(sessionA);
            expect(row.status).toBe('closed');
            expect(row.variance).toBeNull();

            await snap('02a-close-without-reason-still-422-guard-intact');
        } finally {
            dispose();
        }
    });

    test('02b-close-with-reason-network-body-carries-variance-reason', async ({ page }) => {
        // THE REGRESSION PROOF ITSELF. Driven through the REAL Vue dialog
        // (typed input, real click) so the captured network request is the
        // browser's actual outgoing call — not a hand-written page.request
        // body, which would trivially "pass" without exercising
        // CashDrawerService.js::closeSession() at all.
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsAdmin(page, waveD1User.email, CASH_USER_PASSWORD);
            await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });

            // Session A (previous state) is CLOSED, not OPEN — currentSession
            // only returns OPEN sessions, so the dialog offers a fresh "open"
            // form here, not a collision with A.
            await page.locator('[data-testid="pos-cash-session-open"]').click();
            const openForm = page.locator('[data-testid="cash-session-open-form"]');
            await expect(openForm).toBeVisible({ timeout: 15_000 });

            const openingInput = page.locator('[data-testid="cash-session-opening-input"]');
            await openingInput.fill('100');

            const [openResp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.request().method() === 'POST' && /\/cash-drawer\/sessions\/open$/.test(r.url()),
                    { timeout: 15_000 },
                ),
                page.locator('[data-testid="cash-session-open-submit"]').click(),
            ]);
            expect(openResp.status()).toBe(201);
            const sessionB = (await openResp.json()).data.id;
            waveD1SessionIds.push(sessionB);

            // Add a real cash movement server-side (mirrors
            // CashDrawerCloseVarianceTest's own fixture technique) WITHOUT
            // navigating away — a page.reload() here raced PosComponent's
            // `autoLoadCashSession()` re-mount against the dialog's own
            // open-transition and deadlocked the click on a stuck overlay in
            // an earlier version of this spec. Staying in the same SPA
            // session and using the dialog's own "Voir les mouvements" flow
            // (`openMovements()` — an UNGUARDED refetch, unlike
            // resolveMode()'s once-per-session-id guard) is both simpler and
            // a more faithful cashier workflow: view movements, see the
            // total update, go close.
            expect(page.locator('[data-testid="cash-session-active-view"]')).toBeTruthy();
            recordMovement(sessionB, 40.0);
            await page.locator('[data-testid="cash-session-view-movements"]').click();
            const movementsView = page.locator('[data-testid="cash-session-movements-view"]');
            await expect(movementsView).toBeVisible({ timeout: 10_000 });
            await expect(movementsView.locator('[data-testid="cash-session-movement-row"]')).toHaveCount(1, { timeout: 10_000 });
            await movementsView.getByRole('button', { name: 'Retour' }).click();
            await expect(page.locator('[data-testid="cash-session-active-view"]')).toBeVisible({ timeout: 10_000 });
            await expect(page.locator('[data-testid="cash-session-stat-expected"]')).toContainText('140', { timeout: 15_000 });

            await page.locator('[data-testid="cash-session-go-close"]').click();
            const closeForm = page.locator('[data-testid="cash-session-close-form"]');
            await expect(closeForm).toBeVisible({ timeout: 10_000 });

            // 140,00€ expected, closed at 100,00€ → variance -40,00€, past
            // the 2,00€ threshold → reason field must appear.
            await page.locator('[data-testid="cash-session-closing-input"]').fill('100');
            const reasonInput = page.locator('[data-testid="cash-session-reason-input"]');
            await expect(reasonInput).toBeVisible({ timeout: 10_000 });
            await expect(page.locator('[data-testid="cash-session-close-variance"]')).toContainText('40', { timeout: 10_000 });

            const varianceReasonText = 'E2E verif-globale Wave D1 — écart volontaire pour prouver le transport du network body';
            await reasonInput.fill(varianceReasonText);

            const submitBtn = page.locator('[data-testid="cash-session-close-submit"]');
            await expect(submitBtn).toBeEnabled({ timeout: 5_000 });

            // Capture the ACTUAL outgoing request body for POST /reconcile —
            // this is the literal regression: pre-fix, this body was `{}`
            // regardless of what the cashier typed above.
            let reconcileRequestBody = null;
            const onRequest = (req) => {
                if (req.method() === 'POST' && /\/cash-drawer\/sessions\/\d+\/reconcile$/.test(req.url())) {
                    reconcileRequestBody = req.postDataJSON();
                }
            };
            page.on('request', onRequest);

            const [reconcileResp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.request().method() === 'POST' && /\/cash-drawer\/sessions\/\d+\/reconcile$/.test(r.url()),
                    { timeout: 15_000 },
                ),
                submitBtn.click(),
            ]);
            page.off('request', onRequest);

            expect(reconcileResp.status(), 'reconcile with a typed reason must succeed (Branch Manager holds the override permission)').toBe(200);

            // THE CORE ASSERTION.
            expect(reconcileRequestBody, 'the /reconcile POST must have a JSON body at all — the historical bug sent {}').toBeTruthy();
            expect(reconcileRequestBody.variance_reason, 'variance_reason must be present in the actual network request body').toBe(varianceReasonText);

            // Dialog closes itself on success (submitClose()'s emitClose()) —
            // confirms the UI, not just the network layer, saw success.
            await expect(page.locator('[data-testid="cash-session-overlay"]')).toBeHidden({ timeout: 10_000 });

            await snap('02b-close-with-reason-network-body-carries-variance-reason');

            // Session must be terminal RECONCILED — not stuck OPEN (the
            // literal production symptom this commit fixed) and not stuck
            // CLOSED-but-unreconciled (state 02a's failure mode).
            const row = readSessionRow(sessionB);
            expect(row.status).toBe('reconciled');
            expect(row.variance_reason).toBe(varianceReasonText);
            expect(Number(row.variance)).toBeCloseTo(-40.0, 2);
        } finally {
            dispose();
        }
    });
});
