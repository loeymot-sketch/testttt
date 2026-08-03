// [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19 wave-E-1] Visual capture spec for
// the POS main-page loyalty redeem CTA. Confirms:
//   - CTA renders in the operator-bar nav (V1 default dine-in OFF)
//   - CTA is disabled when no order is in flight (currentLoyaltyOrder = null)
//   - data-testid="pos-loyalty-redeem-main-cta-open" is present
//   - Floorplan link is NOT rendered (mutually exclusive when dine-in off)
//   - Modal does NOT open when CTA is disabled
//
// This is a capture-only spec (no order creation, no payment) — the
// wire-up + reactivity logic is fully covered by Vitest unit tests
// (tests/js/posLoyaltyMainPageCta.spec.js, 13/13 GREEN).
//
// Snapshot output goes to reports/audit/wave-e-2026-05-19/WE-1-POS-LOYALTY-CTA/
// so the Architect + RED-team specialist JSONs reference a single source
// of visual evidence.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsPosOperator } = require('./helpers/login');

const REPORTS_DIR = path.resolve(
    __dirname,
    '../../reports/audit/wave-e-2026-05-19/WE-1-POS-LOYALTY-CTA',
);

test.describe('Wave E-1 — POS main-page loyalty CTA visual', () => {
    test.setTimeout(180_000);

    test('CTA renders in operator-bar, disabled with no active order', async ({ browser }) => {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();
        try {
            await loginAsPosOperator(page);
            await page.waitForTimeout(1500); // SPA settle

            // Operator-bar contains the new CTA — assert presence by data-testid.
            const cta = page.locator('[data-testid="pos-loyalty-redeem-main-cta-open"]');
            await expect(cta).toBeVisible({ timeout: 25_000 });

            // No active order → disabled state.
            await expect(cta).toBeDisabled();

            // [advisor 2026-05-19] Verify the user-visible tooltip is the
            // i18n-resolved label, not a raw key. PosV5Button forwards :title
            // to the underlying <button>. Default browser locale on the POS
            // is FR (admin@lecayenne.fr default).
            await expect(cta).toHaveAttribute('title', 'Appliquer une réduction fidélité');

            // Floorplan router-link must NOT render when dine-in is off (default V1).
            const floorplanLink = page.locator('.pos-v4-floorplan-link');
            const floorplanCount = await floorplanLink.count();
            expect(floorplanCount).toBe(0);

            // Capture the operator-bar nav strip + full page for visual evidence.
            if (!fs.existsSync(REPORTS_DIR)) {
                fs.mkdirSync(REPORTS_DIR, { recursive: true });
            }
            await page.screenshot({
                path: path.join(REPORTS_DIR, 'capture-01-pos-main-loyalty-cta-disabled.png'),
                fullPage: false,
            });

            // Clicking the disabled button must NOT open the modal.
            await cta.click({ force: true }).catch(() => { /* button is disabled — expected */ });
            await page.waitForTimeout(300);
            const overlay = page.locator('[data-testid="pos-loyalty-redeem-overlay"]');
            await expect(overlay).toHaveCount(0);
        } finally {
            await ctx.close();
        }
    });
});
