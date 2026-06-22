import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import {
    goToKioskError,
    resolveKioskErrorRoute,
} from '../../resources/js/store/modules/kioskCart';
import {
    KIOSK_ERROR_CODES,
    normalizeKioskErrorCode,
    trackKioskErrorEvent,
} from '../../resources/js/helpers/kioskAnalytics';

function readRepoFile(relativePath) {
    return readFileSync(resolve(process.cwd(), relativePath), 'utf8');
}

describe('[CV1-LOT-K08] global kiosk error routing', () => {
    beforeEach(() => {
        delete window.axios;
        vi.spyOn(axios, 'post').mockResolvedValue({ data: { status: true } });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('normalizes known error aliases to one code vocabulary', () => {
        expect(normalizeKioskErrorCode('network_lost')).toBe(KIOSK_ERROR_CODES.NETWORK);
        expect(normalizeKioskErrorCode('menu-unavailable')).toBe(KIOSK_ERROR_CODES.MENU_UNAVAILABLE);
        expect(normalizeKioskErrorCode('product.removed')).toBe(KIOSK_ERROR_CODES.PRODUCT_REMOVED);
        expect(normalizeKioskErrorCode('payment-refused')).toBe(KIOSK_ERROR_CODES.PAYMENT_REFUSED);
        expect(normalizeKioskErrorCode('unknown')).toBe(KIOSK_ERROR_CODES.NETWORK);
    });

    it('resolves each error code to the canonical kiosk route and query payload', () => {
        expect(resolveKioskErrorRoute('network')).toEqual({ name: 'kiosk.error.network' });
        expect(resolveKioskErrorRoute('menu_unavailable')).toEqual({ name: 'kiosk.error.menu-unavailable' });
        expect(resolveKioskErrorRoute('product_removed', {
            productName: 'Tacos XL',
            itemId: 42,
        })).toEqual({
            name: 'kiosk.error.product-removed',
            query: { name: 'Tacos XL', item_id: 42 },
        });
        expect(resolveKioskErrorRoute('payment_refused', {
            errorCode: 'TPE_TIMEOUT',
            orderId: 1234,
        })).toEqual({
            name: 'kiosk.error.payment-refused',
            query: { code: 'TPE_TIMEOUT', order_id: 1234 },
        });
    });

    it('goToKioskError pushes through an injected router and otherwise returns the route object', async () => {
        const router = { push: vi.fn().mockResolvedValue() };

        await goToKioskError('payment_refused', {
            router,
            errorCode: 'CARD_DECLINED',
            orderId: 'K-900',
        });

        expect(router.push).toHaveBeenCalledWith({
            name: 'kiosk.error.payment-refused',
            query: { code: 'CARD_DECLINED', order_id: 'K-900' },
        });
        expect(goToKioskError('menu')).toEqual({ name: 'kiosk.error.menu-unavailable' });
    });

    it('trackKioskErrorEvent posts operational telemetry with normalized subtype', () => {
        expect(trackKioskErrorEvent('error_shown', 'payment-refused', {
            context: { error_code: 'TPE_TIMEOUT', order_id: 123 },
        })).toBe(true);

        expect(axios.post).toHaveBeenCalledWith('/frontend/kiosk/event', {
            type: 'error_shown',
            subtype: 'payment_refused',
            context: { error_code: 'TPE_TIMEOUT', order_id: 123 },
        });
    });

    it('error components use the shared telemetry helper instead of direct axios posts', () => {
        [
            'resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue',
            'resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue',
            'resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue',
            'resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue',
        ].forEach((file) => {
            const source = readRepoFile(file);
            expect(source).toContain('trackKioskErrorEvent');
            expect(source).not.toContain("import axios from 'axios'");
            expect(source).not.toContain("axios.post('/frontend/kiosk/event'");
        });
    });
});
