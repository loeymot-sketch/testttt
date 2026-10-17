/**
 * [CAYENNE HYGIÈNE 2026-10-18] Écran des alertes e-mail : dit la vérité sur ce qui part, n'enregistre que ce que le serveur accepte,
 * et ne laisse pas envoyer un essai avec des réglages non enregistrés.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({ axios: { get: vi.fn(), post: vi.fn(), put: vi.fn() }, alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));

import HygieneAlertsComponent from '../../resources/js/components/admin/hygiene/HygieneAlertsComponent.vue';

const DOMAINS = [['temperature', 'Relevés de température'], ['tasks', 'Nettoyage'], ['oil', 'Huile de friture'], ['dlc', 'DLC dépassées'], ['trace', 'Traçabilité']].map(([key, label]) => ({ key, label }));
const BASE = { enabled: false, recipients: [], domains: ['temperature', 'tasks', 'oil', 'dlc', 'trace'], send_from: '09:00', send_until: '23:30', repeat_minutes: 60, max_sends: 3 };
const STAFF = [{ id: 7, name: 'Sarah Lopez', email: 'Sarah@Example.test' }];
const payload = (settings = {}, meta = {}, recent = []) => ({ data: { data: { settings: { ...BASE, ...settings }, staff: STAFF, recent }, meta: { mail_ready: true, domains: DOMAINS, timezone: 'Europe/Paris', ...meta } } });

async function mountWith(get) {
    h.axios.get.mockReset(); h.axios.put.mockReset(); h.axios.post.mockReset();
    h.axios.get.mockImplementation(get);
    const w = mount(HygieneAlertsComponent, { global: { mocks: { $t: (k) => k } } });
    await flushPromises();
    return w;
}
const q = (w, id) => w.find(`[data-testid="${id}"]`);

describe('alertes e-mail — écran', () => {
    beforeEach(() => { h.alert.success.mockReset(); h.alert.error.mockReset(); });

    it('réservé au responsable : un refus 403 dit pourquoi, sans formulaire', async () => {
        const w = await mountWith(async () => { const e = new Error('403'); e.response = { status: 403 }; throw e; });
        expect(q(w, 'al-forbidden').text()).toContain('réservé au responsable');
        expect(q(w, 'al-enabled').exists()).toBe(false);
    });

    it('une panne réseau au chargement est dite (pas un écran vide)', async () => {
        const w = await mountWith(async () => { throw new Error('net'); });
        expect(q(w, 'al-error').text()).toContain('indisponibles');
    });

    it('désactivé par défaut ; rien à enregistrer tant que rien ne change ; essai impossible sans destinataire', async () => {
        const w = await mountWith(async () => payload());
        expect(q(w, 'al-enabled').attributes('aria-pressed')).toBe('false');
        expect(q(w, 'al-save').attributes('disabled')).toBeDefined();
        expect(q(w, 'al-test').attributes('disabled')).toBeDefined();
    });

    it('sans messagerie configurée sur le serveur, l’écran le dit en rouge', async () => {
        const w = await mountWith(async () => payload({}, { mail_ready: false }));
        expect(q(w, 'al-mail-off').text()).toContain("Aucune messagerie n'est configurée");
    });

    it('choisir un employé, ajouter une adresse, décocher un type : enregistre exactement cela, adresses en minuscules et sans doublon', async () => {
        const w = await mountWith(async () => payload());
        h.axios.put.mockResolvedValue({ data: { data: { settings: { ...BASE, enabled: true, recipients: ['sarah@example.test', 'chef@example.test'], domains: ['temperature', 'tasks', 'dlc', 'trace'] } } } });

        await q(w, 'al-enabled').trigger('click');
        await q(w, 'al-staff-7').trigger('click');
        await q(w, 'al-new-email').setValue('Chef@Example.test');
        await q(w, 'al-add').trigger('click');
        await q(w, 'al-new-email').setValue('chef@example.test'); // doublon
        await q(w, 'al-add').trigger('click');
        await q(w, 'al-domain-oil').trigger('click');
        await q(w, 'al-save').trigger('click');
        await flushPromises();

        const [url, body] = h.axios.put.mock.calls[0];
        expect(url).toBe('admin/hygiene/alerts');
        expect(body.enabled).toBe(true);
        expect(body.recipients).toEqual(['sarah@example.test', 'chef@example.test']);
        expect(body.domains).toEqual(['temperature', 'tasks', 'dlc', 'trace']);
        expect(q(w, 'al-message').text()).toContain('les alertes sont actives');
        expect(q(w, 'al-save').attributes('disabled')).toBeDefined(); // plus rien à enregistrer
    });

    it('une adresse illisible est refusée sur place, sans appel au serveur', async () => {
        const w = await mountWith(async () => payload());
        await q(w, 'al-new-email').setValue('pas-une-adresse');
        await q(w, 'al-add').trigger('click');
        expect(q(w, 'al-email-error').text()).toContain("pas valide");
        expect(h.axios.put).not.toHaveBeenCalled();
    });

    it('le refus du serveur est affiché tel quel et les réglages restent modifiables', async () => {
        const w = await mountWith(async () => payload());
        const e = new Error('422');
        e.response = { status: 422, data: { message: 'x', errors: { recipients: ['Ajoutez au moins une adresse e-mail pour activer les alertes.'] } } };
        h.axios.put.mockRejectedValue(e);
        await q(w, 'al-enabled').trigger('click');
        await q(w, 'al-save').trigger('click');
        await flushPromises();
        expect(q(w, 'al-message').text()).toContain('Ajoutez au moins une adresse e-mail');
        expect(q(w, 'al-save').attributes('disabled')).toBeUndefined(); // on peut corriger et réessayer
    });

    it('l’essai est refusé tant que des modifications ne sont pas enregistrées, puis part', async () => {
        const w = await mountWith(async () => payload({ enabled: true, recipients: ['chef@example.test'] }));
        expect(q(w, 'al-test').attributes('disabled')).toBeUndefined();
        await q(w, 'al-domain-oil').trigger('click'); // modification non enregistrée
        expect(q(w, 'al-test').attributes('disabled')).toBeDefined();
    });

    it('l’essai affiche l’issue et rafraîchit le journal ; un échec dit que le détail est au journal', async () => {
        let recent = [];
        const w = await mountWith(async () => payload({ enabled: true, recipients: ['chef@example.test'] }, {}, recent));
        h.axios.post.mockResolvedValue({ data: { data: { sent: 1, failed: 0 } } });
        h.axios.get.mockImplementation(async () => payload({ enabled: true, recipients: ['chef@example.test'] }, {}, [{ id: 1, at: '2026-10-20T10:00:00+02:00', recipient: 'chef@example.test', status: 'test', domain: 'temperature', text: 'Ceci est un message de test', error: null }]));
        await q(w, 'al-test').trigger('click');
        await flushPromises();
        expect(q(w, 'al-message').text()).toContain("E-mail d'essai envoyé à 1 adresse");
        expect(w.findAll('[data-testid="al-log-row"]')).toHaveLength(1);
        expect(q(w, 'al-log-row').text()).toContain('Essai');

        const e = new Error('502'); e.response = { status: 502, data: { data: { sent: 0, failed: 1 } } };
        h.axios.post.mockRejectedValue(e);
        await q(w, 'al-test').trigger('click');
        await flushPromises();
        expect(q(w, 'al-message').text()).toContain('Derniers envois');
    });

    it('un envoi en échec figure au journal avec sa raison', async () => {
        const w = await mountWith(async () => payload({}, {}, [{ id: 3, at: '2026-10-20T11:42:00+02:00', recipient: 'chef@example.test', status: 'failed', domain: 'temperature', text: 'Relevé du matin non fait', error: 'Connection refused' }]));
        const row = q(w, 'al-log-row');
        expect(row.text()).toContain('Échec');
        expect(row.text()).toContain('Connection refused');
        expect(row.classes()).toContain('is-alert');
    });
});
