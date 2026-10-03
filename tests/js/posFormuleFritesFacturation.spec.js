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
import { posCart } from '../../resources/js/store/modules/posCart';

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}


// [GOAL CAISSE/CUISINE #6 2026-10-02] Les options PAYANTES d'une formule frites affichées par le
// wizard caisse (« Grande Portion » +1,00, « Cheddar Fondu » +1,00, 2ᵉ sauce frites +0,50) doivent
// partir comme IDS d'extras — sinon PricingService (SSOT) ne les voit pas et ne les facture jamais.
import {
    extraFritesSauceQuantity,
    findSauceSupplementExtra,
    formulaOptionExtras,
} from '../../resources/js/helpers/posFormulaBilling';

function _unusedCartState() {
    return {
        lists: [],
        subtotal: 0,
        discount: 0,
        restoredFromStorage: false,
    };
}

function createVm(item = null, storeDispatch = vi.fn(() => Promise.resolve())) {
    const data = ItemComponent.data.call({});
    const vm = {
        ...data,
        item: item ? clone(item) : null,
        $t: (key) => key,
        $store: {
            dispatch: storeDispatch,
            getters: {
                'frontendSetting/lists': {
                    site_digit_after_decimal_point: 2,
                    site_default_currency_symbol: 'EUR',
                    site_currency_position: 'left',
                },
            },
        },
        $refs: {
            itemVariationModal: {
                dataset: {},
                setAttribute: vi.fn(),
                removeAttribute: vi.fn(),
                classList: { add: vi.fn(), remove: vi.fn() },
            },
            itemInfoModal: {
                classList: { add: vi.fn(), remove: vi.fn() },
            },
        },
    };

    Object.entries(ItemComponent.methods).forEach(([name, fn]) => {
        vm[name] = fn.bind(vm);
    });

    // Mirror Vue computed chain (T11 catalogItemAvailable) for plain `this` shims.
    Object.defineProperty(vm, 'catalogItemAvailable', {
        get() {
            return ItemComponent.computed.catalogItemAvailable.call(vm);
        },
        enumerable: true,
    });
    Object.defineProperty(vm, 'itemUnavailabilityBannerVisible', {
        get() {
            return ItemComponent.computed.itemUnavailabilityBannerVisible.call(vm);
        },
        enumerable: true,
    });
    Object.defineProperty(vm, 'canAddToCart', {
        get() {
            return ItemComponent.computed.canAddToCart.call(vm);
        },
        enumerable: true,
    });

    return vm;
}


const parent = {
    id: 26,
    name: 'Tacos M',
    thumb: '',
    description: '',
    caution: '',
    offer: [],
    convert_price: 8.5,
    currency_price: '8.50 EUR',
    itemAttributes: [],
    variations: {},
    extras: [
        { id: 489, name: 'Sauce supplémentaire', convert_price: 0.5, currency_price: '0.50 EUR', status: 5 },
    ],
    addons: [
        {
            id: 7, item_id: 26, item_addon_id: 1, addon_item_id: 1, addon_item_name: 'Menu (Frites + Boisson)',
            addon_item_convert_price: 2.5,
            addon_item_extras: [
                { id: 234, name: 'Grande Portion', group_label: null, price: 1, convert_price: 1 },
                { id: 235, name: 'Cheddar Fondu', group_label: null, price: 1, convert_price: 1 },
            ],
        },
    ],
};

const wizardAddonLine = (restore) => ({
    parent_addon_id: '7',
    name: 'Menu (Frites + Boisson)',
    item_id: 1,
    quantity: 1,
    convert_price: 2.5,
    total_price: 2.5,
    item_extras: { extras: [], names: [] },
    menu_restore: restore,
});

function vmWithWizard(lines, restoreTotal = 0) {
    const vm = createVm(parent);
    vm.resetTempState();
    vm.temp.name = parent.name;
    vm.temp.item_id = parent.id;
    vm.temp.quantity = 1;
    vm.temp.convert_price = parent.convert_price;
    vm.$refs.itemVariationModal.dataset = { wizardTotal: String(restoreTotal) };
    vm.$refs.itemVariationModal.getAttribute = (name) => (name === 'data-wizard-pos-line-addons' ? JSON.stringify(lines) : null);
    return vm;
}

describe('helper — options de formule facturables', () => {
    const catalog = parent.addons[0];

    it('Grande Portion + Cheddar Fondu → deux ids d\'extras sur la ligne formule', () => {
        // [GOAL REMARQUES 2026-10-03 · R-041] + `unit_price` (prix catalogue, AFFICHAGE / « offrable »
        // seulement — jamais transmis : voir le cas « la ligne envoyée » de posOffertOptionFormule.spec).
        expect(formulaOptionExtras({ fritesGrande: true, fritesCheddar: true }, catalog, 1)).toEqual([
            { id: 234, item_id: 1, name: 'Grande Portion', quantity: 1, unit_price: 1 },
            { id: 235, item_id: 1, name: 'Cheddar Fondu', quantity: 1, unit_price: 1 },
        ]);
    });
    it('rien de coché → rien n\'est ajouté (aucun prix inventé)', () => {
        expect(formulaOptionExtras({ fritesGrande: false, fritesCheddar: false }, catalog, 1)).toEqual([]);
        expect(formulaOptionExtras(null, catalog, 1)).toEqual([]);
    });
    it('id introuvable (API ancienne) → pas d\'entrée fantôme', () => {
        expect(formulaOptionExtras({ fritesGrande: true }, { addon_item_extras: [] }, 1)).toEqual([]);
        expect(formulaOptionExtras({ fritesGrande: true }, {}, 1)).toEqual([]);
    });
    it('la 1ère sauce frites est offerte, chaque suivante est facturée', () => {
        expect(extraFritesSauceQuantity({ sauceFritesOrder: [] })).toBe(0);
        expect(extraFritesSauceQuantity({ sauceFritesOrder: ['sf_1'] })).toBe(0);
        expect(extraFritesSauceQuantity({ sauceFritesOrder: ['sf_1', 'sf_2', 'sf_3'] })).toBe(2);
        expect(extraFritesSauceQuantity(undefined)).toBe(0);
    });
    it('retrouve l\'extra générique du parent', () => {
        expect(findSauceSupplementExtra(parent.extras).id).toBe(489);
        expect(findSauceSupplementExtra([])).toBeNull();
    });
});

describe('payload panier POS — ce qui est affiché est ce qui part au backend', () => {
    it('Grande Portion + Cheddar Fondu partent en item_extras de la ligne formule', () => {
        const vm = vmWithWizard([wizardAddonLine({ fritesGrande: true, fritesCheddar: true, sauceFritesOrder: [] })], 12.5);

        const payload = vm.buildPosCartMainPayload();

        expect(payload.pos_line_addons).toHaveLength(1);
        expect(payload.pos_line_addons[0].item_extras.map((e) => e.id)).toEqual([234, 235]);
        // Aucun prix client : seulement des identifiants.
        payload.pos_line_addons[0].item_extras.forEach((e) => expect(e).not.toHaveProperty('price'));
    });

    it('3 sauces frites → 2 « Sauce supplémentaire » facturées sur le produit parent', () => {
        const vm = vmWithWizard([wizardAddonLine({ fritesGrande: false, fritesCheddar: false, sauceFritesOrder: ['sf_1', 'sf_2', 'sf_3'] })], 9.5);

        const payload = vm.buildPosCartMainPayload();

        const sauce = payload.item_extras.find((e) => e.id === 489);
        expect(sauce, 'extra « Sauce supplémentaire » du parent').toBeTruthy();
        expect(sauce.quantity).toBe(2);
    });

    it('1 seule sauce frites → rien à facturer (la 1ère est offerte)', () => {
        const vm = vmWithWizard([wizardAddonLine({ fritesGrande: false, fritesCheddar: false, sauceFritesOrder: ['sf_1'] })], 8.5);

        expect(vm.buildPosCartMainPayload().item_extras.find((e) => e.id === 489)).toBeUndefined();
    });

    it('idempotent : reconstruire le payload deux fois ne double pas la quantité', () => {
        const vm = vmWithWizard([wizardAddonLine({ sauceFritesOrder: ['sf_1', 'sf_2'] })], 9);

        vm.buildPosCartMainPayload();
        const second = vm.buildPosCartMainPayload();

        expect(second.item_extras.find((e) => e.id === 489).quantity).toBe(1);
    });

    it('s\'ajoute à la 2ᵉ sauce du produit déjà portée par le wizard', () => {
        const vm = vmWithWizard([wizardAddonLine({ sauceFritesOrder: ['sf_1', 'sf_2'] })], 9);
        vm.temp.item_extras = [{ id: 489, quantity: 1, name: 'Sauce supplémentaire' }];

        expect(vm.buildPosCartMainPayload().item_extras.find((e) => e.id === 489).quantity).toBe(2);
    });
});
