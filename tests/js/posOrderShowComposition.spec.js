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

    it('annonce les suppléments non nommés au lieu de les faire disparaître', () => {
        const vm = build().vm;
        // Ligne ancienne réduite à des ids : le normaliseur ne peut pas la nommer,
        // mais l'existence de 2 suppléments facturés doit rester visible.
        expect(vm.unnamedExtrasCount({ item_extras: [{ id: 275, quantity: 1 }, { id: 403, quantity: 1 }] })).toBe(2);
        // Rien à annoncer quand tout est nommé, ni quand il n'y a pas d'extras.
        expect(vm.unnamedExtrasCount({ item_extras: [{ extra_name: 'Cheddar', quantity: 1 }] })).toBe(0);
        expect(vm.unnamedExtrasCount({ item_extras: [] })).toBe(0);
        expect(vm.unnamedExtrasCount({})).toBe(0);
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

    // [GOAL-CAISSE-VISION 2026-08-24] Les suppléments de formule étaient FACTURÉS
    // et IMPRIMÉS sur le ticket, mais absents de cette fiche — celle qu'on ouvre
    // justement quand un client conteste un montant. `grep -c addon` y valait 0.
    it('montre les suppléments de formule facturés, comme le ticket', () => {
        const vm = build().vm;

        // Forme réelle de l'instantané (CompositionSnapshotBuilder.php:166-177).
        expect(vm.normalizedAddons({
            item_addons: [
                { addon_id: 2, addon_item_id: 44, addon_name: 'Frites', role: 'menu_frites', quantity: 1, unit_price: 1.2, line_total: 1.2, catalog_price: 3 },
                { addon_id: 3, addon_name: 'Coca', role: 'menu_boisson', quantity: 2, line_total: 2.4 },
            ],
        })).toEqual([
            { name: 'Frites', quantity: 1, line_total: 1.2 },
            { name: 'Coca', quantity: 2, line_total: 2.4 },
        ]);
    });

    it('n\'invente pas de suppléments quand il n\'y en a pas', () => {
        const vm = build().vm;
        expect(vm.normalizedAddons({ item_addons: [] })).toEqual([]);
        expect(vm.normalizedAddons({})).toEqual([]);
        expect(vm.normalizedAddons(null)).toEqual([]);
        // Une entrée sans nom est écartée, jamais rendue en ligne muette.
        expect(vm.normalizedAddons({ item_addons: [{ addon_id: 7, quantity: 1 }] })).toEqual([]);
    });
});
