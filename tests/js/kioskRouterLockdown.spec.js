import { describe, expect, it, vi } from 'vitest';

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

function findRouteByName(routes, name) {
    for (const route of routes) {
        if (route.name === name) {
            return route;
        }

        if (Array.isArray(route.children)) {
            const child = findRouteByName(route.children, name);
            if (child) {
                return child;
            }
        }
    }

    return null;
}

describe('kiosk admin route lockdown', () => {
    it('keeps kiosk.admin as an idle redirect without an admin component', () => {
        const route = findRouteByName(kioskRoutes, 'kiosk.admin');

        expect(route).toMatchObject({
            path: 'admin',
            name: 'kiosk.admin',
            redirect: { name: 'kiosk.idle' },
        });
        expect(route.component).toBeUndefined();
    });
});
