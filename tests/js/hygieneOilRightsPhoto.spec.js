/**
 * [AUDIT E2E 2026-11-18] Huile de friture — correctifs de l'audit :
 *  - P0 droits : déclarer/renommer/masquer/ajouter une friteuse n'apparaît qu'au responsable (`settings`) ; un 403 est dit clairement ;
 *  - P1 : la photo de la mesure se voit dans la liste du jour et au journal ;
 *  - P2 : l'action corrective ET sa précision (« Autre (à préciser) ») sont lues dans la liste du jour et au journal ;
 *         sans friteuse active, l'onglet « Aujourd'hui » explique pourquoi il est vide.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import alertService from '../../resources/js/services/alertService';
import HygieneOilComponent from '../../resources/js/components/admin/hygiene/HygieneOilComponent.vue';

const NOW = '2026-11-18T10:00:00+01:00';
const ACTIONS = [{ key: 'oil_changed', label: 'Huile changée', note: false }, { key: 'other', label: 'Autre (à préciser)', note: true }];
const F = (o) => ({ id: 1, name: 'Friteuse 1', location: null, is_active: true, state: 'todo', tested_today: false, last_test: null, last_change: null, days_since_change: null, last_filter: null, today: [], ...o });
const C = (o) => ({ id: 10, equipment_id: 1, equipment_name: 'Friteuse 1', type: 'test', polar_pct: 27, bath_temp_c: null, oil_litres: null, oil_brand: null, status: 'alert',
    corrective_action: 'other', corrective_label: 'Autre (à préciser)', corrective_note: 'Huile filtrée et friteuse vidée en partie', attachment_id: 55,
    performed_at: NOW, operator_name: 'Karim Benali', voided: false, void_reason: null, ...o });
const PERMS = (settings) => [{ name: 'settings', url: 'settings', access: settings }];

let state;
function setup({ items = [F({})], fryers = [F({})], history = [] } = {}) {
    state = { items, fryers, history };
    for (const m of ['get', 'post', 'put', 'delete']) h.axios[m].mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/oil/today') {
            return { data: { data: state.items, meta: { summary: { total: state.items.length, todo: state.items.length, ok: 0, warn: 0, alert: 0 },
                types: [], corrective_actions: ACTIONS, limits: { polar_limit: 25, polar_warn: 20, bath_max_c: 180 }, server_now: NOW, timezone: 'Europe/Paris' } } };
        }
        if (url === 'admin/hygiene/oil/fryers') return { data: { data: state.fryers, meta: { server_now: NOW } } };
        if (url === 'admin/hygiene/oil/history') return { data: { data: state.history, meta: { server_now: NOW } } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5, server_now: NOW } } };
        return { data: { data: [], meta: { server_now: NOW } } };
    });
    window.localStorage.clear();
}
async function mountScreen(settings) {
    const w = mount(HygieneOilComponent, { global: { mocks: { $t: (k) => k, $store: { getters: { authPermission: PERMS(settings) } } } } });
    await flushPromises();
    return w;
}

describe('huile — droits sur les friteuses', () => {
    it('le personnel consulte les friteuses sans pouvoir les renommer, masquer ou ajouter', async () => {
        setup({ fryers: [F({}), F({ id: 2, name: 'Friteuse 2', is_active: false })] });
        const w = await mountScreen(false);
        await w.get('[data-testid="oil-tab-fryers"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="oil-fryers-readonly"]').text()).toContain('déclarées par un responsable');
        expect(w.get('[data-testid="oil-fryer-2"]').text()).toContain('Friteuse 2 (masquée)');
        expect(w.find('[data-testid="oil-fryer-name-1"]').exists()).toBe(false);
        expect(w.find('[data-testid="oil-fryer-save-1"]').exists()).toBe(false);
        expect(w.find('[data-testid="oil-fryer-toggle-1"]').exists()).toBe(false);
        expect(w.find('[data-testid="oil-new-add"]').exists()).toBe(false);
        // les gestes du jour restent proposés
        await w.get('[data-testid="oil-tab-today"]').trigger('click');
        expect(w.find('[data-testid="oil-open-test-1"]').exists()).toBe(true);
    });

    it('le responsable garde la gestion ; un refus 403 est dit clairement', async () => {
        setup();
        h.axios.put.mockRejectedValue({ response: { status: 403, data: { message: 'User does not have the right permissions.' } } });
        const w = await mountScreen(true);
        await w.get('[data-testid="oil-tab-fryers"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="oil-fryers-readonly"]').exists()).toBe(false);
        await w.get('[data-testid="oil-fryer-save-1"]').trigger('click');
        await flushPromises();
        expect(alertService.error).toHaveBeenCalledWith('Réservé à un responsable : demandez-lui de gérer les friteuses.');
    });

    it('sans aucune friteuse, le personnel est orienté vers un responsable (pas de bouton de création)', async () => {
        setup({ items: [], fryers: [] });
        const w = await mountScreen(false);
        expect(w.get('[data-testid="oil-setup-readonly"]').text()).toContain('Un responsable doit d\'abord déclarer');
        expect(w.find('[data-testid="oil-setup-go"]').exists()).toBe(false);
    });
});

describe('huile — liste du jour et journal', () => {
    it('action corrective, précision et photo dans la liste du jour', async () => {
        setup({ items: [F({ state: 'alert', today: [C({}), C({ id: 11, type: 'filter', status: 'ok', corrective_action: null, corrective_label: null, corrective_note: null, attachment_id: null })] })] });
        const w = await mountScreen(false);
        const rows = w.get('[data-testid="oil-card-1"]').findAll('li');
        expect(rows[0].text()).toContain('Autre (à préciser) : Huile filtrée et friteuse vidée en partie');
        expect(rows[0].find('[data-testid="hy-photo-view-55"]').text()).toBe('📷 Voir');
        expect(rows[1].text()).not.toContain('—');
        expect(rows[1].find('.hy-photo-view').exists()).toBe(false);
    });

    it('le journal lit aussi la précision de l’action et propose la photo', async () => {
        setup({ history: [C({}), C({ id: 12, corrective_action: 'oil_changed', corrective_label: 'Huile changée', corrective_note: null, attachment_id: null })] });
        const w = await mountScreen(true);
        await w.get('[data-testid="oil-tab-journal"]').trigger('click');
        await flushPromises();
        const rows = w.get('[data-testid="oil-history"]').findAll('li');
        expect(rows[0].text()).toContain('— Autre (à préciser) : Huile filtrée et friteuse vidée en partie');
        expect(rows[0].find('[data-testid="hy-photo-view-55"]').exists()).toBe(true);
        expect(rows[1].text()).toContain('— Huile changée');
        expect(rows[1].text()).not.toContain('Huile changée :');
    });

    it('friteuses toutes masquées : l’onglet « Aujourd’hui » dit pourquoi il est vide', async () => {
        setup({ items: [], fryers: [F({ is_active: false })] });
        let w = await mountScreen(true);
        expect(w.find('[data-testid="oil-setup"]').exists()).toBe(false);
        expect(w.get('[data-testid="oil-today-none"]').text()).toContain('Réactivez-en une dans l\'onglet « Friteuses »');
        w = await mountScreen(false);
        expect(w.get('[data-testid="oil-today-none"]').text()).toContain('Demandez à un responsable');
    });
});
