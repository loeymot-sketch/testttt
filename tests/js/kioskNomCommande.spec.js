import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [NOM-COMMANDE-BORNE 2026-10-19 · owner]
 * « Dernière étape avant de confirmer : un nom pour la commande. Quand un client vient, il me dit
 *   “j'ai la commande au nom de X” ; je ne veux pas ouvrir la liste et chercher une par une. »
 */

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({ onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })) }));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({ default: { modalShow: vi.fn(), modalHide: vi.fn() } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({ default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] } }));

import fr from '../../resources/js/languages/fr.json';
import { kioskCart, buildKioskOrderPayload } from '../../resources/js/store/modules/kioskCart';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import KioskPaymentComponent from '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';
import KioskCashInstructionComponent from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';

const read = (f) => readFileSync(resolve(process.cwd(), f), 'utf8');

describe('store borne : le nom voyage avec la commande, jamais avec le devis', () => {
    const base = () => ({
        items: [], orderType: 25, paymentMethod: 'cash', branchId: 1, orderQuote: null,
        loyaltyCustomer: null, loyaltyRedeemPoints: 0, promoCode: null, kioskMachineId: 1, customerName: '',
    });
    const payload = (state) => buildKioskOrderPayload(state, { orderType: 25, paymentMethod: 'cash', requireExplicitOrderType: true });

    it('envoie pos_customer_name nettoyé quand un nom est saisi', () => {
        const p = payload({ ...base(), customerName: '  Karim  ' });
        expect(p.pos_customer_name).toBe('Karim');
    });

    it('borne la longueur à 60', () => {
        expect(payload({ ...base(), customerName: 'x'.repeat(200) }).pos_customer_name).toHaveLength(60);
    });

    it('n\'envoie AUCUN champ quand le nom est vide', () => {
        expect('pos_customer_name' in payload(base())).toBe(false);
        expect('pos_customer_name' in payload({ ...base(), customerName: '   ' })).toBe(false);
    });

    it('SET_CUSTOMER_NAME écrit, RESET (retour accueil) l\'efface', () => {
        const state = { ...kioskCart.state, items: [] };
        kioskCart.mutations.SET_CUSTOMER_NAME(state, 'Sofia');
        expect(state.customerName).toBe('Sofia');
        kioskCart.mutations.RESET(state);
        expect(state.customerName).toBe('');
    });

    it('le nom n\'est PAS persisté entre sessions (donnée personnelle)', () => {
        expect(read('resources/js/store/index.js')).not.toMatch(/kioskCart\.customerName/);
    });
});

describe('écran borne « paiement à la caisse » : nom obligatoire avant de confirmer', () => {
    const SFC = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

    const build = (name = '') => {
        window.foodkingConfig = { kiosk: { paymentRouteAllToCounter: true } };
        // Vrai store Vuex (mapGetters exige les espaces de noms) avec le VRAI module kioskCart.
        const store = createStore({
            modules: {
                kioskCart: { ...kioskCart, state: () => ({ ...kioskCart.state, items: [], customerName: name }) },
                kioskSettings: { namespaced: true, state: () => ({ locale: 'fr' }) },
            },
        });
        const wrapper = shallowMount(KioskPaymentComponent, {
            global: {
                plugins: [store],
                mocks: { $t: (k) => { let v = fr; for (const p of k.split('.')) v = v?.[p]; return typeof v === 'string' ? v : k; }, $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {} } },
                stubs: { KsVirtualKeyboard: true },
            },
        });
        wrapper.vm.customerName = name;
        return { wrapper, store };
    };

    it('nom vide ou 1 lettre → confirmer désactivé, consigne affichée', async () => {
        const { wrapper } = build('');
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.nameValid).toBe(false);
        expect(wrapper.find('[data-testid="kiosk-payment-counter-confirm"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="kiosk-payment-name-hint"]').exists()).toBe(true);

        wrapper.vm.customerName = 'A';
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.nameValid).toBe(false);
    });

    it('2 lettres ou plus → confirmer activé', async () => {
        const { wrapper } = build('Li');
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.nameValid).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-payment-counter-confirm"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-testid="kiosk-payment-name-hint"]').exists()).toBe(false);
    });

    it('confirmCounterRoute sans nom NE crée PAS de commande et rouvre le clavier', async () => {
        const { wrapper } = build('');
        wrapper.vm.nameKeyboardOpen = false;
        const spy = vi.spyOn(wrapper.vm, 'confirmPayment').mockResolvedValue();
        await wrapper.vm.confirmCounterRoute();
        expect(spy).not.toHaveBeenCalled();
        expect(wrapper.vm.nameKeyboardOpen).toBe(true);
    });

    it('la saisie est écrite dans le store (qui la joint à la commande)', () => {
        const { wrapper, store } = build('');
        wrapper.vm.onNameInput('Karim');
        expect(store.state.kioskCart.customerName).toBe('Karim');
    });

    it('textes FR présents (aucune clé brute à l\'écran)', () => {
        for (const k of ['name_label', 'name_placeholder', 'name_hint']) {
            expect(typeof fr.kiosk.pay_screen[k], k).toBe('string');
        }
        expect(typeof fr.kiosk.cash_instruction.name_label).toBe('string');
        expect(SFC).toContain('kiosk.pay_screen.name_hint');
    });
});

describe('caisse : retrouver une commande par le NOM', () => {
    const harness = (orders) => {
        const store = {
            getters: new Proxy({ 'auth/authBranchId': 1, 'frontendSetting/lists': {} }, { get: (t, p) => (p in t ? t[p] : undefined) }),
            state: { auth: { authBranchId: 1 } }, dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })), commit: vi.fn(),
        };
        const Test = { ...PosOrdersTrackerComponent, mounted() {}, beforeUnmount() {} };
        const wrapper = shallowMount(Test, {
            global: {
                stubs: { transition: false, 'transition-group': false, 'router-link': true },
                mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } },
            },
        });
        wrapper.vm.orders = orders;
        return wrapper;
    };
    const o = (id, name, extra = {}) => ({ id, queue_number: `A00${id}`, source_surface: 'kiosk', status: 1, customer_name: name, ...extra });

    it('chercher « karim » trouve la commande au nom de Karim, pas les autres', async () => {
        const w = harness([o(1, 'Karim'), o(2, 'Sofia'), o(3, null)]);
        w.vm.filters.query = 'karim';
        await w.vm.$nextTick();
        expect(w.vm.filteredOrders.map((x) => x.id)).toEqual([1]);
    });

    it('la recherche par numéro marche toujours', async () => {
        const w = harness([o(1, 'Karim'), o(2, 'Sofia')]);
        w.vm.filters.query = 'A002';
        await w.vm.$nextTick();
        expect(w.vm.filteredOrders.map((x) => x.id)).toEqual([2]);
    });

    it('le nom est affiché en grand : 16 px gras sur la carte', () => {
        const css = read('resources/js/components/admin/pos/PosOrdersTrackerComponent.vue');
        const m = css.match(/\.pos-tracker-card-customer\s*\{([^}]*)\}/);
        expect(m[1]).toMatch(/font-size:\s*16px/);
        expect(m[1]).toMatch(/font-weight:\s*800/);
    });
});

describe('écran « rendez-vous en caisse » : le nom est rappelé au client', () => {
    const monter = (name) => shallowMount(KioskCashInstructionComponent, {
        props: { orderNumber: 'A0042', orderTotal: 7.4, autoRedirectSeconds: 0 },
        global: {
            mocks: {
                $store: { state: { kioskCart: { customerName: name }, frontendSetting: {} } },
                $t: (k) => { let v = fr; for (const p of k.split('.')) v = v?.[p]; return typeof v === 'string' ? v : k; },
                $router: { push: vi.fn(() => Promise.resolve()) },
            },
            stubs: { KsCard: { template: '<div><slot /></div>' }, KsPriceLine: true, KsButton: true },
        },
    });

    it('affiche « Au nom de Karim » quand un nom a été saisi', () => {
        const w = monter('Karim');
        expect(w.find('[data-testid="kiosk-cash-customer-name"]').text()).toBe('Karim');
        expect(w.text()).toContain('Au nom de');
    });

    it('n\'affiche aucune ligne nom quand il n\'y en a pas', () => {
        expect(monter('').find('[data-testid="kiosk-cash-customer-name-row"]').exists()).toBe(false);
    });
});
