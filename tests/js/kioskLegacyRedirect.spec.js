import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('../../resources/js/store/index.js', () => ({
    default: {
        state: { kioskCart: { kioskToken: 'token', orderRef: null } },
        getters: {
            'kioskCart/isEmpty': false,
            'kioskFilter/hydrated': true,
        },
        dispatch: vi.fn().mockResolvedValue(),
    },
}));

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';
import {
    resetSession,
    setBranchId,
    setConsent,
} from '../../resources/js/helpers/kioskAnalytics.js';

function findRouteByName(routes, name) {
    for (const route of routes) {
        if (route.name === name) return route;
        if (Array.isArray(route.children)) {
            const child = findRouteByName(route.children, name);
            if (child) return child;
        }
    }

    return null;
}

describe('kiosk legacy products redirect', () => {
    let postSpy;

    beforeEach(() => {
        postSpy = vi.fn().mockResolvedValue({ data: { status: true } });
        window.axios = { post: postSpy };
        Object.defineProperty(navigator, 'sendBeacon', {
            configurable: true,
            writable: true,
            value: vi.fn(() => false),
        });
        resetSession();
        setConsent(true);
        setBranchId(12);
    });

    it('redirects kiosk.products/:categoryId to kiosk.categories with cat query locked to the route param', () => {
        const route = findRouteByName(kioskRoutes, 'kiosk.products');

        const target = route.redirect({
            params: { categoryId: '42' },
            query: { cat: '999', source: 'qr' },
        });

        expect(target).toEqual({
            name: 'kiosk.categories',
            query: {
                cat: '42',
                source: 'qr',
            },
        });
    });

    it('emits legacy_route_hit telemetry without leaking query values', () => {
        const route = findRouteByName(kioskRoutes, 'kiosk.products');

        route.redirect({
            params: { categoryId: '42' },
            query: { token: 'secret-value', source: 'qr' },
        });

        expect(postSpy).toHaveBeenCalledTimes(1);
        const [url, body] = postSpy.mock.calls[0];
        expect(url).toBe('frontend/kiosk-event');
        expect(body).toMatchObject({
            type: 'idle_event',
            event_name: 'legacy_route_hit',
            subtype: 'legacy_route_hit',
            branch_id: 12,
            details: 'legacy_route_hit',
            payload: {
                from_route: 'kiosk.products',
                target_route: 'kiosk.categories',
                category_id: '42',
                query_keys: ['source', 'token'],
            },
        });
        expect(JSON.stringify(body)).not.toContain('secret-value');
        expect(body.session_id).toMatch(/^[0-9a-f-]{36}$/);
    });
});
