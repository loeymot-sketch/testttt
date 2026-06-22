import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import IngredientListComponent from '../../resources/js/components/admin/ingredients/IngredientListComponent.vue';

const messages = {
    'label.ingredient.tablist_label': "Filtre par type d'ingrédient",
    'label.ingredient.table_caption': 'Liste des ingrédients avec leur statut de disponibilité',
    'label.ingredient.tab_all': 'Tous',
    'label.ingredient.tab_attribute': 'Viandes & Attributs',
    'label.ingredient.tab_extra': 'Suppléments',
    'label.ingredient.tab_addon': 'Add-ons',
    'label.ingredient.title': 'Ingrédients',
    'label.ingredient.subtitle': 'Gestion des viandes, sauces, suppléments et add-ons',
    'label.ingredient.column_name': 'Nom',
    'label.ingredient.column_type': 'Type',
    'label.ingredient.column_status': 'Statut',
    'label.ingredient.column_usage': 'Utilisation',
    'label.ingredient.column_actions': 'Actions',
    'label.ingredient.usage_count': 'Utilisé dans 2 étapes wizard',
    'label.ingredient.view_details': 'Voir les détails',
};

const ingredient = {
    global_id: 'attribute:1',
    type: 'attribute',
    name: 'Steak',
    group_label: 'Cuisson',
    is_available: true,
    unavailable_reason: null,
    used_by_count: 2,
};

function mountList() {
    const store = createStore({
        modules: {
            ingredients: {
                namespaced: true,
                state: () => ({
                    list: [ingredient],
                    loading: false,
                    error: null,
                    lastTogglingGlobalId: null,
                }),
                actions: {
                    fetch: vi.fn().mockResolvedValue({}),
                },
            },
        },
    });

    return mount(IngredientListComponent, {
        global: {
            plugins: [store],
            mocks: {
                $t: (key, params = {}) => {
                    if (key === 'label.ingredient.usage_count') return `Utilisé dans ${params.count} étapes wizard`;
                    return messages[key] || key;
                },
                $route: { params: {} },
            },
            stubs: {
                IngredientAvailabilityToggleComponent: true,
                IngredientUsageDrawer: true,
            },
        },
    });
}

describe('IngredientListComponent a11y', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the filters as a tablist', () => {
        const wrapper = mountList();

        expect(wrapper.get('nav[role="tablist"]').attributes('aria-label')).toBe("Filtre par type d'ingrédient");
    });

    it('uses tabs with aria-selected instead of aria-pressed', () => {
        const wrapper = mountList();
        const tabs = wrapper.findAll('[role="tab"]');

        expect(tabs).toHaveLength(4);
        expect(tabs[0].attributes('aria-selected')).toBe('true');
        expect(tabs.every((tab) => tab.attributes('aria-pressed') === undefined)).toBe(true);
    });

    it('keeps only the active tab in the tab order', () => {
        const wrapper = mountList();
        const tabs = wrapper.findAll('[role="tab"]');

        expect(tabs[0].attributes('tabindex')).toBe('0');
        expect(tabs.slice(1).every((tab) => tab.attributes('tabindex') === '-1')).toBe(true);
    });

    it('renders a caption and scoped table headers', () => {
        const wrapper = mountList();

        expect(wrapper.get('table caption').text()).toBe('Liste des ingrédients avec leur statut de disponibilité');
        expect(wrapper.findAll('thead th').every((header) => header.attributes('scope') === 'col')).toBe(true);
    });
});
