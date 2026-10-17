/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Huile de friture montrée pour de vrai : démarrage « j'ai N friteuses », test (aperçu des
 * mêmes limites que le serveur : 25 % = limite légale), action corrective obligatoire en cas d'écart, vidange, filtrage.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import HygieneOilComponent from '../../resources/js/components/admin/hygiene/HygieneOilComponent.vue';

const ACTIONS = [{ key: 'oil_changed', label: 'Huile changée', note: false }, { key: 'fryer_stopped', label: 'Friteuse arrêtée', note: false }, { key: 'other', label: 'Autre (à préciser)', note: true }];
const F = (o) => ({ id: 1, name: 'Friteuse 1', location: null, is_active: true, state: 'todo', tested_today: false, last_test: null, last_change: null, days_since_change: null, last_filter: null, today: [], ...o });
let items;
let posts;
let hasFryers;

function setup(list, hasAny = list.length > 0) {
    items = list;
    hasFryers = hasAny;
    posts = [];
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/oil/today') {
            const total = items.length;
            return { data: { data: items, meta: { summary: { total, todo: items.filter((i) => i.state === 'todo').length, ok: 0, warn: items.filter((i) => i.state === 'warn').length, alert: items.filter((i) => i.state === 'alert').length },
                types: [], corrective_actions: ACTIONS, limits: { polar_limit: 25, polar_warn: 20, bath_max_c: 180 }, server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' } } };
        }
        if (url === 'admin/hygiene/oil/fryers') return { data: { data: hasFryers ? [F({})] : [] } };
        if (url === 'admin/hygiene/operators') return { data: { data: [{ id: 5, name: 'Karim Benali', short: 'Karim B.', is_me: true }], meta: { me: 5 } } };
        if (url === 'admin/hygiene/oil/history') return { data: { data: [], meta: {} } };
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => { posts.push([url, body]); return { data: { data: { id: 1 } } }; });
    window.localStorage.clear();
}
async function mountScreen() {
    const w = mount(HygieneOilComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}
const polar = (w, v) => w.get('[data-testid="oil-polar-1-input"]').setValue(v);

beforeEach(() => setup([F({})]));

describe('huile — démarrage', () => {
    it('sans friteuse : « combien ? » puis création d’un coup', async () => {
        setup([], false);
        const w = await mountScreen();
        expect(w.get('[data-testid="oil-setup-count"]').text()).toBe('2');
        await w.get('[data-testid="oil-setup-plus"]').trigger('click');
        await w.get('[data-testid="oil-setup-go"]').trigger('click');
        await flushPromises();
        expect(posts[0]).toEqual(['admin/hygiene/oil/fryers/quick-setup', { count: 3 }]);
    });

    it('friteuses toutes masquées : pas de faux démarrage', async () => {
        setup([], true);
        const w = await mountScreen();
        expect(w.find('[data-testid="oil-setup"]').exists()).toBe(false);
    });
});

describe('huile — test', () => {
    it('l’aperçu suit les limites : 25 % toléré, 25,1 % écart, 20 % à surveiller', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-test-1"]').trigger('click');
        await polar(w, '14');
        expect(w.get('[data-testid="oil-verdict-1"]').text()).toContain('Conforme');
        await polar(w, '20');
        expect(w.get('[data-testid="oil-verdict-1"]').text()).toContain('À surveiller');
        await polar(w, '25');
        expect(w.get('[data-testid="oil-verdict-1"]').text()).toContain('À surveiller');
        expect(w.find('[data-testid="oil-corrective-1"]').exists()).toBe(false);
        await polar(w, '25,1');
        expect(w.get('[data-testid="oil-verdict-1"]').text()).toContain('doit être changée');
        expect(w.find('[data-testid="oil-corrective-1"]').exists()).toBe(true);
    });

    it('un bain trop chaud est aussi un écart', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-test-1"]').trigger('click');
        await polar(w, '10');
        await w.get('[data-testid="oil-bath-1-input"]').setValue('181');
        expect(w.get('[data-testid="oil-verdict-1"]').text()).toContain('180');
        expect(w.find('[data-testid="oil-corrective-1"]').exists()).toBe(true);
    });

    it('sans mesure on ne peut pas enregistrer ; en écart il faut l’action (et sa précision si « autre »)', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-test-1"]').trigger('click');
        const submit = () => w.get('[data-testid="oil-submit-1"]');
        expect(submit().attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="oil-block-1"]').text()).toContain('composés polaires');
        await polar(w, '27');
        expect(submit().attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="oil-block-1"]').text()).toContain('ce qui a été fait');
        await w.get('[data-testid="oil-action-1-other"]').trigger('click');
        expect(submit().attributes('disabled')).toBeDefined();
        await w.get('[data-testid="oil-action-note-1"]').setValue('Appel du technicien');
        expect(submit().attributes('disabled')).toBeUndefined();
        await submit().trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/oil/controls');
        expect(body).toMatchObject({ equipment_id: 1, type: 'test', polar_pct: 27, corrective_action: 'other', corrective_note: 'Appel du technicien' });
        expect(body.client_ref).toMatch(/^o/);
    });

    it('test conforme : aucune action n’est envoyée, même si une était choisie avant', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-test-1"]').trigger('click');
        await polar(w, '27');
        await w.get('[data-testid="oil-action-1-oil_changed"]').trigger('click');
        await polar(w, '12');
        await w.get('[data-testid="oil-bath-1-input"]').setValue('172,5');
        await w.get('[data-testid="oil-submit-1"]').trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/oil/controls');
        expect(body).toMatchObject({ polar_pct: 12, bath_temp_c: 172.5 });
        expect(body.corrective_action).toBeUndefined();
    });

    it('valeur illisible : bloquée avec un message', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-test-1"]').trigger('click');
        await polar(w, '1a');
        expect(w.get('[data-testid="oil-submit-1"]').attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="oil-block-1"]').text()).toContain('illisible');
    });

    it('refus du serveur : le message reste dans la carte, la saisie est conservée', async () => {
        const w = await mountScreen();
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { data: { message: 'Friteuse introuvable ou désactivée.' } }; throw e; });
        await w.get('[data-testid="oil-open-test-1"]').trigger('click');
        await polar(w, '12');
        await w.get('[data-testid="oil-submit-1"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="oil-form-error-1"]').text()).toContain('introuvable');
        expect(w.get('[data-testid="oil-polar-1-input"]').element.value).toBe('12');
    });
});

describe('huile — vidange et filtrage', () => {
    it('vidange : volume et huile facultatifs, aucune mesure exigée', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-change-1"]').trigger('click');
        expect(w.get('[data-testid="oil-submit-1"]').attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="oil-litres-1-input"]').setValue('18');
        await w.get('[data-testid="oil-brand-1"]').setValue('Colza 15 L');
        await w.get('[data-testid="oil-submit-1"]').trigger('click');
        await flushPromises();
        const [, body] = posts.find(([u]) => u === 'admin/hygiene/oil/controls');
        expect(body).toMatchObject({ type: 'change', oil_litres: 18, oil_brand: 'Colza 15 L' });
        expect(body.polar_pct).toBeUndefined();
    });

    it('filtrage en un appui de confirmation', async () => {
        const w = await mountScreen();
        await w.get('[data-testid="oil-open-filter-1"]').trigger('click');
        await w.get('[data-testid="oil-submit-1"]').trigger('click');
        await flushPromises();
        expect(posts.find(([u]) => u === 'admin/hygiene/oil/controls')[1]).toMatchObject({ type: 'filter', equipment_id: 1 });
    });
});

describe('huile — état de la friteuse', () => {
    it('dit depuis quand l’huile a été changée et exige un motif pour annuler une ligne', async () => {
        setup([F({ state: 'ok', days_since_change: 6, tested_today: true, today: [{ id: 9, type: 'test', status: 'ok', polar_pct: 12, bath_temp_c: null, performed_at: '2026-10-20T08:00:00+02:00', operator_name: 'Karim', voided: false }] })]);
        const w = await mountScreen();
        expect(w.get('[data-testid="oil-change-1"]').text()).toBe('Huile changée il y a 6 jours');
        expect(w.get('[data-testid="oil-state-1"]').text()).toContain('Conforme');
        await w.get('[data-testid="oil-void-9"]').trigger('click');
        expect(w.get('[data-testid="oil-void-confirm-9"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="oil-void-reason-9"]').setValue('Mauvaise friteuse');
        await w.get('[data-testid="oil-void-confirm-9"]').trigger('click');
        await flushPromises();
        expect(posts.find(([u]) => u === 'admin/hygiene/oil/controls/9/void')[1]).toEqual({ reason: 'Mauvaise friteuse' });
    });

    it('jamais vidangée : le dit', async () => {
        const w = await mountScreen();
        expect(w.get('[data-testid="oil-change-1"]').text()).toContain('Aucune vidange');
    });
});
