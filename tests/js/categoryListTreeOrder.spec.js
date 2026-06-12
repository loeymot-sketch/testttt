/**
 * [GOAL POLISH T-P1.2 2026-06-10 — A-003] La liste settings interleave les
 * sous-catégories sous leur parent (comme Studio + rail stock), et seules les
 * lignes top-level portent la poignée de drag. Mount-level.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import ItemCateogryListComponent from '../../resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue';

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => true),
        statusClass: vi.fn(() => 'status-active'),
        requestHandler: vi.fn(() => ''),
        destroyConfirmation: vi.fn(() => Promise.resolve()),
        modalShow: vi.fn(),
        modalHide: vi.fn(),
    },
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: { successFlip: vi.fn(), error: vi.fn() },
}));

const CATS = [
    { id: 1, name: 'Tacos', parent_id: null, status: 5 },
    { id: 2, name: 'Galette', parent_id: null, status: 5 },
    // stockée en DERNIER, doit se rendre juste sous Tacos
    { id: 9, name: 'Tacos Signature', parent_id: 1, status: 5 },
];

function mountList() {
    const store = createStore({
        modules: {
            itemCategory: {
                namespaced: true,
                state: { lists: CATS, pagination: [], page: {}, temp: { temp_id: null, isEditing: false } },
                getters: {
                    lists: (s) => s.lists,
                    pagination: (s) => s.pagination,
                    page: (s) => s.page,
                    temp: (s) => s.temp,
                },
                actions: {
                    lists: () => Promise.resolve({ data: { data: CATS } }),
                    edit: () => {},
                    reset: () => {},
                },
            },
        },
    });

    return mount(ItemCateogryListComponent, {
        global: {
            plugins: [store],
            mocks: {
                $t: (k) => k,
                $route: { query: {} },
                $router: { push: vi.fn() },
            },
            stubs: {
                LoadingComponent: true,
                ItemCategoryCreateComponent: true,
                PaginationTextComponent: true,
                PaginationBox: true,
                PaginationSMBox: true,
                TableLimitComponent: true,
                SmDeleteComponent: true,
                SmModalEditComponent: true,
                SmViewComponent: true,
                ExportComponent: true,
                CategoryUploadComponent: true,
                draggable: { template: '<tbody><slot /></tbody>' },
            },
        },
        props: { props: { form: {}, search: { paginate: 1, page: 1, per_page: 50 } } },
    });
}

describe('ItemCateogryListComponent — ordre arbre + drag top-level only', () => {
    it('rend la sous-catégorie immédiatement sous son parent', async () => {
        const wrapper = mountList();
        await flushPromises();

        const ids = wrapper
            .findAll('[data-testid^="admin-category-row-"]')
            .map((row) => row.attributes('data-testid'));

        expect(ids).toEqual([
            'admin-category-row-1',
            'admin-category-row-9',
            'admin-category-row-2',
        ]);
        wrapper.unmount();
    });

    it('ne met la poignée de drag que sur les lignes top-level', async () => {
        const wrapper = mountList();
        await flushPromises();

        expect(wrapper.find('[data-testid="admin-category-row-1"] .drag-handle').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-category-row-9"] .drag-handle').exists()).toBe(false);
        wrapper.unmount();
    });
});
