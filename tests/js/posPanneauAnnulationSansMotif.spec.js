import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import axios from 'axios';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [GOAL REMARQUES 2026-10-03 · T-2.1 R-060] Propriétaire, 29/09 : « je veux pas cliquer sur chacune et
// je mettre la justificatif pour pouvoir annuler directement X et ça s'annule » ; 03/10 : « l'annulation
// des commandes annuler par téléphone ». La page Encaissement le faisait depuis le 29/09 ; le panneau
// « à encaisser » de la CAISSE exigeait encore un motif TAPÉ (≥ 3 caractères). La confirmation reste
// (deux gestes : Annuler puis Oui) — seule la saisie disparaît : motif pré-rempli « Client non venu ».

const storeMock = {
    getters: new Proxy({
        'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
        'frontendLanguage/show': { display_mode: 0 },
        'auth/authBranchId': 1,
        'auth/authInfo': {},
        'posCart/subtotal': 0,
        'posCart/discount': 0,
    }, { get(target, property) { return property in target ? target[property] : []; } }),
    dispatch: vi.fn(() => Promise.resolve({ data: { data: { branch_id: 1 } } })),
    commit: vi.fn(),
};

function monter() {
    const Test = {
        ...PosComponent,
        mounted() {},
        beforeUnmount() {},
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(() => Promise.resolve()),
            loadActiveOrdersStats: vi.fn(() => Promise.resolve()),
            loadReadyOrders: vi.fn(() => Promise.resolve()),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), formatKioskPrice: vi.fn((a) => `${a} EUR`), formatKioskTime: vi.fn(() => '10:00'),
            openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: {
            stubs: { transition: false },
            mocks: { $store: storeMock, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } },
        },
    });
}

describe('Caisse — panneau « à encaisser » : annuler sans taper de motif (R-060)', () => {
    beforeEach(() => { axios.post.mockClear(); });

    it('la fenêtre s\'ouvre avec le motif déjà rempli', () => {
        const w = monter();
        w.vm.openCancelKioskCashDialog({ id: 7, queue_number: 'T007' });
        expect(w.vm.cancelKioskCashDialog.reason).toBe('Client non venu');
    });

    it('« Oui, annuler » part tout de suite, sans erreur, avec le motif pré-rempli', async () => {
        const w = monter();
        w.vm.openCancelKioskCashDialog({ id: 7, queue_number: 'T007' });
        await w.vm.confirmCancelKioskCashOrder();
        expect(w.vm.cancelKioskCashDialog.error || '').toBe('');
        expect(axios.post).toHaveBeenCalledWith('admin/pos/counter-collect/7/cancel', { reason: 'Client non venu' }, expect.any(Object));
    });

    it('un motif effacé par le caissier retombe sur « Client non venu » au lieu de bloquer', async () => {
        const w = monter();
        w.vm.openCancelKioskCashDialog({ id: 8 });
        w.vm.cancelKioskCashDialog.reason = '  ';
        await w.vm.confirmCancelKioskCashOrder();
        expect(axios.post).toHaveBeenCalledWith('admin/pos/counter-collect/8/cancel', { reason: 'Client non venu' }, expect.any(Object));
    });

    it('un motif tapé par le caissier est conservé', async () => {
        const w = monter();
        w.vm.openCancelKioskCashDialog({ id: 9 });
        w.vm.cancelKioskCashDialog.reason = 'Doublon de saisie';
        await w.vm.confirmCancelKioskCashOrder();
        expect(axios.post).toHaveBeenCalledWith('admin/pos/counter-collect/9/cancel', { reason: 'Doublon de saisie' }, expect.any(Object));
    });
});
