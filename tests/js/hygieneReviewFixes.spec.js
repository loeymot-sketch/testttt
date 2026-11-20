/**
 * [CAYENNE HYGIÈNE 2026-10-17] Non-régression des défauts trouvés par la revue adverse des écrans (chacun reproduit avant correction).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));

import HygieneTemperatureComponent from '../../resources/js/components/admin/hygiene/HygieneTemperatureComponent.vue';
import HygieneOperatorPicker from '../../resources/js/components/admin/hygiene/HygieneOperatorPicker.vue';
import HygienePhotoView from '../../resources/js/components/admin/hygiene/HygienePhotoView.vue';
import { parseTemperature } from '../../resources/js/helpers/hygieneTemperature';
import { refFor, latest } from '../../resources/js/helpers/hygieneCommon';

const EQ = (o = {}) => ({ id: 1, name: 'Frigo 1', kind: 'fridge', kind_label: 'Réfrigérateur', negative: false, location: null, min_c: 0, max_c: 4, is_active: true, state: 'todo',
    last_today: null, previous: null, done_periods: [], done_this_period: false, ...o });
const ACTIONS = [{ key: 'door_closed', label: 'Porte refermée', note: false }, { key: 'other', label: 'Autre (à préciser)', note: true }];
let state;
let posts;

function setup(items, { equipmentRows = items, history = async () => ({ data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00' } } }), failSetup = false } = {}) {
    state = { items };
    posts = [];
    h.axios.get.mockReset();
    h.axios.post.mockReset();
    h.axios.put.mockReset();
    h.alert.error.mockReset();
    h.axios.get.mockImplementation(async (url, cfg) => {
        if (url === 'admin/hygiene/temperature/today') return { data: { data: state.items, meta: { period: 'morning', periods: { morning: 'Matin' }, summary: { total: state.items.length, todo: state.items.length, ok: 0, alert: 0 }, kinds: [{ key: 'fridge', label: 'Réfrigérateur' }], corrective_actions: ACTIONS, server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' } } };
        if (url === 'admin/hygiene/temperature/equipment') return { data: { data: equipmentRows, meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
        if (url === 'admin/hygiene/temperature/history') return history(cfg);
        return { data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        posts.push([url, body]);
        if (failSetup && url.endsWith('quick-setup')) { const e = new Error('net'); throw e; }
        return { data: { data: (body && Array.isArray(body.readings) ? body.readings.map((r, i) => ({ id: i + 1, equipment_id: r.equipment_id, client_ref: r.client_ref })) : { id: 1 }), meta: { alerts: 0 } } };
    });
    h.axios.put.mockResolvedValue({ data: { data: { id: 1 } } });
    window.localStorage.clear();
}
async function mountTemp() {
    const w = mount(HygieneTemperatureComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}

describe('températures — défauts de la revue', () => {
    it('un échec de « Créer mes équipements » laisse le formulaire de démarrage en place', async () => {
        setup([], { equipmentRows: [], failSetup: true });
        const w = await mountTemp();
        await w.get('[data-testid="tmp-setup-go"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="tmp-error"]').exists()).toBe(true);
        expect(w.find('[data-testid="tmp-setup"]').exists()).toBe(true); // on peut réessayer sans recharger la page
        expect(w.find('[data-testid="tmp-bar"]').exists()).toBe(false);
    });

    it('un seuil illisible n’est JAMAIS envoyé comme « aucun seuil »', async () => {
        setup([EQ()]);
        const w = await mountTemp();
        await w.get('[data-testid="tmp-manage-toggle"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tmp-eq-max-1"]').setValue('4a');
        await w.get('[data-testid="tmp-eq-save-1"]').trigger('click');
        await flushPromises();
        expect(h.axios.put).not.toHaveBeenCalled();
        expect(h.alert.error).toHaveBeenCalled();
        expect(h.alert.error.mock.calls[0][0]).toContain('illisible');
    });

    it('un seuil vide reste « aucun seuil » (null), un seuil valide part en nombre', async () => {
        setup([EQ()]);
        const w = await mountTemp();
        await w.get('[data-testid="tmp-manage-toggle"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tmp-eq-min-1"]').setValue('');
        await w.get('[data-testid="tmp-eq-max-1"]').setValue('5,5');
        await w.get('[data-testid="tmp-eq-save-1"]').trigger('click');
        await flushPromises();
        expect(h.axios.put.mock.calls[0][1]).toMatchObject({ min_c: null, max_c: 5.5 });
    });

    it('masquer le dernier équipement actif laisse la gestion accessible pour le réactiver', async () => {
        setup([], { equipmentRows: [EQ({ is_active: false })] });
        const w = await mountTemp();
        expect(w.find('[data-testid="tmp-setup"]').exists()).toBe(false); // pas de faux « démarrage »
        expect(w.find('[data-testid="tmp-all-hidden"]').exists()).toBe(true);
        expect(w.find('[data-testid="tmp-bar"]').exists()).toBe(false);
        expect(w.find('[data-testid="tmp-manage-toggle"]').exists()).toBe(true);
        await w.get('[data-testid="tmp-manage-toggle"]').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('Réactiver');
    });

    it('un refus du serveur s’affiche DANS la barre de validation et déclenche une alerte visible', async () => {
        setup([EQ()]);
        const w = await mountTemp();
        h.axios.post.mockImplementationOnce(async () => { const e = new Error('x'); e.response = { status: 422, data: { message: 'Relevés refusés : écart sans action.' } }; throw e; });
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('3');
        await w.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="tmp-bar"]').find('[data-testid="tmp-form-error"]').text()).toContain('écart sans action');
        expect(h.alert.error).toHaveBeenCalledWith(expect.stringContaining('écart sans action'));
    });

    it('le journal « écarts seulement » n’est pas écrasé par la réponse lente de l’ancien filtre', async () => {
        let releaseAll;
        const history = (cfg) => (cfg.params.alerts_only === 0
            ? new Promise((resolve) => { releaseAll = () => resolve({ data: { data: [{ id: 1, equipment_name: 'TOUS', value_c: 3, status: 'ok', recorded_at: '2026-10-20T08:00:00+02:00', period: 'morning', voided: false }], meta: { server_now: '2026-10-20T10:00:00+02:00' } } }); })
            : Promise.resolve({ data: { data: [{ id: 2, equipment_name: 'ECARTS', value_c: 7, status: 'alert', recorded_at: '2026-10-20T08:00:00+02:00', period: 'morning', voided: false }], meta: { server_now: '2026-10-20T10:00:00+02:00' } } }));
        setup([EQ()], { history });
        const w = await mountTemp();
        await w.get('[data-testid="tmp-history-toggle"]').trigger('click'); // requête lente (tous)
        await w.get('[data-testid="tmp-alerts-only"]').setValue(true); // requête rapide (écarts)
        await flushPromises();
        releaseAll(); // la lente arrive APRÈS
        await flushPromises();
        const text = w.get('[data-testid="tmp-history"]').text();
        expect(text).toContain('ECARTS');
        expect(text).not.toContain('TOUS');
    });

    it('« hier » n’est écrit que pour hier : une valeur plus ancienne dit sa vraie date', async () => {
        setup([EQ({ previous: { value_c: 3, recorded_at: '2026-10-15T18:00:00+02:00', status: 'ok' } })]);
        const w = await mountTemp();
        const label = w.get('[data-testid="tmp-take-1"]').text();
        expect(label).not.toContain('hier');
        expect(label).toContain('15/10');
    });

    it('coupure réseau : le relevé est GARDÉ sur la tablette (pas perdu, pas doublé), rejoué à l’identique, puis la file se vide', async () => {
        setup([EQ()]);
        const w = await mountTemp();
        const net = async (url, body) => { posts.push([url, body]); throw new Error('Network Error'); };
        h.axios.post.mockImplementationOnce(net).mockImplementationOnce(net); // coupure : l'envoi puis le premier rejeu échouent
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('3');
        await w.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();

        // gardé : la saisie est partie de l'écran, la carte et la bannière le disent
        expect(w.find('[data-testid="tmp-input-1"]').exists()).toBe(false);
        expect(w.get('[data-testid="tmp-offline"]').text()).toContain('1 relevé gardé sur cette tablette');
        expect(w.get('[data-testid="tmp-queued-1"]').text()).toContain('3,0 °C');
        expect(h.alert.info).toHaveBeenCalledWith(expect.stringContaining('gardé'));

        await w.get('[data-testid="tmp-offline-retry"]').trigger('click'); // le réseau n'est pas revenu
        await flushPromises();
        expect(w.find('[data-testid="tmp-offline"]').exists()).toBe(true);

        await w.get('[data-testid="tmp-offline-retry"]').trigger('click'); // le réseau est revenu
        await flushPromises();
        expect(w.find('[data-testid="tmp-offline"]').exists()).toBe(false);
        const sent = posts.filter(([u]) => u === 'admin/hygiene/temperature/readings').map(([, b]) => b);
        expect(sent).toHaveLength(3);
        expect(sent[0].readings[0].measured_at).toBeUndefined(); // en ligne, c'est le serveur qui date
        expect(sent[1].readings[0].client_ref).toBe(sent[0].readings[0].client_ref); // même référence : jamais de doublon
        expect(sent[2].readings[0].client_ref).toBe(sent[0].readings[0].client_ref);
        expect(Number.isFinite(Date.parse(sent[1].readings[0].measured_at))).toBe(true); // l'heure de la mesure part avec le rejeu
        expect(sent[2].readings[0].measured_at).toBe(sent[1].readings[0].measured_at);
    });

    it('un rejeu REFUSÉ par le serveur n’est pas jeté en silence : la raison s’affiche jusqu’à ce que la personne l’ait lue', async () => {
        setup([EQ()]);
        const w = await mountTemp();
        h.axios.post.mockImplementationOnce(async () => { throw new Error('Network Error'); })
            .mockImplementationOnce(async () => { const e = new Error('422'); e.response = { status: 422, data: { message: 'x', errors: { 'readings.0.measured_at': ['« Frigo 1 » : ce relevé date de plus de 72 h — ressaisissez-le.'] } } }; throw e; });
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('3');
        await w.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="tmp-offline-retry"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="tmp-offline"]').exists()).toBe(false);
        const refused = w.get('[data-testid="tmp-refused"]');
        expect(refused.text()).toContain('plus de 72 h');
        expect(refused.text()).toContain("rien n'a été enregistré");
        await w.get('[data-testid="tmp-refused-ok"]').trigger('click');
        expect(w.find('[data-testid="tmp-refused"]').exists()).toBe(false);
    });

    it('la file survit à un rechargement de la page : un relevé gardé repart à l’ouverture de l’écran', async () => {
        setup([EQ()]);
        const w1 = await mountTemp();
        h.axios.post.mockImplementationOnce(async () => { throw new Error('Network Error'); });
        await w1.get('[data-testid="tmp-take-1"]').trigger('click');
        await w1.get('[data-testid="tmp-input-1"]').setValue('3');
        await w1.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        w1.unmount();

        h.axios.post.mockReset();
        h.axios.post.mockImplementation(async (url, body) => { posts.push([url, body]); return { data: { data: (body && Array.isArray(body.readings) ? body.readings.map((r, i) => ({ id: i + 1, equipment_id: r.equipment_id, client_ref: r.client_ref })) : { id: 1 }), meta: { alerts: 0 } } }; });
        const w2 = await mountTemp(); // « rechargement » : le réseau est revenu
        await flushPromises();
        expect(posts.filter(([u]) => u === 'admin/hygiene/temperature/readings').length).toBeGreaterThanOrEqual(1);
        expect(w2.find('[data-testid="tmp-offline"]').exists()).toBe(false);
        expect(h.alert.success).toHaveBeenCalledWith(expect.stringContaining('hors ligne'));
    });
});

describe('aides communes', () => {
    it('parseTemperature : un moins en tête est toléré, au milieu c’est illisible (jamais « 3-5 » → 35)', () => {
        expect(parseTemperature('−18')).toBe(18);
        expect(parseTemperature('-3,5')).toBe(3.5);
        expect(parseTemperature('3-5')).toBeNull();
        expect(parseTemperature('1-8')).toBeNull();
        expect(parseTemperature('3,5')).toBe(3.5);
        expect(parseTemperature('180', 3)).toBe(180);
    });

    it('refFor : même contenu = même référence, contenu différent = autre référence', () => {
        expect(refFor('t1', { v: 3 })).toBe(refFor('t1', { v: 3 }));
        expect(refFor('t1', { v: 3 })).not.toBe(refFor('t1', { v: 2 }));
        expect(refFor('t1', { v: 3 })).not.toBe(refFor('t2', { v: 3 }));
    });

    it('latest : seule la dernière requête est courante', () => {
        const l = latest();
        const a = l.next();
        const b = l.next();
        expect(l.isCurrent(a)).toBe(false);
        expect(l.isCurrent(b)).toBe(true);
    });
});

describe('qui est-ce ? — choix mémorisé par utilisateur connecté', () => {
    const PEOPLE = (me) => ({ data: { data: [{ id: 7, name: 'Alice A', short: 'Alice A.', is_me: me === 7 }, { id: 8, name: 'Bob B', short: 'Bob B.', is_me: me === 8 }], meta: { me, server_now: '2026-10-20T10:00:00+02:00' } } });

    it('Bob qui se connecte n’hérite pas du choix d’Alice sur la même tablette', async () => {
        h.axios.get.mockReset();
        window.localStorage.clear();
        h.axios.get.mockResolvedValue(PEOPLE(7));
        let w = mount(HygieneOperatorPicker, { props: { modelValue: null } });
        await flushPromises();
        await w.get('[data-testid="hy-operator-btn"]').trigger('click');
        await w.get('[data-testid="hy-operator-8"]').trigger('click'); // Alice désigne Bob pour ce relevé
        h.axios.get.mockResolvedValue(PEOPLE(8)); // Bob se connecte
        w = mount(HygieneOperatorPicker, { props: { modelValue: null } });
        await flushPromises();
        expect(w.emitted('update:modelValue').at(-1)).toEqual([null]); // « moi » : l'utilisateur connecté, pas le choix d'Alice
        expect(w.get('[data-testid="hy-operator-btn"]').text()).toContain('Bob B.');
        h.axios.get.mockResolvedValue(PEOPLE(7)); // Alice revient : son choix est retrouvé
        w = mount(HygieneOperatorPicker, { props: { modelValue: null } });
        await flushPromises();
        expect(w.get('[data-testid="hy-operator-btn"]').text()).toContain('Bob B.');
    });
});

describe('qui est-ce ? — jamais une liste vide muette (méga-fichier : défaut n° 1 de Komia)', () => {
    it('aucun employé : le dit, explique ce qui se passe en attendant, et propose d’en ajouter', async () => {
        h.axios.get.mockReset();
        window.localStorage.clear();
        h.axios.get.mockResolvedValue({ data: { data: [], meta: { me: 7, server_now: '2026-10-20T10:00:00+02:00' } } });
        const w = mount(HygieneOperatorPicker, { props: { modelValue: null }, global: { stubs: { 'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' } } } });
        await flushPromises();
        await w.get('[data-testid="hy-operator-btn"]').trigger('click');
        const empty = w.get('[data-testid="hy-operator-empty"]');
        expect(empty.text()).toMatch(/Aucun employé enregistré/);
        expect(empty.text()).toMatch(/attribué au compte connecté/);
        expect(w.get('[data-testid="hy-operator-add"]').attributes('href')).toBe('/admin/employees');
        expect(w.get('[data-testid="hy-operator-btn"]').text()).toContain('moi');
        // et il y a du monde : pas de message
        h.axios.get.mockResolvedValue({ data: { data: [{ id: 7, name: 'Alice A', short: 'Alice A.', is_me: true }], meta: { me: 7, server_now: '2026-10-20T10:00:00+02:00' } } });
        const w2 = mount(HygieneOperatorPicker, { props: { modelValue: null } });
        await flushPromises();
        await w2.get('[data-testid="hy-operator-btn"]').trigger('click');
        expect(w2.find('[data-testid="hy-operator-empty"]').exists()).toBe(false);
    });
});

describe('photo jointe', () => {
    it('un double appui avant l’arrivée de l’image ne crée pas deux liens à libérer', async () => {
        const created = [];
        globalThis.URL.createObjectURL = vi.fn(() => { const u = `blob:${created.length + 1}`; created.push(u); return u; });
        globalThis.URL.revokeObjectURL = vi.fn();
        let release;
        h.axios.get.mockReset();
        h.axios.get.mockImplementation(() => new Promise((resolve) => { release = () => resolve({ data: new Blob(['x']) }); }));
        const w = mount(HygienePhotoView, { props: { id: 5 } });
        await w.get('[data-testid="hy-photo-view-5"]').trigger('click');
        await w.get('[data-testid="hy-photo-view-5"]').trigger('click');
        release();
        await flushPromises();
        expect(h.axios.get).toHaveBeenCalledTimes(1);
        expect(created).toHaveLength(1);
    });
});

describe('relecture adverse (2026-10-17) — « Qui ? »', () => {
    it('liste impossible à charger : on ne dit PAS « aucun employé enregistré »', async () => {
        h.axios.get.mockReset();
        window.localStorage.clear();
        h.axios.get.mockRejectedValue(new Error('réseau'));
        const w = mount(HygieneOperatorPicker, { props: { modelValue: null }, global: { stubs: { 'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' } } } });
        await flushPromises();
        await w.get('[data-testid="hy-operator-btn"]').trigger('click');
        expect(w.get('[data-testid="hy-operator-failed"]').text()).toMatch(/Liste indisponible/);
        expect(w.find('[data-testid="hy-operator-empty"]').exists()).toBe(false);
        expect(w.find('[data-testid="hy-operator-add"]').exists()).toBe(false);
    });
});

describe('suivi après écart (2026-10-18)', () => {
    const ACTIONS2 = [{ key: 'recheck', label: 'Nouvelle mesure après quelques minutes', note: false, followup: true }, { key: 'technician', label: 'Technicien appelé', note: false }];

    async function mountWith(items, meta = {}) {
        h.axios.get.mockReset(); h.axios.post.mockReset(); window.localStorage.clear();
        h.axios.get.mockImplementation(async (url) => {
            if (url === 'admin/hygiene/temperature/today') return { data: { data: items, meta: { period: 'morning', periods: { morning: 'Matin' }, summary: { total: items.length, todo: 0, ok: 0, alert: 1 }, kinds: [{ key: 'fridge', label: 'Réfrigérateur' }], corrective_actions: ACTIONS2, followup_minutes: 30, server_now: '2026-10-20T10:20:00+02:00', timezone: 'Europe/Paris', ...meta } } };
            return { data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
        });
        const w = mount(HygieneTemperatureComponent, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();
        return w;
    }
    const alertCard = (followup) => EQ({ state: 'alert', done_this_period: true, last_today: { id: 5, equipment_id: 1, value_c: 9, status: 'alert', alert_reason: 'too_warm', period: 'morning', recorded_at: '2026-10-20T10:00:00+02:00', operator_name: 'Karim', corrective_action: 'recheck', corrective_label: 'Nouvelle mesure après quelques minutes', voided: false }, followup });

    it('la carte dit l’heure avant laquelle la nouvelle mesure est attendue et met « Nouveau relevé » en avant', async () => {
        const w = await mountWith([alertCard({ reading_id: 5, value_c: 9, recorded_at: '2026-10-20T10:00:00+02:00', due_at: '2026-10-20T10:30:00+02:00', overdue: false, minutes: 30 })]);
        const t = w.get('[data-testid="tmp-followup-1"]');
        expect(t.text()).toContain('Nouvelle mesure à faire avant 10:30');
        expect(t.classes()).not.toContain('is-overdue');
        expect(w.get('[data-testid="tmp-again-1"]').classes()).toContain('tmp-btn--primary');
    });

    it('passé l’heure, la carte le dit en rouge', async () => {
        const w = await mountWith([alertCard({ reading_id: 5, value_c: 9, recorded_at: '2026-10-20T09:00:00+02:00', due_at: '2026-10-20T09:30:00+02:00', overdue: true, minutes: 30 })]);
        const t = w.get('[data-testid="tmp-followup-1"]');
        expect(t.text()).toContain('Nouvelle mesure en retard : attendue depuis 09:30');
        expect(t.classes()).toContain('is-overdue');
    });

    it('sans suivi attendu, rien n’est affiché', async () => {
        const w = await mountWith([alertCard(null)]);
        expect(w.find('[data-testid="tmp-followup-1"]').exists()).toBe(false);
        expect(w.get('[data-testid="tmp-again-1"]').classes()).not.toContain('tmp-btn--primary');
    });

    it('choisir une action « c’est réglé » annonce la nouvelle mesure ; une action sans suivi ne l’annonce pas', async () => {
        const w = await mountWith([EQ()]);
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('9');
        expect(w.find('[data-testid="tmp-followup-hint-1"]').exists()).toBe(false); // aucune action choisie
        await w.get('[data-testid="tmp-action-1-recheck"]').trigger('click');
        expect(w.get('[data-testid="tmp-followup-hint-1"]').text()).toContain('dans 30 minutes');
        await w.get('[data-testid="tmp-action-1-technician"]').trigger('click');
        expect(w.find('[data-testid="tmp-followup-hint-1"]').exists()).toBe(false);
    });
});

describe('relevés hors ligne — revue adverse (2026-10-18)', () => {
    const NET = () => new Error('Network Error');
    async function mountOps({ items = [EQ()], operators } = {}) {
        h.axios.get.mockReset(); h.axios.post.mockReset(); window.localStorage.clear();
        h.axios.get.mockImplementation(async (url) => {
            if (url === 'admin/hygiene/operators') return { data: { data: operators || [{ id: 1, name: 'Compte Tablette', short: 'Compte T.', is_me: true, has_pin: false }], meta: { me: 1, server_now: '2026-10-20T10:00:00+02:00' } } };
            if (url === 'admin/hygiene/temperature/today') return { data: { data: items, meta: { period: 'morning', periods: { morning: 'Matin' }, summary: { total: items.length, todo: items.length, ok: 0, alert: 0 }, kinds: [{ key: 'fridge', label: 'Réfrigérateur' }], corrective_actions: ACTIONS, server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' } } };
            return { data: { data: [], meta: { server_now: '2026-10-20T10:00:00+02:00' } } };
        });
        const w = mount(HygieneTemperatureComponent, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();
        return w;
    }
    const queued = () => JSON.parse(window.localStorage.getItem('hygiene_offline_queue') || '[]');

    it('ce qui est gardé hors ligne est EXACTEMENT ce qui a été validé, même si la saisie est retouchée pendant l’attente', async () => {
        const w = await mountOps();
        const posts = [];
        let fail;
        h.axios.post.mockImplementation((url, body) => { posts.push(body); return new Promise((_, reject) => { fail = () => reject(NET()); }); });
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('3');
        await w.get('[data-testid="tmp-submit"]').trigger('click');       // l'envoi traîne
        expect(w.get('[data-testid="tmp-input-1"]').attributes('readonly')).toBeDefined(); // la saisie est figée pendant l'attente
        expect(w.get('[data-testid="tmp-cancel-1"]').attributes('disabled')).toBeDefined();
        fail();
        await flushPromises();
        const q1 = queued();
        expect(q1).toHaveLength(1);
        expect(q1[0].body.readings[0].value).toBe(3);                     // la valeur validée
        expect(q1[0].body.readings[0].client_ref).toBe(posts[0].readings[0].client_ref); // la même référence : un rejeu, jamais un doublon
        expect(q1[0].body.readings[0].equipment_name).toBe('Frigo 1');    // dit CE qui est gardé, même sans la liste des équipements
    });

    it('si la carte disparaît pendant l’attente, rien n’est perdu en silence (plus de TypeError)', async () => {
        const w = await mountOps();
        let fail;
        h.axios.post.mockImplementation(() => new Promise((_, reject) => { fail = () => reject(NET()); }));
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('3');
        await w.get('[data-testid="tmp-submit"]').trigger('click');
        w.vm.entries = {};                                                // un rechargement retire la saisie pendant l'attente
        fail();
        await flushPromises();
        expect(queued()).toHaveLength(1);
        expect(queued()[0].body.readings[0].value).toBe(3);
        expect(h.alert.info).toHaveBeenCalledWith(expect.stringContaining('gardé'));
    });

    it('« moi » est écrit en toutes lettres dans l’entrée gardée : un autre compte qui la rejoue ne la signe pas à sa place', async () => {
        const w = await mountOps();
        h.axios.post.mockImplementation(async () => { throw NET(); });
        await w.get('[data-testid="tmp-take-1"]').trigger('click');
        await w.get('[data-testid="tmp-input-1"]').setValue('3');
        await w.get('[data-testid="tmp-submit"]').trigger('click');
        await flushPromises();
        expect(queued()[0].body.operator_user_id).toBe(1);               // le compte qui a SAISI
        // en ligne, rien de changé : « moi » reste implicite
        const posts = [];
        h.axios.post.mockReset();
        h.axios.post.mockImplementation(async (url, body) => { posts.push(body); return { data: { data: (body && Array.isArray(body.readings) ? body.readings.map((r, i) => ({ id: i + 1, equipment_id: r.equipment_id, client_ref: r.client_ref })) : { id: 1 }), meta: { alerts: 0 } } }; });
        await w.get('[data-testid="tmp-offline-retry"]').trigger('click');
        await flushPromises();
        expect(posts[0].operator_user_id).toBe(1);
        expect(posts[0].client_now).toBeTruthy();                        // l'heure de la tablette part avec l'envoi (correction de dérive côté serveur)
    });

    it('un relevé gardé pour un équipement déjà relevé se voit sur sa carte, et « Saisir tout ce qui reste » ne le rouvre pas', async () => {
        const done = EQ({ state: 'ok', done_this_period: true, last_today: { id: 5, equipment_id: 1, value_c: 3, status: 'ok', period: 'morning', recorded_at: '2026-10-20T08:00:00+02:00', operator_name: 'Karim', voided: false } });
        const w = await mountOps({ items: [done, EQ({ id: 2, name: 'Frigo 2' })] });
        window.localStorage.setItem('hygiene_offline_queue', JSON.stringify([{ qid: 'a', url: 'admin/hygiene/temperature/readings', kind: 'temperature', at: Date.now(), tries: 1, state: 'pending', reason: '', label: '1 relevé',
            body: { readings: [{ equipment_id: 1, value: 4.5, measured_at: '2026-10-20T09:40:00+02:00', client_ref: 'x1', equipment_name: 'Frigo 1' }] } }]));
        w.vm.refreshQueue();
        await flushPromises();
        expect(w.get('[data-testid="tmp-queued-inline-1"]').text()).toContain('4,5 °C gardée sur la tablette');
        expect(w.find('[data-testid="tmp-again-1"]').exists()).toBe(false);                     // pas de ressaisie qui ferait un doublon
        await w.get('[data-testid="tmp-fill-all"]').trigger('click');
        expect(w.find('[data-testid="tmp-input-2"]').exists()).toBe(true);                       // Frigo 2 s'ouvre
        expect(w.find('[data-testid="tmp-input-1"]').exists()).toBe(false);                      // pas le Frigo 1, déjà gardé
    });

    it('une mesure déjà gardée n’est pas rouverte à la main non plus : on dit pourquoi', async () => {
        const w = await mountOps();
        window.localStorage.setItem('hygiene_offline_queue', JSON.stringify([{ qid: 'a', url: 'u', kind: 'temperature', at: Date.now(), tries: 1, state: 'pending', reason: '', label: '1 relevé',
            body: { readings: [{ equipment_id: 1, value: 4.5, measured_at: '2026-10-20T09:40:00+02:00', client_ref: 'x1', equipment_name: 'Frigo 1' }] } }]));
        w.vm.refreshQueue();
        await flushPromises();
        w.vm.startEntry(w.vm.items[0]);
        await flushPromises();
        expect(w.find('[data-testid="tmp-input-1"]').exists()).toBe(false);
        expect(h.alert.info).toHaveBeenCalledWith(expect.stringContaining('déjà gardée'));
    });

    it('le bandeau dit CE qui est gardé (équipement, valeur, heure), et un refus garde ses valeurs sous les yeux', async () => {
        const w = await mountOps();
        window.localStorage.setItem('hygiene_offline_queue', JSON.stringify([
            { qid: 'p', url: 'u', kind: 'temperature', at: Date.now(), tries: 1, state: 'pending', reason: '', label: '1 relevé', body: { readings: [{ equipment_id: 1, value: 3, measured_at: '2026-10-20T10:12:00+02:00', client_ref: 'a', equipment_name: 'Frigo 1' }] } },
            { qid: 'r', url: 'u', kind: 'temperature', at: Date.now(), tries: 2, state: 'rejected', reason: '« Frigo 2 » : 99 °C n’est pas plausible.', label: '1 relevé', body: { readings: [{ equipment_id: 2, value: 99, measured_at: '2026-10-20T10:20:00+02:00', client_ref: 'b', equipment_name: 'Frigo 2' }] } },
        ]));
        w.vm.refreshQueue();
        await flushPromises();
        expect(w.get('[data-testid="tmp-offline-lines"]').text()).toContain('Frigo 1 : 3,0 °C à 10:12');
        const refused = w.get('[data-testid="tmp-refused"]');
        expect(refused.text()).toContain('pas plausible');
        expect(w.get('[data-testid="tmp-refused-lines"]').text()).toContain('Frigo 2 : 99,0 °C à 10:20');
    });

    it('la cause réelle est dite : session expirée ≠ pas de réseau ≠ serveur en panne', async () => {
        const cases = [[401, 'Session expirée'], [403, 'Droit refusé'], [500, 'Serveur indisponible'], [undefined, 'Pas de réseau']];
        for (const [status, text] of cases) {
            h.alert.info.mockReset();
            const w = await mountOps();
            h.axios.post.mockImplementation(async () => { const e = new Error('x'); if (status) e.response = { status, data: {} }; throw e; });
            await w.get('[data-testid="tmp-take-1"]').trigger('click');
            await w.get('[data-testid="tmp-input-1"]').setValue('3');
            await w.get('[data-testid="tmp-submit"]').trigger('click');
            await flushPromises();
            expect(h.alert.info, String(status)).toHaveBeenCalledWith(expect.stringContaining(text));
            w.unmount();
        }
    });

    it('un suivi impossible se clôt en disant pourquoi (au moins 5 caractères), signé de la personne désignée', async () => {
        const alertCard = EQ({ state: 'alert', done_this_period: true, last_today: { id: 5, equipment_id: 1, value_c: 55, status: 'alert', alert_reason: 'too_cold', period: 'morning', recorded_at: '2026-10-20T09:00:00+02:00', operator_name: 'Karim', corrective_action: 'reheated', corrective_label: 'Plats réchauffés', voided: false },
            followup: { reading_id: 5, value_c: 55, recorded_at: '2026-10-20T09:00:00+02:00', due_at: '2026-10-20T09:30:00+02:00', overdue: true, minutes: 30 } });
        const w = await mountOps({ items: [alertCard] });
        h.axios.post.mockImplementation(async () => ({ data: { data: { id: 1 } } }));
        await w.get('[data-testid="tmp-close-open-1"]').trigger('click');
        await w.get('[data-testid="tmp-close-reason-1"]').setValue('off');
        expect(w.get('[data-testid="tmp-close-go-1"]').attributes('disabled')).toBeDefined();
        await w.get('[data-testid="tmp-close-reason-1"]').setValue('appareil éteint, service terminé');
        await w.get('[data-testid="tmp-close-go-1"]').trigger('click');
        await flushPromises();
        expect(h.axios.post).toHaveBeenCalledWith('admin/hygiene/temperature/readings/5/followup-close', { reason: 'appareil éteint, service terminé', operator_user_id: undefined, operator_token: undefined });
        expect(w.find('[data-testid="tmp-close-1"]').exists()).toBe(false);
    });

    it('un refus de la clôture est affiché et la saisie reste', async () => {
        const alertCard = EQ({ state: 'alert', done_this_period: true, last_today: { id: 5, equipment_id: 1, value_c: 55, status: 'alert', alert_reason: 'too_cold', period: 'morning', recorded_at: '2026-10-20T09:00:00+02:00', operator_name: 'Karim', corrective_action: 'reheated', voided: false },
            followup: { reading_id: 5, value_c: 55, recorded_at: '2026-10-20T09:00:00+02:00', due_at: '2026-10-20T09:30:00+02:00', overdue: true, minutes: 30 } });
        const w = await mountOps({ items: [alertCard] });
        h.axios.post.mockImplementation(async () => { const e = new Error('422'); e.response = { status: 422, data: { message: 'x', errors: { reading: ['Aucun suivi n’est attendu pour ce relevé.'] } } }; throw e; });
        await w.get('[data-testid="tmp-close-open-1"]').trigger('click');
        await w.get('[data-testid="tmp-close-reason-1"]').setValue('appareil éteint');
        await w.get('[data-testid="tmp-close-go-1"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="tmp-close-error-1"]').text()).toContain('Aucun suivi');
        expect(w.get('[data-testid="tmp-close-reason-1"]').element.value).toBe('appareil éteint');
    });
});
