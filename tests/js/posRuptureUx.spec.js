import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

const tileAvailable = {
  id: 42,
  name: 'Dispo',
  offer: [],
  currency_price: '5 EUR',
  convert_price: 5,
  is_available: true,
  itemAttributes: [],
  variations: {},
  extras: [],
  addons: [],
  item: {},
};

const tileUnavailable = {
  ...tileAvailable,
  id: 43,
  name: 'Rupture',
  is_available: false,
};

const frontendStore = {
  dispatch: vi.fn(() => Promise.resolve({ data: { data: tileAvailable } })),
  getters: {
    'frontendSetting/lists': {
      site_digit_after_decimal_point: 2,
      site_default_currency_symbol: 'EUR',
      site_currency_position: 'left',
    },
  },
};

const translate = (key) => ({
  'pos.item_86_d': 'Épuisé',
  'label.add_to_cart': 'Ajouter au panier',
  'pos.item_unavailable_during_edit': 'Cet article vient de devenir indisponible',
  'a11y.add_item': 'Ajouter',
}[key] || key);

describe('POS rupture UX (P2-C)', () => {
  it('unavailable tile has badge and does not open modal/details', async () => {
    const dispatch = vi.fn(() => Promise.resolve({ data: { data: tileAvailable } }));

    const wrapper = shallowMount(ItemComponent, {
      props: { items: [tileUnavailable] },
      global: {
        stubs: { Swiper: true, SwiperSlide: true },
        mocks: {
          $t: translate,
          $store: {
            ...frontendStore,
            dispatch,
          },
        },
      },
    });

    const tile = wrapper.find('.pos-item-tile');
    const badge = wrapper.find('.pos-item-86-badge');
    expect(tile.classes()).toContain('is-unavailable');
    expect(tile.attributes('aria-disabled')).toBe('true');
    expect(tile.attributes('disabled')).toBeDefined();
    expect(badge.exists()).toBe(true);
    expect(badge.text()).toBe('Épuisé');

    await tile.trigger('click');
    await flushPromises();

    expect(dispatch).not.toHaveBeenCalled();
  });

  it('when item becomes unavailable while modal open, add-to-cart is disabled and warning banner is shown', async () => {
    const wrapper = shallowMount(ItemComponent, {
      props: { items: [tileAvailable] },
      global: {
        stubs: { Swiper: true, SwiperSlide: true },
        mocks: {
          $t: translate,
          $store: {
            ...frontendStore,
          },
        },
      },
    });

    // Seed modal-ready state.
    wrapper.vm.item = {
      ...tileAvailable,
      is_available: true,
      availability_reason: 'stock',
    };
    await wrapper.vm.$nextTick();
    wrapper.vm.temp = {
      ...wrapper.vm.temp,
      item_id: tileAvailable.id,
      quantity: 1,
      convert_price: Number(tileAvailable.convert_price),
      currency_price: tileAvailable.currency_price,
      discount: 0,
      item_variations: [],
      item_extras: [],
      item_variation_total: 0,
      item_extra_total: 0,
      total_price: Number(tileAvailable.convert_price),
    };
    wrapper.vm.totalPriceSetup();
    await wrapper.vm.$nextTick();

    expect(wrapper.find('.pos-add-to-cart-sticky button[type="button"]').attributes('disabled')).toBeUndefined();

    wrapper.vm.syncItemAvailabilityFromBroadcast(tileAvailable.id, false, 'out_of_stock');
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.catalogItemAvailable).toBe(false);
    expect(wrapper.vm.canAddToCart).toBe(false);
    expect(wrapper.vm.itemUnavailabilityBannerVisible).toBe(true);
    const addButton = wrapper.find('.pos-add-to-cart-sticky button[type="button"]');
    expect(addButton.attributes('disabled')).toBeDefined();

    const warning = wrapper.find('.pos-add-to-cart-sticky .alert');
    expect(warning.exists()).toBe(true);
    expect(warning.text()).toContain('indisponible');
  });

  it('blocks unavailable POS wizard choices while keeping available choices selectable', async () => {
    const wrapper = shallowMount(ItemComponent, {
      props: { items: [tileAvailable] },
      global: {
        stubs: { Swiper: true, SwiperSlide: true },
        mocks: {
          $t: translate,
          $store: frontendStore,
        },
      },
    });

    const attribute = { id: 7, name: 'Crudités', min_select: 1, max_select: 1, allow_repeat: false };
    const unavailableVariation = {
      id: 701,
      item_attribute_id: attribute.id,
      name: 'Tomate',
      price: 0,
      convert_price: 0,
      currency_price: '0 EUR',
      is_available: false,
      unavailable_reason: 'stock_rupture',
    };
    const availableVariation = {
      ...unavailableVariation,
      id: 702,
      name: 'Salade',
      is_available: true,
      unavailable_reason: null,
    };
    const unavailableExtra = {
      id: 801,
      name: 'Cheddar',
      price: 1,
      convert_price: 1,
      currency_price: '1 EUR',
      is_available: false,
      unavailable_reason: 'stock_rupture',
    };
    const availableExtra = {
      ...unavailableExtra,
      id: 802,
      name: 'Oignons',
      is_available: true,
      unavailable_reason: null,
    };
    const unavailableAddon = {
      id: 901,
      addon_item_id: 77,
      addon_item_name: 'Boisson',
      item_addon_id: 77,
      thumb: '',
      offer: [],
      variations: {},
      variation_names: [],
      addon_item_currency_price: '2 EUR',
      addon_item_convert_price: 2,
      total_currency_price: '2 EUR',
      total_convert_price: 2,
      variation_total_convert_price: 0,
      caution: '',
      is_available: false,
      unavailable_reason: 'stock_rupture',
    };
    const availableAddon = {
      ...unavailableAddon,
      id: 902,
      addon_item_id: 78,
      item_addon_id: 78,
      addon_item_name: 'Dessert',
      is_available: true,
      unavailable_reason: null,
    };

    wrapper.vm.item = {
      ...tileAvailable,
      itemAttributes: [attribute],
      variations: { [attribute.id]: [unavailableVariation, availableVariation] },
      extras: [unavailableExtra, availableExtra],
      addons: [unavailableAddon, availableAddon],
    };
    wrapper.vm.resetTempState();
    wrapper.vm.addonQuantity = { [unavailableAddon.id]: 1, [availableAddon.id]: 1 };
    wrapper.vm.temp = {
      ...wrapper.vm.temp,
      item_id: tileAvailable.id,
      quantity: 1,
      convert_price: tileAvailable.convert_price,
      currency_price: tileAvailable.currency_price,
      total_price: tileAvailable.convert_price,
    };

    wrapper.vm.setVariationQuantity(attribute, unavailableVariation, 1);
    wrapper.vm.setExtraQuantity(unavailableExtra, 1);
    wrapper.vm.changeAddon(unavailableAddon);
    wrapper.vm.addonQuantityIncrement(unavailableAddon.id);

    expect(wrapper.vm.temp.item_variations).toEqual([]);
    expect(wrapper.vm.temp.item_extras).toEqual([]);
    expect(wrapper.vm.addons[unavailableAddon.id]).toBeUndefined();
    expect(wrapper.vm.addonQuantity[unavailableAddon.id]).toBe(1);

    wrapper.vm.setVariationQuantity(attribute, availableVariation, 1);
    wrapper.vm.setExtraQuantity(availableExtra, 1);
    wrapper.vm.changeAddon(availableAddon);
    wrapper.vm.addonQuantityIncrement(availableAddon.id);

    expect(wrapper.vm.temp.item_variations).toMatchObject([
      { id: availableVariation.id, item_attribute_id: attribute.id, quantity: 1 },
    ]);
    expect(wrapper.vm.temp.item_extras).toMatchObject([
      { id: availableExtra.id, quantity: 1 },
    ]);
    expect(wrapper.vm.addons[availableAddon.id]).toMatchObject({
      item_id: availableAddon.item_addon_id,
      quantity: 2,
    });

    wrapper.vm.initializeDefaultSelections();
    expect(wrapper.vm.getVariationQuantity(unavailableVariation.id)).toBe(0);
    expect(wrapper.vm.getVariationQuantity(availableVariation.id)).toBe(1);
  });

  it('removes unavailable restored POS selections before rebuilding cart payload', async () => {
    const wrapper = shallowMount(ItemComponent, {
      props: { items: [tileAvailable] },
      global: {
        stubs: { Swiper: true, SwiperSlide: true },
        mocks: {
          $t: translate,
          $store: frontendStore,
        },
      },
    });

    const attribute = { id: 7, name: 'Sauces', min_select: 0, max_select: 2, allow_repeat: true };
    const unavailableVariation = {
      id: 701,
      item_attribute_id: attribute.id,
      name: 'Sauce rupture',
      price: 0,
      convert_price: 0,
      is_available: false,
    };
    const availableVariation = {
      ...unavailableVariation,
      id: 702,
      name: 'Sauce disponible',
      is_available: true,
    };
    const unavailableExtra = { id: 801, name: 'Cheddar rupture', convert_price: 1, is_available: false };
    const availableExtra = { id: 802, name: 'Cheddar disponible', convert_price: 1, is_available: true };
    const unavailableAddon = {
      id: 901,
      item_addon_id: 77,
      addon_item_name: 'Boisson rupture',
      thumb: '',
      offer: [],
      variations: {},
      variation_names: [],
      addon_item_currency_price: '2 EUR',
      addon_item_convert_price: 2,
      total_currency_price: '2 EUR',
      total_convert_price: 2,
      variation_total_convert_price: 0,
      caution: '',
      is_available: false,
    };
    const availableAddon = { ...unavailableAddon, id: 902, item_addon_id: 78, addon_item_name: 'Dessert disponible', is_available: true };

    wrapper.vm.item = {
      ...tileAvailable,
      itemAttributes: [attribute],
      variations: { [attribute.id]: [unavailableVariation, availableVariation] },
      extras: [unavailableExtra, availableExtra],
      addons: [unavailableAddon, availableAddon],
    };
    wrapper.vm.temp = {
      ...wrapper.vm.temp,
      item_id: tileAvailable.id,
      quantity: 1,
      convert_price: tileAvailable.convert_price,
      currency_price: tileAvailable.currency_price,
      item_variations: [
        { id: unavailableVariation.id, item_attribute_id: attribute.id, quantity: 1 },
        { id: availableVariation.id, item_attribute_id: attribute.id, quantity: 1 },
      ],
      item_extras: [
        { id: unavailableExtra.id, quantity: 1 },
        { id: availableExtra.id, quantity: 1 },
      ],
      total_price: tileAvailable.convert_price,
    };
    wrapper.vm.addons = {
      [unavailableAddon.id]: { item_id: unavailableAddon.item_addon_id, quantity: 1, total_price: 2 },
      [availableAddon.id]: { item_id: availableAddon.item_addon_id, quantity: 1, total_price: 2 },
    };
    wrapper.vm.addonQuantity = { [unavailableAddon.id]: 1, [availableAddon.id]: 1 };

    wrapper.vm.pruneUnavailableRestoredSelections();
    const payload = wrapper.vm.buildPosCartMainPayload();

    expect(payload.item_variations.map((entry) => entry.id)).toEqual([availableVariation.id]);
    expect(payload.item_extras.map((entry) => entry.id)).toEqual([availableExtra.id]);
    expect(payload.pos_line_addons.map((entry) => String(entry.parent_addon_id))).toEqual([String(availableAddon.id)]);
  });
});
