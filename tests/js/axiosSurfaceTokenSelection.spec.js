import { afterEach, describe, expect, it } from 'vitest';
import {
    readTokenFromVuexLocalStorage,
    selectSurfaceBearerToken,
} from '../../resources/js/shared/axios-setup';

function storeState(state = {}) {
    return {
        state: {
            kioskCart: state.kioskCart || {},
            auth: state.auth || {},
            globalState: state.globalState || {},
        },
    };
}

describe('surface-aware bearer token selection', () => {
    afterEach(() => {
        window.localStorage.clear();
        window.history.pushState({}, '', '/');
    });

    it('prefers staff auth token on POS/admin pages even when a kiosk token is persisted', () => {
        expect(selectSurfaceBearerToken({
            kioskToken: 'kiosk-token',
            userToken: 'staff-token',
        }, '/admin/pos')).toBe('staff-token');
    });

    it('prefers kiosk token on kiosk pages', () => {
        expect(selectSurfaceBearerToken({
            kioskToken: 'kiosk-token',
            userToken: 'staff-token',
        }, '/kiosk/categories')).toBe('kiosk-token');
    });

    it('reads persisted tokens without letting kiosk auth poison POS API calls', () => {
        window.history.pushState({}, '', '/admin/pos');
        window.localStorage.setItem('vuex', JSON.stringify({
            kioskCart: { kioskToken: 'persisted-kiosk-token' },
            auth: { authToken: 'persisted-staff-token' },
            globalState: { lists: { language_code: 'fr' } },
        }));

        expect(readTokenFromVuexLocalStorage(storeState())).toEqual({
            token: 'persisted-staff-token',
            language: 'fr',
        });
    });
});
