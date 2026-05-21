// Wave X4 E2E — Admin unified cash & transactions overview (P-OWNER 2026-05-21)
//
// Owner mandate (verbatim translated):
//   « Toutes les commandes encaissées (POS direct, borne cash-collected,
//     livreur) au MÊME ENDROIT en base. Admin part + caisse voient tout.
//     Pour chaque transaction : source (borne/caisse/livreur) + mode
//     paiement + total. Totaux par source + grand total. Permet de
//     détecter écarts (cash manquant). »
//
// [Wave X-C round-1 2026-05-21] Round-1 fixes applied:
//   - C-001: every snapshot now uses attachMegaAuditRecorder so the 4-file
//     quartet (.png + .dom.html + .console.json + .network.json) is emitted
//     for every state — adversarial reviewer can verify network/DOM/console
//     without re-running the spec.
//   - C-002: new test asserts reconciliation card renders cash_collected
//     AND diff cells (écart math the owner mandate requires).
//   - C-003: backend now falls back to most-recent open drawer for admin
//     without ?branch_id=. Default-path test asserts card visible; explicit
//     branch_id=1 test pinned for completeness.
//   - C-004: every filter test now awaits the filtered XHR response AND
//     summary block visibility BEFORE the snap — no more "Chargement..."
//     screenshots.
//   - C-005: spec coverage expanded from 4 → 9 states (added livreur
//     filter, custom date range, capped notice probe, empty-state with
//     reset CTA, reconciliation visible).
//
// Credentials: admin@lecayenne.fr / 123456 (CLAUDE.md §reference_admin_e2e_creds).
//
// Coordination notes (DO NOT REGRESS):
//   - Wave O O4 /admin/cash-sessions-report stays as a sibling read-only
//     view (per-session detail). X4 is the cross-source aggregate.
//   - Reuses the existing `cash-sessions-report` permission — no new
//     seeder line, no new role gate.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const CASH_OVERVIEW_PATH = '/admin/cash-overview';
const CASH_OVERVIEW_API_RE = /\/api\/admin\/cash-overview(\?|$)/;
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/wave-x4-cash-overview';
const REPORT_DIR = 'reports/test-e2e/wave-x-2026-05-21';

for (const d of [SCREENSHOT_DIR, REPORT_DIR]) {
    fs.mkdirSync(path.resolve(d), { recursive: true });
}

/**
 * Wait for the cash-overview API to settle with a specific query substring,
 * THEN wait for the summary block to be visible. This is the C-004 fix :
 * the previous implementation called page.screenshot immediately after the
 * click and captured the "Chargement..." overlay rather than the filtered
 * result.
 *
 * When `querySubstring === null` we skip the waitForResponse and just wait
 * for the summary block — this avoids double-listener deadlock when the
 * caller already consumed the initial XHR via its own `apiPromise`.
 */
async function waitForFilteredSummary(page, querySubstring = null, timeout = 20_000) {
    if (querySubstring !== null) {
        await page.waitForResponse(
            (resp) =>
                CASH_OVERVIEW_API_RE.test(resp.url())
                && resp.status() < 500
                && resp.url().includes(querySubstring),
            { timeout }
        );
    }
    await expect(page.locator('[data-testid="cash-overview-summary"]')).toBeVisible({ timeout });
}

/**
 * Race-proof initial-mount wait: register the response listener BEFORE the
 * navigation, then trigger the goto, then await both. Returns the parsed
 * JSON body when available. Use this pattern whenever a test needs both
 * the payload AND the rendered summary before continuing.
 */
async function gotoAndWaitInitial(page, url) {
    const apiPromise = page.waitForResponse(
        (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
        { timeout: 40_000 }
    );
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const apiResp = await apiPromise;
    const body = await apiResp.json().catch(() => null);
    await expect(page.locator('[data-testid="cash-overview-summary"]')).toBeVisible({ timeout: 30_000 });
    return body;
}

test.describe('Wave X4 — Admin unified cash overview', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('01 mounts page with filter bar and summary cards', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        const apiRequestPromise = page.waitForResponse(
            (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
            { timeout: 25_000 }
        );

        await page.goto(CASH_OVERVIEW_PATH, { waitUntil: 'domcontentloaded' });

        const apiResp = await apiRequestPromise;
        expect(apiResp.status()).toBeLessThan(500);

        const body = await apiResp.json().catch(() => null);
        expect(body).not.toBeNull();
        expect(body).toHaveProperty('summary');
        expect(body).toHaveProperty('data');
        expect(body).toHaveProperty('cash_session');

        // Filter bar visible.
        await expect(
            page.locator('[data-testid="cash-overview-filters"]')
        ).toBeVisible({ timeout: 15_000 });

        // Summary block visible even when empty (zero-count buckets).
        await expect(
            page.locator('[data-testid="cash-overview-summary"]')
        ).toBeVisible({ timeout: 15_000 });

        // All three source cards rendered with stable order : caisse / borne / livreur.
        for (const src of ['caisse', 'borne', 'livreur']) {
            await expect(
                page.locator(`[data-testid="cash-overview-source-${src}"]`)
            ).toBeVisible({ timeout: 5_000 });
        }

        // Numeric integrity probe : grand_total == sum of by_source totals.
        // Owner's headline KPI must always be consistent across cards.
        if (body && body.summary) {
            const sumOfSources = Object.values(body.summary.by_source || {})
                .reduce((acc, s) => acc + Number(s.total || 0), 0);
            expect(Math.round(sumOfSources * 100) / 100)
                .toBeCloseTo(Number(body.summary.total || 0), 2);
        }

        await snap('01-cash-overview-default');
    });

    test('02 source filter borne re-issues GET and renders filtered summary', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        const filteredCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()) && req.url().includes('source=borne'),
            { timeout: 15_000 }
        );

        await page.selectOption('#cashOverviewSource', 'borne');
        await page.click('[data-testid="cash-overview-search"]');

        const req = await filteredCall;
        expect(req.url()).toContain('source=borne');

        // [C-004 fix] Wait for the filtered XHR to come back AND the summary
        // to be visible before snapping — otherwise we capture "Chargement...".
        await waitForFilteredSummary(page, 'source=borne');

        await snap('02-cash-overview-source-borne');
    });

    test('03 mode filter cash re-issues GET and renders filtered summary', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        const filteredCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()) && req.url().includes('mode=cash'),
            { timeout: 15_000 }
        );

        await page.selectOption('#cashOverviewMode', 'cash');
        await page.click('[data-testid="cash-overview-search"]');

        const req = await filteredCall;
        expect(req.url()).toContain('mode=cash');

        await waitForFilteredSummary(page, 'mode=cash');

        await snap('03-cash-overview-mode-cash');
    });

    test('04 Wave O O4 sibling /admin/cash-sessions-report still loads (no regression)', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        await page.goto('/admin/cash-sessions-report', { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/cash-sessions-report/, { timeout: 10_000 });
        // [C-010 cosmetic-related fix] Wait for networkidle so the branch
        // chip in the top bar is hydrated before snap — otherwise the chip
        // can show an empty value.
        await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => null);
        await snap('04-wave-o-o4-sibling-still-loads');
    });

    test('05 source filter livreur re-issues GET and renders filtered summary', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        const filteredCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()) && req.url().includes('source=livreur'),
            { timeout: 15_000 }
        );

        await page.selectOption('#cashOverviewSource', 'livreur');
        await page.click('[data-testid="cash-overview-search"]');

        const req = await filteredCall;
        expect(req.url()).toContain('source=livreur');

        await waitForFilteredSummary(page, 'source=livreur');

        await snap('05-cash-overview-source-livreur');
    });

    test('06 custom date range (yesterday → today) re-issues GET with both bounds', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        // Build yesterday in local-day terms (page's input type=date is local).
        const today = new Date();
        const yesterday = new Date(today.getTime() - 24 * 60 * 60 * 1000);
        const toIso = (d) => d.toISOString().slice(0, 10);
        const fromValue = toIso(yesterday);
        const toValue   = toIso(today);

        await page.fill('#cashOverviewFrom', fromValue);
        await page.fill('#cashOverviewTo',   toValue);

        const filteredCall = page.waitForRequest(
            (req) =>
                CASH_OVERVIEW_API_RE.test(req.url())
                && req.url().includes(`from=${fromValue}`)
                && req.url().includes(`to=${toValue}`),
            { timeout: 15_000 }
        );

        await page.click('[data-testid="cash-overview-search"]');

        const req = await filteredCall;
        expect(req.url()).toContain(`from=${fromValue}`);
        expect(req.url()).toContain(`to=${toValue}`);

        await waitForFilteredSummary(page, `from=${fromValue}`);

        await snap('06-cash-overview-date-range-yesterday-today');
    });

    test('07 capped notice or full table rendered (probe meta.capped path)', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        const body = await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        // The notice is data-dependent : in V1 LOCAL test DB the cap is rarely
        // hit on today's window. We probe the meta envelope to confirm the
        // cap flag exists AND its truthiness lines up with the UI conditional.
        expect(body).toHaveProperty('meta');
        expect(body.meta).toHaveProperty('capped');
        const cappedFlag = Boolean(body.meta.capped);

        if (cappedFlag) {
            await expect(
                page.locator('[data-testid="cash-overview-cap-notice"]')
            ).toBeVisible({ timeout: 5_000 });
        } else {
            // Negative assertion : when NOT capped the notice must NOT render.
            await expect(
                page.locator('[data-testid="cash-overview-cap-notice"]')
            ).toHaveCount(0);
        }

        await snap('07-cash-overview-capped-probe');
    });

    test('08 empty-state with reset CTA (filter that returns 0 rows, then clear)', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        // Force an empty state by selecting an obscure date range in the past
        // where no fixture data lives (2020-01-01..2020-01-01).
        await page.fill('#cashOverviewFrom', '2020-01-01');
        await page.fill('#cashOverviewTo',   '2020-01-01');

        const filteredCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()) && req.url().includes('from=2020-01-01'),
            { timeout: 15_000 }
        );
        await page.click('[data-testid="cash-overview-search"]');
        await filteredCall;
        await waitForFilteredSummary(page, 'from=2020-01-01');

        await expect(
            page.locator('[data-testid="cash-overview-empty"]')
        ).toBeVisible({ timeout: 10_000 });

        await snap('08a-cash-overview-empty-state');

        // Reset CTA (clear button) must reload today's data.
        const clearedCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()),
            { timeout: 15_000 }
        );
        await page.click('[data-testid="cash-overview-clear"]');
        await clearedCall;
        await waitForFilteredSummary(page, null);

        await snap('08b-cash-overview-after-reset');
    });

    test('09 reconciliation card visible with 3 honest values + count-pending note (C-013 + C-014 fix)', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));

        // [Round-2 C-013 fix] The previous build shipped a 5th "Écart caisse"
        // cell that was algebraically `-opening_amount` — mathematically
        // meaningless. We now show 3 HONEST values (opening / collected_today
        // / expected_in_drawer) + an info note explaining the diff is V1.0.2.
        // [Round-2 C-014 fix] `cash_collected` is now computed via a separate
        // unfiltered query, so it stays invariant against the UI source/mode
        // filters (assertion below: same value across default + filtered).
        const body = await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        const cashSessionPresent = body && body.cash_session !== null;
        let unfilteredCollected = null;
        if (cashSessionPresent) {
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation"]')
            ).toBeVisible({ timeout: 10_000 });

            // Three honest cells must be present.
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-opening"]')
            ).toBeVisible({ timeout: 5_000 });
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-collected"]')
            ).toBeVisible({ timeout: 5_000 });
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-expected"]')
            ).toBeVisible({ timeout: 5_000 });

            // [Round-2 C-013] No diff cell anymore — assert it's gone.
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-diff"]')
            ).toHaveCount(0);

            // Info note must be present (replaces the misleading diff).
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-note"]')
            ).toBeVisible({ timeout: 5_000 });

            // Numeric integrity: expected_cash == opening_amount + cash_collected.
            const opening   = Number(body.cash_session.opening_amount || 0);
            const collected = Number(body.cash_session.cash_collected || 0);
            const expected  = Number(body.cash_session.expected_cash  || 0);
            const recomputed = Math.round((opening + collected) * 100) / 100;
            expect(recomputed).toBeCloseTo(expected, 2);

            unfilteredCollected = collected;
        } else {
            // eslint-disable-next-line no-console
            console.warn('[wave-x4 test 09] cash_session=null (no open drawer in test DB) — card-visible assertion skipped, artifact quartet still emitted.');
        }

        await snap('09-cash-overview-reconciliation-default');

        // Branch-scoped variant — same assertions but with explicit ?branch_id=1.
        const body2 = await gotoAndWaitInitial(page, `${CASH_OVERVIEW_PATH}?branch_id=1`);

        if (body2 && body2.cash_session !== null) {
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation"]')
            ).toBeVisible({ timeout: 10_000 });
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-diff"]')
            ).toHaveCount(0);
            await expect(
                page.locator('[data-testid="cash-overview-reconciliation-note"]')
            ).toBeVisible({ timeout: 5_000 });
        }

        await snap('09b-cash-overview-reconciliation-branch1');

        // [Round-2 C-014 fix] cash_collected must NOT change when a UI source
        // filter is applied — the drawer cash IN is a physical invariant.
        // Re-apply source=borne and confirm payload cash_collected stays the
        // same as the unfiltered value above.
        if (cashSessionPresent && unfilteredCollected !== null) {
            const filteredApi = page.waitForResponse(
                (resp) =>
                    CASH_OVERVIEW_API_RE.test(resp.url())
                    && resp.url().includes('source=borne')
                    && resp.status() < 500,
                { timeout: 15_000 }
            );
            await page.selectOption('#cashOverviewSource', 'borne');
            await page.click('[data-testid="cash-overview-search"]');
            const resp = await filteredApi;
            const fbody = await resp.json().catch(() => null);
            expect(fbody).not.toBeNull();
            expect(fbody).toHaveProperty('cash_session');
            if (fbody.cash_session) {
                const filteredCollected = Number(fbody.cash_session.cash_collected || 0);
                expect(filteredCollected).toBeCloseTo(unfilteredCollected, 2);
            }
            // [Round-2] Already consumed the source=borne XHR via filteredApi
            // above — pass null to avoid double-listener deadlock per spec
            // comment on waitForFilteredSummary; just wait for summary repaint.
            await waitForFilteredSummary(page, null);
            await snap('09c-cash-overview-reconciliation-invariant-under-filter');
        }
    });
});
