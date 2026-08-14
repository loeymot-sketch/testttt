// verif-globale-2026-08-14 — Wave A
//
// Adversarial verification of commit ac5ab47f5 (a DIFFERENT concurrent
// session), already deployed + migrated in prod today:
//   database/migrations/2026_08_13_190000_grant_pos_flyer_print_to_cashier.php
// grants POS Operator / Branch Manager a NEW dedicated permission
// `pos-flyer-print`, so the cashier's "Ticket promo" button on the caisse
// tracker (previously 403, Admin-only) now works.
//
// This is an ACCESS-WIDENING change. The core adversarial question this spec
// answers: did widening access for the flyer endpoint leak into anything
// else, or open an abuse vector?
//   — State 05: does `pos-flyer-print` ALSO unlock the generic coupon CRUD
//     (CouponController::store, gated `coupons_create` only)? Must stay 403.
//   — State 08: the role-lock that used to cap abuse was replaced by a
//     per-user DAILY_CAP_PER_USER=40 (PromoFlyerService). Does the cap
//     actually bite for the cashier, and does it correctly NOT apply to
//     Admin (service account) in parallel?
//
// See reports/test-e2e/verif-globale-2026-08-14/AUDIT_PLAN.md §"Wave A" for
// the full numbered-state spec this file implements.
//
// Ground truth verified BEFORE writing this spec (not guessed):
//   - PromoFlyerController constructor: `permission:pos|pos-orders` (ALL
//     actions) then `permission:pos-flyer-print|coupons_create|settings`
//     ->only(['store','reprint','revoke']) (app/Http/Controllers/Admin/PromoFlyerController.php).
//   - CouponController::store gated `permission:coupons_create` ONLY
//     (app/Http/Controllers/Admin/CouponController.php:26) — `pos-flyer-print`
//     does not appear anywhere in that controller.
//   - Live DB check (php artisan tinker): pos@lecayenne.fr (POS Operator) has
//     pos-flyer-print=YES, coupons_create=no, settings=no, pos-orders=YES.
//     chef@lecayenne.fr (Chef) has pos-flyer-print=no, coupons_create=no,
//     settings=no, pos-orders=no, pos=no.
//   - Live curl dry-run confirmed: cashier POST /api/admin/coupon → 403
//     (Spatie UnauthorizedException); cashier POST /api/admin/promo-flyer →
//     201. (The dry-run flyer was revoked immediately after via
//     PromoFlyerService::revoke() so it does not pollute this run's daily
//     cap accounting — state 08 re-reads the live count anyway, see below.)
//   - admin@lecayenne.fr password is TestVisuel2026! (NOT the login.js
//     helper default of 123456) — verified via Hash::check.
//
// Credentials: admin@lecayenne.fr / TestVisuel2026!, pos@lecayenne.fr /
// 123456 (loginAsPosOperator default), chef@lecayenne.fr / 123456
// (loginAsChefOperator default).

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator, loginAsChefOperator, loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/verif-globale-cashier-permission-2026-08-14';
fs.mkdirSync(path.resolve(SCREENSHOT_DIR), { recursive: true });

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || 'TestVisuel2026!';

/**
 * Run a PHP snippet via `artisan tinker --execute`. Same mechanism as
 * tests/e2e/helpers/rate-limit.js / mega-audit-snap.js's cleanupOrphanTestOrders
 * — NEVER `tinker < file` (parses the leading `<?php` and fails), and never
 * prefix a PHP `$var` with a JS backslash inside the template literal (that
 * emits a literal `\$var`, which PHP rejects) — plain `$var` is correct here
 * since PHP `$name` (no immediately-following `{`) is not a JS template
 * interpolation trigger.
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
 * Seed ONE "platform" (Uber Eats style) order in branch 1 so
 * `PosOrdersTrackerComponent.isPlatformOrder()` (checks `source_surface`
 * contains uber/deliveroo/just/platform) is true AND the order lands in the
 * tracker's active bucket (status=PREPARING=7, not a terminal status).
 * Confirmed via a dry-run tinker call before wiring this in (order created
 * and cleanly deleted, no error).
 */
function seedPlatformOrder() {
    const out = tinker(`
        $customer = \\App\\Models\\User::factory()->create(['name' => 'E2E Uber Eats Client', 'branch_id' => 0]);
        $order = \\App\\Models\\Order::factory()->create([
            'branch_id' => 1,
            'user_id' => $customer->id,
            'source_surface' => 'uber_eats',
            'source' => \\App\\Enums\\Source::WEB,
            'order_type' => \\App\\Enums\\OrderType::DELIVERY,
            'status' => \\App\\Enums\\OrderStatus::PREPARING,
            'payment_status' => \\App\\Enums\\PaymentStatus::PAID,
            'order_datetime' => now(),
        ]);
        echo $order->id . '|' . $customer->id;
    `);
    const [orderId, customerId] = String(out).trim().split('|').map((n) => parseInt(n, 10));
    return { orderId, customerId };
}

function deletePlatformOrder(orderId, customerId) {
    if (!orderId) return;
    try {
        tinker(`
            \\App\\Models\\Order::whereKey(${orderId})->delete();
            ${customerId ? `\\App\\Models\\User::whereKey(${customerId})->delete();` : ''}
            echo 'ok';
        `);
    } catch (err) {
        console.warn('[wave-a] deletePlatformOrder cleanup warning:', err?.message || err);
    }
}

/**
 * Reads the LIVE PromoFlyerService::dailyCountForUser() for a given email —
 * used by state 08 so the cap-flood is self-adjusting regardless of how many
 * flyers already exist today for that user (e.g. from state 02 earlier in
 * this same spec run, or leftover dev-DB data), instead of assuming a count
 * of 0 (which failed once already during ground-truth research: a curl
 * dry-run bumped the cashier's count to 1 before this spec existed).
 */
function dailyCountFor(email) {
    const out = tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->first();
        echo app(\\App\\Services\\Promo\\PromoFlyerService::class)->dailyCountForUser((int) $u->id);
    `);
    return parseInt(String(out).trim(), 10) || 0;
}

/**
 * [round-3 fix] `dailyCountFor()` makes state 08's cap-flood self-adjusting,
 * but that means a full spec run legitimately DRIVES the cashier to the real
 * DAILY_CAP_PER_USER (40) for the rest of the calendar day — a genuinely
 * exhausted quota, not a bug. A same-day re-run of this spec then finds
 * state 02 also blocked (429 instead of 201), because the cap is real and
 * doesn't know "this is a different test run". Age today's flyers back by
 * one day before the run starts, exactly the same technique the paired
 * PHPUnit test uses (PromoFlyerCashierAccessTest::test_the_daily_cap_resets_the_next_day
 * — forceFill(['created_at' => now()->subDay()])) — this is what naturally
 * happens tomorrow, not a weakening of the cap assertion itself (state 08
 * still re-reads the LIVE count and floods to the real cap from wherever it
 * starts).
 */
function resetCashierDailyQuotaIfExhausted(email) {
    tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->first();
        \\App\\Models\\PromoFlyer::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)
            ->where('created_by_user_id', $u->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->update(['created_at' => now()->subDay()]);
        echo 'ok';
    `);
}

/** Soft-revoke (disable, keep audit trail — the product's own design, see
 * PromoFlyerController::revoke docblock) every flyer id this spec created,
 * so a full run doesn't leave 40+ live -10% coupons active in the dev DB.
 */
function revokeFlyers(ids) {
    const uniqueIds = Array.from(new Set(ids)).filter((n) => Number.isInteger(n) && n > 0);
    if (uniqueIds.length === 0) return;
    try {
        tinker(`
            $svc = app(\\App\\Services\\Promo\\PromoFlyerService::class);
            $ids = [${uniqueIds.join(',')}];
            $flyers = \\App\\Models\\PromoFlyer::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)->whereIn('id', $ids)->get();
            $n = 0;
            foreach ($flyers as $f) {
                if ($f->coupon_id) { $svc->revoke($f); $n++; }
            }
            echo 'revoked=' . $n;
        `);
    } catch (err) {
        console.warn('[wave-a] revokeFlyers cleanup warning:', err?.message || err);
    }
}

/**
 * Extracts the Sanctum Bearer token + x-api-key this SPA uses, straight from
 * the authenticated page's own runtime state (Vuex `auth.authToken`,
 * persisted to `localStorage.vuex`; `window.foodkingConfig.apiKey`).
 * `page.request` does NOT share axios's in-page interceptor-set headers, so
 * direct API probes (states 05/07/08) need these attached manually — see
 * resources/js/shared/axios-setup.js (readTokenFromVuexLocalStorage) and
 * resources/views/master.blade.php (window.foodkingConfig.apiKey), both
 * read/confirmed live before writing this helper.
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

let platformOrder = { orderId: null, customerId: null };
let state02FlyerId = null;
// [round-2 fix] A static name collides with PromoFlyerService::recentDuplicate()
// (10-minute same-name dedup window) on a re-run of this spec shortly after a
// prior run — the create silently short-circuits to HTTP 200
// `{duplicate:true}` instead of 201. Unique per run so repeated local runs
// stay independent.
const state02CustomerName = `CaissierE2E-WaveA-${Date.now()}`;
const allCreatedFlyerIds = [];

test.describe('Wave A — cashier pos-flyer-print permission widening (ac5ab47f5), adversarial focus', () => {
    // States are sequential by design: 02 creates a flyer that 03/04 act on,
    // and 08's cap-flood should run after 02 so dailyCountFor() sees it.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        clearFoodKingRateLimits();
        resetCashierDailyQuotaIfExhausted('pos@lecayenne.fr');
        platformOrder = seedPlatformOrder();
    });

    test.afterAll(() => {
        revokeFlyers(allCreatedFlyerIds);
        deletePlatformOrder(platformOrder.orderId, platformOrder.customerId);
    });

    test('01-cashier-tracker-flyer-button-visible', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsPosOperator(page);
            // The 🎟️ buttons live on PosOrdersTrackerComponent, mounted at
            // /admin/pos-orders-tracker (routes/modules/posOrderRoutes.js) —
            // a DIFFERENT screen than /admin/pos (the caisse wizard shell
            // loginAsPosOperator lands on by default; confirmed via a live
            // screenshot during spec authoring, the wizard shell has no
            // "Ticket promo" button at all).
            await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });

            // Header button: always visible when canPrintFlyer is true.
            const headerBtn = page.locator('[data-testid="pos-tracker-promo-flyer"]');
            await expect(headerBtn).toBeVisible({ timeout: 15_000 });

            // Per-card button: requires BOTH isPlatformOrder(order) AND
            // canPrintFlyer — proves the gate is evaluated per-card, not just
            // globally. Card may take a poll cycle to appear (tracker refetches
            // periodically) — reload once if not immediately present.
            const cardBtn = page.locator(`[data-testid="tracker-promo-${platformOrder.orderId}"]`);
            if (!(await cardBtn.isVisible({ timeout: 5_000 }).catch(() => false))) {
                await page.reload({ waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(1_500);
            }
            await expect(cardBtn).toBeVisible({ timeout: 15_000 });

            await snap('01-cashier-tracker-flyer-button-visible');
        } finally {
            dispose();
        }
    });

    test('02-cashier-flyer-create-201', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsPosOperator(page);
            await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });

            await page.locator('[data-testid="pos-tracker-promo-flyer"]').click();
            const nameInput = page.locator('#pfq-name');
            await expect(nameInput).toBeVisible({ timeout: 10_000 });
            await nameInput.fill(state02CustomerName);

            const [resp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.request().method() === 'POST' && /\/api\/admin\/promo-flyer$/.test(r.url()),
                    { timeout: 15_000 },
                ),
                page.locator('.pfq-submit').click(),
            ]);

            expect(resp.status(), 'cashier create must be 201, not 403').toBe(201);
            const body = await resp.json();
            expect(body.flyer && body.flyer.id).toBeTruthy();
            state02FlyerId = body.flyer.id;
            allCreatedFlyerIds.push(state02FlyerId);

            // Success state proven in the modal itself (no toast on this
            // surface — see PromoFlyerQuickModal.vue submit(): sets lastCode,
            // does not call alertService). The big code display IS the
            // "success" UI contract for this surface.
            await expect(page.locator('.pfq-code')).toBeVisible({ timeout: 10_000 });
            await expect(page.locator('.pfq-done-label')).toBeVisible();

            await snap('02-cashier-flyer-create-201');
        } finally {
            dispose();
        }
    });

    test('03-cashier-flyer-reprint-200', async ({ page }) => {
        test.skip(!state02FlyerId, 'state 02 must have created a flyer first');
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsPosOperator(page);

            // Reprint only renders for a non-'pending' flyer (the physical
            // caisse-bridge ack loop doesn't run in this test env — simulate
            // it via the SAME acknowledge endpoint the real bridge calls,
            // using the cashier's own session, before touching the UI).
            const headers = await authHeaders(page);
            const ackResp = await page.request.post(`/api/admin/promo-flyer/${state02FlyerId}/ack`, {
                headers,
                data: { success: true },
            });
            expect(ackResp.status()).toBe(200);

            await page.goto('/admin/promo-flyer', { waitUntil: 'domcontentloaded' });
            // `form` alone is ambiguous (Laravel Debugbar injects its own
            // settings form on every page) — scope to the actual create-flyer
            // field this page renders only when canPrintFlyer is true.
            await expect(page.locator('#customer_name')).toBeVisible({ timeout: 15_000 }); // canPrintFlyer form gate open

            const row = page.locator('tr', { hasText: state02CustomerName });
            await expect(row).toBeVisible({ timeout: 15_000 });
            const reprintBtn = row.getByRole('button', { name: 'Reimprimer' });
            await expect(reprintBtn).toBeVisible({ timeout: 10_000 });

            const [resp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.request().method() === 'POST' && /\/promo-flyer\/\d+\/reprint$/.test(r.url()),
                    { timeout: 15_000 },
                ),
                reprintBtn.click(),
            ]);
            expect(resp.status()).toBe(200);

            await snap('03-cashier-flyer-reprint-200');
        } finally {
            dispose();
        }
    });

    test('04-cashier-flyer-revoke-200', async ({ page }) => {
        test.skip(!state02FlyerId, 'state 02 must have created a flyer first');
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsPosOperator(page);
            await page.goto('/admin/promo-flyer', { waitUntil: 'domcontentloaded' });

            const row = page.locator('tr', { hasText: state02CustomerName });
            await expect(row).toBeVisible({ timeout: 15_000 });
            const revokeBtn = row.getByRole('button', { name: 'Annuler' });
            await expect(revokeBtn).toBeVisible({ timeout: 10_000 });

            page.once('dialog', (dialog) => dialog.accept());
            const [resp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.request().method() === 'POST' && /\/promo-flyer\/\d+\/revoke$/.test(r.url()),
                    { timeout: 15_000 },
                ),
                revokeBtn.click(),
            ]);
            expect(resp.status()).toBe(200);

            // Revoked state shows inline next to the code (label.flyer_revoked
            // = "annule", rendered in the `.text-rose-700` badge next to
            // flyer.code — verified exact string via resources/js/languages/fr.json).
            // Also confirms the Reimprimer/Annuler buttons are gone (both are
            // v-if-gated off once flyer.revoked flips true).
            await expect(row.locator('.text-rose-700').first()).toContainText('annule', { ignoreCase: true, timeout: 10_000 });
            await expect(row.getByRole('button', { name: 'Annuler' })).toHaveCount(0);

            await snap('04-cashier-flyer-revoke-200');
        } finally {
            dispose();
        }
    });

    test('05-cashier-coupon-crud-still-403', async ({ page }) => {
        // THE ADVERSARIAL CORE ASSERTION. `pos-flyer-print` must NOT leak into
        // the generic coupon CRUD (CouponController::store, gated
        // `coupons_create` only — confirmed via static read, this proves it
        // holds at runtime for the actual cashier account).
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsPosOperator(page);
            const headers = await authHeaders(page);

            const resp = await page.request.post('/api/admin/coupon', {
                headers,
                data: {
                    code: 'WAVEA-LEAK-CHECK',
                    discount_type: 1,
                    discount: 10,
                    status: 1,
                },
            });

            expect(resp.status(), 'pos-flyer-print must NOT unlock generic coupon CRUD').toBe(403);

            // Belt-and-braces: confirm no coupon row was actually created.
            const dbCheck = tinker(`
                echo \\App\\Models\\Coupon::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)
                    ->where('code', 'WAVEA-LEAK-CHECK')->exists() ? 'EXISTS' : 'ABSENT';
            `);
            expect(String(dbCheck).trim()).toBe('ABSENT');

            await snap('05-cashier-coupon-crud-still-403');
        } finally {
            dispose();
        }
    });

    test('06-chef-flyer-button-hidden', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsChefOperator(page);

            // Chef has NEITHER `pos-orders` (route-level gate on
            // /admin/promo-flyer) NOR `pos-flyer-print` (component-level
            // v-if). Either mechanism firing proves DOM absence; assert both
            // angles so a future change to either gate doesn't silently
            // regress past this test.
            await page.goto('/admin/promo-flyer', { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(1_500);

            const onFlyerPage = /\/admin\/promo-flyer(\/|$|\?)/.test(page.url());
            if (onFlyerPage) {
                // If somehow route-reachable, the create form itself must still
                // be gated off by canPrintFlyer's v-if. Scoped selector
                // (not bare `form`) — Laravel Debugbar always injects its own
                // settings `<form>`, which would false-negative a bare count.
                await expect(page.locator('#customer_name')).toHaveCount(0);
            } else {
                // Router-level permission denial redirected Chef away (expected
                // per resources/js/router/index.js handlePermissionDenied) —
                // DOM absence is total, the component never mounted.
                expect(page.url()).not.toMatch(/\/admin\/promo-flyer/);
            }

            // Defensive, surface-agnostic: no flyer button/text anywhere in DOM.
            await expect(page.locator('[data-testid="pos-tracker-promo-flyer"]')).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Ticket promo' })).toHaveCount(0);

            await snap('06-chef-flyer-button-hidden');
        } finally {
            dispose();
        }
    });

    test('07-chef-flyer-direct-api-403', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsChefOperator(page);
            const headers = await authHeaders(page);

            const resp = await page.request.post('/api/admin/promo-flyer', {
                headers,
                data: { customer_name: 'ChefBypassAttempt' },
            });

            expect(resp.status(), 'Chef bypassing the v-if via direct API must get a real 403, not silent 500/200').toBe(403);

            const dbCheck = tinker(`
                echo \\App\\Models\\PromoFlyer::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)
                    ->where('customer_name', 'ChefBypassAttempt')->exists() ? 'EXISTS' : 'ABSENT';
            `);
            expect(String(dbCheck).trim()).toBe('ABSENT');

            await snap('07-chef-flyer-direct-api-403');
        } finally {
            dispose();
        }
    });

    test('08-daily-cap-429', async ({ page, browser }) => {
        // THE OTHER ADVERSARIAL CORE ASSERTION. The role-lock that used to cap
        // abuse (coupons_create, Admin-only) was replaced by
        // PromoFlyerService::DAILY_CAP_PER_USER=40. Prove: (a) the cap
        // actually bites for the cashier at the 41st create of the day, with
        // the documented 429 message, and (b) an Admin session created IN
        // PARALLEL is NOT subject to that same cap — the exemption is a real
        // cross-role behavior, not just unit-tested logic.
        test.setTimeout(90_000);
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            clearFoodKingRateLimits();
            await loginAsPosOperator(page);
            const headers = await authHeaders(page);

            const DAILY_CAP = 40;
            const alreadyToday = dailyCountFor('pos@lecayenne.fr');
            const toCreate = Math.max(0, DAILY_CAP - alreadyToday);
            const stamp = Date.now();

            for (let i = 0; i < toCreate; i++) {
                const resp = await page.request.post('/api/admin/promo-flyer', {
                    headers,
                    data: { customer_name: `CapE2E${i}-${stamp}` },
                });
                expect(resp.status(), `cap-flood create #${i + 1}/${toCreate} should succeed pre-cap`).toBe(201);
                const body = await resp.json();
                if (body.flyer && body.flyer.id) allCreatedFlyerIds.push(body.flyer.id);
            }

            // Cashier is now AT the cap — the next create must 429.
            const capResp = await page.request.post('/api/admin/promo-flyer', {
                headers,
                data: { customer_name: `CapE2E-over-${stamp}` },
            });
            expect(capResp.status(), 'cashier must be blocked once DAILY_CAP_PER_USER is reached').toBe(429);
            const capBody = await capResp.json();
            expect(capBody.message).toMatch(/[Ll]imite/);

            // No row created for the blocked attempt.
            const dbCheck = tinker(`
                echo \\App\\Models\\PromoFlyer::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)
                    ->where('customer_name', 'CapE2E-over-${stamp}')->exists() ? 'EXISTS' : 'ABSENT';
            `);
            expect(String(dbCheck).trim()).toBe('ABSENT');

            // Admin session, IN PARALLEL (separate browser context, cashier's
            // 429 above is still the most recent fact) — must NOT be capped.
            const adminContext = await browser.newContext();
            const adminPage = await adminContext.newPage();
            try {
                await loginAsAdmin(adminPage, ADMIN_EMAIL, ADMIN_PASS);
                const adminHeaders = await authHeaders(adminPage);
                const adminResp = await adminPage.request.post('/api/admin/promo-flyer', {
                    headers: adminHeaders,
                    data: { customer_name: `AdminNotCapped-${stamp}` },
                });
                expect(adminResp.status(), 'Admin (service account) must never be subject to the cashier daily cap').toBe(201);
                const adminBody = await adminResp.json();
                if (adminBody.flyer && adminBody.flyer.id) allCreatedFlyerIds.push(adminBody.flyer.id);
            } finally {
                await adminContext.close();
            }

            await snap('08-daily-cap-429');
        } finally {
            dispose();
        }
    });
});

// [bonus, mirrors PHPUnit test_branch_manager_can_create_a_promo_flyer]
// AUDIT_PLAN.md §Wave A "Contexts" names Branch Manager as a 3rd context
// alongside cashier/Chef, though it's not one of the 8 numbered adversarial
// states. No seeded Branch Manager fixture has a known E2E password (the
// live account found via tinker, bm.t2admin@lecayenne.fr, is a real prod-ish
// account, not an E2E fixture with a documented credential) — a login-driven
// UI test here would be guessing a password, which is exactly the kind of
// unverified assumption CLAUDE.md §3ter forbids. This checks the SAME fact
// (the migration actually granted the role, not just the two named users)
// via a DB-level read instead, no browser/login needed.
test.describe('Wave A bonus — Branch Manager role also carries pos-flyer-print (migration ROLES list)', () => {
    test('bonus-branch-manager-role-has-permission', async () => {
        const out = tinker(`
            $role = \\Spatie\\Permission\\Models\\Role::where('name', 'Branch Manager')->where('guard_name', 'sanctum')->first();
            echo $role && $role->hasPermissionTo('pos-flyer-print') ? 'YES' : 'NO';
        `).trim();
        expect(out).toBe('YES');
    });
});
