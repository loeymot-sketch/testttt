import { describe, it, expect } from 'vitest';
import {
    V1_HIDDEN_MENU_MODULES,
    V1_HIDDEN_BACKEND_MENU_URLS,
    isV1HiddenMenuModule,
} from '../../resources/js/config/v1-hidden-modules.js';

describe('V1_HIDDEN_MENU_MODULES (Lot A.2)', () => {
    it('contient les modules masqués V1 attendus', () => {
        const expected = [
            'customers', 'coupons', 'offers', 'creditBalanceReport',
            'deliveryBoys', 'onlineOrders', 'tableOrders', 'waiters', 'diningTables',
            'settings.mail', 'settings.loyalty-setup', 'settings.notification', 'settings.theme',
            'settings.item-categories',
            'settings.item-attributes',
            'settings.permission', 'settings.role', 'settings.tax', 'settings.charge',
            'settings.translation', 'settings.activity-log', 'settings.languages',
            'settings.otp', 'settings.notification-alert', 'settings.social-media',
            'settings.cookies', 'settings.analytics', 'settings.time-slots',
            'settings.sliders', 'settings.pages',
            'settings.sms-gateway', 'settings.payment-gateway', 'settings.license',
        ];
        expect(V1_HIDDEN_MENU_MODULES).toEqual(expect.arrayContaining(expected));
        expect(V1_HIDDEN_MENU_MODULES.length).toBe(expected.length);
    });

    it('isV1HiddenMenuModule retourne true pour les modules listés', () => {
        expect(isV1HiddenMenuModule('customers')).toBe(true);
        expect(isV1HiddenMenuModule('settings.theme')).toBe(true);
        expect(isV1HiddenMenuModule('deliveryBoys')).toBe(true);
        expect(isV1HiddenMenuModule('onlineOrders')).toBe(true);
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

    it('hides settings.item-attributes from Paramètres menu', () => {
        expect(V1_HIDDEN_MENU_MODULES).toContain('settings.item-attributes');
    });

    it('exports V1_HIDDEN_BACKEND_MENU_URLS containing items', () => {
        expect(V1_HIDDEN_BACKEND_MENU_URLS).toContain('items');
    });
});
