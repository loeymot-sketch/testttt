/**
 * [WAVE1 CENTRAL heal 2026-06-06 — DASH-01 / DASH-03 / DASH-04]
 *
 * Behavioral Vitest specs (@vue/test-utils mount + Vuex mock + fake timers)
 * for the central dashboard real-time integrity defects
 * (reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md):
 *
 *  • DASH-04 — SlaAlertsComponent + StockLowAlertsWidget rendered a reassuring
 *    'all good' empty-state on API FAILURE (false-negative). After the heal a
 *    distinct neutral 'Données indisponibles' error state renders instead, and
 *    self-heals (clears) on the next successful fetch.
 *
 *  • DASH-01 — OverviewComponent + ChannelStatsComponent fetched once on mount
 *    with no auto-refresh → stale headline KPIs until a full reload. After the
 *    heal they poll on an interval (mirroring RealtimeReportComponent) and clear
 *    the timer on beforeUnmount.
 *
 *  • DASH-03 — CustomerStatsComponent + TopCustomersComponent were orphaned
 *    (backend computes, nothing renders). After the heal DashboardComponent
 *    imports + registers + mounts both.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { readFileSync } from 'fs';
import { resolve } from 'path';

import SlaAlertsComponent from '../../resources/js/components/admin/dashboard/SlaAlertsComponent.vue';
import OverviewComponent from '../../resources/js/components/admin/dashboard/OverviewComponent.vue';
import ChannelStatsComponent from '../../resources/js/components/admin/dashboard/ChannelStatsComponent.vue';

const stubs = { LoadingComponent: true, RouterLink: true, 'router-link': true };
const mocks = { $t: (key) => key };

function makeStore(actions) {
    return createStore({
        modules: {
            dashboard: {
                namespaced: true,
                actions,
            },
        },
    });
}

// =============================================================================
// DASH-04 — error state instead of false 'all good'
// =============================================================================
describe('DASH-04 — SlaAlertsComponent surfaces error state on API failure', () => {
    it('renders the distinct "Données indisponibles" error state (NOT the green all-good) when the fetch rejects', async () => {
        vi.useFakeTimers();
        const slaAlerts = vi.fn().mockRejectedValue(new Error('Network down'));
        const wrapper = mount(SlaAlertsComponent, {
            global: { plugins: [makeStore({ slaAlerts })], stubs, mocks },
        });
        await flushPromises();

        // Error state visible…
        expect(wrapper.find('[data-testid="sla-alerts-error"]').exists()).toBe(true);
        // …and the reassuring green empty-state is NOT shown.
        expect(wrapper.find('[data-testid="sla-alerts-empty"]').exists()).toBe(false);
        // …and the "0 Alerte(s)" badge must NOT masquerade as a healthy count.
        expect(wrapper.find('[data-testid="sla-alerts-count"]').exists()).toBe(false);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('self-heals: error clears and empty state returns on the next successful fetch', async () => {
        vi.useFakeTimers();
        const slaAlerts = vi
            .fn()
            .mockRejectedValueOnce(new Error('boom'))
            .mockResolvedValue({ data: { data: [] } });

        const wrapper = mount(SlaAlertsComponent, {
            global: { plugins: [makeStore({ slaAlerts })], stubs, mocks },
        });
        await flushPromises();
        expect(wrapper.find('[data-testid="sla-alerts-error"]').exists()).toBe(true);

        // 15s poll fires the (now resolving) second fetch.
        await vi.advanceTimersByTimeAsync(15000);
        await flushPromises();

        expect(wrapper.find('[data-testid="sla-alerts-error"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="sla-alerts-empty"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('shows the green empty-state only on a genuine success with zero alerts', async () => {
        vi.useFakeTimers();
        const slaAlerts = vi.fn().mockResolvedValue({ data: { data: [] } });
        const wrapper = mount(SlaAlertsComponent, {
            global: { plugins: [makeStore({ slaAlerts })], stubs, mocks },
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="sla-alerts-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sla-alerts-error"]').exists()).toBe(false);

        vi.useRealTimers();
        wrapper.unmount();
    });
});

describe('DASH-04 — StockLowAlertsWidget surfaces error state on API failure', () => {
    // StockLowAlertsWidget calls axios directly; mock the module.
    it('renders the error state (not the "no low alerts" reassurance) when the request fails', async () => {
        vi.resetModules();
        vi.doMock('axios', () => ({
            default: { get: vi.fn().mockRejectedValue(new Error('500')) },
        }));
        const { default: StockLowAlertsWidget } = await import(
            '../../resources/js/components/admin/dashboard/StockLowAlertsWidget.vue'
        );

        const store = createStore({
            getters: { authPermission: () => [] }, // empty → default-allow → fetch fires
        });
        const wrapper = mount(StockLowAlertsWidget, {
            global: { plugins: [store], stubs, mocks },
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="stock-low-alerts-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-low-alerts-empty"]').exists()).toBe(false);

        wrapper.unmount();
        vi.doUnmock('axios');
        vi.resetModules();
    });

    it('renders the "no low alerts" empty-state on a genuine empty success', async () => {
        vi.resetModules();
        vi.doMock('axios', () => ({
            default: { get: vi.fn().mockResolvedValue({ data: { alerts: [] } }) },
        }));
        const { default: StockLowAlertsWidget } = await import(
            '../../resources/js/components/admin/dashboard/StockLowAlertsWidget.vue'
        );

        const store = createStore({ getters: { authPermission: () => [] } });
        const wrapper = mount(StockLowAlertsWidget, {
            global: { plugins: [store], stubs, mocks },
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="stock-low-alerts-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stock-low-alerts-error"]').exists()).toBe(false);

        wrapper.unmount();
        vi.doUnmock('axios');
        vi.resetModules();
    });
});

// =============================================================================
// DASH-01 — auto-refresh on interval + beforeUnmount cleanup
// =============================================================================
describe('DASH-01 — OverviewComponent auto-refreshes headline KPIs', () => {
    it('re-fetches all three KPIs on the refresh interval and clears the timer on unmount', async () => {
        vi.useFakeTimers();
        const clearSpy = vi.spyOn(global, 'clearInterval');

        const totalSales = vi.fn().mockResolvedValue({ data: { data: { total_sales: '0,00 €' } } });
        const totalOrders = vi.fn().mockResolvedValue({ data: { data: { total_orders: 0 } } });
        const totalMenuItems = vi.fn().mockResolvedValue({ data: { data: { total_menu_items: 45 } } });

        const wrapper = mount(OverviewComponent, {
            global: { plugins: [makeStore({ totalSales, totalOrders, totalMenuItems })], stubs, mocks },
        });
        await flushPromises();

        // Initial mount = 1 call each.
        expect(totalSales).toHaveBeenCalledTimes(1);
        expect(totalOrders).toHaveBeenCalledTimes(1);
        expect(totalMenuItems).toHaveBeenCalledTimes(1);

        // Advance one 30s interval → a second fetch of each.
        await vi.advanceTimersByTimeAsync(30000);
        await flushPromises();
        expect(totalSales).toHaveBeenCalledTimes(2);
        expect(totalOrders).toHaveBeenCalledTimes(2);
        expect(totalMenuItems).toHaveBeenCalledTimes(2);

        wrapper.unmount();
        expect(clearSpy).toHaveBeenCalled();

        clearSpy.mockRestore();
        vi.useRealTimers();
    });

    it('does NOT flash the loading overlay on timer-driven refresh (silent refresh)', async () => {
        vi.useFakeTimers();
        const totalSales = vi.fn().mockResolvedValue({ data: { data: { total_sales: '0,00 €' } } });
        const totalOrders = vi.fn().mockResolvedValue({ data: { data: { total_orders: 0 } } });
        const totalMenuItems = vi.fn().mockResolvedValue({ data: { data: { total_menu_items: 45 } } });

        const wrapper = mount(OverviewComponent, {
            global: { plugins: [makeStore({ totalSales, totalOrders, totalMenuItems })], stubs, mocks },
        });
        await flushPromises();
        // settled after initial load
        expect(wrapper.vm.loading.isActive).toBe(false);

        // Drive a refresh; loading must remain false the whole time (no spinner flash).
        await vi.advanceTimersByTimeAsync(30000);
        // Right after the tick, before promises resolve, loading must still be false.
        expect(wrapper.vm.loading.isActive).toBe(false);
        await flushPromises();
        expect(wrapper.vm.loading.isActive).toBe(false);

        wrapper.unmount();
        vi.useRealTimers();
    });
});

describe('DASH-01 — ChannelStatsComponent auto-refreshes', () => {
    it('re-fetches channel stats on the interval and clears the timer on unmount', async () => {
        vi.useFakeTimers();
        const clearSpy = vi.spyOn(global, 'clearInterval');

        const channelStatistics = vi
            .fn()
            .mockResolvedValue({ data: { data: [{ name: 'Web', value: 0 }] } });

        const wrapper = mount(ChannelStatsComponent, {
            global: { plugins: [makeStore({ channelStatistics })], stubs, mocks },
        });
        await flushPromises();
        expect(channelStatistics).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(30000);
        await flushPromises();
        expect(channelStatistics).toHaveBeenCalledTimes(2);

        wrapper.unmount();
        expect(clearSpy).toHaveBeenCalled();

        clearSpy.mockRestore();
        vi.useRealTimers();
    });
});

// =============================================================================
// DASH-03 — orphaned CustomerStats + TopCustomers mounted in DashboardComponent
// =============================================================================
describe('DASH-03 — DashboardComponent mounts CustomerStats + TopCustomers', () => {
    const dashSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/dashboard/DashboardComponent.vue'),
        'utf-8',
    );

    it('imports CustomerStatsComponent and TopCustomersComponent', () => {
        expect(dashSource).toMatch(/import\s+CustomerStatsComponent\s+from\s+["']\.\/CustomerStatsComponent["']/);
        expect(dashSource).toMatch(/import\s+TopCustomersComponent\s+from\s+["']\.\/TopCustomersComponent["']/);
    });

    it('registers both in the components map', () => {
        expect(dashSource).toContain('CustomerStatsComponent,');
        expect(dashSource).toContain('TopCustomersComponent,');
    });

    it('mounts both in the template', () => {
        expect(dashSource).toMatch(/<CustomerStatsComponent\s*\/>/);
        expect(dashSource).toMatch(/<TopCustomersComponent\s*\/>/);
    });
});
