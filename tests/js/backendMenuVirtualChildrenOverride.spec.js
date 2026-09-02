import { describe, it, expect } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import BackendMenuComponent from '../../resources/js/components/layouts/backend/BackendMenuComponent.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: {
        fr: {
            menu: {
                items: 'Articles',
                catalog: 'Catalogue',
                item_attributes: "Attribut d'articles",
            },
        },
    },
});

function makeStore(authMenu) {
    return createStore({
        getters: {
            authMenu: () => authMenu,
            'frontendSetting/lists': () => ({ theme_logo: '/logo.png' }),
            'globalState/lists': () => ({ topSidebar: false }),
        },
    });
}

describe('BackendMenuComponent virtual children override', () => {
    it('remplace les children BDD legacy par VIRTUAL_CHILDREN pour url=items', () => {
        const authMenu = [
            {
                id: 10,
                url: 'items',
                language: 'items',
                icon: 'lab lab-item',
                children: [
                    {
                        url: 'items/legacy-old',
                        language: 'product_list',
                        icon: 'lab lab-list',
                    },
                ],
            },
        ];
        const wrapper = shallowMount(BackendMenuComponent, {
            global: {
                plugins: [makeStore(authMenu), i18n],
                stubs: { RouterLink: true },
                mocks: {
                    $route: { path: '/admin/dashboard' },
                },
            },
        });

        const itemsMenu = wrapper.vm.enrichedVisibleMenus.find((m) => m.url === 'items');
        expect(itemsMenu).toBeTruthy();
        // [GOAL DASHBOARD-PILOTABLE 2026-09-02] +1 : « Pages de wizard ». La bibliothèque de pages
        // (une page = une question au client + ses choix et leurs prix) est la porte d'entrée pour
        // monter le parcours d'une catégorie ; sans entrée de menu, l'écran existait sans être
        // atteignable. Compteur remonté DANS la session qui livre l'entrée.
        expect(itemsMenu.children.map((c) => c.url)).toEqual([
            'items/studio',
            'wizard-pages',
            'settings/item-attributes/list',
        ]);
        expect(itemsMenu.children.map((c) => c.language)).toEqual(['catalog', 'wizard_pages', 'item_attributes']);
    });

    it('garde le bloc sidebar items avec enfants virtuels (parent row masqué via config)', () => {
        const authMenu = [
            {
                id: 10,
                url: 'items',
                language: 'items',
                icon: 'lab lab-item',
                children: [{ url: 'items/list', language: 'product_list', icon: 'x' }],
            },
        ];
        const wrapper = shallowMount(BackendMenuComponent, {
            global: {
                plugins: [makeStore(authMenu), i18n],
                stubs: { RouterLink: true },
                mocks: {
                    $route: { path: '/admin/dashboard' },
                },
            },
        });

        expect(wrapper.vm.menusForSidebar.some((m) => m.url === 'items')).toBe(true);
        expect(wrapper.vm.showSidebarParentNavRow({ url: 'items' })).toBe(false);
    });
});
