import { describe, it, expect } from 'vitest';
import { V1_HIDDEN_MENU_MODULES, isV1HiddenMenuModule } from '../../resources/js/config/v1-hidden-modules.js';

describe('V1_HIDDEN_MENU_MODULES (Lot A.2)', () => {
    it('contient les 8 modules G2 spécifiés par le plan', () => {
        const expected = [
            'customers', 'coupons', 'offers', 'creditBalanceReport',
            'settings.mail', 'settings.loyalty-setup', 'settings.notification', 'settings.theme',
        ];
        expect(V1_HIDDEN_MENU_MODULES).toEqual(expect.arrayContaining(expected));
        expect(V1_HIDDEN_MENU_MODULES.length).toBe(expected.length);
    });

    it('isV1HiddenMenuModule retourne true pour les modules listés', () => {
        expect(isV1HiddenMenuModule('customers')).toBe(true);
        expect(isV1HiddenMenuModule('settings.theme')).toBe(true);
    });

    it('isV1HiddenMenuModule retourne false pour les modules visibles V1', () => {
        expect(isV1HiddenMenuModule('pos')).toBe(false);
        expect(isV1HiddenMenuModule('items')).toBe(false);
        expect(isV1HiddenMenuModule('stock')).toBe(false);
        expect(isV1HiddenMenuModule('settings.branches')).toBe(false);
    });

    it('liste est immuable (frozen)', () => {
        expect(Object.isFrozen(V1_HIDDEN_MENU_MODULES)).toBe(true);
    });
});
