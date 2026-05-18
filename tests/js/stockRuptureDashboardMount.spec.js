/**
 * BUILD-4 Round 4 — Vue mount sentinel for StockRuptureDashboardComponent.
 *
 * Catches Vue compile errors + verifies the template renders the new wireup
 * surface (search input, branch filter, bulk bar gate, modal mount point).
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
            stock_rupture: {
                title: 'Stock and outages',
                subtitle: 'Track unavailable items',
                cron_enabled: 'Auto monitoring',
                cron_disabled: 'Auto monitoring off',
                run_now: 'Run scan',
                last_run: 'Last scan',
                last_run_at: 'Scan date',
                items_flipped: 'Items flipped',
                items_skipped: 'Items skipped',
                duration_ms: 'Duration',
                currently_86: 'Currently unavailable',
                none_unavailable: 'No items unavailable.',
                flipped_at: 'since',
                low_alerts: 'Low alerts',
                no_low_alerts: 'No low alerts.',
                below_threshold: 'Below threshold',
                mark_rupture: 'Mark out',
                restore: 'Restore',
                restore_selected: 'Restore selected',
                mark_rupture_selected: 'Mark selected out',
                reason_label: 'Reason',
                search_placeholder: 'Search…',
                branch_filter_all: 'All branches',
                branch_filter_label: 'Branch',
                confirm_bulk: 'Confirm bulk',
                cancel: 'Cancel',
                confirm: 'Confirm',
                selected_count: '{count} selected',
                toggle_success: 'Updated.',
                toggle_error: 'Update failed.',
                bulk_partial_error: '{ok} ok, {fail} fail',
                no_selection: 'No selection.',
                select_all: 'Select all',
                loading_error: 'Could not load.',
                reason: {
                    stock_rupture: 'Out',
                    out_of_stock: 'Out',
                    out_of_stock_manual: 'Manual',
                    seasonal: 'Seasonal',
                    recipe_change: 'Recipe',
                    supplier_issue: 'Supplier',
                    quality_issue: 'Quality',
                },
            },
        },
    },
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

describe('StockRuptureDashboardComponent — V1.0.2 BUILD-4 wireup (mount)', () => {
    beforeEach(() => {
        axiosMock.get.mockReset();
        axiosMock.post.mockReset();
        axiosMock.get.mockImplementation((url) => {
            if (url.includes('last-summary')) {
                return Promise.resolve({
                    data: {
                        cron_enabled: true,
                        branches: [
                            { branch_id: 1, branch_name: 'Branche A', summary: null, fetched_at: 'x' },
                            { branch_id: 2, branch_name: 'Branche B', summary: null, fetched_at: 'x' },
                        ],
                        currently_unavailable: [
                            {
                                branch_id: 1,
                                branch_name: 'Branche A',
                                item_id: 42,
                                item_name: 'Big Burger',
                                reason: 'out_of_stock_manual',
                                flipped_at: '2026-05-18T10:00:00Z',
                                flipped_at_human: '2 min ago',
                            },
                        ],
                        fetched_at: 'x',
                    },
                });
            }
            if (url.includes('low-alerts')) {
                return Promise.resolve({
                    data: {
                        alerts: [
                            {
                                branch_id: 1,
                                branch_name: 'Branche A',
                                stockable_type: 'item',
                                stockable_id: 99,
                                stockable_name: 'Chicken Burger',
                                label: 'Chicken Burger',
                                on_hand: 1,
                                threshold_low: 5,
                            },
                        ],
                        fetched_at: 'x',
                    },
                });
            }
            return Promise.resolve({ data: {} });
        });
        axiosMock.post.mockResolvedValue({ data: { ok: true } });
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('mounts without compile errors and renders the header', async () => {
        const wrapper = mountDashboard();
        expect(wrapper.find('[data-testid="stock-rupture-dashboard"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Stock and outages');
    });

    it('renders the search input + cron status badge', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-rupture-search"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-rupture-cron-on"]').exists()).toBe(true);
    });

    it('exposes the branch filter dropdown for admin (branch_id=0) when N>1 branches', async () => {
        const wrapper = mountDashboard({ branchId: 0 });
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-rupture-branch-select"]').exists()).toBe(true);
    });

    it('renders the rupture list with restore button and translates reasons', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-rupture-row-42"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-rupture-restore-42"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Manual');
        expect(wrapper.text()).toContain('Big Burger');
    });

    it('shows the low-alerts list with a Mark-rupture button on item alerts', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-low-alert-99"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-low-alert-mark-99"]').exists()).toBe(true);
    });

    it('POSTs the toggle endpoint when clicking restore (optimistic UI)', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-rupture-restore-42"]').trigger('click');
        await flushPromises();
        expect(axiosMock.post).toHaveBeenCalledWith('admin/availability/toggle', {
            item_id: 42,
            branch_id: 1,
            is_available: true,
        });
    });

    it('opens the reason modal when clicking Mark-rupture on a low-alert row', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-low-alert-mark-99"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-rupture-reason-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-rupture-reason-supplier_issue"]').exists()).toBe(true);
    });

    it('confirm-modal sends toggle with reason payload', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-low-alert-mark-99"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="stock-rupture-reason-supplier_issue"]').setValue(true);
        await wrapper.find('[data-testid="stock-rupture-reason-confirm"]').trigger('click');
        await flushPromises();
        expect(axiosMock.post).toHaveBeenCalledWith('admin/availability/toggle', expect.objectContaining({
            item_id: 99,
            branch_id: 1,
            is_available: false,
            unavailable_reason: 'supplier_issue',
        }));
    });

    it('reveals the bulk action bar only when selection is non-empty', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        // No selection yet → bulk bar hidden
        expect(wrapper.find('[data-testid="stock-rupture-bulk-bar"]').exists()).toBe(false);
        // Tick the checkbox
        await wrapper.find('[data-testid="stock-rupture-select-42"]').setValue(true);
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-rupture-bulk-bar"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-rupture-bulk-restore"]').exists()).toBe(true);
    });

    it('bulk restore issues sequential toggle POSTs', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-rupture-select-42"]').setValue(true);
        await flushPromises();
        axiosMock.post.mockClear();
        await wrapper.find('[data-testid="stock-rupture-bulk-restore"]').trigger('click');
        await flushPromises();
        expect(axiosMock.post).toHaveBeenCalledWith('admin/availability/toggle', expect.objectContaining({
            item_id: 42,
            is_available: true,
        }));
    });
});
