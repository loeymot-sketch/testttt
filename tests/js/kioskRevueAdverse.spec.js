import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [REVUE ADVERSE test-e2e 2026-10-19] Défauts trouvés par trois relecteurs indépendants sur la borne, la
 * caisse et la cuisine — chacun a ici un test qui échoue sans son correctif.
 */

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({ onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })) }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() } }));
vi.mock('../../resources/js/services/appService', () => ({ default: { modalShow: vi.fn(), modalHide: vi.fn() } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({ default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] } }));

import fr from '../../resources/js/languages/fr.json';
import { kioskCart } from '../../resources/js/store/modules/kioskCart';
import KioskPaymentComponent from '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';
import KioskCashInstructionComponent from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';
import KsVirtualKeyboard from '../../resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import kioskRoutes from '../../resources/js/router/modules/kioskRoutes';

const read = (f) => readFileSync(resolve(process.cwd(), f), 'utf8');
const t = (k) => { let v = fr; for (const p of k.split('.')) v = v?.[p]; return typeof v === 'string' ? v : k; };

/* ------------------------------------------------------------ paiement borne */
describe('borne « paiement à la caisse » : inactivité, nom fantôme, lettres', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => { vi.useRealTimers(); delete window.foodkingConfig; });

    const build = () => {
        window.foodkingConfig = { kiosk: { paymentRouteAllToCounter: true } };
        const store = createStore({
            modules: {
                kioskCart: { ...kioskCart, state: () => ({ ...kioskCart.state, items: [{ id: 1 }], customerName: 'Ancien' }) },
                kioskSettings: { namespaced: true, state: () => ({ locale: 'fr' }) },
            },
        });
        const router = { push: vi.fn(), replace: vi.fn() };
        const wrapper = shallowMount(KioskPaymentComponent, {
            global: { plugins: [store], mocks: { $t: t, $router: router, $route: { query: {} } }, stubs: { KsVirtualKeyboard: true } },
        });
        return { wrapper, store, router };
    };

    it('au montage, un nom laissé par un client précédent est effacé (jamais de nom fantôme)', () => {
        const { store } = build();
        expect(store.state.kioskCart.customerName).toBe('');
    });

    it('après 2 minutes sans geste, l\'écran se remet à zéro et retourne à l\'accueil', () => {
        const { store, router } = build();
        store.commit('kioskCart/SET_CUSTOMER_NAME', 'karim');
        vi.advanceTimersByTime(119000);
        expect(router.replace).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1500);
        expect(router.replace).toHaveBeenCalledWith({ name: 'kiosk.idle' });
        expect(store.state.kioskCart.customerName).toBe('');
        expect(store.state.kioskCart.items).toEqual([]);
    });

    it('un geste du client repousse l\'abandon', () => {
        const { router } = build();
        vi.advanceTimersByTime(100000);
        window.dispatchEvent(new Event('pointerdown'));
        vi.advanceTimersByTime(100000);
        expect(router.replace).not.toHaveBeenCalled();
    });

    it('le démontage coupe la veille (aucune minuterie orpheline)', () => {
        const { wrapper, router } = build();
        wrapper.unmount();
        vi.advanceTimersByTime(600000);
        expect(router.replace).not.toHaveBeenCalled();
    });

    it('DEUX LETTRES exigées : « 12 », « -- », « .. » ne suffisent plus', () => {
        const { wrapper } = build();
        for (const faux of ['12', '--', '..', '1 2', 'a', ' a ']) {
            wrapper.vm.customerName = faux;
            expect(wrapper.vm.nameValid, `« ${faux} »`).toBe(false);
        }
        for (const bon of ['Li', 'Zoé', 'Ab', 'a b']) {
            wrapper.vm.customerName = bon;
            expect(wrapper.vm.nameValid, `« ${bon} »`).toBe(true);
        }
    });

    it('le clavier ouvert réserve sa place (le bouton n\'est plus coupé en deux)', async () => {
        const { wrapper } = build();
        wrapper.vm.nameKeyboardOpen = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.kiosk-pay-counter-route').classes()).toContain('kiosk-pay-counter-route--vk');
        wrapper.vm.nameKeyboardOpen = false;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('.kiosk-pay-counter-route').classes()).not.toContain('kiosk-pay-counter-route--vk');
    });

    it('le texte d\'invitation est lisible à la borne (≥ 20 px) et la zone d\'aide est réservée', () => {
        const css = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');
        const m = css.match(/\.kiosk-pay-name-input::placeholder\s*\{([^}]*)\}/);
        expect(m[1]).toMatch(/font-size:\s*clamp\(2\d/);
        expect(css).toMatch(/\.kiosk-pay-name-hintbox\s*\{[^}]*min-height/);
    });
});

/* ------------------------------------------------------------ clavier */
describe('clavier virtuel : accents et majuscule de début de mot', () => {
    const touches = (props) => mount(KsVirtualKeyboard, { props: { modelValue: '', visible: true, ...props }, global: { mocks: { $t: (k) => k } } })
        .findAll('.ks-vkeyb__key').map((b) => b.text());

    it('sans option, aucun accent (champs e-mail et téléphone inchangés)', () => {
        const k = touches({});
        expect(k).not.toContain('é');
    });

    it('avec accents : é è ê à â ç ù ô ï et l\'apostrophe sont tapables', () => {
        const k = touches({ accents: true, autoCapitalize: false });
        for (const c of ['é', 'è', 'ê', 'à', 'â', 'ç', 'ù', 'ô', 'ï', "'"]) expect(k, c).toContain(c);
    });

    it('majuscule automatique : champ vide → « K », puis minuscules, puis majuscule après espace/tiret/apostrophe', async () => {
        const w = mount(KsVirtualKeyboard, { props: { modelValue: '', visible: true, autoCapitalize: true, accents: true }, global: { mocks: { $t: (k) => k } } });
        const lettres = () => w.findAll('.ks-vkeyb__key').map((b) => b.text());
        expect(lettres()).toContain('K');
        expect(lettres()).toContain('É');
        await w.setProps({ modelValue: 'Kar' });
        expect(lettres()).toContain('k');
        expect(lettres()).not.toContain('K');
        await w.setProps({ modelValue: 'Jean-' });
        expect(lettres()).toContain('K');
        await w.setProps({ modelValue: 'Jean ' });
        expect(lettres()).toContain('K');
    });
});

/* ------------------------------------------------------------ rendez-vous en caisse */
describe('« Rendez-vous en caisse » : nom long, numéro hors ligne, impression, timeout', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    const monter = (props = {}, name = 'Karim') => shallowMount(KioskCashInstructionComponent, {
        props: { orderNumber: 'A0042', orderTotal: 7.4, ...props },
        global: {
            mocks: { $store: { state: { kioskCart: { customerName: name }, frontendSetting: {} } }, $t: t, $router: { push: vi.fn(() => Promise.resolve()) } },
            stubs: { KsCard: { template: '<div><slot /></div>' }, KsPriceLine: true, KsButton: { template: '<button><slot /></button>' } },
        },
    });

    it('le prénom a son propre gabarit borné (plus le 96 px du numéro) avec coupure de mot', () => {
        const css = read('resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue');
        const m = css.match(/\.kiosk-cash__name\s*\{([^}]*)\}/);
        expect(m[1]).toMatch(/font-size:\s*clamp\(36px,\s*6\.5vw,\s*64px\)/);
        expect(m[1]).toMatch(/overflow-wrap:\s*anywhere/);
        const w = monter();
        expect(w.find('[data-testid="kiosk-cash-customer-name"]').classes()).toContain('kiosk-cash__name');
        expect(w.find('[data-testid="kiosk-cash-customer-name"]').classes()).not.toContain('kiosk-cash__number');
    });

    it('un numéro « — » (commande hors ligne) n\'est pas un numéro : repli local « T… »', () => {
        const w = monter({ orderNumber: '—' });
        expect(w.vm.numeroReel).toBe('');
        expect(w.vm.displayOrderNumber).toMatch(/^T\d{5}$/);
    });

    it('la garde d\'impression n\'est plus la même pour deux commandes hors ligne différentes', () => {
        const a = monter({ orderNumber: '—' });
        const b = monter({ orderNumber: '—' });
        // deux montages successifs → deux références locales distinctes (Date.now() % 100000)
        vi.setSystemTime(Date.now() + 1000);
        const c = monter({ orderNumber: '—' });
        expect(new Set([a.vm.printGuardKey(), c.vm.printGuardKey()]).size).toBe(2);
        expect(a.vm.printGuardKey()).not.toBe('—|' + new Date().toISOString().slice(0, 10));
        expect(b.vm.printGuardKey()).toContain('T');
    });

    it('pont d\'impression en panne : le client a 20 s pour toucher « Réimprimer » (pas 5 s)', async () => {
        const w = monter({}, 'Karim');
        vi.advanceTimersByTime(1000);
        w.vm.printFailed = true;
        await w.vm.$nextTick();
        expect(w.vm.countdown).toBeGreaterThanOrEqual(20);
        vi.advanceTimersByTime(8000);
        expect(w.emitted('acknowledged')).toBeFalsy();
    });

    it('une impression en cours retarde le retour accueil (15 s au plus)', () => {
        const w = monter({});
        w.vm.printing = true;
        vi.advanceTimersByTime(5500);
        expect(w.emitted('acknowledged')).toBeFalsy();
        w.vm.printing = false;
        vi.advanceTimersByTime(2000);
        expect(w.emitted('acknowledged')).toBeTruthy();
    });

    it('le délai de la route est un entier > 0 : -1, 0, abc donnent 5 s', () => {
        const route = kioskRoutes.flatMap((r) => [r, ...(r.children || [])]).find((r) => r.name === 'kiosk.cash-instruction');
        for (const bad of ['-1', '0', 'abc', '']) expect(route.props({ query: { timeout: bad } }).autoRedirectSeconds, bad).toBe(5);
        expect(route.props({ query: { timeout: '12' } }).autoRedirectSeconds).toBe(12);
    });
});

/* ------------------------------------------------------------ caisse : recherche et carte */
describe('caisse : recherche insensible aux accents, à l\'ordre des mots, et par téléphone', () => {
    const harness = (orders) => {
        const store = {
            getters: new Proxy({ 'auth/authBranchId': 1, 'frontendSetting/lists': {} }, { get: (tg, p) => (p in tg ? tg[p] : undefined) }),
            state: { auth: { authBranchId: 1 } }, dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })), commit: vi.fn(),
        };
        const wrapper = shallowMount({ ...PosOrdersTrackerComponent, mounted() {}, beforeUnmount() {} }, {
            global: { stubs: { transition: false, 'transition-group': false, 'router-link': true }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
        });
        wrapper.vm.orders = orders;
        return wrapper;
    };
    const o = (id, name, extra = {}) => ({ id, queue_number: `A01${id}`, source_surface: 'kiosk', status: 1, customer_name: name, ...extra });
    const trouve = async (w, q) => { w.vm.filters.query = q; await w.vm.$nextTick(); return w.vm.filteredOrders.map((x) => x.id); };

    it('« eloise » trouve « Éloïse Martin » ; « zoe » trouve « Zoé »', async () => {
        const w = harness([o(1, 'Éloïse Martin'), o(2, 'Zoé'), o(3, 'Karim')]);
        expect(await trouve(w, 'eloise')).toEqual([1]);
        expect(await trouve(w, 'zoe')).toEqual([2]);
        expect(await trouve(w, 'ÉLOÏSE')).toEqual([1]);
    });

    it('l\'ordre des mots n\'importe pas : « martin eloise »', async () => {
        const w = harness([o(1, 'Éloïse Martin'), o(2, 'Martin Paul')]);
        expect(await trouve(w, 'martin eloise')).toEqual([1]);
    });

    it('le téléphone se cherche', async () => {
        const w = harness([o(1, 'Karim', { customer_phone: '0699887766' }), o(2, 'Sofia', { customer_phone: '0611223344' })]);
        expect(await trouve(w, '0699')).toEqual([1]);
    });

    it('caractères spéciaux sans effet de regex, requête vide = tout', async () => {
        const w = harness([o(1, 'Karim'), o(2, 'Sofia')]);
        expect(await trouve(w, 'a.b(c*')).toEqual([]);
        expect(await trouve(w, '   ')).toEqual([1, 2]);
    });

    it('la carte laisse le nom passer à la ligne au lieu de le tronquer (« Éloïs… »)', () => {
        const css = read('resources/js/components/admin/pos/PosOrdersTrackerComponent.vue');
        expect(css.match(/\.pos-tracker-card-customer\s*\{([^}]*)\}/)[1]).toMatch(/flex-wrap:\s*wrap/);
        expect(css.match(/\.pos-tracker-card-customer span\s*\{([^}]*)\}/)[1]).toMatch(/white-space:\s*normal/);
    });

    it('le placeholder tient dans le champ de 200 px et « Retour caisse » ne passe plus à la ligne', () => {
        expect(fr.pos.tracker.search_placeholder).toBe('N° ou nom du client');
        const css = read('resources/js/components/admin/pos/PosOrdersTrackerComponent.vue');
        expect(css.match(/\.pos-tracker-search\s*\{([^}]*)\}/)[1]).toMatch(/min-width:\s*200px/);
    });

    it('le rappel compte aussi les commandes borne en attente de paiement (pas seulement le web)', async () => {
        const w = harness([
            o(1, 'Karim', { payment_status: 15, pos_payment_method: 6 }), // borne à encaisser
            o(2, 'Sofia', { payment_status: 5, status: 7 }),              // borne déjà payée
        ]);
        await w.vm.$nextTick();
        expect(w.vm.rappelCount).toBe(1);
    });
});

/* ------------------------------------------------------------ cuisine */
describe('écran cuisine : un mot long ne sort plus de la carte', () => {
    it('la note autorise la coupure de mot', () => {
        const css = read('resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue');
        expect(css.match(/\.kds-line__instruction-text\s*\{([^}]*)\}/)[1]).toMatch(/overflow-wrap:\s*anywhere/);
    });
});
