import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import IngredientUsageDrawer from '../../resources/js/components/admin/ingredients/IngredientUsageDrawer.vue';
import { showIngredient } from '../../resources/js/services/ingredientService';

vi.mock('../../resources/js/services/ingredientService', () => ({
    showIngredient: vi.fn(),
}));

function mountDrawer() {
    return mount(IngredientUsageDrawer, {
        attachTo: document.body,
        props: {
            globalId: 'attribute:1',
            isOpen: true,
        },
        global: {
            stubs: {
                teleport: true,
            },
            mocks: {
                $t: (key, params = {}) => {
                    if (key === 'label.ingredient.usage_drawer_title') return "Utilisation de l'ingrédient";
                    if (key === 'label.ingredient.usage_drawer_close') return 'Fermer';
                    if (key === 'label.ingredient.usage_count') return `Utilisé dans ${params.count} étapes wizard`;
                    return key;
                },
            },
        },
    });
}

describe('IngredientUsageDrawer', () => {
    beforeEach(() => {
        vi.mocked(showIngredient).mockReset();
        vi.mocked(showIngredient).mockResolvedValue({
            data: { data: { used_by_count: 3 } },
        });
        document.body.innerHTML = '';
    });

    it('displays the drawer title', async () => {
        const wrapper = mountDrawer();
        await flushPromises();

        expect(wrapper.text()).toContain("Utilisation de l'ingrédient");

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
