import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';

// [GOAL REMARQUES 2026-10-03 · T-3.5 R-016] « lors de retrait de commande par site Web […] je pourrais les
// valider comme ça ils seront validés il y aura ces points ». Le panneau « Web payées » était en lecture
// seule. Une commande du site PRÊTE y porte « Valider le retrait » (→ livrée → points crédités par
// AwardLoyaltyPointsOnDelivery) ; une commande encore en cuisine n'a pas ce bouton (transition illégale).

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

const loadPaidWebOrders = vi.fn(() => Promise.resolve());

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
            loadWebOrders: vi.fn(() => Promise.resolve()), loadPaidWebOrders,
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), formatKioskPrice: vi.fn((a) => `${a} EUR`), formatKioskTime: vi.fn(() => '10:00'),
            openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: storeMock, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

describe('Caisse — « Web payées » : valider le retrait d\'une commande du site prête (R-016)', () => {
    beforeEach(() => { storeMock.dispatch.mockClear(); loadPaidWebOrders.mockClear(); });

    it('bouton présent sur une commande PRÊTE, absent sur une commande encore en cuisine', async () => {
        const w = monter();
        w.vm.paidWebOrders = [
            { id: 51, queue_number: 'W51', total: 12, status: orderStatusEnum.PREPARED },
            { id: 52, queue_number: 'W52', total: 9, status: orderStatusEnum.PREPARING },
        ];
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-shortcut-web-paid-pickup-51"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-shortcut-web-paid-pickup-52"]').exists()).toBe(false);
    });

    it('[revue vague 3 · P2-5] une commande du site payée et prête n\'apparaît QUE dans « Web payées » — pas aussi dans « Prêt »', () => {
        const w = monter();
        const pret = orderStatusEnum.PREPARED;
        const liste = [
            { id: 51, source_surface: 'web', order_type: orderTypeEnum.TAKEAWAY, payment_status: 5, status: pret, created_at: '2026-10-03T12:00:00Z' },
            { id: 52, source_surface: 'kiosk', order_type: orderTypeEnum.TAKEAWAY, payment_status: 5, status: pret, created_at: '2026-10-03T12:01:00Z' },
            { id: 53, source_surface: 'web', order_type: orderTypeEnum.DELIVERY, payment_status: 5, status: pret, created_at: '2026-10-03T12:02:00Z' },
        ];
        // Elle n'est retirée de « Prêt » que si « Web payées » la montre VRAIMENT.
        w.vm.paidWebOrders = [{ id: 51 }];
        const ids = liste.filter((o) => w.vm.estPretAuComptoir(o)).map((o) => o.id);
        expect(ids).toEqual([52, 53]);
    });

    it('[revue de convergence · P1] absente de « Web payées » (commande à l\'avance, fenêtre de 8 h), elle RESTE dans « Prêt » — jamais invisible', () => {
        const w = monter();
        w.vm.paidWebOrders = [];
        const programmee = { id: 61, source_surface: 'web', order_type: orderTypeEnum.TAKEAWAY, payment_status: 5, status: orderStatusEnum.PREPARED };
        expect(w.vm.estPretAuComptoir(programmee)).toBe(true);
    });

    it('valider le retrait passe la commande en LIVRÉE puis rafraîchit le panneau', async () => {
        const w = monter();
        await w.vm.validerRetraitWeb({ id: 51, queue_number: 'W51', status: orderStatusEnum.PREPARED });
        expect(storeMock.dispatch).toHaveBeenCalledWith('posOrder/changeStatus', { id: 51, status: orderStatusEnum.DELIVERED });
        // Vue lie les méthodes : on vérifie l'espion d'origine, pas la méthode liée.
        expect(loadPaidWebOrders).toHaveBeenCalled();
    });
});
