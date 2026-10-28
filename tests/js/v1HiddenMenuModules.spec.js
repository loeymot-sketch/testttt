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
            // [FIDÉLITÉ 2026-08-12] `settings.loyalty-setup` RETIRÉ de cette liste : l'écran des règles
            // de fidélité est DÉMASQUÉ. Il était caché depuis le nettoyage V1 du 2 mai, si bien que
            // l'exploitant n'avait aucun moyen de régler son barème ni son plancher — les trois valeurs
            // tournaient sur leurs défauts et tout changement exigeait un développeur. Ce n'est pas un
            // module « différé V2 » : c'est le tableau de bord de sa propre mécanique de fidélité.
            'settings.mail', 'settings.notification', 'settings.theme',
            'settings.item-categories',
            'settings.item-attributes',
            // [ONB-05 2026-08-28] QUATRE cles RETIREES de cette liste :
            // 'settings.permission', 'settings.charge', 'settings.translation' et
            // 'settings.activity-log'. Elles masquaient du VIDE — verifie des deux
            // cotes : aucune route dans settingRoutes.js, et aucun isSettingHidden()
            // ne les consomme dans MenuComponent.vue. Restes du nettoyage du 2 mai.
            // Ce banc est une LIGNE DE BASE : je le mets a jour parce que la liste a
            // deliberement change, pas pour faire taire un echec. La sentinelle
            // clesDeMasquageMasquentQuelqueChose.spec.js empeche desormais qu'une
            // cle sans effet y revienne.
            'settings.role', 'settings.tax', 'settings.languages',
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
