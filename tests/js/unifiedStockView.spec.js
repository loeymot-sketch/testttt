/**
 * [PHASE 3d-UI — VUE CONSO & STOCK UNIFIÉE 2026-07-24]
 *
 * Mount spec for UnifiedStockViewComponent.vue. Verifies the screen consumes the
 * committed backend endpoint (GET admin/stock/unified-overview →
 * UnifiedStockViewService::overview) and renders:
 *   - the « 🛒 À acheter » section AT TOP (out-first, matières + boissons fused)
 *   - the 2 rayons (raw materials / resold drinks) with their rows
 *   - totals (stock value, ruptures, to-buy)
 *   - the missing-avg_cost banner + per-row "no cost" marker
 *   - search + status filter
 *   - the empty state
 *
 * axios is GLOBAL (Laravel bootstrap window.axios) — stubbed like the sibling
 * StockRupture mount spec. $t is mocked to echo keys so assertions target
 * data-testids + rendered data values, not translated copy.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import UnifiedStockViewComponent from '../../resources/js/components/admin/stock/UnifiedStockViewComponent.vue';

const axiosMock = {
    get: vi.fn(),
    post: vi.fn(),
};

vi.stubGlobal('axios', axiosMock);

const OVERVIEW = {
    branch_id: 1,
    window_days: 30,
    generated_at: '2026-07-24T10:00:00+00:00',
    raw_materials: [
        { id: 1, name: 'Steak haché', unit: 'kg', on_hand: 12.5, threshold_low: 5, recent_consumption: 8.2, avg_cost: 9.9, has_cost: true, stock_value: 123.75, status: 'ok' },
        { id: 2, name: 'Cheddar', unit: 'kg', on_hand: 0, threshold_low: 2, recent_consumption: 3, avg_cost: null, has_cost: false, stock_value: null, status: 'out' },
        { id: 3, name: 'Pain buns', unit: 'u', on_hand: 40, threshold_low: 50, recent_consumption: 60, avg_cost: 0.3, has_cost: true, stock_value: 12, status: 'low' },
    ],
    resold_products: [
        { id: 101, name: 'Coca-Cola 33cl', unit: 'u', on_hand: 24, threshold_low: 12, recent_consumption: 30, status: 'ok' },
        { id: 102, name: 'Eau 50cl', unit: 'u', on_hand: 0, threshold_low: 6, recent_consumption: 10, status: 'out' },
    ],
    to_buy: [
        { kind: 'raw_material', id: 2, name: 'Cheddar', unit: 'kg', on_hand: 0, threshold_low: 2, status: 'out' },
        { kind: 'resold_product', id: 102, name: 'Eau 50cl', unit: 'u', on_hand: 0, threshold_low: 6, status: 'out' },
        { kind: 'raw_material', id: 3, name: 'Pain buns', unit: 'u', on_hand: 40, threshold_low: 50, status: 'low' },
    ],
    totals: {
        raw_material_stock_value: 135.75,
        raw_materials_count: 3,
        resold_products_count: 2,
        out_count: 2,
        low_count: 1,
        to_buy_count: 3,
        missing_cost_count: 1,
    },
};

const EMPTY_OVERVIEW = {
    branch_id: 1,
    window_days: 30,
    generated_at: '2026-07-24T10:00:00+00:00',
    raw_materials: [],
    resold_products: [],
    to_buy: [],
    totals: {
        raw_material_stock_value: 0,
        raw_materials_count: 0,
        resold_products_count: 0,
        out_count: 0,
        low_count: 0,
        to_buy_count: 0,
        missing_cost_count: 0,
    },
};

function mountView() {
    return mount(UnifiedStockViewComponent, {
        global: {
            mocks: {
                // Echo i18n keys + shallow-interpolate {params} so no plugin is needed.
                $t: (key, params) => {
                    if (params && typeof params === 'object') {
                        return Object.keys(params).reduce(
                            (acc, k) => acc.replace(`{${k}}`, params[k]),
                            key,
                        );
                    }
                    return key;
                },
            },
        },
    });
}

describe('UnifiedStockViewComponent — conso & stock unifiée (mount)', () => {
    beforeEach(() => {
        axiosMock.get.mockReset();
        axiosMock.post.mockReset();
        axiosMock.get.mockImplementation((url) => {
            if (String(url).includes('admin/stock/unified-overview')) {
                return Promise.resolve({ data: OVERVIEW });
            }
            return Promise.resolve({ data: {} });
        });
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('mounts and fetches the committed unified endpoint', async () => {
        const wrapper = mountView();
        expect(wrapper.find('[data-testid="unified-stock-view"]').exists()).toBe(true);
        await flushPromises();
        expect(axiosMock.get).toHaveBeenCalledWith('admin/stock/unified-overview');
    });

    it('renders the « À acheter » section AT TOP with fused matières + boissons (out-first)', async () => {
        const wrapper = mountView();
        await flushPromises();
        const tobuy = wrapper.find('[data-testid="usv-tobuy"]');
        expect(tobuy.exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-buy-raw_material-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-buy-resold_product-102"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-buy-raw_material-3"]').exists()).toBe(true);

        // « À acheter » must sit ABOVE both rayons in DOM order.
        const html = wrapper.html();
        expect(html.indexOf('data-testid="usv-tobuy"')).toBeLessThan(html.indexOf('data-testid="usv-raw"'));
        expect(html.indexOf('data-testid="usv-raw"')).toBeLessThan(html.indexOf('data-testid="usv-resold"'));
    });

    it('renders the 2 rayons: raw materials + resold drinks with their rows', async () => {
        const wrapper = mountView();
        await flushPromises();
        expect(wrapper.find('[data-testid="usv-raw"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-resold"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-raw-1"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Steak haché');
        expect(wrapper.find('[data-testid="usv-resold-101"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Coca-Cola 33cl');
    });

    it('renders totals (stock value, ruptures, to-buy)', async () => {
        const wrapper = mountView();
        await flushPromises();
        expect(wrapper.find('[data-testid="usv-totals"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-total-out"]').text()).toBe('2');
        expect(wrapper.find('[data-testid="usv-total-tobuy"]').text()).toBe('3');
        // Money is formatted (fr-FR EUR) — assert the numeric part is present.
        expect(wrapper.find('[data-testid="usv-total-value"]').text()).toContain('135');
    });

    it('shows the missing-avg_cost banner + per-row "no cost" marker', async () => {
        const wrapper = mountView();
        await flushPromises();
        expect(wrapper.find('[data-testid="usv-missing-cost"]').exists()).toBe(true);
        // Cheddar (id 2) has has_cost=false → its cost cell renders the no-cost marker.
        expect(wrapper.find('[data-testid="usv-raw-nocost-2"]').exists()).toBe(true);
        // Steak haché (id 1) has a cost → no marker.
        expect(wrapper.find('[data-testid="usv-raw-nocost-1"]').exists()).toBe(false);
    });

    it('search filters both rayons by name', async () => {
        const wrapper = mountView();
        await flushPromises();
        wrapper.vm.searchQuery = 'coca';
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="usv-resold-101"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-raw-1"]').exists()).toBe(false);
    });

    it('status filter "out" keeps only ruptures across rayons', async () => {
        const wrapper = mountView();
        await flushPromises();
        await wrapper.find('[data-testid="usv-filter-out"]').trigger('click');
        expect(wrapper.find('[data-testid="usv-raw-2"]').exists()).toBe(true); // Cheddar out
        expect(wrapper.find('[data-testid="usv-raw-1"]').exists()).toBe(false); // Steak ok
        expect(wrapper.find('[data-testid="usv-raw-3"]').exists()).toBe(false); // Pain low
        expect(wrapper.find('[data-testid="usv-resold-102"]').exists()).toBe(true); // Eau out
        expect(wrapper.find('[data-testid="usv-resold-101"]').exists()).toBe(false); // Coca ok
    });

    it('renders the empty state when the branch has no material and no tracked drink', async () => {
        axiosMock.get.mockImplementation((url) => {
            if (String(url).includes('admin/stock/unified-overview')) {
                return Promise.resolve({ data: EMPTY_OVERVIEW });
            }
            return Promise.resolve({ data: {} });
        });
        const wrapper = mountView();
        await flushPromises();
        expect(wrapper.find('[data-testid="usv-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-raw"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="usv-resold"]').exists()).toBe(false);
    });

    it('surfaces an error state when the endpoint fails', async () => {
        axiosMock.get.mockRejectedValueOnce(new Error('boom'));
        const wrapper = mountView();
        await flushPromises();
        expect(wrapper.find('[data-testid="usv-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="usv-retry"]').exists()).toBe(true);
    });
});
