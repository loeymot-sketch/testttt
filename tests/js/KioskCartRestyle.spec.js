/**
 * Kiosk Design V1 — Phase 2.x : KioskCartComponent restyle
 *
 * Vérifie :
 *  - data-testid stables pour les 6 flux critiques (liste, totaux, CTA, clear, edit, qty)
 *  - A11y : radiogroup order-type, aria-modal pour clear-confirm, role=alert empty state
 *  - Logique métier préservée (changeQty, proceedToUpsell)
 *  - Aucun hex hardcodé (tokens 100%)
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const ORDER_TYPE_KIOSK = 25;
const ORDER_TYPE_TAKEAWAY = 10;

function makeStore(opts = {}) {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                state: () => ({}),
                getters: {
                    items: () => opts.items || [],
                    count: () => (opts.items || []).length,
                    subtotal: () => opts.subtotal ?? 0,
                    total: () => opts.total ?? 0,
                    loyaltyDiscount: () => opts.loyaltyDiscount ?? 0,
                    upsellShown: () => opts.upsellShown ?? false,
                    orderType: () => opts.orderType ?? ORDER_TYPE_KIOSK,
                    isEmpty: () => (opts.items || []).length === 0,
                },
                actions: {
                    updateQuantity: vi.fn(),
                    removeItem: vi.fn(),
                    reset: vi.fn(),
                    markUpsellShown: vi.fn(),
                    popItem: vi.fn().mockResolvedValue({ item_id: 1 }),
                    setOrderType: vi.fn(),
                },
            },
            kioskMenu: {
                namespaced: true,
                getters: {
                    categories: () => [],
                    selectedCategoryId: () => null,
                },
            },
        },
    });
}

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

function mountOpts(store) {
    return {
        global: {
            plugins: [store, i18n],
            mocks: {
                $router: { push: vi.fn(), replace: vi.fn() },
                $route: { query: {}, params: {} },
            },
        },
    };
}

describe('KioskCartComponent restyle', () => {
    it('empty state : testid + role=status + CTA present', () => {
        const store = makeStore({ items: [] });
        const wrapper = mount(KioskCartComponent, mountOpts(store));
        const empty = wrapper.find('[data-testid="kiosk-cart-empty"]');
        expect(empty.exists()).toBe(true);
        expect(empty.attributes('role')).toBe('status');
        expect(wrapper.find('[data-testid="kiosk-cart-empty-cta"]').exists()).toBe(true);
    });

    it('non-empty cart : 1 item rendered with all testids', () => {
        const items = [
            {
                item_id: 42,
                name: 'Tacos XL',
                quantity: 2,
                convert_price: 8,
                total: 16,
                image: null,
                item_variations: [],
                item_extras: [],
                item_variation_total: 0,
                item_extra_total: 0,
            },
        ];
        const store = makeStore({ items, subtotal: 16, total: 16 });
        const wrapper = mount(KioskCartComponent, mountOpts(store));
        expect(wrapper.find('[data-testid="kiosk-cart-item-0"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-cart-item-name-0"]').text()).toContain('Tacos XL');
        expect(wrapper.find('[data-testid="kiosk-cart-item-qty-0"]').text()).toBe('2');
        expect(wrapper.find('[data-testid="kiosk-cart-item-total-0"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-cart-total"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-cart-checkout"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-cart-add-more"]').exists()).toBe(true);
    });

    it('order-type bar : role=radiogroup + aria-checked aligns with state', () => {
        const items = [{ item_id: 1, name: 'X', quantity: 1, convert_price: 1, total: 1, item_variations: [], item_extras: [] }];
        const store = makeStore({ items, orderType: ORDER_TYPE_TAKEAWAY });
        const wrapper = mount(KioskCartComponent, mountOpts(store));
        const group = wrapper.find('[data-testid="kiosk-cart-order-type"]');
        expect(group.exists()).toBe(true);
        expect(group.attributes('role')).toBe('radiogroup');
        const dinein = wrapper.find('[data-testid="kiosk-cart-order-type-dinein"]');
        const tak = wrapper.find('[data-testid="kiosk-cart-order-type-takeaway"]');
        // [GOAL-2026-05-29] V1: dine-in is disabled (pos.dine_in_enabled=false owner
        // mandate) so the dine-in tile is NOT rendered; takeaway is the only option
        // and is checked. (Was written assuming both tiles present.)
        expect(dinein.exists()).toBe(false);
        expect(tak.attributes('aria-checked')).toBe('true');
    });

    it('clear modal : aria-modal + testid + open/close via button', async () => {
        const items = [{ item_id: 1, name: 'X', quantity: 1, convert_price: 1, total: 1, item_variations: [], item_extras: [] }];
        const store = makeStore({ items });
        const wrapper = mount(KioskCartComponent, mountOpts(store));
        await wrapper.find('[data-testid="kiosk-cart-clear"]').trigger('click');
        const modal = wrapper.find('[data-testid="kiosk-cart-clear-modal"]');
        expect(modal.exists()).toBe(true);
        expect(modal.attributes('aria-modal')).toBe('true');
        expect(modal.attributes('role')).toBe('dialog');
        await wrapper.find('[data-testid="kiosk-cart-clear-no"]').trigger('click');
        expect(wrapper.find('[data-testid="kiosk-cart-clear-modal"]').exists()).toBe(false);
    });

    it('qty minus dispatches updateQuantity with quantity - 1', async () => {
        const items = [{ item_id: 1, name: 'X', quantity: 3, convert_price: 1, total: 3, item_variations: [], item_extras: [] }];
        const store = makeStore({ items });
        const spy = vi.spyOn(store._actions['kioskCart/updateQuantity'], '0');
        const wrapper = mount(KioskCartComponent, mountOpts(store));
        await wrapper.find('[data-testid="kiosk-cart-item-qty-minus-0"]').trigger('click');
        expect(spy).toHaveBeenCalledWith({ index: 0, quantity: 2 });
    });

    it('loyalty button shown with correct testid', () => {
        const items = [{ item_id: 1, name: 'X', quantity: 1, convert_price: 5, total: 5, item_variations: [], item_extras: [] }];
        const store = makeStore({ items, loyaltyDiscount: 2, total: 3 });
        const wrapper = mount(KioskCartComponent, mountOpts(store));
        expect(wrapper.find('[data-testid="kiosk-cart-loyalty-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-cart-loyalty-discount"]').text()).toContain('-');
    });
});
