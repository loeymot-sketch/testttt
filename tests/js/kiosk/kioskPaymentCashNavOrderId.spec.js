import { describe, it, expect, vi } from 'vitest';

/**
 * [TICKET-BORNE-SERVEUR 2026-07-06] FIX B — le flux CASH (Plan B caisse) doit
 * transporter l'orderId backend jusqu'à l'écran kiosk.cash-instruction pour que
 * le ticket borne sorte du RENDERER SERVEUR (design caisse) et non du builder
 * client legacy. Avant ce fix, KioskPaymentComponent naviguait sans orderId →
 * l'écran ne pouvait qu'imprimer le ticket legacy (ASCII-fold, EUR, compo Vuex).
 */

// Vuex store est un prérequis du module kioskRoutes.js — on le mock.
vi.mock('../../../resources/js/store/index.js', () => ({
    default: {
        state: { kioskCart: { kioskToken: null, orderRef: null } },
        getters: { 'kioskCart/isEmpty': true },
        dispatch: vi.fn().mockResolvedValue(),
    },
}));

import KioskPaymentComponent from '../../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';
import kioskRoutes from '../../../resources/js/router/modules/kioskRoutes.js';

const buildNav = KioskPaymentComponent.methods.buildPaymentNavTarget;

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

describe('KioskPaymentComponent.buildPaymentNavTarget — orderId vers cash-instruction', () => {
    it('CASH : la query porte orderId (String), number, total, timeout', () => {
        const nav = buildNav.call({ method: 'cash' }, {
            orderId: 5312, queueNum: 'A0007', total: 12.4, isOfflineId: false,
        });
        expect(nav.name).toBe('kiosk.cash-instruction');
        expect(nav.query).toEqual({ number: 'A0007', total: 12.4, timeout: 45, orderId: '5312' });
    });

    it('CASH offline (offline_…) : PAS d\'orderId (commande inexistante côté serveur → fallback legacy)', () => {
        const nav = buildNav.call({ method: 'cash' }, {
            orderId: 'offline_123', queueNum: 'A0008', total: 5, isOfflineId: true,
        });
        expect(nav.name).toBe('kiosk.cash-instruction');
        expect(nav.query.orderId).toBeUndefined();
    });

    it('CARD : navigation kiosk.waiting inchangée (params.orderId)', () => {
        const nav = buildNav.call({ method: 'card' }, {
            orderId: 5312, queueNum: 'A0007', total: 12.4, isOfflineId: false,
        });
        expect(nav.name).toBe('kiosk.waiting');
        expect(nav.params.orderId).toBe('5312');
    });
});

describe('kioskRoutes — kiosk.cash-instruction expose la prop orderId', () => {
    const route = findRouteByName(kioskRoutes, 'kiosk.cash-instruction');

    it('la route existe et a un props extractor', () => {
        expect(route).toBeTruthy();
        expect(typeof route.props).toBe('function');
    });

    it('query orderId → prop orderId (String)', () => {
        const props = route.props({ query: { number: 'A0007', total: '12.4', timeout: '45', orderId: '5312' } });
        expect(props.orderId).toBe('5312');
        expect(props.orderNumber).toBe('A0007');
        expect(props.orderTotal).toBe(12.4);
    });

    it('sans orderId (deep-link / legacy) → null (fallback builder client)', () => {
        const props = route.props({ query: { number: 'A0007', total: '12.4' } });
        expect(props.orderId).toBeNull();
    });
});
