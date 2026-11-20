import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

/**
 * [C-001 · supervisor-caisse-2026-08-24/round-1/wave-C-findings.json]
 *
 * MÊME COMMANDE, DEUX SURFACES, DEUX VÉRITÉS.
 *
 * La fiche `/admin/pos-orders/show/:id` rend 4 lignes de composition (sauce,
 * extras, suppléments de formule, instruction) pour la commande 6751 ; la
 * carte de la file d'encaissement rendait `1× Menu (Frites + Boisson)` et
 * RIEN d'autre — l'instruction « Sans oignons » comprise.
 *
 * La donnée est DÉJÀ dans la réponse : `routes/api.php:988` →
 * `OrderDetailsResource` → `OrderItemResource.php:33-36` (item_variations /
 * item_extras / item_addons / composition_snapshot) et `:50` (instruction).
 * C'était le TEMPLATE (`EncaissementComponent.vue:54-61`) qui la jetait.
 *
 * Pourquoi ce n'est pas cosmétique : l'encaissement est le SEUL des trois
 * écrans où le caissier fait face au client au moment de prendre l'argent.
 * C'était aussi le seul à ne pas savoir dire de quoi le montant est fait.
 *
 * Le lecteur de composition est le normaliseur canonique partagé
 * (`helpers/posReceiptBuilder`), le même que la fiche et le ticket — pas un
 * quatrième lecteur.
 */

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { data: [] } })), post: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { error: vi.fn(), success: vi.fn() } }));
vi.mock('../../resources/js/services/appService', () => ({
    default: { permissionChecker: () => true, modalShow: vi.fn(), modalHide: vi.fn(), handleSlide: vi.fn() },
}));
vi.mock('../../resources/js/services/eventContract', () => ({ onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })) }));
vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({ printEscPosViaCaisseBridge: vi.fn() }));

import EncaissementComponent from '../../resources/js/components/admin/encaissement/EncaissementComponent.vue';

/** La commande 6751 de la vague C, telle que l'API la renvoie réellement. */
const ORDER_6751 = {
    id: 6751,
    queue_number: 'C98A2',
    source_surface: 'kiosk',
    total: 14.6,
    user: { name: 'Admin Le Cayenne' },
    order_items: [
        {
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Sans oignons',
            item_variations: [
                { variation_id: 390, attribute_name: 'Sauce', variation_name: 'Algérienne', quantity: 1 },
            ],
            item_extras: [
                { extra_id: 275, name: 'Cheddar', quantity: 2, line_total: 1.0 },
            ],
            item_addons: [
                { addon_id: 12, addon_name: 'Frites', quantity: 1, line_total: 1.2 },
                { addon_id: 13, addon_name: 'Coca-Cola 33cl', quantity: 1, line_total: 1.2 },
            ],
        },
    ],
};

const build = (orders = [ORDER_6751]) => {
    const store = {
        getters: new Proxy({}, { get: () => undefined }),
        state: {},
        dispatch: vi.fn(),
        commit: vi.fn(),
    };
    // mounted() déclenche fetch + poll + Echo : neutralisé, on injecte l'état.
    const Test = { ...EncaissementComponent, mounted() {}, beforeUnmount() {} };
    return shallowMount(Test, {
        data() {
            return { orders, loading: { isActive: false }, fetchError: false, encaisseOrder: null, pollTimer: null };
        },
        global: {
            stubs: { 'router-link': true },
            mocks: { $store: store, $t: (k) => k, $route: { params: {}, query: {} }, $router: { push: vi.fn() } },
        },
    });
};

describe('C-001 — la carte d\'encaissement dit de quoi le montant est fait', () => {
    it('rend la composition complète de la ligne (sauce, extras, formule, instruction)', () => {
        const text = build().text();

        // Le nom de l'article : déjà présent avant le correctif (témoin).
        expect(text).toContain('Menu (Frites + Boisson)');

        // Ce qui manquait — et qui est déjà dans la charge utile de l'API.
        expect(text, 'la sauce choisie').toContain('Algérienne');
        expect(text, 'le supplément payant facturé').toContain('Cheddar');
        expect(text, 'les composants de la formule (addons)').toContain('Frites');
        expect(text, 'les composants de la formule (addons)').toContain('Coca-Cola 33cl');
        expect(text, 'l\'instruction du client — invisible au comptoir').toContain('Sans oignons');
    });

    it('porte la quantité des suppléments (Cheddar ×2, pas « Cheddar »)', () => {
        expect(build().text()).toMatch(/Cheddar\s*×\s*2/);
    });

    it('lit la composition par le normaliseur canonique, pas en brut', () => {
        const vm = build().vm;
        // Forme ANCIENNE (rôles non inversés) : le brut aurait rendu l'inverse.
        expect(vm.normalizedVariations({ item_variations: [{ variation_name: 'Sauce', name: 'Ketchup' }] }))
            .toEqual([{ label: 'Sauce', name: 'Ketchup', quantity: 1 }]);
        // Forme INSTANTANÉ NF525 (attribute_name = intitulé, variation_name = choix).
        expect(vm.normalizedVariations({
            item_variations: [{ variation_id: 1, attribute_name: 'Sauce', variation_name: 'Algérienne', quantity: 1 }],
        })).toEqual([{ label: 'Sauce', name: 'Algérienne', quantity: 1 }]);
        // Entrées réduites à des ids : écartées, jamais rendues « : , ».
        expect(vm.normalizedExtras({ item_extras: [{ id: 9, quantity: 1 }] })).toEqual([]);
        expect(vm.normalizedAddons({ item_addons: null })).toEqual([]);
    });

    it('une ligne sans composition ne fabrique aucune ligne vide', () => {
        const nu = {
            id: 1, total: 5, order_items: [{ item_name: 'Coca', quantity: 1, instruction: null }],
        };
        const wrapper = build([nu]);
        expect(wrapper.text()).toContain('Coca');
        expect(wrapper.findAll('[data-testid="enc-item-composition"]')).toHaveLength(0);
    });
});
