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
 * [ULTRA-AUDIT 2026-09-26 · P0-01, rapport externe Codex] "Tacos XL : 3 viandes incluses
 * + Nuggets/Tenders/Fricadelle + Ketchup/Mayonnaise = 18,90€ à l'ajout. À la réouverture
 * 'Modifier', la modal recharge une seule viande et 13,90€, tandis que le panier reste
 * à 18,90€."
 *
 * Root cause trouvée par lecture de code (buildWizardRestorePayload, ItemComponent.vue) :
 * ce projet a DEUX formes de composition_snapshot / item_variations selon leur origine
 * (le commentaire du code le documente lui-même) :
 *   - forme POS (native, posée par setVariationQuantity) : `variation_name`=ATTRIBUT
 *     ("Viande 2"), `name`=VALEUR ("Poulet mariné").
 *   - forme snapshot KDS (celle qu'un cartLine récupère après un aller-retour serveur,
 *     ex. quote/prix recalculé, ou une commande rechargée) : `attribute_name`=ATTRIBUT,
 *     `variation_name`=VALEUR — l'INVERSE.
 *
 * `attrName` était calculé par `variationEntry.variation_name || variationEntry.attribute_name
 * || ...` — testant `variation_name` EN PREMIER. Pour une entrée en forme KDS, ce champ
 * contient la VALEUR ("Poulet mariné"), jamais l'attribut : `attrLower.includes('viande')`
 * devient donc FAUX, la branche entière de correspondance est sautée, et CE choix disparaît
 * silencieusement de la restauration — reproduit ici avec 3 viandes distinctes en forme KDS,
 * dont une seule ("Nuggets", qui ne contient "viande" nulle part non plus, mais servait à
 * documenter que même le nom du choix ne sauve jamais la branche) survivrait par accident
 * si son NOM de choix contenait le mot "viande" — ce qui n'est structurellement jamais le
 * cas pour un nom de plat réel, donc TOUJOURS 0 des 3 restaurées en pratique côté viande
 * pour une ligne 100% en forme KDS ; le rapport en montre 1/3 restaurée probablement parce
 * que sa ligne réelle mélangeait les deux formes selon l'historique de la commande.
 */
describe('ItemComponent.buildWizardRestorePayload — attribute_name doit primer sur variation_name (forme snapshot KDS)', () => {
    const tacosXL = {
        id: 234,
        name: 'Tacos XL',
        itemAttributes: [
            { id: 1, name: 'Viande 1', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 2, name: 'Viande 2', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 3, name: 'Viande 3', min_select: 1, max_select: 1, allow_repeat: false },
            { id: 5, name: 'Sauce (1ère Gratuite)', min_select: 1, max_select: 1, allow_repeat: false },
        ],
        variations: {
            1: [{ id: 780, item_attribute_id: 1, name: 'Nuggets', price: 0 }],
            2: [{ id: 788, item_attribute_id: 2, name: 'Tenders', price: 0 }],
            3: [{ id: 796, item_attribute_id: 3, name: 'Fricadelle', price: 0 }],
            5: [
                { id: 799, item_attribute_id: 5, name: 'Ketchup', price: 0 },
                { id: 798, item_attribute_id: 5, name: 'Mayonnaise', price: 0 },
            ],
        },
    };

    // Forme SNAPSHOT KDS : `attribute_name`=attribut, `variation_name`=valeur — celle
    // qu'un cartLine peut porter après un aller-retour serveur (le cas réel du rapport).
    const cartLineKdsShape = {
        name: 'Tacos XL',
        quantity: 1,
        instruction: '',
        item_variations: [
            { id: 780, item_attribute_id: 1, quantity: 1, attribute_name: 'Viande 1', variation_name: 'Nuggets' },
            { id: 788, item_attribute_id: 2, quantity: 1, attribute_name: 'Viande 2', variation_name: 'Tenders' },
            { id: 796, item_attribute_id: 3, quantity: 1, attribute_name: 'Viande 3', variation_name: 'Fricadelle' },
            { id: 799, item_attribute_id: 5, quantity: 1, attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Ketchup' },
        ],
    };

    it('restaure les 3 viandes (pas 1 seule) quand la ligne panier est en forme snapshot KDS', () => {
        const vm = createVm();
        vm.item = clone(tacosXL);

        const restore = vm.buildWizardRestorePayload(clone(cartLineKdsShape), vm.item);

        expect(Object.keys(restore.viandes), 'les 3 viandes doivent être restaurées, pas 1 seule').toHaveLength(3);
        expect(restore.viandes['v_780']).toBe(1);
        expect(restore.viandes['v_788']).toBe(1);
        expect(restore.viandes['v_796']).toBe(1);
    });

    it('restaure aussi la sauce en forme snapshot KDS (même branche, même bug)', () => {
        const vm = createVm();
        vm.item = clone(tacosXL);

        const restore = vm.buildWizardRestorePayload(clone(cartLineKdsShape), vm.item);

        expect(Object.keys(restore.sauces)).toHaveLength(1);
        expect(restore.sauces['s_799']).toBe(true);
    });

    it('ne régresse pas la forme POS native (variation_name=attribut, name=valeur, sans attribute_name)', () => {
        const vm = createVm();
        vm.item = clone(tacosXL);
        const cartLineNativeShape = {
            name: 'Tacos XL',
            quantity: 1,
            instruction: '',
            item_variations: [
                { id: 780, item_attribute_id: 1, quantity: 1, variation_name: 'Viande 1', name: 'Nuggets' },
                { id: 788, item_attribute_id: 2, quantity: 1, variation_name: 'Viande 2', name: 'Tenders' },
                { id: 796, item_attribute_id: 3, quantity: 1, variation_name: 'Viande 3', name: 'Fricadelle' },
            ],
        };

        const restore = vm.buildWizardRestorePayload(cartLineNativeShape, vm.item);

        expect(Object.keys(restore.viandes)).toHaveLength(3);
        expect(restore.viandes['v_780']).toBe(1);
        expect(restore.viandes['v_788']).toBe(1);
        expect(restore.viandes['v_796']).toBe(1);
    });
});
