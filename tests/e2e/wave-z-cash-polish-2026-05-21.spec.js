// Wave Z — Cash Overview UX polish capture (Q5/Q6/Q7/Q8) 2026-05-21
//
// Owner mandate verbatim (decisions-v1.html 2026-05-21):
//   Q5 € symbol applied to aggregate cards + per-method chips + table rows.
//   Q6 Empty state with illustration + 20+ char copy + reset CTA.
//   Q7 Removed silent no-op "Autre" mode option from dropdown.
//   Q8 Filters URL-bound — refresh / shared link restores the view.
//
// This spec is a CAPTURE-ONLY recorder targeted at the 4 visual states the
// adversarial review needs to verify. Functional invariants are already
// pinned by `wave-x4-cash-overview.spec.js` (15 PHPUnit tests + 9 E2E
// states). No assertions beyond presence / absence of the targeted DOM
// elements — the screenshots ARE the deliverable.
//
// Captures :
//   01 default-view             → verify € everywhere
//   02 filter-borne             → verify URL sync (?source=borne)
//   03 empty-state              → verify illustration + reset CTA via 1999 date range
//   04 mode-dropdown-options    → verify Autre absent
//   05 after-reset              → verify URL clean + filters reset
//
// Credentials : admin@lecayenne.fr / 123456 (CLAUDE.md §reference_admin_e2e_creds).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const CASH_OVERVIEW_PATH = '/admin/cash-overview';
const CASH_OVERVIEW_API_RE = /\/api\/admin\/cash-overview(\?|$)/;
const SCREENSHOT_DIR = 'tests/e2e/__screenshots__/wave-z-cash-polish';

fs.mkdirSync(path.resolve(SCREENSHOT_DIR), { recursive: true });

async function gotoAndWaitInitial(page, url) {
    const apiPromise = page.waitForResponse(
        (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
        { timeout: 40_000 }
    );
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await apiPromise;
    // Either summary OR empty-state visible — both are valid mount end states.
    await page.waitForSelector(
        '[data-testid="cash-overview-summary"], [data-testid="cash-overview-empty"]',
        { timeout: 30_000 },
    );
}

test.describe('Wave Z — Cash Overview UX polish (Q5/Q6/Q7/Q8)', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('Q5 default view — € symbol everywhere', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));
        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);
        await snap('01-default-view-euro');
    });

    test('Q8 filter via UI — URL sync to ?source=borne', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));
        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        // Change dropdown then submit — should push to URL.
        await page.selectOption('#cashOverviewSource', 'borne');
        const apiPromise = page.waitForResponse(
            (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.url().includes('source=borne'),
            { timeout: 20_000 },
        );
        await page.locator('[data-testid="cash-overview-search"]').click();
        await apiPromise;

        // Verify URL was updated.
        const url = new URL(page.url());
        expect(url.searchParams.get('source')).toBe('borne');

        await snap('02-filter-borne-url-sync');
    });

    test('Q6 empty state — illustration + copy + reset CTA via 1999 date range', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));
        // 1999 dates → guaranteed zero rows.
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?from=1999-01-01&to=1999-01-02`,
        );
        await expect(page.locator('[data-testid="cash-overview-empty"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-empty-illustration"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-empty-copy"]')).toBeVisible();
        await expect(page.locator('[data-testid="cash-overview-empty-reset"]')).toBeVisible();
        await snap('03-empty-state-illustration-cta');
    });

    test('Q7 mode dropdown — Autre option removed', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));
        await gotoAndWaitInitial(page, CASH_OVERVIEW_PATH);

        // Open dropdown by capturing options list.
        const optionValues = await page.locator('#cashOverviewMode option').evaluateAll(
            (opts) => opts.map((o) => o.value),
        );
        // Must contain cash/card/mobile/ticket + empty for "all". Must NOT contain 'other'.
        expect(optionValues).toContain('cash');
        expect(optionValues).toContain('card');
        expect(optionValues).not.toContain('other');

        // Force the select open visually for capture by clicking it.
        await page.locator('#cashOverviewMode').click({ trial: false }).catch(() => {});
        await snap('04-mode-dropdown-no-autre');
    });

    test('Q8 reset — URL clean after click', async ({ page }) => {
        const { snap } = attachMegaAuditRecorder(page, path.resolve(SCREENSHOT_DIR));
        // Start with a filtered URL.
        await gotoAndWaitInitial(
            page,
            `${CASH_OVERVIEW_PATH}?source=borne`,
        );

        // Click clear filters → URL should drop source param.
        const apiPromise = page.waitForResponse(
            (resp) => CASH_OVERVIEW_API_RE.test(resp.url()) && resp.status() < 500,
            { timeout: 20_000 },
        );
        await page.locator('[data-testid="cash-overview-clear"]').click();
        await apiPromise.catch(() => {});

        await page.waitForTimeout(500);
        const url = new URL(page.url());
        expect(url.searchParams.get('source')).toBeNull();

        await snap('05-after-reset-url-clean');
    });
});
