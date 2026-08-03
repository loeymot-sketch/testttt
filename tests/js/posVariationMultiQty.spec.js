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

const multiItem = {
    id: 10,
    name: 'Tacos 4 viandes',
    thumb: '',
    description: 'Compose ton tacos',
    caution: '',
    offer: [],
    convert_price: 10,
    currency_price: '10.00 EUR',
    itemAttributes: [
        { id: 1, name: 'Viande', min_select: 4, max_select: 4, allow_repeat: true },
    ],
    variations: {
        1: [
            { id: 101, item_attribute_id: 1, name: 'Steak', convert_price: 0, currency_price: '0.00 EUR', price: 0 },
            { id: 102, item_attribute_id: 1, name: 'Poulet', convert_price: 0, currency_price: '0.00 EUR', price: 0 },
            { id: 103, item_attribute_id: 1, name: 'Cordon bleu', convert_price: 0, currency_price: '0.00 EUR', price: 0 },
            { id: 104, item_attribute_id: 1, name: 'Nuggets', convert_price: 0, currency_price: '0.00 EUR', price: 0 },
        ],
    },
    extras: [
        { id: 201, name: 'Sauce Algerienne', convert_price: 1, currency_price: '1.00 EUR' },
    ],
    addons: [],
};

const legacyItem = {
    id: 11,
    name: 'Tacos classique',
    thumb: '',
    description: 'Recette legacy',
    caution: '',
    offer: [],
    convert_price: 8,
    currency_price: '8.00 EUR',
    itemAttributes: [
        { id: 2, name: 'Taille', min_select: 1, max_select: 1, allow_repeat: false },
    ],
    variations: {
        2: [
            { id: 301, item_attribute_id: 2, name: 'Mega', convert_price: 0, currency_price: '0.00 EUR', price: 0 },
            { id: 302, item_attribute_id: 2, name: 'Maxi', convert_price: 1, currency_price: '1.00 EUR', price: 1 },
        ],
    },
    extras: [],
    addons: [],
};

function makeCartState() {
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

function canAddToCart(vm) {
    return vm.canAddToCart;
}

function seedVm(vm, item) {
    vm.item = clone(item);
    vm.resetTempState();
    vm.temp.name = item.name;
    vm.temp.image = item.thumb;
    vm.temp.item_id = item.id;
    vm.temp.quantity = 1;
    vm.temp.discount = 0;
    vm.temp.convert_price = item.convert_price;
    vm.temp.currency_price = item.currency_price;
    vm.totalPriceSetup();
}

describe('POS variation multi quantity', () => {
    it('stores 4x same variation in cart payload', () => {
        const vm = createVm();
        seedVm(vm, multiItem);

        const attribute = vm.item.itemAttributes[0];
        const steak = vm.item.variations[1][0];

        for (let i = 0; i < 4; i += 1) {
            vm.incrementVariation(attribute, steak);
        }

        expect(vm.buildPosCartMainPayload().item_variations).toEqual([
            { id: 101, item_attribute_id: 1, quantity: 4, variation_name: 'Viande', name: 'Steak' },
        ]);
    });

    it('stores a 2 plus 2 mixed selection', () => {
        const vm = createVm();
        seedVm(vm, multiItem);

        const attribute = vm.item.itemAttributes[0];
        const [steak, poulet] = vm.item.variations[1];

        vm.incrementVariation(attribute, steak);
        vm.incrementVariation(attribute, steak);
        vm.incrementVariation(attribute, poulet);
        vm.incrementVariation(attribute, poulet);

        expect(vm.buildPosCartMainPayload().item_variations).toEqual([
            { id: 101, item_attribute_id: 1, quantity: 2, variation_name: 'Viande', name: 'Steak' },
            { id: 102, item_attribute_id: 1, quantity: 2, variation_name: 'Viande', name: 'Poulet' },
        ]);
    });

    it('stores a 3 plus 1 mixed selection', () => {
        const vm = createVm();
        seedVm(vm, multiItem);

        const attribute = vm.item.itemAttributes[0];
        const [steak, poulet] = vm.item.variations[1];

        vm.incrementVariation(attribute, steak);
        vm.incrementVariation(attribute, steak);
        vm.incrementVariation(attribute, steak);
        vm.incrementVariation(attribute, poulet);

        expect(vm.buildPosCartMainPayload().item_variations).toEqual([
            { id: 101, item_attribute_id: 1, quantity: 3, variation_name: 'Viande', name: 'Steak' },
            { id: 102, item_attribute_id: 1, quantity: 1, variation_name: 'Viande', name: 'Poulet' },
        ]);
    });

    it('disables add to cart while minimum selection is not met', () => {
        const vm = createVm();
        seedVm(vm, multiItem);

        expect(vm.hasSelectionErrors()).toBe(true);
        expect(canAddToCart(vm)).toBe(false);
    });

    it('marks the attribute as capped once max selection is reached', () => {
        const vm = createVm();
        seedVm(vm, multiItem);

        const attribute = vm.item.itemAttributes[0];
        const steak = vm.item.variations[1][0];

        for (let i = 0; i < 4; i += 1) {
            vm.incrementVariation(attribute, steak);
        }

        expect(vm.isAttributeAtMax(attribute)).toBe(true);
    });

    it('removes a variation entry when decremented to zero', () => {
        const state = makeCartState();
        const item = {
            item_variations: [
                { id: 101, item_attribute_id: 1, quantity: 1, variation_name: 'Viande', name: 'Steak' },
            ],
        };

        posCart.mutations.setItemVariations(state, {
            item,
            attrId: 1,
            varId: 101,
            quantity: 0,
        });

        expect(item.item_variations).toEqual([]);
    });

    it('restores multi quantity selections when editing a cart line', async () => {
        const storeDispatch = vi.fn(() => Promise.resolve({ data: { data: clone(multiItem) } }));
        const vm = createVm(null, storeDispatch);

        vm.openEditFromCart({
            item_id: 10,
            name: multiItem.name,
            image: '',
            quantity: 1,
            discount: 0,
            convert_price: 10,
            currency_price: '10.00 EUR',
            instruction: '',
            item_variations: [
                { id: 101, item_attribute_id: 1, quantity: 3, variation_name: 'Viande', name: 'Steak' },
                { id: 102, item_attribute_id: 1, quantity: 1, variation_name: 'Viande', name: 'Poulet' },
            ],
            item_extras: [],
            pos_line_addons: [],
        }, 0);
        await flushPromises();

        expect(vm.getVariationQuantity(101)).toBe(3);
        expect(vm.getVariationQuantity(102)).toBe(1);
    });

    it('keeps legacy single select behavior with quantity one payload', () => {
        const vm = createVm();
        seedVm(vm, legacyItem);

        vm.initializeDefaultSelections();

        expect(vm.getVariationQuantity(301)).toBe(1);
        expect(canAddToCart(vm)).toBe(true);
        expect(vm.buildPosCartMainPayload().item_variations).toEqual([
            { id: 301, item_attribute_id: 2, quantity: 1, variation_name: 'Taille', name: 'Mega' },
        ]);
    });
});
