/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Plan de nettoyage monté pour de vrai (serveur simulé) : les fiches du jour, « Tout cocher »,
 * un point non fait exige un motif, l'opérateur choisi et la référence d'envoi partent avec la validation, l'annulation exige un motif,
 * et le plan de départ s'installe seul au premier lancement.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import alertService from '../../resources/js/services/alertService';
import HygieneTasksComponent from '../../resources/js/components/admin/hygiene/HygieneTasksComponent.vue';

const T = (over) => ({
    id: 1, catalog_key: 'salle', name: 'Salle', zone: 'Salle', how_to: 'Désinfecter.', frequency: 'daily', weekday: null, month_day: null, due_time: null,
    sort_order: 10, is_active: true, state: 'todo', overdue: false, due_at: null, completion: null,
    checklist: [{ key: 'k1', label: 'Mobilier' }, { key: 'k2', label: 'Sols' }, { key: 'k3', label: 'Vitrines' }], ...over,
});
const meta = (items, rec = {}) => ({
    summary: { total: items.length, done: items.filter((i) => i.state === 'done').length, partial: items.filter((i) => i.state === 'partial').length,
        todo: items.filter((i) => i.state === 'todo').length, overdue: items.filter((i) => i.overdue).length },
    recommended: { total: 8, missing: 0, never_configured: false, ...rec }, server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris',
});

let today;
let recommended;
let posts;

function setup(items, rec = {}) {
    today = { items, rec };
    posts = [];
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/tasks/today') return { data: { data: today.items, meta: meta(today.items, today.rec) } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }, { id: 6, name: 'Sarah Lopez', short: 'Sarah L.', is_me: false }], meta: { me: 5 } } };
        if (url === 'admin/hygiene/tasks/history') return { data: { data: [], meta: {} } };
        if (url === 'admin/hygiene/tasks/templates') return { data: { data: today.items, meta: { recommended: meta(today.items, today.rec).recommended } } };
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        posts.push([url, body]);
        if (url === 'admin/hygiene/tasks/templates/install-recommended') {
            recommended = true;
            today = { items: [T({ id: 9, name: 'Cuisine' })], rec: {} };
            return { data: { data: { added: 8 } } };
        }
        return { data: { data: { id: 1 } } };
    });
    window.localStorage.clear();
}

async function mountScreen() {
    const w = mount(HygieneTasksComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}

beforeEach(() => { recommended = false; });

describe('plan de nettoyage — fiches du jour', () => {
    it('affiche le compteur, sépare « à faire » et « faites », et marque le retard', async () => {
        setup([T({ id: 1 }), T({ id: 2, name: 'Sanitaires', overdue: true, due_time: '22:00' }),
            T({ id: 3, name: 'Hotte', state: 'done', completion: { id: 30, complete: true, items_done: 3, items_total: 3, completed_at: '2026-10-20T09:00:00+02:00', operator_name: 'Karim Benali', late: false, items: [], note: null } })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="tsk-progress"]').text()).toBe('1/3 faites');
        expect(w.get('[data-testid="tsk-sum-overdue"]').text()).toContain('1 en retard');
        expect(w.get('[data-testid="tsk-due-2"]').text()).toContain('En retard');
        expect(w.find('[data-testid="tsk-card-3"]').exists()).toBe(false); // faite → onglet « Faites »
        await w.get('[data-testid="tsk-tab-done"]').trigger('click');
        expect(w.get('[data-testid="tsk-done-3"]').text()).toContain('Faite');
        expect(w.get('[data-testid="tsk-done-3"]').text()).toContain('Karim Benali');
    });

    it('un point non coché exige un motif avant de pouvoir valider', async () => {
        setup([T({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-item-1-k1"]').trigger('click');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click'); // l'engagement est coché par la personne
        expect(w.get('[data-testid="tsk-submit-1"]').attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="tsk-submit-1"]').text()).toContain('avec réserve');
        await w.get('[data-testid="tsk-note-1"]').setValue('ab');
        expect(w.get('[data-testid="tsk-submit-1"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="tsk-note-1"]').setValue('Vitrine en panne');
        expect(w.get('[data-testid="tsk-submit-1"]').attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="tsk-submit-1"]').trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/tasks/completions');
        expect(body.done).toEqual(['k1']);
        expect(body.note).toBe('Vitrine en panne');
        expect(body.all).toBeUndefined();
        expect(body.client_ref).toMatch(/^k/);
    });

    it('tout coché : all:true, pas de champ de motif, opérateur choisi transmis', async () => {
        setup([T({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="hy-operator-btn"]').trigger('click');
        await w.get('[data-testid="hy-operator-6"]').trigger('click');
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-all-1"]').trigger('click');
        expect(w.find('[data-testid="tsk-note-1"]').exists()).toBe(false);
        expect(w.get('[data-testid="tsk-submit-1"]').text()).toBe('Valider');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        await w.get('[data-testid="tsk-submit-1"]').trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/tasks/completions');
        expect(body).toMatchObject({ template_id: 1, all: true, operator_user_id: 6 });
        expect(body.note).toBeUndefined();
    });

    it('récompense honnête : « plus que N fiches » tant qu’il en reste, « bravo » à la dernière, jamais sur une fiche avec réserve', async () => {
        setup([T({ id: 1 }), T({ id: 2, name: 'Cuisine' })]);
        const base = h.axios.post.getMockImplementation();
        h.axios.post.mockImplementation(async (url, body) => {
            const r = await base(url, body);
            if (url === 'admin/hygiene/tasks/completions') today = { ...today, items: today.items.map((i) => (i.id === body.template_id ? { ...i, state: body.all ? 'done' : 'partial' } : i)) };
            return r;
        });
        const w = await mountScreen();
        alertService.success.mockClear();
        const validate = async (id) => {
            await w.get(`[data-testid="tsk-open-${id}"]`).trigger('click');
            await w.get(`[data-testid="tsk-all-${id}"]`).trigger('click');
            await w.get(`[data-testid="tsk-attest-${id}"]`).trigger('click');
            await w.get(`[data-testid="tsk-submit-${id}"]`).trigger('click');
            await flushPromises();
        };
        await validate(1);
        expect(alertService.success).toHaveBeenLastCalledWith('« Salle » validée ✓ Plus que 1 fiche à faire.', 'top-right', { timeout: 6000 });
        await validate(2);
        expect(alertService.success).toHaveBeenLastCalledWith('« Cuisine » validée ✓ Toutes les fiches du jour sont faites, bravo !', 'top-right', { timeout: 6000 });
    });

    it('une fiche validée AVEC réserve n’est jamais fêtée : pas de « bravo » même si c’est la dernière', async () => {
        setup([T({ id: 1 })]);
        const base = h.axios.post.getMockImplementation();
        h.axios.post.mockImplementation(async (url, body) => {
            const r = await base(url, body);
            if (url === 'admin/hygiene/tasks/completions') today = { ...today, items: today.items.map((i) => ({ ...i, state: 'partial' })) };
            return r;
        });
        const w = await mountScreen();
        alertService.success.mockClear();
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-note-1"]').setValue('Frigo en panne');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        await w.get('[data-testid="tsk-submit-1"]').trigger('click');
        await flushPromises();
        const msg = alertService.success.mock.calls.at(-1)[0];
        expect(msg).toContain('validée avec réserve');
        expect(msg).not.toMatch(/bravo/);
    });

    it('« moi » n’envoie pas d’opérateur (le serveur prend l’utilisateur connecté)', async () => {
        setup([T({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-all-1"]').trigger('click');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        await w.get('[data-testid="tsk-submit-1"]').trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/tasks/completions');
        expect(body.operator_user_id).toBeUndefined();
    });

    it('l’engagement est obligatoire : sans la case cochée, « Valider » reste grisé même tout coché ; coché, il part avec la validation', async () => {
        setup([T()]);
        const w = await mountScreen();
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-all-1"]').trigger('click');
        expect(w.get('[data-testid="tsk-submit-1"]').attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="tsk-attest-1"]').text()).toContain("Je confirme l'exactitude des informations");
        expect(w.get('[data-testid="tsk-attest-1"]').attributes('aria-pressed')).toBe('false');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        expect(w.get('[data-testid="tsk-attest-1"]').attributes('aria-pressed')).toBe('true');
        expect(w.get('[data-testid="tsk-submit-1"]').attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="tsk-submit-1"]').trigger('click');
        await flushPromises();
        expect(posts.find(([u]) => u === 'admin/hygiene/tasks/completions')[1].attested).toBe(true);
    });

    it('décocher l’engagement referme le bouton Valider', async () => {
        setup([T()]);
        const w = await mountScreen();
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-all-1"]').trigger('click');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        expect(w.get('[data-testid="tsk-submit-1"]').attributes('disabled')).toBeDefined();
    });

    it('le refus du serveur reste dans la carte et conserve la saisie', async () => {
        setup([T({ id: 1 })]);
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { data: { message: 'Cette fiche n’est pas à faire aujourd’hui.' } }; throw e; });
        const w = await mountScreen();
        await w.get('[data-testid="tsk-open-1"]').trigger('click');
        await w.get('[data-testid="tsk-all-1"]').trigger('click');
        await w.get('[data-testid="tsk-attest-1"]').trigger('click');
        await w.get('[data-testid="tsk-submit-1"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="tsk-form-error-1"]').text()).toContain('pas à faire aujourd’hui');
        expect(w.get('[data-testid="tsk-item-1-k1"]').attributes('aria-pressed')).toBe('true');
    });

    it('annuler une validation exige un motif d’au moins 3 caractères', async () => {
        setup([T({ id: 3, state: 'done', completion: { id: 30, complete: true, items_done: 3, items_total: 3, completed_at: '2026-10-20T09:00:00+02:00', operator_name: 'K', late: false, items: [], note: null } })]);
        const w = await mountScreen();
        await w.get('[data-testid="tsk-tab-done"]').trigger('click');
        await w.get('[data-testid="tsk-void-3"]').trigger('click');
        expect(w.get('[data-testid="tsk-void-confirm-3"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="tsk-void-reason-3"]').setValue('Cochée par erreur');
        await w.get('[data-testid="tsk-void-confirm-3"]').trigger('click');
        await flushPromises();
        expect(posts.find(([u]) => u === 'admin/hygiene/tasks/completions/30/void')[1]).toEqual({ reason: 'Cochée par erreur' });
    });

    it('premier lancement : le plan de départ s’installe seul, une seule fois', async () => {
        setup([], { never_configured: true, missing: 8 });
        const w = await mountScreen();
        const installs = posts.filter(([u]) => u === 'admin/hygiene/tasks/templates/install-recommended');
        expect(installs).toHaveLength(1);
        expect(installs[0][1]).toEqual({ only_if_empty: true });
        expect(recommended).toBe(true);
        expect(w.find('[data-testid="tsk-card-9"]').exists()).toBe(true);
    });

    it('rien à faire : message clair, pas de carte vide', async () => {
        setup([T({ id: 3, state: 'done', completion: { id: 30, complete: true, items_done: 3, items_total: 3, completed_at: '2026-10-20T09:00:00+02:00', operator_name: 'K', late: false, items: [], note: null } })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="tsk-all-done"]').text()).toContain('Tout est fait');
    });
});

describe('plan de nettoyage — éditeur de fiches', () => {
    it('crée une fiche avec ses points et son horaire', async () => {
        setup([T({ id: 1 })]);
        const w = await mountScreen();
        await w.get('[data-testid="tsk-tab-plan"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tsk-plan-new"]').trigger('click');
        await w.get('[data-testid="tsk-e-name"]').setValue('Machine à glaçons');
        await w.get('[data-testid="tsk-e-freq"]').setValue('weekly');
        await w.get('[data-testid="tsk-e-weekday"]').setValue('3');
        await w.get('[data-testid="tsk-e-time"]').setValue('22:30');
        await w.get('[data-testid="tsk-e-item-0"]').setValue('Bac vidé');
        await w.get('[data-testid="tsk-e-add-item"]').trigger('click');
        await w.get('[data-testid="tsk-e-item-1"]').setValue('Joints lavés');
        await w.get('[data-testid="tsk-e-save"]').trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/tasks/templates');
        expect(body).toMatchObject({ name: 'Machine à glaçons', frequency: 'weekly', weekday: 3, month_day: null, due_time: '22:30' });
        expect(body.checklist.map((c) => c.label)).toEqual(['Bac vidé', 'Joints lavés']);
    });
});

describe('relecture adverse (2026-10-18) — « faite » veut dire la même chose que sur le hub', () => {
    const partial = () => T({ id: 1, name: 'Cuisine', state: 'partial', completion: { id: 3, complete: false, items_done: 1, items_total: 3, completed_at: '2026-10-20T09:00:00+02:00', operator_name: 'Karim B.', late: false, note: 'sol mouillé', items: [{ key: 'k1', label: 'Mobilier', done: true }, { key: 'k2', label: 'Sols', done: false }, { key: 'k3', label: 'Vitrines', done: false }] } });

    it('une fiche validée INCOMPLÈTE n’est pas comptée « faite » : 0/1, jamais « 1/1 faites » ni « Bravo »', async () => {
        setup([partial()]);
        const w = await mountScreen();
        expect(w.get('[data-testid="tsk-progress"]').text()).toBe('0/1 faites');
        expect(w.get('[data-testid="tsk-sum-partial"]').text()).toContain('1 incomplète');
        expect(w.text()).not.toContain('Bravo');
        expect(w.find('[data-testid="tsk-all-done"]').exists()).toBe(false);
        const left = w.get('[data-testid="tsk-partial-left"]').text();
        expect(left).toContain('1 fiche incomplète à reprendre');
    });

    it('le « Bravo » ne vient que quand plus rien n’est à faire NI à reprendre', async () => {
        setup([T({ id: 1, state: 'done', completion: { id: 3, complete: true, items_done: 3, items_total: 3, completed_at: '2026-10-20T09:00:00+02:00', operator_name: 'Karim B.', late: false, note: null, items: [] } })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="tsk-progress"]').text()).toBe('1/1 faites');
        expect(w.get('[data-testid="tsk-all-done"]').text()).toContain('Bravo');
        expect(w.find('[data-testid="tsk-partial-left"]').exists()).toBe(false);
    });
});
