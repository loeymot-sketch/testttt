import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import ItemCategoryCreateComponent from '../../resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue';

/**
 * [GOAL CMS GESTION 2026-06-10 — Wave C1, T-C1.1]
 * Sélecteur de catégorie parente (sous-catégories) dans le modal CRUD :
 *  - options = catégories top-level uniquement (profondeur 2 max)
 *  - la catégorie en cours d'édition est exclue (pas de self-parent)
 *  - mount-level (comportemental), pas un sentinel source-string.
 */

const messages = {
    en: {
        label: {
            parent_category: 'Parent category',
            none: 'None',
            name: 'Name',
            image: 'Image',
            status: 'Status',
            active: 'Active',
            inactive: 'Inactive',
            description: 'Description',
        },
        message: { subcategory_wizard_hint: 'hint' },
        menu: { item_categories: 'Categories' },
        button: { add_item_category: 'Add category', save: 'Save', close: 'Close' },
    },
};

function mountForm({ lists = [], tempId = null } = {}) {
    const store = createStore({
        modules: {
            itemCategory: {
                namespaced: true,
                state: { lists, temp: { temp_id: tempId, isEditing: tempId !== null } },
                getters: {
                    lists: (state) => state.lists,
                    temp: (state) => state.temp,
                },
                actions: { reset: () => {}, save: () => Promise.resolve({}) },
            },
        },
    });
    const i18n = createI18n({ legacy: true, locale: 'en', messages, silentTranslationWarn: true, silentFallbackWarn: true });

    return mount(ItemCategoryCreateComponent, {
        global: {
            plugins: [store, i18n],
            stubs: { SmModalCreateComponent: true, LoadingComponent: true },
        },
        props: {
            props: {
                form: {
                    name: '',
                    description: '',
                    status: 5,
                    parent_id: null,
                    wizard_template: 'simple',
                    has_menu: 0,
                    default_menu_kiosk: 0,
                    sauce_included_menu: 0,
                    kiosk_upsell_include: 1,
                    kiosk_upsell_skip_after_cart: 0,
                },
                search: {},
            },
        },
    });
}

const CATS = [
    { id: 1, name: 'Tacos', parent_id: null },
    { id: 2, name: 'Sandwich Cayenne', parent_id: null },
    { id: 3, name: 'Signature', parent_id: 2 },
];

describe('ItemCategoryCreateComponent — parent selector (sub-categories)', () => {
    it('renders the parent select with a none option', () => {
        const wrapper = mountForm({ lists: CATS });
        const select = wrapper.find('[data-testid="admin-category-form-parent"]');
        expect(select.exists()).toBe(true);

        const optionLabels = select.findAll('option').map((o) => o.text());
        expect(optionLabels[0]).toBe('None');
    });

    it('offers only top-level categories as parents (depth 2 max)', () => {
        const wrapper = mountForm({ lists: CATS });
        const labels = wrapper
            .find('[data-testid="admin-category-form-parent"]')
            .findAll('option')
            .map((o) => o.text());

        expect(labels).toContain('Tacos');
        expect(labels).toContain('Sandwich Cayenne');
        expect(labels).not.toContain('Signature');
    });

    it('excludes the category being edited from parent options', () => {
        const wrapper = mountForm({ lists: CATS, tempId: 2 });
        const labels = wrapper
            .find('[data-testid="admin-category-form-parent"]')
            .findAll('option')
            .map((o) => o.text());

        expect(labels).not.toContain('Sandwich Cayenne');
        expect(labels).toContain('Tacos');
    });

    it('binds the selection to form.parent_id', async () => {
        const wrapper = mountForm({ lists: CATS });
        const select = wrapper.find('[data-testid="admin-category-form-parent"]');
        await select.setValue('1');

        expect(Number(wrapper.props('props').form.parent_id)).toBe(1);
    });
});
