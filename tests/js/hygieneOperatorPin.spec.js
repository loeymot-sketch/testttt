/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] Côté tablette : le code est demandé pour une personne protégée, le jeton
 * reste en mémoire (jamais le code), expire, et accompagne les écritures. Côté responsable : définir / changer / retirer un code.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));

import HygieneOperatorPicker from '../../resources/js/components/admin/hygiene/HygieneOperatorPicker.vue';
import HygieneTeamComponent from '../../resources/js/components/admin/hygiene/HygieneTeamComponent.vue';
import HygieneTemperatureComponent from '../../resources/js/components/admin/hygiene/HygieneTemperatureComponent.vue';

const PEOPLE = [
    { id: 1, name: 'Compte Tablette', short: 'Compte T.', is_me: true, has_pin: false },
    { id: 7, name: 'Sarah Lopez', short: 'Sarah L.', is_me: false, has_pin: true },
    { id: 8, name: 'Bob Martin', short: 'Bob M.', is_me: false, has_pin: false },
];
const q = (w, id) => w.find(`[data-testid="${id}"]`);
const err = (status, data) => { const e = new Error(String(status)); e.response = { status, data }; return e; };

function setupOps({ people = PEOPLE, verify } = {}) {
    h.axios.get.mockReset(); h.axios.post.mockReset();
    h.axios.get.mockImplementation(async (url) => (url === 'admin/hygiene/operators' ? { data: { data: people, meta: { me: 1 } } } : { data: { data: [] } }));
    h.axios.post.mockImplementation(async (url, body) => (url === 'admin/hygiene/operators/verify' ? verify(body) : { data: { data: [], meta: { alerts: 0 } } }));
    window.localStorage.clear();
}
async function mountPicker(props = {}) {
    const w = mount(HygieneOperatorPicker, { props, global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}
const lastEmit = (w, name) => (w.emitted(name) || []).slice(-1)[0];

describe('sélecteur « Qui ? » avec code', () => {
    beforeEach(() => { vi.useRealTimers(); });
    afterEach(() => { vi.useRealTimers(); });

    it('une personne protégée est marquée 🔒 ; choisir son nom demande le code (pas de choix immédiat)', async () => {
        setupOps({ verify: async () => ({ data: { data: { token: 'T', expires_at: new Date(Date.now() + 600000).toISOString() } } }) });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        expect(q(w, 'hy-operator-7').text()).toContain('🔒');
        expect(q(w, 'hy-operator-8').text()).not.toContain('🔒');
        await q(w, 'hy-operator-7').trigger('click');
        expect(q(w, 'hy-pin').exists()).toBe(true);
        expect(q(w, 'hy-pin').text()).toContain('Code de Sarah L.');
        expect(lastEmit(w, 'update:modelValue')).toEqual([null]); // rien n'a changé tant que le code n'est pas validé
    });

    it('le bon code choisit la personne, émet le jeton (pas le code) et ne garde rien en stockage', async () => {
        const verify = vi.fn(async () => ({ data: { data: { token: 'JETON', expires_at: new Date(Date.now() + 600000).toISOString() } } }));
        setupOps({ verify });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        expect(verify).toHaveBeenCalledWith({ user_id: 7, pin: '4821' });
        expect(lastEmit(w, 'update:modelValue')).toEqual([7]);
        expect(lastEmit(w, 'update:token')).toEqual(['JETON']);
        expect(q(w, 'hy-operator-btn').text()).toContain('Sarah L.');
        expect(q(w, 'hy-pin').exists()).toBe(false);
        // le code ne touche jamais le stockage ; le choix d'une personne protégée n'est jamais mémorisé
        expect(JSON.stringify(window.localStorage)).not.toContain('4821');
        expect(window.localStorage.getItem('hygiene.operator.1')).toBeNull();
    });

    it('un mauvais code reste sur la saisie, dit les essais restants, vide le champ et ne choisit personne', async () => {
        setupOps({ verify: async () => { throw err(422, { message: 'x', errors: { pin: ['Code incorrect (4 essais restants).'] } }); } });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('0000');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        expect(q(w, 'hy-pin-error').text()).toContain('4 essais restants');
        expect(q(w, 'hy-pin-input').element.value).toBe('');
        expect(lastEmit(w, 'update:modelValue')).toEqual([null]);
        expect(lastEmit(w, 'update:token')).toEqual([null]);
    });

    it('trop d’essais : le blocage est dit tel quel', async () => {
        setupOps({ verify: async () => { throw err(423, { message: 'x', errors: { pin: ['Trop d’essais : bloqué pendant 10 minutes.'] } }); } });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('1234');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        expect(q(w, 'hy-pin-error').text()).toContain('bloqué pendant 10 minutes');
    });

    it('sans réseau, la vérification le dit (et on peut toujours choisir « moi »)', async () => {
        setupOps({ verify: async () => { throw new Error('Network Error'); } });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        expect(q(w, 'hy-pin-error').text()).toContain('pas de réseau');
        await q(w, 'hy-pin-cancel').trigger('click');
        await q(w, 'hy-operator-1').trigger('click');
        expect(lastEmit(w, 'update:modelValue')).toEqual([null]);
    });

    it('le format est contrôlé avant tout appel (4 à 6 chiffres)', async () => {
        const verify = vi.fn();
        setupOps({ verify });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        for (const bad of ['12', 'abcd', '1234567']) {
            await q(w, 'hy-pin-input').setValue(bad);
            expect(q(w, 'hy-pin-ok').attributes('disabled')).toBeDefined();
        }
        await q(w, 'hy-pin-input').setValue('123456');
        expect(q(w, 'hy-pin-ok').attributes('disabled')).toBeUndefined();
        expect(verify).not.toHaveBeenCalled();
    });

    it('soi-même ou une personne sans code : aucun code demandé', async () => {
        setupOps({ verify: vi.fn() });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-8').trigger('click');
        expect(q(w, 'hy-pin').exists()).toBe(false);
        expect(lastEmit(w, 'update:modelValue')).toEqual([8]);
        expect(lastEmit(w, 'update:token')).toEqual([null]);
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-1').trigger('click');
        expect(q(w, 'hy-pin').exists()).toBe(false);
        expect(lastEmit(w, 'update:modelValue')).toEqual([null]);
    });

    it('au bout de la validité du jeton, la personne reste affichée SANS jeton (jamais de retour silencieux sur le compte partagé) avec une explication', async () => {
        vi.useFakeTimers();
        setupOps({ verify: async () => ({ data: { data: { token: 'T', expires_at: new Date(Date.now() + 600000).toISOString() } } }) });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        expect(lastEmit(w, 'update:modelValue')).toEqual([7]);
        expect(lastEmit(w, 'update:token')).toEqual(['T']);

        vi.advanceTimersByTime(601000);
        await flushPromises();
        expect(lastEmit(w, 'update:token')).toEqual([null]);          // le jeton n'est plus joint…
        expect(lastEmit(w, 'update:modelValue')).toEqual([7]);        // …mais la personne reste désignée : la prochaine écriture est REFUSÉE par le serveur, pas signée « moi »
        expect(q(w, 'hy-operator-expired').text()).toContain('Le code de Sarah L. a expiré');
        expect(q(w, 'hy-operator-btn').text()).toContain('Sarah L.');
    });

    it('refermer le menu efface un code à moitié tapé : un collègue qui touche la pastille ensuite ne retrouve jamais le code d’un autre', async () => {
        const verify = vi.fn(async () => ({ data: { data: { token: 'T', expires_at: new Date(Date.now() + 600000).toISOString() } } }));
        setupOps({ verify });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-operator-btn').trigger('click');           // on referme sans valider
        expect(q(w, 'hy-pin').exists()).toBe(false);
        await q(w, 'hy-operator-btn').trigger('click');           // le collègue rouvre
        expect(q(w, 'hy-pin').exists()).toBe(false);              // pas de saisie de code : la LISTE
        expect(q(w, 'hy-operator-list').exists()).toBe(true);
        await q(w, 'hy-operator-7').trigger('click');
        expect(q(w, 'hy-pin-input').element.value).toBe('');      // et le champ est vide
        expect(verify).not.toHaveBeenCalled();
    });

    it('Échap ferme le menu et efface le code', async () => {
        setupOps({ verify: vi.fn() });
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-operator').trigger('keydown', { key: 'Escape' });
        expect(q(w, 'hy-pin').exists()).toBe(false);
        expect(q(w, 'hy-operator-list').exists()).toBe(false);
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        expect(q(w, 'hy-pin-input').element.value).toBe('');
    });

    it('la liste est rechargée à l’ouverture du menu quand son premier chargement a échoué (comme le message le promet)', async () => {
        let calls = 0;
        h.axios.get.mockReset(); h.axios.post.mockReset(); window.localStorage.clear();
        h.axios.get.mockImplementation(async (url) => {
            if (url !== 'admin/hygiene/operators') return { data: { data: [] } };
            calls += 1;
            if (calls === 1) throw new Error('Network Error');
            return { data: { data: PEOPLE, meta: { me: 1 } } };
        });
        const w = mount(HygieneOperatorPicker, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();
        await q(w, 'hy-operator-btn').trigger('click'); // ouvre → recharge
        await flushPromises();
        expect(calls).toBe(2);
        expect(q(w, 'hy-operator-7').exists()).toBe(true);
        expect(q(w, 'hy-operator-btn').text()).toContain('Compte T.');
    });

    it('si on change de personne pendant la vérification, la réponse de la première ne s’applique pas à la seconde', async () => {
        let release;
        setupOps({ verify: () => new Promise((resolve) => { release = () => resolve({ data: { data: { token: 'JETON-DE-SARAH', expires_at: new Date(Date.now() + 600000).toISOString() } } }); }) });
        setupOps2();
        const w = await mountPicker();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-pin-ok').trigger('click');             // vérification en cours (lente)
        await q(w, 'hy-pin-cancel').trigger('click');         // on annule
        await q(w, 'hy-operator-9').trigger('click');         // et on choisit Lina, protégée elle aussi
        release();                                             // la réponse de Sarah arrive MAINTENANT
        await flushPromises();
        expect(lastEmit(w, 'update:modelValue')).toEqual([null]);       // Lina n'est PAS validée
        expect(lastEmit(w, 'update:token')).not.toEqual(['JETON-DE-SARAH']);
        expect(q(w, 'hy-pin').text()).toContain('Code de Lina R.');     // on attend toujours SON code

        function setupOps2() {
            h.axios.get.mockImplementation(async (url) => (url === 'admin/hygiene/operators'
                ? { data: { data: [...PEOPLE, { id: 9, name: 'Lina Roy', short: 'Lina R.', is_me: false, has_pin: true }], meta: { me: 1 } } } : { data: { data: [] } }));
        }
    });

    it('une personne protégée n’est jamais rétablie toute seule depuis la mémoire de la tablette ; une personne libre l’est', async () => {
        setupOps({ verify: vi.fn() });
        window.localStorage.setItem('hygiene.operator.1', '7');
        let w = await mountPicker();
        expect(lastEmit(w, 'update:modelValue')).toEqual([null]);
        expect(q(w, 'hy-operator-btn').text()).toContain('Compte T.'); // retour sur le compte de la tablette
        window.localStorage.setItem('hygiene.operator.1', '8');
        w = await mountPicker();
        expect(lastEmit(w, 'update:modelValue')).toEqual([8]);
    });
});

describe('écran du responsable : codes de l’équipe', () => {
    const TEAM = [
        { id: 7, name: 'Sarah Lopez', has_pin: true, pin_set_at: '2026-10-18T10:00:00+02:00', locked: false },
        { id: 8, name: 'Bob Martin', has_pin: false, pin_set_at: null, locked: false },
        { id: 9, name: 'Lina Roy', has_pin: true, pin_set_at: '2026-10-17T10:00:00+02:00', locked: true },
    ];
    let team;
    async function mountTeam(get) {
        h.axios.get.mockReset(); h.axios.put.mockReset(); h.axios.delete.mockReset();
        team = TEAM.map((u) => ({ ...u }));
        h.axios.get.mockImplementation(get || (async () => ({ data: { data: team, meta: { without_pin: team.filter((u) => !u.has_pin).length, timezone: 'Europe/Paris' } } })));
        const w = mount(HygieneTeamComponent, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();
        return w;
    }

    it('un refus 403 dit pourquoi, sans liste', async () => {
        const w = await mountTeam(async () => { throw err(403); });
        expect(q(w, 'tm-forbidden').text()).toContain('réservé au responsable');
        expect(q(w, 'tm-list').exists()).toBe(false);
    });

    it('liste les états (code défini, sans code, bloqué) et compte les personnes sans code', async () => {
        const w = await mountTeam();
        expect(q(w, 'tm-state-7').text()).toBe('Code défini');
        expect(q(w, 'tm-state-8').text()).toBe('Sans code');
        expect(q(w, 'tm-state-9').text()).toContain('Bloqué');
        expect(q(w, 'tm-summary').text()).toContain('1 employé sans code');
    });

    it('définir un code : format contrôlé, envoyé au serveur, jamais réaffiché', async () => {
        const w = await mountTeam();
        h.axios.put.mockResolvedValue({ data: { data: { id: 8, has_pin: true } } });
        await q(w, 'tm-set-8').trigger('click');
        await q(w, 'tm-input-8').setValue('12');
        expect(q(w, 'tm-save-8').attributes('disabled')).toBeDefined();
        await q(w, 'tm-input-8').setValue('4821');
        expect(q(w, 'tm-save-8').attributes('disabled')).toBeDefined();           // pas encore confirmé
        await q(w, 'tm-confirm-8').setValue('4812');                               // faute de frappe à la confirmation
        expect(q(w, 'tm-mismatch-8').text()).toContain('pas identiques');
        expect(q(w, 'tm-save-8').attributes('disabled')).toBeDefined();
        await q(w, 'tm-confirm-8').setValue('4821');
        expect(q(w, 'tm-mismatch-8').exists()).toBe(false);
        await q(w, 'tm-save-8').trigger('click');
        await flushPromises();
        expect(h.axios.put).toHaveBeenCalledWith('admin/hygiene/team/8/pin', { pin: '4821' });
        expect(q(w, 'tm-message').text()).toContain('Code de Bob Martin enregistré');
        expect(w.text()).not.toContain('4821');
        expect(q(w, 'tm-input-8').exists()).toBe(false);
    });

    it('retirer un code demande une confirmation', async () => {
        const w = await mountTeam();
        h.axios.delete.mockResolvedValue({ data: { data: { id: 7, has_pin: false } } });
        await q(w, 'tm-remove-7').trigger('click');
        expect(h.axios.delete).not.toHaveBeenCalled();
        await q(w, 'tm-remove-no-7').trigger('click');
        expect(h.axios.delete).not.toHaveBeenCalled();
        await q(w, 'tm-remove-7').trigger('click');
        await q(w, 'tm-remove-yes-7').trigger('click');
        await flushPromises();
        expect(h.axios.delete).toHaveBeenCalledWith('admin/hygiene/team/7/pin');
        expect(q(w, 'tm-message').text()).toContain('Code de Sarah Lopez retiré');
    });

    it('une personne bloquée a un bouton « Débloquer » qui ne change pas son code', async () => {
        const w = await mountTeam();
        h.axios.post.mockResolvedValue({ data: { data: { id: 9, has_pin: true, locked: false } } });
        expect(q(w, 'tm-unlock-7').exists()).toBe(false);
        await q(w, 'tm-unlock-9').trigger('click');
        await flushPromises();
        expect(h.axios.post).toHaveBeenCalledWith('admin/hygiene/team/9/unlock');
        expect(h.axios.put).not.toHaveBeenCalled();
        expect(q(w, 'tm-message').text()).toContain('son code reste le même');
    });

    it('la durée de validité annoncée est celle du serveur', async () => {
        const w = await mountTeam(async () => ({ data: { data: TEAM, meta: { without_pin: 1, token_minutes: 15, timezone: 'Europe/Paris' } } }));
        expect(w.text()).toContain('15 minutes');
    });

    it('un refus du serveur est affiché et la saisie reste', async () => {
        const w = await mountTeam();
        h.axios.put.mockRejectedValue(err(422, { message: 'x', errors: { pin: ['Le code se compose de 4 à 6 chiffres.'] } }));
        await q(w, 'tm-set-8').trigger('click');
        await q(w, 'tm-input-8').setValue('1234');
        await q(w, 'tm-confirm-8').setValue('1234');
        await q(w, 'tm-save-8').trigger('click');
        await flushPromises();
        expect(q(w, 'tm-message').text()).toContain('4 à 6 chiffres');
        expect(q(w, 'tm-input-8').exists()).toBe(true);
    });
});

describe('le jeton accompagne l’écriture', () => {
    it('relevé signé au nom d’une personne protégée : operator_user_id ET operator_token partent, jamais le code', async () => {
        h.axios.get.mockReset(); h.axios.post.mockReset();
        window.localStorage.clear();
        const eq = { id: 1, name: 'Frigo 1', kind: 'fridge', kind_label: 'Réfrigérateur', negative: false, location: null, min_c: 0, max_c: 4, is_active: true, state: 'todo', last_today: null, previous: null, done_periods: [], done_this_period: false };
        const posts = [];
        h.axios.get.mockImplementation(async (url) => {
            if (url === 'admin/hygiene/operators') return { data: { data: PEOPLE, meta: { me: 1 } } };
            if (url === 'admin/hygiene/temperature/today') return { data: { data: [eq], meta: { period: 'morning', periods: { morning: 'Matin' }, summary: { total: 1, todo: 1, ok: 0, alert: 0 }, kinds: [{ key: 'fridge', label: 'Réfrigérateur' }], corrective_actions: [], server_now: '2026-10-20T10:00:00+02:00', timezone: 'Europe/Paris' } } };
            return { data: { data: [] } };
        });
        h.axios.post.mockImplementation(async (url, body) => {
            posts.push([url, body]);
            if (url === 'admin/hygiene/operators/verify') return { data: { data: { token: 'JETON-SARAH', expires_at: new Date(Date.now() + 600000).toISOString() } } };
            return { data: { data: [{ id: 1 }], meta: { alerts: 0 } } };
        });
        const w = mount(HygieneTemperatureComponent, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();
        await q(w, 'hy-operator-btn').trigger('click');
        await q(w, 'hy-operator-7').trigger('click');
        await q(w, 'hy-pin-input').setValue('4821');
        await q(w, 'hy-pin-ok').trigger('click');
        await flushPromises();
        await q(w, 'tmp-take-1').trigger('click');
        await q(w, 'tmp-input-1').setValue('3');
        await q(w, 'tmp-submit').trigger('click');
        await flushPromises();

        const body = posts.find(([u]) => u === 'admin/hygiene/temperature/readings')[1];
        expect(body.operator_user_id).toBe(7);
        expect(body.operator_token).toBe('JETON-SARAH');
        expect(JSON.stringify(body)).not.toContain('4821');
        expect(body.operator_pin).toBeUndefined();
    });
});
