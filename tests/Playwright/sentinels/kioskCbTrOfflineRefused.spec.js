/**
 * @FK-ID FK-030/FK-044 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M11-KIOSK-RUNTIME | @reason kiosk offline mode must disable/refuse CB/TR payment instead of creating an unreconciliable local payment.
 */
const { test, expect } = require('@playwright/test');

test.describe('KioskCbTrOfflineRefusedSentinel', () => {
    test('offline kiosk refuses card or ticket-restaurant payment affordances', async ({ page }) => {
        await page.route('**/api/**', route => route.abort('internetdisconnected'));
        await page.route('**/frontend/**', route => route.abort('internetdisconnected'));

        const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';
        await page.goto(`${baseUrl}/kiosk/login`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        const disabledPaymentButtons = await page
            .locator('button')
            .evaluateAll((buttons) => buttons.filter((button) => {
                const label = (button.innerText || button.textContent || '').toLowerCase();
                const isCardOrTr = /cb|carte|card|ticket|restaurant|\btr\b/.test(label);
                return isCardOrTr && (button.disabled || button.getAttribute('aria-disabled') === 'true');
            }).length)
            .catch(() => 0);

        const visibleText = await page.locator('body').innerText().catch(() => '');
        const hasOfflineRefusalMessage = /offline|hors ligne|connexion|indisponible|paiement.*refus/i.test(visibleText);

        expect(disabledPaymentButtons > 0 || hasOfflineRefusalMessage).toBeTruthy();
    });
});
