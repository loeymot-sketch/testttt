// test-e2e-goal-4chantiers-wave-E.spec.js — GOAL 4 chantiers (2026-08-16), Wave E
//
// Stock intelligence: dashboard widget (StockLowAlertsWidget.vue, mounted onto
// DashboardComponent.vue by commit 4b7574598) + POS toolbar badge
// (PosComponent.vue `lowStockCount` / `data-testid="pos-low-stock-open"`).
// Both read the SAME endpoint: GET admin/stock/low-alerts
// (StockRuptureDashboardController::lowAlerts(), SSOT stock_levels
// on_hand/threshold_low) — the PosComponent.vue code comment at ~L4072-4078
// calls this out explicitly as "même endpoint, même sémantique... SSOT".
//
// See reports/test-e2e/goal-4chantiers-2026-08-16/AUDIT_PLAN.md §"Wave E" for
// the full numbered-state spec this file implements, and the "Cross-surface
// scenarios" section for SYNC-STOCK-1.
//
// Ground truth verified BEFORE writing this spec (not guessed):
//   - pos@lecayenne.fr (POS Operator, id=3) has permission `dashboard`=YES,
//     `items`=NO, `items_show`=NO (php artisan tinker, live DB).
//   - StockRuptureDashboardController::__construct() gates lowAlerts() behind
//     `permission:items_show` — POS Operator lacking it means a REAL 403 from
//     Spatie's permission middleware, not a guess.
//   - [test-e2e fix A-002/E-001/E-002/E-006 round-1 2026-08-16] Two findings,
//     now BOTH fixed in this pass:
//     (a) PosComponent.vue's loadLowStockCount() had NO client-side gate at
//         all — it fired GET admin/stock/low-alerts unconditionally on every
//         poll tick, producing a REAL 403 forever for a role like POS
//         Operator. Now gated by canFetchLowStockAlerts(), mirroring the
//         widget's pattern. State 01 below proves ZERO requests fire.
//     (b) StockLowAlertsWidget.vue's OWN pre-existing client-side gate
//         (canFetchAlerts(), mounted() iter15-mega-fix D-005) checked the
//         WRONG permission slug — the `items` entry (url:'items') — while
//         the real backend gate on lowAlerts() is `items_show`
//         (url:'items/show'). For POS Operator both happen to be NO, so the
//         widget's gate produced the same (correct) client-side skip by
//         coincidence — but a role granted items_show without items would
//         have gotten a false-negative (widget hidden despite having
//         access). Both canFetchAlerts() and the new
//         canFetchLowStockAlerts() now check the SAME correct slug
//         (url==='items/show').
//   - bootstrap.js's global axios error interceptor: `admin/stock/low-alerts`
//     matches neither TOAST_STATUSES ({408,425,429,502,503,504}) nor
//     _CRITICAL_4XX_PATTERNS (['/api/admin/item','/api/admin/fiscal/']) — a
//     403 on this endpoint is silently rejected (no toast, no console.warn)
//     by design, confirmed by reading the interceptor source, not assumed.
//   - PosComponent.vue's `_startKioskPolling()` tick() calls
//     `this.loadLowStockCount()` on EVERY tick (same tick as the tracker/web-
//     order polls). `_kioskPollingInterval()` returns 5000ms when
//     `window._wsService?.isConnected()` is false — confirmed no process
//     listens on MIX_PUSHER_PORT=6001 in this dev env, so polling runs at the
//     5s (not 60s) cadence, making a ">=2 poll cycles" wait cheap (~11s).
//   - stock_levels has exactly 3 rows in this dev DB (branch_id=1, all
//     threshold_low IS NULL) — current low-alerts count is 0. Row id=17
//     (App\Models\Item #52, "Coca-Cola 33cl", category "Boissons" id=10,
//     on_hand=19) is the smallest on_hand of the 3 and is used as the seed
//     target — see seedLowStockRow() below. Mutation touches ONLY
//     threshold_low (NULL -> on_hand+6); on_hand (real inventory count) is
//     never touched, matching the plan's "smaller, more reversible mutation"
//     instruction.
//   - admin@lecayenne.fr / 123456 (loginAsAdmin default) verified via
//     Hash::check — works. Role Admin, has `pos`/`items`/`items_show` = YES.
//   - `/admin/stock/rupture` (route admin.stock.rupture) mounts
//     StockRuptureDashboardComponent.vue — a DIFFERENT subsystem (binary
//     item/extra/variation AVAILABILITY toggle, backed by
//     `catalog-overview`), not the on_hand/threshold_low low-stock concept.
//     grep confirmed ZERO references to `threshold_low`/`on_hand` in that
//     component. State 05 below therefore proves item-IDENTITY reachability
//     (the SAME seeded item is deep-linkable from the badge's destination),
//     not a THIRD numeric on_hand/threshold_low readout — that field does
//     not exist on that page by design. SYNC-STOCK-1's actual numeric claim
//     (widget count == badge count, both against the SAME SSOT endpoint) is
//     verified directly against the live API response body in states 03/04.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { loginAsPosOperator, loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/test-e2e-goal-4chantiers-wave-E';

/**
 * Run a PHP snippet via `artisan tinker --execute`. Same mechanism as the
 * other verif-globale-2026-08-14 specs — never `tinker < file`, never a JS
 * backslash before a PHP `$var` inside the template literal.
 */
function tinker(phpCode) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', phpCode], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
    });
}

/** Live re-read of the exact same predicate StockRuptureDashboardController::lowAlerts()
 * uses (whereNotNull threshold_low, on_hand<=threshold_low), GLOBAL (all branches) —
 * used as the "pre-seed baseline" / "post-revert baseline" ground truth this
 * wave's own regression check compares against. Not scoped to branch_id=1 on
 * purpose: the live endpoint itself scopes by the requesting user's branch,
 * but this readback is the DB-level source of truth independent of that.
 */
function currentLowAlertsCount() {
    const out = tinker(`
        echo \\App\\Models\\StockLevel::whereNotNull('threshold_low')
            ->whereColumn('on_hand', '<=', 'threshold_low')->count();
    `);
    return parseInt(String(out).trim(), 10);
}

/**
 * Picks the branch_id=1 stock_levels row with the smallest on_hand (the
 * existing Coca-Cola 33cl row, id=17, on_hand=19, threshold_low=NULL at spec
 * authoring time — re-derived live here rather than hardcoded so a future
 * dev-DB reseed doesn't silently break this spec) and raises threshold_low
 * from NULL to on_hand+6. on_hand (real inventory) is NEVER touched — this
 * is the smaller, more reversible half of the two options the plan allows.
 * Records the EXACT original value (NULL in the observed case) for revert.
 */
function seedLowStockRow() {
    const out = tinker(`
        $row = \\App\\Models\\StockLevel::where('branch_id', 1)->orderBy('on_hand', 'asc')->first();
        if (!$row) { echo 'NONE'; exit; }
        $orig = $row->threshold_low;
        $seeded = (int) $row->on_hand + 6;
        $row->threshold_low = $seeded;
        $row->save();
        $item = \\App\\Models\\Item::find($row->stockable_id);
        echo implode('|', [
            $row->id,
            $row->stockable_type,
            $row->stockable_id,
            $row->branch_id,
            $row->on_hand,
            $orig === null ? 'NULL' : $orig,
            $seeded,
            $item ? $item->item_category_id : 0,
            $item ? $item->name : ('#' . $row->stockable_id),
        ]);
    `);
    const parts = String(out).trim().split('|');
    if (parts[0] === 'NONE') {
        throw new Error('[wave-e] No branch_id=1 stock_levels row found to seed — cannot prove the populated state.');
    }
    return {
        id: parseInt(parts[0], 10),
        stockableType: parts[1],
        stockableId: parseInt(parts[2], 10),
        branchId: parseInt(parts[3], 10),
        onHand: parseInt(parts[4], 10),
        originalThresholdLow: parts[5] === 'NULL' ? null : parseInt(parts[5], 10),
        seededThresholdLow: parseInt(parts[6], 10),
        categoryId: parseInt(parts[7], 10),
        itemName: parts.slice(8).join('|'),
        baselineCount: null,
        afterSeedCount: null,
        reverted: false,
    };
}

/** Reverts stock_levels.threshold_low to its EXACT recorded original value
 * (NULL-safe) and reads the row straight back from the DB (not assumed) to
 * confirm the write actually landed as intended. Returns the post-revert
 * value as a string ('NULL' or the numeric threshold) for direct comparison.
 */
function revertLowStockRow(seed) {
    const thresholdExpr = seed.originalThresholdLow === null ? 'null' : String(seed.originalThresholdLow);
    const out = tinker(`
        $row = \\App\\Models\\StockLevel::find(${seed.id});
        $row->threshold_low = ${thresholdExpr};
        $row->save();
        $fresh = \\App\\Models\\StockLevel::find(${seed.id});
        echo $fresh->threshold_low === null ? 'NULL' : $fresh->threshold_low;
    `);
    return String(out).trim();
}

let seed = null;

// [round-1 heal, discovered mid-run] Chrome's DevTools console logs a native
// "Failed to load resource: the server responded with a status of 403
// (Forbidden)" line for EVERY failed XHR/fetch, independent of any app-level
// try/catch — it is not something PosComponent.vue / bootstrap.js's axios
// interceptor can suppress, and it is universal across the whole app (any
// 4xx/5xx response triggers it), not specific to this endpoint. The genuine
// "no spam" signal is therefore NOT "zero console errors ever" (impossible
// given this browser behavior) but "exactly ONE such line per completed poll
// — no duplication/retry-storm — and ZERO application-level (toast/warn)
// errors beyond it".
const CHROME_NATIVE_FAILED_RESOURCE_RE = /Failed to load resource.*(403|Forbidden)/i;

// Same benign-noise patterns mega-audit-snap.js's OWN recorder already
// filters out of its console.json artifact (no Reverb/Soketi broadcast
// server running in this dev/test env — Echo retries, app polls as
// fallback; documented there as informational, not a real failure). My
// separate `rawConsole` listener below needs the identical filter since it
// persists across snap() calls (snap() resets the recorder's own buffer,
// which is why this file needs its own copy for the cross-cycle comparison).
// [round-1 heal, discovered mid-run] PosComponent.vue's polling tick ALSO
// health-checks the local printer bridges (127.0.0.1:9100 caisse,
// 127.0.0.1:9101 cuisine — strictly per-station, per project memory) and a
// language-flag image; both legitimately net::ERR_CONNECTION_REFUSED /
// net::ERR_BLOCKED_BY_ORB in this headless dev/test env where no physical
// bridge process runs. Confirmed via a `requestfailed` listener during
// authoring (URLs: http://127.0.0.1:9100/health, .../9101/health,
// http://.../storage/1/english.png) — completely unrelated to
// admin/stock/low-alerts. Safe to filter here specifically because the
// low-alerts call itself is independently verified via `page.waitForResponse`
// + a real HTTP status assertion above (a genuine connection-refused on THAT
// call would time out waitForResponse and fail the test long before reaching
// this console-error check, not hide behind this filter).
const KNOWN_NOISE_PATTERNS = [
    /WebSocket connection to '(ws|wss):\/\/[^']*' failed/i,
    /^Pusher\s*:\s*/i,
    /net::ERR_CONNECTION_REFUSED/i,
    /net::ERR_BLOCKED_BY_ORB/i,
];
function isKnownNoise(text) {
    return KNOWN_NOISE_PATTERNS.some((rx) => rx.test(String(text || '')));
}

// [round-2 heal, E-004/E-005] Round-1 captures of 04-pos-badge-visible-admin
// and 06-post-cleanup-verify were taken MID CSS fade-out of the
// vue-element-loading overlay (class="velmld-full-screen velmld-overlay
// fade-leave-active fade-leave-to" still in the DOM at snap() time) — a
// washed-out, low-opacity screenshot. Confirmed by source, not assumed:
// resources/js/components/admin/components/LoadingComponent.vue wraps
// `<VueElementLoading :active="props.isActive" :is-full-screen="true"/>`
// (vue-element-loading package, package.json "vue-element-loading": "^3.0.1"),
// PosComponent.vue mounts it via `<LoadingComponent :props="loading" />`
// (L73) and toggles `this.loading.isActive` around every async load.
//
// [round-2 correction, verified against the actual compiled component before
// assuming] The overlay div is NOT v-if'd out on hide — the library's own
// compiled render function (public/js/vendor.js ~L5480-5502) wraps it in a
// Vue <Transition name="fade"> (0.3s opacity, CSS at ~L4912) whose child uses
// the `vShow` directive (`[vShow, isActive || isActiveDelay]`), not v-if. So
// the node NEVER detaches from the DOM — Vue's transition machinery plays the
// 0.3s fade then sets `style.display:none` on transition-end, and it stays
// there permanently. Confirmed directly in a captured DOM snapshot:
// `style="background-color: rgba(255,255,255,.9); display: none;"` with no
// fade-* classes left. `state:'detached'` can therefore never resolve (it
// would silently eat the full timeout every call); `state:'hidden'` is the
// correct target — Playwright's hidden check is satisfied once display:none
// lands, which only happens after the CSS transition has fully finished.
async function waitForLoadingOverlayDetached(page) {
    await page.waitForSelector('.velmld-overlay', { state: 'hidden', timeout: 5_000 }).catch(() => {});
}

test.describe('Wave E — Stock intelligence: dashboard widget + POS badge (empty vs populated)', () => {
    // 03 seeds a DB row that 04/05 depend on and 06 must revert — order matters.
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        clearFoodKingRateLimits();
    });

    // Safety net ONLY: test 06 is the real, verified revert (in-wave, asserted
    // against a live DB readback). This afterAll exists solely so a mid-spec
    // crash (e.g. test 04/05 throwing) still attempts the revert instead of
    // leaving a dangling false low-stock row for other waves/the owner.
    test.afterAll(() => {
        if (seed && !seed.reverted) {
            try {
                const reverted = revertLowStockRow(seed);
                seed.reverted = true;
                // eslint-disable-next-line no-console
                console.warn(`[wave-e] afterAll safety-net revert ran (test 06 did not run/complete) — threshold_low back to ${reverted}.`);
            } catch (err) {
                // eslint-disable-next-line no-console
                console.warn('[wave-e] afterAll safety-net revert FAILED — manual DB check needed for stock_levels id='
                    + seed.id + ':', err?.message || err);
            }
        }
    });

    // ── E1 — POS Operator (no items_show permission) ───────────────────────

    // [test-e2e fix A-002/E-001/E-002/E-006 round-1 2026-08-16] This test used
    // to PROVE (and tolerate) a REAL 403-per-poll-tick forever for POS
    // Operator — waitForResponse()'d two consecutive 403s and asserted the
    // native "Failed to load resource" console line grew 1:1 (0→1→2) as
    // "acceptable" spam. That was the exact bug the adversarial supervisor
    // flagged (A-002/E-001/E-002): PosComponent.vue's loadLowStockCount()
    // fired GET admin/stock/low-alerts unconditionally, regardless of
    // permission. The real fix (canFetchLowStockAlerts() gate mirroring
    // StockLowAlertsWidget.vue's canFetchAlerts(), corrected to the true
    // backend slug `items_show`/'items/show' — E-006) means the request is
    // no longer fired AT ALL for this role. So this test now asserts ZERO
    // occurrences across multiple poll cycles, not 1:1 growth.
    test('01-pos-badge-hidden-operator', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        const rawConsole = [];
        const onConsole = (msg) => {
            const text = msg.text();
            if (isKnownNoise(text)) return; // WS-connect-failed / Pusher retry chatter — same filter as mega-audit-snap.js
            rawConsole.push({ type: msg.type(), text });
        };
        page.on('console', onConsole);
        let lowAlertsHit = false;
        const reqWatcher = (r) => { if (r.url().includes('/admin/stock/low-alerts')) lowAlertsHit = true; };
        page.on('request', reqWatcher);
        try {
            await loginAsPosOperator(page);

            // No waitForResponse() here on purpose — there is nothing to wait
            // FOR anymore. Instead, sit through >=2 full poll cycles worth of
            // wall-clock time (5s cadence per file header, WS disconnected in
            // this dev env) and prove the endpoint was NEVER hit.
            await page.waitForTimeout(11_000);

            expect(lowAlertsHit, 'POS Operator lacks items_show — the gated loadLowStockCount() must never fire GET admin/stock/low-alerts at all, across >=2 poll cycles (round-1 fix removes the request entirely, it does not just tolerate its 403)').toBe(false);

            await expect(page.locator('[data-testid="pos-low-stock-open"]'), 'badge must be absent (gate keeps lowStockCount at 0, request never fires)').toHaveCount(0);

            const bodyText1 = await page.locator('body').innerText();
            expect(bodyText1).not.toMatch(/\b403\b/);
            expect(bodyText1.toLowerCase()).not.toMatch(/forbidden|unauthoriz/);
            await expect(page.locator('.Vue-Toastification__toast'), 'no toast').toHaveCount(0);

            await snap('01a-pos-badge-hidden-cycle1');

            const errs = rawConsole.filter((m) => m.type === 'error');
            const nativeLines = errs.filter((m) => CHROME_NATIVE_FAILED_RESOURCE_RE.test(m.text));
            const otherErrs = errs.filter((m) => !CHROME_NATIVE_FAILED_RESOURCE_RE.test(m.text));
            expect(otherErrs.length, 'no application-level (toast/warn) console errors').toBe(0);
            expect(nativeLines.length, 'ZERO browser-native "failed to load resource" 403 lines — the request that produced them is gone entirely, not just tolerated at a 1:1 growth rate (round-1 fix)').toBe(0);

            await snap('01b-pos-badge-hidden-cycle2');
        } finally {
            page.off('console', onConsole);
            page.off('request', reqWatcher);
            dispose();
        }
    });

    test('02-dashboard-widget-empty-operator', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsPosOperator(page);

            let lowAlertsHitFromDashboard = false;
            const respWatcher = (r) => { if (r.url().includes('/admin/stock/low-alerts')) lowAlertsHitFromDashboard = true; };
            page.on('response', respWatcher);

            await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
            // POS Operator DOES have the `dashboard` route permission (confirmed
            // live) — this is NOT the N/A case, the route is genuinely reachable.
            await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 15_000 });
            await page.waitForTimeout(3_000); // canFetchAlerts() runs synchronously in mounted() — generous settle window

            page.off('response', respWatcher);

            // StockLowAlertsWidget.vue's canFetchAlerts() gate (items_show
            // perm, access:false for this role — corrected slug, round-1 fix
            // E-006) must skip the fetch client-side entirely — never even
            // attempt the call that E1-01 proves is ALSO gated out on the POS
            // toolbar badge side.
            expect(lowAlertsHitFromDashboard, 'dashboard widget must not fire admin/stock/low-alerts for a role gated out client-side').toBe(false);

            await expect(page.locator('[data-testid="stock-low-alerts-error"]'), 'must NOT show the error banner').toHaveCount(0);
            await expect(page.locator('[data-testid="stock-low-alerts-count"]'), 'must NOT show a count badge').toHaveCount(0);
            // [test-e2e fix E-003 round-1 2026-08-16] label.no_low_alerts
            // lengthened from "Aucune alerte" (13 chars, under the 20-char
            // adequacy threshold) to a full sentence — substring match, not
            // exact, so this assertion doesn't pin the exact copy.
            const emptyText = page.getByText('Aucune alerte de stock bas', { exact: false });
            await expect(emptyText, 'clean empty state text (label.no_low_alerts)').toBeVisible({ timeout: 10_000 });

            // Scroll the widget into frame so the captured screenshot actually
            // SHOWS the state under test (CLAUDE.md §6 visual mandate) rather
            // than relying solely on off-screen locator assertions.
            await emptyText.scrollIntoViewIfNeeded();
            await snap('02-dashboard-widget-empty-operator');
        } finally {
            dispose();
        }
    });

    // ── E2 — Admin (populated-state proof + cross-surface consistency) ─────

    test('03-dashboard-widget-populated-admin', async ({ page }) => {
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            const baselineCount = currentLowAlertsCount();
            seed = seedLowStockRow();
            seed.baselineCount = baselineCount;
            expect(seed.onHand, 'seed mutation must actually qualify (on_hand <= seeded threshold_low)').toBeLessThanOrEqual(seed.seededThresholdLow);

            const afterSeedCount = currentLowAlertsCount();
            expect(afterSeedCount, 'seeding must add exactly ONE new qualifying row, no side effects on other rows').toBe(baselineCount + 1);
            seed.afterSeedCount = afterSeedCount;

            // [round-1 heal] loginAsAdmin()'s OWN natural post-login landing is
            // /admin/dashboard, which ALSO mounts the widget and fires this exact
            // endpoint — registering the listener before login would capture THAT
            // early response, then our own `page.goto` below (a hard navigation,
            // not an SPA route change) tears down its document before we can read
            // the body (`Network.getResponseBody: No resource with given
            // identifier found`). Register AFTER login settles, right before the
            // explicit reload we actually want to observe.
            await loginAsAdmin(page);
            const respPromise = page.waitForResponse(
                (r) => r.url().includes('/admin/stock/low-alerts') && r.request().method() === 'GET',
                { timeout: 20_000 },
            );
            await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
            const resp = await respPromise;
            expect(resp.status()).toBe(200);
            const body = await resp.json();
            expect(Array.isArray(body.alerts)).toBe(true);
            expect(body.alerts.length, 'live API alerts.length must match the live DB low-stock count (SSOT)').toBe(afterSeedCount);

            const countBadge = page.locator('[data-testid="stock-low-alerts-count"]');
            await expect(countBadge, 'count badge next to the widget title, showing the TRUE (uncapped) count').toBeVisible({ timeout: 15_000 });
            await expect(countBadge).toHaveText(String(afterSeedCount));

            const row = page.locator(`[data-testid="stock-low-alert-${seed.stockableId}"]`);
            await expect(row, 'seeded row visible in the (<=5-row-capped) table').toBeVisible({ timeout: 10_000 });
            await expect(row).toContainText(`${seed.onHand} / ${seed.seededThresholdLow}`);
            await expect(row).toContainText(seed.itemName);

            // Badge count == table row count here is expected (afterSeedCount<=5,
            // no cap divergence to observe in THIS dev DB) — by-design divergence
            // (badge=true count, table capped at 5) would only manifest with >5
            // qualifying rows, not reproduced here; not a bug either way.
            await expect(page.locator('[data-testid="stock-low-alerts-error"]'), 'no error banner despite a real populated fetch').toHaveCount(0);

            // Scroll the widget into frame for real visual evidence (CLAUDE.md §6).
            await row.scrollIntoViewIfNeeded();
            await snap('03-dashboard-widget-populated-admin');
        } finally {
            dispose();
        }
    });

    test('04-pos-badge-visible-admin', async ({ page }) => {
        test.skip(!seed, 'seed step (03) must run first');
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            // Same registration-ordering fix as state 03 — register AFTER
            // login's own natural /admin/dashboard landing settles.
            await loginAsAdmin(page);
            const respPromise = page.waitForResponse(
                (r) => r.url().includes('/admin/stock/low-alerts'),
                { timeout: 20_000 },
            );
            await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
            const resp = await respPromise;
            expect(resp.status()).toBe(200);
            const body = await resp.json();
            const posCount = body.alerts.length;

            // SYNC-STOCK-1 core numeric assertion: the POS surface's own live
            // read of the SAME SSOT endpoint must equal what the dashboard
            // widget saw in state 03 — a P0 per REVIEWER_PROTOCOL if these ever
            // diverge (both surfaces read the identical unscoped-by-UI endpoint).
            expect(posCount, 'POS badge live count must equal the dashboard widget live count (same SSOT endpoint)').toBe(seed.afterSeedCount);

            const badge = page.locator('[data-testid="pos-low-stock-open"]');
            await expect(badge, '"Stock faible" badge visible in the POS header toolbar').toBeVisible({ timeout: 15_000 });
            const badgeText = (await badge.locator('.pos-v5-btn__badge').innerText()).trim();
            expect(badgeText).toBe(String(posCount));
            expect(badgeText.toLowerCase()).not.toMatch(/null|undefined/);

            // [round-2 heal E-004] wait out the loading overlay's fade-out
            // before persisting the "final rendered state" screenshot.
            await waitForLoadingOverlayDetached(page);
            await snap('04-pos-badge-visible-admin');
        } finally {
            dispose();
        }
    });

    test('05-badge-click-navigates', async ({ page }) => {
        test.skip(!seed, 'seed step (03) must run first');
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            await loginAsAdmin(page);
            await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });

            const badge = page.locator('[data-testid="pos-low-stock-open"]');
            await expect(badge).toBeVisible({ timeout: 15_000 });
            await badge.click();

            // Router-link target: { name: 'admin.stock.rupture' } -> /admin/stock/rupture.
            await expect(page).toHaveURL(/\/admin\/stock\/rupture/, { timeout: 15_000 });

            // NOTE (verified via grep, not assumed): StockRuptureDashboardComponent.vue
            // (this destination) is the "Gestion Produits & Stock" AVAILABILITY-TOGGLE
            // catalog browser (catalog-overview endpoint) — it carries ZERO
            // on_hand/threshold_low fields, a DIFFERENT subsystem than the
            // low-alerts widget/badge. So this state proves IDENTITY consistency
            // (the badge deep-links to a page where the SAME seeded item is
            // findable), not a third numeric on_hand/threshold_low readout —
            // that field does not exist on this page by design, not a bug.
            const bucketBtn = page.locator(`[data-testid="stock-mgmt-bucket-cat-${seed.categoryId}"]`);
            await expect(bucketBtn, `category bucket for the seeded item's category (id=${seed.categoryId})`).toBeVisible({ timeout: 15_000 });
            await bucketBtn.click();

            const productCard = page.locator(`[data-testid="stock-mgmt-product-item-${seed.stockableId}"]`);
            await expect(productCard, 'the SAME seeded item (identity match) is reachable from the badge destination').toBeVisible({ timeout: 15_000 });
            await expect(productCard).toContainText(seed.itemName);

            await snap('05-badge-click-navigates');
        } finally {
            dispose();
        }
    });

    // ── E2 teardown (mandatory, in-wave — not deferred) ─────────────────────

    test('06-post-cleanup-verify', async ({ page }) => {
        test.skip(!seed, 'seed step (03) must run first');
        const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
        try {
            const revertedValue = revertLowStockRow(seed);
            const expectedValue = seed.originalThresholdLow === null ? 'NULL' : String(seed.originalThresholdLow);
            expect(revertedValue, 'threshold_low must revert to its EXACT original recorded value, read back from the DB (not assumed)').toBe(expectedValue);

            const postRevertCount = currentLowAlertsCount();
            expect(postRevertCount, 'live low-alerts count must return to the pre-seed baseline').toBe(seed.baselineCount);
            seed.reverted = true;

            // Same registration-ordering fix as states 03/04 — register AFTER
            // login's own natural /admin/dashboard landing settles.
            await loginAsAdmin(page);
            const dashRespPromise = page.waitForResponse(
                (r) => r.url().includes('/admin/stock/low-alerts'),
                { timeout: 20_000 },
            );
            await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
            const dashResp = await dashRespPromise;
            const dashBody = await dashResp.json();
            expect(dashBody.alerts.length, 'dashboard widget live read must also be back at baseline').toBe(seed.baselineCount);
            await expect(page.locator(`[data-testid="stock-low-alert-${seed.stockableId}"]`), 'seeded row no longer present').toHaveCount(0);
            if (seed.baselineCount === 0) {
                await expect(page.locator('[data-testid="stock-low-alerts-count"]')).toHaveCount(0);
                // [test-e2e fix E-003 round-1 2026-08-16] substring match — see 02-dashboard-widget-empty-operator comment.
                const emptyTextAfterRevert = page.getByText('Aucune alerte de stock bas', { exact: false });
                await expect(emptyTextAfterRevert).toBeVisible({ timeout: 10_000 });
                await emptyTextAfterRevert.scrollIntoViewIfNeeded();
            }
            await snap('06a-dashboard-back-to-baseline');

            const posRespPromise = page.waitForResponse(
                (r) => r.url().includes('/admin/stock/low-alerts'),
                { timeout: 20_000 },
            );
            await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
            const posResp = await posRespPromise;
            const posBody = await posResp.json();
            expect(posBody.alerts.length, 'POS badge live read must also be back at baseline').toBe(seed.baselineCount);
            if (seed.baselineCount === 0) {
                await expect(page.locator('[data-testid="pos-low-stock-open"]')).toHaveCount(0);
            }

            // [round-2 heal E-005] same overlay-detach wait as state 04 —
            // this snap is the "teardown verified" evidence, it must show the
            // settled, fully-rendered final state, not a mid-fade frame.
            await waitForLoadingOverlayDetached(page);
            await snap('06-post-cleanup-verify');
        } finally {
            dispose();
        }
    });
});
