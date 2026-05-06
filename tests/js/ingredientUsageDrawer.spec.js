import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import IngredientUsageDrawer from '../../resources/js/components/admin/ingredients/IngredientUsageDrawer.vue';
import { getIngredientUsage } from '../../resources/js/services/ingredientService';

vi.mock('../../resources/js/services/ingredientService', () => ({
    getIngredientUsage: vi.fn(),
}));

const T = {
    usage_drawer_title: "Utilisation de l'ingrédient",
    usage_drawer_close: 'Fermer',
    usage_count: (count) => `Utilisé dans ${count} étapes wizard`,
    loading: 'Chargement…',
    error: 'Impossible de charger les ingrédients.',
    status_unavailable: 'En rupture',
    usage_empty: "Aucun produit ni catégorie n'utilise cet ingrédient.",
    owner_category: 'Catégorie',
    owner_item: 'Article (legacy)',
};

function translate(key, params = {}) {
    if (key === 'label.ingredient.usage_drawer_title') return T.usage_drawer_title;
    if (key === 'label.ingredient.usage_drawer_close') return T.usage_drawer_close;
    if (key === 'label.ingredient.usage_count') return T.usage_count(params.count);
    if (key === 'label.ingredient.loading') return T.loading;
    if (key === 'label.ingredient.error') return T.error;
    if (key === 'label.ingredient.status_unavailable') return T.status_unavailable;
    if (key === 'label.ingredient.usage_empty') return T.usage_empty;
    if (key === 'label.ingredient.owner_category') return T.owner_category;
    if (key === 'label.ingredient.owner_item') return T.owner_item;
    return key;
}

function mountDrawer(props = {}) {
    return mount(IngredientUsageDrawer, {
        attachTo: document.body,
        props: {
            globalId: 'attribute:1',
            isOpen: true,
            ...props,
        },
        global: {
            stubs: {
                teleport: true,
            },
            mocks: {
                $t: translate,
            },
        },
    });
}

describe('IngredientUsageDrawer', () => {
    beforeEach(() => {
        vi.mocked(getIngredientUsage).mockReset();
        vi.mocked(getIngredientUsage).mockResolvedValue({
            data: {
                data: {
                    name: 'Bœuf',
                    is_available: true,
                    used_by_count: 0,
                    used_by: [],
                },
            },
        });
        document.body.innerHTML = '';
    });

    it('displays the drawer title', async () => {
        const wrapper = mountDrawer();
        await flushPromises();

        expect(wrapper.text()).toContain(T.usage_drawer_title);

        wrapper.unmount();
    });

    it('renders two usage entries with correct admin URLs', async () => {
        vi.mocked(getIngredientUsage).mockResolvedValue({
            data: {
                data: {
                    name: 'Bœuf',
                    is_available: true,
                    used_by_count: 2,
                    used_by: [
                        {
                            owner_type: 'category',
                            owner_id: 5,
                            owner_name: 'Burgers',
                            step_label: 'Choix viande',
                            wizard_profile_id: 8,
                            admin_url: '/admin/categories/5/composer',
                        },
                        {
                            owner_type: 'item',
                            owner_id: 17,
                            owner_name: 'Burger Maison Legacy',
                            step_label: 'Viande',
                            wizard_profile_id: 12,
                            admin_url: '/admin/items/17/composer',
                        },
                    ],
                },
            },
        });

        const wrapper = mountDrawer();
        await flushPromises();

        const lis = wrapper.findAll('[data-testid="ingredient-usage-list"] li');
        expect(lis.length).toBe(2);

        const catLink = wrapper.get('[data-testid="ingredient-usage-entry-category-5"] a');
        const itemLink = wrapper.get('[data-testid="ingredient-usage-entry-item-17"] a');
        expect(catLink.attributes('href')).toBe('/admin/categories/5/composer');
        expect(itemLink.attributes('href')).toBe('/admin/items/17/composer');

        wrapper.unmount();
    });

    it('renders empty state when used_by is empty', async () => {
        vi.mocked(getIngredientUsage).mockResolvedValue({
            data: {
                data: {
                    name: 'Sauce',
                    is_available: true,
                    used_by_count: 0,
                    used_by: [],
                },
            },
        });

        const wrapper = mountDrawer();
        await flushPromises();

        expect(wrapper.get('[data-testid="ingredient-usage-empty"]').text()).toContain(T.usage_empty);

        wrapper.unmount();
    });

    it('shows unavailable badge when ingredient is out of stock', async () => {
        vi.mocked(getIngredientUsage).mockResolvedValue({
            data: {
                data: {
                    name: 'Bœuf',
                    is_available: false,
                    used_by_count: 0,
                    used_by: [],
                },
            },
        });

        const wrapper = mountDrawer();
        await flushPromises();

        expect(wrapper.get('[data-testid="ingredient-usage-name"]').text()).toContain(T.status_unavailable);

        wrapper.unmount();
    });

    it('shows loading state while the request is pending', async () => {
        let resolvePromise;
        const pending = new Promise((resolve) => {
            resolvePromise = resolve;
        });
        vi.mocked(getIngredientUsage).mockReturnValue(pending);

        const wrapper = mountDrawer();

        expect(wrapper.get('[data-testid="ingredient-usage-loading"]').text()).toContain(T.loading);

        resolvePromise({
            data: {
                data: {
                    name: 'X',
                    is_available: true,
                    used_by_count: 0,
                    used_by: [],
                },
            },
        });
        await flushPromises();

        wrapper.unmount();
    });

    it('shows error state when the request fails', async () => {
        vi.mocked(getIngredientUsage).mockRejectedValue(new Error('network'));

        const wrapper = mountDrawer();
        await flushPromises();

        expect(wrapper.get('[data-testid="ingredient-usage-error"]').text()).toContain(T.error);

        wrapper.unmount();
    });

    it('emits close when Escape is pressed', async () => {
        const wrapper = mountDrawer();
        await flushPromises();

        await wrapper.find('[data-testid="ingredient-usage-drawer"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.emitted('close')).toBeTruthy();

        wrapper.unmount();
    });

    it('emits close when the backdrop is clicked', async () => {
        const wrapper = mountDrawer();
        await flushPromises();

        await wrapper.find('[data-testid="ingredient-usage-backdrop"]').trigger('click');

        expect(wrapper.emitted('close')).toBeTruthy();

        wrapper.unmount();
    });
});
