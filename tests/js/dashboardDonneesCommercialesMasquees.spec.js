/**
 * [VG-2026-10-21 M9-104] Le cuisinier voyait « Ventes du jour », « Ticket moyen », la répartition
 * par canal et le journal d'audit sur le tableau de bord. Le serveur exige désormais `pos` ou
 * `sales-report` pour ces données ; l'écran ne monte plus ces cartes sans cette permission
 * (sinon : appels voués au 403 et tuiles « … » qui ne se remplissent jamais).
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(() => new Promise(() => {})), post: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
globalThis.axios = h.axios;

import DashboardComponent from '../../resources/js/components/admin/dashboard/DashboardComponent.vue';
import OverviewComponent from '../../resources/js/components/admin/dashboard/OverviewComponent.vue';

const COMMERCIALES = ['RealtimeReportComponent', 'ChannelStatsComponent', 'AuditTrailComponent', 'SalesSummaryComponent'];
const EXPLOITATION = ['OrderStatisticsComponent', 'OrderSummaryComponent', 'SlaAlertsComponent', 'MostPopularItemsComponent', 'DlcWatchWidget'];

const monterTableau = (perms) => mount(DashboardComponent, {
    global: {
        mocks: { $t: (k) => k, $store: { getters: { authPermission: perms, authInfo: { name: 'X' } } } },
        stubs: {
            LoadingComponent: true, OverviewComponent: true, OrderStatisticsComponent: true, LastZReportWidget: true,
            FeaturedItemsComponent: true, MostPopularItemsComponent: true, SalesSummaryComponent: true,
            OrderSummaryComponent: true, RealtimeReportComponent: true, SlaAlertsComponent: true,
            ChannelStatsComponent: true, AuditTrailComponent: true, StockLowAlertsWidget: true, DlcWatchWidget: true,
            ErrorBoundary: { template: '<div><slot /></div>' },
            'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' },
        },
    },
});

const CHEF = [
    { url: 'dashboard', access: true },
    { url: 'kitchen-display-system', access: true },
    { url: 'order-status-screen', access: true },
];

describe('tableau de bord — données commerciales réservées (M9-104)', () => {
    it('le chef ne voit ni CA, ni canal, ni audit, ni ventes ; il garde les cartes d’exploitation', () => {
        const w = monterTableau(CHEF);
        for (const nom of COMMERCIALES) {
            expect(w.findComponent({ name: nom }).exists(), nom).toBe(false);
        }
        for (const nom of EXPLOITATION) {
            expect(w.findComponent({ name: nom }).exists(), nom).toBe(true);
        }
        expect(w.findComponent({ name: 'OverviewComponent' }).props('showSales')).toBe(false);
    });

    it.each([
        ['caisse (pos)', [{ url: 'dashboard', access: true }, { url: 'pos', access: true }]],
        ['rapports de vente', [{ url: 'dashboard', access: true }, { url: 'sales-report', access: true }]],
    ])('avec %s, tout reste affiché', (_l, perms) => {
        const w = monterTableau(perms);
        for (const nom of COMMERCIALES) {
            expect(w.findComponent({ name: nom }).exists(), nom).toBe(true);
        }
        expect(w.findComponent({ name: 'OverviewComponent' }).props('showSales')).toBe(true);
    });

    it('la tuile « Ventes du jour » n’est ni affichée ni demandée sans la permission', () => {
        const dispatch = vi.fn(() => new Promise(() => {}));
        const w = mount(OverviewComponent, {
            props: { showSales: false },
            global: { mocks: { $t: (k) => k, $store: { dispatch } }, stubs: { LoadingComponent: true } },
        });
        expect(dispatch.mock.calls.map((c) => c[0])).not.toContain('dashboard/totalSales');
        expect(dispatch.mock.calls.map((c) => c[0])).toContain('dashboard/totalOrders');
        expect(w.text()).not.toContain('label.total_sales_today');
        expect(w.text()).toContain('label.total_orders_today');
    });

    it('par défaut (gérant, admin), la tuile « Ventes du jour » reste', () => {
        const dispatch = vi.fn(() => new Promise(() => {}));
        const w = mount(OverviewComponent, {
            global: { mocks: { $t: (k) => k, $store: { dispatch } }, stubs: { LoadingComponent: true } },
        });
        expect(dispatch.mock.calls.map((c) => c[0])).toContain('dashboard/totalSales');
        expect(w.text()).toContain('label.total_sales_today');
    });
});
