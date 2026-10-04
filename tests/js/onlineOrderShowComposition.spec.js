import { describe, it, expect, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

// [E2E stores · revue adverse round 2 · B2-R2-03 · 2026-10-01]
// La fiche caisse d'une commande WEB (OnlineOrderShowComponent) lisait la composition en BRUT,
// alors que l'API sert l'instantané NF525 (rôles inversés : `attribute_name` = l'intitulé,
// `variation_name` = le choix ; extras nommés `extra_name`). Vu en E2E sur une commande réelle :
// « Tenders: , Algérienne: , Cordon Bleu: » et « Suppléments: » VIDE — le Cheddar à 0,90 € était
// invisible pour le caissier. Même défaut corrigé le 29/07 sur la fiche caisse POS
// (posOrderShowComposition.spec.js) : ce composant-ci avait été oublié.
// Aussi : badge de paiement VIDE après acceptation (PENDING_COUNTER sans libellé), « Paiement à la
// livraison » sur une commande à emporter, et la liste des livreurs demandée à chaque ouverture
// (403 pour le compte caisse, erreur non rattrapée).

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { data: {} } })), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { error: vi.fn(), success: vi.fn() } }));
vi.mock('../../resources/js/services/appService', () => ({
    default: { permissionChecker: () => true, modalShow: vi.fn(), modalHide: vi.fn(), orderStatusClass: () => '', statusClass: () => '', acceptOrder: vi.fn() },
}));
vi.mock('vue3-print-nb', () => ({ default: { directive: {} } }));

import OnlineOrderShowComponent from '../../resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';
import paymentTypeEnum from '../../resources/js/enums/modules/paymentTypeEnum';

const build = (commande = {}, { garderMounted = false } = {}) => {
    const donnees = { id: 7566, order_type: orderTypeEnum.TAKEAWAY, payment_method: paymentTypeEnum.CASH_ON_DELIVERY, status: 4, payment_status: paymentStatusEnum.PENDING_COUNTER, ...commande };
    const store = {
        getters: new Proxy({ 'onlineOrder/show': donnees, 'onlineOrder/orderItems': [], 'deliveryBoy/lists': [] }, { get: (t, p) => (p in t ? t[p] : undefined) }),
        dispatch: vi.fn((nom) => Promise.resolve({ data: { data: nom === 'onlineOrder/show' ? donnees : [] } })),
        commit: vi.fn(),
    };
    const Test = { ...OnlineOrderShowComponent, render: () => null };
    if (!garderMounted) Test.mounted = function () {};
    const w = shallowMount(Test, {
        global: {
            stubs: { 'router-link': true },
            directives: { print: {} },
            mocks: { $store: store, $t: (k) => k, $route: { params: { id: 7566 }, query: {} }, $router: { push: vi.fn() } },
        },
    });
    return { w, store };
};

describe('Fiche caisse d\'une commande web — composition, statuts, libellés', () => {
    it('lit la composition de l\'instantané NF525 (rôles inversés) — Tacos M + Cheddar', () => {
        const { w } = build();
        const ligne = {
            item_variations: [
                { variation_id: 356, attribute_name: 'Viande 1', variation_name: 'Tenders', quantity: 1 },
                { variation_id: 311, attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne', quantity: 1 },
            ],
            item_extras: [{ extra_id: 250, extra_name: 'Cheddar', quantity: 1, line_total: 0.9 }],
        };
        const v = w.vm.normalizedVariations(ligne);
        expect(v.map((x) => `${x.label}: ${x.name}`)).toEqual(['Viande 1: Tenders', 'Sauce (1ère Gratuite): Algérienne']);
        expect(w.vm.normalizedExtras(ligne).map((x) => x.name)).toEqual(['Cheddar']);
    });

    it('lit encore l\'ancienne forme (compatibilité descendante)', () => {
        const { w } = build();
        const v = w.vm.normalizedVariations({ item_variations: [{ variation_name: 'Sauce', name: 'Ketchup' }] });
        expect(`${v[0].label}: ${v[0].name}`).toBe('Sauce: Ketchup');
    });

    it('le badge de paiement a un libellé après acceptation (à encaisser) et après remboursement', () => {
        const { w } = build();
        expect(w.vm.paymentStatusEnumArray[paymentStatusEnum.PENDING_COUNTER]).toBe('label.pending_counter');
        expect(w.vm.paymentStatusEnumArray[paymentStatusEnum.REFUNDED]).toBe('label.refunded');
    });

    it('une commande à emporter réglée sur place dit « Paiement au comptoir », une livraison garde son libellé', () => {
        const { w } = build();
        expect(w.vm.libellePaiement({ order_type: orderTypeEnum.TAKEAWAY, payment_method: paymentTypeEnum.CASH_ON_DELIVERY })).toBe('label.pay_at_counter');
        expect(w.vm.libellePaiement({ order_type: orderTypeEnum.DELIVERY, payment_method: paymentTypeEnum.CASH_ON_DELIVERY })).toBe('label.cash_on_delivery');
    });

    it('n\'appelle PAS la liste des livreurs pour une commande à emporter (403 côté caisse)', async () => {
        const { store } = build({ order_type: orderTypeEnum.TAKEAWAY }, { garderMounted: true });
        await flushPromises();
        const appels = store.dispatch.mock.calls.map((c) => c[0]);
        expect(appels).toContain('onlineOrder/show');
        expect(appels).not.toContain('deliveryBoy/lists');
    });

    it('l\'appelle pour une LIVRAISON', async () => {
        const { store } = build({ order_type: orderTypeEnum.DELIVERY }, { garderMounted: true });
        await flushPromises();
        expect(store.dispatch.mock.calls.map((c) => c[0])).toContain('deliveryBoy/lists');
    });
});
