import { describe, it, expect } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import BackendMenuComponent from '../../resources/js/components/layouts/backend/BackendMenuComponent.vue';

/**
 * [hub-sidebar-lands-on-catalogue-tab 2026-07-22 / Vague 2 STOCK-HARDENING]
 * The "Produits & Stock" sidebar entry points at the CatalogHub, which defaults to
 * the Catalogue tab — so clicking it landed on Catalogue, not Stock. It must deep-link
 * to the hub's existing `?tab=stock` while keeping the bare `catalog-hub` url for the
 * `items` permission gate + dedup.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: { menu: { stock_rupture: 'Produits & Stock', dashboard: 'D', pos: 'P' } } },
});

function makeStore(authPermission = null) {
    return createStore({
        getters: {
            authMenu: () => [],
            authPermission: () => authPermission,
            'frontendSetting/lists': () => ({ theme_logo: '/logo.png' }),
            'globalState/lists': () => ({ topSidebar: false }),
        },
    });
}

function mountMenu(authPermission = null) {
    return shallowMount(BackendMenuComponent, {
        global: {
            plugins: [makeStore(authPermission), i18n],
            stubs: { RouterLink: true },
            mocks: { $route: { path: '/admin/dashboard' } },
        },
    });
}

describe('BackendMenu — Produits & Stock deep-links to the Stock tab', () => {
    it('the catalog-hub entry carries the ?tab=stock deep-link query', () => {
        const wrapper = mountMenu();
        const hub = wrapper.vm.menusForSidebar.find((m) => m.url === 'catalog-hub');
        expect(hub).toBeTruthy();
        expect(hub.query).toBe('?tab=stock');
    });

    it('renders the sidebar link pointing at /admin/catalog-hub?tab=stock', () => {
        const wrapper = mountMenu();
        expect(wrapper.html()).toContain('/admin/catalog-hub?tab=stock');
    });

    it('keeps the bare catalog-hub url so the items permission gate still applies', () => {
        // Denying `items` access must hide the entry — proves the permission mapping
        // still keys on the canonical `catalog-hub` url, not the query-suffixed link.
        const wrapper = mountMenu([{ url: 'items', access: false }]);
        expect(wrapper.vm.menuPathAllowed('catalog-hub')).toBe(false);
        expect(wrapper.vm.menusForSidebar.some((m) => m.url === 'catalog-hub')).toBe(false);
    });
});
