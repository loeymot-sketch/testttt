import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [S2 V6 2026-07-29 · reports/goal-s2-caisse-stock/V6-FICHE-IMAGES.md]
// La fiche commande lisait la composition en BRUT. Sur les commandes récentes,
// l'instantané NF525 inverse les rôles (`variation_name` = le CHOIX,
// `attribute_name` = l'intitulé) et nomme les extras `extra_name` : la fiche
// affichait « Mayonnaise: » sans valeur et « Extras: , ». Par ailleurs
// `instruction` vaut NULL en base, donc le garde `!== ''` laissait passer une
// ligne « Instruction: » vide sur CHAQUE article. Enfin les cartes de statut
// ignoraient CANCELED / REJECTED / PENDING_COUNTER / REFUNDED → badge vide sur
// une commande annulée ou remboursée.

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { data: {} } })), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { error: vi.fn(), success: vi.fn() } }));
vi.mock('../../resources/js/services/appService', () => ({
    default: { permissionChecker: () => true, modalShow: vi.fn(), modalHide: vi.fn(), orderStatusClass: () => '', handleSlide: vi.fn() },
}));
vi.mock('vue3-print-nb', () => ({ default: { directive: {} } }));

import PosOrderShowComponent from '../../resources/js/components/admin/posOrders/PosOrderShowComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';

const build = (order = {}) => {
    const store = {
        getters: new Proxy(
            {
                'posOrder/show': { order_type: 10, status: orderStatusEnum.DELIVERED, ...order },
                'posOrder/orderItems': [],
                'posOrder/orderUser': {},
                'posOrder/orderAddress': {},
            },
            { get: (t, p) => (p in t ? t[p] : undefined) }
        ),
        dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
        commit: vi.fn(),
    };
    const Test = { ...PosOrderShowComponent, mounted() {}, beforeUnmount() {}, render: () => null };
    return shallowMount(Test, {
        global: {
            stubs: { 'router-link': true },
            directives: { print: {} },
            mocks: { $store: store, $t: (k) => k, $route: { params: { id: 1 }, query: {} }, $router: { push: vi.fn() } },
        },
    });
};

describe('Fiche commande — composition et statuts', () => {
    it('lit la composition depuis l\'instantané NF525 (rôles inversés)', () => {
        const vm = build().vm;
        const item = {
            item_variations: [{ variation_id: 390, attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Mayonnaise', quantity: 1 }],
            item_extras: [
                { extra_id: 275, extra_name: 'Cheddar', quantity: 1, line_total: 0.9 },
                { extra_id: 403, extra_name: 'Viande supplémentaire', quantity: 1, line_total: 2.5 },
            ],
        };
        const v = vm.normalizedVariations(item);
        expect(v).toHaveLength(1);
        expect(v[0].label).toBe('Sauce (1ère Gratuite)');
        expect(v[0].name).toBe('Mayonnaise');

        const e = vm.normalizedExtras(item);
        expect(e.map((x) => x.name)).toEqual(['Cheddar', 'Viande supplémentaire']);
    });

    it('lit encore l\'ancienne forme (compatibilité descendante)', () => {
        const vm = build().vm;
        const v = vm.normalizedVariations({ item_variations: [{ variation_name: 'Sauce', name: 'Ketchup' }] });
        expect(v[0].label).toBe('Sauce');
        expect(v[0].name).toBe('Ketchup');
    });

    it('écarte les entrées sans nom (ids nus) au lieu d\'afficher « Extras: , »', () => {
        const vm = build().vm;
        expect(vm.normalizedExtras({ item_extras: [{ id: 275, quantity: 1 }, { id: 403, quantity: 1 }] })).toEqual([]);
        expect(vm.normalizedVariations({ item_variations: [{ id: 390, quantity: 1 }] })).toEqual([]);
    });

    it('n\'affiche pas de ligne Instruction quand elle est NULL ou vide', () => {
        const vm = build().vm;
        expect(vm.hasInstruction({ instruction: null })).toBe(false);
        expect(vm.hasInstruction({ instruction: '' })).toBe(false);
        expect(vm.hasInstruction({ instruction: '   ' })).toBe(false);
        expect(vm.hasInstruction({ instruction: 'Sans oignon' })).toBe(true);
    });

    it('nomme TOUS les statuts, y compris annulée / rejetée / remboursée', () => {
        const vm = build().vm;
        for (const s of Object.values(orderStatusEnum)) {
            expect(vm.orderStatusEnumArray[s], `statut ${s} sans libellé`).toBeTruthy();
        }
        for (const p of Object.values(paymentStatusEnum)) {
            expect(vm.paymentStatusEnumArray[p], `paiement ${p} sans libellé`).toBeTruthy();
        }
    });

    it('ne parle de livraison que pour une commande LIVRAISON', () => {
        expect(build({ order_type: 5 }).vm.isDeliveryOrder).toBe(true);
        expect(build({ order_type: 10 }).vm.isDeliveryOrder).toBe(false); // à emporter
        expect(build({ order_type: 25 }).vm.isDeliveryOrder).toBe(false); // borne
    });
});
