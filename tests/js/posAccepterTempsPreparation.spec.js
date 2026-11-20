import { describe, it, expect, vi, beforeEach } from 'vitest';
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
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

// [GOAL REMARQUES 2026-10-03 · T-3.3 R-017] Propriétaire, 22/09 : « c'est moi qui met ça depuis la caisse
// 15 minutes pour être prêt l'autre il commande une autre commande, je mets par exemple 17 minutes ». Le
// Suivi le permettait ; « Accepter » sur l'écran PRINCIPAL de la caisse n'envoyait aucun temps. Même champ
// libre (défaut 15), toujours envoyé : ce que le caissier voit est ce que le client lira.

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
        computed: { ...PosComponent.computed, canProcessWebOrders: () => true },
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(() => Promise.resolve()), loadActiveOrdersStats: vi.fn(() => Promise.resolve()), loadReadyOrders: vi.fn(() => Promise.resolve()),
            loadWebOrders: vi.fn(() => Promise.resolve()),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), formatKioskPrice: vi.fn((a) => `${a} EUR`), formatKioskTime: vi.fn(() => '10:00'),
            openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: storeMock, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

describe('Caisse — temps de préparation choisi à l\'acceptation d\'une commande web (R-017)', () => {
    beforeEach(() => { axios.post.mockClear(); });

    it('chaque commande web du panneau a son champ de minutes, prérempli à 15', async () => {
        const w = monter();
        w.vm.webOrders = [{ id: 3, queue_number: 'W003', total: 12 }];
        await w.vm.$nextTick();
        const champ = w.find('[data-testid="pos-shortcut-web-prep-3"]');
        expect(champ.exists()).toBe(true);
        expect(champ.element.value).toBe('15');
    });

    it('« Accepter » envoie le temps tapé (17 min)', async () => {
        const w = monter();
        w.vm.webPrepChoice = { 3: 17 };
        await w.vm.acceptWebOrder({ id: 3, queue_number: 'W003' });
        expect(axios.post).toHaveBeenCalledWith(
            'admin/online-order/change-status/3',
            { status: orderStatusEnum.ACCEPT, preparation_time: 17 },
            expect.any(Object),
        );
    });

    it('[revue vague 3 · P2-3] un temps hors des bornes du serveur (5-120 min) est ramené dedans — jamais un 422', async () => {
        const w = monter();
        w.vm.webPrepChoice = { 5: 3, 6: 150 };
        await w.vm.acceptWebOrder({ id: 5 });
        await w.vm.acceptWebOrder({ id: 6 });
        const envoye = (id) => axios.post.mock.calls.find((c) => c[0] === `admin/online-order/change-status/${id}`)[1];
        expect(envoye(5).preparation_time).toBe(5);
        expect(envoye(6).preparation_time).toBe(120);
    });

    it('[revue vague 3 · P2-3] le champ annonce les mêmes bornes que le serveur, à la caisse comme au Suivi', () => {
        const fs = require('node:fs');
        const path = require('node:path');
        for (const f of ['PosComponent.vue', 'PosOrdersTrackerComponent.vue']) {
            const src = fs.readFileSync(path.resolve(__dirname, `../../resources/js/components/admin/pos/${f}`), 'utf8');
            expect(src, f).not.toMatch(/max="180"/);
            expect(src, f).toMatch(/bornerTempsPreparation/);
        }
    });

    it('sans saisie, le défaut affiché (15) est bien celui envoyé', async () => {
        const w = monter();
        await w.vm.acceptWebOrder({ id: 4 });
        expect(axios.post.mock.calls[0][1]).toEqual({ status: orderStatusEnum.ACCEPT, preparation_time: 15 });
    });
});
