import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import IngredientListComponent from '../../resources/js/components/admin/ingredients/IngredientListComponent.vue';

const messages = {
    'label.ingredient.tab_all': 'Tous',
    'label.ingredient.tab_attribute': 'Viandes & Attributs',
    'label.ingredient.tab_extra': 'Suppléments',
    'label.ingredient.tab_addon': 'Add-ons',
    'label.ingredient.title': 'Ingrédients',
    'label.ingredient.subtitle': 'Gestion des viandes, sauces, suppléments et add-ons',
    'label.ingredient.empty': 'Aucun ingrédient trouvé pour ce filtre.',
};

function mountList({ list = [], loading = false, error = null } = {}) {
    const fetch = vi.fn().mockResolvedValue({});
    const store = createStore({
        modules: {
            ingredients: {
                namespaced: true,
                state: () => ({ list, loading, error, lastTogglingGlobalId: null }),
                actions: { fetch },
            },
        },
    });

    const wrapper = mount(IngredientListComponent, {
        global: {
            plugins: [store],
            mocks: {
                $t: (key) => messages[key] || key,
                $route: { params: {} },
            },
            stubs: {
                IngredientAvailabilityToggleComponent: true,
                IngredientUsageDrawer: true,
            },
        },
    });

    return { wrapper, fetch };
}

describe('IngredientListComponent', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('displays the four ingredient tabs', () => {
        const { wrapper } = mountList();

        expect(wrapper.text()).toContain('Tous');
        expect(wrapper.text()).toContain('Viandes & Attributs');
        expect(wrapper.text()).toContain('Suppléments');
        expect(wrapper.text()).toContain('Add-ons');
    });

    it('fetches extras when the Suppléments tab is selected', async () => {
        const { wrapper, fetch } = mountList();

        await wrapper.findAll('button').find((button) => button.text() === 'Suppléments').trigger('click');

        expect(fetch).toHaveBeenLastCalledWith(expect.anything(), { type: 'extra' });
    });

    /*
     * [ONB 2026-08-28] Ce banc s'appelait « when no ingredients match THE FILTER »
     * mais montait le composant sur l'onglet PAR DEFAUT — donc sans aucun filtre.
     * Il mesurait le cas « rien du tout » en pretendant mesurer « rien qui
     * corresponde », et epinglait la phrase qui confond precisement les deux.
     *
     * Un commercant qui vient d'installer n'a AUCUN ingredient et n'a pose AUCUN
     * filtre : lui dire « trouve pour ce filtre » l'envoie chercher un filtre qui
     * n'existe pas. On mesure desormais les deux branches, separement.
     */
    it("sans aucun ingrédient et sans filtre, l'écran ne parle pas de filtre", () => {
        const { wrapper } = mountList({ list: [] });

        const texte = wrapper.find('[data-testid="ingredient-empty"]').text();

        expect(texte).toContain('label.ingredient.empty_all');
        expect(texte).not.toContain('empty_filtered');
    });

    it("sur un onglet sélectionné, l'écran renvoie bien vers « Tous »", async () => {
        const { wrapper } = mountList({ list: [] });

        await wrapper.findAll('button').find((button) => button.text() === 'Suppléments').trigger('click');

        expect(wrapper.find('[data-testid="ingredient-empty"]').text())
            .toContain('label.ingredient.empty_filtered');
    });
});
