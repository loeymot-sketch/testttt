/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner « 4 frigos positifs, 2 congélateurs négatifs : je dis 3°, −18°, je valide »] Les relevés de
 * température : fonctions pures (saisie, aperçu du verdict) et l'écran monté pour de vrai (serveur simulé).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

import { parseTemperature, previewVerdict, formatTemperature, limitText, chartModel } from '../../resources/js/helpers/hygieneTemperature.js';
import HygieneTemperatureComponent from '../../resources/js/components/admin/hygiene/HygieneTemperatureComponent.vue';

describe('hygieneTemperature — fonctions pures', () => {
    it('lit la saisie : virgule ou point, une décimale, jamais de signe tapé', () => {
        expect(parseTemperature('3')).toBe(3);
        expect(parseTemperature('3,5')).toBe(3.5);
        expect(parseTemperature('3.5')).toBe(3.5);
        expect(parseTemperature('18')).toBe(18);
        expect(parseTemperature('-18')).toBe(18); // le signe vient du bouton ±
        expect(parseTemperature('−18,0')).toBe(18);
        expect(parseTemperature('')).toBeNull();
        expect(parseTemperature('abc')).toBeNull();
        expect(parseTemperature('3,55')).toBeNull();
        expect(parseTemperature('123')).toBeNull();
    });

    it('aperçu du verdict : mêmes bornes que le serveur', () => {
        expect(previewVerdict(4, 0, 4).status).toBe('ok');
        expect(previewVerdict(0, 0, 4).status).toBe('ok');
        expect(previewVerdict(4.1, 0, 4)).toEqual({ status: 'alert', reason: 'too_warm' });
        expect(previewVerdict(-0.5, 0, 4)).toEqual({ status: 'alert', reason: 'too_cold' });
        expect(previewVerdict(-18, null, -18).status).toBe('ok');
        expect(previewVerdict(-30, null, -18).status).toBe('ok');
        expect(previewVerdict(-17.9, null, -18)).toEqual({ status: 'alert', reason: 'too_warm' });
        expect(previewVerdict(4.04, 0, 4).status).toBe('ok');
    });

    it('affichage : virgule française, vrai signe moins, seuils lisibles', () => {
        expect(formatTemperature(3)).toBe('3,0');
        expect(formatTemperature(3.5)).toBe('3,5');
        expect(formatTemperature(-18)).toBe('−18,0');
        expect(limitText(0, 4)).toBe('0 à +4 °C');
        expect(limitText(null, -18)).toBe('≤ −18 °C');
        expect(limitText(null, null)).toBe('sans seuil');
    });
});

const EQ = (id, name, kind, min, max, extra = {}) => ({
    id, name, kind, kind_label: kind === 'freezer' ? 'Congélateur' : 'Réfrigérateur', negative: kind === 'freezer', location: null, min_c: min, max_c: max,
    sort_order: id * 10, is_active: true, state: 'todo', last_today: null, previous: null, done_periods: [], done_this_period: false, ...extra,
});
const SIX = () => [
    EQ(1, 'Frigo 1', 'fridge', 0, 4), EQ(2, 'Frigo 2', 'fridge', 0, 4), EQ(3, 'Frigo 3', 'fridge', 0, 4), EQ(4, 'Frigo 4', 'fridge', 0, 4),
    EQ(5, 'Congélateur 1', 'freezer', null, -18), EQ(6, 'Congélateur 2', 'freezer', null, -18),
];
const ACTIONS = [
    { key: 'products_checked', label: 'Produits contrôlés (température à cœur)', note: false },
    { key: 'other', label: 'Autre (à préciser)', note: true },
];

let today;
let posts;

function setup(items, opts = {}) {
    today = items;
    posts = [];
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/hygiene/temperature/today') {
            const summary = { total: today.length, todo: today.filter((e) => e.state === 'todo').length, ok: today.filter((e) => e.state === 'ok').length, alert: today.filter((e) => e.state === 'alert').length };
            return { data: { data: today, meta: { period: 'morning', periods: { morning: 'Matin', evening: 'Soir', other: 'Autre' }, summary, kinds: [{ key: 'fridge', label: 'Réfrigérateur' }, { key: 'freezer', label: 'Congélateur' }], corrective_actions: ACTIONS, server_now: '2026-10-17T08:00:00+02:00', timezone: 'Europe/Paris' } } };
        }
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        posts.push([url, body]);
        if (url === 'admin/hygiene/temperature/readings') {
            if (opts.reject) { const e = new Error('422'); e.response = { data: { message: opts.reject, errors: { 'readings.0.corrective_action': [opts.reject] } } }; throw e; }
            today = today.map((e) => {
                const r = body.readings.find((x) => x.equipment_id === e.id);
                if (!r) return e;
                const bad = (e.max_c !== null && r.value > e.max_c) || (e.min_c !== null && r.value < e.min_c);
                return { ...e, state: bad ? 'alert' : 'ok', done_this_period: true, last_today: { id: 100 + e.id, equipment_id: e.id, value_c: r.value, status: bad ? 'alert' : 'ok', alert_reason: bad ? 'too_warm' : null, period: 'morning', recorded_at: '2026-10-17T08:01:00+02:00', operator_name: 'Karim', corrective_label: r.corrective_action ? 'Produits contrôlés (température à cœur)' : null } };
            });
            return { data: { data: body.readings.map((r) => ({ id: 1, equipment_id: r.equipment_id })), meta: { alerts: today.filter((e) => e.state === 'alert').length } } };
        }
        if (url === 'admin/hygiene/temperature/equipment/quick-setup') { today = SIX(); return { data: { data: { added: 6 } } }; }
        return { data: { data: {} } };
    });
}

async function mountScreen() {
    const w = mount(HygieneTemperatureComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}
const type = async (w, id, raw, opts = {}) => {
    await w.get(`[data-testid="tmp-take-${id}"]`).trigger('click');
    await w.get(`[data-testid="tmp-input-${id}"]`).setValue(raw);
    if (opts.flip) await w.get(`[data-testid="tmp-sign-${id}"]`).trigger('click');
};

beforeEach(() => { vi.useRealTimers(); });

describe('écran températures — la saisie du propriétaire', () => {
    it('aucun équipement : « 4 frigos, 2 congélateurs » se crée en un geste', async () => {
        setup([]);
        const w = await mountScreen();
        expect(w.find('[data-testid="tmp-setup"]').exists()).toBe(true);
        expect(w.get('[data-testid="tmp-setup-fridge"]').text()).toBe('4');
        expect(w.get('[data-testid="tmp-setup-freezer"]').text()).toBe('2');
        await w.get('[data-testid="tmp-setup-go"]').trigger('click');
        await flushPromises();
        expect(posts).toEqual([['admin/hygiene/temperature/equipment/quick-setup', { fridge: 4, freezer: 2, cold_room: 0, display: 0, hot_holding: 0 }]]);
        expect(w.find('[data-testid="tmp-setup"]').exists()).toBe(false);
        expect(w.find('[data-testid="tmp-card-6"]').exists()).toBe(true);
    });

    it('4 frigos à 3 °C et 2 congélateurs à −18 °C : un seul envoi, tout conforme', async () => {
        setup(SIX());
        const w = await mountScreen();
        expect(w.get('[data-testid="tmp-sum-todo"]').text()).toBe('6 à relever');
        // le seuil de chaque carte est visible
        expect(w.get('[data-testid="tmp-card-1"]').text()).toContain('0 à +4 °C');
        expect(w.get('[data-testid="tmp-card-5"]').text()).toContain('≤ −18 °C');

        for (const id of [1, 2, 3, 4]) await type(w, id, '3');
        for (const id of [5, 6]) await type(w, id, '18'); // congélateur : le signe − est déjà posé
        expect(w.get('[data-testid="tmp-sign-5"]').text()).toBe('−');
        expect(w.get('[data-testid="tmp-verdict-5"]').text()).toContain('Conforme : −18,0 °C');
        expect(w.get('[data-testid="tmp-pending"]').text()).toBe('6');

        await w.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        const [url, body] = posts[0];
        expect(url).toBe('admin/hygiene/temperature/readings');
        expect(body.readings.map((r) => [r.equipment_id, r.value])).toEqual([[1, 3], [2, 3], [3, 3], [4, 3], [5, -18], [6, -18]]);
        expect(body.readings.every((r) => r.client_ref && !('corrective_action' in r))).toBe(true);
        expect(w.get('[data-testid="tmp-sum-ok"]').text()).toBe('6 conformes');
        expect(w.get('[data-testid="tmp-sum-todo"]').text()).toBe('0 à relever');
        expect(w.get('[data-testid="tmp-done-1"]').text()).toContain('3,0 °C');
        expect(w.get('[data-testid="tmp-done-5"]').text()).toContain('−18,0 °C');
    });

    it('un frigo à 7 °C : l’écart exige ce qui a été fait, et « valider » reste bloqué tant que ce n’est pas dit', async () => {
        setup(SIX());
        const w = await mountScreen();
        await type(w, 1, '3');
        await type(w, 2, '7');
        expect(w.get('[data-testid="tmp-verdict-2"]').text()).toContain('Hors seuil');
        expect(w.get('[data-testid="tmp-verdict-2"]').text()).toContain('trop chaud');
        expect(w.find('[data-testid="tmp-corrective-2"]').exists()).toBe(true);
        expect(w.find('[data-testid="tmp-corrective-1"]').exists()).toBe(false);
        expect(w.get('[data-testid="tmp-submit"]').attributes('disabled')).toBeDefined();
        expect(w.get('[data-testid="tmp-bar"]').text()).toContain('1 écart sans action');

        await w.get('[data-testid="tmp-action-2-products_checked"]').trigger('click');
        expect(w.get('[data-testid="tmp-submit"]').attributes('disabled')).toBeUndefined();
        await w.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        const sent = posts[0][1].readings;
        expect(sent.find((r) => r.equipment_id === 2)).toMatchObject({ value: 7, corrective_action: 'products_checked' });
        expect(sent.find((r) => r.equipment_id === 1)).not.toHaveProperty('corrective_action');
        expect(w.get('[data-testid="tmp-sum-alert"]').text()).toBe('1 écart');
    });

    it('« Autre » exige une précision', async () => {
        setup(SIX());
        const w = await mountScreen();
        await type(w, 1, '9');
        await w.get('[data-testid="tmp-action-1-other"]').trigger('click');
        expect(w.get('[data-testid="tmp-submit"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="tmp-note-1"]').setValue('compresseur bruyant');
        expect(w.get('[data-testid="tmp-submit"]').attributes('disabled')).toBeUndefined();
    });

    it('congélateur à −12 : écart ; le bouton ± permet de saisir un positif', async () => {
        setup(SIX());
        const w = await mountScreen();
        await type(w, 5, '12');
        expect(w.get('[data-testid="tmp-verdict-5"]').text()).toContain('Hors seuil : −12,0 °C');
        await w.get('[data-testid="tmp-sign-5"]').trigger('click'); // +12 : encore pire, mais saisissable
        expect(w.get('[data-testid="tmp-verdict-5"]').text()).toContain('12,0 °C');
        expect(w.get('[data-testid="tmp-verdict-5"]').text()).not.toContain('−');
    });

    it('une valeur illisible n’est jamais envoyée', async () => {
        setup(SIX());
        const w = await mountScreen();
        await type(w, 1, '3,55');
        expect(w.get('[data-testid="tmp-raw-error-1"]').text()).toMatch(/illisible/);
        expect(w.get('[data-testid="tmp-pending"]').text()).toBe('0');
        expect(w.get('[data-testid="tmp-submit"]').attributes('disabled')).toBeDefined();
    });

    it('« = hier » remplit en un appui (jamais pré-rempli tout seul)', async () => {
        const items = SIX();
        items[0].previous = { value_c: 2.5, recorded_at: '2026-10-16T18:00:00+02:00', status: 'ok' };
        items[4].previous = { value_c: -19, recorded_at: '2026-10-16T18:00:00+02:00', status: 'ok' };
        setup(items);
        const w = await mountScreen();
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        expect(w.get('[data-testid="tmp-input-1"]').element.value).toBe('');
        expect(w.get('[data-testid="tmp-same-1"]').text()).toContain('2,5');
        await w.get('[data-testid="tmp-same-1"]').trigger('click');
        expect(w.get('[data-testid="tmp-input-1"]').element.value).toBe('2,5');
        await w.get('[data-testid="tmp-take-5"]').trigger('click');
        await w.get('[data-testid="tmp-same-5"]').trigger('click');
        expect(w.get('[data-testid="tmp-sign-5"]').text()).toBe('−');
        expect(w.get('[data-testid="tmp-verdict-5"]').text()).toContain('−19,0 °C');
    });

    it('refus du serveur : la saisie reste, le message s’affiche, et le renvoi garde le même client_ref (rien n’est doublé)', async () => {
        setup(SIX());
        const w = await mountScreen();
        await type(w, 1, '3');
        const first = w;
        h.axios.post.mockReset();
        const refs = [];
        h.axios.post.mockImplementation(async (url, body) => {
            refs.push(body.readings[0].client_ref);
            const e = new Error('réseau');
            e.response = { data: { message: 'Relevés refusés : test' } };
            throw e;
        });
        await first.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        expect(first.get('[data-testid="tmp-form-error"]').text()).toContain('Relevés refusés');
        expect(first.get('[data-testid="tmp-input-1"]').element.value).toBe('3');
        await first.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        expect(refs).toHaveLength(2);
        expect(refs[0]).toBe(refs[1]);
    });

    it('relevé déjà fait : « Nouveau relevé » et « Annuler ce relevé » (motif obligatoire)', async () => {
        const items = SIX();
        items[0] = { ...items[0], state: 'ok', done_this_period: true, last_today: { id: 11, equipment_id: 1, value_c: 3, status: 'ok', period: 'morning', recorded_at: '2026-10-17T08:01:00+02:00', operator_name: 'Karim' } };
        setup(items);
        const w = await mountScreen();
        expect(w.find('[data-testid="tmp-take-1"]').exists()).toBe(false);
        await w.get('[data-testid="tmp-void-1"]').trigger('click');
        expect(w.get('[data-testid="tmp-void-confirm-1"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="tmp-void-reason-1"]').setValue('faute de frappe');
        await w.get('[data-testid="tmp-void-confirm-1"]').trigger('click');
        await flushPromises();
        expect(posts).toEqual([['admin/hygiene/temperature/readings/11/void', { reason: 'faute de frappe' }]]);
        await w.get('[data-testid="tmp-again-1"]').trigger('click');
        expect(w.find('[data-testid="tmp-input-1"]').exists()).toBe(true);
    });

    it('« Saisir tout ce qui reste » ouvre toutes les cartes à relever', async () => {
        setup(SIX());
        const w = await mountScreen();
        await w.get('[data-testid="tmp-fill-all"]').trigger('click');
        for (const id of [1, 2, 3, 4, 5, 6]) expect(w.find(`[data-testid="tmp-input-${id}"]`).exists()).toBe(true);
        expect(w.get('[data-testid="tmp-fill-all"]').attributes('disabled')).toBeDefined();
    });
});

// ───────────── équipements chauds et courbe (2026-10-17 · méga-fichier P1.1) ─────────────

describe('hygieneTemperature — chaud et courbe (fonctions pures)', () => {
    it('un équipement chaud : seuil bas seul (63 °C), « pas assez chaud » en dessous', () => {
        expect(previewVerdict(63, 63, null).status).toBe('ok');
        expect(previewVerdict(62.9, 63, null)).toEqual({ status: 'alert', reason: 'too_cold' });
        expect(previewVerdict(95, 63, null).status).toBe('ok');
        expect(limitText(63, null)).toBe('≥ +63 °C');
    });

    it('chartModel : points du plus ancien au plus récent, seuils en lignes, écarts comptés, annulés ignorés', () => {
        const m = chartModel([
            { value_c: 6.5, recorded_at: '2026-10-17T08:00:00+02:00', status: 'alert' },
            { value_c: 3, recorded_at: '2026-10-15T08:00:00+02:00', status: 'ok' },
            { value_c: 33, recorded_at: '2026-10-16T08:00:00+02:00', status: 'ok', voided: true },
            { value_c: 2.5, recorded_at: '2026-10-16T18:00:00+02:00', status: 'ok' },
        ], 0, 4);
        expect(m.empty).toBe(false);
        expect(m.count).toBe(3);
        expect(m.alerts).toBe(1);
        expect(m.minV).toBe(2.5);
        expect(m.maxV).toBe(6.5);
        expect(m.dots.map((d) => d.value)).toEqual([3, 2.5, 6.5]);
        expect(m.dots[0].x).toBeLessThan(m.dots[2].x);
        // plus chaud = plus haut sur l'écran (y plus petit)
        expect(m.dots[2].y).toBeLessThan(m.dots[0].y);
        expect(m.lines.map((l) => l.kind)).toEqual(['max', 'min']);
        expect(m.lines[0].label).toBe('max 4');
        expect(m.lines[0].y).toBeLessThan(m.lines[1].y);
        expect(m.line.startsWith('M')).toBe(true);
    });

    it('chartModel : un seul relevé est centré, une série vide est « vide », seuil unique accepté', () => {
        expect(chartModel([], 0, 4).empty).toBe(true);
        expect(chartModel([{ value_c: 33, recorded_at: '2026-10-16T08:00:00+02:00', status: 'ok', voided: true }], 0, 4).empty).toBe(true);
        const one = chartModel([{ value_c: -19, recorded_at: '2026-10-16T08:00:00+02:00', status: 'ok' }], null, -18);
        expect(one.dots).toHaveLength(1);
        expect(one.dots[0].x).toBeCloseTo(one.w / 2 + 15, 0);
        expect(one.lines.map((l) => l.kind)).toEqual(['max']);
    });
});

describe('écran températures — bain-marie et courbe', () => {
    const HOT = () => EQ(7, 'Bain-marie 1', 'hot_holding', 63, null, { kind_label: 'Bain-marie / maintien au chaud', hot: true });
    const COLD = () => EQ(1, 'Frigo 1', 'fridge', 0, 4, { hot: false });
    const ACTIONS2 = [
        { key: 'defrost', label: 'Dégivrage en cours', note: false, for: 'cold' },
        { key: 'reheated', label: 'Plats réchauffés à plus de 63 °C à cœur', note: false, for: 'hot' },
        { key: 'other', label: 'Autre (à préciser)', note: true },
    ];

    function chaud() {
        setup([COLD(), HOT()]);
        const base = h.axios.get.getMockImplementation();
        h.axios.get.mockImplementation(async (url, cfg) => {
            if (url === 'admin/hygiene/temperature/today') {
                const r = await base(url, cfg);
                r.data.meta.corrective_actions = ACTIONS2;
                r.data.meta.kinds = [{ key: 'fridge', label: 'Réfrigérateur' }, { key: 'hot_holding', label: 'Bain-marie / maintien au chaud', hot: true }];
                return r;
            }
            if (url === 'admin/hygiene/temperature/history') {
                h.history = cfg;
                return { data: { data: [
                    { id: 3, equipment_id: cfg.params.equipment_id, value_c: 71, status: 'ok', recorded_at: '2026-10-16T12:00:00+02:00', voided: false },
                    { id: 4, equipment_id: cfg.params.equipment_id, value_c: 60, status: 'alert', recorded_at: '2026-10-17T12:00:00+02:00', voided: false },
                ] } };
            }
            return base(url, cfg);
        });
    }

    it('un plat à 60 °C : « pas assez chaud », et seules les actions du chaud sont proposées', async () => {
        chaud();
        const w = await mountScreen();
        expect(w.get('[data-testid="tmp-card-7"]').text()).toMatch(/≥ \+63 °C/);
        await type(w, 7, '60');
        expect(w.get('[data-testid="tmp-verdict-7"]').text()).toMatch(/Hors seuil : 60,0 °C \(pas assez chaud\)/);
        const keys = w.findAll('[data-testid^="tmp-action-7-"]').map((x) => x.attributes('data-testid'));
        expect(keys).toEqual(['tmp-action-7-reheated', 'tmp-action-7-other']);
        // le frigo, lui, garde ses actions de froid et dit « trop chaud »
        await type(w, 1, '6');
        expect(w.get('[data-testid="tmp-verdict-1"]').text()).toMatch(/\(trop chaud\)/);
        expect(w.findAll('[data-testid^="tmp-action-1-"]').map((x) => x.attributes('data-testid'))).toEqual(['tmp-action-1-defrost', 'tmp-action-1-other']);
    });

    it('à 71 °C le bain-marie est conforme, sans rien demander', async () => {
        chaud();
        const w = await mountScreen();
        await type(w, 7, '71');
        expect(w.get('[data-testid="tmp-verdict-7"]').text()).toMatch(/Conforme : 71,0 °C/);
        expect(w.find('[data-testid="tmp-corrective-7"]').exists()).toBe(false);
    });

    it('« Courbe 14 jours » charge les relevés de CET équipement, les dessine avec le seuil, et se referme', async () => {
        chaud();
        const w = await mountScreen();
        await w.get('[data-testid="tmp-chart-toggle-7"]').trigger('click');
        await flushPromises();
        expect(h.history.params.equipment_id).toBe(7);
        expect(h.history.params.from).toBe('2026-10-04');
        expect(w.get('[data-testid="tmp-card-7"] [data-testid="tmp-chart"] svg').exists()).toBe(true);
        expect(w.get('[data-testid="tmp-card-7"] [data-testid="tmp-chart-caption"]').text()).toMatch(/2 relevés sur 14 jours, de 60,0 à 71,0 °C, dont 1 écart/);
        expect(w.findAll('[data-testid="tmp-card-7"] circle')).toHaveLength(2);
        expect(w.find('[data-testid="tmp-card-1"] [data-testid="tmp-chart"]').exists()).toBe(false);
        await w.get('[data-testid="tmp-chart-toggle-7"]').trigger('click');
        expect(w.find('[data-testid="tmp-card-7"] [data-testid="tmp-chart"]').exists()).toBe(false);
    });

    it('le démarrage propose les bains-marie (chaud) à côté des frigos et congélateurs', async () => {
        setup([]);
        const w = await mountScreen();
        expect(w.find('[data-testid="tmp-setup-hot_holding"]').exists()).toBe(true);
        await w.get('[data-testid="tmp-setup-hot_holding-plus"]').trigger('click');
        await w.get('[data-testid="tmp-setup-go"]').trigger('click');
        await flushPromises();
        expect(posts[0][1].hot_holding).toBe(1);
    });
});

