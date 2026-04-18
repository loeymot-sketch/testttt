/**
 * Kiosk Design V1 — Phase 3 route configuration tests
 *
 * Vérifie :
 *  - Les 5 routes P3 sont déclarées avec les bons noms
 *  - Les props extractors parsent correctement les query strings
 *  - Fallback sécurisé (null, undefined, chaines vides, NaN)
 *  - Protection contre injection (valeurs non parseFloat-able)
 *
 * Note: ces tests court-circuitent le store/vuex pour ne valider QUE la
 * configuration du router (indépendant de l'auth et du cart).
 */

import { describe, it, expect, vi } from 'vitest';

// Vuex store est un prérequis du module kioskRoutes.js — on le mock.
vi.mock('../../resources/js/store/index.js', () => ({
    default: {
        state: { kioskCart: { kioskToken: null, orderRef: null } },
        getters: { 'kioskCart/isEmpty': true },
        dispatch: vi.fn().mockResolvedValue(),
    },
}));

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';

function findRouteByName(routes, name) {
    for (const r of routes) {
        if (r.name === name) return r;
        if (Array.isArray(r.children)) {
            const child = findRouteByName(r.children, name);
            if (child) return child;
        }
    }
    return null;
}

describe('Phase 3 — routes registered', () => {
    const expectedNames = [
        'kiosk.cash-instruction',
        'kiosk.error.network',
        'kiosk.error.menu-unavailable',
        'kiosk.error.product-removed',
        'kiosk.error.payment-refused',
    ];

    it('all 5 P3 routes exist with correct name', () => {
        for (const name of expectedNames) {
            expect(findRouteByName(kioskRoutes, name), `route ${name} missing`).toBeTruthy();
        }
    });

    it('all 5 P3 routes have isKiosk meta', () => {
        for (const name of expectedNames) {
            const r = findRouteByName(kioskRoutes, name);
            expect(r.meta?.isKiosk).toBe(true);
        }
    });
});

describe('Phase 3 — cash-instruction props extractor', () => {
    const route = findRouteByName(kioskRoutes, 'kiosk.cash-instruction');

    it('parses normal query', () => {
        const props = route.props({ query: { number: '000123', total: '12.50', timeout: '60' } });
        expect(props.orderNumber).toBe('000123');
        expect(props.orderTotal).toBeCloseTo(12.50);
        expect(props.autoRedirectSeconds).toBe(60);
    });

    it('defaults autoRedirectSeconds to 45 when missing', () => {
        const props = route.props({ query: {} });
        expect(props.autoRedirectSeconds).toBe(45);
    });

    it('handles empty orderTotal gracefully (null)', () => {
        const props = route.props({ query: { total: '' } });
        expect(props.orderTotal).toBeNull();
    });

    it('does not break with injection attempts', () => {
        const props = route.props({
            query: { number: '<script>', total: 'NaN_injection', timeout: 'abc' },
        });
        expect(props.orderNumber).toBe('<script>'); // Vue escapera en template
        expect(Number.isNaN(props.orderTotal)).toBe(true); // parseFloat('NaN_injection') = NaN
        expect(props.autoRedirectSeconds).toBe(45); // parseInt('abc')=NaN → ||45
    });
});

describe('Phase 3 — product-removed props extractor', () => {
    const route = findRouteByName(kioskRoutes, 'kiosk.error.product-removed');

    it('parses productName and itemId', () => {
        const props = route.props({ query: { name: 'Tacos XL', item_id: '42' } });
        expect(props.productName).toBe('Tacos XL');
        expect(props.itemId).toBe('42');
    });

    it('handles missing query (nulls)', () => {
        const props = route.props({ query: {} });
        expect(props.productName).toBeNull();
        expect(props.itemId).toBeNull();
    });
});

describe('Phase 3 — payment-refused props extractor', () => {
    const route = findRouteByName(kioskRoutes, 'kiosk.error.payment-refused');

    it('parses errorCode and orderId', () => {
        const props = route.props({ query: { code: 'TPE_TIMEOUT', order_id: '1234' } });
        expect(props.errorCode).toBe('TPE_TIMEOUT');
        expect(props.orderId).toBe('1234');
    });

    it('handles missing query (nulls)', () => {
        const props = route.props({ query: {} });
        expect(props.errorCode).toBeNull();
        expect(props.orderId).toBeNull();
    });
});
