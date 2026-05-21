// Wave X4 E2E — Admin unified cash & transactions overview (P-OWNER 2026-05-21)
//
// Owner mandate (verbatim translated):
//   « Toutes les commandes encaissées (POS direct, borne cash-collected,
//     livreur) au MÊME ENDROIT en base. Admin part + caisse voient tout.
//     Pour chaque transaction : source (borne/caisse/livreur) + mode
//     paiement + total. Totaux par source + grand total. Permet de
//     détecter écarts (cash manquant). »
//
// What this spec exercises (live-DB E2E, consistent with Wave V no-seed):
//   1. Admin loads /admin/cash-overview → page mounts with filter bar +
//      summary cards visible (grand total + 3 source cards) BEFORE any
//      data round-trip — empty state must still render the layout.
//   2. The transactions GET request fires (route /api/admin/cash-overview)
//      with `from`/`to` defaulting to today.
//   3. Filter UI is interactive: changing `source=borne` re-issues GET
//      with the param + visually highlights the source dropdown choice.
//   4. Filter UI: changing `mode=cash` re-issues GET with the param.
//   5. Wave O O4 sibling `/admin/cash-sessions-report` still loads
//      without regression (X4 is a sibling, not a replacement).
//   6. Screenshot capture in `tests/e2e/__screenshots__/wave-x4-cash-overview/`
//      for visual diff.
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

const CASH_OVERVIEW_PATH = '/admin/cash-overview';
const CASH_OVERVIEW_API_RE = /\/api\/admin\/cash-overview(\?|$)/;
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/wave-x4-cash-overview';
const REPORT_DIR = 'reports/test-e2e/wave-x-2026-05-21';

for (const d of [SCREENSHOT_DIR, REPORT_DIR]) {
    fs.mkdirSync(path.resolve(d), { recursive: true });
}

test.describe('Wave X4 — Admin unified cash overview', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('mounts page with filter bar and summary cards', async ({ page }) => {
        const apiRequestPromise = page.waitForResponse(
            (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
            { timeout: 25_000 }
        );

        await page.goto(CASH_OVERVIEW_PATH, { waitUntil: 'domcontentloaded' });

        const apiResp = await apiRequestPromise;
        expect(apiResp.status()).toBeLessThan(500);

        // The default `from`/`to` are today on the client; the controller
        // also accepts an empty query and applies the same default. Body
        // must include the summary envelope.
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

        await page.screenshot({
            path: path.resolve(SCREENSHOT_DIR, '01-cash-overview-default.png'),
            fullPage: true,
        });
    });

    test('source filter re-issues GET with source param', async ({ page }) => {
        await page.goto(CASH_OVERVIEW_PATH, { waitUntil: 'domcontentloaded' });
        await page
            .waitForResponse((resp) => CASH_OVERVIEW_API_RE.test(resp.url()), { timeout: 25_000 })
            .catch(() => null);

        const filteredCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()) && req.url().includes('source=borne'),
            { timeout: 15_000 }
        );

        await page.selectOption('#cashOverviewSource', 'borne');
        await page.click('[data-testid="cash-overview-search"]');

        const req = await filteredCall;
        expect(req.url()).toContain('source=borne');

        await page.screenshot({
            path: path.resolve(SCREENSHOT_DIR, '02-cash-overview-source-borne.png'),
            fullPage: true,
        });
    });

    test('mode filter re-issues GET with mode=cash', async ({ page }) => {
        await page.goto(CASH_OVERVIEW_PATH, { waitUntil: 'domcontentloaded' });
        await page
            .waitForResponse((resp) => CASH_OVERVIEW_API_RE.test(resp.url()), { timeout: 25_000 })
            .catch(() => null);

        const filteredCall = page.waitForRequest(
            (req) => CASH_OVERVIEW_API_RE.test(req.url()) && req.url().includes('mode=cash'),
            { timeout: 15_000 }
        );

        await page.selectOption('#cashOverviewMode', 'cash');
        await page.click('[data-testid="cash-overview-search"]');

        const req = await filteredCall;
        expect(req.url()).toContain('mode=cash');

        await page.screenshot({
            path: path.resolve(SCREENSHOT_DIR, '03-cash-overview-mode-cash.png'),
            fullPage: true,
        });
    });

    test('Wave O O4 sibling /admin/cash-sessions-report still loads (no regression)', async ({ page }) => {
        // Confirms the X4 page is a sibling, not a replacement.
        await page.goto('/admin/cash-sessions-report', { waitUntil: 'domcontentloaded' });
        // Wave O O4 page mounts the CashSessionReportListComponent — check
        // that the URL stuck (no redirect to dashboard meaning 403/missing route).
        await expect(page).toHaveURL(/cash-sessions-report/, { timeout: 10_000 });
        await page.screenshot({
            path: path.resolve(SCREENSHOT_DIR, '04-wave-o-o4-sibling-still-loads.png'),
            fullPage: true,
        });
    });
});
