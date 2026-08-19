import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0));

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

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

const tileAvailable = {
    id: 42,
    name: 'Dispo',
    thumb: '',
    offer: [],
    currency_price: '5 EUR',
    is_available: true,
};

const tileUnavailable = {
    id: 43,
    name: 'Rupture',
    thumb: '',
    offer: [],
    currency_price: '5 EUR',
    is_available: false,
};

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

    // Mirror Vue's computed resolution so `canAddToCart` can read `catalogItemAvailable`.
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

function canAddToCart(vm) {
    return vm.canAddToCart;
}

function catalogItemAvailable(vm) {
    return vm.catalogItemAvailable;
}

function itemUnavailabilityBannerVisible(vm) {
    return vm.itemUnavailabilityBannerVisible;
}

describe('POS availability live guard (T11)', () => {
    it('available tile: click triggers item/details load (modal path)', async () => {
        const detail = clone(legacyItem);
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: detail } }));
        const wrapper = shallowMount(ItemComponent, {
            props: { items: [tileAvailable] },
            global: {
                stubs: { Swiper: true, SwiperSlide: true },
                mocks: {
                    $t: (k) => k,
                    $store: {
                        dispatch,
                        getters: {
                            'frontendSetting/lists': {
                                site_digit_after_decimal_point: 2,
                                site_default_currency_symbol: 'EUR',
                                site_currency_position: 'left',
                            },
                        },
                    },
                },
            },
        });

        await wrapper.vm.$nextTick();
        wrapper.vm.handleNativeTileClick({
            target: wrapper.find('.pos-item-tile').element,
            preventDefault: vi.fn(),
        });
        await flushPromises();

        expect(dispatch).toHaveBeenCalledWith('item/details', { id: 42, surface: 'pos' });
    });

    it('modal load normalizes missing collection fields', async () => {
        const detail = {
            id: 44,
            name: 'Bare item',
            thumb: '',
            description: '',
            convert_price: 6,
            currency_price: '6.00 EUR',
        };
        const vm = createVm(null, vi.fn(() => Promise.resolve({ data: { data: detail } })));
        // [T-CAISSE-1TAP 2026-08-19] Ce produit n'a AUCUNE option : il rejoint
        // désormais le panier en un seul appui au lieu d'ouvrir une modale vide.
        // On neutralise l'ajout pour n'observer ici que la normalisation, qui est
        // l'objet réel de ce cas.
        vm.addToCart = vi.fn();

        await vm.variationModalShow({ id: 44 });
        await flushPromises();

        expect(vm.item.offer).toEqual([]);
        expect(vm.item.addons).toEqual([]);
        expect(vm.item.extras).toEqual([]);
        expect(vm.item.itemAttributes).toEqual([]);
        expect(vm.item.variations).toEqual({});
    });

    /**
     * [T-CAISSE-1TAP 2026-08-19 · GOAL owner] Un produit sans la moindre option
     * (boisson, dessert) n'ouvre plus de modale : il part directement au panier.
     * Observé en direct : un Coca-Cola imposait une modale plein écran vide PUIS
     * un second clic — deux fois le travail en plein coup de feu.
     */
    it('produit SANS option : ajout direct au panier, aucune modale', async () => {
        const detail = {
            id: 52,
            name: 'Coca-Cola 33cl',
            thumb: '',
            convert_price: 1.9,
            currency_price: '1.90 EUR',
            itemAttributes: [],
            extras: [],
            addons: [],
        };
        const vm = createVm(null, vi.fn(() => Promise.resolve({ data: { data: detail } })));
        vm.addToCart = vi.fn();

        await vm.variationModalShow({ id: 52 });
        await flushPromises();

        expect(vm.addToCart).toHaveBeenCalled();
        expect(vm.$refs.itemVariationModal.classList.add).not.toHaveBeenCalledWith('active');
        expect(vm.wizardLoading, 'l\'overlay de chargement doit être relâché').toBe(false);
    });

    /**
     * SÛRETÉ : la moindre option doit continuer d'ouvrir le wizard — un sandwich
     * envoyé en cuisine sans son choix de viande serait bien pire que deux clics.
     */
    it('produit AVEC options : le wizard s\'ouvre toujours', async () => {
        const vm = createVm(null, vi.fn(() => Promise.resolve({ data: { data: clone(legacyItem) } })));
        vm.addToCart = vi.fn();

        await vm.variationModalShow({ id: 11 });
        await flushPromises();

        expect(vm.addToCart).not.toHaveBeenCalled();
        expect(vm.$refs.itemVariationModal.classList.add).toHaveBeenCalledWith('active');
    });

    it('modal load failure is visible instead of silently swallowing the click', async () => {
        const error = new Error('Forbidden');
        const vm = createVm(null, vi.fn(() => Promise.reject(error)));
        vm.showItemLoadError = vi.fn();

        await vm.variationModalShow({ id: 45 });
        await flushPromises();

        expect(vm.showItemLoadError).toHaveBeenCalledWith(error);
        expect(vm.$refs.itemVariationModal.classList.add).not.toHaveBeenCalled();
    });

    it('unavailable tile: has is-unavailable class and click does not load details', async () => {
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: clone(legacyItem) } }));
        const wrapper = shallowMount(ItemComponent, {
            props: { items: [tileUnavailable] },
            global: {
                stubs: { Swiper: true, SwiperSlide: true },
                mocks: {
                    $t: (k) => k,
                    $store: {
                        dispatch,
                        getters: {
                            'frontendSetting/lists': {
                                site_digit_after_decimal_point: 2,
                                site_default_currency_symbol: 'EUR',
                                site_currency_position: 'left',
                            },
                        },
                    },
                },
            },
        });

        const tile = wrapper.find('.pos-item-tile');
        expect(tile.classes()).toContain('is-unavailable');

        await tile.trigger('click');
        await flushPromises();

        expect(dispatch).not.toHaveBeenCalled();
    });

    it('modal with available item: add-to-cart computed is enabled when selections valid', () => {
        const vm = createVm();
        seedVm(vm, { ...legacyItem, is_available: true });
        vm.initializeDefaultSelections();
        expect(canAddToCart(vm)).toBe(true);
    });

    it('modal: when is_available flips to false, add disabled and banner visible', () => {
        const vm = createVm();
        seedVm(vm, { ...legacyItem, is_available: true });
        vm.initializeDefaultSelections();
        expect(canAddToCart(vm)).toBe(true);

        vm.syncItemAvailabilityFromBroadcast(11, false, 'out_of_stock');

        expect(canAddToCart(vm)).toBe(false);
        expect(itemUnavailabilityBannerVisible(vm)).toBe(true);
    });

    it('backward compat: item without is_available is treated as available', () => {
        const vm = createVm();
        const bare = { ...legacyItem };
        delete bare.is_available;
        seedVm(vm, bare);
        vm.initializeDefaultSelections();
        expect(catalogItemAvailable(vm)).toBe(true);
        expect(canAddToCart(vm)).toBe(true);
    });
});
