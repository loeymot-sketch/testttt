/**
 * [AUDIT E2E 2026-11-18] Plan de nettoyage — correctifs de l'audit :
 *  - P0 droits : « Modifier », « Nouvelle fiche », « Ajouter les fiches recommandées » n'apparaissent qu'au responsable (`settings`) ; un 403 est dit clairement ;
 *  - P0 fréquence : l'éditeur prévient qu'une nouvelle fiche remplacera l'ancienne, et la liste est rechargée (l'identifiant change) ;
 *  - P1 : la photo de preuve se voit sur la carte « Faites » et au journal ; « Supprimer la fiche » se confirme en deux temps ;
 *        l'éditeur est amené à l'écran et le curseur posé sur le nom.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import alertService from '../../resources/js/services/alertService';
import HygieneTasksComponent from '../../resources/js/components/admin/hygiene/HygieneTasksComponent.vue';

const NOW = '2026-11-18T10:00:00+01:00';
const T = (over) => ({
    id: 1, catalog_key: 'hotte', name: 'Hotte et filtres', zone: 'Cuisine', how_to: null, frequency: 'daily', weekday: null, month_day: null, due_time: null,
    sort_order: 10, is_active: true, state: 'todo', overdue: false, due_at: null, completion: null,
    checklist: [{ key: 'k1', label: 'Filtres' }], ...over,
});
const meta = (items) => ({
    summary: { total: items.length, done: items.filter((i) => i.state === 'done').length, partial: 0, todo: items.filter((i) => i.state === 'todo').length, overdue: 0 },
    recommended: { total: 8, missing: 3, never_configured: false }, server_now: NOW, timezone: 'Europe/Paris',
});
const PERMS = (settings) => [{ name: 'settings', url: 'settings', access: settings }, { name: 'pos-orders', url: 'pos-orders', access: true }];

let state;

function setup({ items = [T()], plan = [T()], history = [] } = {}) {
    state = { items, plan, history, templateGets: 0 };
    for (const m of ['get', 'post', 'put', 'delete']) h.axios[m].mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/tasks/today') return { data: { data: state.items, meta: meta(state.items) } };
        if (url === 'admin/hygiene/tasks/templates') { state.templateGets++; return { data: { data: state.plan, meta: { server_now: NOW, recommended: meta(state.items).recommended } } }; }
        if (url === 'admin/hygiene/tasks/history') return { data: { data: state.history, meta: { server_now: NOW } } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5, server_now: NOW } } };
        return { data: { data: [], meta: { server_now: NOW } } };
    });
    window.localStorage.clear();
}

async function mountScreen(settings) {
    const w = mount(HygieneTasksComponent, {
        attachTo: document.body,
        global: { mocks: { $t: (k) => k, $store: { getters: { authPermission: PERMS(settings) } } } },
    });
    await flushPromises();
    return w;
}

let scrolled;
beforeEach(() => {
    vi.mocked(alertService.success).mockClear();
    scrolled = vi.fn();
    Element.prototype.scrollIntoView = scrolled;
});
afterEach(() => { document.body.innerHTML = ''; });

describe('plan de nettoyage — droits', () => {
    it('le personnel sans le droit « settings » consulte le plan sans aucun bouton de gestion', async () => {
        setup();
        const w = await mountScreen(false);
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="tsk-plan-1"]').exists()).toBe(true);
        expect(w.find('[data-testid="tsk-plan-edit-1"]').exists()).toBe(false);
        expect(w.find('[data-testid="tsk-plan-new"]').exists()).toBe(false);
        expect(w.find('[data-testid="tsk-reco"]').exists()).toBe(false);
        expect(w.get('[data-testid="tsk-plan-readonly"]').text()).toContain('préparé par un responsable');
        w.unmount();
    });

    it('le responsable voit « Modifier », « Nouvelle fiche » et le plan de départ', async () => {
        setup();
        const w = await mountScreen(true);
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="tsk-plan-edit-1"]').exists()).toBe(true);
        expect(w.find('[data-testid="tsk-plan-new"]').exists()).toBe(true);
        expect(w.find('[data-testid="tsk-reco"]').exists()).toBe(true);
        expect(w.find('[data-testid="tsk-plan-readonly"]').exists()).toBe(false);
        w.unmount();
    });

    it('un refus 403 du serveur est dit clairement (droits pas encore chargés)', async () => {
        setup();
        h.axios.put.mockRejectedValue({ response: { status: 403, data: { message: 'User does not have the right permissions.' } } });
        const w = await mountScreen(true);
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tsk-plan-edit-1"]').trigger('click');
        await w.get('[data-testid="tsk-e-save"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="tsk-e-error"]').text()).toBe('Réservé à un responsable : demandez-lui de modifier le plan de nettoyage.');
        w.unmount();
    });
});

describe('plan de nettoyage — éditeur', () => {
    it('à l’ouverture, l’éditeur est amené à l’écran et le curseur est sur le nom', async () => {
        setup();
        const w = await mountScreen(true);
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tsk-plan-edit-1"]').trigger('click');
        await flushPromises();
        expect(scrolled).toHaveBeenCalledTimes(1);
        expect(scrolled.mock.instances[0].getAttribute('data-testid')).toBe('tsk-editor');
        expect(document.activeElement && document.activeElement.getAttribute('data-testid')).toBe('tsk-e-name');
        w.unmount();
    });

    it('« Supprimer la fiche » demande une confirmation avant d’agir, « Garder » annule', async () => {
        setup();
        h.axios.delete.mockResolvedValue({ data: { status: 'ok' } });
        const w = await mountScreen(true);
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tsk-plan-edit-1"]').trigger('click');

        await w.get('[data-testid="tsk-e-delete"]').trigger('click');
        expect(h.axios.delete).not.toHaveBeenCalled();
        expect(w.get('[data-testid="tsk-e-delete-confirm"]').text()).toContain('Supprimer « Hotte et filtres » ?');
        await w.get('[data-testid="tsk-e-delete-no"]').trigger('click');
        expect(w.find('[data-testid="tsk-e-delete-confirm"]').exists()).toBe(false);
        expect(h.axios.delete).not.toHaveBeenCalled();

        await w.get('[data-testid="tsk-e-delete"]').trigger('click');
        await w.get('[data-testid="tsk-e-delete-yes"]').trigger('click');
        await flushPromises();
        expect(h.axios.delete).toHaveBeenCalledTimes(1);
        expect(h.axios.delete.mock.calls[0][0]).toBe('admin/hygiene/tasks/templates/1');
        w.unmount();
    });

    it('changer la fréquence prévient du remplacement, puis recharge la liste au nouvel identifiant', async () => {
        setup();
        h.axios.put.mockImplementation(async () => {
            state.plan = [T({ id: 42, frequency: 'monthly', month_day: 1 })];
            return { data: { data: state.plan[0], meta: { replaced: true, previous_id: 1 } } };
        });
        const w = await mountScreen(true);
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tsk-plan-edit-1"]').trigger('click');
        expect(w.find('[data-testid="tsk-e-freq-note"]').exists()).toBe(false);
        await w.get('[data-testid="tsk-e-name"]').setValue('Hotte et filtres');
        expect(w.find('[data-testid="tsk-e-freq-note"]').exists()).toBe(false); // un autre champ ne remplace rien
        await w.get('[data-testid="tsk-e-freq"]').setValue('monthly');
        expect(w.get('[data-testid="tsk-e-freq-note"]').text()).toContain('remplace la fiche par une nouvelle');

        const before = state.templateGets;
        await w.get('[data-testid="tsk-e-save"]').trigger('click');
        await flushPromises();
        expect(h.axios.put.mock.calls[0][1]).toMatchObject({ frequency: 'monthly', month_day: 1, weekday: null });
        expect(state.templateGets).toBe(before + 1);
        expect(w.find('[data-testid="tsk-plan-42"]').exists()).toBe(true);
        expect(w.find('[data-testid="tsk-plan-1"]').exists()).toBe(false);
        expect(alertService.success).toHaveBeenCalledWith(expect.stringMatching(/nouvelle fiche remplace l'ancienne/i));
        w.unmount();
    });
});

describe('plan de nettoyage — photo de preuve', () => {
    it('la carte « Faites » et la ligne du journal proposent « 📷 Voir » quand une photo est jointe', async () => {
        const done = { id: 30, complete: true, items_done: 1, items_total: 1, completed_at: NOW, operator_name: 'Karim Benali', late: false, items: [], note: null, attachment_id: 77 };
        setup({
            items: [T({ id: 1, state: 'done', completion: done }), T({ id: 2, name: 'Salle', state: 'done', completion: { ...done, id: 31, attachment_id: null } })],
            history: [{ id: 30, template_id: 1, task_name: 'Hotte et filtres', completed_at: NOW, items_done: 1, items_total: 1, complete: true, attachment_id: 77, voided: false },
                { id: 31, template_id: 2, task_name: 'Salle', completed_at: NOW, items_done: 1, items_total: 1, complete: true, attachment_id: null, voided: false }],
        });
        const w = await mountScreen(false);
        await w.get('[data-testid="tsk-tab-done"]').trigger('click');
        const card = w.get('[data-testid="tsk-card-1"]');
        expect(card.get('[data-testid="hy-photo-view-77"]').text()).toBe('📷 Voir');
        expect(w.get('[data-testid="tsk-card-2"]').find('.hy-photo-view').exists()).toBe(false);

        await w.get('[data-testid="tsk-tab-journal"]').trigger('click');
        await flushPromises();
        const rows = w.get('[data-testid="tsk-history"]').findAll('li');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('[data-testid="hy-photo-view-77"]').exists()).toBe(true);
        expect(rows[1].find('.hy-photo-view').exists()).toBe(false);
        w.unmount();
    });
});
