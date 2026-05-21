/**
 * [Mission 1 — Stock-Rupture UI Simplification 2026-05-21]
 *
 * Vue mount spec for StockRuptureDashboardComponent.vue V2 (unified catalogue
 * browser). Catches Vue compile errors + verifies the new template:
 *   - root section data-testid="stock-management-v2"
 *   - left rail with a bucket button per axis (category / extra group / variation attribute)
 *   - right pane product cards with toggle buttons that POST to the correct
 *     AvailabilityController endpoints
 *   - axios calls for the unified read endpoint + per-axis toggle endpoints
 *
 * Uses happy-dom (configured globally in vitest.config.mjs).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { createStore } from 'vuex';
import StockRuptureDashboardComponent from '../../resources/js/components/admin/stock/StockRuptureDashboardComponent.vue';

// ---- Mocks -----------------------------------------------------------------

const axiosMock = {
    get: vi.fn(),
    post: vi.fn(),
};

vi.stubGlobal('axios', axiosMock);

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => true),
    },
}));

vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));

// ---- Fixtures --------------------------------------------------------------

const messages = {
    en: {
        admin: {
            stock_mgmt: {
                title: 'Products & Stock',
                subtitle: 'Enable or disable each product for this branch.',
                search: 'Search a product…',
                empty: 'No products in this category.',
                in_stock: 'IN STOCK',
                out_of_stock: 'OUT OF STOCK',
                loading_error: 'Could not load products.',
                toggle_error: 'Could not change status.',
                branch_all: 'All branches',
                branch_label: 'Branch',
                rail_items: 'Product categories',
                rail_extras: 'Supplements',
                rail_variations: 'Variations',
                loading: 'Loading…',
                read_only: 'Read-only',
            },
        },
    },
};

const CATALOG_PAYLOAD = {
    branch_id: 1,
    categories: [
        {
            id: 10,
            name: 'Burgers',
            slug: 'burgers',
            items: [
                { id: 42, name: 'Big Cayenne', slug: 'big-cayenne', thumb: '/img/big.png', is_available: true, reason: null },
                { id: 43, name: 'Cheese Burger', slug: 'cheese', thumb: null, is_available: false, reason: 'out_of_stock_manual' },
            ],
        },
    ],
    extra_groups: [
        {
            group_label: 'sauce_supp',
            display_name: 'Sauces supplémentaires',
            items: [
                {
                    name: 'Algérienne',
                    extra_ids: [101, 102, 103],
                    thumb: null,
                    is_available: true,
                    any_unavailable_count: 0,
                    total_count: 3,
                },
            ],
        },
    ],
    variation_groups: [
        {
            attribute_id: 7,
            attribute_name: 'Crudité',
            items: [
                {
                    name: 'Tomate',
                    variation_ids: [201, 202],
                    thumb: null,
                    is_available: true,
                    any_unavailable_count: 0,
                    total_count: 2,
                },
            ],
        },
    ],
    fetched_at: '2026-05-21T10:00:00Z',
};

function mountDashboard(options = {}) {
    const i18n = createI18n({ legacy: true, locale: 'en', messages });
    const store = createStore({
        modules: {
            auth: {
                namespaced: true,
                state: { authBranchId: options.branchId ?? 0 },
                getters: {
                    authBranchId: (s) => s.authBranchId,
                },
            },
        },
    });
    return mount(StockRuptureDashboardComponent, {
        global: {
            plugins: [i18n, store],
        },
        props: {
            pollIntervalMs: 60_000,
            ...options.props,
        },
    });
}

// ---- Tests -----------------------------------------------------------------

describe('StockRuptureDashboardComponent — Mission 1 V2 unified browser (mount)', () => {
    beforeEach(() => {
        axiosMock.get.mockReset();
        axiosMock.post.mockReset();
        axiosMock.get.mockImplementation((url) => {
            if (url.includes('admin/stock/catalog-overview')) {
                return Promise.resolve({ data: CATALOG_PAYLOAD });
            }
            return Promise.resolve({ data: {} });
        });
        axiosMock.post.mockResolvedValue({ data: { ok: true } });
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('mounts without compile errors and renders the V2 root + title', async () => {
        const wrapper = mountDashboard();
        expect(wrapper.find('[data-testid="stock-management-v2"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Products & Stock');
    });

    it('renders the rail with one bucket per axis after loadAll', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-rail"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-bucket-cat-10"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-bucket-extra-sauce_supp"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-bucket-var-7"]').exists()).toBe(true);
    });

    it('renders the active bucket items (first category by default)', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-product-item-42"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-product-item-43"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Big Cayenne');
    });

    it('switching bucket updates the visible products', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-bucket-extra-sauce_supp"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-product-extra-sauce_supp-Algérienne"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-product-item-42"]').exists()).toBe(false);
    });

    it('clicking an item toggle POSTs to admin/menu/availability/toggle', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-toggle-item-42"]').trigger('click');
        await flushPromises();
        expect(axiosMock.post).toHaveBeenCalledWith('admin/menu/availability/toggle', expect.objectContaining({
            item_id: 42,
            is_available: false,
        }));
    });

    it('clicking an extra toggle fans out to N admin/menu/availability/extra/toggle POSTs', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-bucket-extra-sauce_supp"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-product-extra-sauce_supp-Algérienne"] [role="switch"]').trigger('click');
        // [M1 round-2 A-005/A-006/A-013] sendBulkToggle now batches at
        // concurrency=2 with a 100ms inter-batch delay; wait for the second
        // batch's setTimeout to fire so the third extra_id (103) is sent.
        await new Promise((resolve) => setTimeout(resolve, 200));
        await flushPromises();
        const extraCalls = axiosMock.post.mock.calls.filter(
            ([url]) => url === 'admin/menu/availability/extra/toggle',
        );
        expect(extraCalls).toHaveLength(3);
        const ids = extraCalls.map(([, body]) => body.extra_id).sort();
        expect(ids).toEqual([101, 102, 103]);
        // Toggling OFF must send the default reason from the whitelist.
        extraCalls.forEach(([, body]) => {
            expect(body.is_available).toBe(false);
            expect(body.reason).toBe('out_of_stock_manual');
        });
    });

    it('clicking a variation toggle fans out to N admin/menu/availability/variation/toggle POSTs', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-bucket-var-7"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-product-var-7-Tomate"] [role="switch"]').trigger('click');
        await flushPromises();
        const varCalls = axiosMock.post.mock.calls.filter(
            ([url]) => url === 'admin/menu/availability/variation/toggle',
        );
        expect(varCalls).toHaveLength(2);
        const ids = varCalls.map(([, body]) => body.variation_id).sort();
        expect(ids).toEqual([201, 202]);
    });

    it('caps bulk-toggle fan-out at concurrency-2 with inter-batch delay (M1 round-2 A-005/A-006/A-013)', async () => {
        // Regression guard: bulk fan-out used to fire concurrency-5 which tripped
        // throttle:60,1 on /admin/menu/availability/{extra,variation}/toggle and
        // cascaded 429s into POS catalogue. New contract: at-most-2 in flight + a
        // pause between batches so the burst respects the rate-limit window.
        const wrapper = mountDashboard();
        await flushPromises();

        let inFlight = 0;
        let peakInFlight = 0;
        const startTimestamp = Date.now();
        const callTimestamps = [];

        const makeRequest = (id) => {
            callTimestamps.push(Date.now() - startTimestamp);
            inFlight += 1;
            if (inFlight > peakInFlight) peakInFlight = inFlight;
            return new Promise((resolve) => {
                setTimeout(() => {
                    inFlight -= 1;
                    resolve({ data: { ok: true, id } });
                }, 5);
            });
        };

        await wrapper.vm.sendBulkToggle([101, 102, 103, 104, 105, 106], false, makeRequest);

        // Peak concurrency must NOT exceed 2 (the new contract).
        expect(peakInFlight).toBeLessThanOrEqual(2);
        // Six requests must have happened.
        expect(callTimestamps).toHaveLength(6);
        // Three batches of 2 → at least two inter-batch gaps. Each batch issues
        // 2 calls (cheap), the 3rd batch's first call must be >= 100ms after the
        // 1st batch's first call. We use a generous lower bound to dodge timer
        // flake on slow CI.
        const lastCallOffset = callTimestamps[callTimestamps.length - 1];
        expect(lastCallOffset).toBeGreaterThanOrEqual(150);
    });

    it('subscribes to Echo when staff branch_id > 0 and window.Echo is present', async () => {
        const { onEvents } = await import('../../resources/js/services/eventContract');
        onEvents.mockClear();
        // The component bails out when window.Echo is missing — stub a no-op
        // instance so the subscribeEcho path executes end-to-end.
        const previousEcho = window.Echo;
        window.Echo = { stub: true };
        try {
            const wrapper = mountDashboard({ branchId: 1 });
            await flushPromises();
            expect(onEvents).toHaveBeenCalledTimes(1);
            wrapper.unmount();
        } finally {
            window.Echo = previousEcho;
        }
    });
});
