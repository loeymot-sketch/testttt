/**
 * CV1-LOT-K08 static Playwright sentinel.
 *
 * The root Playwright config currently points testDir to tests/e2e, so the
 * canonical Wave 2 command may need --config tests/Playwright to collect this
 * file. The assertions stay static to avoid requiring a live kiosk server.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function readRepoFile(relativePath) {
    return fs.readFileSync(path.join(process.cwd(), relativePath), 'utf8');
}

test.describe('Kiosk global error routing contract', () => {
    test('one helper owns kiosk error routing and telemetry helpers are shared', async () => {
        const cartSource = readRepoFile('resources/js/store/modules/kioskCart.js');
        const analyticsSource = readRepoFile('resources/js/helpers/kioskAnalytics.js');

        expect(cartSource).toContain('export function goToKioskError');
        expect(cartSource).toContain('export function resolveKioskErrorRoute');
        expect(cartSource).toContain('kiosk.error.network');
        expect(cartSource).toContain('kiosk.error.menu-unavailable');
        expect(cartSource).toContain('kiosk.error.product-removed');
        expect(cartSource).toContain('kiosk.error.payment-refused');

        expect(analyticsSource).toContain('export function trackKioskErrorEvent');
        expect(analyticsSource).toContain('export function normalizeKioskErrorCode');
        expect(analyticsSource).toContain('/frontend/kiosk/event');
    });

    test('the four error screens use shared telemetry and stable CTA selectors', async () => {
        const files = [
            {
                path: 'resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue',
                ctas: ['kiosk-error-network-cta-retry', 'kiosk-error-network-cta-staff'],
            },
            {
                path: 'resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue',
                ctas: ['kiosk-error-menu-cta-retry', 'kiosk-error-menu-cta-home'],
            },
            {
                path: 'resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue',
                ctas: ['kiosk-error-product-cta-menu', 'kiosk-error-product-cta-home'],
            },
            {
                path: 'resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue',
                ctas: [
                    'kiosk-error-payment-cta-retry',
                    'kiosk-error-payment-cta-counter',
                    'kiosk-error-payment-cta-cancel',
                ],
            },
        ];

        files.forEach(({ path: filePath, ctas }) => {
            const source = readRepoFile(filePath);
            expect(source).toContain('trackKioskErrorEvent');
            expect(source).not.toContain("import axios from 'axios'");
            ctas.forEach((cta) => expect(source).toContain(`data-testid="${cta}"`));
        });
    });
});
