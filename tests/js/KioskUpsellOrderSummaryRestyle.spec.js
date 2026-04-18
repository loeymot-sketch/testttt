/**
 * Kiosk Design V1 — Phase 2.x : KioskUpsellComponent + KioskOrderSummaryComponent restyle
 *
 * Upsell :
 *  - data-testid stables (root, loading, title, grid, card-<id>, skip, add-continue, autoskip-bar)
 *  - A11y : cards ont role=listitem, aria-pressed, activation clavier (Space)
 *  - progressbar aria-valuenow/min/max pour auto-skip
 *
 * OrderSummary :
 *  - data-testid (root, total, qty +/-/value, main-item/name/price)
 *  - A11y : qty group + labels, aria-live sur quantity change
 *  - Émission 'update' quantity via boutons
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskUpsellComponent from '../../resources/js/components/frontend/kiosk/KioskUpsellComponent.vue';
import KioskOrderSummaryComponent from '../../resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

function makeUpsellStore(items = []) {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                state: () => ({}),
                actions: {
                    addItem: vi.fn(),
                    fetchUpsellItems: vi.fn().mockResolvedValue({ data: { data: items } }),
                },
            },
        },
    });
}

function mountUpsell(items = []) {
    const store = makeUpsellStore(items);
    return mount(KioskUpsellComponent, {
        global: {
            plugins: [store, i18n],
            mocks: {
                $router: { push: vi.fn(), replace: vi.fn() },
                $route: { query: {}, params: {} },
            },
        },
    });
}

describe('KioskUpsellComponent restyle', () => {
    it('loading : testid loading rendu, role=status', () => {
        const w = mountUpsell([]);
        const loading = w.find('[data-testid="kiosk-upsell-loading"]');
        expect(loading.exists()).toBe(true);
        expect(loading.attributes('role')).toBe('status');
    });

    it('après load : grid + cards + skip + autoskip-bar visibles', async () => {
        const items = [
            { id: 10, name: 'Coca', convert_price: 2.5, thumb: null },
            { id: 11, name: 'Frites', convert_price: 3, thumb: null },
        ];
        const w = mountUpsell(items);
        // flush the promise chain
        await new Promise((r) => setTimeout(r, 10));
        await w.vm.$nextTick();
        expect(w.find('[data-testid="kiosk-upsell-grid"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-upsell-card-10"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-upsell-card-11"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-upsell-skip"]').exists()).toBe(true);
        const bar = w.find('[data-testid="kiosk-upsell-autoskip-bar"]');
        expect(bar.attributes('role')).toBe('progressbar');
        expect(bar.attributes('aria-valuemin')).toBe('0');
        expect(bar.attributes('aria-valuemax')).toBe('100');
    });

    it('card : keyboard Space selects → aria-pressed passes to true', async () => {
        const items = [{ id: 42, name: 'Cookie', convert_price: 1.5, thumb: null }];
        const w = mountUpsell(items);
        await new Promise((r) => setTimeout(r, 10));
        await w.vm.$nextTick();
        const card = w.find('[data-testid="kiosk-upsell-card-42"]');
        expect(card.attributes('aria-pressed')).toBe('false');
        await card.trigger('keydown.space');
        expect(card.attributes('aria-pressed')).toBe('true');
        expect(w.find('[data-testid="kiosk-upsell-add-continue"]').exists()).toBe(true);
    });
});

/* ---------------- OrderSummary ---------------- */

function mountSummary(selectionOverrides = {}) {
    const item = {
        id: 1,
        name: 'Burger',
        convert_price: 9,
        extras: [],
        itemAttributes: [],
        variations: {},
        addons: [],
    };
    const selections = {
        pain: null,
        viandes: {},
        garnitures: {},
        supplements: {},
        sauceOrder: [],
        fritesSauceOrder: [],
        menuChoice: 'none',
        boissonChoice: null,
        quantity: 2,
        totalViandes: 0,
        ...selectionOverrides,
    };
    return mount(KioskOrderSummaryComponent, {
        props: { step: {}, item, selections },
        global: { plugins: [i18n] },
    });
}

describe('KioskOrderSummaryComponent restyle', () => {
    it('renders root / main item / total / qty testids', () => {
        const w = mountSummary();
        expect(w.find('[data-testid="kiosk-order-summary-root"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-order-summary-main-name"]').text()).toContain('Burger');
        expect(w.find('[data-testid="kiosk-order-summary-total"]').attributes('role')).toBe('status');
        expect(w.find('[data-testid="kiosk-order-summary-qty-value"]').text()).toBe('2');
    });

    it('qty plus/minus emit update with correct payload', async () => {
        const w = mountSummary({ quantity: 3 });
        await w.find('[data-testid="kiosk-order-summary-qty-plus"]').trigger('click');
        const pushed = w.emitted('update');
        expect(pushed).toBeTruthy();
        expect(pushed[0]).toEqual(['quantity', 4]);
        await w.find('[data-testid="kiosk-order-summary-qty-minus"]').trigger('click');
        expect(w.emitted('update')[1]).toEqual(['quantity', 2]);
    });

    it('qty-minus disabled when quantity === 1', () => {
        const w = mountSummary({ quantity: 1 });
        expect(w.find('[data-testid="kiosk-order-summary-qty-minus"]').attributes('disabled')).toBeDefined();
    });
});
