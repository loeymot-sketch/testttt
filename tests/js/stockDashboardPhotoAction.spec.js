/**
 * [CATALOG-HUB PHOTO 2026-07-21] Inline photo action on the stock dashboard.
 *
 * Locks the ITEM-2 behaviour: each ITEM row (not extras/variations) exposes a
 * 📷 button that expands the reused ItemPhotoUpload uploader inline; on
 * upload-success the panel collapses and the dashboard reconciles state via the
 * unified read endpoint (loadAll). Additive only — no existing testid removed,
 * no bulk/scan modal.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { createStore } from 'vuex';
import StockRuptureDashboardComponent from '../../resources/js/components/admin/stock/StockRuptureDashboardComponent.vue';
import ItemPhotoUpload from '../../resources/js/components/admin/items/ItemPhotoUpload.vue';

const axiosMock = { get: vi.fn(), post: vi.fn() };
vi.stubGlobal('axios', axiosMock);

vi.mock('../../resources/js/services/appService', () => ({
    default: { permissionChecker: vi.fn(() => true) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));

const messages = {
    en: {
        admin: {
            stock_mgmt: {
                title: 'Products & Stock', subtitle: '', search: 'Search', empty: 'Empty',
                in_stock: 'IN STOCK', out_of_stock: 'OUT OF STOCK', loading_error: 'err',
                toggle_error: 'err', branch_label: 'Branch', loading: 'Loading',
                read_only: 'Read-only', photo_action: 'Product photo',
                globally_disabled: 'Disabled globally',
            },
        },
        studio: { image: { upload_idle: 'Add a photo', upload_error: 'Upload error', upload_forbidden: 'Reserved to administrators' } },
    },
};

const CATALOG_PAYLOAD = {
    branch_id: 1,
    categories: [
        {
            id: 10, name: 'Burgers', slug: 'burgers',
            items: [
                { id: 42, name: 'Big Cayenne', thumb: '/img/big.png', is_available: true, globally_disabled: false },
                { id: 43, name: 'Cheese', thumb: null, is_available: false, globally_disabled: true },
            ],
        },
    ],
    extra_groups: [
        {
            group_label: 'sauce_supp', display_name: 'Sauces',
            items: [{ name: 'Algérienne', extra_ids: [101], thumb: null, is_available: true }],
        },
    ],
    variation_groups: [
        {
            attribute_id: 7, attribute_name: 'Crudité',
            items: [{ name: 'Tomate', variation_ids: [201], thumb: null, is_available: true }],
        },
    ],
};

// roleId defaults to 1 (Admin — roleEnum.ADMIN) so the photo action is visible for
// the ITEM-2 photo tests; pass a non-admin role id to assert the server-mirroring gate.
function mountDashboard(roleId = 1) {
    const i18n = createI18n({ legacy: true, locale: 'en', messages });
    const store = createStore({
        modules: {
            auth: {
                namespaced: true,
                state: { authBranchId: 0, authInfo: { role_id: roleId } },
                getters: {
                    authBranchId: (s) => s.authBranchId,
                    authInfo: (s) => s.authInfo,
                },
            },
        },
    });
    return mount(StockRuptureDashboardComponent, {
        global: { plugins: [i18n, store] },
        props: { pollIntervalMs: 60_000 },
    });
}

describe('StockRupture dashboard — inline photo action (ITEM 2)', () => {
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

    afterEach(() => vi.clearAllMocks());

    it('renders a photo button on ITEM rows only', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-photo-btn-item-42"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-photo-btn-item-43"]').exists()).toBe(true);
    });

    // [photo-upload-authz-and-feedback 2026-07-22] The server reserves the photo route
    // to Admin/Tenant-Admin — a non-admin with items_edit would 403. The button must be
    // hidden for non-admin roles so no manager taps a dead button.
    it('hides the photo button for a non-Admin role (server would 403)', async () => {
        const wrapper = mountDashboard(6); // Branch Manager (not roleEnum.ADMIN)
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-photo-btn-item-42"]').exists()).toBe(false);
        // The availability toggle (items_edit) stays available — only the photo action is gated.
        expect(wrapper.find('[data-testid="stock-mgmt-toggle-item-42"]').exists()).toBe(true);
    });

    // [global-flag-defeats-dashboard-toggle 2026-07-22] When items.is_available (global)
    // is off, the branch toggle is inert — surface a « disabled globally » badge so the
    // conflict is visible instead of a silently dead switch.
    it('shows the « disabled globally » badge only when the global flag is off', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-global-off-item-43"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-mgmt-global-off-item-43"]').text()).toContain('Disabled globally');
        expect(wrapper.find('[data-testid="stock-mgmt-global-off-item-42"]').exists()).toBe(false);
    });

    it('does NOT render a photo button on extra / variation rows', async () => {
        const wrapper = mountDashboard();
        await flushPromises();

        await wrapper.find('[data-testid="stock-mgmt-bucket-extra-sauce_supp"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-photo-btn-extra-sauce_supp-Algérienne"]').exists()).toBe(false);
        // The availability toggle switch is still present on the extra row.
        expect(wrapper.find('[data-testid="stock-mgmt-product-extra-sauce_supp-Algérienne"] [role="switch"]').exists()).toBe(true);

        await wrapper.find('[data-testid="stock-mgmt-bucket-var-7"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-photo-btn-var-7-Tomate"]').exists()).toBe(false);
    });

    it('preserves the availability toggle testid + role on item rows', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        const toggle = wrapper.find('[data-testid="stock-mgmt-toggle-item-42"]');
        expect(toggle.exists()).toBe(true);
        expect(toggle.attributes('role')).toBe('switch');
    });

    it('expands the inline ItemPhotoUpload panel when the photo button is clicked', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        expect(wrapper.find('[data-testid="stock-mgmt-photo-panel-item-42"]').exists()).toBe(false);

        await wrapper.find('[data-testid="stock-mgmt-photo-btn-item-42"]').trigger('click');
        expect(wrapper.find('[data-testid="stock-mgmt-photo-panel-item-42"]').exists()).toBe(true);
        expect(wrapper.findComponent(ItemPhotoUpload).exists()).toBe(true);
        // The uploader receives the correct item id (Number) + current image.
        expect(wrapper.findComponent(ItemPhotoUpload).props('itemId')).toBe(42);
        expect(wrapper.find('[data-testid="stock-mgmt-photo-btn-item-42"]').attributes('aria-expanded')).toBe('true');
    });

    it('opens one panel at a time (clicking a second row moves the panel)', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        await wrapper.find('[data-testid="stock-mgmt-photo-btn-item-42"]').trigger('click');
        await wrapper.find('[data-testid="stock-mgmt-photo-btn-item-43"]').trigger('click');
        expect(wrapper.find('[data-testid="stock-mgmt-photo-panel-item-42"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="stock-mgmt-photo-panel-item-43"]').exists()).toBe(true);
    });

    it('on upload-success: collapses the panel and re-fetches canonical state', async () => {
        const wrapper = mountDashboard();
        await flushPromises();
        const loadsAfterMount = axiosMock.get.mock.calls.length;

        await wrapper.find('[data-testid="stock-mgmt-photo-btn-item-42"]').trigger('click');
        wrapper.findComponent(ItemPhotoUpload).vm.$emit('upload-success', { url: '/img/new.png' });
        await flushPromises();

        // Panel collapsed.
        expect(wrapper.vm.photoOpenKey).toBe('');
        expect(wrapper.find('[data-testid="stock-mgmt-photo-panel-item-42"]').exists()).toBe(false);
        // loadAll() re-fetched the catalogue to reconcile the thumbnail.
        expect(axiosMock.get.mock.calls.length).toBeGreaterThan(loadsAfterMount);
    });
});
