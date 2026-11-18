/**
 * [CAYENNE HYGIÈNE 2026-11-18 · audit E2E, lot Températures / Accueil / Alertes] Chaque constat est reproduit à l'écran, serveur simulé :
 * P1-1 refus des alertes visible dans la barre + interrupteur fidèle, P1-3 écart d'un équipement masqué + confirmation + droits de structure,
 * P1-4 historique des seuils, P1-5 pastille = bouton, P1-6 barre non collante quand rien n'est à valider, P2 (motif de clôture, journal, messages).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));

import HygieneTemperatureComponent from '../../resources/js/components/admin/hygiene/HygieneTemperatureComponent.vue';
import HygieneAlertsComponent from '../../resources/js/components/admin/hygiene/HygieneAlertsComponent.vue';
import HygieneHomeComponent from '../../resources/js/components/admin/hygiene/HygieneHomeComponent.vue';

const NOW = '2026-11-18T16:05:00+01:00';
const EQ = (o = {}) => ({ id: 1, name: 'Frigo 1', kind: 'fridge', kind_label: 'Réfrigérateur', negative: false, hot: false, location: null, min_c: 0, max_c: 4, is_active: true,
    state: 'todo', last_today: null, previous: null, done_periods: [], due: true, done_this_period: false, hidden_today: false, followup: null, ...o });
const READ = (o = {}) => ({ id: 50, equipment_id: 1, equipment_name: 'Frigo 1', equipment_kind: 'fridge', value_c: 3, min_c: 0, max_c: 4, status: 'ok', alert_reason: null, period: 'morning',
    recorded_at: '2026-11-18T08:00:00+01:00', operator_name: 'Karim', corrective_action: null, corrective_label: null, corrective_note: null, followup_closed: false,
    followup_close_reason: null, voided: false, voided_at: null, void_reason: null, voided_by_name: null, client_ref: 'r', ...o });
const q = (w, id) => w.find(`[data-testid="${id}"]`);

let gets;
function setupTemp(items, { meta = {}, equipmentRows = items, history = [], historyMeta = { limit: 200, truncated: false } } = {}) {
    gets = [];
    h.axios.get.mockReset(); h.axios.post.mockReset(); h.axios.put.mockReset();
    h.alert.error.mockReset(); h.alert.success.mockReset();
    h.axios.get.mockImplementation(async (url, cfg) => {
        gets.push([url, cfg]);
        if (url === 'admin/hygiene/temperature/today') {
            const active = items.filter((i) => !i.hidden_today);
            return { data: { data: items, meta: { period: 'evening', periods: { morning: 'Matin', evening: 'Soir' },
                summary: { total: active.length, todo: active.filter((i) => i.due).length, ok: 0, alert: items.filter((i) => i.state === 'alert').length, hidden_alert: items.filter((i) => i.hidden_today && i.state === 'alert').length },
                kinds: [{ key: 'fridge', label: 'Réfrigérateur' }], corrective_actions: [], server_now: NOW, timezone: 'Europe/Paris', ...meta } } };
        }
        if (url === 'admin/hygiene/temperature/equipment') return { data: { data: equipmentRows, meta: { server_now: NOW, ...meta } } };
        if (url === 'admin/hygiene/temperature/history') return { data: { data: history, meta: { server_now: NOW, ...historyMeta } } };
        return { data: { data: [], meta: { server_now: NOW } } };
    });
    h.axios.put.mockResolvedValue({ data: { data: { id: 1 } } });
    window.localStorage.clear();
}
async function mountTemp() {
    const w = mount(HygieneTemperatureComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}

describe('P1-5 — le soir, la pastille et le bouton disent la même chose', () => {
    it('3 équipements relevés le matin, relevé du soir attendu : « 3 à relever » ET « Saisir tout ce qui reste (3) »', async () => {
        const morning = (id) => EQ({ id, name: `Frigo ${id}`, last_today: READ({ id: 50 + id, equipment_id: id }), done_periods: ['morning'], due: true, done_this_period: false, state: 'todo' });
        setupTemp([morning(1), morning(2), morning(3)]);
        const w = await mountTemp();
        expect(q(w, 'tmp-period').text()).toBe('Soir');
        expect(q(w, 'tmp-sum-todo').text()).toBe('3 à relever');
        expect(q(w, 'tmp-fill-all').text()).toContain('(3)');
    });

    it('relevé du soir NON attendu (le serveur dit « fait ») : « 0 à relever » et rien à « saisir tout »', async () => {
        const done = (id) => EQ({ id, name: `Frigo ${id}`, last_today: READ({ id: 50 + id, equipment_id: id }), done_periods: ['morning'], due: false, done_this_period: true, state: 'ok' });
        setupTemp([done(1), done(2), done(3)]);
        const w = await mountTemp();
        expect(q(w, 'tmp-sum-todo').text()).toBe('0 à relever');
        expect(q(w, 'tmp-fill-all').text()).toContain('(0)');
        expect(q(w, 'tmp-fill-all').attributes('disabled')).toBeDefined();
    });
});

describe('P1-6 — la barre ne reste collée que s’il y a quelque chose à valider', () => {
    it('rien saisi : barre « au repos » (non collante) ; une valeur saisie : collante', async () => {
        setupTemp([EQ()]);
        const w = await mountTemp();
        expect(q(w, 'tmp-bar').classes()).toContain('is-idle');
        await q(w, 'tmp-take-1').trigger('click');
        await q(w, 'tmp-input-1').setValue('3');
        expect(q(w, 'tmp-bar').classes()).not.toContain('is-idle');
    });

    it('libellés courts pour le téléphone présents à côté des longs (une seule ligne sous 600 px)', async () => {
        setupTemp([EQ()]);
        const w = await mountTemp();
        expect(q(w, 'tmp-fill-all').find('.tmp-short').text()).toBe('Tout saisir');
        expect(q(w, 'tmp-submit').find('.tmp-short').text()).toBe('Valider');
    });
});

describe('P1-3 — masquer un équipement ne fait pas disparaître son écart', () => {
    it('un équipement masqué aujourd’hui avec un écart reste affiché, dit pourquoi, n’est plus à relever et ne propose pas de relevé', async () => {
        const alert = READ({ id: 60, equipment_id: 2, equipment_name: 'Frigo 2', value_c: 9, status: 'alert', alert_reason: 'too_warm', corrective_label: 'Produits jetés' });
        setupTemp([EQ(), EQ({ id: 2, name: 'Frigo 2', is_active: false, hidden_today: true, last_today: alert, state: 'alert', due: false, done_this_period: true })]);
        const w = await mountTemp();
        expect(q(w, 'tmp-hidden-2').text()).toContain('Masqué aujourd\'hui');
        expect(q(w, 'tmp-sum-alert').text()).toContain('1 écart');
        expect(q(w, 'tmp-sum-alert').text()).toContain('dont 1 masqué');
        expect(q(w, 'tmp-again-2').exists()).toBe(false);
        expect(q(w, 'tmp-take-2').exists()).toBe(false);
        expect(q(w, 'tmp-fill-all').text()).toContain('(1)'); // seul Frigo 1 reste à relever
        expect(q(w, 'tmp-all-hidden').exists()).toBe(false);
    });

    it('masquer un équipement qui a un écart du jour demande une confirmation explicite ; « Garder » n’envoie rien', async () => {
        const alert = READ({ id: 61, equipment_id: 1, value_c: 9, status: 'alert', alert_reason: 'too_warm' });
        setupTemp([EQ({ last_today: alert, state: 'alert' })]);
        const w = await mountTemp();
        await q(w, 'tmp-manage-toggle').trigger('click');
        await flushPromises();
        await q(w, 'tmp-eq-toggle-1').trigger('click');
        expect(h.axios.put).not.toHaveBeenCalled();
        expect(q(w, 'tmp-eq-confirm-1').text()).toContain('un écart aujourd\'hui');
        await q(w, 'tmp-eq-confirm-cancel-1').trigger('click');
        expect(q(w, 'tmp-eq-confirm-1').exists()).toBe(false);
        expect(h.axios.put).not.toHaveBeenCalled();
        await q(w, 'tmp-eq-toggle-1').trigger('click');
        await q(w, 'tmp-eq-confirm-go-1').trigger('click');
        await flushPromises();
        expect(h.axios.put).toHaveBeenCalledWith('admin/hygiene/temperature/equipment/1', { is_active: false });
    });

    it('un suivi ouvert déclenche aussi la confirmation ; sans écart ni suivi, « Masquer » part tout de suite', async () => {
        setupTemp([EQ({ followup: { reading_id: 5, due_at: '2026-11-18T16:30:00+01:00', overdue: false } }), EQ({ id: 2, name: 'Frigo 2' })]);
        const w = await mountTemp();
        await q(w, 'tmp-manage-toggle').trigger('click');
        await flushPromises();
        await q(w, 'tmp-eq-toggle-1').trigger('click');
        expect(q(w, 'tmp-eq-confirm-1').text()).toContain('nouvelle mesure attendue');
        await q(w, 'tmp-eq-toggle-2').trigger('click');
        await flushPromises();
        expect(h.axios.put).toHaveBeenCalledWith('admin/hygiene/temperature/equipment/2', { is_active: false });
    });

    it('sans le droit de structure (le serveur le dit) : ni création, ni Enregistrer / Masquer / Ajouter — la saisie reste', async () => {
        setupTemp([EQ()], { meta: { can_manage: false } });
        const w = await mountTemp();
        expect(q(w, 'tmp-take-1').exists()).toBe(true); // la SAISIE reste ouverte
        await q(w, 'tmp-manage-toggle').trigger('click');
        await flushPromises();
        expect(q(w, 'tmp-manage-readonly').text()).toContain('réservé au responsable');
        expect(q(w, 'tmp-eq-save-1').exists()).toBe(false);
        expect(q(w, 'tmp-eq-toggle-1').exists()).toBe(false);
        expect(q(w, 'tmp-add').exists()).toBe(false);
        expect(q(w, 'tmp-eq-1').text()).toContain('0 à +4 °C');
    });

    it('sans le droit de structure et sans équipement : pas de bouton de création, un message clair', async () => {
        setupTemp([], { meta: { can_manage: false }, equipmentRows: [] });
        const w = await mountTemp();
        expect(q(w, 'tmp-setup-forbidden').text()).toContain('réservée au responsable');
        expect(q(w, 'tmp-setup-go').exists()).toBe(false);
    });
});

describe('P1-4 — l’historique de structure de chaque équipement', () => {
    it('« Historique » liste date, personne et « Seuil max 4 °C → 8 °C »', async () => {
        const changes = [{ id: 9, at: '2026-11-18T09:12:00+01:00', by: 'Sarah Lopez', field: 'max_c', old_value: '4', new_value: '8', text: 'Seuil max 4 °C → 8 °C' }];
        setupTemp([EQ()], { equipmentRows: [EQ({ changes, changes_total: 1 })] });
        const w = await mountTemp();
        await q(w, 'tmp-manage-toggle').trigger('click');
        await flushPromises();
        expect(q(w, 'tmp-eq-history-1').text()).toContain('Historique (1)');
        await q(w, 'tmp-eq-history-1').trigger('click');
        const row = q(w, 'tmp-eq-change-9').text();
        expect(row).toContain('18/11');
        expect(row).toContain('Sarah Lopez');
        expect(row).toContain('Seuil max 4 °C → 8 °C');
    });
});

describe('P2 — Températures', () => {
    it('(c) un suivi clos par « Mesure impossible » montre son motif sur la carte', async () => {
        const r = READ({ status: 'alert', value_c: 9, alert_reason: 'too_warm', followup_closed: true, followup_close_reason: 'Frigo en panne, technicien appelé' });
        setupTemp([EQ({ last_today: r, state: 'alert', due: false, done_this_period: true })]);
        const w = await mountTemp();
        expect(q(w, 'tmp-closed-1').text()).toContain('Frigo en panne, technicien appelé');
    });

    it('(d) un seuil illisible ne propose plus « laissez vide pour aucun seuil » (le serveur le refuserait)', async () => {
        setupTemp([EQ()]);
        const w = await mountTemp();
        await q(w, 'tmp-manage-toggle').trigger('click');
        await flushPromises();
        await q(w, 'tmp-eq-max-1').setValue('4a');
        await q(w, 'tmp-eq-save-1').trigger('click');
        await flushPromises();
        expect(h.alert.error.mock.calls[0][0]).toContain('illisible');
        expect(h.alert.error.mock.calls[0][0]).not.toContain('laissez vide');
    });

    it('(e)(f) journal : annulation lisible avec auteur et heure ; la fenêtre est dite ; filtre par équipement', async () => {
        const voided = READ({ id: 70, voided: true, voided_at: '2026-11-18T10:05:00+01:00', void_reason: 'faute de frappe', voided_by_name: 'Sarah Lopez' });
        setupTemp([EQ(), EQ({ id: 2, name: 'Frigo 2' })], { history: [voided], historyMeta: { limit: 200, truncated: true } });
        const w = await mountTemp();
        await q(w, 'tmp-history-toggle').trigger('click');
        await flushPromises();
        expect(q(w, 'tmp-row-voided-70').text()).toBe('Annulé par Sarah Lopez à 10:05 : faute de frappe');
        expect(q(w, 'tmp-history-window').text()).toContain('30 derniers jours');
        expect(q(w, 'tmp-history-window').text()).toContain('liste tronquée');
        await q(w, 'tmp-history-equipment').findAll('option')[2].setSelected();
        await flushPromises();
        const last = gets.filter(([u]) => u === 'admin/hygiene/temperature/history').pop();
        expect(last[1].params.equipment_id).toBe(2);
    });

    it('(g) « 150 » sur un bain-marie : « au-delà de la plage plausible », pas « illisible »', async () => {
        setupTemp([EQ({ kind: 'hot_holding', hot: true, min_c: 63, max_c: null })]);
        const w = await mountTemp();
        await q(w, 'tmp-take-1').trigger('click');
        await q(w, 'tmp-input-1').setValue('150');
        expect(q(w, 'tmp-raw-error-1').text()).toContain('au-delà de la plage plausible');
        await q(w, 'tmp-input-1').setValue('1a');
        expect(q(w, 'tmp-raw-error-1').text()).toContain('Valeur illisible');
    });
});

describe('P1-1 — Alertes : un refus se voit sans défiler, l’interrupteur dit la vérité', () => {
    const DOMAINS = [{ key: 'temperature', label: 'Relevés de température' }];
    const BASE = { enabled: false, recipients: [], domains: ['temperature'], send_from: '09:00', send_until: '23:30', repeat_minutes: 60, max_sends: 3 };
    const payload = () => ({ data: { data: { settings: { ...BASE }, staff: [], recent: [] }, meta: { mail_ready: true, domains: DOMAINS, timezone: 'Europe/Paris', server_now: NOW } } });
    beforeEach(() => { h.axios.get.mockReset(); h.axios.put.mockReset(); h.alert.error.mockReset(); });

    it('activer sans destinataire : le refus est DANS la barre d’action (role=alert), notifié, et l’interrupteur revient à « désactivé »', async () => {
        h.axios.get.mockImplementation(async () => payload());
        const w = mount(HygieneAlertsComponent, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();
        expect(q(w, 'al-bar').classes()).toContain('is-idle'); // rien à enregistrer : pas collante
        const e = new Error('422');
        e.response = { status: 422, data: { message: 'x', errors: { recipients: ['Ajoutez au moins une adresse e-mail pour activer les alertes.'] } } };
        h.axios.put.mockRejectedValue(e);
        await q(w, 'al-enabled').trigger('click');
        expect(q(w, 'al-bar').classes()).not.toContain('is-idle'); // une modification : collante
        await q(w, 'al-save').trigger('click');
        await flushPromises();
        const msg = q(w, 'al-bar').find('[data-testid="al-message"]');
        expect(msg.exists()).toBe(true);
        expect(msg.attributes('role')).toBe('alert');
        expect(msg.text()).toContain('Ajoutez au moins une adresse e-mail');
        expect(msg.text()).toContain('Les alertes restent désactivées.');
        expect(h.alert.error).toHaveBeenCalledWith(expect.stringContaining('Ajoutez au moins une adresse e-mail'));
        expect(q(w, 'al-enabled').attributes('aria-pressed')).toBe('false');
        expect(q(w, 'al-enabled').text()).toContain('désactivé');
        expect(q(w, 'al-bar').classes()).not.toContain('is-idle'); // un refus à lire : la barre reste visible
    });
});

describe('Accueil — deux relevés par jour', () => {
    const RouterLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' };
    it('« Journée en règle » dit matin ET soir quand le restaurant relève le soir', async () => {
        const t = { key: 'temperature', label: 'Températures', icon: 'x', route: '/admin/hygiene/temperature', status: 'ok', headline: 'Matin 3/3 · Soir 3/3', detail: 'Tout est relevé',
            counts: { done: 3, total: 3, evening_expected: true, morning_done: 3, evening_done: 3 } };
        h.axios.get.mockReset();
        h.axios.get.mockResolvedValue({ data: { data: [t], meta: { alerts: 0, todo: 0, reminders: [], server_now: NOW, timezone: 'Europe/Paris' } } });
        const w = mount(HygieneHomeComponent, { global: { stubs: { 'router-link': RouterLinkStub } } });
        await flushPromises();
        expect(q(w, 'home-banner').text()).toBe('Journée en règle ✓ · relevés matin 3/3 · soir 3/3');
        expect(q(w, 'home-headline-temperature').text()).toBe('Matin 3/3 · Soir 3/3');
        w.unmount();
    });
});
