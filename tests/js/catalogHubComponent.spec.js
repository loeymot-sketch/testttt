/**
 * [CATALOG-HUB 2026-07-21] Owner-approved unification.
 *
 * CatalogHubComponent is a thin accessible TAB WRAPPER that hosts the two
 * catalogue-side admin screens on a single URL (`/admin/catalog-hub`) without
 * merging their internals:
 *   - Tab "Catalogue" → mounts <CatalogStudioComponent> as-is
 *   - Tab "Stock"     → mounts <StockRuptureDashboardComponent> as-is
 *
 * The two heavy children are mocked here so this spec isolates the wrapper's
 * tab logic (a11y roles, roving tabindex, keyboard nav, ?tab= deep-link, lazy
 * mount of only the active child). A second block locks the router + menu
 * wiring at the source level.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// --- Isolate the wrapper from the heavy children -----------------------------
vi.mock('../../resources/js/components/admin/items/CatalogStudioComponent.vue', () => ({
    default: {
        name: 'CatalogStudioComponent',
        template: '<div data-testid="stub-catalog-studio"></div>',
    },
}));
vi.mock('../../resources/js/components/admin/stock/StockRuptureDashboardComponent.vue', () => ({
    default: {
        name: 'StockRuptureDashboardComponent',
        template: '<div data-testid="stub-stock-dashboard"></div>',
    },
}));

import CatalogHubComponent from '../../resources/js/components/admin/items/CatalogHubComponent.vue';

function mountHub({ query = {}, replace } = {}) {
    const replaceFn = replace || vi.fn(() => Promise.resolve());
    return mount(CatalogHubComponent, {
        global: {
            mocks: {
                $t: (key) => key,
                $route: { query },
                $router: { replace: replaceFn },
            },
        },
    });
}

describe('CatalogHubComponent — accessible tab wrapper', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders exactly two ARIA tabs with stable testids', () => {
        const wrapper = mountHub();
        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs).toHaveLength(2);
        expect(wrapper.find('[data-testid="catalog-hub-tab-catalogue"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="catalog-hub-tab-stock"]').exists()).toBe(true);
        expect(wrapper.find('[role="tablist"]').exists()).toBe(true);
    });

    it('defaults to the catalogue tab and mounts only that child', () => {
        const wrapper = mountHub();
        expect(wrapper.vm.activeTab).toBe('catalogue');
        expect(wrapper.find('[data-testid="catalog-hub-tab-catalogue"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.find('[data-testid="catalog-hub-tab-stock"]').attributes('aria-selected')).toBe('false');
        // Only the active child is mounted (lazy — keeps the inactive screen's
        // timers / polling / Echo idle).
        expect(wrapper.find('[data-testid="stub-catalog-studio"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stub-stock-dashboard"]').exists()).toBe(false);
    });

    it('applies roving tabindex (active=0, inactive=-1)', () => {
        const wrapper = mountHub();
        expect(wrapper.find('[data-testid="catalog-hub-tab-catalogue"]').attributes('tabindex')).toBe('0');
        expect(wrapper.find('[data-testid="catalog-hub-tab-stock"]').attributes('tabindex')).toBe('-1');
    });

    it('clicking the Stock tab activates it, mounts its child, and syncs ?tab=stock', async () => {
        const replace = vi.fn(() => Promise.resolve());
        const wrapper = mountHub({ replace });
        await wrapper.find('[data-testid="catalog-hub-tab-stock"]').trigger('click');
        expect(wrapper.vm.activeTab).toBe('stock');
        expect(wrapper.find('[data-testid="stub-stock-dashboard"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stub-catalog-studio"]').exists()).toBe(false);
        expect(replace).toHaveBeenCalledWith({ query: { tab: 'stock' } });
    });

    it('deep-links to the stock tab from ?tab=stock', () => {
        const wrapper = mountHub({ query: { tab: 'stock' } });
        expect(wrapper.vm.activeTab).toBe('stock');
        expect(wrapper.find('[data-testid="catalog-hub-tab-stock"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.find('[data-testid="stub-stock-dashboard"]').exists()).toBe(true);
    });

    it('falls back to catalogue for an unknown ?tab= value', () => {
        const wrapper = mountHub({ query: { tab: 'bogus' } });
        expect(wrapper.vm.activeTab).toBe('catalogue');
    });

    it('supports ArrowRight / ArrowLeft / Home / End keyboard navigation', async () => {
        const wrapper = mountHub();
        const tablist = wrapper.find('[role="tablist"]');

        await tablist.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.vm.activeTab).toBe('stock');

        await tablist.trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.vm.activeTab).toBe('catalogue');

        await tablist.trigger('keydown', { key: 'End' });
        expect(wrapper.vm.activeTab).toBe('stock');

        await tablist.trigger('keydown', { key: 'Home' });
        expect(wrapper.vm.activeTab).toBe('catalogue');
    });

    it('binds each tabpanel to its tab via aria-controls / aria-labelledby', () => {
        const wrapper = mountHub();
        const panelCat = wrapper.find('[data-testid="catalog-hub-panel-catalogue"]');
        const panelStock = wrapper.find('[data-testid="catalog-hub-panel-stock"]');
        expect(panelCat.attributes('role')).toBe('tabpanel');
        expect(panelCat.attributes('aria-labelledby')).toBe('catalog-hub-tab-catalogue');
        expect(panelStock.attributes('aria-labelledby')).toBe('catalog-hub-tab-stock');
        expect(wrapper.find('[data-testid="catalog-hub-tab-catalogue"]').attributes('aria-controls')).toBe('catalog-hub-panel-catalogue');
    });
});

describe('CatalogHub — router + menu wiring (source-level lock)', () => {
    const root = resolve(process.cwd());
    const itemRoutes = readFileSync(resolve(root, 'resources/js/router/modules/itemRoutes.js'), 'utf8');
    const stockRoutes = readFileSync(resolve(root, 'resources/js/router/modules/stockRoutes.js'), 'utf8');
    const backendMenu = readFileSync(resolve(root, 'resources/js/components/layouts/backend/BackendMenuComponent.vue'), 'utf8');

    it('registers the hub route with name admin.catalog.hub + permissionUrl items', () => {
        expect(itemRoutes).toContain('CatalogHubComponent');
        expect(itemRoutes).toContain("path: '/admin/catalog-hub'");
        expect(itemRoutes).toContain("name: 'admin.catalog.hub'");
    });

    it('keeps BOTH original routes alive for deep-links', () => {
        expect(itemRoutes).toContain("name: 'admin.items.studio'");
        expect(stockRoutes).toContain('admin.stock.rupture');
        expect(stockRoutes).toContain('StockRuptureDashboardComponent');
    });

    it('points a single menu entry at the hub, gated by the items permission', () => {
        expect(backendMenu).toContain("url: 'catalog-hub'");
        expect(backendMenu).toContain("'catalog-hub': 'items'");
    });
});
