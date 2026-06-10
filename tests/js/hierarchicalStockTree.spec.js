/**
 * [GOAL CMS GESTION 2026-06-10 — Wave S2, T-S2.2]
 *
 * Rail hiérarchique du dashboard stock : les sous-catégories (parent_id émis
 * par catalogOverview depuis S2.1) sont rendues IMMÉDIATEMENT sous leur
 * parent, indentées (depth=1 → classe pl-7 + flèche ↳). Mount-level
 * (comportemental), harness identique à stockRuptureDashboardMount.spec.js.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { createStore } from 'vuex';
import StockRuptureDashboardComponent from '../../resources/js/components/admin/stock/StockRuptureDashboardComponent.vue';

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
                rail_items: 'Product categories',
                rail_extras: 'Supplements',
                rail_variations: 'Variations',
                rail_ruptures: 'Out of stock',
                loading: 'Loading…',
                read_only: 'Read-only',
            },
        },
    },
};

const HIERARCHICAL_PAYLOAD = {
    branch_id: 1,
    categories: [
        {
            id: 5,
            name: 'Tacos',
            slug: 'tacos',
            parent_id: null,
            items: [{ id: 1, name: 'Tacos M', slug: 'tacos-m', thumb: null, is_available: true, reason: null }],
        },
        {
            id: 6,
            name: 'Bols',
            slug: 'bols',
            parent_id: null,
            items: [],
        },
        // Stored LAST by sort, must render right under "Tacos" (id 5).
        {
            id: 21,
            name: 'Tacos Signature',
            slug: 'tacos-signature',
            parent_id: 5,
            items: [],
        },
    ],
    extra_groups: [],
    variation_groups: [],
    fetched_at: '2026-06-10T00:00:00Z',
};

function mountDashboard() {
    const i18n = createI18n({ legacy: true, locale: 'en', messages, silentTranslationWarn: true, silentFallbackWarn: true });
    const store = createStore({
        modules: {
            auth: {
                namespaced: true,
                state: { authBranchId: 0 },
                getters: { authBranchId: (s) => s.authBranchId },
            },
        },
    });
    return mount(StockRuptureDashboardComponent, {
        global: { plugins: [i18n, store] },
        props: { pollIntervalMs: 60_000 },
    });
}

describe('StockRuptureDashboardComponent — hierarchical category rail', () => {
    beforeEach(() => {
        axiosMock.get.mockReset();
        axiosMock.get.mockResolvedValue({ data: HIERARCHICAL_PAYLOAD });
    });

    it('renders the sub-category bucket immediately under its parent', async () => {
        const wrapper = mountDashboard();
        await flushPromises();

        const keys = wrapper
            .findAll('[data-testid^="stock-mgmt-bucket-cat-"]')
            .map((b) => b.attributes('data-testid'));

        expect(keys).toEqual([
            'stock-mgmt-bucket-cat-5',
            'stock-mgmt-bucket-cat-21',
            'stock-mgmt-bucket-cat-6',
        ]);
        wrapper.unmount();
    });

    it('indents the sub-category bucket (depth class + arrow)', async () => {
        const wrapper = mountDashboard();
        await flushPromises();

        const child = wrapper.find('[data-testid="stock-mgmt-bucket-cat-21"]');
        const parent = wrapper.find('[data-testid="stock-mgmt-bucket-cat-5"]');

        expect(child.classes()).toContain('pl-7');
        expect(child.text()).toContain('↳');
        expect(parent.classes()).not.toContain('pl-7');
        wrapper.unmount();
    });
});
