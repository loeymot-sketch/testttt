import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({
    default: { name: 'ItemComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({
    default: { name: 'PaymentComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({
    default: { name: 'CreateCustomerAddressComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({
    default: { name: 'CustomerAddressCreateComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

const storeMock = {
    getters: new Proxy({
        'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
        'frontendLanguage/show': { display_mode: 0 },
        'posCategory/lists': [],
        'item/lists': [],
        'user/lists': [],
        'posCart/lists': [],
        'posCart/subtotal': 0,
        'posCart/discount': 0,
        'diningTable/lists': [],
        'user/addressLists': [],
        'auth/authBranchId': 1,
        'auth/authInfo': {},
    }, { get(target, property) { return property in target ? target[property] : []; } }),
    dispatch: vi.fn(() => Promise.resolve({ data: { data: { branch_id: 1 } } })),
    commit: vi.fn(),
};

/**
 * [Root cause 2026-09-24, owner : « je veux pas que ça imprime toujours, c'est du
 * gaspillage de papier » — pour les commandes téléphone/web encaissées au comptoir]
 *
 * onCounterCollectConfirmed() imprimait TOUJOURS le ticket client dès l'encaissement
 * confirmé, sans jamais demander — seule la vente directe (ReceiptComponent) posait
 * la question depuis le 2026-09-21. Même choix explicite ajouté ici : la fonction ne
 * doit plus déclencher d'impression elle-même, seulement poser la question (état
 * counterCollectPrintDecisionOrderId), et printAEncaisserTicket() n'est appelé QUE
 * si le caissier répond "Oui, imprimer".
 */
function buildWrapper() {
    const printAEncaisserTicket = vi.fn(() => Promise.resolve());
    const TestPosComponent = {
        ...PosComponent,
        mounted() {},
        beforeUnmount() {},
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(),
            itemCategories: vi.fn(),
            itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(() => Promise.resolve()),
            loadActiveOrdersStats: vi.fn(() => Promise.resolve()),
            loadReadyOrders: vi.fn(() => Promise.resolve()),
            _subscribeEcho: vi.fn(),
            _startKioskPolling: vi.fn(),
            _bindWsService: vi.fn(),
            _unsubscribeEcho: vi.fn(),
            _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0),
            currencyFormat: vi.fn(() => '0 EUR'),
            formatKioskPrice: vi.fn((amount) => `${amount} EUR`),
            formatKioskTime: vi.fn(() => '10:00'),
            collectKioskCashOrder: vi.fn(),
            openCanvas: vi.fn(),
            closeCanvas: vi.fn(),
            printAEncaisserTicket,
        },
    };
    const wrapper = shallowMount(TestPosComponent, {
        global: {
            stubs: { transition: false },
            mocks: {
                $store: storeMock,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    return { wrapper, printAEncaisserTicket };
}

describe('PosComponent — encaissement téléphone/web ne force plus l\'impression', () => {
    beforeEach(() => {
        storeMock.dispatch.mockClear();
    });

    it('onCounterCollectConfirmed pose la question au lieu d\'imprimer automatiquement', async () => {
        const { wrapper, printAEncaisserTicket } = buildWrapper();

        await wrapper.vm.onCounterCollectConfirmed({ orderId: 4242 });

        expect(wrapper.vm.counterCollectPrintDecisionOrderId, 'la question doit être posée pour CETTE commande').toBe(4242);
        expect(printAEncaisserTicket, 'aucune impression ne doit partir avant la réponse du caissier').not.toHaveBeenCalled();
    });

    it('« Oui, imprimer » déclenche le ticket client et efface la question', async () => {
        const { wrapper, printAEncaisserTicket } = buildWrapper();
        await wrapper.vm.onCounterCollectConfirmed({ orderId: 4242 });

        wrapper.vm.confirmCounterCollectPrint();

        expect(printAEncaisserTicket).toHaveBeenCalledWith({ id: 4242 }, 'client');
        expect(wrapper.vm.counterCollectPrintDecisionOrderId).toBeNull();
    });

    it('« Non merci » n\'imprime PAS et efface la question', async () => {
        const { wrapper, printAEncaisserTicket } = buildWrapper();
        await wrapper.vm.onCounterCollectConfirmed({ orderId: 4242 });

        wrapper.vm.declineCounterCollectPrint();

        expect(printAEncaisserTicket).not.toHaveBeenCalled();
        expect(wrapper.vm.counterCollectPrintDecisionOrderId).toBeNull();
    });
});
