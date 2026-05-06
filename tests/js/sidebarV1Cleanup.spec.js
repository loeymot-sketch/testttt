import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MenuComponent from '../../resources/js/components/admin/settings/MenuComponent.vue';
import BackendMenuComponent from '../../resources/js/components/layouts/backend/BackendMenuComponent.vue';

const RouterLink = {
    props: ['to'],
    template: '<a><slot /></a>',
};

describe('V1 admin sidebar cleanup', () => {
    it('does not render legacy settings entries in the settings menu', () => {
        const wrapper = mount(MenuComponent, {
            global: {
                stubs: { RouterLink },
                mocks: { $t: (key) => key },
            },
        });

        const text = wrapper.text();
        expect(text).not.toContain('menu.role_permissions');
        expect(text).not.toContain('menu.taxes');
        expect(text).not.toContain('menu.languages');
        expect(text).not.toContain('menu.theme');
        expect(text).not.toContain('menu.otp');
        expect(text).not.toContain('menu.notification_alert');
        expect(text).not.toContain('menu.social_media');
        expect(text).not.toContain('menu.cookies');
        expect(text).not.toContain('menu.analytics');
        expect(text).not.toContain('menu.time_slots');
        expect(text).not.toContain('menu.sliders');
        expect(text).not.toContain('menu.pages');
        expect(text).not.toContain('menu.sms_gateway');
        expect(text).not.toContain('menu.payment_gateway');
        expect(text).not.toContain('menu.license');
    });

    it('renders dashboard + POS + V1 primary band (virtual catalogue children under items)', () => {
        const wrapper = mount(BackendMenuComponent, {
            global: {
                stubs: { RouterLink },
                mocks: {
                    $route: { path: '/admin/items/studio' },
                    $t: (key) => key,
                    $store: {
                        getters: {
                            'frontendSetting/lists': { theme_logo: '/logo.png' },
                            authMenu: [],
                            'globalState/lists': { topSidebar: false },
                        },
                        dispatch: () => Promise.resolve(),
                    },
                },
            },
        });

        const text = wrapper.text();
        expect(text).toContain('menu.stock_rupture');
        expect(text).toContain('menu.catalog');
        expect(text).toContain('menu.ingredients');
        expect(text).toContain('menu.pos_orders');
        // buildMergedSidebarMenus: dashboard, pos, rupture, items→studio+attrs, ingredients, pos-orders = 7 rows
        expect(wrapper.findAll('.db-sidebar-nav-menu')).toHaveLength(7);
    });
});
