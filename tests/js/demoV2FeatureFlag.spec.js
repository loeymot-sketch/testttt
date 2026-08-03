import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MenuComponent from '../../resources/js/components/admin/settings/MenuComponent.vue';
import itemRoutes, { requireWizardPerItemDemo } from '../../resources/js/router/modules/itemRoutes.js';

const RouterLink = {
    props: ['to'],
    template: '<a><slot /></a>',
};

function setFlag(enabled) {
    window.foodkingConfig = {
        features: {
            wizard_per_item_demo: enabled,
        },
    };
}

describe('Demo V2 feature flag isolation', () => {
    afterEach(() => {
        delete window.foodkingConfig;
    });

    it('does not show the advanced wizard menu item when the flag is false', () => {
        setFlag(false);
        const wrapper = mount(MenuComponent, {
            global: {
                stubs: { RouterLink },
                mocks: { $t: (key) => key },
            },
        });

        expect(wrapper.text()).not.toContain('menu.demo_wizard_advanced');
    });

    it('shows the advanced wizard menu item when the flag is true', () => {
        setFlag(true);
        const wrapper = mount(MenuComponent, {
            global: {
                stubs: { RouterLink },
                mocks: { $t: (key) => key },
            },
        });

        expect(wrapper.text()).toContain('menu.advanced_tools');
        expect(wrapper.text()).toContain('menu.demo_wizard_advanced');
    });

    it('redirects /admin/items/:id/composer to Catalog Studio when the flag is false', () => {
        setFlag(false);
        const next = vi.fn();

        requireWizardPerItemDemo({ name: 'admin.items.composer', params: { id: 42 } }, {}, next);

        expect(next).toHaveBeenCalledWith({ name: 'admin.items.studio' });
    });

    it('allows /admin/items/:id/composer when the flag is true', () => {
        setFlag(true);
        const next = vi.fn();
        const route = itemRoutes.find(candidate => candidate.name === 'admin.items.composer');

        expect(route.path).toBe('/admin/items/:id/composer');
        route.beforeEnter({ name: 'admin.items.composer', params: { id: 42 } }, {}, next);

        expect(next).toHaveBeenCalledWith();
    });
});
