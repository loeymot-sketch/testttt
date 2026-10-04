import { describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
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

import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function createVm() {
    const data = ItemComponent.data.call({});
    const vm = {
        ...data,
        item: null,
        $t: (key) => key,
        $store: { dispatch: vi.fn(() => Promise.resolve()), getters: { 'frontendSetting/lists': {} } },
        $refs: {
            itemVariationModal: { dataset: {}, setAttribute: vi.fn(), removeAttribute: vi.fn(), classList: { add: vi.fn(), remove: vi.fn() } },
            itemInfoModal: { classList: { add: vi.fn(), remove: vi.fn() } },
        },
    };
    Object.entries(ItemComponent.methods).forEach(([name, fn]) => { vm[name] = fn.bind(vm); });
    return vm;
}

/**
 * [Root cause 2026-09-23, owner: "test-e2e la caisse et lors de modifier ou
 * dupliquer un produit en panier et le modifier assure"]
 *
 * Repro réelle trouvée en testant le flux "dupliquer puis modifier" : un
 * Tacos M (1 seule viande choisie par le client + 1 sauce) soumettait à
 * l'encaissement RÉEL une composition avec 3 viandes — "Poulet mariné"
 * (choisi), PLUS "Poulet mariné" sous l'étape optionnelle "Viande 2" et
 * "Cordon Bleu" sous "Viande 3", jamais choisis. Ça atterrissait dans
 * composition_snapshot (immuable, lu par le ticket cuisine) — la cuisine
 * aurait préparé 3 viandes pour une commande à 1 viande.
 *
 * Deux bugs empilés, tous deux dans ItemComponent.vue (NON gelé) :
 *
 * 1. `initializeDefaultSelections()` ne vérifiait QUE `!config.isMulti`
 *    (choix unique) — jamais `config.minSelect` — donc tout attribut à choix
 *    unique recevait un défaut, MÊME optionnel (min_select=0).
 *
 * 2. `getAttributeConfig()` calculait minSelect via `normalizeId(min_select)`
 *    — un helper conçu pour des clés étrangères (jamais légitimement 0) qui
 *    traite TOUT 0 comme absent. `min_select=0` (vraiment "optionnel")
 *    devenait donc `null` puis retombait sur le défaut `(isMulti ? 0 : 1)` =
 *    1 pour un choix unique — un attribut réellement optionnel était donc
 *    TOUJOURS traité comme requis, quelle que soit sa vraie valeur en base.
 *    Ce 2e bug rendait le 1er fix inopérant tant qu'il n'était pas corrigé
 *    aussi : `config.minSelect` valait 1 même quand la base disait 0.
 */
describe('ItemComponent — un créneau optionnel (min_select=0) ne reçoit jamais de défaut fantôme', () => {
    const itemWithOptionalSlot = {
        id: 26,
        name: 'Tacos M',
        thumb: '',
        description: '',
        caution: '',
        offer: [],
        convert_price: 6.9,
        currency_price: '6.90 EUR',
        itemAttributes: [
            { id: 1, name: 'Viande 1', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 2, name: 'Viande 2', min_select: 0, max_select: 1, allow_repeat: false },
            { id: 3, name: 'Viande 3', min_select: 0, max_select: 1, allow_repeat: false },
            { id: 5, name: 'Sauce (1ère Gratuite)', min_select: 1, max_select: 1, allow_repeat: false },
        ],
        variations: {
            1: [{ id: 43, item_attribute_id: 1, name: 'Poulet mariné', convert_price: 0, currency_price: '0.00 EUR', price: 0 }],
            2: [{ id: 1175, item_attribute_id: 2, name: 'Poulet mariné', convert_price: 0, currency_price: '0.00 EUR', price: 0 }],
            3: [{ id: 1185, item_attribute_id: 3, name: 'Cordon Bleu', convert_price: 0, currency_price: '0.00 EUR', price: 0 }],
            5: [{ id: 317, item_attribute_id: 5, name: 'Ketchup', convert_price: 0, currency_price: '0.00 EUR', price: 0 }],
        },
        extras: [],
        addons: [],
    };

    it('getAttributeConfig lit min_select=0 comme réellement 0, jamais comme "absent"', () => {
        const vm = createVm();
        vm.item = clone(itemWithOptionalSlot);
        const viande2 = vm.item.itemAttributes[1];
        expect(vm.getAttributeConfig(viande2).minSelect).toBe(0);
    });

    it('getAttributeConfig continue de traiter min_select=1 comme requis (non régressé)', () => {
        const vm = createVm();
        vm.item = clone(itemWithOptionalSlot);
        const viande1 = vm.item.itemAttributes[0];
        expect(vm.getAttributeConfig(viande1).minSelect).toBe(1);
    });

    it('initializeDefaultSelections ne pré-remplit PAS les créneaux optionnels (Viande 2/3)', () => {
        const vm = createVm();
        vm.item = clone(itemWithOptionalSlot);
        vm.temp.item_variations = [];

        vm.initializeDefaultSelections();

        const attrIds = vm.temp.item_variations.map((entry) => entry.item_attribute_id).sort();
        expect(attrIds, 'seuls Viande 1 (requis) et Sauce (requis) doivent recevoir un défaut').toEqual([1, 5]);
    });

    it('initializeDefaultSelections pré-remplit toujours les attributs réellement requis (comportement inchangé)', () => {
        const vm = createVm();
        vm.item = clone(itemWithOptionalSlot);
        vm.temp.item_variations = [];

        vm.initializeDefaultSelections();

        const viande1Entry = vm.temp.item_variations.find((e) => e.item_attribute_id === 1);
        const sauceEntry = vm.temp.item_variations.find((e) => e.item_attribute_id === 5);
        expect(viande1Entry?.id).toBe(43);
        expect(sauceEntry?.id).toBe(317);
    });

    it('sélectionner UNIQUEMENT Viande 1 laisse Viande 2/3 vides — jamais de 3 viandes fantômes', () => {
        const vm = createVm();
        vm.item = clone(itemWithOptionalSlot);
        vm.temp.item_variations = [];
        vm.initializeDefaultSelections();

        // Le client choisit explicitement Poulet mariné sous Viande 1 (déjà le défaut ici,
        // mais on simule le clic réel comme le ferait onWizardBridgeSelect/setVariationQuantity).
        const viande1 = vm.item.itemAttributes[0];
        const poulet = vm.item.variations['1'][0];
        vm.setVariationQuantity(viande1, poulet, 1);

        expect(vm.temp.item_variations.length, 'jamais de 3e/4e ligne fantôme').toBe(2);
        expect(vm.temp.item_variations).toEqual(expect.arrayContaining([
            { id: 43, item_attribute_id: 1, quantity: 1, variation_name: 'Viande 1', name: 'Poulet mariné' },
            { id: 317, item_attribute_id: 5, quantity: 1, variation_name: 'Sauce (1ère Gratuite)', name: 'Ketchup' },
        ]));
    });
});
