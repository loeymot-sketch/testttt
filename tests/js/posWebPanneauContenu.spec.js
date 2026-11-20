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

import axios from 'axios';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [CONTENU AVANT ACCEPTATION 2026-11-15] Panneau « Commandes web » de /admin/pos : le caissier acceptait sans
// voir le contenu. Les données viennent de GET admin/pos/web-orders/pending (OrderDetailsResource, inchangée) :
// pos_customer_name / pos_customer_phone, user {name, phone}, order_items [{item_name, quantity, instruction}].
// Instruction réelle d'une commande web = lignes séparées par « \n » (FrontendOrderService::instructionWebAssainie),
// la note du client sur sa propre ligne « [Note : …] » / « [Note commande : …] ».
const commande = (over = {}) => ({
    id: 9001,
    order_type: 10,
    queue_number: 'W1',
    total: 24.5,
    created_at: '2026-11-15T19:00:00+01:00',
    pos_customer_name: 'Karim B.',
    pos_customer_phone: '06 12 34 56 78',
    user: { id: 7, name: 'Karim B.', phone: '06 12 34 56 78' },
    order_items: [],
    ...over,
});

function monter() {
    const store = {
        getters: new Proxy({
            'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
            'frontendLanguage/show': { display_mode: 0 },
            'auth/authBranchId': 1, 'auth/authInfo': {}, 'posCart/lists': [], 'posCart/subtotal': 0, 'posCart/discount': 0,
        }, { get(target, property) { return property in target ? target[property] : []; } }),
        dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
        commit: vi.fn(),
    };
    const Test = {
        ...PosComponent,
        mounted() {},
        beforeUnmount() {},
        computed: { ...PosComponent.computed, canProcessWebOrders: () => true },
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(() => Promise.resolve()), loadActiveOrdersStats: vi.fn(() => Promise.resolve()), loadReadyOrders: vi.fn(() => Promise.resolve()),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            _notifyPolledNewOrders: vi.fn(), totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

async function monterAvec(commandes) {
    axios.get.mockResolvedValueOnce({ data: { data: commandes, meta: { total: commandes.length, returned: commandes.length, limit: 200, truncated: false } } });
    const w = monter();
    await w.vm.loadWebOrders();
    await w.vm.$nextTick();
    return w;
}

describe('caisse — panneau « Commandes web » : contenu visible avant d\'accepter', () => {
    beforeEach(() => { axios.get.mockReset(); });

    it('affiche le client (nom · téléphone), le nombre d\'articles et la liste quantité × nom', async () => {
        const w = await monterAvec([commande({
            order_items: [
                { item_name: 'Tacos L', quantity: 1, instruction: null },
                { item_name: 'Coca-Cola', quantity: 2, instruction: null },
            ],
        })]);
        expect(w.find('[data-testid="pos-shortcut-web-client-9001"]').text()).toContain('Karim B. · 06 12 34 56 78');
        const articles = w.find('[data-testid="pos-shortcut-web-articles-9001"]').text();
        expect(articles).toContain('3 articles');
        expect(articles).toContain('1× Tacos L, 2× Coca-Cola');
        expect(articles).not.toContain('autre');
        w.unmount();
    });

    it('au-delà de 3 lignes : liste repliée avec « + N autres »', async () => {
        const w = await monterAvec([commande({
            order_items: [
                { item_name: 'Tacos L', quantity: 1 },
                { item_name: 'Burger Cayenne', quantity: 1 },
                { item_name: 'Frites', quantity: 1 },
                { item_name: 'Coca-Cola', quantity: 1 },
                { item_name: 'Tiramisu', quantity: 1 },
            ],
        })]);
        const articles = w.find('[data-testid="pos-shortcut-web-articles-9001"]').text();
        expect(articles).toContain('5 articles');
        expect(articles).toContain('1× Tacos L, 1× Burger Cayenne, 1× Frites');
        expect(articles).not.toContain('Tiramisu');
        expect(articles).toContain('+ 2 autres');
        w.unmount();
    });

    it('une seule ligne cachée : singulier « + 1 autre » ; un seul article : « 1 article »', async () => {
        const w = await monterAvec([
            commande({ id: 1, order_items: [{ item_name: 'A', quantity: 1 }, { item_name: 'B', quantity: 1 }, { item_name: 'C', quantity: 1 }, { item_name: 'D', quantity: 1 }] }),
            commande({ id: 2, order_items: [{ item_name: 'Tacos L', quantity: 1 }] }),
        ]);
        expect(w.find('[data-testid="pos-shortcut-web-articles-1"]').text()).toContain('+ 1 autre');
        expect(w.find('[data-testid="pos-shortcut-web-articles-1"]').text()).not.toContain('autres');
        expect(w.find('[data-testid="pos-shortcut-web-articles-2"]').text()).toContain('1 article :');
        w.unmount();
    });

    it('note du client : seul le texte de « [Note : …] » est affiché, jamais les lignes techniques', async () => {
        const w = await monterAvec([commande({
            order_items: [
                {
                    item_name: 'Tacos L',
                    quantity: 1,
                    instruction: 'Frites: Classiques\nBoisson menu: Coca-Cola\nSauce frites : Mayonnaise\n[Note : sans oignons, allergie arachide]',
                },
                { item_name: 'Burger', quantity: 1, instruction: 'Viandes en plus : Poulet.\n[Note commande : sonner 2 fois]' },
            ],
        })]);
        const note = w.find('[data-testid="pos-shortcut-web-note-9001"]');
        expect(note.exists()).toBe(true);
        expect(note.text()).toContain('sans oignons, allergie arachide');
        expect(note.text()).toContain('sonner 2 fois');
        const panneau = w.find('[data-testid="pos-shortcut-web-detail-9001"]').text();
        expect(panneau).not.toContain('Boisson menu');
        expect(panneau).not.toContain('Sauce frites');
        expect(panneau).not.toContain('Frites:');
        expect(panneau).not.toContain('Viandes en plus');
        expect(panneau).not.toContain('[Note');
        w.unmount();
    });

    it('sans note : pas de bandeau ; une même note répétée n\'apparaît qu\'une fois', async () => {
        const w = await monterAvec([
            commande({ id: 1, order_items: [{ item_name: 'Tacos L', quantity: 1, instruction: 'Boisson menu: Coca-Cola' }] }),
            commande({ id: 2, order_items: [
                { item_name: 'A', quantity: 1, instruction: '[Note commande : sonner]' },
                { item_name: 'B', quantity: 1, instruction: '[Note commande : sonner]' },
            ] }),
        ]);
        expect(w.find('[data-testid="pos-shortcut-web-note-1"]').exists()).toBe(false);
        expect(w.find('[data-testid="pos-shortcut-web-note-2"]').text().match(/sonner/g)).toHaveLength(1);
        w.unmount();
    });

    it('client : repli sur le compte (user) si la caisse n\'a rien saisi ; rien du tout ⇒ pas de ligne client', async () => {
        const w = await monterAvec([
            commande({ id: 1, pos_customer_name: null, pos_customer_phone: null, user: { name: 'Sofia', phone: null } }),
            commande({ id: 2, pos_customer_name: null, pos_customer_phone: null, user: null }),
        ]);
        expect(w.find('[data-testid="pos-shortcut-web-client-1"]').text()).toContain('Sofia');
        expect(w.find('[data-testid="pos-shortcut-web-client-1"]').text()).not.toContain('·');
        expect(w.find('[data-testid="pos-shortcut-web-client-2"]').exists()).toBe(false);
        w.unmount();
    });

    it('les boutons existants (Accepter, Détails) restent en place', async () => {
        const w = await monterAvec([commande({ order_items: [{ item_name: 'Tacos L', quantity: 1 }] })]);
        expect(w.find('[data-testid="pos-shortcut-web-accept-9001"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-shortcut-web-open-9001"]').exists()).toBe(true);
        w.unmount();
    });
});
