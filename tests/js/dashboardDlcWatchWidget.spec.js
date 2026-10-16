/**
 * [ETIQUETTES 2026-10-16 · owner « je ne trouve pas les fonctionnalités … dans la barre latérale et en tableau de bord »]
 * Le tableau de bord mène aux étiquettes (accès rapide) et montre les DLC à surveiller — avec la même porte que l'écran.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));

import DlcWatchWidget from '../../resources/js/components/admin/dashboard/DlcWatchWidget.vue';
import DashboardComponent from '../../resources/js/components/admin/dashboard/DashboardComponent.vue';

const store = (perms) => ({ getters: { authPermission: perms } });
const mountWidget = (perms) => mount(DlcWatchWidget, {
    global: { mocks: { $t: (k) => k, $store: store(perms) }, stubs: { 'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
});
const lot = (over) => ({ id: 1, dlc_product_id: 1, status: 'printed', product_name: 'Olives', copies: 1, lot: 'L261016-0001', ...over });

beforeEach(() => { h.axios.get.mockReset(); });

describe('tableau de bord — DLC à surveiller', () => {
    it('compte les lots expirés, du jour et sous 72 h, liste les plus pressés et mène à l’écran', async () => {
        const now = Date.now();
        h.axios.get.mockResolvedValue({ data: { data: [
            lot({ id: 1, expires_at: new Date(now - 3 * 3600000).toISOString() }),
            lot({ id: 2, product_name: 'Tomates', expires_at: new Date(now + 20 * 60000).toISOString() }),
            lot({ id: 3, product_name: 'Sauce', expires_at: new Date(now + 50 * 3600000).toISOString() }),
        ], meta: { server_now: new Date(now).toISOString() } } });
        const w = mountWidget([{ url: 'pos-orders', access: true }]);
        await flushPromises();
        expect(h.axios.get).toHaveBeenCalledWith('admin/labels/watch');
        expect(w.get('[data-testid="dlc-watch-widget-count-expired"]').text()).toContain(': 1');
        expect(w.get('[data-testid="dlc-watch-widget-count-today"]').text()).toContain(': 1');
        expect(w.get('[data-testid="dlc-watch-widget-count-soon"]').text()).toContain(': 1');
        expect(w.get('[data-testid="dlc-watch-widget-item-1"]').text()).toMatch(/Olives · N° L261016-0001.*EXPIRÉE depuis 3 h/);
        expect(w.get('[data-testid="dlc-watch-widget-open"]').attributes('href')).toBe('/admin/labels/dlc');
    });

    it('sans la permission de l’écran : rien n’est affiché ni demandé (jamais un appel promis au 403)', async () => {
        const w = mountWidget([{ url: 'pos-orders', access: false }]);
        await flushPromises();
        expect(w.find('[data-testid="dlc-watch-widget"]').exists()).toBe(false);
        expect(h.axios.get).not.toHaveBeenCalled();
    });

    it('une panne se lit comme une panne, jamais comme « rien à surveiller »', async () => {
        h.axios.get.mockRejectedValue(new Error('500'));
        const w = mountWidget([{ url: 'pos-orders', access: true }]);
        await flushPromises();
        expect(w.find('[data-testid="dlc-watch-widget-error"]').exists()).toBe(true);
        expect(w.find('[data-testid="dlc-watch-widget-none"]').exists()).toBe(false);
    });

    it('accès rapide : « Étiquettes commandes » et « Étiquettes DLC » pour qui a la porte de l’écran, et pour lui seul', () => {
        const links = (perms) => DashboardComponent.computed.quickAccessLinks.call({ normalizedPermissions: () => perms, $t: (k) => k });
        const to = links([{ url: 'pos-orders', access: true }]).map((l) => l.to);
        expect(to).toContain('/admin/labels/orders');
        expect(to).toContain('/admin/labels/dlc');
        expect(links([{ url: 'pos', access: true }]).map((l) => l.to)).not.toContain('/admin/labels/dlc');
    });
});
